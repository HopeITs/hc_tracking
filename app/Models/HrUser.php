<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\RemembersHrLookup;
use App\Support\Like;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A login account from the HR database (hchr_users).
 *
 * @property int $id
 * @property int|null $employee_id
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string $password
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Hidden(['password', 'remember_token'])]
class HrUser extends HrModel
{
    use RemembersHrLookup;

    protected $table = 'users';

    /**
     * Every HR account's email and English name by account id, kept in memory
     * (see RemembersHrLookup).
     *
     * Tracking keeps no copy of either; User::email and User::name read them
     * from here, so a page listing many people costs two HR queries in total.
     *
     * @return array<int, array{email: string, name: string}>
     */
    public static function directory(): array
    {
        return self::rememberedHrLookup(fn (): array => self::loadDirectory());
    }

    /**
     * One HR account's email and English name, null when HR no longer has it.
     *
     * @return array{email: string, name: string}|null
     */
    public static function directoryEntry(int $id): ?array
    {
        return self::rememberedHrLookupEntry($id, fn (): array => self::loadDirectory());
    }

    /**
     * The ids of the HR accounts whose email or employee name (English or
     * Arabic) contains the search.
     *
     * @return list<int>
     */
    public static function idsMatching(string $search): array
    {
        $employeeIds = HrEmployee::idsMatchingName($search);

        return array_values(static::query()
            ->where(fn (Builder $query) => $query
                ->where('email', 'like', Like::contains($search))
                ->orWhereIn('employee_id', $employeeIds))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * Every HR account id, ordered by the English name tracking shows (then id).
     *
     * @return list<int>
     */
    public static function idsOrderedByName(): array
    {
        $directory = self::directory();

        uksort($directory, fn (int $a, int $b): int => [mb_strtolower($directory[$a]['name']), $a] <=> [mb_strtolower($directory[$b]['name']), $b]);

        return array_keys($directory);
    }

    /**
     * @return array<int, array{email: string, name: string}>
     */
    private static function loadDirectory(): array
    {
        $accounts = static::query()
            ->select(['id', 'employee_id', 'email'])
            ->get();

        $employees = HrEmployee::query()
            ->select(HrEmployee::NameColumns)
            ->whereIn('id', $accounts->pluck('employee_id')->filter()->unique()->all())
            ->get()
            ->keyBy('id');

        return $accounts->mapWithKeys(fn (HrUser $account): array => [$account->id => [
            'email' => $account->email,
            'name' => $employees->get($account->employee_id)?->name_en ?: $account->email,
        ]])->all();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    /**
     * Whether this account may use hc-tracking: its employee is active in HR.
     *
     * A system account with no employee record (e.g. HR's super admin) counts
     * as active.
     */
    public function isActive(): bool
    {
        return $this->employee_id === null || $this->employee?->is_active === true;
    }

    /**
     * Accounts that may use hc-tracking, matching isActive().
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query
            ->whereNull('employee_id')
            ->orWhereHas('employee', fn (Builder $query) => $query->where('is_active', true)));
    }

    /**
     * The employee this account belongs to.
     *
     * @return BelongsTo<HrEmployee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(HrEmployee::class, 'employee_id');
    }
}
