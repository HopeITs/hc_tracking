<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Admin\ManagesRoles;
use App\Livewire\Concerns\Admin\ManagesUserAccess;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for admin/users, with both traits that page gained when it was
 * split.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class ManagesUserAccess and
 * ManagesRoles are never analysed. It declares the page's own members the
 * traits read (the filters, selectedRoleId and showAssignedUsersModal); the
 * form properties live in the traits. Never instantiated.
 */
abstract class AdminUsersPageHost extends Component
{
    use ManagesRoles;
    use ManagesUserAccess;
    use WithPagination;

    public string $search = '';

    public string $role = '';

    public string $status = '';

    public ?int $selectedRoleId = null;

    public bool $showAssignedUsersModal = false;
}
