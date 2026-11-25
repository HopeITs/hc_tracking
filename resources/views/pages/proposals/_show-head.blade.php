{{--
    The head of proposals/show: breadcrumb, status, one primary button for
    the next step and a More actions menu (BuildsProposalHead::headActions).
--}}
@php
    $headActions = $this->headActions;
    $primaryAction = collect($headActions)->firstWhere('primary', true);
    $menuActions = array_values(array_filter($headActions, fn (array $action): bool => ! $action['primary']));
    // The data-test names the old action buttons carried, so existing tests keep finding them.
    $testHooks = [
        'move' => 'open-status-modal', 'approve' => 'open-approve-modal', 'reject' => 'open-status-modal',
        'edit' => 'open-details-modal', 'change-request' => 'request-change-button', 'change-open' => 'open-change-request',
        'agreement' => 'record-agreement-button', 'hold' => 'open-hold-modal', 'off-hold' => 'open-off-hold-modal',
        'extend' => 'open-extension-modal', 'final' => 'open-final-report-modal', 'note' => 'add-negotiation-note',
        'correct' => 'open-correct-status-modal', 'grant-dates' => 'open-grant-dates-modal',
    ];
    $hookFor = fn (array $action): string => $action['disabled'] ? 'head-disabled-'.$action['key'] : ($testHooks[$action['key']] ?? 'head-action-'.$action['key']);
@endphp

<header class="pp-head" data-test="proposal-head">
    <nav class="pp-crumb" aria-label="{{ __('proposals.page.breadcrumb') }}">
        <a href="{{ route('proposals.index') }}" wire:navigate.hover data-detail-back>{{ __('common.proposals') }}</a>
        <span aria-hidden="true">/</span>
        <span class="sr-only">{{ __('common.internal_project_number') }}</span>
        <x-ui.project-number :number="$this->proposal->internal_project_number" kind="internal" />
        <span @class(['pp-pill', 'pp-pill-red' => ! $this->proposal->isOnHold() && $this->proposal->status !== \App\Enums\ProposalStatus::Rejected, 'pp-pill-solid' => $this->proposal->status === \App\Enums\ProposalStatus::Rejected]) data-test="proposal-status">
            <span class="pp-dot" aria-hidden="true"></span>{{ $this->proposal->statusLabel() }}
        </span>
        @if ($this->proposal->isPayroll())
            <span class="pp-pill pp-pill-ink" data-test="payroll-kind-chip">{{ $this->proposal->kind->label() }}</span>
        @endif
    </nav>

    <h1 class="sr-only">{{ $this->proposal->name }}</h1>

    <div class="pp-head-actions" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false">
        @if ($primaryAction !== null)
            <button type="button" class="pp-btn pp-btn-primary" wire:click="{{ $primaryAction['call'] }}" @disabled($primaryAction['disabled']) @if ($primaryAction['why']) title="{{ $primaryAction['why'] }}" @endif data-test="{{ $hookFor($primaryAction) }}">{{ $primaryAction['label'] }}</button>
        @endif

        @if ($menuActions !== [])
            <button type="button" class="pp-btn" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="menu" data-test="head-more">
                {{ __('proposals.page.more_actions') }}
                <svg class="pp-chev" x-bind:class="open && 'pp-chev-up'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>
            <div class="pp-menu" role="menu" x-show="open" x-cloak x-transition.opacity.duration.100ms>
                @foreach ($menuActions as $action)
                    <button type="button" role="menuitem" wire:click="{{ $action['call'] }}" x-on:click="open = false" @disabled($action['disabled']) data-test="{{ $hookFor($action) }}">
                        {{ $action['label'] }}
                        @if ($action['why'])
                            <span class="pp-why">{{ $action['why'] }}</span>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</header>
