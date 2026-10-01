<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreEmployeeRequest;
use App\Http\Requests\Finance\UpdateEmployeeRequest;
use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Http\RedirectResponse;

/**
 * Employee master for payroll (PRD §4.7 "Gaji Karyawan Tetap") — Finance
 * only. Listed on the Penggajian page's "Karyawan" tab
 * (PayrollController::index()). No destroy: employees are deactivated,
 * never deleted, so their salary history stays intact.
 */
class EmployeeController extends Controller
{
    public function store(StoreEmployeeRequest $request, EmployeeService $service): RedirectResponse
    {
        $employee = $service->create($request->validated(), $request->user());

        return back()->with('success', "Karyawan {$employee->name} berhasil ditambahkan.");
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee, EmployeeService $service): RedirectResponse
    {
        $employee = $service->update($employee, $request->validated(), $request->user());

        return back()->with('success', "Data karyawan {$employee->name} berhasil diperbarui.");
    }
}
