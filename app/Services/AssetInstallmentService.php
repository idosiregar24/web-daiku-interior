<?php

namespace App\Services;

use App\Enums\FinanceCategory;
use App\Enums\FinanceTransactionType;
use App\Models\Asset;
use App\Models\AssetInstallmentPayment;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Aset & Cicilan: pencatatan aset perusahaan yang masih dalam
 * cicilan (Mobil Pickup, Scross, Laptop, dll)" — Sprint 9 decision #8.
 *
 * - Logistics sets the plan on the asset form (§7.1 "Asset Inventory":
 *   LOG CRUD), so asset writes go through here: createAsset(),
 *   updateAsset() and deleteAsset() guard the plan against the payments
 *   already made.
 * - Finance records each payment (recordPayment()): a PENGELUARAN /
 *   ANGSURAN FinanceTransaction on the chosen bank account (PRD §4.7
 *   "setiap transaksi wajib mencantumkan rekening bank"), the append-only
 *   ledger row, and `assets.paid_install` — together or not at all.
 *
 * Money is compared in integer cents so decimals never drift. Every write
 * that touches the plan or the ledger is audited (PRD §9.4).
 */
class AssetInstallmentService
{
    private const ASSET_FIELDS = ['name', 'category', 'purchase_date', 'value', 'condition', 'location', 'notes'];

    private const PLAN_FIELDS = ['has_installment', 'total_install', 'installment_amount', 'installment_due_day'];

    public function __construct(
        private FinanceTransactionService $financeTransactionService,
        private AuditLogService $auditLogService,
    ) {}

    public function createAsset(array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($data, $actor) {
            $asset = Asset::create([...Arr::only($data, self::ASSET_FIELDS), ...$this->planAttributes($data)])->refresh();

            if ($asset->has_installment) {
                $this->auditPlan($asset, null, $actor);
            }

            return $asset;
        });
    }

    /**
     * The asset row is locked so the checks below see the `paid_install`
     * a concurrent payment may have just committed.
     */
    public function updateAsset(Asset $asset, array $data, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->id);
            $plan = $this->planAttributes($data);
            $paidCents = self::cents($locked->paid_install);

            if (! $plan['has_installment'] && $paidCents > 0) {
                throw ValidationException::withMessages([
                    'has_installment' => 'Cicilan aset ini sudah dibayar sebagian — rencana cicilan tidak bisa dihapus.',
                ]);
            }

            if ($plan['has_installment'] && self::cents($plan['total_install']) < $paidCents) {
                throw ValidationException::withMessages([
                    'total_install' => 'Total cicilan tidak boleh lebih kecil dari yang sudah dibayar ('.self::rupiah($paidCents).').',
                ]);
            }

            $oldPlan = $this->planSnapshot($locked);
            $locked->fill([...Arr::only($data, self::ASSET_FIELDS), ...$plan])->save();

            if ($locked->wasChanged(self::PLAN_FIELDS)) {
                $this->auditPlan($locked, $oldPlan, $actor);
            }

            $asset->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    /**
     * An asset with installment payments is referenced by the ledger
     * (restrictOnDelete) — refuse with a readable message instead of a raw
     * FK error. Locked so a payment can't land between check and delete.
     */
    public function deleteAsset(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->id);

            if ($locked->installmentPayments()->exists()) {
                throw ValidationException::withMessages([
                    'asset' => "Aset {$locked->name} sudah punya riwayat pembayaran cicilan dan tidak bisa dihapus.",
                ]);
            }

            $locked->delete();
        });
    }

    /**
     * Records one installment. The asset row is locked so two concurrent
     * payments (double click, two Finance users) can't both pass the
     * "≤ sisa cicilan" check.
     *
     * @param  array{amount: numeric-string|float|int, paid_at: string, bank_account_id: int|string, note?: string|null}  $data
     */
    public function recordPayment(Asset $asset, array $data, User $actor): AssetInstallmentPayment
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->id);

            if (! $locked->has_installment || $locked->total_install === null) {
                throw ValidationException::withMessages([
                    'amount' => 'Aset ini tidak punya rencana cicilan.',
                ]);
            }

            $amountCents = self::cents($data['amount'] ?? 0);
            $paidCents = self::cents($locked->paid_install);
            $remainingCents = self::cents($locked->total_install) - $paidCents;

            if ($amountCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran harus lebih dari 0.',
                ]);
            }

            if ($remainingCents <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Cicilan aset ini sudah lunas.',
                ]);
            }

            if ($amountCents > $remainingCents) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran melebihi sisa cicilan ('.self::rupiah($remainingCents).').',
                ]);
            }

            // PRD §4.7 "Setiap transaksi wajib mencantumkan rekening bank" —
            // re-checked here, not only in the Form Request.
            $bankAccountId = (int) ($data['bank_account_id'] ?? 0);

            if (! BankAccount::whereKey($bankAccountId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'bank_account_id' => 'Rekening sumber wajib dipilih dan harus aktif.',
                ]);
            }

            $amount = self::decimal($amountCents);
            $paidAt = $data['paid_at'] ?? today()->toDateString();

            $transaction = $this->financeTransactionService->create([
                'bank_account_id' => $bankAccountId,
                'type' => FinanceTransactionType::Expense->value,
                'kategori' => FinanceCategory::Angsuran->value,
                'amount' => $amount,
                'description' => "Cicilan {$locked->name}",
                // Same convention as the Sprint 8 ledgers (loan / supplier
                // debt id): read together with kategori ANGSURAN.
                'reference_id' => $locked->id,
                'date' => $paidAt,
            ], $actor);

            $payment = $locked->installmentPayments()->create([
                'amount' => $amount,
                'paid_at' => $paidAt,
                'bank_account_id' => $bankAccountId,
                'finance_transaction_id' => $transaction->id,
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            $oldPaid = $locked->paid_install;
            $locked->forceFill(['paid_install' => self::decimal($paidCents + $amountCents)])->save();

            $this->auditLogService->record(
                'finance.asset_installment_paid',
                $locked,
                ['paid_install' => $oldPaid],
                [
                    'paid_install' => $locked->paid_install,
                    'remaining_install' => $locked->remaining_install,
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'paid_at' => $paidAt,
                    'bank_account_id' => $bankAccountId,
                    'finance_transaction_id' => $transaction->id,
                ],
                $actor,
            );

            $asset->setRawAttributes($locked->getAttributes(), true);

            return $payment;
        });
    }

    /** Plan columns from validated input — a plan switched off clears every plan field. */
    private function planAttributes(array $data): array
    {
        if (! (bool) ($data['has_installment'] ?? false)) {
            return ['has_installment' => false, 'total_install' => null, 'installment_amount' => null, 'installment_due_day' => null];
        }

        return [
            'has_installment' => true,
            'total_install' => $data['total_install'],
            'installment_amount' => $data['installment_amount'] ?? null,
            'installment_due_day' => $data['installment_due_day'] ?? null,
        ];
    }

    private function planSnapshot(Asset $asset): array
    {
        return [...$asset->only(self::PLAN_FIELDS), 'paid_install' => $asset->paid_install];
    }

    /** PRD §9.4 "perubahan finance" — the plan is the liability the ledger pays off. */
    private function auditPlan(Asset $asset, ?array $old, User $actor): void
    {
        $this->auditLogService->record('finance.asset_installment_plan_updated', $asset, $old, $this->planSnapshot($asset), $actor);
    }

    private static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }

    private static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function rupiah(int $cents): string
    {
        return 'Rp '.number_format($cents / 100, 0, ',', '.');
    }
}
