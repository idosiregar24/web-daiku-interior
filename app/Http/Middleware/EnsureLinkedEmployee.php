<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `employee.self` — gate of the "Milik Saya" pages (Sprint 10 decisions
 * #6/#11): the signed-in user must be linked to an active, HR-eligible
 * employee row. Field staff are never linked, so they always get 403.
 * The employee is handed to the controller as the `employee` request
 * attribute, so no page can be pointed at someone else's data.
 */
class EnsureLinkedEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if(! $user || $user->hasRole('FIELD_STAFF'), 403);

        $employee = Employee::query()->hrEligible()->active()->where('user_id', $user->id)->first();

        abort_unless($employee, 403, 'Akun Anda belum ditautkan ke data karyawan.');

        $request->attributes->set('employee', $employee);

        return $next($request);
    }
}
