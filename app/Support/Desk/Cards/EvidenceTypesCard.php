<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\EvidenceType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Evidence types in card form: how many are offered, how many files use
 * them, and the list in its managed order with each type's use.
 */
final class EvidenceTypesCard extends PageCard
{
    public function route(): string
    {
        return 'admin.evidence-types.index';
    }

    public function figures(User $user): array
    {
        $active = EvidenceType::query()->active()->count();

        return [
            $this->figure(__('desk-workspace.card.figure.offered'), $active, 'good'),
            $this->figure(__('desk-workspace.card.figure.in_use'), DB::table('monthly_report_files')->distinct()->count('evidence_type_id')),
            $this->figure(__('desk-workspace.card.figure.inactive'), EvidenceType::query()->count() - $active),
        ];
    }

    public function hero(User $user): ?array
    {
        $used = DB::table('monthly_report_files')->distinct()->pluck('evidence_type_id')->flip();
        $types = EvidenceType::query()->active()->ordered()->get(['id', 'name_en', 'name_ar']);

        return [
            'kind' => 'waffle',
            'big' => $this->number($types->filter(fn (EvidenceType $type): bool => $used->has($type->id))->count()).'/'.$this->number($types->count()),
            'caption' => (string) __('desk-workspace.card.figure.in_use'),
            'tone' => 'active',
            'items' => $types->map(fn (EvidenceType $type): array => ['label' => $type->localizedName(), 'tone' => 'active', 'on' => $used->has($type->id)])->all(),
        ];
    }

    public function layout(): string
    {
        return 'meter';
    }

    protected function records(User $user, string $search): Collection
    {
        $uses = DB::table('monthly_report_files')->selectRaw('evidence_type_id, count(*) as uses')->groupBy('evidence_type_id')->pluck('uses', 'evidence_type_id');
        $most = (int) ($uses->max() ?? 0);

        return EvidenceType::query()->ordered()->get()->map(fn (EvidenceType $type): array => $this->shaped(
            $this->row(
                'et-'.$type->id,
                $type->localizedName(),
                trans_choice('desk-workspace.card.files', (int) ($uses[$type->id] ?? 0), ['count' => (int) ($uses[$type->id] ?? 0)]),
                $type->is_active ? null : $this->activeBadge(false),
                route($this->route()),
                $type->name_en.' '.$type->name_ar,
            ),
            end: ['big' => $this->number((int) ($uses[$type->id] ?? 0))],
            bar: ['to' => $this->share((int) ($uses[$type->id] ?? 0), $most), 'tone' => $type->is_active ? 'active' : 'neutral'],
        ));
    }
}
