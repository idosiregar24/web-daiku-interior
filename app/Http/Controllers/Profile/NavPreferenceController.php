<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateNavPreferenceRequest;
use Illuminate\Http\Response;

/**
 * Sprint 13 Sub 01 — remembers which sidebar groups the user folded.
 * Own account only (no route parameter), every role. Saved in the
 * background by the sidebar (axios, not an Inertia visit — it must never
 * cancel the page visit the user is making), hence 204 instead of back().
 */
class NavPreferenceController extends Controller
{
    public function update(UpdateNavPreferenceRequest $request): Response
    {
        $user = $request->user();

        $user->forceFill([
            'nav_preferences' => [
                ...($user->nav_preferences ?? []),
                'collapsed_groups' => array_values($request->validated('collapsed_groups')),
            ],
        ])->save();

        return response()->noContent();
    }
}
