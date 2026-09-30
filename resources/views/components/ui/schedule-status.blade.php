@props(['planned' => null, 'actual' => null, 'done' => false])

@php
    use Illuminate\Support\Carbon;

    $today = Carbon::today();

    if ($planned === null) {
        $text = __('common.unscheduled');
        $tone = 'neutral';
    } elseif ($done) {
        if ($actual === null) {
            $text = __('common.completed');
            $tone = 'good';
        } elseif ($actual->lte($planned)) {
            $text = __('ui.schedule_on_time');
            $tone = 'good';
        } else {
            $days = abs($planned->diffInDays($actual));
            $text = trans_choice('ui.schedule_days_late', $days, ['count' => $days]);
            $tone = 'warn';
        }
    } elseif ($planned->lt($today)) {
        $days = abs($planned->diffInDays($today));
        $text = trans_choice('ui.schedule_overdue_by', $days, ['count' => $days]);
        $tone = 'critical';
    } elseif (abs($planned->diffInDays($today)) <= 7) {
        $days = abs($planned->diffInDays($today));
        $text = trans_choice('ui.schedule_due_in', $days, ['count' => $days]);
        $tone = 'warn';
    } else {
        $text = __('ui.schedule_due_date', ['date' => $planned->translatedFormat(__('formats.date'))]);
        $tone = 'neutral';
    }

    $textClasses = match ($tone) {
        'good' => 'text-status-good',
        'warn' => 'text-status-warn',
        'critical' => 'text-primary dark:text-brand-text',
        default => 'text-secondary-medium dark:text-ink-muted',
    };

    $dotClasses = match ($tone) {
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-primary dark:bg-status-critical',
        default => 'bg-secondary-light dark:bg-ink-faint',
    };
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1.5 type-meta font-semibold '.$textClasses) }}>
    <span class="size-1.5 shrink-0 rounded-full {{ $dotClasses }}" aria-hidden="true"></span>
    {{ $text }}
</span>
