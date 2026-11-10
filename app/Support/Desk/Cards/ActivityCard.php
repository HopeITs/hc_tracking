<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\Activity;
use App\Models\User;
use App\Notifications\ActivityPresenter;
use App\Notifications\NotificationPresenter;
use App\Notifications\SubjectLinks;
use App\Support\Desk\RelativeDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The activity log in card form: what changed lately, newest first, each line
 * the same sentence the log writes and opening the record it is about.
 */
final class ActivityCard extends PageCard
{
    public function route(): string
    {
        return 'activity.index';
    }

    public function figures(User $user): array
    {
        $today = CarbonImmutable::today();

        return [
            $this->figure(__('desk-workspace.card.figure.today'), Activity::query()->where('created_at', '>=', $today)->count(), 'active'),
            $this->figure(__('desk-workspace.card.figure.this_week'), Activity::query()->where('created_at', '>=', $today->subDays(6))->count()),
            $this->figure(__('desk-workspace.card.figure.people'), Activity::query()->where('created_at', '>=', $today->subDays(6))->whereNotNull('actor_id')->distinct()->count('actor_id')),
        ];
    }

    public function hero(User $user): ?array
    {
        $today = CarbonImmutable::today();
        $counts = Activity::query()->where('created_at', '>=', $today->subDays(13))->toBase()
            ->selectRaw('date(created_at) as day, count(*) as total')->groupBy('day')->pluck('total', 'day');
        $most = max(1, (int) $counts->max());

        return [
            'kind' => 'pulse',
            'big' => $this->number((int) ($counts[$today->toDateString()] ?? 0)),
            'caption' => (string) __('desk-workspace.card.figure.today'),
            'tone' => 'active',
            'items' => collect(range(13, 0))->map(function (int $back) use ($today, $counts, $most): array {
                $day = $today->subDays($back);
                $count = (int) ($counts[$day->toDateString()] ?? 0);

                return ['label' => $day->translatedFormat('D j M'), 'value' => (string) $count, 'share' => $this->share($count, $most), 'tone' => 'active', 'on' => $back === 0];
            })->all(),
        ];
    }

    public function layout(): string
    {
        return 'feed';
    }

    protected function records(User $user, string $search): Collection
    {
        $sentences = app(ActivityPresenter::class);
        $modules = app(NotificationPresenter::class);
        $links = app(SubjectLinks::class);

        return Activity::query()->with('actor:id,hr_user_id,employee_id')->latest('created_at')->latest('id')->limit(self::RowLimit)->get()
            ->map(fn (Activity $activity): array => $this->shaped(
                $this->row(
                    'ac-'.$activity->id,
                    $sentences->sentence($activity),
                    collect([$activity->actor?->name, $modules->moduleLabel($activity->module)])->filter()->implode(' · '),
                    null,
                    $links->routeFor($activity->subject_type, (int) $activity->subject_id) ?? route($this->route()),
                ),
                lead: ['kind' => 'dot'],
                end: $activity->created_at !== null ? ['big' => $activity->created_at->format('H:i'), 'small' => RelativeDay::for($activity->created_at)] : null,
            ));
    }
}
