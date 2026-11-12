<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StylistInvitationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportStylistInvitations extends Command
{
    protected $signature = 'stylist-invitations:import
        {path : CSV file with the normalized English invitation headers}
        {--dry-run : Report eligible records without creating invitations}
        {--activation-export= : Write source references and one-time activation URLs to this CSV path}';

    protected $description = 'Create stylist invitations from the normalized legacy client directory';

    public function __construct(private readonly StylistInvitationService $invitations)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read CSV file: {$path}");
            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle);
        $expectedHeaders = [
            'source_reference', 'display_name', 'email', 'phone', 'address', 'city', 'postal_code',
            'business_name', 'business_address', 'business_city', 'business_phone',
        ];

        if ($headers !== $expectedHeaders) {
            $this->error('The CSV headers do not match the normalized invitation format.');
            return self::FAILURE;
        }

        $existingContacts = $this->existingContacts();
        $activationRows = [];
        $summary = ['created' => 0, 'eligible' => 0, 'no_contact' => 0, 'existing_account' => 0];

        while (($row = fgetcsv($handle)) !== false) {
            $attributes = array_combine($expectedHeaders, array_pad($row, count($expectedHeaders), null));
            $attributes = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $attributes);

            if (blank($attributes['display_name'])) {
                continue;
            }

            if (blank($attributes['email']) && blank($attributes['phone'])) {
                $summary['no_contact']++;
                continue;
            }

            if ($this->matchesExistingAccount($attributes, $existingContacts)) {
                $summary['existing_account']++;
                continue;
            }

            $summary['eligible']++;

            if ($this->option('dry-run')) {
                continue;
            }

            [$invitation, $token] = $this->invitations->create($this->fillBusinessDefaults($attributes));
            $activationRows[] = [
                $invitation->source_reference,
                $invitation->display_name,
                rtrim(config('app.frontend_url'), '/') . '/stylist/activate/' . $token,
                $invitation->expires_at->toIso8601String(),
            ];
            $summary['created']++;
        }

        fclose($handle);

        if (! $this->option('dry-run') && filled($this->option('activation-export'))) {
            $this->writeActivationExport((string) $this->option('activation-export'), $activationRows);
        }

        $verb = $this->option('dry-run') ? 'Eligible' : 'Created';
        $this->info("{$verb}: {$summary[$this->option('dry-run') ? 'eligible' : 'created']}");
        $this->line("Skipped without contact: {$summary['no_contact']}");
        $this->line("Skipped existing account: {$summary['existing_account']}");

        return self::SUCCESS;
    }

    private function existingContacts(): array
    {
        return User::withTrashed()
            ->get(['email', 'phone'])
            ->flatMap(fn (User $user) => [
                $this->normalizeEmail($user->email),
                $this->normalizePhone($user->phone),
            ])
            ->filter()
            ->flip()
            ->all();
    }

    private function matchesExistingAccount(array $attributes, array $existingContacts): bool
    {
        return isset($existingContacts[$this->normalizeEmail($attributes['email'])])
            || isset($existingContacts[$this->normalizePhone($attributes['phone'])]);
    }

    private function normalizeEmail(?string $value): ?string
    {
        return filled($value) ? mb_strtolower(trim($value)) : null;
    }

    private function normalizePhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return $digits !== '' ? $digits : null;
    }

    private function fillBusinessDefaults(array $attributes): array
    {
        return [
            ...$attributes,
            'business_name' => $attributes['business_name'] ?: $attributes['display_name'],
            'business_address' => $attributes['business_address'] ?: $attributes['address'],
            'business_city' => $attributes['business_city'] ?: $attributes['city'],
            'business_phone' => $attributes['business_phone'] ?: $attributes['phone'],
        ];
    }

    private function writeActivationExport(string $path, array $rows): void
    {
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'w');
        fputcsv($handle, ['source_reference', 'display_name', 'activation_url', 'expires_at']);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }
}
