<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Services\UserService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
            // Sprint 21 (K4) — the username field is locked until then.
            'usernameChangeableAt' => $request->user()->usernameChangeableAt()?->toIso8601String(),
            'usernameChangeDays' => (int) config('daiku.username_change_days'),
        ]);
    }

    /**
     * Update the user's profile information — name, and (Sprint 21) their
     * own username and email, through UserService (40-day limit, audit).
     */
    public function update(ProfileUpdateRequest $request, UserService $service): RedirectResponse
    {
        $service->updateProfile($request->user(), $request->validated());

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    // No destroy(): Breeze's self-service account deletion was removed.
    // Deleting a user cascades away their penalties, Family Fund rows and
    // pipeline history (PRD §9.4 audit trail) — offboarding is the CEO
    // deactivating the account (`is_active`, User Management) instead.
}
