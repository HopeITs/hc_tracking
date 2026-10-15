<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\AddsQuickTask;
use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\ManagesAgreementForm;
use App\Livewire\Concerns\ManagesTransferForm;
use App\Livewire\Concerns\PreviewsFiles;
use App\Livewire\Concerns\Proposals\ChartsProposalChronology;
use App\Livewire\Concerns\Proposals\ManagesProposalFiles;
use App\Livewire\Concerns\Proposals\ManagesProposalForm;
use App\Livewire\Concerns\Proposals\ManagesProposalRecord;
use App\Livewire\Concerns\Proposals\ManagesProposalReviewers;
use App\Livewire\Concerns\Proposals\ManagesProposalStatus;
use App\Livewire\Concerns\Proposals\ReadsProposalDelivery;
use App\Livewire\Concerns\Proposals\ReadsProposalFunding;
use App\Livewire\Concerns\Proposals\ReadsProposalHealth;
use App\Livewire\Concerns\Proposals\ReadsProposalVitals;
use App\Livewire\Concerns\SwitchesFundingView;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Stands in for proposals/show, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the proposal traits are never
 * analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class ProposalsShowPageHost extends Component
{
    use AddsQuickTask;
    use ChartsProposalChronology;
    use ChecksFinanceVisibility;
    use ManagesAgreementForm;
    use ManagesProposalFiles;
    use ManagesProposalForm;
    use ManagesProposalRecord;
    use ManagesProposalReviewers;
    use ManagesProposalStatus;
    use ManagesTransferForm;
    use PreviewsFiles;
    use ReadsProposalDelivery;
    use ReadsProposalFunding;
    use ReadsProposalHealth;
    use ReadsProposalVitals;
    use SwitchesFundingView;
    use WithFileUploads;

    public int $proposalId;

    private function forgetReadings(): void {}
}
