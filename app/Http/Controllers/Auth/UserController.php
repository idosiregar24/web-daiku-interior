<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreUserRequest;
use App\Http\Requests\Auth\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * PRD §7.1 doesn't itemize "User Management" — scoped CEO-only via route
 * middleware (see routes/web.php) since assigning roles is an admin-level
 * action. CSV Sprint 1 Week 2: "User Management: CRUD user + assign role".
 */
class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::query()
            ->with('roles:id,name')
            ->latest()
            ->paginate(15);

        return Inertia::render('Auth/Users/Index', [
            'users' => $users,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Auth/Users/Create', [
            'roles' => Role::query()->orderBy('name')->pluck('name'),
        ]);
    }

    public function store(StoreUserRequest $request, UserService $service)
    {
        $service->create($request->validated());

        return redirect()->route('users.index')->with('success', 'User berhasil ditambahkan.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('Auth/Users/Edit', [
            'user' => $user->load('roles:id,name'),
            'roles' => Role::query()->orderBy('name')->pluck('name'),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UserService $service)
    {
        $service->update($user, $request->validated());

        return redirect()->route('users.index')->with('success', 'User berhasil diperbarui.');
    }
}
