<?php

namespace App\Http\Requests\Auth;

use App\Support\Username;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sprint 21 Sub 03 (K2) — one "Email atau Username" field: with an `@` it
 * is an email, otherwise a username. Failures always read the same, so the
 * form never tells an outsider whether an account exists.
 */
class LoginRequest extends FormRequest
{
    private const FAILED = 'Email/username atau password salah.';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'Email atau username wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ];
    }

    /**
     * Attempt to authenticate the request's credentials. A deactivated
     * account (`is_active = false`, User Management) is refused whichever
     * way it signs in — with the same message as a wrong password.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            $this->loginField() => $this->identity(),
            'password' => $this->string('password')->value(),
            fn ($query) => $query->where('is_active', true),
        ];

        if (! Auth::attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'login' => self::FAILED,
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /** `email` when the value has an "@", `username` otherwise. */
    public function loginField(): string
    {
        return str_contains($this->string('login')->value(), '@') ? 'email' : 'username';
    }

    /** The login value as stored: lower-cased email, or Username::normalize(). */
    public function identity(): string
    {
        $value = $this->string('login')->value();

        return $this->loginField() === 'email'
            ? mb_strtolower(trim($value))
            : (string) Username::normalize($value);
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => 'Terlalu banyak percobaan masuk. Coba lagi dalam '.ceil($seconds / 60).' menit.',
        ]);
    }

    /**
     * 5 tries per normalized identity + IP — "BUDI" and "budi" share one
     * bucket, so changing case doesn't buy extra guesses.
     */
    public function throttleKey(): string
    {
        return Str::transliterate($this->identity().'|'.$this->ip());
    }
}
