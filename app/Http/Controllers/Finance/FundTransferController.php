<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFundTransferRequest;
use App\Services\FundTransferService;
use Illuminate\Http\RedirectResponse;

/**
 * PRD §4.7 "Pindah Dana" between the company's own accounts — no own row
 * in §7.1, so it follows "Finance – Transaction": Finance creates, CEO/PM
 * read the resulting legs on the Transactions page. Create-only: a
 * mistaken transfer is corrected with a transfer back (append-only,
 * PRD §9.4).
 */
class FundTransferController extends Controller
{
    public function store(StoreFundTransferRequest $request, FundTransferService $service): RedirectResponse
    {
        $service->transfer($request->validated(), $request->user());

        return back()->with('success', 'Pindah dana berhasil dicatat.');
    }
}
