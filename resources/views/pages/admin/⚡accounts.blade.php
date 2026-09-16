<?php

use App\Models\Account;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.accounts')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $state = '';

    public bool $showAccountModal = false;
    public ?int $editingAccountId = null;
    public ?int $closingAccountId = null;
    public string $name = '';
    public string $openedAt = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->state = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Account> */
    #[Computed]
    public function accounts(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Account::query()
            ->select(['id', 'name', 'opened_at', 'closed_at', 'is_active', 'updated_at'])
            ->withCount('transfers')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($this->state === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->state === 'closed', fn ($query) => $query->where('is_active', false))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    public function openCreateModal(): void
    {
        $this->resetAccountForm();
        $this->showAccountModal = true;
    }

    public function openEditModal(int $accountId): void
    {
        $account = Account::query()->select(['id', 'name', 'opened_at'])->findOrFail($accountId);

        $this->resetValidation();
        $this->editingAccountId = $account->id;
        $this->name = $account->name;
        $this->openedAt = $account->opened_at?->toDateString() ?? '';
        $this->showAccountModal = true;
    }

    public function saveAccount(): void
    {
        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'openedAt' => ['required', 'date'],
        ], [
            'name.required' => __('accounts.enter_an_account_name'),
            'openedAt.required' => __('accounts.set_the_date_this_account_opened'),
        ]);

        $account = $this->editingAccountId === null
            ? new Account
            : Account::query()->findOrFail($this->editingAccountId);

        // is_active is deliberately not set here: the column default makes a
        // new account usable, and closing goes through Account::close().
        $account->fill([
            'name' => $validated['name'],
            'opened_at' => $validated['openedAt'],
        ])->save();

        $wasRecentlyCreated = $account->wasRecentlyCreated;

        $this->showAccountModal = false;
        $this->resetAccountForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('accounts.account_opened') : __('accounts.account_updated'),
        );
    }

    public function confirmClose(int $accountId): void
    {
        $this->closingAccountId = $accountId;
    }

    /**
     * Close an account so it can no longer receive transfers.
     *
     * Accounts are never deleted: transfers_tracking.account_id RESTRICTs
     * precisely so an account money has passed through survives. Account::close()
     * writes closed_at and is_active together, through forceFill, because
     * closed_at sits outside #[Fillable].
     */
    public function closeAccount(): void
    {
        if ($this->closingAccountId === null) {
            return;
        }

        Account::query()->findOrFail($this->closingAccountId)->close();

        $this->closingAccountId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('accounts.account_closed'));
    }

    public function closeAccountModal(): void
    {
        $this->showAccountModal = false;
        $this->resetAccountForm();
    }

    protected function resetAccountForm(): void
    {
        $this->resetValidation();
        $this->editingAccountId = null;
        $this->name = '';
        $this->openedAt = '';
    }
};
?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="account-directory-heading">
    <x-ui.page-header
        heading-id="account-directory-heading"
        :title="__('common.accounts')"
        :count="$this->accounts->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('accounts.search_accounts')" :placeholder="__('accounts.account_name')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('common.state')" wire:model.live="state" :value="$state">
                <flux:select.option value="">{{ __('common.all_accounts') }}</flux:select.option>
                <flux:select.option value="active">{{ __('accounts.open') }}</flux:select.option>
                <flux:select.option value="closed">{{ __('accounts.closed') }}</flux:select.option>
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-account-button">
                {{ __('accounts.open_account') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('accounts.count_matching_account', $this->accounts->total(), ['count' => $this->accounts->total()])">
        @if ($this->accounts->isEmpty())
            <x-ui.empty-state
                icon="building-library"
                :heading="$search !== '' || $state !== '' ? __('accounts.no_matching_accounts') : __('accounts.no_accounts_yet')"
                :body="$search !== '' || $state !== '' ? __('common.change_the_search_or_filters_and_try_again') : __('accounts.open_the_first_account')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->accounts as $account)
                    <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="account-{{ $account->id }}">
                        <div class="min-w-0 me-auto">
                            <p class="text-sm font-semibold">{{ $account->name }}</p>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ __('accounts.opened_date', ['date' => $account->opened_at?->translatedFormat(__('formats.date'))]) }}
                                @if ($account->closed_at !== null)
                                    · {{ __('accounts.closed_date', ['date' => $account->closed_at->translatedFormat(__('formats.date'))]) }}
                                @endif
                                · {{ trans_choice('accounts.count_transfer', $account->transfers_count, ['count' => $account->transfers_count]) }}
                            </p>
                        </div>

                        @if ($account->is_active)
                            <span class="rounded-md border border-secondary-dark bg-secondary-dark px-2 py-0.5 text-xs font-semibold text-white dark:border-secondary-medium dark:bg-secondary-medium">{{ __('accounts.open') }}</span>
                        @else
                            <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('accounts.closed') }}</span>
                        @endif

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $account->id }})" :aria-label="__('common.edit_name', ['name' => $account->name])" />

                        {{-- No delete anywhere: transfers_tracking.account_id RESTRICTs. --}}
                        @if ($account->is_active)
                            <x-ui.button tone="ghost" size="sm" icon="lock-closed" class="size-9!" wire:click="confirmClose({{ $account->id }})" :aria-label="__('accounts.close_name', ['name' => $account->name])" />
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->accounts" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#account-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showAccountModal" class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveAccount" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingAccountId === null ? __('accounts.open_account') : __('accounts.edit_account') }}</flux:heading>
                <flux:subheading>{{ __('accounts.a_new_account_is_usable_immediately_to_stop_it_receiving_tra') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('accounts.account_name')" type="text" maxlength="255" required autofocus />
            <x-ui.input wire:model="openedAt" :label="__('accounts.opened_on')" type="date" required />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeAccountModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-account-button">
                    {{ $editingAccountId === null ? __('accounts.open_account') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$closingAccountId !== null" wire:close="$set('closingAccountId', null)" class="w-full max-w-md border border-secondary-light! bg-white! dark:bg-secondary-dark!">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('accounts.close_this_account') }}</flux:heading>
            <flux:subheading>{{ __('accounts.it_stops_accepting_new_transfers_existing_transfer_records_a') }}</flux:subheading>
            <div class="flex justify-end gap-2 border-t border-secondary-soft pt-4 dark:border-secondary-medium">
                <x-ui.button type="button" wire:click="$set('closingAccountId', null)">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" wire:click="closeAccount" data-test="confirm-close-account">{{ __('accounts.close_account') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
