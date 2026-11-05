<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RiskLibraryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An entry of the MEAL-managed risk library (contracts.md §7, W-09, D-61).
 * Proposals pick their risks from here and nowhere else. An entry a risk was
 * picked from is deactivated, never deleted: it stays on those proposals
 * and is no longer offered.
 *
 * `is_active` and `sort_order` are never mass assigned: they move only
 * through the Deactivate / Reactivate / Move actions.
 *
 * @property int $id
 * @property string $title_en
 * @property string $title_ar
 * @property string|null $category
 * @property int|null $default_likelihood
 * @property int|null $default_severity
 * @property bool $is_active
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['title_en', 'title_ar', 'category', 'default_likelihood', 'default_severity'])]
class RiskLibrary extends Model
{
    /** @use HasFactory<RiskLibraryFactory> */
    use HasFactory;

    protected $table = 'risk_library';

    /**
     * Mirror the column defaults so an entry that has not been round-tripped
     * through the database still reports them under strict mode.
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
            'default_likelihood' => 'integer',
            'default_severity' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * The risks picked from this entry.
     *
     * @return HasMany<Risk, $this>
     */
    public function risks(): HasMany
    {
        return $this->hasMany(Risk::class, 'library_risk_id');
    }

    /**
     * Entries still offered for new selection.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The order the managers set, with the id breaking ties.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy($query->qualifyColumn('sort_order'))->orderBy($query->qualifyColumn('id'));
    }

    /**
     * The title in the current language.
     */
    public function localizedTitle(): string
    {
        return app()->getLocale() === 'ar' ? $this->title_ar : $this->title_en;
    }
}
