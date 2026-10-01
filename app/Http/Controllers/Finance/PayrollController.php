<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\PaySalaryRequest;
use App\Models\BankAccount;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Services\PayrollService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.7 "Gaji Karyawan Tetap" — Sprint 9 decision #7. Salaries are
 * confidential: CEO reads, Finance pays and manages employees
 * (EmployeeController); every other role — PM included — is refused by
 * the route middleware. No edit/destroy: salary payments are append-only
 * (PRD §9.4).
 */
class PayrollController extends Controller
{
    public function index(Request $request): Response
    {
        $currentPeriod = now()->format('Y-m');
        $period = $this->period($request->string('period')->value(), $currentPeriod);
        $canManage = $request->user()->hasAnyRole(['FINANCE', 'SUPERADMIN']);

        $employees = Employee::query()
            ->with('user:id,name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $payments = SalaryPayment::query()
            ->forPeriod($period)
            ->with(['bankAccount:id,label', 'creator:id,name'])
            ->get()
            ->keyBy('employee_id');

        // Everyone payable in this period (active, already joined), plus
        // anyone deactivated since who was paid in it — history stays visible.
        $rows = $employees
            ->filter(fn (Employee $employee) => $payments->has($employee->id) || ($employee->is_active
                && (! $employee->join_date || $employee->join_date->format('Y-m') <= $period)))
            ->map(fn (Employee $employee) => [
                'employee' => $employee->only(['id', 'name', 'position', 'base_salary', 'bank_name', 'account_no', 'is_active']),
                'payment' => $payments->get($employee->id),
            ])
            ->values();

        $unpaid = $rows->whereNull('payment');

        return Inertia::render('Finance/Payroll/Index', [
            'period' => $period,
            'periodLabel' => PayrollService::periodLabel($period),
            'currentPeriod' => $currentPeriod,
            'rows' => $rows,
            'summary' => [
                'totalPaid' => round((float) $payments->sum('amount'), 2),
                'paidCount' => $payments->count(),
                'unpaidCount' => $unpaid->count(),
                'unpaidBaseTotal' => round((float) $unpaid->sum(fn (array $row) => (float) $row['employee']['base_salary']), 2),
            ],
            'employees' => $employees,
            'canManage' => $canManage,
            'bankAccounts' => $canManage
                ? BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label'])
                : [],
            'linkableUsers' => $canManage ? $this->linkableUsers() : [],
        ]);
    }

    public function pay(PaySalaryRequest $request, PayrollService $service): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));
        $payment = $service->pay($employee, $request->validated(), $request->user());

        return back()->with('success', "Gaji {$employee->name} periode ".PayrollService::periodLabel($payment->period).' berhasil dicatat.');
    }

    /** `YYYY-MM` from the query string; anything invalid or in the future falls back to the current month. */
    private function period(string $requested, string $currentPeriod): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) === 1 && $requested <= $currentPeriod
            ? $requested
            : $currentPeriod;
    }

    /**
     * Accounts the "Tautkan akun" select may offer: active non-field-staff
     * users (field staff are paid per task), plus whoever is linked today
     * so an existing link still shows its name.
     */
    private function linkableUsers(): Collection
    {
        return User::query()
            ->where(fn ($query) => $query->where('is_active', true)->withoutRole('FIELD_STAFF'))
            ->orWhereIn('id', Employee::query()->whereNotNull('user_id')->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
