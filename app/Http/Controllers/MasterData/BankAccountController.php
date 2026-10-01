<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreBankAccountRequest;
use App\Http\Requests\MasterData\UpdateBankAccountRequest;
use App\Models\BankAccount;
use App\Services\BankAccountService;
use Illuminate\Http\RedirectResponse;

class BankAccountController extends Controller
{
    public function store(StoreBankAccountRequest $request, BankAccountService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return back()->with('success', 'Rekening bank berhasil ditambahkan.');
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount, BankAccountService $service): RedirectResponse
    {
        $service->update($bankAccount, $request->validated(), $request->user());

        return back()->with('success', 'Rekening bank berhasil diperbarui.');
    }

    public function destroy(BankAccount $bankAccount): RedirectResponse
    {
        // Deleting would null the FK on its transactions (and the fund
        // transfer FKs refuse it outright) — every derived balance would
        // silently change. Deactivate it instead.
        if ($bankAccount->transactions()->exists()) {
            return back()->with('error', 'Rekening ini sudah punya transaksi — nonaktifkan saja, jangan dihapus.');
        }

        $bankAccount->delete();

        return back()->with('success', 'Rekening bank berhasil dihapus.');
    }
}
