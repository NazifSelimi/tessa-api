<?php

namespace App\Services;

use App\Models\StylistInvitation;
use App\Models\StylistProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StylistInvitationService
{
    private const CITY_NAMES = [
        'битола' => 'Bitola', 'bitola' => 'Bitola',
        'велес' => 'Veles', 'veles' => 'Veles',
        'гостивар' => 'Gostivar', 'gostivar' => 'Gostivar',
        'демир хисар' => 'Demir Hisar', 'demir hisar' => 'Demir Hisar',
        'кавадарци' => 'Kavadarci', 'kavadarci' => 'Kavadarci',
        'кичево' => 'Kicevo', 'kicevo' => 'Kicevo',
        'куманово' => 'Kumanovo', 'kumanovo' => 'Kumanovo',
        'неготино' => 'Negotino', 'negotino' => 'Negotino',
        'охрид' => 'Ohrid', 'ohrid' => 'Ohrid',
        'прилеп' => 'Prilep', 'prilep' => 'Prilep',
        'скопје' => 'Skopje', 'skopje' => 'Skopje',
        'струга' => 'Struga', 'struga' => 'Struga',
        'струмица' => 'Strumica', 'strumica' => 'Strumica',
        'тетово' => 'Tetovo', 'tetovo' => 'Tetovo',
        'штип' => 'Stip', 'stip' => 'Stip',
    ];

    private const REQUIRED_FIELDS = [
        'phone',
        'address',
        'city',
        'postal_code',
        'business_name',
        'business_address',
        'business_city',
        'business_phone',
    ];

    public function create(array $attributes): array
    {
        $attributes = $this->normalizeLocations($attributes);
        $token = Str::random(64);
        $payload = [
            ...$attributes,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
            'activated_at' => null,
            'activated_user_id' => null,
        ];

        if (filled($attributes['source_reference'] ?? null)) {
            $existing = StylistInvitation::where('source_reference', $attributes['source_reference'])->first();

            if ($existing?->activated_at) {
                throw ValidationException::withMessages([
                    'source_reference' => ['This source client has already activated a stylist account.'],
                ]);
            }

            $invitation = $existing
                ? tap($existing)->update($payload)
                : StylistInvitation::create($payload);
        } else {
            $invitation = StylistInvitation::create($payload);
        }

        return [$invitation, $token];
    }

    public function resolve(string $token): ?StylistInvitation
    {
        return StylistInvitation::where('token_hash', hash('sha256', $token))->first();
    }

    public function missingFields(StylistInvitation $invitation): array
    {
        return array_values(array_filter(
            self::REQUIRED_FIELDS,
            fn (string $field) => blank($invitation->{$field})
        ));
    }

    public function activate(StylistInvitation $invitation, array $data): User
    {
        $data = $this->normalizeLocations($data);

        return DB::transaction(function () use ($invitation, $data) {
            $invitation->refresh();

            if (! $invitation->isAvailable()) {
                abort(410, 'This activation link has expired or has already been used.');
            }

            $user = new User([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => filled($data['email'] ?? null) ? Str::lower($data['email']) : null,
                'phone' => $data['phone'],
                'address' => $data['address'],
                'city' => $data['city'],
                'postcode' => $data['postal_code'],
                'password' => Hash::make($data['password']),
            ]);
            // Role fields are intentionally not mass assignable from public requests.
            $user->role = User::ROLE_STYLIST;
            $user->is_stylist = true;
            $user->phone_login = $this->phoneLogin($data['phone']);
            $user->save();

            StylistProfile::create([
                'user_id' => $user->id,
                // The current database uses these legacy column names; API fields stay English.
                'saloon_name' => $data['business_name'],
                'saloon_address' => $data['business_address'],
                'saloon_city' => $data['business_city'],
                'saloon_phone' => $data['business_phone'],
            ]);

            $invitation->update([
                'activated_at' => now(),
                'activated_user_id' => $user->id,
            ]);

            return $user;
        });
    }

    private function normalizeLocations(array $attributes): array
    {
        foreach (['city', 'business_city'] as $field) {
            if (! array_key_exists($field, $attributes) || blank($attributes[$field])) {
                continue;
            }

            $key = Str::lower(trim((string) $attributes[$field]));
            $attributes[$field] = self::CITY_NAMES[$key] ?? trim((string) $attributes[$field]);
        }

        return $attributes;
    }

    public function reissue(StylistInvitation $invitation): array
    {
        $token = Str::random(64);
        $invitation->update([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(30),
            'revoked_at' => null,
        ]);

        return [$invitation->fresh(), $token];
    }

    private function phoneLogin(string $phone): string
    {
        $normalized = preg_replace('/\D+/', '', $phone) ?: '';

        if (User::where('phone_login', $normalized)->exists()) {
            throw ValidationException::withMessages([
                'phone' => ['This phone number is already linked to an account.'],
            ]);
        }

        return $normalized;
    }
}
