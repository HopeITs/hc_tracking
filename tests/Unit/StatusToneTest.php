<?php

use App\Enums\Concerns\HasStatusTone;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\RagStatus;
use App\Enums\StatusTone;
use App\Enums\TaskStatus;

/**
 * Every enum under app/Enums that implements HasStatusTone.
 *
 * @return array<string, class-string<HasStatusTone&UnitEnum>>
 */
function statusToneEnums(): array
{
    $enums = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Enums/*.php') as $file) {
        $class = 'App\\Enums\\'.basename($file, '.php');

        if (enum_exists($class) && is_subclass_of($class, HasStatusTone::class)) {
            $enums[class_basename($class)] = $class;
        }
    }

    return $enums;
}

test('every status enum answers a tone for every case', function (string $enum) {
    foreach ($enum::cases() as $case) {
        expect($case->tone())->toBeInstanceOf(StatusTone::class);
    }
})->with(fn () => array_map(fn (string $enum) => [$enum], statusToneEnums()));

test('the status-like enums all declare a tone', function () {
    expect(array_keys(statusToneEnums()))->toContain(
        'TaskStatus', 'MilestoneStatus', 'ProposalStatus', 'ProjectStatus', 'ArStatus', 'TaskPriority',
        'RagStatus', 'DeliveryState', 'ReportStatus', 'MonthlyReportStatus', 'ActivityStatus',
        'ReportTemplateStatus', 'ReportTemplateLineStatus', 'AssignmentStatus', 'ReopenRequestStatus',
    );
});

test('the W-28 inconsistencies map onto the unified tones', function (HasStatusTone $case, StatusTone $tone) {
    expect($case->tone())->toBe($tone);
})->with([
    'red is critical' => [RagStatus::Red, StatusTone::Critical],
    'amber is warn' => [RagStatus::Amber, StatusTone::Warn],
    'done is good' => [TaskStatus::Done, StatusTone::Good],
    'in progress is active' => [TaskStatus::InProgress, StatusTone::Active],
    'under review is warn' => [TaskStatus::UnderReview, StatusTone::Warn],
    'postponed is warn' => [MilestoneStatus::Postponed, StatusTone::Warn],
    'approved project is good' => [ProjectStatus::Approved, StatusTone::Good],
]);

test('each tone has badge and dot classes on the status tokens', function (StatusTone $tone) {
    expect($tone->badgeClasses())->toContain('text-')
        ->and($tone->badgeClasses())->toContain('bg-')
        ->and($tone->dotClass())->toStartWith('bg-');
})->with(StatusTone::cases());

test('the critical tone uses the status-critical token, not the brand red', function () {
    expect(StatusTone::Critical->badgeClasses())->toContain('text-status-critical')
        ->and(StatusTone::Critical->badgeClasses())->not->toContain('bg-primary')
        ->and(StatusTone::Critical->dotClass())->toBe('bg-status-critical');
});
