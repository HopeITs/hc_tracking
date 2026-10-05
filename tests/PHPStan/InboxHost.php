<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\ManagesInbox;
use Livewire\Component;

/**
 * Stands in for the notification bell and the notifications page.
 *
 * The real pages are single-file Livewire components inside Blade, which
 * PHPStan cannot read, so without this class the trait is never analysed.
 * Never instantiated.
 */
abstract class InboxHost extends Component
{
    use ManagesInbox;
}
