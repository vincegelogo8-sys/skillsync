<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public const ADMIN_ONLY_MESSAGE = 'Administrators must sign in through the Administrator Portal.';

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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(bool $adminPortal = false): void
    {
        $this->ensureIsNotRateLimited();

        $credentials = $this->only('email', 'password');
        if (! Auth::validate($credentials)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = User::where('email', $credentials['email'])->first();

        // Each portal only accepts its own accounts. /admin/login treats anyone who is not an
        // admin like a wrong password; /login tells admins where to sign in instead.
        if ($adminPortal && $user?->role !== User::ROLE_ADMIN) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        if (! $adminPortal && $user?->role === User::ROLE_ADMIN) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => self::ADMIN_ONLY_MESSAGE,
            ]);
        }

        if ($user?->isDeactivated()) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'This account has been deactivated. Please contact the administrator.',
            ]);
        }

        Auth::login($user, $this->boolean('remember'));
        RateLimiter::clear($this->throttleKey());
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
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}