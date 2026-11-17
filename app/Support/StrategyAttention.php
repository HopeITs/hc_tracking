<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

/**
 * The "Needs your attention" lines of one strategy, for
 * x-strategies.attention-list: one plain sentence per thing that is not zero,
 * each with one button to the register already narrowed to it. A figure the
 * viewer may not see (null in the rollup) never becomes a line.
 *
 * @phpstan-import-type Rollup from StrategyRollup
 *
 * @phpstan-type AttentionItem array{text: HtmlString, label: string, href: string, tone: 'critical'|'warn'}
 */
final class StrategyAttention
{
    /** The programs-behind line names this many programs, then says how many more. */
    private const int NamedPrograms = 3;

    /**
     * @param  Rollup  $rollup
     * @param  array{planned: int, programs: int, missing: int}|null  $readiness  plans for $year, null when hidden
     * @return list<AttentionItem>
     */
    public static function items(int $strategyId, array $rollup, ?array $readiness, int $year): array
    {
        $narrowed = fn (string $route, array $extra = []): string => route($route, ['strategy' => $strategyId, ...$extra]);
        $sentence = fn (string $key, int $count, array $replace = []): HtmlString => new HtmlString(trans_choice($key, $count, [
            'count' => '<bdi>'.$count.'</bdi>',
            ...array_map(fn (int|string $value): string => '<bdi>'.e((string) $value).'</bdi>', $replace),
        ]));

        $items = [];
        $lateReports = $rollup['late']['reports'] ?? null;

        if ($lateReports !== null && $lateReports > 0) {
            $items[] = ['text' => $sentence('strategies.attention_late_reports', $lateReports), 'label' => __('strategies.see_late_reports'), 'href' => $narrowed('reports.index', ['overdue' => 1]), 'tone' => 'critical'];
        }

        if ($rollup['risks'] !== null && $rollup['risks'] > 0) {
            $items[] = ['text' => $sentence('strategies.attention_high_risks', $rollup['risks']), 'label' => __('strategies.see_high_risks'), 'href' => $narrowed('risks.index', ['high' => 1]), 'tone' => 'critical'];
        }

        if ($readiness !== null && $readiness['missing'] > 0) {
            $items[] = ['text' => $sentence('strategies.attention_no_plan', $readiness['missing'], ['year' => $year]), 'label' => __('strategies.see_programs'), 'href' => route('programs.index', ['strategy' => $strategyId, 'gap' => 'no_plan']), 'tone' => 'warn'];
        }

        return $items;
    }

    /**
     * The strategy page's extra line: the programs falling behind on their
     * targets, named (the first few, then how many more), with a link to the
     * strategy's targets that are not on pace. No programs, no line.
     *
     * @param  list<string>  $programNames
     * @return list<AttentionItem>
     */
    public static function programsBehind(int $strategyId, array $programNames): array
    {
        if ($programNames === []) {
            return [];
        }

        $count = count($programNames);
        $named = array_map(fn (string $name): string => '<bdi>'.e($name).'</bdi>', array_slice($programNames, 0, self::NamedPrograms));
        $others = $count - count($named);

        $names = $others === 0
            ? Arr::join($named, __('strategies.list_separator'), __('strategies.list_last_separator'))
            : implode(__('strategies.list_separator'), $named).' '.trans_choice('strategies.and_count_others', $others, ['count' => '<bdi>'.$others.'</bdi>']);

        return [[
            'text' => new HtmlString(trans_choice('strategies.attention_programs_behind', $count, ['count' => '<bdi>'.$count.'</bdi>', 'names' => $names])),
            'label' => __('strategies.see_targets'),
            'href' => route('indicators.index', ['strategy' => $strategyId, 'focus' => 'offtrack']),
            'tone' => 'critical',
        ]];
    }
}
