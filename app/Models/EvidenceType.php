<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EvidenceTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A kind of evidence a monthly report line can require, such as attendance
 * sheets or activity photos (contracts.md §12.4, D-42), managed by MEAL only
 * (`evidence-types.manage`). A type some line or report file uses is
 * deactivated, never deleted: it stays where it is used with an "Inactive"
 * badge and is no longer offered for new selection.
 *
 * `is_active` and `sort_order` are never mass assigned: they move only
 * through DeactivateEvidenceType / ReactivateEvidenceType and
 * MoveEvidenceType.
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
class EvidenceType extends Model
{
    /** @use HasFactory<EvidenceTypeFactory> */
    use HasFactory;

    /**
     * Mirror the column defaults so a type that has not been round-tripped
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
     * Types still offered for new selection.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The order MEAL set, with the id breaking ties. Qualified, so it stays
     * unambiguous once a pivot with an `id` of its own is joined.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy($query->qualifyColumn('sort_order'))->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Whether anything refers to this type, so it can only be deactivated:
     * a report template line requiring it. T17 adds
     * `monthly_report_files.evidence_type_id` here; until then its
     * restricting foreign key is the backstop DeleteEvidenceType catches.
     */
    public function isInUse(): bool
    {
        return DB::table('report_template_line_evidence_types')->where('evidence_type_id', $this->getKey())->exists();
    }

    /**
     * The name in the current language; a type with no English name shows
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
