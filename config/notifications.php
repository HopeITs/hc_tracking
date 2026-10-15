<?php

declare(strict_types=1);

use App\Notifications\Alerts\DataGapScanner;
use App\Notifications\Alerts\DueSoonScanner;
use App\Notifications\Alerts\EscalationScanner;
use App\Notifications\Alerts\OffTrackIndicatorScanner;
use App\Notifications\Alerts\OverdueScanner;
use App\Notifications\Alerts\StalledScanner;
use App\Notifications\Recipients\AccountRecipients;
use App\Notifications\Recipients\DonorRecipients;
use App\Notifications\Recipients\FundingAgreementRecipients;
use App\Notifications\Recipients\IndicatorRecipients;
use App\Notifications\Recipients\LibraryIndicatorRecipients;
use App\Notifications\Recipients\ManagedRecipients;
use App\Notifications\Recipients\MealCoverageRecipients;
use App\Notifications\Recipients\MilestoneRecipients;
use App\Notifications\Recipients\ProjectRecipients;
use App\Notifications\Recipients\ProposalRecipients;
use App\Notifications\Recipients\ReportRecipients;
use App\Notifications\Recipients\ResultRecipients;
use App\Notifications\Recipients\RiskRecipients;
use App\Notifications\Recipients\StrategyRecipients;
use App\Notifications\Recipients\TaskRecipients;
use App\Notifications\Recipients\TransferRecipients;
use App\Notifications\Recipients\UserRecipients;

return [

    /*
    |--------------------------------------------------------------------------
    | Module recipient maps
    |--------------------------------------------------------------------------
    |
    | One entry per module, keyed by the access-control group whose
    | "{module}.view" permission a user must hold to hear about it. Each class
    | knows who is named on that module's records and which roles watch it.
    */
    'modules' => [
        'tasks' => TaskRecipients::class,
        'milestones' => MilestoneRecipients::class,
        'projects' => ProjectRecipients::class,
        'programs' => ManagedRecipients::class,
        'annual-plans' => ManagedRecipients::class,
        'strategies' => StrategyRecipients::class,
        'risks' => RiskRecipients::class,
        'indicator-library' => LibraryIndicatorRecipients::class,
        'indicators' => IndicatorRecipients::class,
        'targets' => IndicatorRecipients::class,
        'results' => ResultRecipients::class,
        'reports' => ReportRecipients::class,
        'donors' => DonorRecipients::class,
        'proposals' => ProposalRecipients::class,
        'funding-agreements' => FundingAgreementRecipients::class,
        'transfers' => TransferRecipients::class,
        'accounts' => AccountRecipients::class,
        'access-management' => UserRecipients::class,
        'cities' => MealCoverageRecipients::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | View permissions
    |--------------------------------------------------------------------------
    |
    | The permission that gates a module's notifications when it is not
    | "{module}.view".
    */
    'view_permissions' => [
        'access-management' => 'access-management.manage-users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Top roles
    |--------------------------------------------------------------------------
    |
    | Roles that also receive key (★) events and escalations.
    */
    'top_roles' => ['General Manager', 'Super Admin'],

    /*
    |--------------------------------------------------------------------------
    | Top-level position
    |--------------------------------------------------------------------------
    |
    | English title of the HR position (with no direct manager) whose holder is
    | above the program managers. They hear about a project-owner assignment
    | only when a program manager rejects it.
    */
    'top_level_position_title' => 'Chief Executive Officer',

    /*
    |--------------------------------------------------------------------------
    | Grouping window
    |--------------------------------------------------------------------------
    |
    | Same actor + same record + unread + within this many minutes → the new
    | event merges into the existing notification.
    */
    'grouping_window_minutes' => 5,

    /*
    |--------------------------------------------------------------------------
    | Scanner groups
    |--------------------------------------------------------------------------
    |
    | Scanner groups run by `notifications:scan {group}`.
    */
    'scanners' => [
        'frequent' => [DueSoonScanner::class, OverdueScanner::class, EscalationScanner::class, StalledScanner::class],
        'daily' => [OffTrackIndicatorScanner::class, DataGapScanner::class],
    ],

    /*
    |--------------------------------------------------------------------------
    | Desktop notification banners
    |--------------------------------------------------------------------------
    |
    | The icon on each module's banner (the same Heroicon the sidebar uses) and
    | the browser `php artisan notifications:banners` renders them with. The
    | rendered PNGs live in public/notification-banners and are committed.
    */
    'module_icons' => [
        'tasks' => 'clipboard-document-check',
        'milestones' => 'flag',
        'projects' => 'rectangle-stack',
        'programs' => 'briefcase',
        'annual-plans' => 'calendar-days',
        'strategies' => 'chart-bar-square',
        'risks' => 'exclamation-triangle',
        'indicator-library' => 'book-open',
        'indicators' => 'variable',
        'targets' => 'flag',
        'results' => 'chart-bar',
        'reports' => 'document-chart-bar',
        'donors' => 'building-library',
        'proposals' => 'document-text',
        'funding-agreements' => 'banknotes',
        'transfers' => 'arrow-right-circle',
        'accounts' => 'credit-card',
        'access-management' => 'users',
        'cities' => 'map-pin',
        'system' => 'bell',
    ],

    'banner_browser' => env('NOTIFICATION_BANNER_BROWSER'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Housekeeping: read notifications and old activity are pruned daily.
    | Unread notifications are never pruned.
    */
    'retention' => [
        'read_notification_days' => 61,
        'activity_years' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert defaults
    |--------------------------------------------------------------------------
    |
    | Shipped defaults for every admin-editable setting (Admin → Notification
    | settings). Day counts are WORKING days. working_days uses ISO numbers:
    | 1 = Monday … 7 = Sunday.
    */
    'defaults' => [
        'working_days' => [1, 2, 3, 4, 5],
        'due_soon_days' => [7, 3, 1],
        'overdue_repeat_days' => 3,
        'escalate_manager_after_days' => 5,
        'escalate_top_after_days' => 10,
        'escalation_catch_up_days' => 3,
        'stalled_task_in_progress_days' => 7,
        'stalled_task_under_review_days' => 3,
        'stalled_report_draft_days' => 7,
        'stalled_report_reviewed_days' => 5,
        'stalled_proposal_days' => 30,
        'off_track_pace_percent' => 80,
        'daily_brief_time' => '07:30',
        'weekly_digest_time' => '08:00',
        // Admin overrides per EventCatalog key: enabled, importance, extra oversight roles.
        'event_overrides' => [],
        // Default proposal reviewers (contracts §9): an HR position id per slot, never a person.
        'proposal_default_reviewers' => ['meal_manager' => null, 'finance_manager' => null, 'fundraising_manager' => null, 'ceo' => null],
    ],

];
