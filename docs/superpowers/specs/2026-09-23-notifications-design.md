# Notifications, Smart Alerts & Activity Log — Design

Date: 2026-09-23
Status: Approved design, pending spec review

## Goal

Make the platform feel like it is working *with* its users: every meaningful action across every module is recorded and told to the right people instantly, and the system proactively raises reminders, alerts and summaries about deadlines, stalled work, off-track indicators and risks.

## Decisions (from brainstorming)

| Topic | Decision |
|---|---|
| Channels | In-app only (no email) + real-time |
| Real-time | Laravel Reverb websockets (primary) + Livewire polling fallback (~60s, only while the socket is disconnected) + re-sync on tab focus. New dependencies approved: `laravel/reverb`, `laravel-echo`, `pusher-js` |
| Hosting | Own server / VPS — Supervisor runs `reverb:start` and `queue:work`; cron runs the scheduler |
| Assigned HR employee without a login account | Skipped; the **actor** receives a warning notification naming who was not notified |
| Actor | Never notified about their own action (only exception: the warning above) |
| Audience layers | Direct people + role watchers + program-manager cascade + General Manager / Super Admin (key events only) |
| Role watchers | Any role the user holds (not only the active role) |
| Preferences | Per-module mute; direct notifications cannot be muted |
| Granularity | Meaningful events on every module + smart grouping (same actor, same record, unread, within 5 min → merged) |
| Activity log | Yes — per-record Activity tab + global log. Custom implementation (no package). Retained 2 years |
| Priority | Info (no toast), Important (toast), Critical (red, sticky toast, pinned until read) |
| OS desktop popups | Browser Notification API, opt-in, Important + Critical only, only when tab is hidden |
| Retention | Read notifications: 61 days. Unread: forever |
| Thresholds | Editable by admins in the UI; defaults shipped in config |
| Working days | Admin-configurable; default Mon–Fri (weekend Sat–Sun). Deadline/overdue/escalation/stalled offsets count working days; no such alerts on non-working days; items due on a non-working day are warned on the last working day before |
| Stalled definition | Only a status change resets the stall timer |
| Snooze end | Notification returns as unread, moves to top, re-broadcasts (toast/popup per priority) |
| Assignment priority | Being assigned something = Important; other plain action events = Info |
| Deleted subject | Its notifications stay readable; the link shows "this item no longer exists" |
| Language | Stored as translation keys + params; rendered in the **reader's** locale (en / ar, RTL aware) |
| Security | A user is never notified about a module they lack `{module}.view` for |

## 1. Architecture

```
Livewire action → Eloquent save
   └─ RecordsActivity trait (every tracked model)
        → activities row
        → dispatch FanOutActivity (queued)
             → RecipientResolver
                 direct ∪ role watchers ∪ program-manager cascade ∪ top roles (key events)
                 − actor − users without {module}.view − users who muted the module (non-direct only)
             → Grouper (same actor + subject + unread + < 5 min → update existing row)
             → store notification + broadcast on private channel `App.Models.User.{id}`

Scheduler
   └─ Scanners (one class per rule)
        → notification_alert_ledger dedupe (rule + subject + stage + recipient)
        → same store + broadcast path
```

Units and their single responsibilities:

- `RecordsActivity` trait — hooks `created`, `updated`, `deleted` (and pivot/assignment changes via explicit calls) and writes one `Activity`. Knows which attributes are tracked per model and which count as "meaningful". Global kill switch (`Activity::withoutRecording(fn)`) used by seeders, imports and HR sync.
- `Activity` model — the audit record; source of truth for the Activity tab and global log.
- `FanOutActivity` job — turns one activity into notifications.
- `RecipientResolver` — pure recipient calculation per module (a per-module "recipient map" class keeps module knowledge out of the resolver core).
- `EmployeeRecipient` mapping — HR employee → `User` via `users.employee_id`; returns unmatched employees so the actor warning can be raised.
- `NotificationGrouper` — merge-or-create.
- `AppNotification` (custom Laravel notification + custom `database` channel writing the extra columns) + broadcast.
- Scanners — `DueSoonScanner`, `OverdueScanner`, `EscalationScanner`, `StalledScanner`, `OffTrackIndicatorScanner`, `DataGapScanner`, `DailyBriefScanner`, `WeeklyDigestScanner`. Each is independently testable, reads thresholds from `NotificationSettings`.
- `NotificationSettings` — reads admin-edited values from DB, falling back to `config/notifications.php`.

All fan-out and scanner work runs on the queue; saving a record never waits for notifications.

## 2. Data model

**`activities`**
`id`, `actor_id` (nullable → users, null for system), `subject_type`, `subject_id`, `program_id` (nullable, denormalised for cascade/filters), `module` (string, matches access-control group), `event` (e.g. `task.status_changed`), `changes` (JSON `{field: [old, new]}`), `properties` (JSON), `created_at`.
Indexes: (`subject_type`, `subject_id`, `created_at`), (`program_id`, `created_at`), (`actor_id`, `created_at`), `created_at`.

