<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreVendorRequest;
use App\Http\Requests\MasterData\UpdateVendorRequest;
use App\Models\Vendor;
use App\Services\VendorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 11 Sub 2 — Data Master → Vendor. Its own page (not a tab of
 * MasterDataController) because the CEO manages vendors too, while the
 * rest of Data Master stays SUPERADMIN-only.
 */
class VendorController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->value();

        $vendors = Vendor::query()
            ->search($request->string('search')->trim()->value() ?: null)
            ->when($request->string('type')->value(), fn ($query, $type) => $query->where('type', $type))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->withExists(['supplierDebts as has_supplier_debts', 'projectMaterials as has_project_materials'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Vendor $vendor) => [
                ...$vendor->only(['id', 'name', 'contact', 'address', 'type', 'bank_name', 'bank_account_number', 'account_holder', 'is_active']),
                'in_use' => $vendor->has_supplier_debts || $vendor->has_project_materials,
            ]);

        return Inertia::render('MasterData/Vendors/Index', [
            'vendors' => $vendors,
            'filters' => $request->only(['search', 'type', 'status']),
        ]);
    }

    public function store(StoreVendorRequest $request, VendorService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return back()->with('success', 'Vendor berhasil ditambahkan.');
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor, VendorService $service): RedirectResponse
    {
        $service->update($vendor, $request->validated());

        return back()->with('success', 'Vendor berhasil diperbarui.');
    }

    public function destroy(Vendor $vendor, VendorService $service): RedirectResponse
    {
        $service->delete($vendor);

        return back()->with('success', 'Vendor berhasil dihapus.');
    }
}
