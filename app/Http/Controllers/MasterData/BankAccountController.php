<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreBankAccountRequest;
use App\Http\Requests\MasterData\UpdateBankAccountRequest;
use App\Models\BankAccount;
use Illuminate\Http\RedirectResponse;

class BankAccountController extends Controller
{
    public function store(StoreBankAccountRequest $request): RedirectResponse
    {
        BankAccount::create($request->validated());

        return back()->with('success', 'Rekening bank berhasil ditambahkan.');
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): RedirectResponse
    {
        $bankAccount->update($request->validated());

        return back()->with('success', 'Rekening bank berhasil diperbarui.');
    }

    public function destroy(BankAccount $bankAccount): RedirectResponse
    {
        $bankAccount->delete();

        return back()->with('success', 'Rekening bank berhasil dihapus.');
    }
}
