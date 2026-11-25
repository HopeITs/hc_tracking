<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\RecordsParentActivity;
use App\Enums\ProgramRequestStatus;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\ProgramRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for a program HR does not have yet (contracts.md §7, W-34a),
 * opened by a proposal location row marked new without a program. Until
 * W-34b flips the program source, this is the only bridge to HR: the admin
 * links the HR program once it exists (`created`, `hr_program_id` set) or
 * declines it. The names are what the requester typed, not HR data.
 *
 * Opening a request is recorded on the proposal (`program_request.opened`).
 *
 * @property int $id
 * @property int $proposal_location_id
 * @property string $name_en
 * @property string $name_ar
 * @property ProgramRequestStatus $status
 * @property int|null $hr_program_id
 * @property int|null $requested_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['proposal_location_id', 'name_en', 'name_ar', 'status', 'hr_program_id', 'requested_by'])]
class ProgramRequest extends Model
{
    /** @use HasFactory<ProgramRequestFactory> */
    use HasFactory, RecordsParentActivity;

    /**
     * Mirror the column default so an unrefreshed model reports its status.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProgramRequestStatus::class,
            'hr_program_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProposalLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ProposalLocation::class, 'proposal_location_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isPending(): bool
    {
        return $this->status === ProgramRequestStatus::Pending;
    }

    /**
     * The requested name in the reader's language.
     */
    public function localizedName(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    /**
     * The requested name in the other language, shown under the first.
     */
    public function otherLanguageName(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_en : $this->name_ar;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), ProgramRequestStatus::Pending->value);
    }

    public function activityParent(): (Model&TracksActivity)|null
    {
        return $this->location?->proposal;
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'program_request.opened' : null;
    }

    /**
     * The requested name is free text the requester typed, never HR data.
     *
     * @return array<string, mixed>
     */
    protected function parentActivityProperties(string $hook): array
    {
        return [
            'program_request_id' => $this->id,
            'proposal_location_id' => $this->proposal_location_id,
            'name' => $this->name_en,
        ];
    }
}
