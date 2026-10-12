@use('App\Enums\ReportStatus')
@use('Illuminate\Support\Number')

    {{-- Greeting and the signed-in person's own work --}}
    <header class="flex flex-col gap-4 border-b border-secondary-soft pb-7 @4xl/dashboard:flex-row @4xl/dashboard:items-center @4xl/dashboard:justify-between dark:border-line">
        <div class="min-w-0">
            <h1 id="dashboard-heading" class="text-3xl font-bold leading-none text-balance text-secondary-dark dark:text-ink">
                {{ $this->greeting() }}
            </h1>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-secondary-medium dark:text-ink-muted">
                <span>{{ now()->translatedFormat(__('formats.date_long')) }}</span>
                <span aria-hidden="true" class="text-secondary-light">·</span>
                @if ($counts['all'] > 0)
                    <span class="rounded-full bg-primary dark:border-transparent dark:bg-status-critical-soft dark:text-status-critical px-2 py-0.5 text-xs font-bold text-white">{{ trans_choice('dashboard.items_need_attention', $counts['all'], ['count' => $counts['all']]) }}</span>
                @else
                    <span class="rounded-full bg-status-good-soft px-2 py-0.5 text-xs font-bold text-status-good">{{ __('dashboard.all_clear') }}</span>
                @endif
            </p>
        </div>

        <nav class="flex flex-wrap items-stretch gap-2" aria-label="{{ __('dashboard.my_work') }}" data-my-work>
            <span class="type-label hidden self-center pe-1 text-secondary-medium @xl/dashboard:inline dark:text-ink-muted">{{ __('dashboard.my_work') }}</span>
            @php
                $myTasksHref = $myWork['employeeId'] ? route('tasks.index', ['assignee' => $myWork['employeeId']]) : null;
                $myWorkLinks = [
                    ['label' => __('dashboard.my_overdue_tasks'), 'value' => $myWork['overdue'], 'tone' => $myWork['overdue'] > 0 ? 'critical' : 'neutral', 'href' => $myTasksHref, 'icon' => 'clock'],
                    ['label' => __('dashboard.due_next_7_days'), 'value' => $myWork['dueThisWeek'], 'tone' => 'neutral', 'href' => $myTasksHref, 'icon' => 'calendar-days'],
                    ['label' => __('dashboard.my_draft_reports'), 'value' => $myWork['draftReports'], 'tone' => $myWork['draftReports'] > 0 ? 'warn' : 'neutral', 'href' => route('reports.index', ['status' => ReportStatus::Draft->value, 'author' => auth()->id()]), 'icon' => 'document-text'],
                ];
            @endphp
            @foreach ($myWorkLinks as $link)
                @php $tag = $link['href'] ? 'a' : 'div'; @endphp
                <{{ $tag }}
                    @if ($link['href']) href="{{ $link['href'] }}" wire:navigate @endif
                    @class([
                        $cardClasses,
                        'group flex min-h-11 items-center gap-2.5 py-1.5 ps-1.5 pe-3.5 transition-[border-color,box-shadow,transform] duration-200',
                        'hover:-translate-y-px hover:border-secondary-light focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary' => $link['href'],
                    ])
                    wire:key="my-work-{{ $loop->index }}"
                    data-my-work-link
                >
                    <span class="{{ $iconTone($link['tone']) }} flex size-8 items-center justify-center rounded-lg">
                        <flux:icon :name="$link['icon']" variant="micro" class="size-4" />
                    </span>
                    <span class="text-lg font-bold tabular-nums {{ $figureTone($link['tone']) }}">{{ Number::format($link['value']) }}</span>
                    <span class="type-meta max-w-28 leading-tight text-secondary-medium dark:text-ink-muted">{{ $link['label'] }}</span>
                </{{ $tag }}>
            @endforeach
        </nav>
    </header>
