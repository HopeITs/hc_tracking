{{--
    A change request's "from → to" list (Y-09), as App\Support\ProposalChangeLines reads it. The requester's
    summary step, the review and decision modal and the proposal page's chapter all render it, so the three
    name the same differences. Values are user text or ids resolved to names: each sits in its own dir="auto"
    run, and the arrow follows the reading direction.
--}}
@props(['lines' => []])

<dl {{ $attributes->class('grid gap-2 text-sm') }} data-change-list>
    @foreach ($lines as $line)
        <div class="grid gap-0.5 rounded-lg border border-secondary-soft px-3 py-2 dark:border-line" data-change-line="{{ $line['field'] }}">
            <dt class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                {{ $line['label'] }}
                @if ($line['place'] !== null)
                    <span class="font-normal" dir="auto">· {{ $line['place'] }}</span>
                @endif
            </dt>
            <dd class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-secondary-dark dark:text-ink">
                <span class="text-secondary-medium line-through decoration-secondary-light dark:text-ink-muted" dir="auto" data-change-from>{{ $line['from'] }}</span>
                <span class="text-secondary-medium dark:text-ink-muted" aria-hidden="true">
                    <flux:icon.arrow-long-right variant="micro" class="size-4 rtl:-scale-x-100" />
                </span>
                <span class="sr-only">{{ __('proposals.change.becomes') }}</span>
                <span class="font-semibold" dir="auto" data-change-to>{{ $line['to'] }}</span>
            </dd>
        </div>
    @endforeach
</dl>
