<?php

namespace App\Console\Commands;

use App\Services\OfferAuditor;
use Illuminate\Console\Command;

/**
 * Read-only: prices every offer and pick-any deal for a shopper and a salon
 * and checks the cart against the offer card. Changes nothing.
 */
class AuditOffers extends Command
{
    protected $signature = 'offers:audit
        {--markdown= : also write the report as Markdown to this file}
        {--fail-on-wrong : exit with code 1 when any line is WRONG}';

    protected $description = 'Check that every offer and pick-any deal costs in the cart what its offer card says (read-only)';

    public function handle(OfferAuditor $auditor): int
    {
        $report = $auditor->audit();

        $this->table(
            ['#', 'Offer', 'Step', 'Who', 'Expected', 'Real', 'Diff', 'No offer', 'Result', 'Why'],
            collect($report['rows'])->map(fn ($r) => [
                $r['bundleId'], mb_strimwidth($r['offer'], 0, 34, '…'), $r['case'], $r['audience'],
                $r['expected'], $r['real'], $r['difference'], $r['withoutOffer'], $r['verdict'],
                mb_strimwidth(implode('; ', $r['why']), 0, 90, '…'),
            ])->all()
        );

        $this->line(count($report['overlaps']) . ' products are in more than one offer or deal (see --markdown).');

        if ($path = $this->option('markdown')) {
            file_put_contents($path, OfferAuditor::markdown($report, 'Offer price audit ' . now('Europe/Skopje')->toDateString()));
            $this->info("Saved {$path}");
        }

        return $this->option('fail-on-wrong') && OfferAuditor::hasWrong($report) ? self::FAILURE : self::SUCCESS;
    }
}