**`notifications`** — Laravel standard table (`id` uuid, `type`, `notifiable` morph, `data` JSON, `read_at`, timestamps) plus:
`priority` (info/important/critical), `module`, `kind` (action/alert/digest), `subject_type`, `subject_id`, `actor_id` (nullable), `activity_id` (nullable), `group_key` (nullable), `snoozed_until` (nullable).
Indexes: (`notifiable_type`, `notifiable_id`, `read_at`), (`notifiable_id`, `group_key`, `read_at`), (`notifiable_id`, `subject_type`, `subject_id`, `read_at`), `snoozed_until`.
`data` holds the translation key, params, URL and optional quick action definition.

**`notification_alert_ledger`**
`rule`, `subject_type`, `subject_id`, `stage`, `user_id`, `fired_at`. Unique (`rule`, `subject_type`, `subject_id`, `stage`, `user_id`). Rows are deleted to re-arm a rule (item recovered/changed).

**`notification_mutes`**
`user_id`, `module`. Unique (`user_id`, `module`).

**`notification_settings`**
`key` (unique), `value` (JSON). Defaults in `config/notifications.php`.

## 3. Recipient layers

- **Direct** — people named on the record (task assignees + creator, milestone responsible, project owner, program/annual-plan manager, report author, affected user).
- **Cascade** — manager of the record's program.
- **Role watchers** — by module (table below). A user with several roles receives every stream they qualify for.
- **Top** — General Manager and Super Admin receive ★ events and escalations only.

Always removed: the actor; users lacking `{module}.view`; users who muted the module (unless they are a direct recipient).

## 4. Event catalog (action notifications)

| Module | Events | Direct | Role watchers |
|---|---|---|---|
| Tasks | created, assigned/unassigned, status changed, due date changed, priority changed, attachment added, subtask added/completed, deleted | assignees, creator | — |
| Milestones | created, responsible changed, status changed (Postponed/Cancelled ★), dates changed, deleted | responsible, project owner | Managers |
| Projects | created, status changed (Approved/Closed ★), owner changed, dates changed, schedule baseline saved, deleted ★ | project owner | Managers |
| Programs / Annual plans / Strategies | created, manager changed, updated, deleted | manager | Managers |
| Risks | created, updated, marked happened ★ | project owner | Managers, MEAL |
| Indicators / Targets / Results | created, target changed, progress recorded, deleted | project owner | MEAL |
| Reports | created, submitted, Draft→Reviewed→Approved, file added, seen by MEAL | author | MEAL (all); Public Relations / Media Team (their own reports) |
| Donors / Proposals | created, status changed (Approved/Rejected ★), status note added | — | Financial, Public Relations Manager |
| Funding agreements / Donor reports | created, donor report submitted | — | Financial, Public Relations Manager |
| Transfers | scheduled, received, failed ★ | — | Financial |
| Accounts / Cities | created, closed, deleted | — | Financial (accounts) / Super Admin (cities) |
| Users & roles | account created, role changed | affected user | Super Admin |

★ = also sent to General Manager / Super Admin; priority Critical (failed, happened, rejected, deleted project) or Important (others).
Default priority for all other action events: Info; assignment to you: Important.

"Meaningful" fields are declared per model (e.g. task: `status`, `priority`, `due_date`, `start_date`, `title`, assignees). Changes to other fields are recorded in the activity log but do not notify.

## 5. Smart alerts (scheduled)

All numbers are defaults, editable in Admin → Notification settings. Every alert fires once per stage (ledger) and stops when the item is completed.

| Rule | Covers | Default stages | Priority | Recipients |
|---|---|---|---|---|
| Due soon | task `due_date`, milestone `planned_end_date`, report `planned_date`, donor report `due_on`, proposal `planned_date_end`, transfer `scheduled_date`, project `end_date` | 7d, 3d, 1d, today | Info; "today" = Important | direct (role watcher when no owner) |
| Overdue | same items, not Done / Reached / Approved / submitted / Received | day 1, then every 3 days | Important | direct |
| Escalation | still overdue | +5 days → program manager; +10 days → General Manager | Critical | manager / GM |
| Stalled | task In Progress with no status change 7d; task Under Review 3d; report Draft 7d / Reviewed 5d; transfer Pending 7d past `scheduled_date`; proposal same status 30d | once per stall, re-armed on status change | Important | direct + reviewer role |
| Off-track indicator | progress < 80% of expected pace (target × elapsed share of period); no progress within its `frequency` window | on crossing; re-armed on recovery | Important | project owner + MEAL |
| Data gaps | active project without milestones or indicators; project past `end_date` still Active; approved report without files | weekly | Info | project owner (+ MEAL for indicators) |
| Daily brief | due today, overdue, stalled, new assignments | 07:30 on working days; only if non-empty | Info | each user |
| Weekly digest | per program: overdue counts, off-track indicators, open risks, deadlines next 14 days | 08:00 on first working day of the week | Info | program managers (their programs), GM (all) |

