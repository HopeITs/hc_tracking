<?php

declare(strict_types=1);

use App\Notifications\Recipients\AccountRecipients;
use App\Notifications\Recipients\DonorRecipients;
use App\Notifications\Recipients\FundingAgreementRecipients;
use App\Notifications\Recipients\IndicatorRecipients;
use App\Notifications\Recipients\ManagedRecipients;
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
    | Grouping window
    |--------------------------------------------------------------------------
    |
    | Same actor + same record + unread + within this many minutes → the new
    | event merges into the existing notification.
    */
    'grouping_window_minutes' => 5,

];
