<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\HasSlug;
use App\Concerns\RecordsActivity;
use App\Enums\DonorType;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Database\Factories\DonorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property DonorType|null $type
 * @property string|null $contact_person
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $country
 * @property string|null $notes
 * @property bool $is_internal
 * @property CarbonImmutable|null $last_contacted_on
 * @property string|null $next_step
 * @property CarbonImmutable|null $next_step_due_on
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'is_internal', 'type', 'contact_person', 'email', 'phone', 'country', 'notes', 'last_contacted_on', 'next_step', 'next_step_due_on'])]
class Donor extends Model implements TracksActivity
{
    /** @use HasFactory<DonorFactory> */
    use HasFactory, HasSlug, RecordsActivity;

    protected function slugSource(): string
    {
        return $this->name;
    }

    /**
     * Whether this is the internal donor that stands for self-funding.
     */
    public function isInternal(): bool
    {
        return $this->is_internal;
    }

    /**
     * The funding agreements this donor has signed.
     *
     * @return HasMany<DonorProposal, $this>
     */
    public function donorProposals(): HasMany
    {
        return $this->hasMany(DonorProposal::class);
    }

    /**
     * The proposals written for this donor (proposals.donor_id), from the
     * concept note on, whether or not an agreement was signed yet.
     *
     * @return HasMany<Proposal, $this>
     */
    public function proposalsWrittenFor(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /**
     * The proposals this donor funds.
     *
     * `donor_proposal` carries budget and currency, so prefer donorProposals()
     * when those columns matter.
     *
     * @return BelongsToMany<Proposal, $this>
     */
    public function proposals(): BelongsToMany
    {
        return $this->belongsToMany(Proposal::class, 'donor_proposal')
            ->withPivot(['budget', 'currency', 'external_project_number'])
            ->withTimestamps();
    }

    /**
     * Whether the planned next step has passed its due date undone.
     */
    public function isFollowUpOverdue(): bool
    {
        return $this->next_step_due_on !== null && $this->next_step_due_on->isBefore(CarbonImmutable::today());
    }

    /**
     * Get the attributes that should be cast.
     *
     * `type` is nullable: donors recorded before the column existed, and
     * donors known only from a grant letter, carry no classification.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'type' => DonorType::class,
            'last_contacted_on' => 'date',
            'next_step_due_on' => 'date',
        ];
    }

    public function activityModule(): string
    {
        return 'donors';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function activityEvents(): array
    {
        return ['name' => 'updated', 'contact_person' => 'updated', 'next_step' => 'updated', 'next_step_due_on' => 'updated'];
    }
}
