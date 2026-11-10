<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Enums\StatusTone;
use App\Models\User;
use App\Support\ArabicText;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * A page in card form: what a register page is about, said in the space of a
 * Desk card. Every card has the same three parts, so it reads the same at any
 * size (components/desk/page-card): a few headline figures, an optional split
 * of the whole by state, and the page's records as rows that open in a Desk
 * tab. The card component decides how much of each fits the card it is in.
 *
 * A card is only offered to someone who may open its page (Destinations), and
 * reads its rows with the same rules as the page, so it never shows a row the
 * page would hide.
 */
abstract class PageCard
{
    /**
     * How many rows a card reads at most: a card is a glance, its page holds the rest.
     */
    public const int RowLimit = 60;

    /**
     * The register this card stands for (a Destinations route).
     */
    abstract public function route(): string;

    /**
     * Headline figures, the most telling first. The card shows as many as fit.
     *
     * @return list<array{label: string, value: string, tone: string}>
     */
    abstract public function figures(User $user): array;

    /**
     * The page's records, most worth a look first, at most RowLimit. $search
     * is what was typed in the card's search; a card may narrow in SQL, and
     * rows() narrows the rest by title, meta and words.
     *
     * @return Collection<int, array{key: string, title: string, meta: string, badge: array{label: string, tone: string}|null, href: string, words: string, lead: array{kind: string, big: string, small: string, tone: string}|null, end: array{big: string, small: string}|null, bar: array{from: float, to: float, now: float|null, tone: string}|null}>
     */
    abstract protected function records(User $user, string $search): Collection;

    /**
     * The whole split by state (each part's label, count and tone), drawn as
     * one bar; empty when the page has no states worth splitting by.
     *
     * @return list<array{label: string, count: int, tone: string}>
     */
    public function breakdown(User $user): array
    {
        return [];
    }

    /**
     * The rows the card lists: the page's records, narrowed by the search in
     * either language and on either keyboard layout.
     *
     * @return Collection<int, array{key: string, title: string, meta: string, badge: array{label: string, tone: string}|null, href: string, words: string, lead: array{kind: string, big: string, small: string, tone: string}|null, end: array{big: string, small: string}|null, bar: array{from: float, to: float, now: float|null, tone: string}|null}>
     */
    public function rows(User $user, string $search = ''): Collection
    {
        $search = trim($search);
        $rows = $this->records($user, $search)->take(self::RowLimit)->values();

        return $search === ''
            ? $rows
            : $rows->filter(fn (array $row): bool => ArabicText::containsTyped($row['title'].' '.$row['meta'].' '.$row['words'], $search))->values();
    }

    /**
     * @return array{label: string, value: string, tone: string}
     */
    protected function figure(string $label, int|float|string $value, string $tone = 'neutral'): array
    {
        return ['label' => $label, 'value' => is_string($value) ? $value : $this->number($value), 'tone' => $tone];
    }

    /**
     * $words is searched but not shown: a record's name in the other language,
     * so the search finds it in either language whichever one the page is in.
     *
     * @param  array{label: string, tone: string}|null  $badge
     * @return array{key: string, title: string, meta: string, badge: array{label: string, tone: string}|null, href: string, words: string, lead: array{kind: string, big: string, small: string, tone: string}|null, end: array{big: string, small: string}|null, bar: array{from: float, to: float, now: float|null, tone: string}|null}
     */
    protected function row(string $key, string $title, string $meta, ?array $badge, string $href, string $words = ''): array
    {
        return ['key' => $key, 'title' => $title, 'meta' => $meta, 'badge' => $badge, 'href' => $href, 'words' => $words, 'lead' => null, 'end' => null, 'bar' => null];
    }

    /**
     * How the card draws its records, chosen by what they are (components/desk/panels/⚡page,
     * desk-cards-shapes.css): "list" (name, detail, state), "agenda" (a date block first),
     * "timeline" (a span over the years with today marked), "meter" (a bar of how much),
     * "rank" (place and count), "ledger" (an amount at the end), "people" (initials),
     * "feed" (a line of events), "coded" (a code block first), "tagged" (a coloured edge by
     * kind), "numbered" (questions in order) or "bank" (a currency coin and the last digits).
     */
    public function layout(): string
    {
        return 'list';
    }

