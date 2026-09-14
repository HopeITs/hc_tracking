<?php

use App\Models\DonorProposal;
use App\Models\TransfersTracking;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Funding Agreement')] class extends Component {
    public int $agreementId;

    public function mount(DonorProposal $donorProposal): void
    {
        $this->agreementId = $donorProposal->id;
    }

    #[Computed]
    public function agreement(): DonorProposal
    {
        return DonorProposal::query()
            ->select(['id', 'donor_id', 'proposal_id', 'budget', 'currency', 'project_num', 'updated_at'])
            ->with(['donor:id,name', 'proposal:id,name,status'])
            ->withCount('transfers')
            ->findOrFail($this->agreementId);
    }

    /** @return Collection<int, TransfersTracking> */
    #[Computed]
    public function transfers(): Collection
    {
        return TransfersTracking::query()
            ->select([
                'id', 'donor_proposal_id', 'account_id', 'payment_amount', 'amount_transfered_currency',
                'scheduled_date', 'transfer_date', 'fees', 'fees_currency', 'amount_received',
                'exchange_rate', 'net_amount_received', 'amount_received_currency', 'receive_date', 'ar_status',
            ])
            ->where('donor_proposal_id', $this->agreementId)
            // account_id is nullable: a tranche may be scheduled before an
            // account is assigned.
            ->with('account:id,name,is_active')
            ->orderByRaw('scheduled_date is null, scheduled_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Net received per currency.
     *
     * Grouped on amount_received_currency, which is a different column from
     * the transferred currency: a USD payment can land as SYP.
     *
     * @return array<string, float>
     */
    #[Computed]
    public function receivedByCurrency(): array
    {
        return $this->transfers
            ->filter(fn (TransfersTracking $transfer): bool => $transfer->net_amount_received !== null)
            ->groupBy(fn (TransfersTracking $transfer): string => $transfer->amount_received_currency->value)
            ->map(fn (Collection $group): float => (float) $group->sum(fn (TransfersTracking $transfer): float => (float) $transfer->net_amount_received))
            ->all();
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Funding Agreements'), 'url' => route('funding-agreements.index')],
            ['label' => $this->agreement->donor->name],
        ]"
        :title="$this->agreement->donor->name.' · '.$this->agreement->proposal->name"
        :subtitle="$this->agreement->project_num ?? __('No donor reference')"
    >
        <x-slot:meta>
            <a href="{{ route('donors.show', $this->agreement->donor) }}" wire:navigate class="font-medium hover:underline">{{ __('Donor page') }}</a>
            <a href="{{ route('proposals.show', $this->agreement->proposal) }}" wire:navigate class="font-medium hover:underline">{{ __('Proposal page') }}</a>
        </x-slot:meta>

        <x-slot:actions>
            <x-ui.enum-badge :case="$this->agreement->proposal->status" />
        </x-slot:actions>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-3">
        <x-ui.stat-tile
            :label="__('Committed')"
            :value="number_format((float) $this->agreement->budget, 2)"
            :unit="$this->agreement->currency->value"
            :scope="$this->agreement->currency->value"
        />

        @foreach ($this->receivedByCurrency as $currencyCode => $total)
            <x-ui.stat-tile :label="__('Net received')" :value="number_format($total, 2)" :unit="$currencyCode" :scope="$currencyCode" wire:key="received-{{ $currencyCode }}" />
        @endforeach
    </div>

    <x-ui.panel :title="__('Tranches')" :count="$this->transfers->count()" :subtitle="__('Scheduled first. The transferred and received currencies can differ.')">
        @if ($this->transfers->isEmpty())
            <x-ui.empty-state icon="arrow-right-circle" :heading="__('No tranches scheduled')" :body="__('Payments scheduled under this agreement will appear here.')" />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->transfers as $transfer)
                    <article class="grid gap-2 p-3.5 @3xl/detail:grid-cols-[1fr_auto_auto_auto]" wire:key="agreement-transfer-{{ $transfer->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">
                                {{ $transfer->scheduled_date?->format('M j, Y') ?? __('Unscheduled') }}
                            </p>
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                @if ($transfer->account === null)
                                    <span class="italic">{{ __('No account assigned') }}</span>
                                @else
                                    {{ $transfer->account->name }}@unless ($transfer->account->is_active) · {{ __('closed') }}@endunless
                                @endif
                                @if ($transfer->transfer_date !== null)
                                    · {{ __('sent :date', ['date' => $transfer->transfer_date->format('M j')]) }}
                                @endif
                            </p>
                        </div>

                        <x-ui.money :amount="$transfer->payment_amount" :currency="$transfer->amount_transfered_currency" />

                        <div class="text-xs text-secondary-medium tabular-nums dark:text-secondary-soft">
                            @if ($transfer->net_amount_received !== null)
                                <x-ui.money :amount="$transfer->net_amount_received" :currency="$transfer->amount_received_currency" />
                                <p>{{ __('rate :rate', ['rate' => rtrim(rtrim((string) $transfer->exchange_rate, '0'), '.')]) }}</p>
                            @else
                                <span>—</span>
                            @endif
                        </div>

                        <x-ui.enum-badge :case="$transfer->ar_status" />
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
</section>
