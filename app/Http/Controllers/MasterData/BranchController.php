<?php

namespace App\Http\Controllers\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreBranchRequest;
use App\Http\Requests\MasterData\UpdateBranchRequest;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;

class BranchController extends Controller
{
    public function store(StoreBranchRequest $request): RedirectResponse
    {
        Branch::create($request->validated());

        return back()->with('success', 'Cabang berhasil ditambahkan.');
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        return back()->with('success', 'Cabang berhasil diperbarui.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $branch->delete();

        return back()->with('success', 'Cabang berhasil dihapus.');
    }
}
