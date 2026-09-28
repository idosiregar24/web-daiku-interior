<?php

namespace App\Services;

use App\Models\FinanceAllocationConfig;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.7 "Alokasi Persentase Otomatis: Sistem mengalokasikan persentase
 * dari nilai proyek secara otomatis". Sprint 8 decision #5: this is a
 * computed budget breakdown shown on the project's Finance tab — it never
 * writes FinanceTransactions. Config changes (CEO/Finance) are audited.
 */
class FinanceAllocationService
{
    public function __construct(private AuditLogService $auditLogService) {}

    public function create(array $data, User $actor): FinanceAllocationConfig
    {
        return DB::transaction(function () use ($data, $actor) {
            $config = FinanceAllocationConfig::create([
                'label' => $data['label'],
                'percentage' => $data['percentage'],
                'kategori' => $data['kategori'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->guardTotal();
            $this->auditLogService->record('finance.allocation_created', $config, null, $this->snapshot($config), $actor);

            return $config;
        });
    }

    public function update(FinanceAllocationConfig $config, array $data, User $actor): FinanceAllocationConfig
    {
        return DB::transaction(function () use ($config, $data, $actor) {
            $old = $this->snapshot($config);

            $config->update([
                'label' => $data['label'],
                'percentage' => $data['percentage'],
                'kategori' => $data['kategori'],
                'is_active' => $data['is_active'] ?? $config->is_active,
            ]);

            $this->guardTotal();
            $this->auditLogService->record('finance.allocation_updated', $config, $old, $this->snapshot($config), $actor);

            return $config;
        });
    }

    /** Sum of all active percentages — shown on the config page. */
    public function activeTotal(): float
    {
        return (float) FinanceAllocationConfig::active()->sum('percentage');
    }

    /**
     * @return list<array{label: string, kategori: string, percentage: float, amount: float}>
     */
    public function breakdownFor(Project $project): array
    {
        $contractValue = (float) $project->contract_value;

        return FinanceAllocationConfig::active()
            ->orderByDesc('percentage')
            ->orderBy('label')
            ->get()
            ->map(fn (FinanceAllocationConfig $config) => [
                'label' => $config->label,
                'kategori' => $config->kategori->value,
                'percentage' => (float) $config->percentage,
                'amount' => round($contractValue * (float) $config->percentage / 100, 2),
            ])
            ->all();
    }

    /** Runs inside the write's transaction, so a violating change is rolled back. */
    private function guardTotal(): void
    {
        // Locking the active rows serializes concurrent CEO/Finance saves,
        // so two writes can't each see ≤ 100% and together exceed it.
        $total = (float) FinanceAllocationConfig::active()->lockForUpdate()->get(['percentage'])->sum('percentage');

        if ($total > 100) {
            throw ValidationException::withMessages([
                'percentage' => 'Total persentase alokasi yang aktif tidak boleh melebihi 100%.',
            ]);
        }
    }

    private function snapshot(FinanceAllocationConfig $config): array
    {
        return $config->only(['label', 'percentage', 'kategori', 'is_active']);
    }
}
