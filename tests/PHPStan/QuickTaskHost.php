<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\AddsQuickTask;
use Livewire\Component;

/**
 * Stands in for proposals/show, projects/show, programs/show and
 * milestones/show.
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class QuickTaskHost extends Component
{
    use AddsQuickTask;
}
