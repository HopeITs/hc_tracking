<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The user directory in card form: who has an account and in which roles.
 */
final class UsersCard extends PageCard
{
    public function route(): string
    {
        return 'admin.users.index';
    }

    public function figures(User $user): array
    {
        return [
            $this->figure(__('desk-workspace.card.figure.accounts'), User::query()->count(), 'active'),
            $this->figure(__('desk-workspace.card.figure.roles'), Role::query()->count()),
            $this->figure(__('desk-workspace.card.figure.without_role'), User::query()->doesntHave('roles')->count(), 'warn'),
        ];
    }

    public function breakdown(User $user): array
    {
        $tones = ['active', 'good', 'warn', 'neutral'];

        return $this->parts(Role::query()->withCount('users')->orderByDesc('users_count')->limit(4)->get()
            ->values()
            ->map(fn (Role $role, int $i): array => ['label' => (string) $role->name, 'count' => (int) $role->users_count, 'tone' => $tones[$i] ?? 'neutral'])
            ->all());
    }

    public function hero(User $user): ?array
    {
        $tones = ['active', 'good', 'warn', 'neutral'];
        $faces = User::query()->orderByName()->limit(5)->get()->map(fn (User $person): array => [
            'label' => $this->initials($person->name !== '' ? $person->name : $person->email),
            'tone' => 'tone-'.($person->id % 6),
        ])->all();

        return [
            'kind' => 'faces',
            'big' => $this->number(User::query()->count()),
            'caption' => (string) __('desk-workspace.card.figure.accounts'),
            'tone' => 'active',
            'faces' => $faces,
            'items' => Role::query()->withCount('users')->orderByDesc('users_count')->limit(3)->get()->values()
                ->map(fn (Role $role, int $i): array => ['label' => (string) $role->name, 'value' => $this->number((int) $role->users_count), 'tone' => $tones[$i] ?? 'neutral'])->all(),
        ];
    }

    public function layout(): string
    {
        return 'people';
    }

    protected function records(User $user, string $search): Collection
    {
        return User::query()->with('roles:id,name')->orderByName()->limit(self::RowLimit)->get()->map(function (User $account): array {
            $name = $account->name !== '' ? $account->name : $account->email;

            return $this->shaped(
                $this->row(
                    'us-'.$account->id,
                    $name,
                    $account->roles->pluck('name')->implode(' · ') ?: (string) __('desk-workspace.card.no_role'),
                    null,
                    route($this->route()),
                ),
                lead: ['kind' => 'avatar', 'big' => $this->initials($name), 'tone' => 'tone-'.($account->id % 6)],
            );
        });
    }
}
