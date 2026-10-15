<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A sector a project covers (contracts.md §12.5, D-47), managed by MEAL and
 * the General Manager (`sectors.manage`). A sector some project uses is
 * deactivated, never deleted: it stays on those projects with an "Inactive"
 * badge and is no longer offered for new selection.
 *
 * `is_active` and `sort_order` are never mass assigned: they move only
 * through DeactivateSector / ReactivateSector and MoveSector.
 *
 * @property int $id
 * @property string $name_ar
 * @property string|null $name_en
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name_ar', 'name_en'])]
class Sector extends Model
{
    /** @use HasFactory<SectorFactory> */
    use HasFactory;

    /**
     * Mirror the column defaults so a sector that has not been round-tripped
     * through the database still reports them under strict mode (the factory
     * leaves these columns to the database).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The projects that cover this sector.
     *
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    /**
     * Sectors still offered for new selection.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The order the managers set, with the id breaking ties. Qualified,
     * because the `project_sector` pivot has an `id` of its own.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy($query->qualifyColumn('sort_order'))->orderBy($query->qualifyColumn('id'));
    }

    /**
     * What a sector picker offers: every active sector, plus the inactive
     * ones already chosen, so editing a project never drops them silently.
     *
     * @param  list<int>  $chosenIds
     * @return Collection<int, self>
     */
    public static function pickerOptions(array $chosenIds): Collection
    {
        return self::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->when($chosenIds !== [], fn (Builder $query) => $query->orWhereIn('id', $chosenIds)))
            ->ordered()
            ->get(['id', 'name_ar', 'name_en', 'is_active']);
    }

    /**
     * The name in the current language; a sector with no English name shows
     * its Arabic name everywhere.
     */
    public function localizedName(): string
    {
        if (app()->getLocale() === 'ar' || blank($this->name_en)) {
            return $this->name_ar;
        }

        return $this->name_en;
    }
}
