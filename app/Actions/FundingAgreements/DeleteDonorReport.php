<?php

declare(strict_types=1);

namespace App\Actions\FundingAgreements;

use App\Models\DonorReport;
use Illuminate\Support\Facades\Gate;

class DeleteDonorReport
{
    public function handle(DonorReport $report): void
    {
        Gate::authorize('funding-agreements.update');

        $report->delete();
    }
}
