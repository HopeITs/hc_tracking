<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\PreviewsFiles;
use Livewire\Component;

/**
 * Stands in for proposals/show, reports/show and tasks/show.
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class FilePreviewHost extends Component
{
    use PreviewsFiles;
}
