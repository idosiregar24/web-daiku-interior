<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * `AuthorizesRequests` gives every controller `$this->authorize()` for
 * Policy checks (security-standards.md §2 layer 2) — Laravel 11's default
 * skeleton Controller doesn't include it out of the box.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
