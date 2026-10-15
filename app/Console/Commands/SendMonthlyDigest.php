<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunScanner;
use App\Notifications\Alerts\MonthlyDigestScanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Runs daily at 08:00. By default it queues the monthly digest scanner,
 * which only sends on the first working day of the month. --month sends one
 * past or current month now (mail itself stays queued); --dry-run lists who
 * would get what and sends and claims nothing.
 */
class SendMonthlyDigest extends Command
{
    protected $signature = 'reports:monthly-digest
        {--dry-run : List the digests without sending or claiming anything}
        {--month= : The month to report, as YYYY-MM (default: last month)}';

    protected $description = 'Send the monthly digest of the previous month on the first working day';

    public function handle(MonthlyDigestScanner $scanner): int
    {
        $month = null;

        if ($this->option('month') !== null) {
            $month = $this->parseMonth((string) $this->option('month'));

            if ($month === null) {
                $this->error(__('notifications.digests.monthly.invalid_month'));

                return self::FAILURE;
            }
        }

        if ($this->option('dry-run')) {
            $this->preview($scanner, $month ?? CarbonImmutable::now()->startOfMonth()->subMonth());

            return self::SUCCESS;
        }

        if ($month !== null) {
            $this->info(__('notifications.digests.monthly.console.sent', ['count' => $scanner->send($month), 'month' => $month->format('Y-m')]));

            return self::SUCCESS;
        }

        RunScanner::dispatch(MonthlyDigestScanner::class);
        $this->info(__('notifications.digests.monthly.console.queued'));

        return self::SUCCESS;
    }

    private function preview(MonthlyDigestScanner $scanner, CarbonImmutable $month): void
    {
        $this->info(__('notifications.digests.monthly.console.dry_run', ['month' => $month->format('Y-m')]));

        $this->table(
            [__('notifications.digests.monthly.console.recipient'), __('notifications.digests.monthly.console.scope'), __('notifications.digests.monthly.console.counts')],
            array_map(fn (array $digest): array => [
                $digest['user']->name,
                $digest['all_programs'] ? __('notifications.digests.monthly.console.all_programs') : implode(', ', $digest['program_ids']),
                collect($digest['sections'])
                    ->flatMap(fn (array $lines, string $section): array => collect($lines)->mapWithKeys(fn (int $count, string $line): array => ["{$section}.{$line}" => "{$section}.{$line}={$count}"])->all())
                    ->implode(' '),
            ], $scanner->preview($month)),
        );
    }

    /**
     * A YYYY-MM month that is not in the future, else null.
     */
    private function parseMonth(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return null;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $value);

        return $month instanceof CarbonImmutable && $month->lte(CarbonImmutable::now()->startOfMonth()) ? $month : null;
    }
}
