<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Locale;
use App\Enums\ProposalStatus;
use App\Enums\StageTemplateCreates;
use App\Enums\TaskKind;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalStageTaskTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The department work one proposal stage needs (D-63, contracts.md §7).
 *
 * The PR Manager writes these once; CreateStageTasks makes a task — or the
 * final-report milestone — from every active template of the status a
 * proposal has just entered. The department is an HR id, never a copied
 * name, so a department renamed in HR needs no change here.
 *
 * @property int $id
 * @property ProposalStatus $status
 * @property int $department_id
 * @property TaskKind $kind
 * @property StageTemplateCreates $creates
 * @property string $title_en
 * @property string $title_ar
 * @property string|null $description_en
 * @property string|null $description_ar
 * @property-read string $title
 * @property-read string|null $description
 * @property int $due_offset_days
 * @property bool $is_active
 * @property bool $repeat_on_reentry
 * @property int $sort_order
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'status',
    'department_id',
    'kind',
    'creates',
    'title_en',
    'title_ar',
    'description_en',
    'description_ar',
    'due_offset_days',
    'is_active',
    'repeat_on_reentry',
    'sort_order',
])]
class ProposalStageTaskTemplate extends Model
{
    /** @use HasFactory<ProposalStageTaskTemplateFactory> */
    use HasFactory;

    /**
     * The index that keeps one template per status, department, kind and title.
     */
    public const UNIQUE_INDEX = 'stage_task_templates_unique';

    /**
     * Mirror the column defaults so an unsaved template still reports them.
     *
     * @var array<string, bool|int|string>
     */
    protected $attributes = [
        'creates' => StageTemplateCreates::Task->value,
        'due_offset_days' => 0,
        'is_active' => true,
        'repeat_on_reentry' => false,
        'sort_order' => 0,
    ];

    /**
     * The templates that fire when a proposal enters a status, in the order
     * the PR Manager put them.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function forStatus(Builder $query, ProposalStatus $status): void
    {
        $query->where('status', $status->value)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * The title in the interface language, falling back to English.
     *
     * @return Attribute<string, never>
     */
    protected function title(): Attribute
    {
        return Attribute::get(fn (): string => Locale::current() === Locale::Arabic && trim($this->title_ar) !== ''
            ? trim($this->title_ar)
            : trim($this->title_en));
    }

    /**
     * The description in the interface language, falling back to English.
     *
     * @return Attribute<non-empty-string|null, never>
     */
    protected function description(): Attribute
    {
        return Attribute::get(function (): ?string {
            $arabic = trim((string) $this->description_ar);
            $english = trim((string) $this->description_en);
            $chosen = Locale::current() === Locale::Arabic && $arabic !== '' ? $arabic : $english;

            return $chosen === '' ? null : $chosen;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'kind' => TaskKind::class,
            'creates' => StageTemplateCreates::class,
            'due_offset_days' => 'integer',
            'is_active' => 'boolean',
            'repeat_on_reentry' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
