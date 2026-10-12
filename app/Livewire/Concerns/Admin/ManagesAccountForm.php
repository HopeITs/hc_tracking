<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Admin;

use App\Enums\Currency;
use App\Models\Account;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;
use Livewire\Attributes\Locked;

/**
 * The account form of the accounts page: the open/edit modal, saving, and the
 * close-account confirmation, with the properties only they use.
 *
 * Used by admin/accounts only. It calls resetPage() from WithPagination on
 * the page. The register's filter properties and queries are not touched
 * here.
 */
trait ManagesAccountForm
{
    public bool $showAccountModal = false;

    #[Locked]
    public ?int $editingAccountId = null;

    public string $accountName = '';

    public string $bankName = '';

    public string $currency = 'USD';

    public string $authorizedPerson = '';

    public string $accountNumber = '';

    public string $iban = '';

    public string $swiftCode = '';

    public string $country = '';

    public string $poBoxStreet = '';

    public string $companyAddress = '';

    public string $openedAt = '';

    /**
     * Read-only context shown in the edit modal alongside the editable
     * fields — is_active/closed_at are deliberately not editable here (they
     * only change through Account::close()), but the user should still see
     * them rather than the form looking incomplete.
     */
    public bool $editingAccountIsActive = true;

    public ?string $editingAccountClosedAt = null;

    public int $editingAccountTransfersCount = 0;

    /**
     * Paired with $closingAccountId. The close modal binds it with wire:model,
     * never :open (see .ai/rules/pages.md, "Bind flux:modal with wire:model").
     */
    public bool $showCloseModal = false;

    #[Locked]
    public ?int $closingAccountId = null;

    public function openCreateModal(): void
    {
        Gate::authorize('accounts.create');

        $this->resetAccountForm();
        $this->showAccountModal = true;
    }

    public function openEditModal(int $accountId): void
    {
        Gate::authorize('accounts.update');

        $account = Account::query()
            ->select(['id', 'account_name', 'bank_name', 'currency', 'authorized_person', 'account_number', 'iban', 'swift_code', 'country', 'po_box_street', 'company_address', 'opened_at', 'closed_at', 'is_active'])
            ->withCount('transfers')
            ->findOrFail($accountId);

        $this->resetValidation();
        $this->editingAccountId = $account->id;
        $this->accountName = $account->account_name;
        $this->bankName = $account->bank_name;
        $this->currency = $account->currency->value;
        $this->authorizedPerson = $account->authorized_person ?? '';
        $this->accountNumber = $account->account_number;
        $this->iban = $account->iban ?? '';
        $this->swiftCode = $account->swift_code ?? '';
        $this->country = $account->country;
        $this->poBoxStreet = $account->po_box_street ?? '';
        $this->companyAddress = $account->company_address ?? '';
        $this->openedAt = $account->opened_at?->toDateString() ?? '';
        $this->editingAccountIsActive = $account->is_active;
        $this->editingAccountClosedAt = $account->closed_at?->toDateString();
        $this->editingAccountTransfersCount = $account->transfers_count;
        $this->showAccountModal = true;
    }

    public function saveAccount(): void
    {
        Gate::authorize($this->editingAccountId === null ? 'accounts.create' : 'accounts.update');

        $this->accountName = trim($this->accountName);
        $this->bankName = trim($this->bankName);
        $this->authorizedPerson = trim($this->authorizedPerson);
        $this->accountNumber = trim($this->accountNumber);
        $this->iban = trim($this->iban);
        $this->swiftCode = trim($this->swiftCode);
        $this->country = trim($this->country);
        $this->poBoxStreet = trim($this->poBoxStreet);
        $this->companyAddress = trim($this->companyAddress);

        $validated = $this->validate([
            'accountName' => ['required', 'string', 'max:255'],
            'bankName' => ['required', 'string', 'max:255'],
            'currency' => ['required', new Enum(Currency::class)],
            'authorizedPerson' => ['nullable', 'string', 'max:255'],
            'accountNumber' => ['required', 'string', 'max:100'],
            'iban' => ['nullable', 'string', 'max:100'],
            'swiftCode' => ['nullable', 'string', 'max:50'],
            'country' => ['required', 'string', 'max:100'],
            'poBoxStreet' => ['nullable', 'string'],
            'companyAddress' => ['nullable', 'string'],
            'openedAt' => ['nullable', 'date'],
        ], [
            'accountName.required' => __('accounts.enter_an_account_name'),
            'bankName.required' => __('accounts.enter_a_bank_name'),
            'accountNumber.required' => __('accounts.enter_an_account_number'),
            'country.required' => __('accounts.enter_the_country'),
        ]);

        $account = $this->editingAccountId === null
            ? new Account
            : Account::query()->findOrFail($this->editingAccountId);

        // is_active is deliberately not set here: the column default makes a
        // new account usable, and closing goes through Account::close().
        $account->fill([
            'account_name' => $validated['accountName'],
            'bank_name' => $validated['bankName'],
            'currency' => $validated['currency'],
            'authorized_person' => $validated['authorizedPerson'] === '' ? null : $validated['authorizedPerson'],
            'account_number' => $validated['accountNumber'],
            'iban' => $validated['iban'] === '' ? null : $validated['iban'],
            'swift_code' => $validated['swiftCode'] === '' ? null : $validated['swiftCode'],
            'country' => $validated['country'],
            'po_box_street' => $validated['poBoxStreet'] === '' ? null : $validated['poBoxStreet'],
            'company_address' => $validated['companyAddress'] === '' ? null : $validated['companyAddress'],
            'opened_at' => $validated['openedAt'] === '' || $validated['openedAt'] === null ? null : $validated['openedAt'],
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
        Gate::authorize('accounts.delete');

        $this->closingAccountId = $accountId;
        $this->showCloseModal = true;
    }

    public function cancelClose(): void
    {
        $this->showCloseModal = false;
        $this->closingAccountId = null;
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

        // Closing is the account's end of life, so it sits behind accounts.delete.
        Gate::authorize('accounts.delete');

        Account::query()->findOrFail($this->closingAccountId)->close();

        $this->showCloseModal = false;
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
        $this->accountName = '';
        $this->bankName = '';
        $this->currency = 'USD';
        $this->authorizedPerson = '';
        $this->accountNumber = '';
        $this->iban = '';
        $this->swiftCode = '';
        $this->country = '';
        $this->poBoxStreet = '';
        $this->companyAddress = '';
        $this->openedAt = '';
        $this->editingAccountIsActive = true;
        $this->editingAccountClosedAt = null;
        $this->editingAccountTransfersCount = 0;
    }
}
