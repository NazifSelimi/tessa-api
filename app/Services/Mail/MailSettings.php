<?php

namespace App\Services\Mail;

use App\Models\Setting;
use App\Services\BusinessProfile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

/**
 * How the shop sends email, set by an admin on /admin/emails → Settings
 * (their own mailbox: Gmail, Outlook, hosting mail, Brevo…). Falls back to the
 * server .env when nothing is saved.
 *
 * Outside production every email goes to one test address (or only to the log
 * when none is set), so a staging copy of real customer data can never email
 * real customers.
 */
class MailSettings
{
    public const AUTO_EMAILS = [
        'order_confirmation' => ['label' => 'Order confirmation to the customer', 'default' => true],
        'order_status' => ['label' => 'Order status changes to the customer (confirmed, shipped, cancelled)', 'default' => true],
        'admin_new_order' => ['label' => 'New order alert to the shop', 'default' => true],
        'admin_stylist_request' => ['label' => 'New stylist application alert to the shop', 'default' => true],
        'stylist_status' => ['label' => 'Stylist application approved / rejected to the stylist', 'default' => true],
    ];

    /** Ready-made settings for common mailboxes, shown as one-click presets. */
    public const PRESETS = [
        'hostinger' => ['label' => 'Hostinger Email (recommended mailbox)', 'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl',
            'help' => 'Username = the full mailbox address (e.g. shop@yourdomain.mk), password = that mailbox\'s password from hPanel → Emails. Port 465 SSL (or 587 TLS). Set SPF, DKIM and DMARC in hPanel → Emails → DNS so mail does not land in spam. Plans allow a limited number of emails per day.'],
        'gmail' => ['label' => 'Gmail / Google Workspace', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Turn on 2-step verification, then create an App Password at myaccount.google.com/apppasswords and paste it as the password. Up to ~500 emails a day (2,000 on Workspace).'],
        'outlook' => ['label' => 'Outlook / Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Use the full email address as the username. Microsoft 365 admins may need to allow "Authenticated SMTP" for the mailbox.'],
        'zoho' => ['label' => 'Zoho Mail', 'host' => 'smtp.zoho.eu', 'port' => 465, 'encryption' => 'ssl',
            'help' => 'Use an application-specific password if two-factor login is on.'],
        'hosting' => ['label' => 'My website hosting (cPanel / Plesk)', 'host' => 'mail.yourdomain.mk', 'port' => 465, 'encryption' => 'ssl',
            'help' => 'Create a mailbox such as shop@yourdomain.mk in the hosting panel; it shows the server name. Usually port 465 (SSL) or 587 (TLS).'],
        'brevo' => ['label' => 'Brevo (newsletters, high volume)', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Free plan: 300 emails a day. Verify your sender address/domain in Brevo, then use the SMTP login and key from Brevo → SMTP & API.'],
    ];

    /** Free-plan sending limits (per day) used to spread group emails; admins can lower or raise it. */
    public const DEFAULT_DAILY_LIMIT = ['resend' => 100, 'smtp' => 300, 'env' => 300, 'off' => 1000000];

    public const RESEND_MONTHLY_LIMIT = 3000;

    public function __construct(private readonly BusinessProfile $business) {}

    public function raw(string $key, mixed $default = null): mixed
    {
        return Setting::get("mail.$key", $default);
    }

    /** Settings for the admin page; the password is never sent back. */
    public function forAdmin(): array
    {
        $auto = [];
        foreach (self::AUTO_EMAILS as $key => $meta) {
            $auto[] = ['key' => $key, 'label' => $meta['label'], 'enabled' => $this->autoEnabled($key)];
        }

        return [
            'mode' => $this->raw('mode', 'env'), // env = server file, resend = Resend API, smtp = own mailbox, off = don't send (log only)
            'hasResendKey' => (string) $this->raw('resend_key', '') !== '' || (string) config('services.resend.key') !== '',
            'resendKeyFromServer' => (string) $this->raw('resend_key', '') === '' && (string) config('services.resend.key') !== '',
            'dailyLimit' => $this->dailyLimit(),
            'host' => (string) $this->raw('host', ''),
            'port' => (int) $this->raw('port', 587),
            'encryption' => (string) $this->raw('encryption', 'tls'),
            'username' => (string) $this->raw('username', ''),
            'hasPassword' => (string) $this->raw('password', '') !== '',
            'fromAddress' => (string) $this->raw('from_address', ''),
            'fromName' => (string) $this->raw('from_name', ''),
            'replyTo' => (string) $this->raw('reply_to', ''),
            'adminRecipients' => (string) $this->raw('admin_recipients', (string) config('tessa.admin_email', '')),
            'testRecipient' => (string) $this->raw('test_recipient', ''),
            'isProduction' => app()->isProduction(),
            'effective' => $this->describe(),
            'autoEmails' => $auto,
            'presets' => collect(self::PRESETS)->map(fn ($preset, $key) => ['key' => $key] + $preset)->values(),
        ];
    }

    public function update(array $data): void
    {
        $map = [
            'mode' => 'mode', 'host' => 'host', 'port' => 'port', 'encryption' => 'encryption', 'username' => 'username',
            'fromAddress' => 'from_address', 'fromName' => 'from_name', 'replyTo' => 'reply_to',
            'adminRecipients' => 'admin_recipients', 'testRecipient' => 'test_recipient', 'dailyLimit' => 'daily_limit',
        ];
        foreach ($map as $input => $key) {
            if (array_key_exists($input, $data)) {
                Setting::put("mail.$key", is_string($data[$input]) ? trim($data[$input]) : $data[$input]);
            }
        }
        // Empty password field = keep the saved one; "clearPassword" removes it.
        if (! empty($data['password'])) {
            Setting::put('mail.password', Crypt::encryptString((string) $data['password']));
        } elseif (! empty($data['clearPassword'])) {
            Setting::put('mail.password', '');
        }
        if (! empty($data['resendKey'])) {
            Setting::put('mail.resend_key', Crypt::encryptString((string) $data['resendKey']));
        } elseif (! empty($data['clearResendKey'])) {
            Setting::put('mail.resend_key', '');
        }
        foreach ($data['autoEmails'] ?? [] as $key => $on) {
            if (isset(self::AUTO_EMAILS[$key])) {
                Setting::put("mail.auto.$key", $on ? 1 : 0);
            }
        }
    }

    public function autoEnabled(string $key): bool
    {
        $default = self::AUTO_EMAILS[$key]['default'] ?? true;

        return (bool) (int) $this->raw("auto.$key", $default ? 1 : 0);
    }

    /** @return list<string> */
    public function adminRecipients(): array
    {
        $raw = (string) $this->raw('admin_recipients', (string) config('tessa.admin_email', ''));

        return collect(preg_split('/[\s,;]+/', $raw))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }

    /** Where mail really goes right now: 'smtp', 'redirected' (test address) or 'log'. */
    public function deliveryMode(): string
    {
        if (! app()->isProduction()) {
            return $this->testRecipient() ? 'redirected' : 'log';
        }
        $mode = $this->raw('mode', 'env');
        if ($mode === 'off') {
            return 'log';
        }
        if ($mode === 'smtp' || $mode === 'resend') {
            return 'smtp';
        }

        return config('mail.default') === 'log' ? 'log' : 'smtp';
    }

    public function testRecipient(): ?string
    {
        $address = trim((string) $this->raw('test_recipient', ''));

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }

    public function describe(): string
    {
        return match ($this->deliveryMode()) {
            'redirected' => 'Test server: every email goes to ' . $this->testRecipient() . ' only.',
            'log' => app()->isProduction()
                ? 'Sending is off: emails are only written to the server log.'
                : 'Test server: emails are only logged. Add a test address to receive them.',
            default => 'Emails are delivered through ' . match ($this->raw('mode', 'env')) {
                'smtp' => (string) $this->raw('host'),
                'resend' => 'Resend',
                default => 'the server mail settings',
            } . '.',
        };
    }

    /** Points Laravel's mailer at the saved settings. Call before every send. */
    public function apply(): void
    {
        $mode = $this->raw('mode', 'env');
        $profile = $this->business->all();

        if ($mode === 'resend' && $this->resendKey()) {
            config(['mail.default' => 'resend', 'services.resend.key' => $this->resendKey(), 'resend.api_key' => $this->resendKey()]);
            // The Resend client is a singleton; rebuild it so a newly saved key is used.
            app()->forgetInstance(\Resend\Contracts\Client::class);
        }

        if ($mode === 'smtp' && $this->raw('host')) {
            $encryption = (string) $this->raw('encryption', 'tls');
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => (string) $this->raw('host'),
                'mail.mailers.smtp.port' => (int) $this->raw('port', 587),
                'mail.mailers.smtp.scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
                'mail.mailers.smtp.username' => (string) $this->raw('username', '') ?: null,
                'mail.mailers.smtp.password' => $this->password(),
                'mail.mailers.smtp.timeout' => 20,
            ]);
        }

        $fromAddress = (string) $this->raw('from_address', '') ?: (string) config('mail.from.address');
        $fromName = (string) $this->raw('from_name', '') ?: ($profile['name'] ?: (string) config('mail.from.name'));
        config(['mail.from.address' => $fromAddress, 'mail.from.name' => $fromName]);

        $delivery = $this->deliveryMode();
        if ($delivery === 'log') {
            config(['mail.default' => 'log']);
        }

        Mail::purge();
        $mailer = Mail::mailer();
        if ($delivery === 'redirected') {
            $mailer->alwaysTo($this->testRecipient());
        }
        // Replies reach a person: the saved reply-to, else the shop's contact mailbox.
        $replyTo = (string) $this->raw('reply_to', '') ?: $profile['email'];
        if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mailer->alwaysReplyTo($replyTo, $fromName);
        }
    }

    /** How many emails a day the connected mailbox may send (group emails are spread to stay under it). */
    public function dailyLimit(): int
    {
        $saved = (int) $this->raw('daily_limit', 0);
        if ($saved > 0) {
            return $saved;
        }

        return self::DEFAULT_DAILY_LIMIT[$this->raw('mode', 'env')] ?? 300;
    }

    public function resendKey(): ?string
    {
        $stored = (string) $this->raw('resend_key', '');
        if ($stored !== '') {
            try {
                return Crypt::decryptString($stored);
            } catch (\Throwable) {
                return null;
            }
        }

        return (string) config('services.resend.key') ?: null;
    }

    private function password(): ?string
    {
        $stored = (string) $this->raw('password', '');
        if ($stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }
}
