<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 20 Sub 03 (D5) — the system and the company profile share one
 * domain. Every response of the `web` group (login, Inertia pages, PDFs,
 * the client's offer link) tells search engines not to index it; only the
 * company profile's home page and the public brand images are exempt.
 * The other profile pages are in the `site` group and never pass here.
 */
class NoIndexSystemPages
{
    private const INDEXABLE = ['site.home', 'branding.show'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->route()?->getName(), self::INDEXABLE, true) && ! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
