<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CheckUsernameRequest;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;

/**
 * Sprint 21 Sub 02 — live availability hint for UsernameInput. Guidance
 * only: the final word is the Form Request's `unique` rule and the UNIQUE
 * index on `users.username`.
 */
class UsernameCheckController extends Controller
{
    public function __invoke(CheckUsernameRequest $request, UserService $service): JsonResponse
    {
        return response()->json($service->checkUsername(
            $request->validated('username') ?? '',
            $request->targetUser(),
            $request->validated('name'),
        ));
    }
}
