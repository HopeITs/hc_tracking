{{--
    The renewal chain of proposals/show (T10): previous ← this → next(s). Shown only when the
    proposal is part of a chain. Names, numbers and statuses only. Included by ⚡show.blade.php.
--}}
@php($chain = $this->renewalChain)
@if ($chain['previous'] !== null || $chain['removed'] || $chain['next']->isNotEmpty())
    <nav class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-2 text-sm" aria-label="{{ __('proposals.renewal_chain') }}" data-test="renewal-chain">
        <span class="type-label text-secondary-medium dark:text-ink-muted">{{ __('proposals.renewal_chain') }}</span>

        @if ($chain['previous'] !== null)
            <a href="{{ route('proposals.show', $chain['previous']) }}" wire:navigate class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-secondary-soft px-3 text-secondary-dark hover:underline dark:border-line dark:text-ink" data-test="renewal-chain-previous">
                <flux:icon.arrow-right variant="micro" class="size-4 shrink-0 rtl:rotate-180" aria-hidden="true" />
                <span class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.renewal_chain_previous') }}</span>
                <span class="font-semibold tabular-nums" dir="ltr">{{ $chain['previous']->internal_project_number }}</span>
                <x-ui.enum-badge :case="$chain['previous']->status" />
            </a>
        @elseif ($chain['removed'])
            <span class="inline-flex min-h-10 items-center rounded-lg border border-dashed border-secondary-light px-3 text-secondary-medium dark:text-ink-muted" data-test="renewal-chain-removed">{{ __('proposals.renewal_chain_removed') }}</span>
        @endif

        <span class="inline-flex min-h-10 items-center rounded-lg bg-secondary-soft px-3 font-semibold text-secondary-dark dark:bg-surface-2 dark:text-ink" aria-current="page">{{ __('proposals.renewal_chain_current') }}</span>

        @foreach ($chain['next'] as $renewal)
            <a href="{{ route('proposals.show', $renewal) }}" wire:navigate class="inline-flex min-h-10 items-center gap-1.5 rounded-lg border border-secondary-soft px-3 text-secondary-dark hover:underline dark:border-line dark:text-ink" wire:key="renewal-next-{{ $renewal->id }}" data-test="renewal-chain-next">
                <span class="text-xs text-secondary-medium dark:text-ink-muted">{{ __('proposals.renewal_chain_next') }}</span>
                <span class="font-semibold tabular-nums" dir="ltr">{{ $renewal->internal_project_number }}</span>
                <x-ui.enum-badge :case="$renewal->status" />
                <flux:icon.arrow-right variant="micro" class="size-4 shrink-0 rtl:rotate-180" aria-hidden="true" />
            </a>
        @endforeach
    </nav>
@endif
