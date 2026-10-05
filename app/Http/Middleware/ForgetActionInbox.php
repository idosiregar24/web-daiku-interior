<?php

namespace App\Http\Middleware;

use App\Services\ActionInboxService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 13 D4 — the "Perlu Tindakan" counts are cached 60 s per user;
 * after any write the user makes (approve, verify, submit…) their own
 * cache is dropped, so the page they are sent back to shows the queue
 * without the item they just processed. One hook here instead of a
 * forget() call in every service that can empty a queue.
 */
class ForgetActionInbox
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $response = $next($request);

        if ($user && ! $request->isMethodSafe()) {
            ActionInboxService::forget($user);
        }

        return $response;
    }
}
