<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

/**
 * The view switch every Funding page shares: Table | Overview, plus any view
 * the page adds by overriding fundingViews() (Board, Calendar). Table is the
 * default.
 */
trait SwitchesFundingView
{
    #[Url(except: 'table')]
    public string $view = 'table';

    public function mountSwitchesFundingView(): void
    {
        $this->normaliseFundingView();
    }

    public function updatedSwitchesFundingView(string $property): void
    {
        if ($property === 'view') {
            $this->normaliseFundingView();
        }
    }

    protected function normaliseFundingView(): void
    {
        if (! in_array($this->view, $this->fundingViews(), true)) {
            $this->view = 'table';
        }
    }

    /**
     * @return list<string>
     */
    protected function fundingViews(): array
    {
        return ['table', 'overview'];
    }
}
