<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A report template turned `confirmed` for the first time (contracts.md §5,
 * Y-03). Its monthly reports are generated from it.
 */
final class ReportTemplateConfirmed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $templateId,
    ) {}
}
