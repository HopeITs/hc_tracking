<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\DonorProposal;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Funding agreements in card form (funding-agreements.view only, like the
 * page): how many, the budget per currency, and the newest agreements.
 */
final class AgreementsCard extends PageCard
{
    public function route(): string
    {
        return 'funding-agreements.index';
    }

    public function figures(User $user): array
    {
        $figures = [
            $this->figure(__('desk-workspace.card.figure.agreements'), DonorProposal::query()->count(), 'active'),
            $this->figure(__('desk-workspace.card.figure.donors'), DonorProposal::query()->distinct()->count('donor_id')),
        ];

        foreach (DonorProposal::query()->toBase()->selectRaw('currency, sum(budget) as total')->groupBy('currency')->orderByDesc('total')->limit(2)->get() as $line) {
            $figures[] = $this->figure((string) __('desk-workspace.card.figure.budget', ['currency' => $line->currency]), (float) $line->total, 'good');
        }

        return $figures;
    }

    public function hero(User $user): ?array
    {
        $lines = DonorProposal::query()->toBase()->selectRaw('currency, sum(budget) as total, count(*) as agreements')->groupBy('currency')->orderByDesc('total')->limit(3)->get();
        $most = max(1.0, (float) $lines->max('total'));

        return [
            'kind' => 'money',
            'big' => $this->number(DonorProposal::query()->count()),
            'caption' => (string) __('desk-workspace.card.figure.agreements'),
            'tone' => 'active',
            'items' => $lines->map(fn (object $line): array => [
                'label' => (string) $line->currency,
                'value' => $this->number((float) $line->total),
                'share' => $this->share((float) $line->total, $most),
                'note' => trans_choice('desk-workspace.card.agreements_count', (int) $line->agreements, ['count' => (int) $line->agreements]),
                'tone' => 'good',
            ])->all(),
        ];
    }

    public function layout(): string
    {
        return 'ledger';
    }

    protected function records(User $user, string $search): Collection
    {
        return DonorProposal::query()->with(['donor:id,name', 'proposal:id,name'])->latest('id')->limit(self::RowLimit)->get()
            ->map(fn (DonorProposal $agreement): array => $this->shaped(
                $this->row(
                    'fa-'.$agreement->id,
                    $agreement->proposal?->name ?? '—',
                    (string) ($agreement->donor?->name ?? ''),
                    null,
                    route('funding-agreements.show', $agreement),
                ),
                lead: ['kind' => 'avatar', 'big' => $this->initials((string) ($agreement->donor?->name ?? '—')), 'tone' => 'tone-'.($agreement->donor_id % 6)],
                end: ['big' => $this->number((float) $agreement->budget), 'small' => $agreement->currency->value],
            ));
    }
}
