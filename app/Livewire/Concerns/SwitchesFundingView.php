<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use Livewire\Attributes\Url;

/**
 * The Table | Overview switch every Funding page shares. Table is the default.
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
        if (! in_array($this->view, ['table', 'overview'], true)) {
            $this->view = 'table';
        }
    }
}
