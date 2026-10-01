<?php

/*
 * Guided tour content map (see x-tour.overlay).
 *
 * `blocks` are steps every page can earn from the shared UI components: each
 * one only appears when the current page renders a matching element. `pages`
 * adds steps for one route, keyed by route name, pointing at that page's own
 * hooks. Copy lives in lang/*\/tour.php: `blocks.<id>` for a block,
 * `pages.<route>.<id>` for a page step, and `pages.<route>.<block id>` to
 * override a block's wording on one page. Route names become keys with "." and
 * "-" replaced by "_" (funding-agreements.show => funding_agreements_show).
 * `union` spotlights every match together instead of only the first.
 */
return [
    'blocks' => [
        ['id' => 'detail', 'targets' => ['[data-detail-header]']],
        ['id' => 'stats', 'targets' => ['[data-stat-tile]'], 'union' => true],
        ['id' => 'views', 'targets' => ['[data-view-toggle]']],
        ['id' => 'status', 'targets' => ['[data-status-strip]']],
        ['id' => 'tabs', 'targets' => ['[data-relation-tabs]']],
        ['id' => 'chart', 'targets' => ['[data-chart]']],
        ['id' => 'table', 'targets' => ['[data-data-table]']],
        ['id' => 'record', 'targets' => ['[data-record-activity]']],
        ['id' => 'finance_filters', 'targets' => ['[data-finance-filters]']],
        ['id' => 'finance_totals', 'targets' => ['[data-register-totals]']],
    ],

    'pages' => [
        'dashboard' => [
            ['id' => 'my_work', 'targets' => ['[data-my-work]']],
            ['id' => 'attention', 'targets' => ['[data-attention]']],
            ['id' => 'pulse', 'targets' => ['[data-pulse]']],
            ['id' => 'funding', 'targets' => ['[data-funding-position]']],
            ['id' => 'upcoming', 'targets' => ['[data-upcoming]']],
            ['id' => 'activity', 'targets' => ['[data-activity]']],
        ],
        'programs.show' => [
            ['id' => 'loose_tasks', 'targets' => ['[data-loose-tasks]']],
        ],
        'timeline.index' => [
            ['id' => 'board', 'targets' => ['[data-timeline-board]']],
        ],
        'donors.index' => [
            ['id' => 'overview', 'targets' => ['[data-donor-overview]']],
            ['id' => 'wallet', 'targets' => ['[data-wallet]']],
            ['id' => 'follow_ups', 'targets' => ['[data-follow-ups-due]']],
            ['id' => 'rows', 'targets' => ['[data-donor-row]']],
        ],
        'donors.show' => [
            ['id' => 'river', 'targets' => ['[data-river]']],
            ['id' => 'ledger', 'targets' => ['[data-tranche-ledger]']],
            ['id' => 'owed', 'targets' => ['[data-owed-donor-reports]']],
        ],
        'proposals.index' => [
            ['id' => 'flow', 'targets' => ['[data-flow-node]'], 'union' => true],
            ['id' => 'rows', 'targets' => ['[data-proposal-row]']],
        ],
        'proposals.show' => [
            ['id' => 'vital', 'targets' => ['[data-vital]']],
            ['id' => 'lanes', 'targets' => ['[data-lane]'], 'union' => true],
            ['id' => 'warnings', 'targets' => ['[data-warnings]']],
        ],
        'funding-agreements.index' => [
            ['id' => 'stages', 'targets' => ['[data-stage-chips]']],
            ['id' => 'ledger', 'targets' => ['[data-ledger-row]']],
        ],
        'funding-agreements.show' => [
            ['id' => 'tranches', 'targets' => ['[data-tranches]']],
            ['id' => 'trace', 'targets' => ['[data-trace]']],
        ],
        'transfers.index' => [
            ['id' => 'figures', 'targets' => ['[data-ledger-figure]'], 'union' => true],
            ['id' => 'ledger', 'targets' => ['[data-ledger]']],
        ],
        'notifications.index' => [
            ['id' => 'brief', 'targets' => ['[data-inbox-brief]']],
            ['id' => 'search', 'targets' => ['[data-inbox-search]']],
            ['id' => 'feed', 'targets' => ['[data-inbox-feed]']],
        ],
        'notification-settings.edit' => [
            ['id' => 'popups', 'targets' => ['[data-popup-preference]']],
            ['id' => 'mutes', 'targets' => ['[data-module-mutes]']],
        ],
        'admin.notification-settings.edit' => [
            ['id' => 'savebar', 'targets' => ['[data-rules-savebar]']],
        ],
        'security.edit' => [
            ['id' => 'sign_in', 'targets' => ['[data-sign-in-check]']],
        ],
    ],
];
