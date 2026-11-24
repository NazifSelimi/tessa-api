<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * The phone number a user signs in with (users.phone_login): digits only and
 * unique. Used by the customer's own profile and by the admin user editor,
 * so both keep the sign-in phone in step with the phone shown on the account.
 */
final class PhoneLogin
{
    public static function sync(User $user, ?string $phone): void
    {
        // Production may still be on the pre-phone-first schema. Keeping this
        // guard makes the API backwards-compatible while staging uses the
        // normalized unique value required for phone-first stylist sign-in.
        if (! Schema::hasColumn('users', 'phone_login')) {
            return;
        }

        $normalized = preg_replace('/\D+/', '', (string) $phone) ?: '';

        if ($normalized === '') {
            return;
        }

        $exists = User::query()
            ->where('phone_login', $normalized)
            ->when($user->exists, fn ($query) => $query->whereKeyNot($user->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'phone' => [__('This phone number is already linked to an account.')],
            ]);
        }

        $user->phone_login = $normalized;
    }
}
