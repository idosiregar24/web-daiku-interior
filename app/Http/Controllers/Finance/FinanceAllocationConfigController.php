<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreFinanceAllocationConfigRequest;
use App\Http\Requests\Finance\UpdateFinanceAllocationConfigRequest;
use App\Models\FinanceAllocationConfig;
use App\Services\FinanceAllocationService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.7 "Alokasi persentase dikonfigurasi di finance_allocation_configs
 * dan bisa diubah CEO/Finance". No destroy — deactivate via `is_active`.
 */
class FinanceAllocationConfigController extends Controller
{
    public function index(FinanceAllocationService $service): Response
    {
        return Inertia::render('Finance/Allocations/Index', [
            'allocations' => FinanceAllocationConfig::query()
                ->orderByDesc('is_active')
                ->orderByDesc('percentage')
                ->orderBy('label')
                ->get(),
            'activeTotal' => $service->activeTotal(),
        ]);
    }

    public function store(StoreFinanceAllocationConfigRequest $request, FinanceAllocationService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return back()->with('success', 'Alokasi berhasil ditambahkan.');
    }

    public function update(UpdateFinanceAllocationConfigRequest $request, FinanceAllocationConfig $allocation, FinanceAllocationService $service): RedirectResponse
    {
        $service->update($allocation, $request->validated(), $request->user());

        return back()->with('success', 'Alokasi berhasil diperbarui.');
    }
}
