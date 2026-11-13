<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use Illuminate\Support\Facades\Cache;

/**
 * Tells a project owner (the project's project manager, 2026-11-12) that a
 * proposal of their project is waiting on them, with the step to do: finish
 * the concept note, collect its reviews, finish the proposal, or send it to
 * the donor. Once per owner, proposal and status, so a reminder run or a
 * repeated save never sends it twice.
 */
final class ProposalOwnerNotifier
{
    /** @var array<string, string> status => step key under notifications.events.proposal.waiting_on_you */
    public const Steps = [
        'concept note' => 'finish_concept_note',
        'concept note review' => 'collect_concept_reviews',
        'proposal draft' => 'finish_proposal',
        'proposal review' => 'send_proposal',
    ];

    /**
     * @return int how many owners were told
     */
    public static function notifyWaiting(Proposal $proposal, ?User $actor = null): int
    {
        $status = $proposal->status instanceof ProposalStatus ? $proposal->status->value : (string) $proposal->status;
        $step = self::Steps[$status] ?? null;

        if ($step === null) {
            return 0;
        }

        $ownerIds = Proposal::ownerEmployeeIdsFor([(int) $proposal->id])[(int) $proposal->id] ?? [];
        $sent = 0;

        foreach (User::query()->whereIn('employee_id', $ownerIds)->get() as $user) {
            if ($actor !== null && $user->id === $actor->id) {
                continue;
            }

            if (! Cache::add("proposal-waiting:{$proposal->id}:{$status}:{$user->id}", true, now()->addDays(60))) {
                continue;
            }

            rescue(fn () => $user->notify(new AppNotification(new NotificationPayload(
                kind: NotificationKind::Action,
                module: 'proposals',
                priority: NotificationPriority::Important,
                key: 'notifications.events.proposal.waiting_on_you.'.$step,
                params: ['proposal' => (string) $proposal->name],
                subjectType: 'proposal',
                subjectId: (int) $proposal->id,
                actorId: $actor?->id,
                link: ['route' => 'proposals.show', 'parameters' => [$proposal->slug]],
            ))), report: false);
            $sent++;
        }

        return $sent;
    }

    /**
     * Every proposal currently waiting on its owners (the daily run).
     */
    public static function notifyAllWaiting(): int
    {
        $sent = 0;

        foreach (Proposal::query()->whereIn('status', array_keys(self::Steps))->whereHas('projects', fn ($query) => $query->whereNotNull('projects.user_id'))->get() as $proposal) {
            $sent += self::notifyWaiting($proposal);
        }

        return $sent;
    }
}