    /**
     * The card's own picture of its page, drawn where the figures would be
     * (components/desk/card-hero): each page has a different one, so no two
     * cards look alike. Kinds: ruler, ring, years, pyramid, tally, gauge,
     * flow, weeks, money, coins, faces, pulse, podium, bubbles, waffle,
     * dots, tags. Null keeps the plain figures.
     *
     * @return array{kind: string, big: string, caption: string, tone: string, share?: float, now?: float, ticks?: list<array{label: string, at: float}>, faces?: list<array{label: string, tone: string}>, items: list<array<string, mixed>>}|null
     */
    public function hero(User $user): ?array
    {
        return null;
    }

    /**
     * A row with its shape: what leads it (a date, initials, a code…), the figure at its end,
     * and a bar (from/to/now are percentages of the card's own range).
     *
     * @param  array{key: string, title: string, meta: string, badge: array{label: string, tone: string}|null, href: string, words: string, lead: mixed, end: mixed, bar: mixed}  $row
     * @param  array{kind: string, big?: string, small?: string, tone?: string}|null  $lead
     * @param  array{big: string, small?: string}|null  $end
     * @param  array{from?: float, to: float, now?: float|null, tone?: string}|null  $bar
     * @return array{key: string, title: string, meta: string, badge: array{label: string, tone: string}|null, href: string, words: string, lead: array{kind: string, big: string, small: string, tone: string}|null, end: array{big: string, small: string}|null, bar: array{from: float, to: float, now: float|null, tone: string}|null}
     */
    protected function shaped(array $row, ?array $lead = null, ?array $end = null, ?array $bar = null): array
    {
        return [
            ...$row,
            'lead' => $lead === null ? null : ['kind' => $lead['kind'], 'big' => (string) ($lead['big'] ?? ''), 'small' => (string) ($lead['small'] ?? ''), 'tone' => (string) ($lead['tone'] ?? 'neutral')],
            'end' => $end === null ? null : ['big' => $end['big'], 'small' => (string) ($end['small'] ?? '')],
            'bar' => $bar === null ? null : [
                'from' => max(0.0, min(100.0, (float) ($bar['from'] ?? 0))),
                'to' => max(0.0, min(100.0, (float) $bar['to'])),
                'now' => isset($bar['now']) ? max(0.0, min(100.0, (float) $bar['now'])) : null,
                'tone' => (string) ($bar['tone'] ?? 'neutral'),
            ],
        ];
    }

    /**
     * A share of the largest, as a bar's length: never zero for a record that has some.
     */
    protected function share(int|float $value, int|float $largest): float
    {
        if ($largest <= 0 || $value <= 0) {
            return 0.0;
        }

        return max(4.0, round($value / $largest * 100, 1));
    }

    /**
     * Two letters for a person or a thing: first and last word's initials.
     */
    protected function initials(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_substr((string) ($words[0] ?? ''), 0, 1);
        $last = count($words) > 1 ? mb_substr((string) end($words), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * A status enum as a badge: its label and the shared tone (W-28).
     *
     * @return array{label: string, tone: string}
     */
    protected function badgeOf(BackedEnum $status): array
    {
        $tone = method_exists($status, 'tone') ? $status->tone() : StatusTone::Neutral;

        return [
            'label' => method_exists($status, 'label') ? (string) $status->label() : (string) $status->value,
            'tone' => $tone instanceof StatusTone ? $tone->value : 'neutral',
        ];
    }

    /**
     * @return array{label: string, tone: string}
     */
    protected function activeBadge(bool $active): array
    {
        return $active
            ? ['label' => (string) __('desk-workspace.card.active'), 'tone' => StatusTone::Good->value]
            : ['label' => (string) __('desk-workspace.card.inactive'), 'tone' => StatusTone::Neutral->value];
    }

    /**
     * A whole number in the reader's language; plain digits where PHP's intl
     * extension is missing, so a figure always shows.
     */
    protected function number(int|float $value): string
    {
        return extension_loaded('intl') ? (string) Number::format($value, maxPrecision: 0) : number_format($value);
    }

    /**
     * The breakdown with its empty parts left out.
     *
     * @param  list<array{label: string, count: int, tone: string}>  $parts
     * @return list<array{label: string, count: int, tone: string}>
     */
    protected function parts(array $parts): array
    {
        return array_values(array_filter($parts, fn (array $part): bool => $part['count'] > 0));
    }
}