"Untouched" / "same status" = no **status change** on the subject since the threshold; other edits (fields, files, subtasks) do not reset the stall timer. Stalled rules re-arm only on a status change.

Day counting: all stage offsets (due-soon 7d/3d/1d, overdue repeat every 3 days, escalation +5/+10, stalled thresholds) count **working days** only, using the admin-configured working days (default Mon–Fri). No deadline, overdue, escalation or stalled alert is sent on a non-working day. An item due on a non-working day gets its final "due" alert on the last working day before it (worded "due on Saturday"); its overdue count starts from the next working day.

Due/overdue/stalled scanners run every 15 minutes; indicator and data-gap scanners daily; digests on their schedule. Dates use the app timezone.

## 6. Interface

Visual direction: no boxy bordered panels as primary structure; priority shown with a colour dot and typography (see project design feedback).

- **Bell** in desktop and mobile headers beside the user menu; unread counter (red when any unread Critical), brief pulse on arrival.
- **Dropdown panel** — tabs All / Unread / Alerts; groups Today / Yesterday / Earlier; row = avatar-initials or alert glyph, one-line sentence, relative time, module tag; unread Critical pinned on top; hover: mark read/unread, snooze (1h / tomorrow / next week), inline quick action; footer: Mark all read, View all.
- **Quick actions** (server-side authorised via existing permissions; each writes an activity): task Mark done / Start; report Mark reviewed / Approve; transfer Mark received; overdue alert Snooze reminder.
- **`/notifications` page** — filters: module, kind, priority, read state; search; multi-select mark read; infinite scroll.
- **Toasts** — bottom corner, RTL aware; Important auto-hide 6s; Critical sticky; Info none.
- **OS popups** — opt-in; Important + Critical; only when the tab is hidden.
- **Badges** — unread count per sidebar module; dot on list rows and record headers with unread notifications for that record.
- **Activity tab** on project, task, milestone, report, proposal, program and transfer pages (timeline, "field: old → new"); **global activity log** page for Super Admin and General Manager with filters.
- **Settings → Notifications** — per-module mute toggles; OS popup opt-in.
- **Admin → Notification settings** — all thresholds, working days, digest times.
- Unread counts: one cached query per user, invalidated on new/read notification.
- Snoozed notifications are hidden until `snoozed_until`, then reappear as unread and re-broadcast.

## 7. Error handling

- Fan-out and scanner jobs are idempotent (ledger unique key; grouping by `group_key`); failed jobs retry with backoff and land in `failed_jobs`.
- Broadcasting failure never blocks storage: the notification is saved first; the client catches up via polling fallback / focus re-sync.
- A deleted subject leaves its notifications readable (sentence params stored in `data`); the link shows "item no longer exists".
- Quick actions re-check permission and current state (e.g. task already Done → friendly message, no change).

## 8. Rollout phases

Each phase gets its own implementation plan, tests and review.

1. **Foundation** — migrations, `RecordsActivity`, kill switch, `RecipientResolver`, grouping, notification storage, translation keys; Tasks module wired end-to-end as pilot.
2. **Real-time + UI** — Reverb + Echo, private channels, bell + panel, toasts, OS popups, polling fallback, `/notifications` page, read/snooze.
3. **All modules' action events** — remaining catalog, no-account actor warning, badges, Activity tabs, global log.
4. **Smart alerts** — all scanners, ledger, admin thresholds page, working days.
5. **Digests + quick actions** — daily brief, weekly digest, inline actions.
6. **Preferences & housekeeping** — module mutes page, pruning (read notifications 61d, activities 2y), Supervisor configs, cron line, index/performance pass.

## 9. Testing

Pest feature tests per phase:
- Recipient resolution: each layer, actor excluded, `.view` filtering, mutes vs direct, multi-role users, employee without account → actor warning.
- Grouping window (inside/outside 5 min, read vs unread).
- Each scanner: every stage, dedupe, re-arm on status change only, completion stops alerts, working-day counting, no alerts on non-working days, item due on a weekend warned on the preceding working day, custom working-day sets, timezone edges (using `travelTo`).
- Quick action authorisation and state checks.
- Arabic rendering of notification text.
- Broadcasting via `Event::fake` / `Notification::fake`.
- Livewire component tests for bell, panel, page, settings.
- Seeders run with recording disabled (no notifications produced).

## 10. Ops

- Dev: `composer run dev` gains `reverb:start` and `queue:work` processes.
- Production (VPS): Supervisor program files for `php artisan reverb:start` and `php artisan queue:work`; cron `* * * * * php artisan schedule:run`.
- `.env.example` gains `BROADCAST_CONNECTION=reverb` and `REVERB_*` keys.

## Out of scope

Email, SMS, mobile push, per-event-type preferences, manual follow/watch, dependency-ripple alerts, deleting/archiving notifications from the inbox (read notifications are pruned automatically after 61 days instead).
