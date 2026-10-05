<?php

namespace App\Http\Controllers;

use App\Services\ActionInboxService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 13 #4 — "Perlu Tindakan": every queue waiting for the signed-in
 * user. Every role (a role without a queue simply sees "Semua beres");
 * what each queue holds is scoped in ActionInboxService.
 */
class InboxController extends Controller
{
    public function index(Request $request, ActionInboxService $service): Response
    {
        return Inertia::render('Inbox/Index', [
            // Fresh on its own page — the cached copy is for the badges.
            'groups' => $service->for($request->user(), fresh: true),
        ]);
    }
}
