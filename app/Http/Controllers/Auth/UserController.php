<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreUserRequest;
use App\Http\Requests\Auth\UpdateUserRequest;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
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
    public function index(Request $request): Response
    {
        // Sprint 21 — name, username ("@budi" or "budi") and email.
        $search = ltrim(trim($request->string('search')->value()), '@');

        $users = User::query()
            ->with('roles:id,name')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('username', 'like', '%'.mb_strtolower($search).'%')
                ->orWhere('email', 'like', '%'.mb_strtolower($search).'%')))
            ->latest()
            ->paginate(15)
            ->withQueryString()
            // A stacked role (Kepala Desain) is what's shown and edited, not its base role.
            ->through(fn (User $user) => [...$user->toArray(), 'assignable_role' => $user->assignableRoleName()]);

        return Inertia::render('Auth/Users/Index', [
            'users' => $users,
            'filters' => ['search' => $request->string('search')->value()],
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
            'user' => [...$user->load('roles:id,name')->toArray(), 'assignable_role' => $user->assignableRoleName()],
            'roles' => Role::query()->orderBy('name')->pluck('name'),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UserService $service)
    {
        $service->update($user, $request->validated(), $request->user());

        return redirect()->route('users.index')->with('success', 'User berhasil diperbarui.');
    }
}
