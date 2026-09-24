<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §9.4 Audit Trail viewer — CEO (+ SUPERADMIN bypass), read-only.
 * There is intentionally no update/destroy action here or anywhere else
 * ("Audit log tidak bisa dihapus oleh siapapun, termasuk CEO" — CLAUDE.md
 * golden rule #7).
 */
class AuditLogController extends Controller
{
    /** Action prefixes written by AuditLogService callers — the page's area filter. */
    private const AREAS = [
        'quotation' => 'Quotation',
        'qa' => 'QA',
        'finance' => 'Finance',
        'overtime' => 'Lembur',
        'penalty' => 'Penalti',
        'user' => 'User',
        'analytics' => 'Target',
    ];

    public function index(Request $request): Response
    {
        $area = $request->string('area')->value();

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->actionPrefix(array_key_exists($area, self::AREAS) ? $area : null)
            ->when($request->integer('user_id'), fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($request->date('from'), fn ($query, $from) => $query->where('created_at', '>=', $from->startOfDay()))
            ->when($request->date('to'), fn ($query, $to) => $query->where('created_at', '<=', $to->endOfDay()))
            ->latest('created_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('AuditLogs/Index', [
            'logs' => $logs,
            'filters' => $request->only(['area', 'user_id', 'from', 'to']),
            'areas' => self::AREAS,
            'actors' => User::whereIn('id', AuditLog::whereNotNull('user_id')->distinct()->select('user_id'))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
