<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required_without:email', 'nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $identifier = $this->identifier();
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone_login';

        if (! Auth::attempt([$field => $field === 'email' ? Str::lower($identifier) : $this->normalizePhone($identifier), 'password' => $this->password])) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'identifier' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return Str::lower($this->identifier()).'|'.$this->ip();
    }

    private function identifier(): string
    {
        return (string) ($this->input('identifier') ?: $this->input('email'));
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?: '';
    }
}
