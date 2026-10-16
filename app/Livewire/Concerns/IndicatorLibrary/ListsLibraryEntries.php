<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\IndicatorLibrary;

use App\Models\HrProgram;
use App\Models\LibraryIndicator;
use App\Support\ArabicText;
use App\Support\Like;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;

/**
 * The Indicator Library's one read: every entry with how many indicators
 * use it and whether any of them carries targets, loaded once, then
 * searched, filtered, grouped by program and paged in memory, so the page
 * costs the same few queries however large the library grows.
 *
 * Used by indicator-library/index only. The page provides its #[Url]
 * filters and WithPagination's getPage().
 *
 * @property string $search #[Url] filter on the page
 * @property string $program #[Url] '' | 'institutional' | an HR program id
 * @property string $level #[Url] '' | G | CC | OC | OP | AC
 * @property string $measure #[Url] filter on the page
 * @property string $collector #[Url] filter on the page
 * @property string $status #[Url] '' | 'active' | 'inactive'
 * @property string $english #[Url] '' | 'missing'
 * @property-read Collection<int, LibraryIndicator> $entries #[Computed] entries()
 * @property-read Collection<int, LibraryIndicator> $filteredEntries #[Computed] filteredEntries()
 * @property-read LengthAwarePaginator<int, LibraryIndicator> $pagedEntries #[Computed] pagedEntries()
 */
trait ListsLibraryEntries
{
    /**
     * How many entries one page lists.
     */
    private const PER_PAGE = 25;

    /**
     * The framework levels in the order a logframe reads, top down.
     *
     * @var list<string>
     */
    private const LEVEL_KINDS = ['G', 'CC', 'OC', 'OP', 'AC'];

    /**
     * Every entry, in reading order: institutional first, then by program
     * name, then down the framework by level and serial.
     *
     * `indicators_count` freezes the program and level; `has_targets` locks
     * the measure. Both are display hints only: the actions re-check them
     * under lock.
     *
     * @return Collection<int, LibraryIndicator>
     */
    #[Computed]
    public function entries(): Collection
    {
        $programOrder = array_flip(HrProgram::idsOrderedByName());

        return LibraryIndicator::query()
            ->withCount('indicators')
            ->withExists(['indicators as has_targets' => fn (Builder $query): Builder => $query->has('planIndicators')])
            ->get()
            ->sort(fn (LibraryIndicator $a, LibraryIndicator $b): int => $this->sortKey($a, $programOrder) <=> $this->sortKey($b, $programOrder))
            ->values();
    }

    /**
     * @return Collection<int, LibraryIndicator>
     */
    #[Computed]
    public function filteredEntries(): Collection
    {
        // Trimmed again: the cap can leave a cut term ending in spaces.
        $term = trim(Like::term($this->search));

        return $this->entries
            ->filter(fn (LibraryIndicator $entry): bool => match ($this->program) {
                '' => true,
                'institutional' => $entry->program_id === null,
                default => $entry->program_id === (int) $this->program,
            })
            ->filter(fn (LibraryIndicator $entry): bool => $this->level === '' || self::levelKindOf($entry->level_ref) === $this->level)
            ->filter(fn (LibraryIndicator $entry): bool => $this->measure === '' || $entry->measure->value === $this->measure)
            ->filter(fn (LibraryIndicator $entry): bool => $this->collector === '' || $entry->collected_by->value === $this->collector)
            ->filter(fn (LibraryIndicator $entry): bool => match ($this->status) {
                'active' => $entry->is_active,
                'inactive' => ! $entry->is_active,
                default => true,
            })
            ->filter(fn (LibraryIndicator $entry): bool => $this->english !== 'missing' || blank($entry->name_en))
            ->filter(fn (LibraryIndicator $entry): bool => $term === ''
                || ArabicText::contains($entry->name_ar, $term)
                || ArabicText::contains((string) $entry->name_en, $term)
                || ArabicText::contains($entry->code, $term))
            ->values();
    }

    /**
     * @return LengthAwarePaginator<int, LibraryIndicator>
     */
    #[Computed]
    public function pagedEntries(): LengthAwarePaginator
    {
        $entries = $this->filteredEntries;
        $page = max(1, (int) $this->getPage());

        return new LengthAwarePaginator(
            $entries->forPage($page, self::PER_PAGE)->values(),
            $entries->count(),
            self::PER_PAGE,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath(), 'pageName' => 'page'],
        );
    }

    /**
     * The current page's entries under their program heading, in order.
     *
     * @return BaseCollection<int, array{key: string, heading: string, entries: BaseCollection<int, LibraryIndicator>}>
     */
    public function pagedGroups(): BaseCollection
    {
        $names = HrProgram::namesById();

        return collect($this->pagedEntries->items())
            ->groupBy(fn (LibraryIndicator $entry): string => $entry->program_id === null ? 'institutional' : (string) $entry->program_id)
            ->map(fn (BaseCollection $entries, string $key): array => [
                'key' => $key,
                'heading' => $key === 'institutional' ? __('indicator_library.institutional') : ($names[(int) $key] ?? __('indicator_library.program_not_in_hr')),
                'entries' => $entries->values(),
            ])
            ->values();
    }

    /**
     * Programs that hold at least one entry, by name, for the program filter.
     *
     * @return array<int, string>
     */
    public function programFilterOptions(): array
    {
        $used = $this->entries->pluck('program_id')->filter()->unique()->flip();
        $names = HrProgram::namesById();

        return collect(HrProgram::idsOrderedByName())
            ->filter(fn (int $id): bool => $used->has($id))
            ->mapWithKeys(fn (int $id): array => [$id => $names[$id]])
            ->all();
    }

    /**
     * The framework level a level reference sits at: OP1.2 → OP.
     */
    public static function levelKindOf(string $levelRef): string
    {
        return LibraryIndicator::levelKindOf($levelRef);
    }

    /**
     * Drop every cached read after a write, so the same request re-renders
     * the library it just changed.
     */
    protected function forgetLibrary(): void
    {
        foreach (['entries', 'filteredEntries', 'pagedEntries'] as $property) {
            unset($this->{$property});
        }
    }

    /**
     * @param  array<int, int>  $programOrder  HR program id => position by name
     * @return array{int, int, int, string, int}
     */
    private function sortKey(LibraryIndicator $entry, array $programOrder): array
    {
        $kind = self::levelKindOf($entry->level_ref);
        $kindPosition = array_search($kind, self::LEVEL_KINDS, true);

        return [
            $entry->program_id === null ? 0 : 1,
            $entry->program_id === null ? 0 : ($programOrder[$entry->program_id] ?? PHP_INT_MAX),
            $kindPosition === false ? count(self::LEVEL_KINDS) : $kindPosition,
            // Zero-padded numbers so OP2 sorts before OP10 and OP1.2 before OP1.10.
            (string) preg_replace_callback('/\d+/', fn (array $digits): string => str_pad($digits[0], 6, '0', STR_PAD_LEFT), $entry->level_ref),
            $entry->serial,
        ];
    }
}
