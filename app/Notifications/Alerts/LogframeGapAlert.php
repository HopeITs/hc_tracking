<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Program;
use App\Models\Result;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\EmployeeRecipients;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use App\Notifications\RecipientResolver;
use App\Support\Slots;

/**
 * The MEAL alert for a program with no program-level framework (T14): no
 * `results` row with its program_id and proposal_id NULL. Called after commit
 * by CheckProgramLogframe (new programs from HR, SaveProject) and daily by
 * DataGapScanner.
 *
 * One alert per program per person, held in the ledger until the program
 * gets a framework; the next check then releases it, so a later deletion
 * alerts again. Several programs in one call give each person one grouped
 * notification.
 */
final class LogframeGapAlert
{
    public const Rule = 'logframe_missing';

    public const Stage = 'logframe';

    private const Module = 'results';

    public function __construct(
        private RecipientFilter $filter,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
        private AlertLedger $ledger,
    ) {}

    /**
     * @param  list<int>  $programIds
     * @return int notifications sent
     */
    public function notify(array $programIds): int
    {
        $programIds = array_values(array_unique(array_map(intval(...), $programIds)));

        if ($programIds === []) {
            return 0;
        }

        $framed = Result::query()->programFramework()->whereIn('program_id', $programIds)->distinct()->pluck('program_id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ($framed as $programId) {
            $this->ledger->release(self::Rule, 'program', $programId, self::Stage);
        }

        $gaps = Program::query()->whereKey(array_diff($programIds, $framed))->orderBy('id')->get(['id']);

        if ($gaps->isEmpty()) {
            return 0;
        }

        $sent = 0;

        foreach (User::query()->whereIn('id', $this->recipients())->get() as $user) {
            $claimed = $gaps->filter(fn (Program $program): bool => $this->ledger->claim(self::Rule, 'program', $program->id, self::Stage, $user->id))->values();

            if ($claimed->isEmpty()) {
                continue;
            }

            $user->notify(new AppNotification($claimed->count() === 1 ? $this->single($claimed->first()) : $this->grouped($claimed->count())));
            $sent++;
        }

        return $sent;
    }

    /**
     * MEAL role holders and the MEAL Manager slot holder, kept only when they
     * may view results.
     *
     * @return list<int>
     */
    private function recipients(): array
    {
        $slotHolder = Slots::holderOf('meal_manager');
        $candidates = [
            ...$this->resolver->usersWithRoles(['MEAL']),
            ...array_values($this->employees->usersFor($slotHolder === null ? [] : [$slotHolder])),
        ];

        return $this->filter->apply($candidates, self::Module, null, $candidates);
    }

    private function single(Program $program): NotificationPayload
    {
        return $this->payload('single', ['subject' => $program->name], 'program', $program->id, [
            'route' => 'results.index',
            'parameters' => ['program' => $program->id, 'create' => 1],
        ]);
    }

    private function grouped(int $count): NotificationPayload
    {
        return $this->payload('grouped', ['count' => $count], null, null, ['route' => 'results.index']);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{route: string, parameters?: array<string, int>}  $link
     */
    private function payload(string $variant, array $params, ?string $subjectType, ?int $subjectId, array $link): NotificationPayload
    {
        return new NotificationPayload(
            kind: NotificationKind::Alert,
            module: self::Module,
            priority: NotificationPriority::Important,
            key: "notifications.alerts.logframe_missing.{$variant}",
            params: $params,
            subjectType: $subjectType,
            subjectId: $subjectId,
            link: $link,
        );
    }
}
