<?php

namespace App\Services\Mail;

/**
 * Checks the sending domain's DNS so emails don't land in spam: SPF, DKIM
 * (Resend and Hostinger selectors), DMARC and the Resend bounce domain.
 * Uses PHP's own DNS lookups: free, no outside service.
 */
class DeliverabilityCheck
{
    /** @return array{domain: string, checks: list<array{key: string, label: string, status: 'ok'|'warn'|'bad', found: string|null, fix: string|null}>} */
    public function run(string $domain): array
    {
        $domain = strtolower(trim($domain, ". \t\n"));
        $root = $this->rootDomain($domain);
        $checks = [];

        $spf = $this->txt($domain, 'v=spf1');
        $sendSpf = $this->txt('send.' . $domain, 'v=spf1');
        $checks[] = $spf || $sendSpf
            ? $this->item('spf', 'SPF (who may send)', 'ok', $spf ?? ('send.' . $domain . ': ' . $sendSpf))
            : $this->item('spf', 'SPF (who may send)', 'bad', null, "Add a TXT record on {$domain} like \"v=spf1 include:_spf.mail.hostinger.com ~all\" (Hostinger) — Resend shows its own record when you add the domain.");

        $dkim = null;
        foreach (['resend', 'hostingermail-a', 'hostingermail-b', 'hostingermail-c', 'default', 'google', 'selector1', 'selector2'] as $selector) {
            $host = "{$selector}._domainkey.{$domain}";
            if ($this->txt($host, 'p=') || $this->cname($host)) {
                $dkim = $host;
                break;
            }
        }
        $checks[] = $dkim
            ? $this->item('dkim', 'DKIM (signed emails)', 'ok', $dkim)
            : $this->item('dkim', 'DKIM (signed emails)', 'bad', null, 'Turn on DKIM for this domain in your email provider (Resend → Domains, or Hostinger hPanel → Emails → DNS) and add the records it shows.');

        $dmarc = $this->txt('_dmarc.' . $domain, 'v=DMARC1') ?? ($root !== $domain ? $this->txt('_dmarc.' . $root, 'v=DMARC1') : null);
        $checks[] = match (true) {
            $dmarc === null => $this->item('dmarc', 'DMARC (policy)', 'warn', null, "Add a TXT record _dmarc.{$root} = \"v=DMARC1; p=none\" (Gmail and Yahoo expect one for bulk mail)."),
            default => $this->item('dmarc', 'DMARC (policy)', 'ok', $dmarc),
        };

        $mx = @dns_get_record('send.' . $domain, DNS_MX) ?: @dns_get_record($domain, DNS_MX) ?: [];
        $checks[] = $mx
            ? $this->item('mx', 'Bounce / reply handling (MX)', 'ok', collect($mx)->pluck('target')->implode(', '))
            : $this->item('mx', 'Bounce / reply handling (MX)', 'warn', null, 'No MX record: bounces and replies to this address cannot be received. Set Reply-to to a real mailbox.');

        return ['domain' => $domain, 'checks' => $checks];
    }

    private function item(string $key, string $label, string $status, ?string $found, ?string $fix = null): array
    {
        return compact('key', 'label', 'status', 'found', 'fix');
    }

    private function txt(string $host, string $prefix): ?string
    {
        foreach (@dns_get_record($host, DNS_TXT) ?: [] as $record) {
            $value = $record['txt'] ?? implode('', $record['entries'] ?? []);
            if (stripos($value, $prefix) !== false) {
                return mb_substr($value, 0, 200);
            }
        }

        return null;
    }

    private function cname(string $host): ?string
    {
        $records = @dns_get_record($host, DNS_CNAME) ?: [];

        return $records[0]['target'] ?? null;
    }

    private function rootDomain(string $domain): string
    {
        $parts = explode('.', $domain);

        return count($parts) > 2 ? implode('.', array_slice($parts, -2)) : $domain;
    }
}
