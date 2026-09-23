# Notifications, Smart Alerts & Activity Log — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the full system in `docs/superpowers/specs/2026-09-23-notifications-design.md` — activity log, in-app real-time notifications, smart alerts, digests, quick actions, preferences and housekeeping — as the six rollout phases of spec §8.

**Architecture:** Every tracked model records one `Activity` row per meaningful lifecycle event through the `RecordsActivity` trait. A queued `FanOutActivity` job resolves recipients (direct ∪ cascade ∪ role watchers ∪ top, minus actor / missing `{module}.view` / mutes), groups them into existing unread rows, stores them in the `notifications` table through a custom channel, and broadcasts a `NotificationPushed` event over Reverb. Scheduled scanners feed the same store-and-broadcast path, deduplicated by `notification_alert_ledger`. Livewire components (bell, inbox page, activity timeline, settings pages) read the store; a small JS module drives toasts, OS popups and the polling fallback.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4 SFCs, Flux (flux-lite), Tailwind 4, Pest 5, spatie/laravel-permission 8, MariaDB, Laravel Reverb + laravel-echo + pusher-js (Phase 2).

## Global Constraints

- Channels: in-app only, no email. Real-time via Reverb; Livewire/JS polling fallback every ~60s **only while the socket is disconnected**; re-sync on tab focus.
- New dependencies allowed: `laravel/reverb` (composer), `laravel-echo` + `pusher-js` (npm) — Phase 2 only. Nothing else.
- The actor is never notified about their own action (only exception: the no-account warning).
- A user is never notified about a module they lack `{module}.view` for.
- Direct notifications cannot be muted; mutes are per module.
- Grouping: same actor + same record + unread + within 5 minutes → merged into one row.
- Priorities: `info` (no toast), `important` (toast, auto-hide 6s), `critical` (red, sticky toast, pinned on top until read). Being assigned = important.
- OS popups: opt-in, important + critical only, only when the tab is hidden.
- Retention: read notifications 61 days, unread forever; activities 2 years.
- Stored as translation keys + params; rendered in the **reader's** locale (en / ar, RTL aware). Every visible string in `lang/en` **and** `lang/ar`.
- Working days default Mon–Fri, admin-editable; all deadline/overdue/escalation/stalled offsets count working days; no such alerts on non-working days; items due on a non-working day are warned on the last working day before.
- Only a status change resets the stall timer.
- Deleted subject: notification stays readable; opening it shows "this item no longer exists".
- House rules: `declare(strict_types=1)` in every `app/Models` class; `#[Fillable]` attributes, `casts()` method; `CarbonImmutable`; `Model::shouldBeStrict` stays on (never lazy-load — query relations explicitly); morph map is enforced (new morph keys go in `AppServiceProvider::configureMorphMap()` and `MorphMapTest`); no `@php(...)` shorthand in Blade; logical Tailwind classes only (`ms/me/ps/pe/start/end`); `text-status-critical` for red text; `flux:modal` bound with `wire:model`; no boxy stat-tile grids or uniform bordered panels as primary structure (dot + typography instead).
- PHP binary is `C:\php85\php.exe` (XAMPP's PHP is off-limits). In commands below `php` means `/c/php85/php.exe`.
- Never commit. Every "Stage" step is `git add` only.
- After each phase: run that phase's tests + all earlier `tests/Feature/Notifications` tests + the existing test files of every module touched, then `vendor/bin/pint --dirty --format agent`.

## Interpretations the spec does not spell out (approve with the plan)

These follow from existing code or rules; each is the narrowest reading. Flagged so the approval covers them.

1. **Cities** — cities are HR-owned and read-only here (`.ai/rules/models.md`: HR is never written). Tracking has no create/close/delete path for cities, so the catalog's "Cities" row has nothing to hook and is **not built**. Accounts are built.
2. **`{module}.view` applies to direct recipients too** (spec §3 "Always removed"). Consequence: a user whose role lacks `tasks.view` gets no task notifications even as an assignee. Users created from HR with no role hold no permissions → they receive nothing until given a role. Implemented exactly as the spec says.
3. **Users & roles module** is `access-management`; its view permission is `access-management.manage-users` (there is no `access-management.view`). The **affected user** is exempt from that check (it is their own account).
4. **Reports "Public Relations / Media Team (their own reports)"** = a PR (or Media Team) user watches reports whose **author holds the same role**.
5. **Transfer "Activity tab"** — transfers have no show page. The funding-agreement show page gets an Activity section covering the agreement, its tranches and its donor reports.
6. **Quick action "transfer Mark received"** cannot be one click: `ManagesTransferForm` requires net amount + receive date for a received tranche and `.ai/rules/database-migrations.md` forbids defaulting them. The action opens the tranche's edit form (`transfers.index?edit={id}`, new deep link) instead.
7. **Escalation +10 days** goes to the "top" layer (General Manager **and** Super Admin), matching spec §3 ("top roles receive ★ events and escalations"). Items with no program (proposal, transfer, donor report) skip the +5 program-manager stage.
8. **Closed states for alerts**: task `done`; milestone `reached` or `cancelled`; report anything but `draft` (same as `Report::isOverdue()`); donor report `submitted_on` set; proposal `completed` or `rejected`; transfer: due-soon while `upcoming`, overdue while `late` (`TransfersTracking::state()` — sent money is transit, never overdue); project: `! status->isOpen()`.
9. **Stall timer origin** = latest status-change activity (or the `created` activity); records with no activity yet fall back to `updated_at`. Transfer "pending 7d past scheduled_date" counts from `scheduled_date`.
10. **Snooze presets**: 1h = now + 1 hour; tomorrow = next day 08:00; next week = first working day of next week 08:00 (app timezone).
11. **"Stalled" reviewer roles**: report → MEAL; transfer → Financial; proposal → Financial; task → none extra (creator is already direct).
12. **Supervisor configs and the cron line** are delivered in the final report text, not as repo files (no new top-level folder).
13. **Settings → Notifications** is reachable from the bell panel footer and the settings side nav (the sidebar deliberately has no Settings entry — `.ai/rules/app.md`).
14. Programs are created/renamed only by the HR mirror (`Program::syncFromHr`, run under the kill switch), so program "created/deleted" events come only from manual Eloquent calls; `manager changed` and `updated` (strategy) are the real events.

## File map

New folders (Laravel-standard, created by `make:` commands): `app/Notifications`, `app/Notifications/Recipients`, `app/Notifications/Alerts`, `app/Jobs`, `app/Events`, `tests/Feature/Notifications`.

| File | Responsibility |
|---|---|
| `app/Enums/NotificationPriority.php`, `NotificationKind.php` | priority (`info/important/critical`, `rank()`, `highest()`), kind (`action/alert/digest`) |
| `app/Models/Activity.php` | audit row, kill switch (`withoutRecording`, `isRecording`), `Prunable` (P6) |
| `app/Models/UserNotification.php` | `notifications` row (extends `DatabaseNotification`), scopes `forUser`, `visible`, `Prunable` (P6) |
| `app/Models/NotificationMute.php` | user × module mute |
| `app/Concerns/RecordsActivity.php` | trait for subject models: lifecycle hooks → one activity |
| `app/Concerns/RecordsParentActivity.php` | trait for child rows (assignee, attachment, file, note, baseline, progress) → activity on the parent |
| `app/Notifications/TracksActivity.php` | interface every subject model implements |
| `app/Notifications/ActivityRecorder.php` | writes `Activity`, dispatches `FanOutActivity` after commit |
| `app/Notifications/ActivityParameters.php` | activity → typed sentence params |
| `app/Notifications/EmployeeRecipients.php` | HR employee id → user id, localized names |
| `app/Notifications/RecipientFilter.php` | actor / `{module}.view` / mute filtering (shared by fan-out and alerts) |
| `app/Notifications/RecipientResolver.php`, `ResolvedRecipients.php` | layer union + filter + per-user priority |
| `app/Notifications/Recipients/ModuleRecipients.php` + one class per module | module knowledge (direct, assigned, watchers, ★, priority) |
| `app/Notifications/NotificationPayload.php` | DTO for one notification |
| `app/Notifications/AppNotification.php` | Laravel notification, `via()` = `AppDatabaseChannel` |
| `app/Notifications/AppDatabaseChannel.php` | store (via grouper) → invalidate counts → broadcast |
| `app/Notifications/NotificationGrouper.php` | merge-or-create |
| `app/Notifications/NotificationPresenter.php` | sentence / module label / broadcast array in a given locale |
| `app/Notifications/UnreadCounts.php` | cached per-user counts (total, critical, per module) |
| `app/Notifications/Inbox.php` | mark read/unread/all, snooze, wake — user-scoped |
| `app/Notifications/NotificationBroadcaster.php` | builds + fires `NotificationPushed`, never throws |
| `app/Notifications/SubjectLinks.php` | subject → URL, null when gone |
| `app/Notifications/Alerts/*` (P4–P5) | `NotificationSettings`, `WorkingCalendar`, `AlertLedger`, `AlertDispatcher`, `DeadlineItem`, `DeadlineCatalog`, scanners, `QuickActions` |
| `app/Jobs/FanOutActivity.php`, `app/Jobs/RunScanner.php` | queued work |
| `app/Events/NotificationPushed.php` | broadcast on `App.Models.User.{id}` as `.notification.pushed` |
| `app/Http/Controllers/OpenNotificationController.php` | mark read + redirect / "no longer exists" |
| `app/Console/Commands/WakeSnoozedNotifications.php`, `ScanNotificationAlerts.php`, `SendNotificationDigests.php` | scheduled entry points |
| `config/notifications.php` | module map, top roles, view-permission overrides, defaults |
| `lang/{en,ar}/notifications.php`, `lang/{en,ar}/activity.php` | all copy |
| `resources/views/components/notifications/⚡bell.blade.php` | bell + dropdown panel |
| `resources/views/components/notifications/row.blade.php` | one notification row (bell + page) |
| `resources/views/components/activity/⚡timeline.blade.php` | Activity tab / section |
| `resources/views/pages/notifications/⚡index.blade.php` | `/notifications` |
| `resources/views/pages/activity/⚡index.blade.php` | global activity log |
| `resources/views/pages/notifications/⚡settings.blade.php` | popup opt-in (P2) + module mutes (P6) |
| `resources/views/pages/admin/⚡notification-settings.blade.php` | thresholds, working days, digest times |
| `resources/js/notifications.js` | Echo subscription, toasts, OS popups, polling fallback, focus re-sync |

Every test lives in `tests/Feature/Notifications/`. Run a phase with `php artisan test --compact tests/Feature/Notifications`.

---

# PHASE 1 — Foundation (Tasks module pilot)

Delivers: migrations, activity recording with kill switch, recipient resolution, grouping, storage, translation keys; Tasks recorded and notified end-to-end. No UI, no broadcast yet.

### Task 1.1: Priority and kind enums

**Files:**
- Create: `app/Enums/NotificationPriority.php`, `app/Enums/NotificationKind.php`
- Modify: `lang/en/enums.php`, `lang/ar/enums.php`
- Test: `tests/Feature/Notifications/NotificationEnumTest.php`

**Interfaces — Produces:** `NotificationPriority::{Info,Important,Critical}`, `->rank(): int`, `::highest(NotificationPriority ...$priorities): NotificationPriority`, `::values()`; `NotificationKind::{Action,Alert,Digest}`, `::values()`. Both use `HasTranslatedLabel`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;

test('priorities rank info below important below critical', function () {
    expect(NotificationPriority::Info->rank())->toBeLessThan(NotificationPriority::Important->rank())
        ->and(NotificationPriority::Important->rank())->toBeLessThan(NotificationPriority::Critical->rank());
});

test('the highest of several priorities wins', function () {
    expect(NotificationPriority::highest(NotificationPriority::Info, NotificationPriority::Critical, NotificationPriority::Important))
        ->toBe(NotificationPriority::Critical)
        ->and(NotificationPriority::highest())->toBe(NotificationPriority::Info);
});

test('priority and kind labels are translated in arabic', function () {
    app()->setLocale('ar');

    expect(NotificationPriority::Critical->label())->toBe('حرجة')
        ->and(NotificationKind::Alert->label())->toBe('تنبيه');
});
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --compact tests/Feature/Notifications/NotificationEnumTest.php` → FAIL (class not found).

- [ ] **Step 3: Implement**

`app/Enums/NotificationPriority.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How loudly a notification arrives: info (inbox only), important (toast),
 * critical (red sticky toast, pinned until read).
 *
 * Stored in `notifications.priority`. Values are lower case.
 */
enum NotificationPriority: string
{
    use HasTranslatedLabel;

    case Info = 'info';
    case Important = 'important';
    case Critical = 'critical';

    /**
     * The position of this priority, so two can be compared.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Important => 1,
            self::Critical => 2,
        };
    }

    /**
     * The loudest of the given priorities, Info when none are given.
     */
    public static function highest(self ...$priorities): self
    {
        $highest = self::Info;

        foreach ($priorities as $priority) {
            if ($priority->rank() > $highest->rank()) {
                $highest = $priority;
            }
        }

        return $highest;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

`app/Enums/NotificationKind.php`:
```php
<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Where a notification came from: somebody's action, a scheduled alert, or
 * a digest. Stored in `notifications.kind`. Values are lower case.
 */
enum NotificationKind: string
{
    use HasTranslatedLabel;

    case Action = 'action';
    case Alert = 'alert';
    case Digest = 'digest';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
```

Add to `lang/en/enums.php`:
```php
    'notification_priority' => [
        'info' => 'Info',
        'important' => 'Important',
        'critical' => 'Critical',
    ],
    'notification_kind' => [
        'action' => 'Activity',
        'alert' => 'Alert',
        'digest' => 'Digest',
    ],
```
Add to `lang/ar/enums.php`:
```php
    'notification_priority' => [
        'info' => 'معلومة',
        'important' => 'مهمة',
        'critical' => 'حرجة',
    ],
    'notification_kind' => [
        'action' => 'نشاط',
        'alert' => 'تنبيه',
        'digest' => 'ملخص',
    ],
```

- [ ] **Step 4: Run to verify it passes** — same command → PASS. Also `php artisan test --compact tests/Feature/LocalizationTest.php tests/Feature/EnumCastTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Enums/Notification*.php lang/en/enums.php lang/ar/enums.php tests/Feature/Notifications/NotificationEnumTest.php`

### Task 1.2: Tables, models and User wiring

**Files:**
- Create (via `php artisan make:migration --no-interaction`): `create_activities_table`, `create_notifications_table`, `create_notification_mutes_table`
- Create: `app/Models/Activity.php`, `app/Models/UserNotification.php`, `app/Models/NotificationMute.php`
- Modify: `app/Models/User.php` (notifications relation, `HasLocalePreference`, delete inbox on delete), `app/Providers/AppServiceProvider.php` (morph key `task`), `tests/Feature/Models/MorphMapTest.php`
- Test: `tests/Feature/Notifications/NotificationStorageTest.php`

**Interfaces — Produces:**
- `Activity` columns `actor_id, subject_type, subject_id, program_id, module, event, changes (array), properties (array)`; `Activity::withoutRecording(callable): mixed`, `Activity::isRecording(): bool`, `->actor(): BelongsTo<User>`, `->subject(): MorphTo`, `->shouldNotify(): bool`, `->label(): string`.
- `UserNotification` (table `notifications`, uuid) extra columns `priority, module, kind, subject_type, subject_id, actor_id, activity_id, group_key, snoozed_until`; scopes `forUser(User)`, `visible()`; inherits `unread()`, `markAsRead()`, `markAsUnread()`.
- `NotificationMute` `user_id, module`.
- `User::notifications(): MorphMany<UserNotification>`, `User::preferredLocale(): ?string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Str;

test('a notification row carries priority module kind subject and group key', function () {
    $user = User::factory()->create();

    $notification = UserNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'test',
        'notifiable_type' => 'user',
        'notifiable_id' => $user->id,
        'data' => ['key' => 'notifications.events.task.created', 'params' => ['subject' => 'مهمة']],
        'priority' => NotificationPriority::Important,
        'module' => 'tasks',
        'kind' => NotificationKind::Action,
        'subject_type' => 'task',
        'subject_id' => 7,
        'group_key' => '1:task:7',
    ]);

    $fresh = $user->notifications()->first();

    expect($fresh)->toBeInstanceOf(UserNotification::class)
        ->and($fresh->id)->toBe($notification->id)
        ->and($fresh->priority)->toBe(NotificationPriority::Important)
        ->and($fresh->kind)->toBe(NotificationKind::Action)
        ->and($fresh->data['params']['subject'])->toBe('مهمة');
});

test('arabic text in notification data is stored unescaped so it can be searched', function () {
    $user = User::factory()->create();

    UserNotification::query()->create([
        'id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => 'user', 'notifiable_id' => $user->id,
        'data' => ['params' => ['subject' => 'توزيع']], 'module' => 'tasks',
    ]);

    expect(UserNotification::query()->where('data', 'like', '%توزيع%')->count())->toBe(1);
});

test('snoozed notifications are hidden until their snooze ends', function () {
    $user = User::factory()->create();
    $base = ['type' => 'test', 'notifiable_type' => 'user', 'notifiable_id' => $user->id, 'data' => [], 'module' => 'tasks'];

    UserNotification::query()->create($base + ['id' => (string) Str::uuid(), 'snoozed_until' => now()->addHour()]);
    UserNotification::query()->create($base + ['id' => (string) Str::uuid(), 'snoozed_until' => now()->subMinute()]);
    UserNotification::query()->create($base + ['id' => (string) Str::uuid()]);

    expect(UserNotification::query()->forUser($user)->visible()->count())->toBe(2);
});

test('the kill switch stops recording for the callback only', function () {
    expect(Activity::isRecording())->toBeTrue();

    $inside = Activity::withoutRecording(fn (): bool => Activity::isRecording());

    expect($inside)->toBeFalse()->and(Activity::isRecording())->toBeTrue();
});

test('the kill switch is restored even when the callback throws', function () {
    try {
        Activity::withoutRecording(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    expect(Activity::isRecording())->toBeTrue();
});

test('a user prefers the language saved on their account', function () {
    $user = User::factory()->create();
    $user->forceFill(['locale' => 'ar'])->save();

    expect($user->preferredLocale())->toBe('ar');
});

test('deleting a user deletes their inbox', function () {
    $user = User::factory()->create();
    UserNotification::query()->create([
        'id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => 'user', 'notifiable_id' => $user->id,
        'data' => [], 'module' => 'tasks',
    ]);

    $user->delete();

    expect(UserNotification::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run** — FAIL (tables/classes missing).

- [ ] **Step 3: Migrations**

`php artisan make:migration create_activities_table --no-interaction`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail: one row per meaningful thing that happened to a record.
 *
 * subject_type/subject_id is polymorphic (morph-map keys), so it has no
 * foreign key and outlives the record it describes on purpose — the Activity
 * tab and global log still read after a delete. program_id is denormalised for
 * the manager cascade and filters, and likewise carries no foreign key.
 * Rows are pruned after two years (Activity::prunable()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('program_id')->nullable();
            $table->string('module', 50);
            $table->string('event', 100);
            $table->json('changes');
            $table->json('properties');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'created_at']);
            $table->index(['program_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
```

`php artisan make:migration create_notifications_table --no-interaction`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's database-notification table plus the columns the inbox filters,
 * groups and pins on.
 *
 * `data` holds the translation key, its params, the merge count and the
 * optional link / quick actions; the sentence is rendered in the reader's
 * language at display time. A row whose subject was deleted stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type', 50);
            $table->unsignedBigInteger('notifiable_id');
            $table->json('data');
            $table->string('priority', 20)->default('info');
            $table->string('module', 50);
            $table->string('kind', 20)->default('action');
            $table->string('subject_type', 50)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained('activities')->nullOnDelete();
            $table->string('group_key', 120)->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index(['notifiable_id', 'group_key', 'read_at']);
            $table->index(['notifiable_id', 'subject_type', 'subject_id', 'read_at'], 'notifications_subject_unread_index');
            $table->index('snoozed_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
```

`php artisan make:migration create_notification_mutes_table --no-interaction`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modules a user has muted. A mute silences watcher, cascade and top-level
 * notifications for that module; direct notifications still arrive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('module', 50);
            $table->timestamps();

            $table->unique(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_mutes');
    }
};
```

- [ ] **Step 4: Models**

`app/Models/Activity.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One meaningful thing that happened to a record: the source of truth for the
 * Activity tab, the global log and every action notification.
 *
 * Recording is global and on by default. Seeders, imports and the HR mirror
 * wrap their writes in withoutRecording() so they produce no activity and
 * therefore no notifications.
 *
 * @property int $id
 * @property int|null $actor_id
 * @property string $subject_type
 * @property int $subject_id
 * @property int|null $program_id
 * @property string $module
 * @property string $event
 * @property array<string, array{0: mixed, 1: mixed}> $changes
 * @property array<string, mixed> $properties
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['actor_id', 'subject_type', 'subject_id', 'program_id', 'module', 'event', 'changes', 'properties'])]
class Activity extends Model
{
    private static bool $recording = true;

    /**
     * Run the callback without recording any activity, restoring the previous
     * state afterwards even when it throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutRecording(callable $callback): mixed
    {
        $previous = self::$recording;
        self::$recording = false;

        try {
            return $callback();
        } finally {
            self::$recording = $previous;
        }
    }

    /**
     * Whether model events currently write activity.
     */
    public static function isRecording(): bool
    {
        return self::$recording;
    }

    /**
     * The user who acted; null for the system.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The record this happened to. Can be gone.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether this activity is meaningful enough to notify anyone.
     */
    public function shouldNotify(): bool
    {
        return (bool) ($this->properties['notify'] ?? false);
    }

    /**
     * The record's name as it was when this happened.
     */
    public function label(): string
    {
        return (string) ($this->properties['label'] ?? '');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'changes' => 'json:unicode',
            'properties' => 'json:unicode',
        ];
    }
}
```

`app/Models/UserNotification.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One row of a user's inbox.
 *
 * Extends Laravel's DatabaseNotification so the Notifiable helpers keep
 * working, and adds the columns the inbox groups, filters and pins on.
 *
 * @property string $id
 * @property string $type
 * @property string $notifiable_type
 * @property int $notifiable_id
 * @property array<string, mixed> $data
 * @property NotificationPriority $priority
 * @property string $module
 * @property NotificationKind $kind
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property int|null $actor_id
 * @property int|null $activity_id
 * @property string|null $group_key
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'priority', 'module', 'kind',
    'subject_type', 'subject_id', 'actor_id', 'activity_id', 'group_key', 'snoozed_until', 'read_at',
])]
class UserNotification extends DatabaseNotification
{
    /**
     * Mirror the column defaults for rows that have not been round-tripped.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'priority' => 'info',
        'kind' => 'action',
    ];

    /**
     * Limit to one user's inbox.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function forUser(Builder $query, User $user): void
    {
        $query->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey());
    }

    /**
     * Hide rows that are still snoozed.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'json:unicode',
            'priority' => NotificationPriority::class,
            'kind' => NotificationKind::class,
            'subject_id' => 'integer',
            'actor_id' => 'integer',
            'activity_id' => 'integer',
            'snoozed_until' => 'datetime',
            'read_at' => 'datetime',
        ];
    }
}
```

`app/Models/NotificationMute.php`:
```php
<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A module one user has muted. Direct notifications ignore it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $module
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'module'])]
class NotificationMute extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/User.php` — add `implements PasskeyUser, HasLocalePreference` (`use Illuminate\Contracts\Translation\HasLocalePreference;`, `use Illuminate\Database\Eloquent\Relations\MorphMany;`) and:
```php
    /**
     * Remove the inbox with the user: notifications are polymorphic, so no
     * foreign key can cascade them.
     */
    protected static function booted(): void
    {
        static::deleted(function (User $user): void {
            UserNotification::query()->forUser($user)->delete();
        });
    }

    /**
     * This user's inbox, newest first, as UserNotification rows.
     *
     * @return MorphMany<UserNotification, $this>
     */
    public function notifications(): MorphMany
    {
        return $this->morphMany(UserNotification::class, 'notifiable')->latest();
    }

    /**
     * The language notifications are written in for this user.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
```

`AppServiceProvider::configureMorphMap()` — add `'task' => Task::class,` (import `App\Models\Task`) and extend the docblock: "`task` is the subject of activity and notifications". Update `tests/Feature/Models/MorphMapTest.php` first test's expected array to include `'task' => Task::class` and rename it to "…and the activity subjects". (Phase 3 adds the rest.)

- [ ] **Step 5: Run** — `php artisan test --compact tests/Feature/Notifications/NotificationStorageTest.php tests/Feature/Models/MorphMapTest.php tests/Feature/DatabaseEncodingTest.php` → PASS.
- [ ] **Step 6: Stage** — `git add database/migrations/*activities* database/migrations/*notifications_table* database/migrations/*notification_mutes* app/Models/Activity.php app/Models/UserNotification.php app/Models/NotificationMute.php app/Models/User.php app/Providers/AppServiceProvider.php tests/Feature/Models/MorphMapTest.php tests/Feature/Notifications/NotificationStorageTest.php`

### Task 1.3: Recipient resolution (employees, filter, resolver, Tasks map)

**Files:**
- Create: `config/notifications.php`, `app/Notifications/EmployeeRecipients.php`, `app/Notifications/RecipientFilter.php`, `app/Notifications/ResolvedRecipients.php`, `app/Notifications/RecipientResolver.php`, `app/Notifications/Recipients/ModuleRecipients.php`, `app/Notifications/Recipients/TaskRecipients.php`
- Test: `tests/Feature/Notifications/RecipientResolverTest.php`

**Interfaces — Produces:**
- `EmployeeRecipients::usersFor(array $employeeIds): array<int,int>` (employee id ⇒ user id); `::names(array $employeeIds): array<int, array{en: string, ar: string}>`.
- `RecipientFilter::apply(array $candidateIds, string $module, ?int $actorId, array $directIds = [], array $exemptIds = []): list<int>`.
- `RecipientResolver::resolve(Activity $activity): ResolvedRecipients`; `RecipientResolver::usersWithRoles(array $roles): list<int>`; `RecipientResolver::programManagerUserIds(?int $programId): list<int>`.
- `ResolvedRecipients { array $priorities (user id ⇒ NotificationPriority); list<int> $assignedUserIds; list<int> $unnotifiedEmployeeIds }`.
- `ModuleRecipients` abstract: `directEmployeeIds`, `directUserIds`, `assignedEmployeeIds`, `watcherRoles`, `isKeyEvent`, `priority`, `permissionExemptUserIds` (all take `Activity`), helpers `changedTo(Activity, string): mixed`, `changedFrom(Activity, string): mixed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\NotificationMute;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\RecipientResolver;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * A task activity written by hand, so the resolver is tested without the
 * recorder.
 *
 * @param  array<string, mixed>  $properties
 */
function taskActivity(Task $task, string $event, ?int $actorId, array $properties = [], ?int $programId = null): Activity
{
    return Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actorId,
        'subject_type' => 'task',
        'subject_id' => $task->id,
        'program_id' => $programId,
        'module' => 'tasks',
        'event' => $event,
        'changes' => [],
        'properties' => ['label' => $task->title, 'notify' => true] + $properties,
    ]));
}

/**
 * A task with exactly the given HR employees assigned, created without
 * recording.
 *
 * @param  list<int>  $employeeIds
 */
function taskAssignedTo(array $employeeIds, ?int $createdBy = null): Task
{
    return Activity::withoutRecording(function () use ($employeeIds, $createdBy): Task {
        $task = Task::factory()->create($createdBy === null ? [] : ['created_by' => $createdBy]);
        $task->taskAssignees()->delete();

        foreach ($employeeIds as $employeeId) {
            $task->taskAssignees()->create(['employee_id' => $employeeId]);
        }

        return $task;
    });
}

test('assignees with an account and the creator are direct recipients; the actor is removed', function () {
    $employee = insertHrEmployee();
    $assignee = User::factory()->role('Managers')->create(['employee_id' => $employee]);
    $creator = User::factory()->role('Managers')->create();
    $task = taskAssignedTo([$employee], $creator->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.status_changed', $creator->id));

    expect(array_keys($resolved->priorities))->toBe([$assignee->id]);
});

test('an assigned employee without an account is reported as not notified', function () {
    $withoutAccount = insertHrEmployee(firstNameEn: 'Nour');
    $actor = User::factory()->role('Managers')->create();
    $task = taskAssignedTo([$withoutAccount], $actor->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.assigned', $actor->id, ['employee_id' => $withoutAccount]));

    expect($resolved->priorities)->toBe([])
        ->and($resolved->unnotifiedEmployeeIds)->toBe([$withoutAccount]);
});

test('being assigned is important while the same event is info for everyone else', function () {
    $employee = insertHrEmployee();
    $assignee = User::factory()->role('Managers')->create(['employee_id' => $employee]);
    $creator = User::factory()->role('Managers')->create();
    $actor = User::factory()->role('Managers')->create();
    $task = taskAssignedTo([$employee], $creator->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.assigned', $actor->id, ['employee_id' => $employee]));

    expect($resolved->priorities[$assignee->id])->toBe(NotificationPriority::Important)
        ->and($resolved->priorities[$creator->id])->toBe(NotificationPriority::Info)
        ->and($resolved->assignedUserIds)->toBe([$assignee->id]);
});

test('the manager of the task program is a cascade recipient', function () {
    $managerEmployee = insertHrEmployee();
    $manager = User::factory()->role('Managers')->create(['employee_id' => $managerEmployee]);
    $program = Program::factory()->create(['manager_id' => $managerEmployee]);
    $actor = User::factory()->role('Managers')->create();
    $task = taskAssignedTo([], $actor->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.created', $actor->id, programId: $program->id));

    expect(array_keys($resolved->priorities))->toBe([$manager->id]);
});

test('users without tasks.view are never notified about tasks', function () {
    $employee = insertHrEmployee();
    User::factory()->role('Financial')->create(['employee_id' => $employee]);
    $actor = User::factory()->role('Managers')->create();
    $task = taskAssignedTo([$employee], $actor->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.status_changed', $actor->id));

    expect($resolved->priorities)->toBe([]);
});

test('a mute silences cascade notifications but never direct ones', function () {
    $managerEmployee = insertHrEmployee();
    $manager = User::factory()->role('Managers')->create(['employee_id' => $managerEmployee]);
    $assigneeEmployee = insertHrEmployee();
    $assignee = User::factory()->role('Managers')->create(['employee_id' => $assigneeEmployee]);
    $program = Program::factory()->create(['manager_id' => $managerEmployee]);
    $actor = User::factory()->role('Managers')->create();
    $task = taskAssignedTo([$assigneeEmployee], $actor->id);
    NotificationMute::query()->create(['user_id' => $manager->id, 'module' => 'tasks']);
    NotificationMute::query()->create(['user_id' => $assignee->id, 'module' => 'tasks']);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.status_changed', $actor->id, programId: $program->id));

    expect(array_keys($resolved->priorities))->toBe([$assignee->id]);
});

test('a deleted task still reaches the people it named', function () {
    $employee = insertHrEmployee();
    $assignee = User::factory()->role('Managers')->create(['employee_id' => $employee]);
    $actor = User::factory()->role('Managers')->create();
    $task = taskAssignedTo([], $actor->id);

    $resolved = app(RecipientResolver::class)->resolve(taskActivity($task, 'task.deleted', $actor->id, ['employee_ids' => [$employee], 'creator_id' => $actor->id]));

    expect(array_keys($resolved->priorities))->toBe([$assignee->id]);
});

test('a module nobody mapped has no recipients', function () {
    $actor = User::factory()->create();
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => 1, 'module' => 'nowhere',
        'event' => 'x.y', 'changes' => [], 'properties' => [],
    ]));

    expect(app(RecipientResolver::class)->resolve($activity)->priorities)->toBe([]);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`config/notifications.php`:
```php
<?php

declare(strict_types=1);

use App\Notifications\Recipients\TaskRecipients;

return [

    /*
    | Module → recipient map. The key is the access-control group the module's
    | `{module}.view` permission belongs to. Phase 3 adds the other modules.
    */
    'modules' => [
        'tasks' => TaskRecipients::class,
    ],

    /*
    | Permission a user must hold to hear about a module, when it is not
    | "{module}.view".
    */
    'view_permissions' => [
        'access-management' => 'access-management.manage-users',
    ],

    /*
    | Roles that receive ★ events and escalations.
    */
    'top_roles' => ['General Manager', 'Super Admin'],

    /*
    | Same actor + same record + unread + within this many minutes → merged.
    */
    'grouping_window_minutes' => 5,

];
```

`app/Notifications/EmployeeRecipients.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\HrEmployee;
use App\Models\User;

/**
 * Maps HR employees (who are assigned, responsible, owners, managers) to the
 * tracking users that can receive notifications. An employee with no login
 * account maps to nobody.
 */
final class EmployeeRecipients
{
    /**
     * @param  array<int, int>  $employeeIds
     * @return array<int, int> employee id => user id
     */
    public function usersFor(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('employee_id', array_values(array_unique($employeeIds)))
            ->pluck('id', 'employee_id')
            ->mapWithKeys(fn (int|string $userId, int|string $employeeId): array => [(int) $employeeId => (int) $userId])
            ->all();
    }

    /**
     * Both-language names, so a sentence can be rendered in either.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, array{en: string, ar: string}>
     */
    public function names(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return HrEmployee::query()
            ->whereIn('id', array_values(array_unique($employeeIds)))
            ->get()
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => ['en' => $employee->name_en, 'ar' => $employee->name_ar]])
            ->all();
    }
}
```

`app/Notifications/RecipientFilter.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\NotificationMute;
use App\Models\Permission;
use App\Models\User;

/**
 * The removals every notification goes through: the actor, anyone lacking the
 * module's view permission, and anyone who muted the module (unless they are
 * a direct recipient).
 */
final class RecipientFilter
{
    /**
     * @param  array<int, int>  $candidateIds
     * @param  array<int, int>  $directIds  never removed by a mute
     * @param  array<int, int>  $exemptIds  skip the permission check (their own account)
     * @return list<int>
     */
    public function apply(array $candidateIds, string $module, ?int $actorId, array $directIds = [], array $exemptIds = []): array
    {
        $ids = array_values(array_unique(array_diff($candidateIds, $actorId === null ? [] : [$actorId])));

        if ($ids === []) {
            return [];
        }

        $allowed = array_values(array_unique([
            ...$this->withViewPermission($ids, $module),
            ...array_intersect($ids, $exemptIds),
        ]));

        if ($allowed === []) {
            return [];
        }

        $muted = NotificationMute::query()
            ->where('module', $module)
            ->whereIn('user_id', $allowed)
            ->pluck('user_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $kept = array_filter($allowed, fn (int $id): bool => in_array($id, $directIds, true) || ! in_array($id, $muted, true));

        sort($kept);

        return array_values($kept);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function withViewPermission(array $ids, string $module): array
    {
        $permission = config("notifications.view_permissions.{$module}", "{$module}.view");

        if (! Permission::query()->where('name', $permission)->exists()) {
            return [];
        }

        return User::permission($permission)
            ->whereIn('users.id', $ids)
            ->pluck('users.id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }
}
```

`app/Notifications/ResolvedRecipients.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationPriority;

/**
 * Who hears about one activity, and how loudly.
 */
final readonly class ResolvedRecipients
{
    /**
     * @param  array<int, NotificationPriority>  $priorities  user id => priority
     * @param  list<int>  $assignedUserIds  recipients this activity assigns something to
     * @param  list<int>  $unnotifiedEmployeeIds  assigned employees who have no account
     */
    public function __construct(
        public array $priorities,
        public array $assignedUserIds = [],
        public array $unnotifiedEmployeeIds = [],
    ) {}
}
```

`app/Notifications/Recipients/ModuleRecipients.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;

/**
 * Everything one module knows about who hears about its activity. The
 * resolver core stays module-agnostic; each module overrides only what it has.
 */
abstract class ModuleRecipients
{
    /**
     * HR employees named on the record.
     *
     * @return list<int>
     */
    public function directEmployeeIds(Activity $activity): array
    {
        return [];
    }

    /**
     * Tracking users named on the record (creator, author, affected user).
     *
     * @return list<int>
     */
    public function directUserIds(Activity $activity): array
    {
        return [];
    }

    /**
     * Employees this activity assigns something to: important for them, and
     * the actor is warned about any who have no account.
     *
     * @return list<int>
     */
    public function assignedEmployeeIds(Activity $activity): array
    {
        return [];
    }

    /**
     * Roles that watch this activity. A user with any of them receives it.
     *
     * @return list<string>
     */
    public function watcherRoles(Activity $activity): array
    {
        return [];
    }

    /**
     * ★ — also sent to the top roles.
     */
    public function isKeyEvent(Activity $activity): bool
    {
        return false;
    }

    /**
     * Priority for every recipient; being assigned raises it for that person.
     */
    public function priority(Activity $activity): NotificationPriority
    {
        return $this->isKeyEvent($activity) ? NotificationPriority::Important : NotificationPriority::Info;
    }

    /**
     * Users who skip the view-permission check for this activity.
     *
     * @return list<int>
     */
    public function permissionExemptUserIds(Activity $activity): array
    {
        return [];
    }

    /**
     * The value a field was changed to, or null when it did not change.
     */
    protected function changedTo(Activity $activity, string $field): mixed
    {
        return $activity->changes[$field][1] ?? null;
    }

    /**
     * The value a field was changed from, or null when it did not change.
     */
    protected function changedFrom(Activity $activity, string $field): mixed
    {
        return $activity->changes[$field][0] ?? null;
    }

    /**
     * Integer ids from a property that may hold one id or a list.
     *
     * @return list<int>
     */
    protected function idsFromProperty(Activity $activity, string $key): array
    {
        $value = $activity->properties[$key] ?? [];

        return array_values(array_map('intval', is_array($value) ? $value : [$value]));
    }
}
```

`app/Notifications/Recipients/TaskRecipients.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskAssignee;

/**
 * Tasks: assignees and creator are direct; no role watchers.
 */
final class TaskRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        $current = TaskAssignee::query()->where('task_id', $activity->subject_id)->pluck('employee_id')->all();

        return array_values(array_unique(array_map('intval', [
            ...$current,
            ...$this->idsFromProperty($activity, 'employee_ids'),
            ...$this->idsFromProperty($activity, 'employee_id'),
        ])));
    }

    public function directUserIds(Activity $activity): array
    {
        $creator = $activity->properties['creator_id']
            ?? Task::query()->whereKey($activity->subject_id)->value('created_by');

        return $creator === null ? [] : [(int) $creator];
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        return $activity->event === 'task.assigned' ? $this->idsFromProperty($activity, 'employee_id') : [];
    }
}
```

`app/Notifications/RecipientResolver.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Recipients\ModuleRecipients;

/**
 * Turns one activity into the users who hear about it:
 * direct ∪ program-manager cascade ∪ role watchers ∪ top (★ only),
 * then RecipientFilter removes the actor, users without the module's view
 * permission and users who muted it (unless direct).
 */
final class RecipientResolver
{
    public function __construct(
        private EmployeeRecipients $employees,
        private RecipientFilter $filter,
    ) {}

    public function resolve(Activity $activity): ResolvedRecipients
    {
        $map = $this->mapFor($activity->module);

        if ($map === null) {
            return new ResolvedRecipients([]);
        }

        $assignedEmployeeIds = $map->assignedEmployeeIds($activity);
        $employeeIds = array_values(array_unique([...$map->directEmployeeIds($activity), ...$assignedEmployeeIds]));
        $matched = $this->employees->usersFor($employeeIds);

        $direct = array_values(array_unique([...array_values($matched), ...$map->directUserIds($activity)]));
        $assignedUserIds = array_values(array_intersect_key($matched, array_flip($assignedEmployeeIds)));

        $candidates = [
            ...$direct,
            ...$this->programManagerUserIds($activity->program_id),
            ...$this->usersWithRoles($map->watcherRoles($activity)),
            ...($map->isKeyEvent($activity) ? $this->usersWithRoles(config('notifications.top_roles')) : []),
        ];

        $recipients = $this->filter->apply($candidates, $activity->module, $activity->actor_id, $direct, $map->permissionExemptUserIds($activity));
        $base = $map->priority($activity);
        $priorities = [];

        foreach ($recipients as $userId) {
            $priorities[$userId] = in_array($userId, $assignedUserIds, true)
                ? NotificationPriority::highest($base, NotificationPriority::Important)
                : $base;
        }

        return new ResolvedRecipients(
            $priorities,
            array_values(array_intersect($assignedUserIds, $recipients)),
            array_values(array_diff($assignedEmployeeIds, array_keys($matched))),
        );
    }

    /**
     * Users holding any of the roles; roles that do not exist are skipped
     * (spatie's role() scope throws on an unknown name).
     *
     * @param  array<int, string>  $roles
     * @return list<int>
     */
    public function usersWithRoles(array $roles): array
    {
        $existing = Role::query()->whereIn('name', $roles)->pluck('name')->all();

        if ($existing === []) {
            return [];
        }

        return User::role($existing)->pluck('users.id')->map(fn (int|string $id): int => (int) $id)->all();
    }

    /**
     * The user behind a program's HR manager, when there is one.
     *
     * @return list<int>
     */
    public function programManagerUserIds(?int $programId): array
    {
        if ($programId === null) {
            return [];
        }

        $managerId = Program::query()->whereKey($programId)->value('manager_id');

        return $managerId === null ? [] : array_values($this->employees->usersFor([(int) $managerId]));
    }

    private function mapFor(string $module): ?ModuleRecipients
    {
        $class = config("notifications.modules.{$module}");

        return $class === null ? null : app($class);
    }
}
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/RecipientResolverTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add config/notifications.php app/Notifications tests/Feature/Notifications/RecipientResolverTest.php`

### Task 1.4: Payload, grouping, custom channel, AppNotification

**Files:**
- Create: `app/Notifications/NotificationPayload.php`, `app/Notifications/NotificationGrouper.php`, `app/Notifications/AppDatabaseChannel.php`, `app/Notifications/AppNotification.php` (via `php artisan make:notification AppNotification --no-interaction`, then replaced)
- Test: `tests/Feature/Notifications/NotificationGroupingTest.php`

**Interfaces — Produces:**
- `NotificationPayload` readonly: `kind, module, priority, key, params (array), subjectType, subjectId, actorId, activityId, groupKey, link (array{route?: string, parameters?: array}), actions (list<array>)`.
- `NotificationGrouper::store(User $user, NotificationPayload $payload, string $id): array{0: UserNotification, 1: bool}` (row, merged?).
- `AppNotification(NotificationPayload $payload)`, `via()` ⇒ `[AppDatabaseChannel::class]`.
- `AppDatabaseChannel::send(User $notifiable, AppNotification $notification): UserNotification`.
- Stored `data` shape: `{key, params, count, activity_ids: list<int>, link, actions}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;

function taskPayload(int $activityId, NotificationPriority $priority = NotificationPriority::Info, string $key = 'notifications.events.task.updated', string $groupKey = '5:task:9'): NotificationPayload
{
    return new NotificationPayload(
        kind: NotificationKind::Action,
        module: 'tasks',
        priority: $priority,
        key: $key,
        params: ['actor' => 'Sami', 'subject' => 'Plan'],
        subjectType: 'task',
        subjectId: 9,
        actorId: null,
        activityId: $activityId,
        groupKey: $groupKey,
    );
}

test('a notification is stored with its columns and a count of one', function () {
    $user = User::factory()->create();

    $user->notify(new AppNotification(taskPayload(1, NotificationPriority::Important)));

    $row = UserNotification::query()->sole();

    expect($row->priority)->toBe(NotificationPriority::Important)
        ->and($row->module)->toBe('tasks')
        ->and($row->subject_type)->toBe('task')
        ->and($row->subject_id)->toBe(9)
        ->and($row->group_key)->toBe('5:task:9')
        ->and($row->data['count'])->toBe(1)
        ->and($row->data['activity_ids'])->toBe([1]);
});

test('a second event on the same record by the same actor within five minutes merges', function () {
    $user = User::factory()->create();

    $user->notify(new AppNotification(taskPayload(1)));
    $this->travel(4)->minutes();
    $user->notify(new AppNotification(taskPayload(2, NotificationPriority::Important, 'notifications.events.task.assigned')));

    $row = UserNotification::query()->sole();

    expect($row->data['count'])->toBe(2)
        ->and($row->data['activity_ids'])->toBe([1, 2])
        ->and($row->data['key'])->toBe('notifications.events.task.assigned')
        ->and($row->priority)->toBe(NotificationPriority::Important);
});

test('a louder earlier event keeps its sentence when a quieter one merges in', function () {
    $user = User::factory()->create();

    $user->notify(new AppNotification(taskPayload(1, NotificationPriority::Important, 'notifications.events.task.assigned')));
    $user->notify(new AppNotification(taskPayload(2)));

    expect(UserNotification::query()->sole()->data['key'])->toBe('notifications.events.task.assigned');
});

test('events more than five minutes apart are separate notifications', function () {
    $user = User::factory()->create();

    $user->notify(new AppNotification(taskPayload(1)));
    $this->travel(6)->minutes();
    $user->notify(new AppNotification(taskPayload(2)));

    expect(UserNotification::query()->count())->toBe(2);
});

test('an event never merges into a notification that was already read', function () {
    $user = User::factory()->create();

    $user->notify(new AppNotification(taskPayload(1)));
    UserNotification::query()->sole()->markAsRead();
    $user->notify(new AppNotification(taskPayload(2)));

    expect(UserNotification::query()->count())->toBe(2);
});

test('another actor on the same record starts its own notification', function () {
    $user = User::factory()->create();

    $user->notify(new AppNotification(taskPayload(1, groupKey: '5:task:9')));
    $user->notify(new AppNotification(taskPayload(2, groupKey: '6:task:9')));

    expect(UserNotification::query()->count())->toBe(2);
});

test('grouping is per recipient', function () {
    [$first, $second] = User::factory()->count(2)->create();

    $first->notify(new AppNotification(taskPayload(1)));
    $second->notify(new AppNotification(taskPayload(1)));

    expect(UserNotification::query()->count())->toBe(2);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Notifications/NotificationPayload.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;

/**
 * Everything one notification needs, independent of who receives it.
 *
 * `key` + `params` are rendered in the reader's language later; params are
 * plain strings or typed arrays understood by NotificationPresenter.
 */
final readonly class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array{route?: string, parameters?: array<string, mixed>}  $link  used when there is no subject page
     * @param  list<array<string, mixed>>  $actions  quick actions (Phase 5)
     */
    public function __construct(
        public NotificationKind $kind,
        public string $module,
        public NotificationPriority $priority,
        public string $key,
        public array $params,
        public ?string $subjectType,
        public ?int $subjectId,
        public ?int $actorId = null,
        public ?int $activityId = null,
        public ?string $groupKey = null,
        public array $link = [],
        public array $actions = [],
    ) {}
}
```

`app/Notifications/NotificationGrouper.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationPriority;
use App\Models\User;
use App\Models\UserNotification;

/**
 * Merge-or-create: an unread notification for the same actor and record,
 * touched within the grouping window, absorbs the new event instead of a new
 * row appearing. The loudest event's sentence stays the headline.
 */
final class NotificationGrouper
{
    /**
     * @return array{0: UserNotification, 1: bool} the row, and whether it was merged
     */
    public function store(User $user, NotificationPayload $payload, string $id): array
    {
        $existing = $payload->groupKey === null ? null : UserNotification::query()
            ->forUser($user)
            ->where('group_key', $payload->groupKey)
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subMinutes((int) config('notifications.grouping_window_minutes')))
            ->latest()
            ->first();

        if ($existing !== null) {
            return [$this->merge($existing, $payload), true];
        }

        return [UserNotification::query()->create([
            'id' => $id,
            'type' => AppNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => [
                'key' => $payload->key,
                'params' => $payload->params,
                'count' => 1,
                'activity_ids' => $payload->activityId === null ? [] : [$payload->activityId],
                'link' => $payload->link,
                'actions' => $payload->actions,
            ],
            'priority' => $payload->priority,
            'module' => $payload->module,
            'kind' => $payload->kind,
            'subject_type' => $payload->subjectType,
            'subject_id' => $payload->subjectId,
            'actor_id' => $payload->actorId,
            'activity_id' => $payload->activityId,
            'group_key' => $payload->groupKey,
        ]), false];
    }

    private function merge(UserNotification $existing, NotificationPayload $payload): UserNotification
    {
        $data = $existing->data;

        if ($payload->priority->rank() >= $existing->priority->rank()) {
            $data['key'] = $payload->key;
            $data['params'] = $payload->params;
            $data['actions'] = $payload->actions;
        }

        $data['count'] = (int) ($data['count'] ?? 1) + 1;
        $data['activity_ids'] = [...($data['activity_ids'] ?? []), ...($payload->activityId === null ? [] : [$payload->activityId])];

        $existing->forceFill([
            'data' => $data,
            'priority' => NotificationPriority::highest($existing->priority, $payload->priority),
            'activity_id' => $payload->activityId ?? $existing->activity_id,
            'created_at' => now(),
        ])->save();

        return $existing;
    }
}
```

`app/Notifications/AppDatabaseChannel.php` (Phase 2 adds count invalidation + broadcast):
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Models\UserNotification;

/**
 * Stores an AppNotification in the inbox, merging it into a recent unread
 * row when the grouper says so.
 */
final class AppDatabaseChannel
{
    public function __construct(private NotificationGrouper $grouper) {}

    public function send(User $notifiable, AppNotification $notification): UserNotification
    {
        [$row] = $this->grouper->store($notifiable, $notification->payload, $notification->id);

        return $row;
    }
}
```

`app/Notifications/AppNotification.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * The single notification class of this app. It is sent synchronously from
 * already-queued work (fan-out, scanners), so it does not queue itself.
 */
final class AppNotification extends Notification
{
    public function __construct(public NotificationPayload $payload) {}

    /**
     * @return list<class-string>
     */
    public function via(User $notifiable): array
    {
        return [AppDatabaseChannel::class];
    }
}
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/NotificationGroupingTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications tests/Feature/Notifications/NotificationGroupingTest.php`

### Task 1.5: Sentence rendering and translation keys

**Files:**
- Create: `app/Notifications/ActivityParameters.php`, `app/Notifications/NotificationPresenter.php`, `lang/en/notifications.php`, `lang/ar/notifications.php`
- Test: `tests/Feature/Notifications/NotificationPresenterTest.php`

**Interfaces — Produces:**
- Typed params: `string` | `['trans' => key]` | `['enum' => snake_enum, 'value' => raw]` | `['date' => 'Y-m-d']` | `['localized' => ['en' => ..., 'ar' => ...]]` | `['weekday' => 'Y-m-d']`.
- `ActivityParameters::for(Activity $activity): array<string, mixed>` — `actor`, `subject`, `from`/`to` (for the field named in `properties.field`), plus `employee`, `subtask`, `file`, `note` when present.
- `NotificationPresenter::sentence(array $data, ?string $locale = null): string`, `::moduleLabel(string $module, ?string $locale = null): string`, `::param(mixed $param, string $locale): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Notifications\ActivityParameters;
use App\Notifications\NotificationPresenter;

test('a status change renders with translated statuses in english and arabic', function () {
    $data = [
        'key' => 'notifications.events.task.status_changed',
        'params' => [
            'actor' => 'Sami Haddad',
            'subject' => 'Distribution plan',
            'from' => ['enum' => 'task_status', 'value' => 'todo'],
            'to' => ['enum' => 'task_status', 'value' => 'in progress'],
        ],
        'count' => 1,
    ];

    $presenter = app(NotificationPresenter::class);

    expect($presenter->sentence($data, 'en'))->toBe('Sami Haddad moved “Distribution plan” from To do to In progress')
        ->and($presenter->sentence($data, 'ar'))->toContain('نقل')->toContain(__('enums.task_status.in_progress', [], 'ar'));
});

test('localized names follow the reader language', function () {
    $data = [
        'key' => 'notifications.events.task.assigned',
        'params' => ['actor' => 'Sami', 'subject' => 'Plan', 'employee' => ['localized' => ['en' => 'Nour Ali', 'ar' => 'نور علي']]],
        'count' => 1,
    ];

    expect(app(NotificationPresenter::class)->sentence($data, 'ar'))->toContain('نور علي')
        ->and(app(NotificationPresenter::class)->sentence($data, 'en'))->toContain('Nour Ali');
});

test('a merged notification says how many more updates it holds', function () {
    $data = ['key' => 'notifications.events.task.updated', 'params' => ['actor' => 'Sami', 'subject' => 'Plan'], 'count' => 3];

    expect(app(NotificationPresenter::class)->sentence($data, 'en'))->toEndWith('and 2 more updates');
});

test('activity parameters carry the actor, the label and the changed field typed by its cast', function () {
    $actor = User::factory()->create(['name' => 'Sami Haddad']);
    $task = Activity::withoutRecording(fn () => Task::factory()->create(['title' => 'Plan']));
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id, 'module' => 'tasks',
        'event' => 'task.due_date_changed',
        'changes' => ['due_date' => ['2026-09-01 00:00:00', '2026-09-30 00:00:00']],
        'properties' => ['label' => 'Plan', 'notify' => true, 'field' => 'due_date'],
    ]));

    expect(app(ActivityParameters::class)->for($activity))->toBe([
        'actor' => 'Sami Haddad',
        'subject' => 'Plan',
        'from' => ['date' => '2026-09-01'],
        'to' => ['date' => '2026-09-30'],
    ]);
});

test('a system activity names the system as its actor', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create());
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => null, 'subject_type' => 'task', 'subject_id' => $task->id, 'module' => 'tasks',
        'event' => 'task.created', 'changes' => [], 'properties' => ['label' => 'Plan', 'notify' => true],
    ]));

    expect(app(ActivityParameters::class)->for($activity)['actor'])->toBe(['trans' => 'notifications.system']);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Notifications/ActivityParameters.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Activity;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Builds the typed sentence params for an activity. Nothing is translated
 * here: enum values, dates and names stay typed so they render in whatever
 * language the reader uses.
 */
final class ActivityParameters
{
    /**
     * Properties copied through verbatim when present.
     */
    private const PassThrough = ['subtask', 'file', 'note', 'role'];

    /**
     * @return array<string, mixed>
     */
    public function for(Activity $activity): array
    {
        $actorName = $activity->relationLoaded('actor')
            ? $activity->getRelation('actor')?->name
            : $activity->actor()->value('name');

        $params = [
            'actor' => $actorName ?? ['trans' => 'notifications.system'],
            'subject' => $activity->label(),
        ];

        $field = $activity->properties['field'] ?? null;

        if (is_string($field) && array_key_exists($field, $activity->changes)) {
            [$old, $new] = $activity->changes[$field];
            $params['from'] = $this->typed($activity, $field, $old, 0);
            $params['to'] = $this->typed($activity, $field, $new, 1);
        }

        if (isset($activity->properties['employee'])) {
            $params['employee'] = ['localized' => $activity->properties['employee']];
        }

        foreach (self::PassThrough as $key) {
            if (isset($activity->properties[$key])) {
                $params[$key] = (string) $activity->properties[$key];
            }
        }

        return $params;
    }

    /**
     * One side of a change, typed by the subject model's cast.
     */
    private function typed(Activity $activity, string $field, mixed $value, int $side): mixed
    {
        if ($value === null || $value === '') {
            return ['trans' => 'notifications.none'];
        }

        $name = $activity->properties['names'][$field][$side] ?? null;

        if (is_array($name)) {
            return ['localized' => $name];
        }

        $cast = $this->castFor($activity->subject_type, $field);

        if ($cast !== null && enum_exists($cast) && is_subclass_of($cast, BackedEnum::class)) {
            return ['enum' => Str::snake(class_basename($cast)), 'value' => (string) $value];
        }

        if ($cast !== null && str_contains($cast, 'date')) {
            return ['date' => substr((string) $value, 0, 10)];
        }

        return (string) $value;
    }

    private function castFor(string $subjectType, string $field): ?string
    {
        $class = Relation::getMorphedModel($subjectType);

        if ($class === null) {
            return null;
        }

        /** @var Model $model */
        $model = new $class;

        return $model->getCasts()[$field] ?? null;
    }
}
```

`app/Notifications/NotificationPresenter.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Renders stored notification data in a given language.
 */
final class NotificationPresenter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function sentence(array $data, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $params = [];

        foreach ($data['params'] ?? [] as $name => $param) {
            $params[$name] = $this->param($param, $locale);
        }

        $sentence = (string) __((string) ($data['key'] ?? ''), $params, $locale);
        $more = (int) ($data['count'] ?? 1) - 1;

        if ($more > 0) {
            $sentence .= ' '.trans_choice('notifications.more_updates', $more, ['count' => $more], $locale);
        }

        return $sentence;
    }

    public function moduleLabel(string $module, ?string $locale = null): string
    {
        return (string) __("notifications.modules.{$module}", [], $locale ?? app()->getLocale());
    }

    /**
     * One typed param as display text.
     */
    public function param(mixed $param, string $locale): string
    {
        if (! is_array($param)) {
            return (string) $param;
        }

        return match (true) {
            isset($param['trans']) => (string) __($param['trans'], [], $locale),
            isset($param['enum']) => (string) __('enums.'.$param['enum'].'.'.$this->enumKey((string) $param['value']), [], $locale),
            isset($param['date']) => CarbonImmutable::parse($param['date'])->locale($locale)->translatedFormat((string) __('formats.date', [], $locale)),
            isset($param['weekday']) => CarbonImmutable::parse($param['weekday'])->locale($locale)->translatedFormat('l'),
            isset($param['localized']) => $this->localized($param['localized'], $locale),
            default => '',
        };
    }

    /**
     * @param  array{en?: string, ar?: string}  $names
     */
    private function localized(array $names, string $locale): string
    {
        $preferred = $names[$locale] ?? '';

        return $preferred !== '' ? $preferred : (string) ($names['en'] ?? '');
    }

    /**
     * Same rule as HasTranslatedLabel::labelKey(): the lang key segment for a
     * stored enum value.
     */
    private function enumKey(string $value): string
    {
        return Str::lower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value));
    }
}
```

`lang/en/notifications.php`:
```php
<?php

return [
    'system' => 'The system',
    'none' => 'none',
    'more_updates' => '{0} |{1} and 1 more update|[2,*] and :count more updates',

    'modules' => [
        'tasks' => 'Tasks',
        'milestones' => 'Milestones',
        'projects' => 'Projects',
        'programs' => 'Programs',
        'annual-plans' => 'Annual plans',
        'strategies' => 'Strategies',
        'risks' => 'Risks',
        'indicators' => 'Indicators',
        'targets' => 'Targets',
        'results' => 'Results',
        'reports' => 'Reports',
        'donors' => 'Donors',
        'proposals' => 'Proposals',
        'funding-agreements' => 'Funding agreements',
        'transfers' => 'Transfers',
        'accounts' => 'Accounts',
        'access-management' => 'Users & roles',
        'system' => 'System',
    ],

    'events' => [
        'task' => [
            'created' => ':actor created the task “:subject”',
            'assigned' => ':actor assigned :employee to “:subject”',
            'assigned_you' => ':actor assigned you to “:subject”',
            'unassigned' => ':actor removed :employee from “:subject”',
            'status_changed' => ':actor moved “:subject” from :from to :to',
            'due_date_changed' => ':actor changed the due date of “:subject” to :to',
            'priority_changed' => ':actor changed the priority of “:subject” to :to',
            'updated' => ':actor updated the task “:subject”',
            'attachment_added' => ':actor attached :file to “:subject”',
            'subtask_added' => ':actor added the subtask “:subtask” to “:subject”',
            'subtask_completed' => ':actor completed the subtask “:subtask” of “:subject”',
            'deleted' => ':actor deleted the task “:subject”',
        ],
    ],
];
```

`lang/ar/notifications.php`:
```php
<?php

return [
    'system' => 'النظام',
    'none' => 'لا شيء',
    'more_updates' => '{0} |{1} وتحديث آخر|{2} وتحديثان آخران|[3,10] و:count تحديثات أخرى|[11,*] و:count تحديثًا آخر',

    'modules' => [
        'tasks' => 'المهام',
        'milestones' => 'المراحل',
        'projects' => 'المشاريع',
        'programs' => 'البرامج',
        'annual-plans' => 'الخطط السنوية',
        'strategies' => 'الاستراتيجيات',
        'risks' => 'المخاطر',
        'indicators' => 'المؤشرات',
        'targets' => 'المستهدفات',
        'results' => 'النتائج',
        'reports' => 'التقارير',
        'donors' => 'المانحون',
        'proposals' => 'المقترحات',
        'funding-agreements' => 'اتفاقيات التمويل',
        'transfers' => 'التحويلات',
        'accounts' => 'الحسابات',
        'access-management' => 'المستخدمون والأدوار',
        'system' => 'النظام',
    ],

    'events' => [
        'task' => [
            'created' => 'أنشأ :actor المهمة «:subject»',
            'assigned' => 'أسند :actor المهمة «:subject» إلى :employee',
            'assigned_you' => 'أسند إليك :actor المهمة «:subject»',
            'unassigned' => 'أزال :actor :employee من المهمة «:subject»',
            'status_changed' => 'نقل :actor المهمة «:subject» من :from إلى :to',
            'due_date_changed' => 'غيّر :actor تاريخ استحقاق المهمة «:subject» إلى :to',
            'priority_changed' => 'غيّر :actor أولوية المهمة «:subject» إلى :to',
            'updated' => 'حدّث :actor المهمة «:subject»',
            'attachment_added' => 'أرفق :actor الملف :file بالمهمة «:subject»',
            'subtask_added' => 'أضاف :actor المهمة الفرعية «:subtask» إلى «:subject»',
            'subtask_completed' => 'أنجز :actor المهمة الفرعية «:subtask» ضمن «:subject»',
            'deleted' => 'حذف :actor المهمة «:subject»',
        ],
    ],
];
```

(Check the English expectation in the first test against the real `enums.task_status.todo` / `in_progress` labels in `lang/en/enums.php` and adjust only the test string if the labels differ.)

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/NotificationPresenterTest.php tests/Feature/LocalizationTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications lang/en/notifications.php lang/ar/notifications.php tests/Feature/Notifications/NotificationPresenterTest.php`

### Task 1.6: Fan-out job

**Files:**
- Create: `app/Jobs/FanOutActivity.php` (via `php artisan make:job FanOutActivity --no-interaction`); Modify: `app/Notifications/NotificationPayload.php` (add `fromActivity`)
- Test: `tests/Feature/Notifications/FanOutActivityTest.php`

**Interfaces — Produces:** `FanOutActivity(int $activityId)` (`$tries = 3`, `$backoff = [10, 60, 300]`), `handle(RecipientResolver, ActivityParameters): void`; `NotificationPayload::fromActivity(Activity $activity, NotificationPriority $priority, array $params, bool $assignedToRecipient): self` — key `notifications.events.{event}`, suffixed `_you` when the recipient is the one assigned and the key exists; group key `{actor_id|system}:{subject_type}:{subject_id}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationPriority;
use App\Jobs\FanOutActivity;
use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

test('fan-out notifies each recipient once with the assigned wording for the assignee', function () {
    $employee = insertHrEmployee();
    $assignee = User::factory()->role('Managers')->create(['employee_id' => $employee]);
    $actor = User::factory()->role('Managers')->create(['name' => 'Sami']);
    $task = Activity::withoutRecording(function () use ($employee, $actor) {
        $task = Task::factory()->create(['created_by' => $actor->id, 'title' => 'Plan']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $employee]);

        return $task;
    });
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => $actor->id, 'subject_type' => 'task', 'subject_id' => $task->id, 'module' => 'tasks',
        'event' => 'task.assigned', 'changes' => [],
        'properties' => ['label' => 'Plan', 'notify' => true, 'employee_id' => $employee, 'employee' => ['en' => 'Sami Haddad', 'ar' => 'سامي حداد']],
    ]));

    dispatch_sync(new FanOutActivity($activity->id));
    dispatch_sync(new FanOutActivity($activity->id)); // a retry must not duplicate

    $row = UserNotification::query()->forUser($assignee)->sole();

    expect($row->data['key'])->toBe('notifications.events.task.assigned_you')
        ->and($row->priority)->toBe(NotificationPriority::Important)
        ->and($row->activity_id)->toBe($activity->id)
        ->and($row->actor_id)->toBe($actor->id)
        ->and($row->group_key)->toBe($actor->id.':task:'.$task->id);
});

test('fan-out skips activities that are not meaningful', function () {
    Notification::fake();
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => null, 'subject_type' => 'task', 'subject_id' => 1, 'module' => 'tasks',
        'event' => 'task.updated', 'changes' => ['description' => ['a', 'b']], 'properties' => ['label' => 'x', 'notify' => false],
    ]));

    dispatch_sync(new FanOutActivity($activity->id));

    Notification::assertNothingSent();
});

test('fan-out sends the app notification through the notification system', function () {
    Notification::fake();
    $employee = insertHrEmployee();
    $assignee = User::factory()->role('Managers')->create(['employee_id' => $employee]);
    $task = Activity::withoutRecording(function () use ($employee) {
        $task = Task::factory()->create();
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $employee]);

        return $task;
    });
    $activity = Activity::withoutRecording(fn () => Activity::query()->create([
        'actor_id' => null, 'subject_type' => 'task', 'subject_id' => $task->id, 'module' => 'tasks',
        'event' => 'task.created', 'changes' => [], 'properties' => ['label' => 'x', 'notify' => true],
    ]));

    dispatch_sync(new FanOutActivity($activity->id));

    Notification::assertSentTo($assignee, AppNotification::class, fn (AppNotification $n) => $n->payload->key === 'notifications.events.task.created');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

Add to `NotificationPayload`:
```php
    /**
     * The payload for one recipient of an action activity.
     *
     * @param  array<string, mixed>  $params
     */
    public static function fromActivity(Activity $activity, NotificationPriority $priority, array $params, bool $assignedToRecipient = false): self
    {
        $key = 'notifications.events.'.$activity->event;

        if ($assignedToRecipient && Lang::has($key.'_you')) {
            $key .= '_you';
        }

        return new self(
            kind: NotificationKind::Action,
            module: $activity->module,
            priority: $priority,
            key: $key,
            params: $params,
            subjectType: $activity->subject_type,
            subjectId: $activity->subject_id,
            actorId: $activity->actor_id,
            activityId: $activity->id,
            groupKey: ($activity->actor_id ?? 'system').':'.$activity->subject_type.':'.$activity->subject_id,
        );
    }
```
(imports: `App\Models\Activity`, `Illuminate\Support\Facades\Lang`)

`app/Jobs/FanOutActivity.php`:
```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\ActivityParameters;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Turns one activity into notifications. Idempotent: a recipient whose inbox
 * already holds this activity (directly or merged) is skipped, so a retry
 * never doubles anything.
 */
final class FanOutActivity implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $activityId) {}

    public function handle(RecipientResolver $resolver, ActivityParameters $parameters): void
    {
        $activity = Activity::query()->with('actor:id,name')->find($this->activityId);

        if ($activity === null || ! $activity->shouldNotify()) {
            return;
        }

        $recipients = $resolver->resolve($activity);

        if ($recipients->priorities === []) {
            return;
        }

        $params = $parameters->for($activity);

        User::query()->whereIn('id', array_keys($recipients->priorities))->get()->each(
            function (User $user) use ($activity, $recipients, $params): void {
                $delivered = UserNotification::query()
                    ->forUser($user)
                    ->whereJsonContains('data->activity_ids', $activity->id)
                    ->exists();

                if ($delivered) {
                    return;
                }

                $user->notify(new AppNotification(NotificationPayload::fromActivity(
                    $activity,
                    $recipients->priorities[$user->id],
                    $params,
                    in_array($user->id, $recipients->assignedUserIds, true),
                )));
            },
        );
    }
}
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/FanOutActivityTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Jobs app/Notifications/NotificationPayload.php tests/Feature/Notifications/FanOutActivityTest.php`

### Task 1.7: Recording traits and the Tasks pilot

**Files:**
- Create: `app/Notifications/TracksActivity.php`, `app/Notifications/ActivityRecorder.php`, `app/Concerns/RecordsActivity.php`, `app/Concerns/RecordsParentActivity.php`
- Modify: `app/Models/Task.php`, `app/Models/TaskAssignee.php`, `app/Models/TaskAttachment.php`
- Test: `tests/Feature/Notifications/TaskActivityTest.php`

**Interfaces — Produces:**
- `TracksActivity` interface: `activityModule(): string`, `activityProgramId(): ?int`, `activityLabel(): string`, `activityEmployeeFields(): array`, `recordActivity(string $event, array $changes = [], array $properties = []): ?Activity`.
- `ActivityRecorder::record(Model&TracksActivity $subject, string $event, array $changes = [], array $properties = [], bool $notify = true): ?Activity` — returns null while recording is off; dispatches `FanOutActivity` `afterCommit()` when `$notify`.
- `RecordsActivity` (subjects): abstract `activityEvents(): array<string,string>`; overridable `activityEventForChange(string $field, mixed $old, mixed $new): ?string`, `activityProperties(string $event): array`, `activityPropertiesBeforeDelete(): array`, `activityCreatedEvent(): string`, `activityRecordsUpdates(): bool`, `activityEmployeeFields(): array`; helper `activityAttribute(string $key): mixed`.
- `RecordsParentActivity` (children): abstract `activityParent(): (Model&TracksActivity)|null`, `parentActivityEvent(string $hook): ?string` (hooks `created`, `deleted`); overridable `parentActivityProperties(string $hook): array`.
- Task events: `task.created|updated|status_changed|due_date_changed|priority_changed|deleted|assigned|unassigned|attachment_added|subtask_added|subtask_completed`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\TaskStatus;
use App\Jobs\FanOutActivity;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

test('creating a task records who created it, in which program', function () {
    $actor = User::factory()->create();
    $program = Program::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => Project::factory()->create(['program_id' => $program->id])->id]);

    $this->actingAs($actor);
    $task = Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'title' => 'Plan']);

    $activity = Activity::query()->where('event', 'task.created')->sole();

    expect($activity->actor_id)->toBe($actor->id)
        ->and($activity->subject_type)->toBe('task')
        ->and($activity->subject_id)->toBe($task->id)
        ->and($activity->program_id)->toBe($program->id)
        ->and($activity->module)->toBe('tasks')
        ->and($activity->label())->toBe('Plan')
        ->and($activity->shouldNotify())->toBeTrue();
});

test('a status change is recorded with old and new values', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create());

    $task->update(['status' => TaskStatus::InProgress]);

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('task.status_changed')
        ->and($activity->changes['status'])->toBe(['todo', 'in progress'])
        ->and($activity->properties['field'])->toBe('status');
});

test('a change to a field that is not meaningful is logged but does not notify', function () {
    Queue::fake();
    $task = Activity::withoutRecording(fn () => Task::factory()->create());

    $task->update(['description' => 'More detail']);

    $activity = Activity::query()->sole();

    expect($activity->event)->toBe('task.updated')->and($activity->shouldNotify())->toBeFalse();
    Queue::assertNotPushed(FanOutActivity::class);
});

test('a meaningful activity queues its fan-out', function () {
    Queue::fake();

    Task::factory()->create();

    Queue::assertPushed(FanOutActivity::class);
});

test('assigning and unassigning are recorded on the task', function () {
    $employee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali');
    $task = Activity::withoutRecording(fn () => Task::factory()->create());

    $assignee = $task->taskAssignees()->create(['employee_id' => $employee]);
    $assignee->delete();

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['task.assigned', 'task.unassigned'])
        ->and(Activity::query()->first()->properties['employee'])->toBe(['en' => 'Nour Ali', 'ar' => 'سامي حداد']);
});

test('deleting a task remembers who it named', function () {
    $employee = insertHrEmployee();
    $task = Activity::withoutRecording(function () use ($employee) {
        $task = Task::factory()->create();
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $employee]);

        return $task;
    });

    $task->delete();

    $activity = Activity::query()->where('event', 'task.deleted')->sole();

    expect($activity->properties['employee_ids'])->toBe([$employee])
        ->and($activity->properties['creator_id'])->toBe($task->created_by);
});

test('attachments and subtasks are recorded on their parent task', function () {
    $parent = Activity::withoutRecording(fn () => Task::factory()->create());

    TaskAttachment::factory()->create(['task_id' => $parent->id]);
    $subtask = Activity::withoutRecording(fn () => Task::factory()->create(['parent_task_id' => $parent->id]));
    Task::factory()->create(['parent_task_id' => $parent->id, 'title' => 'Buy tanks']);
    $subtask->update(['status' => TaskStatus::Done]);

    $onParent = Activity::query()->where('subject_id', $parent->id)->orderBy('id')->pluck('event')->all();

    expect($onParent)->toBe(['task.attachment_added', 'task.subtask_added', 'task.subtask_completed']);
});

test('nothing is recorded while recording is off', function () {
    Activity::withoutRecording(function (): void {
        $task = Task::factory()->create();
        $task->update(['status' => TaskStatus::Done]);
        $task->delete();
    });

    expect(Activity::query()->count())->toBe(0);
});

test('completing a task from the task list notifies its assignee but not the actor', function () {
    $employee = insertHrEmployee();
    $assignee = User::factory()->role('Managers')->create(['employee_id' => $employee]);
    $actor = User::factory()->role('Managers')->create(['name' => 'Sami']);
    $task = Activity::withoutRecording(function () use ($employee, $actor) {
        $task = Task::factory()->create(['created_by' => $actor->id, 'title' => 'Plan']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $employee]);

        return $task;
    });

    Livewire::actingAs($actor)->test('pages::tasks.index')->call('toggleComplete', $task->id);

    expect(UserNotification::query()->forUser($actor)->count())->toBe(0);

    $row = UserNotification::query()->forUser($assignee)->sole();

    expect(app(\App\Notifications\NotificationPresenter::class)->sentence($row->data, 'en'))
        ->toBe('Sami moved “Plan” from '.__('enums.task_status.todo').' to '.__('enums.task_status.done'));
});
```

(The Arabic name in the assign test is `'سامي حداد'` because `insertHrEmployee()` defaults `firstNameAr/lastNameAr` to سامي/حداد.)

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Notifications/TracksActivity.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Activity;

/**
 * A model that activity is recorded against (a "subject").
 */
interface TracksActivity
{
    /**
     * The access-control module, e.g. "tasks".
     */
    public function activityModule(): string;

    /**
     * The program this record rolls up to, for the manager cascade.
     */
    public function activityProgramId(): ?int;

    /**
     * The record's display name for sentences.
     */
    public function activityLabel(): string;

    /**
     * Fields holding HR employee ids, recorded with both-language names.
     *
     * @return list<string>
     */
    public function activityEmployeeFields(): array;

    /**
     * Record an activity no model event can see.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, mixed>  $properties
     */
    public function recordActivity(string $event, array $changes = [], array $properties = []): ?Activity;
}
```

`app/Notifications/ActivityRecorder.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Jobs\FanOutActivity;
use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes one Activity and, when it is meaningful, queues its fan-out after
 * the surrounding transaction commits (so recipients read committed rows and
 * a rolled-back save notifies nobody).
 */
final class ActivityRecorder
{
    public function __construct(private EmployeeRecipients $employees) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, mixed>  $properties
     */
    public function record(Model&TracksActivity $subject, string $event, array $changes = [], array $properties = [], bool $notify = true): ?Activity
    {
        if (! Activity::isRecording()) {
            return null;
        }

        $activity = Activity::query()->create([
            'actor_id' => Auth::id(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'program_id' => $subject->activityProgramId(),
            'module' => $subject->activityModule(),
            'event' => $event,
            'changes' => $changes,
            'properties' => [
                'label' => $subject->activityLabel(),
                'notify' => $notify,
                ...$this->employeeNames($subject, $changes),
                ...$properties,
            ],
        ]);

        if ($notify) {
            FanOutActivity::dispatch($activity->id)->afterCommit();
        }

        return $activity;
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @return array{names?: array<string, array{0: array{en: string, ar: string}|null, 1: array{en: string, ar: string}|null}>}
     */
    private function employeeNames(TracksActivity $subject, array $changes): array
    {
        $fields = array_values(array_intersect($subject->activityEmployeeFields(), array_keys($changes)));

        if ($fields === []) {
            return [];
        }

        $ids = array_filter(array_merge(...array_map(fn (string $field): array => $changes[$field], $fields)));
        $names = $this->employees->names(array_map('intval', $ids));
        $result = [];

        foreach ($fields as $field) {
            [$old, $new] = $changes[$field];
            $result[$field] = [$names[(int) $old] ?? null, $names[(int) $new] ?? null];
        }

        return ['names' => $result];
    }
}
```

`app/Concerns/RecordsActivity.php`:
```php
<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Activity;
use App\Notifications\ActivityRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * Records one Activity per created / meaningful update / deleted event of a
 * subject model (see App\Notifications\TracksActivity).
 *
 * activityEvents() lists the meaningful fields in priority order, each with
 * the event suffix a change to it raises; the first changed one names the
 * activity. Changes to any other field are still logged, as "{type}.updated",
 * but notify nobody.
 */
trait RecordsActivity
{
    /**
     * Properties captured while the row still existed, for the deleted event.
     *
     * @var array<string, mixed>
     */
    protected array $activityDeletionProperties = [];

    public static function bootRecordsActivity(): void
    {
        static::created(fn (Model $model) => $model->recordLifecycleActivity('created'));
        static::updated(fn (Model $model) => $model->recordLifecycleActivity('updated'));
        static::deleting(function (Model $model): void {
            $model->activityDeletionProperties = $model->activityPropertiesBeforeDelete();
        });
        static::deleted(fn (Model $model) => $model->recordLifecycleActivity('deleted'));
    }

    /**
     * Meaningful field => event suffix, in priority order.
     *
     * @return array<string, string>
     */
    abstract public function activityEvents(): array;

    /**
     * The event suffix a change to a meaningful field raises. Override to split
     * one field into several events; null skips the field.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        return $this->activityEvents()[$field] ?? null;
    }

    /**
     * Extra properties for an event (sentence params, snapshots).
     *
     * @return array<string, mixed>
     */
    protected function activityProperties(string $event): array
    {
        return [];
    }

    /**
     * Snapshot taken while the row still exists, stored on the deleted event.
     *
     * @return array<string, mixed>
     */
    protected function activityPropertiesBeforeDelete(): array
    {
        return [];
    }

    protected function activityCreatedEvent(): string
    {
        return $this->getMorphClass().'.created';
    }

    /**
     * Whether updates are logged at all (false for noisy bookkeeping models).
     */
    protected function activityRecordsUpdates(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function activityEmployeeFields(): array
    {
        return [];
    }

    public function recordActivity(string $event, array $changes = [], array $properties = []): ?Activity
    {
        return app(ActivityRecorder::class)->record($this, $event, $changes, $properties);
    }

    /**
     * Read an attribute without tripping strict mode when a partial select
     * left it out.
     */
    protected function activityAttribute(string $key): mixed
    {
        if (array_key_exists($key, $this->getAttributes())) {
            return $this->getAttribute($key);
        }

        return $this->getKey() === null ? null : $this->newQueryWithoutScopes()->whereKey($this->getKey())->value($key);
    }

    protected function recordLifecycleActivity(string $hook): void
    {
        if (! Activity::isRecording()) {
            return;
        }

        $recorder = app(ActivityRecorder::class);

        if ($hook === 'created') {
            $event = $this->activityCreatedEvent();
            $recorder->record($this, $event, properties: $this->activityProperties($event));

            return;
        }

        if ($hook === 'deleted') {
            $event = $this->getMorphClass().'.deleted';
            $recorder->record($this, $event, properties: [...$this->activityDeletionProperties, ...$this->activityProperties($event)]);

            return;
        }

        if (! $this->activityRecordsUpdates()) {
            return;
        }

        $changes = $this->activityChanges();

        if ($changes === []) {
            return;
        }

        [$field, $suffix] = [null, null];

        foreach (array_keys($this->activityEvents()) as $candidate) {
            if (! array_key_exists($candidate, $changes)) {
                continue;
            }

            $suffix = $this->activityEventForChange($candidate, $changes[$candidate][0], $changes[$candidate][1]);

            if ($suffix !== null) {
                $field = $candidate;

                break;
            }
        }

        $event = $this->getMorphClass().'.'.($suffix ?? 'updated');

        $recorder->record($this, $event, $changes, ['field' => $field, ...$this->activityProperties($event)], notify: $suffix !== null);
    }

    /**
     * Raw old/new values of every changed column, minus timestamps and hidden
     * columns (secrets never reach the log).
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected function activityChanges(): array
    {
        $skipped = [$this->getCreatedAtColumn(), $this->getUpdatedAtColumn(), ...$this->getHidden()];
        $changes = [];

        foreach (array_keys($this->getChanges()) as $field) {
            if (in_array($field, $skipped, true)) {
                continue;
            }

            $changes[$field] = [$this->getRawOriginal($field), $this->getAttributes()[$field] ?? null];
        }

        return $changes;
    }
}
```

`app/Concerns/RecordsParentActivity.php`:
```php
<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\Activity;
use App\Notifications\TracksActivity;
use Illuminate\Database\Eloquent\Model;

/**
 * For child rows (assignees, attachments, files, notes, baselines, readings):
 * their creation or deletion is recorded as an activity on the parent record.
 */
trait RecordsParentActivity
{
    public static function bootRecordsParentActivity(): void
    {
        static::created(fn (Model $model) => $model->recordOnParent('created'));
        static::deleted(fn (Model $model) => $model->recordOnParent('deleted'));
    }

    /**
     * The record this row's activity is logged against.
     */
    abstract public function activityParent(): (Model&TracksActivity)|null;

    /**
     * The event a hook raises on the parent, or null for none.
     */
    abstract protected function parentActivityEvent(string $hook): ?string;

    /**
     * @return array<string, mixed>
     */
    protected function parentActivityProperties(string $hook): array
    {
        return [];
    }

    protected function recordOnParent(string $hook): void
    {
        if (! Activity::isRecording()) {
            return;
        }

        $event = $this->parentActivityEvent($hook);
        $parent = $event === null ? null : $this->activityParent();

        $parent?->recordActivity($event, properties: $this->parentActivityProperties($hook));
    }
}
```

`app/Models/Task.php` — `class Task extends Model implements TracksActivity`, `use HasFactory, RecordsActivity;` and add:
```php
    protected static function booted(): void
    {
        static::created(function (Task $task): void {
            $task->activityParentTask()?->recordActivity('task.subtask_added', properties: ['subtask' => $task->activityLabel()]);
        });

        static::updated(function (Task $task): void {
            if ($task->wasChanged('status') && ($task->getAttributes()['status'] ?? null) === TaskStatus::Done->value) {
                $task->activityParentTask()?->recordActivity('task.subtask_completed', properties: ['subtask' => $task->activityLabel()]);
            }
        });
    }

    public function activityModule(): string
    {
        return 'tasks';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('title');
    }

    public function activityEvents(): array
    {
        return [
            'status' => 'status_changed',
            'due_date' => 'due_date_changed',
            'priority' => 'priority_changed',
            'title' => 'updated',
            'start_date' => 'updated',
        ];
    }

    public function activityProgramId(): ?int
    {
        $sourceId = $this->activityAttribute('source_id');

        $programId = match ($this->activityAttribute('source_type')) {
            'program' => $sourceId,
            'project' => Project::query()->whereKey($sourceId)->value('program_id'),
            'milestone' => Milestone::query()
                ->join('projects', 'projects.id', '=', 'milestones.project_id')
                ->where('milestones.id', $sourceId)
                ->value('projects.program_id'),
            default => null,
        };

        return $programId === null ? null : (int) $programId;
    }

    protected function activityPropertiesBeforeDelete(): array
    {
        return [
            'employee_ids' => $this->taskAssignees()->pluck('employee_id')->map(fn (int|string $id): int => (int) $id)->all(),
            'creator_id' => $this->activityAttribute('created_by'),
        ];
    }

    /**
     * The parent task, when this is a subtask.
     */
    private function activityParentTask(): ?Task
    {
        $parentId = $this->activityAttribute('parent_task_id');

        return $parentId === null ? null : Task::query()->find($parentId);
    }
```
(imports: `App\Concerns\RecordsActivity`, `App\Notifications\TracksActivity`)

`app/Models/TaskAssignee.php` — `use HasFactory, RecordsParentActivity;` and:
```php
    public function activityParent(): ?Task
    {
        return $this->task()->first();
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'task.assigned' : 'task.unassigned';
    }

    protected function parentActivityProperties(string $hook): array
    {
        $employeeId = (int) $this->employee_id;

        return [
            'employee_id' => $employeeId,
            'employee' => app(EmployeeRecipients::class)->names([$employeeId])[$employeeId] ?? null,
        ];
    }
```

`app/Models/TaskAttachment.php` — `use HasFactory, RecordsParentActivity;` and:
```php
    public function activityParent(): ?Task
    {
        return $this->task()->first();
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'task.attachment_added' : null;
    }

    protected function parentActivityProperties(string $hook): array
    {
        return ['file' => basename((string) $this->path)];
    }
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications` → PASS. Then existing task tests: `php artisan test --compact tests/Feature/TaskTest.php tests/Feature/TaskDetailTest.php tests/Feature/TaskDirectoryTest.php tests/Feature/TaskAttachmentTest.php tests/Feature/CalendarTest.php tests/Feature/DashboardTest.php tests/Feature/Models` → PASS (fix any strict-mode issue at its root, never by disabling strict mode).
- [ ] **Step 5: Stage** — `git add app/Concerns app/Notifications app/Models/Task.php app/Models/TaskAssignee.php app/Models/TaskAttachment.php tests/Feature/Notifications/TaskActivityTest.php`

### Task 1.8: Kill switch in seeders and HR sync

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php`, `app/Models/Program.php` (`syncFromHr`), `app/Models/User.php` (`syncFromHrAccounts`)
- Test: `tests/Feature/Notifications/QuietWritesTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Activity;
use App\Models\Program;
use App\Models\User;
use App\Models\UserNotification;

test('seeding the database records no activity and sends no notifications', function () {
    $this->seed();

    expect(Activity::query()->count())->toBe(0)
        ->and(UserNotification::query()->count())->toBe(0);
});

test('mirroring hr programs records no activity', function () {
    insertHrProgram('Hope Center');

    Program::syncFromHr();

    expect(Activity::query()->count())->toBe(0);
});

test('bulk creating users from hr accounts records no activity', function () {
    insertHrUser(insertHrEmployee(), 'someone@example.org');

    User::syncFromHrAccounts();

    expect(Activity::query()->count())->toBe(0);
});
```

(The last two only become meaningful once Program and User record activity in Phase 3; they are written now so the kill switch is in place before that.)

- [ ] **Step 2: Run** — first test FAILS (factories inside seeders record task activity).
- [ ] **Step 3: Implement** — in `DatabaseSeeder::run()` wrap the existing `$this->call([...])` in `Activity::withoutRecording(function (): void { ... });` and extend its docblock: "Runs with activity recording off: seeded data is history, not news." Wrap the bodies of `Program::syncFromHr()` and `User::syncFromHrAccounts()` the same way (`return Activity::withoutRecording(function () { ...existing body... });`), with a docblock line "The HR mirror is not somebody's action, so it records no activity."
- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/QuietWritesTest.php tests/Feature/DatabaseSeederTest.php tests/Feature/ProgramTest.php tests/Feature/SyncHrUsersCommandTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add database/seeders/DatabaseSeeder.php app/Models/Program.php app/Models/User.php tests/Feature/Notifications/QuietWritesTest.php`

### Phase 1 close-out

- [ ] Run `php artisan test --compact tests/Feature/Notifications` and the task/calendar/dashboard/seeder/model tests above → all PASS.
- [ ] `vendor/bin/pint --dirty --format agent`.
- [ ] Record the durable rules with `record-rule`: (a) glob `app/Models/**` — "Tracked models implement TracksActivity + RecordsActivity (child rows RecordsParentActivity); new morph keys for subjects go in the morph map"; (b) glob `database/seeders/**` — "DatabaseSeeder runs under Activity::withoutRecording; any bulk import/sync must too".
- [ ] Post progress update, continue to Phase 2.

---

# PHASE 2 — Real-time + UI

Delivers: Reverb + Echo, private channel, broadcast on store, unread counts cache, bell + panel, toasts, OS popups (opt-in), polling fallback + focus re-sync, `/notifications` page, read / unread / snooze / wake, "item no longer exists".

Order note: Task 2.2's presenter uses `route('notifications.open')` and Task 2.4's bell uses `route('notification-settings.edit')`. Register **both routes first** (Task 2.3 Step 3 route lines and Task 2.6 Step 3 settings route line) at the start of this phase, pointing at the controller/page files created in those tasks.

### Task 2.1: Install Reverb and Echo, private user channel

**Files:**
- Created by the installer: `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`, `resources/js/echo.js`; modified by it: `bootstrap/app.php` (`channels:`), `resources/js/app.js`, `composer.json/lock`, `package.json/lock`, `.env`
- Modify: `.env.example`, `routes/channels.php`, `resources/views/partials/head.blade.php` (csrf meta)
- Test: `tests/Feature/Notifications/BroadcastChannelTest.php`

- [ ] **Step 1: Install (approved dependencies only)**

Locate Composer, then run it with PHP 8.5 explicitly (the `composer` on PATH may use XAMPP's PHP):
```bash
where composer
```
```bash
/c/php85/php.exe "<path to composer.phar found above>" require laravel/reverb --no-interaction
```
```bash
/c/php85/php.exe artisan install:broadcasting --reverb --without-node --no-interaction
```
```bash
npm install laravel-echo pusher-js
```
Expected: `config/broadcasting.php`, `config/reverb.php`, `routes/channels.php`, `resources/js/echo.js` exist; `resources/js/app.js` imports `./echo`; `bootstrap/app.php` `withRouting(...)` has `channels: __DIR__.'/../routes/channels.php'`; `.env` has `BROADCAST_CONNECTION=reverb` and `REVERB_*` / `VITE_REVERB_*`. If the installer did not run `reverb:install`, run `/c/php85/php.exe artisan reverb:install --no-interaction`. Confirm `git diff composer.json package.json` shows only `laravel/reverb`, `laravel-echo`, `pusher-js`.

- [ ] **Step 2: `.env.example`** — replace `BROADCAST_CONNECTION=log` with `BROADCAST_CONNECTION=reverb` and add (blank placeholders, never real secrets):
```
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```
`phpunit.xml` keeps `BROADCAST_CONNECTION=null`.

- [ ] **Step 3: Write the failing test**

```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

test('only the owner may listen on a user notification channel', function () {
    [$owner, $other] = User::factory()->count(2)->create();

    $callback = Broadcast::driver()->getChannels()->get('App.Models.User.{id}');

    expect($callback($owner, $owner->id))->toBeTrue()
        ->and($callback($other, $owner->id))->toBeFalse();
});
```

- [ ] **Step 4: `routes/channels.php`** (replace the generated body):
```php
<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Each user's inbox is pushed on their own private channel. Nobody else may
| subscribe to it.
*/
Broadcast::channel('App.Models.User.{id}', fn (User $user, int|string $id): bool => $user->id === (int) $id);
```
Add to `resources/views/partials/head.blade.php` (Echo sends it with the channel-auth request): `<meta name="csrf-token" content="{{ csrf_token() }}" />`

- [ ] **Step 5: Run** — `php artisan test --compact tests/Feature/Notifications/BroadcastChannelTest.php` → PASS; `npm run build` → succeeds.
- [ ] **Step 6: Stage** — `git add composer.json composer.lock package.json package-lock.json config/broadcasting.php config/reverb.php routes/channels.php resources/js/echo.js resources/js/app.js bootstrap/app.php .env.example resources/views/partials/head.blade.php tests/Feature/Notifications/BroadcastChannelTest.php` (never `.env`).

### Task 2.2: Unread counts, broadcast event, inbox operations, snooze wake-up

**Files:**
- Create: `app/Notifications/UnreadCounts.php`, `app/Notifications/NotificationBroadcaster.php`, `app/Notifications/Inbox.php`, `app/Events/NotificationPushed.php` (via `php artisan make:event NotificationPushed --no-interaction`), `app/Console/Commands/WakeSnoozedNotifications.php` (via `php artisan make:command WakeSnoozedNotifications --no-interaction`)
- Modify: `app/Notifications/AppDatabaseChannel.php`, `app/Notifications/NotificationPresenter.php` (`toBroadcast`), `routes/console.php`, `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/InboxTest.php`

**Interfaces — Produces:**
- `UnreadCounts::for(User $user): array{total: int, critical: int, modules: array<string, int>}` (cached 10 min, key `notifications.unread.{id}`), `::forget(int $userId): void`.
- `NotificationPushed(int $userId, array $payload)` → `PrivateChannel('App.Models.User.{id}')`, `broadcastAs(): 'notification.pushed'`.
- `NotificationPresenter::toBroadcast(UserNotification $n, string $locale): array{id, text, module, module_label, priority, kind, url, created_at}` (all strings).
- `NotificationBroadcaster::push(User $user, UserNotification $notification): void` (never throws).
- `Inbox::SnoozePresets = ['1h', 'tomorrow', 'next_week']`; `markRead(User, string $id)`, `markUnread(User, string $id)`, `markManyRead(User, array $ids): int`, `markAllRead(User): int`, `snooze(User, string $id, string $preset)`, `static snoozeUntil(string $preset, CarbonImmutable $now): CarbonImmutable`, `wakeDue(): int`.
- Command `notifications:wake-snoozed`, scheduled every minute.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Events\NotificationPushed;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\Inbox;
use App\Notifications\NotificationPayload;
use App\Notifications\UnreadCounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

function inboxPayload(string $module = 'tasks', NotificationPriority $priority = NotificationPriority::Info): NotificationPayload
{
    return new NotificationPayload(
        kind: NotificationKind::Action, module: $module, priority: $priority,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => 'Plan'],
        subjectType: 'task', subjectId: 1,
    );
}

test('a stored notification is pushed to its owner in their language', function () {
    Event::fake([NotificationPushed::class]);
    $user = User::factory()->create();
    $user->forceFill(['locale' => 'ar'])->save();

    $user->notify(new AppNotification(inboxPayload(priority: NotificationPriority::Critical)));

    Event::assertDispatched(NotificationPushed::class, fn (NotificationPushed $event): bool => $event->userId === $user->id
        && $event->payload['priority'] === 'critical'
        && str_contains($event->payload['text'], 'أنشأ')
        && $event->broadcastAs() === 'notification.pushed'
        && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$user->id);
});

test('a broadcast failure never loses the notification', function () {
    Event::listen(NotificationPushed::class, fn () => throw new RuntimeException('socket down'));
    $user = User::factory()->create();

    $user->notify(new AppNotification(inboxPayload()));

    expect(UserNotification::query()->count())->toBe(1);
});

test('unread counts split by module and flag critical, and refresh on arrival and on read', function () {
    $user = User::factory()->create();
    $counts = app(UnreadCounts::class);

    $user->notify(new AppNotification(inboxPayload('tasks')));
    $user->notify(new AppNotification(inboxPayload('risks', NotificationPriority::Critical)));

    expect($counts->for($user))->toBe(['total' => 2, 'critical' => 1, 'modules' => ['risks' => 1, 'tasks' => 1]]);

    app(Inbox::class)->markRead($user, UserNotification::query()->where('module', 'risks')->value('id'));

    expect($counts->for($user))->toBe(['total' => 1, 'critical' => 0, 'modules' => ['tasks' => 1]]);
});

test('inbox operations only touch the acting user rows', function () {
    [$owner, $intruder] = User::factory()->count(2)->create();
    $owner->notify(new AppNotification(inboxPayload()));
    $id = UserNotification::query()->value('id');

    app(Inbox::class)->markRead($intruder, $id);
    app(Inbox::class)->snooze($intruder, $id, '1h');

    $row = UserNotification::query()->sole();
    expect($row->read_at)->toBeNull()->and($row->snoozed_until)->toBeNull();
});

test('mark unread, mark many and mark all read', function () {
    $user = User::factory()->create();
    foreach (range(1, 3) as $n) {
        $user->notify(new AppNotification(inboxPayload()));
    }
    $ids = UserNotification::query()->pluck('id')->all();
    $inbox = app(Inbox::class);

    expect($inbox->markManyRead($user, [$ids[0], $ids[1]]))->toBe(2);
    $inbox->markUnread($user, $ids[0]);
    expect($inbox->markAllRead($user))->toBe(2)
        ->and(UserNotification::query()->whereNull('read_at')->count())->toBe(0);
});

test('snooze presets land on the promised moments', function () {
    $now = CarbonImmutable::parse('2026-09-23 10:15'); // a Wednesday

    expect(Inbox::snoozeUntil('1h', $now)->format('Y-m-d H:i'))->toBe('2026-09-23 11:15')
        ->and(Inbox::snoozeUntil('tomorrow', $now)->format('Y-m-d H:i'))->toBe('2026-09-24 08:00')
        ->and(Inbox::snoozeUntil('next_week', $now)->format('Y-m-d H:i'))->toBe('2026-09-28 08:00');
});

test('an unknown snooze preset is refused', function () {
    Inbox::snoozeUntil('forever', CarbonImmutable::now());
})->throws(InvalidArgumentException::class);

test('a snoozed notification hides, then returns unread on top and is pushed again', function () {
    Event::fake([NotificationPushed::class]);
    $user = User::factory()->create();
    $user->notify(new AppNotification(inboxPayload()));
    $id = UserNotification::query()->value('id');

    app(Inbox::class)->snooze($user, $id, '1h');
    expect(app(UnreadCounts::class)->for($user)['total'])->toBe(0);

    $this->travel(61)->minutes();
    $this->artisan('notifications:wake-snoozed')->assertSuccessful();

    $row = UserNotification::query()->sole();
    expect($row->snoozed_until)->toBeNull()
        ->and($row->read_at)->toBeNull()
        ->and($row->created_at->greaterThan(now()->subMinute()))->toBeTrue()
        ->and(app(UnreadCounts::class)->for($user)['total'])->toBe(1);
    Event::assertDispatchedTimes(NotificationPushed::class, 2);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Events/NotificationPushed.php`:
```php
<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notification arrived (or woke from a snooze) for one user. Broadcast on
 * their private channel as ".notification.pushed"; the payload is already
 * rendered in their language.
 */
final class NotificationPushed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, string>  $payload
     */
    public function __construct(public int $userId, public array $payload) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.pushed';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
```

`app/Notifications/UnreadCounts.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationPriority;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Cache;

/**
 * One grouped query per user, cached, invalidated whenever that user's inbox
 * changes (arrival, read, unread, snooze, wake).
 */
final class UnreadCounts
{
    /**
     * @return array{total: int, critical: int, modules: array<string, int>}
     */
    public function for(User $user): array
    {
        return Cache::remember($this->key($user->id), now()->addMinutes(10), function () use ($user): array {
            $rows = UserNotification::query()
                ->forUser($user)
                ->whereNull('read_at')
                ->visible()
                ->toBase()
                ->selectRaw('module, priority, count(*) as aggregate')
                ->groupBy('module', 'priority')
                ->get();

            $counts = ['total' => 0, 'critical' => 0, 'modules' => []];

            foreach ($rows as $row) {
                $count = (int) $row->aggregate;
                $counts['total'] += $count;
                $counts['modules'][$row->module] = ($counts['modules'][$row->module] ?? 0) + $count;

                if ($row->priority === NotificationPriority::Critical->value) {
                    $counts['critical'] += $count;
                }
            }

            ksort($counts['modules']);

            return $counts;
        });
    }

    public function forget(int $userId): void
    {
        Cache::forget($this->key($userId));
    }

    private function key(int $userId): string
    {
        return "notifications.unread.{$userId}";
    }
}
```

`NotificationPresenter::toBroadcast()` (import `App\Models\UserNotification`):
```php
    /**
     * @return array{id: string, text: string, module: string, module_label: string, priority: string, kind: string, url: string, created_at: string}
     */
    public function toBroadcast(UserNotification $notification, string $locale): array
    {
        return [
            'id' => $notification->id,
            'text' => $this->sentence($notification->data, $locale),
            'module' => $notification->module,
            'module_label' => $this->moduleLabel($notification->module, $locale),
            'priority' => $notification->priority->value,
            'kind' => $notification->kind->value,
            'url' => route('notifications.open', $notification->id),
            'created_at' => (string) $notification->created_at?->toIso8601String(),
        ];
    }
```

`app/Notifications/NotificationBroadcaster.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Events\NotificationPushed;
use App\Models\User;
use App\Models\UserNotification;
use Throwable;

/**
 * Pushes a stored notification to its owner's browser. The notification is
 * already saved; a failure here is reported and swallowed so storage never
 * depends on the socket (the client catches up by polling / focus re-sync).
 */
final class NotificationBroadcaster
{
    public function __construct(private NotificationPresenter $presenter) {}

    public function push(User $user, UserNotification $notification): void
    {
        try {
            event(new NotificationPushed(
                $user->id,
                $this->presenter->toBroadcast($notification, $user->preferredLocale() ?? (string) config('app.locale')),
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
```

`AppDatabaseChannel` becomes:
```php
final class AppDatabaseChannel
{
    public function __construct(
        private NotificationGrouper $grouper,
        private UnreadCounts $counts,
        private NotificationBroadcaster $broadcaster,
    ) {}

    public function send(User $notifiable, AppNotification $notification): UserNotification
    {
        [$row] = $this->grouper->store($notifiable, $notification->payload, $notification->id);

        $this->counts->forget($notifiable->id);
        $this->broadcaster->push($notifiable, $row);

        return $row;
    }
}
```

`app/Notifications/Inbox.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Every change a person makes to their own inbox. Each operation is scoped to
 * the acting user, so a tampered id can never touch someone else's row.
 */
final class Inbox
{
    public const SnoozePresets = ['1h', 'tomorrow', 'next_week'];

    public function __construct(
        private UnreadCounts $counts,
        private NotificationBroadcaster $broadcaster,
    ) {}

    public function markRead(User $user, string $id): void
    {
        $this->markManyRead($user, [$id]);
    }

    public function markUnread(User $user, string $id): void
    {
        UserNotification::query()->forUser($user)->whereKey($id)->update(['read_at' => null]);
        $this->counts->forget($user->id);
    }

    /**
     * @param  array<int, string>  $ids
     */
    public function markManyRead(User $user, array $ids): int
    {
        $updated = UserNotification::query()->forUser($user)->whereKey($ids)->whereNull('read_at')->update(['read_at' => now()]);
        $this->counts->forget($user->id);

        return $updated;
    }

    public function markAllRead(User $user): int
    {
        $updated = UserNotification::query()->forUser($user)->visible()->whereNull('read_at')->update(['read_at' => now()]);
        $this->counts->forget($user->id);

        return $updated;
    }

    public function snooze(User $user, string $id, string $preset): void
    {
        UserNotification::query()->forUser($user)->whereKey($id)->update(['snoozed_until' => self::snoozeUntil($preset, CarbonImmutable::now())]);
        $this->counts->forget($user->id);
    }

    /**
     * When a snooze preset ends: in an hour, tomorrow 08:00, or next week's
     * first day 08:00 (Phase 4 makes that the first working day).
     */
    public static function snoozeUntil(string $preset, CarbonImmutable $now): CarbonImmutable
    {
        return match ($preset) {
            '1h' => $now->addHour(),
            'tomorrow' => $now->addDay()->setTime(8, 0),
            'next_week' => $now->startOfWeek()->addWeek()->setTime(8, 0),
            default => throw new InvalidArgumentException("Unknown snooze preset [{$preset}]."),
        };
    }

    /**
     * Wake every notification whose snooze has ended: unread again, moved to
     * the top, pushed again.
     */
    public function wakeDue(): int
    {
        $due = UserNotification::query()
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', now())
            ->get();

        $users = User::query()->whereIn('id', $due->pluck('notifiable_id')->unique())->get()->keyBy('id');

        foreach ($due as $notification) {
            $notification->forceFill(['snoozed_until' => null, 'read_at' => null, 'created_at' => now()])->save();

            $user = $users->get($notification->notifiable_id);

            if ($user !== null) {
                $this->counts->forget($user->id);
                $this->broadcaster->push($user, $notification);
            }
        }

        return $due->count();
    }
}
```

`app/Console/Commands/WakeSnoozedNotifications.php`:
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\Inbox;
use Illuminate\Console\Command;

/**
 * Returns notifications whose snooze has ended to their owners' inboxes.
 */
class WakeSnoozedNotifications extends Command
{
    protected $signature = 'notifications:wake-snoozed';

    protected $description = 'Return snoozed notifications whose snooze has ended';

    public function handle(Inbox $inbox): int
    {
        $this->info(__('notifications.console.woken', ['count' => $inbox->wakeDue()]));

        return self::SUCCESS;
    }
}
```

`routes/console.php` — add:
```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifications:wake-snoozed')->everyMinute()->withoutOverlapping();
```

Lang `notifications.console.woken`: en `':count notification(s) woken.'`, ar `'أُعيد :count من الإشعارات.'`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications app/Events app/Console/Commands/WakeSnoozedNotifications.php routes/console.php lang tests/Feature/Notifications/InboxTest.php`

### Task 2.3: Opening a notification; subject links; "no longer exists"

**Files:**
- Create: `app/Notifications/SubjectLinks.php`, `app/Http/Controllers/OpenNotificationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Notifications/OpenNotificationTest.php`

**Interfaces — Produces:** `SubjectLinks::urlFor(UserNotification $n): ?string`, `::urlForSubject(string $type, int $id): ?string` (null when gone), `::routeFor(string $type, int $id): ?string` (no existence check); routes `notifications.index`, `notifications.open` (`GET notifications/{notification}/open`).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;

function notifyAboutTask(User $user, int $taskId): string
{
    $user->notify(new AppNotification(new NotificationPayload(
        kind: NotificationKind::Action, module: 'tasks', priority: NotificationPriority::Info,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => 'Plan'],
        subjectType: 'task', subjectId: $taskId,
    )));

    return UserNotification::query()->latest()->value('id');
}

test('opening a notification marks it read and goes to its record', function () {
    $user = User::factory()->create();
    $task = Activity::withoutRecording(fn () => Task::factory()->create());
    $id = notifyAboutTask($user, $task->id);

    $this->actingAs($user)->get(route('notifications.open', $id))->assertRedirect(route('tasks.show', $task));

    expect(UserNotification::query()->find($id)->read_at)->not->toBeNull();
});

test('a notification about a deleted record opens the inbox saying it no longer exists', function () {
    $user = User::factory()->create();
    $id = notifyAboutTask($user, 999999);

    $this->actingAs($user)->get(route('notifications.open', $id))
        ->assertRedirect(route('notifications.index'))
        ->assertSessionHas('notification-missing', true);

    $this->actingAs($user)->withSession(['notification-missing' => true])
        ->get(route('notifications.index'))
        ->assertSeeText(__('notifications.item_no_longer_exists'));
});

test('someone else cannot open my notification', function () {
    [$owner, $intruder] = User::factory()->count(2)->create();
    $id = notifyAboutTask($owner, 1);

    $this->actingAs($intruder)->get(route('notifications.open', $id))->assertNotFound();
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`routes/web.php` inside the `auth, verified` group (import the controller):
```php
    Route::livewire('notifications', 'pages::notifications.index')
        ->name('notifications.index');

    Route::get('notifications/{notification}/open', OpenNotificationController::class)
        ->name('notifications.open');
```

`app/Notifications/SubjectLinks.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Where a notification or activity row leads. Returns null when the record
 * no longer exists, so the caller can say so instead of 404ing.
 */
final class SubjectLinks
{
    public function urlFor(UserNotification $notification): ?string
    {
        $link = $notification->data['link'] ?? [];

        if (isset($link['route'])) {
            return route($link['route'], $link['parameters'] ?? []);
        }

        if ($notification->subject_type === null || $notification->subject_id === null) {
            return null;
        }

        return $this->urlForSubject($notification->subject_type, $notification->subject_id);
    }

    public function urlForSubject(string $type, int $id): ?string
    {
        $class = Relation::getMorphedModel($type);

        if ($class === null || ! $class::query()->whereKey($id)->exists()) {
            return null;
        }

        return $this->routeFor($type, $id);
    }

    /**
     * The page for a record, without checking it still exists.
     */
    public function routeFor(string $type, int $id): ?string
    {
        return match ($type) {
            'task' => route('tasks.show', $id),
            'milestone' => route('milestones.show', $id),
            'project' => route('projects.show', $id),
            'program' => route('programs.show', $id),
            'annual_plan' => route('annual-plans.show', $id),
            'proposal' => route('proposals.show', $id),
            'user' => route('admin.users.index'),
            default => null,
        };
    }
}
```

`app/Http/Controllers/OpenNotificationController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Notifications\Inbox;
use App\Notifications\SubjectLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opening a notification reads it, then leads to its record — or back to the
 * inbox with a note when that record was deleted.
 */
class OpenNotificationController extends Controller
{
    public function __invoke(Request $request, string $notification, Inbox $inbox, SubjectLinks $links): RedirectResponse
    {
        $row = UserNotification::query()->forUser($request->user())->findOrFail($notification);

        $inbox->markRead($request->user(), $row->id);

        $url = $links->urlFor($row);

        return $url === null
            ? redirect()->route('notifications.index')->with('notification-missing', true)
            : redirect($url);
    }
}
```

- [ ] **Step 4: Run** — the third test passes now; the first two need the page from Task 2.5 — re-run this file at the end of Task 2.5 → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/SubjectLinks.php app/Http/Controllers/OpenNotificationController.php routes/web.php tests/Feature/Notifications/OpenNotificationTest.php`

### Task 2.4: Bell and panel

**Files:**
- Create: `app/Livewire/Concerns/ManagesInbox.php`, `resources/views/components/notifications/⚡bell.blade.php` (via `php artisan make:livewire notifications.bell --sfc --no-interaction`), `resources/views/components/notifications/row.blade.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php` (bell in desktop + mobile headers, body data attributes), `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/NotificationBellTest.php`

**Interfaces — Produces:** trait `ManagesInbox`: `markRead(string $id)`, `markUnread(string $id)`, `markAllRead()`, `snooze(string $id, string $preset)`; each dispatches the Livewire event `notification-received`. The bell listens to `notification-received` and dispatches the browser event `notification-counts` with `counts`. Blade `<x-notifications.row :notification="$n" :selectable="false" />` (default slot renders under the sentence — Phase 5 puts quick actions there).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use Livewire\Livewire;

function bellNotify(User $user, NotificationPriority $priority = NotificationPriority::Info, NotificationKind $kind = NotificationKind::Action, string $subject = 'Plan'): string
{
    $user->notify(new AppNotification(new NotificationPayload(
        kind: $kind, module: 'tasks', priority: $priority,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => $subject],
        subjectType: 'task', subjectId: random_int(1, 99999),
    )));

    return UserNotification::query()->latest()->value('id');
}

test('the bell shows the unread count and the sentences', function () {
    $user = User::factory()->create();
    bellNotify($user, subject: 'Water trucking');
    bellNotify($user, subject: 'Hygiene kits');

    Livewire::actingAs($user)->test('notifications.bell')
        ->assertSeeHtml('data-unread-count')
        ->assertSeeText('Sami created the task “Water trucking”')
        ->assertSeeText('Hygiene kits');
});

test('an unread critical notification is pinned first and turns the counter red', function () {
    $user = User::factory()->create();
    bellNotify($user, NotificationPriority::Critical, subject: 'Urgent');
    $this->travel(1)->minutes();
    bellNotify($user, subject: 'Latest of all');

    Livewire::actingAs($user)->test('notifications.bell')
        ->assertSeeInOrder(['Urgent', 'Latest of all'])
        ->assertSeeHtml('data-critical-unread');
});

test('the unread and alerts tabs narrow the list', function () {
    $user = User::factory()->create();
    UserNotification::query()->find(bellNotify($user, subject: 'Already read'))->markAsRead();
    bellNotify($user, kind: NotificationKind::Alert, subject: 'Due tomorrow');
    bellNotify($user, subject: 'Fresh action');

    Livewire::actingAs($user)->test('notifications.bell')
        ->call('showTab', 'unread')
        ->assertDontSeeText('Already read')
        ->assertSeeText('Fresh action')
        ->call('showTab', 'alerts')
        ->assertSeeText('Due tomorrow')
        ->assertDontSeeText('Fresh action');
});

test('marking read, unread, all read and snoozing from the bell', function () {
    $user = User::factory()->create();
    $first = bellNotify($user);
    $second = bellNotify($user);

    Livewire::actingAs($user)->test('notifications.bell')
        ->call('markRead', $first)
        ->assertDispatched('notification-received')
        ->call('markUnread', $first)
        ->call('snooze', $second, 'tomorrow')
        ->call('markAllRead');

    expect(UserNotification::query()->find($first)->read_at)->not->toBeNull()
        ->and(UserNotification::query()->find($second)->snoozed_until)->not->toBeNull();
});

test('an unknown snooze preset from the browser changes nothing', function () {
    $user = User::factory()->create();
    $id = bellNotify($user);

    Livewire::actingAs($user)->test('notifications.bell')->call('snooze', $id, 'forever');

    expect(UserNotification::query()->find($id)->snoozed_until)->toBeNull();
});

test('every authenticated page shows the bell and tells the script who is listening', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSeeHtml('data-notification-bell')
        ->assertSeeHtml('data-user-id="'.$user->id.'"');
});

test('the bell renders in arabic for arabic readers', function () {
    $user = User::factory()->create();
    bellNotify($user, subject: 'خطة');

    app()->setLocale('ar');

    Livewire::actingAs($user)->test('notifications.bell')->assertSeeText('أنشأ Sami المهمة «خطة»');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Livewire/Concerns/ManagesInbox.php`:
```php
<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Notifications\Inbox;

/**
 * Inbox actions shared by the bell and the /notifications page. Inbox scopes
 * every action to the signed-in user; each action tells every inbox view on
 * the page to refresh.
 */
trait ManagesInbox
{
    public function markRead(string $id): void
    {
        app(Inbox::class)->markRead(auth()->user(), $id);
        $this->dispatch('notification-received');
    }

    public function markUnread(string $id): void
    {
        app(Inbox::class)->markUnread(auth()->user(), $id);
        $this->dispatch('notification-received');
    }

    public function markAllRead(): void
    {
        app(Inbox::class)->markAllRead(auth()->user());
        $this->dispatch('notification-received');
    }

    public function snooze(string $id, string $preset): void
    {
        if (! in_array($preset, Inbox::SnoozePresets, true)) {
            return;
        }

        app(Inbox::class)->snooze(auth()->user(), $id, $preset);
        $this->dispatch('notification-received');
    }
}
```

`resources/views/components/notifications/⚡bell.blade.php`:
```blade
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Livewire\Concerns\ManagesInbox;
use App\Models\UserNotification;
use App\Notifications\UnreadCounts;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    use ManagesInbox;

    /** Which slice of the inbox the panel shows: all, unread or alerts. */
    public string $tab = 'all';

    /**
     * @return array{total: int, critical: int, modules: array<string, int>}
     */
    #[Computed]
    public function counts(): array
    {
        return app(UnreadCounts::class)->for(auth()->user());
    }

    /**
     * The latest 30 rows, bucketed: pinned (unread critical), today, yesterday, earlier.
     *
     * @return Collection<string, Collection<int, UserNotification>>
     */
    #[Computed]
    public function sections(): Collection
    {
        $order = ['pinned', 'today', 'yesterday', 'earlier'];

        return UserNotification::query()
            ->forUser(auth()->user())
            ->visible()
            ->when($this->tab === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($this->tab === 'alerts', fn ($query) => $query->whereIn('kind', [NotificationKind::Alert->value, NotificationKind::Digest->value]))
            ->with('actor:id,name')
            ->orderByRaw('case when read_at is null and priority = ? then 0 else 1 end', [NotificationPriority::Critical->value])
            ->latest()
            ->limit(30)
            ->get()
            ->groupBy(fn (UserNotification $notification): string => match (true) {
                $notification->read_at === null && $notification->priority === NotificationPriority::Critical => 'pinned',
                $notification->created_at->isToday() => 'today',
                $notification->created_at->isYesterday() => 'yesterday',
                default => 'earlier',
            })
            ->sortBy(fn (Collection $rows, string $section): int => (int) array_search($section, $order, true));
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['all', 'unread', 'alerts'], true) ? $tab : 'all';
        unset($this->sections);
    }

    #[On('notification-received')]
    public function refreshInbox(): void
    {
        unset($this->counts, $this->sections);
        $this->dispatch('notification-counts', counts: $this->counts);
    }
}; ?>

<div
    x-data="notificationBell"
    x-on:keydown.escape.window="open = false"
    x-on:click.outside="open = false"
    class="relative"
    data-notification-bell
>
    <button
        type="button"
        x-on:click="open = ! open"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="dialog"
        aria-label="{{ trans_choice('notifications.bell_label', $this->counts['total'], ['count' => $this->counts['total']]) }}"
        x-bind:class="pulse && 'motion-safe:animate-pulse'"
        class="relative inline-flex size-9 touch-manipulation items-center justify-center rounded-lg text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark max-lg:size-11 dark:text-secondary-soft dark:hover:bg-white/10 dark:hover:text-white"
        data-test="notification-bell-button"
    >
        <flux:icon.bell variant="mini" />

        @if ($this->counts['total'] > 0)
            <span
                @class([
                    'absolute -end-0.5 -top-0.5 inline-flex min-w-[1.125rem] items-center justify-center rounded-full px-1 text-[10px] font-bold leading-[1.125rem] tabular-nums text-white',
                    'bg-primary' => $this->counts['critical'] > 0,
                    'bg-secondary-dark dark:bg-secondary-medium' => $this->counts['critical'] === 0,
                ])
                data-unread-count
                @if ($this->counts['critical'] > 0) data-critical-unread @endif
            >{{ $this->counts['total'] > 99 ? '99+' : $this->counts['total'] }}</span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.150ms
        role="dialog"
        aria-label="{{ __('notifications.inbox') }}"
        class="absolute end-0 top-full z-50 mt-2 flex w-[min(26rem,calc(100vw-1.5rem))] flex-col rounded-xl bg-white text-secondary-dark shadow-[0_12px_32px_rgba(16,24,40,0.14),0_2px_6px_rgba(16,24,40,0.08)] dark:bg-secondary-dark dark:text-white dark:ring-1 dark:ring-secondary-medium"
    >
        <div class="flex items-center justify-between gap-3 px-4 pt-4">
            <p class="text-base font-bold">{{ __('notifications.inbox') }}</p>
            <a href="{{ route('notification-settings.edit') }}" wire:navigate class="inline-flex size-8 items-center justify-center rounded-lg text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10" aria-label="{{ __('notifications.settings_link') }}">
                <flux:icon.cog-6-tooth variant="mini" />
            </a>
        </div>

        <div class="flex gap-5 px-4 pt-2 text-sm" role="tablist">
            @foreach (['all', 'unread', 'alerts'] as $key)
                <button
                    type="button"
                    role="tab"
                    wire:click="showTab('{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    @class([
                        'min-h-9 border-b-2 font-semibold transition-colors',
                        'border-primary text-secondary-dark dark:text-white' => $tab === $key,
                        'border-transparent text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white' => $tab !== $key,
                    ])
                >{{ __('notifications.tabs.'.$key) }}</button>
            @endforeach
        </div>

        <div class="max-h-[min(32rem,70vh)] overflow-y-auto px-2 pb-2">
            @forelse ($this->sections as $section => $rows)
                <p class="type-label px-2 pt-4 pb-1">{{ __('notifications.sections.'.$section) }}</p>
                <ol class="space-y-0.5">
                    @foreach ($rows as $notification)
                        <x-notifications.row :notification="$notification" wire:key="bell-{{ $notification->id }}" />
                    @endforeach
                </ol>
            @empty
                <p class="px-4 py-12 text-center text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.empty') }}</p>
            @endforelse
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-secondary-soft px-4 py-3 text-sm dark:border-secondary-medium">
            <button type="button" wire:click="markAllRead" class="font-semibold text-secondary-medium hover:text-secondary-dark dark:text-secondary-soft dark:hover:text-white">{{ __('notifications.mark_all_read') }}</button>
            <a href="{{ route('notifications.index') }}" wire:navigate class="font-semibold text-status-critical hover:underline">{{ __('notifications.view_all') }}</a>
        </div>
    </div>
</div>
```

`resources/views/components/notifications/row.blade.php`:
```blade
@props(['notification', 'selectable' => false])

@php
    $presenter = app(\App\Notifications\NotificationPresenter::class);
    $isUnread = $notification->read_at === null;
    $dotClasses = match ($notification->priority) {
        \App\Enums\NotificationPriority::Critical => 'bg-primary',
        \App\Enums\NotificationPriority::Important => 'bg-status-warn',
        \App\Enums\NotificationPriority::Info => 'bg-secondary-light dark:bg-secondary-medium',
    };
@endphp

<li
    {{ $attributes->class('group relative flex gap-3 rounded-lg px-2 py-2.5 hover:bg-secondary-soft/50 dark:hover:bg-white/5') }}
    data-notification-row
    data-priority="{{ $notification->priority->value }}"
    @if ($isUnread) data-unread @endif
>
    @if ($selectable)
        <input type="checkbox" wire:model.live="selected" value="{{ $notification->id }}" class="mt-2 size-4 shrink-0 accent-primary" aria-label="{{ __('notifications.select_notification') }}" />
    @endif

    <span class="relative mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-secondary-soft text-[11px] font-bold text-secondary-dark dark:bg-white/10 dark:text-white" aria-hidden="true">
        @if ($notification->kind === \App\Enums\NotificationKind::Action)
            {{ $notification->actor?->initials() ?? '·' }}
        @elseif ($notification->kind === \App\Enums\NotificationKind::Alert)
            <flux:icon.bell-alert variant="micro" />
        @else
            <flux:icon.newspaper variant="micro" />
        @endif
        <span class="absolute -end-0.5 -bottom-0.5 size-2.5 rounded-full ring-2 ring-white dark:ring-secondary-dark {{ $dotClasses }}"></span>
    </span>

    <div class="min-w-0 flex-1">
        <a href="{{ route('notifications.open', $notification->id) }}" class="block focus:outline-none" data-notification-link>
            <p @class([
                'text-sm leading-snug',
                'font-semibold text-secondary-dark dark:text-white' => $isUnread,
                'text-secondary-medium dark:text-secondary-soft' => ! $isUnread,
            ])>{{ $presenter->sentence($notification->data) }}</p>
            <p class="mt-1 flex flex-wrap items-center gap-x-2 text-xs text-secondary-medium dark:text-secondary-soft">
                <time datetime="{{ $notification->created_at?->toIso8601String() }}">{{ $notification->created_at?->diffForHumans() }}</time>
                <span aria-hidden="true">·</span>
                <span>{{ $presenter->moduleLabel($notification->module) }}</span>
                <span class="sr-only">{{ $notification->priority->label() }}</span>
            </p>
        </a>
        {{ $slot }}
    </div>

    <div x-data="{ snoozing: false }" class="relative flex shrink-0 items-start gap-0.5 lg:opacity-0 lg:group-hover:opacity-100 lg:group-focus-within:opacity-100">
        @if ($isUnread)
            <button type="button" wire:click="markRead('{{ $notification->id }}')" class="inline-flex size-8 items-center justify-center rounded-md text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10" aria-label="{{ __('notifications.mark_read') }}">
                <flux:icon.check variant="micro" />
            </button>
        @else
            <button type="button" wire:click="markUnread('{{ $notification->id }}')" class="inline-flex size-8 items-center justify-center rounded-md text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10" aria-label="{{ __('notifications.mark_unread') }}">
                <flux:icon.envelope variant="micro" />
            </button>
        @endif

        <button type="button" x-on:click="snoozing = ! snoozing" x-bind:aria-expanded="snoozing.toString()" class="inline-flex size-8 items-center justify-center rounded-md text-secondary-medium hover:bg-secondary-soft hover:text-secondary-dark dark:text-secondary-soft dark:hover:bg-white/10" aria-label="{{ __('notifications.snooze') }}">
            <flux:icon.clock variant="micro" />
        </button>

        <div x-show="snoozing" x-cloak x-on:click.outside="snoozing = false" class="absolute end-0 top-9 z-10 w-40 rounded-lg bg-white py-1 text-sm shadow-lg dark:bg-secondary-dark dark:ring-1 dark:ring-secondary-medium">
            @foreach (\App\Notifications\Inbox::SnoozePresets as $preset)
                <button type="button" wire:click="snooze('{{ $notification->id }}', '{{ $preset }}')" x-on:click="snoozing = false" class="block w-full px-3 py-2 text-start hover:bg-secondary-soft/60 dark:hover:bg-white/10">{{ __('notifications.snooze_presets.'.$preset) }}</button>
            @endforeach
        </div>
    </div>
</li>
```

`resources/views/layouts/app/sidebar.blade.php`:
- `<body>` gains `@auth data-user-id="{{ auth()->id() }}" data-notification-popups="off" @endauth` (Task 2.6 switches `off` to the user's setting).
- Desktop header: `<livewire:notifications.bell />` as the first child of the `flex items-center gap-1.5` group (before the theme toggle).
- Mobile header: `<livewire:notifications.bell />` between `<flux:spacer />` and the profile `<flux:dropdown>`.

Lang additions (en):
```php
    'inbox' => 'Notifications',
    'bell_label' => '{0} Notifications, none unread|{1} Notifications, 1 unread|[2,*] Notifications, :count unread',
    'settings_link' => 'Notification settings',
    'empty' => 'You are all caught up.',
    'mark_all_read' => 'Mark all read',
    'view_all' => 'View all',
    'mark_read' => 'Mark as read',
    'mark_unread' => 'Mark as unread',
    'snooze' => 'Snooze',
    'select_notification' => 'Select notification',
    'item_no_longer_exists' => 'This item no longer exists.',
    'tabs' => ['all' => 'All', 'unread' => 'Unread', 'alerts' => 'Alerts'],
    'sections' => ['pinned' => 'Needs attention', 'today' => 'Today', 'yesterday' => 'Yesterday', 'earlier' => 'Earlier'],
    'snooze_presets' => ['1h' => 'For 1 hour', 'tomorrow' => 'Until tomorrow', 'next_week' => 'Until next week'],
```
(ar):
```php
    'inbox' => 'الإشعارات',
    'bell_label' => '{0} الإشعارات، لا جديد|{1} الإشعارات، إشعار واحد غير مقروء|{2} الإشعارات، إشعاران غير مقروءين|[3,10] الإشعارات، :count إشعارات غير مقروءة|[11,*] الإشعارات، :count إشعارًا غير مقروء',
    'settings_link' => 'إعدادات الإشعارات',
    'empty' => 'لا جديد لديك.',
    'mark_all_read' => 'تعليم الكل كمقروء',
    'view_all' => 'عرض الكل',
    'mark_read' => 'تعليم كمقروء',
    'mark_unread' => 'تعليم كغير مقروء',
    'snooze' => 'تأجيل',
    'select_notification' => 'تحديد الإشعار',
    'item_no_longer_exists' => 'هذا العنصر لم يعد موجودًا.',
    'tabs' => ['all' => 'الكل', 'unread' => 'غير المقروءة', 'alerts' => 'التنبيهات'],
    'sections' => ['pinned' => 'بحاجة إلى متابعة', 'today' => 'اليوم', 'yesterday' => 'أمس', 'earlier' => 'سابقًا'],
    'snooze_presets' => ['1h' => 'لمدة ساعة', 'tomorrow' => 'حتى الغد', 'next_week' => 'حتى الأسبوع القادم'],
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/NotificationBellTest.php tests/Feature/NavigationTest.php tests/Feature/DashboardTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Livewire/Concerns/ManagesInbox.php resources/views/components/notifications resources/views/layouts/app/sidebar.blade.php lang tests/Feature/Notifications/NotificationBellTest.php`

### Task 2.5: `/notifications` page

**Files:**
- Create: `resources/views/pages/notifications/⚡index.blade.php`
- Modify: `lang/{en,ar}/notifications.php`, `lang/{en,ar}/titles.php`
- Test: `tests/Feature/Notifications/NotificationInboxPageTest.php`

**Interfaces — Consumes:** `ManagesInbox`, `x-notifications.row`, `UnreadCounts`. **Produces:** `pages::notifications.index` with `#[Url]` filters `module`, `kind`, `priority`, `state` (`all|unread|read`), `search`; `perPage` + `loadMore()`; `selected` + `markSelectedRead()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use Livewire\Livewire;

function pageNotify(User $user, string $subject, string $module = 'tasks', NotificationKind $kind = NotificationKind::Action, NotificationPriority $priority = NotificationPriority::Info): string
{
    $user->notify(new AppNotification(new NotificationPayload(
        kind: $kind, module: $module, priority: $priority,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => $subject],
        subjectType: 'task', subjectId: random_int(1, 99999),
    )));

    return UserNotification::query()->latest()->value('id');
}

test('guests cannot open the inbox page', function () {
    $this->get(route('notifications.index'))->assertRedirect(route('login'));
});

test('the inbox lists only my notifications', function () {
    [$me, $other] = User::factory()->count(2)->create();
    pageNotify($me, 'Mine');
    pageNotify($other, 'Theirs');

    $this->actingAs($me)->get(route('notifications.index'))->assertOk()->assertSeeText('Mine')->assertDontSeeText('Theirs');
});

test('filters narrow by module kind priority and read state', function () {
    $user = User::factory()->create();
    pageNotify($user, 'Task row');
    pageNotify($user, 'Risk row', module: 'risks', priority: NotificationPriority::Critical);
    pageNotify($user, 'Alert row', kind: NotificationKind::Alert);
    UserNotification::query()->find(pageNotify($user, 'Read row'))->markAsRead();

    $page = fn () => Livewire::actingAs($user)->test('pages::notifications.index');

    $page()->set('module', 'risks')->assertSeeText('Risk row')->assertDontSeeText('Task row');
    $page()->set('kind', 'alert')->assertSeeText('Alert row')->assertDontSeeText('Task row');
    $page()->set('priority', 'critical')->assertSeeText('Risk row')->assertDontSeeText('Alert row');
    $page()->set('state', 'read')->assertSeeText('Read row')->assertDontSeeText('Task row');
    $page()->set('state', 'unread')->assertDontSeeText('Read row');
});

test('search matches the stored names, arabic included', function () {
    $user = User::factory()->create();
    pageNotify($user, 'توزيع المياه');
    pageNotify($user, 'Hygiene kits');

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->set('search', 'توزيع')
        ->assertSeeText('توزيع المياه')
        ->assertDontSeeText('Hygiene kits');
});

test('selected notifications can be marked read together', function () {
    $user = User::factory()->create();
    $first = pageNotify($user, 'One');
    $second = pageNotify($user, 'Two');
    $third = pageNotify($user, 'Three');

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->set('selected', [$first, $second])
        ->call('markSelectedRead')
        ->assertSet('selected', []);

    expect(UserNotification::query()->whereNull('read_at')->pluck('id')->all())->toBe([$third]);
});

test('the list grows by loading more', function () {
    $user = User::factory()->create();
    foreach (range(1, 30) as $n) {
        pageNotify($user, "Row {$n}");
    }

    Livewire::actingAs($user)->test('pages::notifications.index')
        ->assertSet('perPage', 25)
        ->assertSeeHtml('data-load-more')
        ->call('loadMore')
        ->assertSet('perPage', 50)
        ->assertDontSeeHtml('data-load-more');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement** `resources/views/pages/notifications/⚡index.blade.php`:
```blade
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Livewire\Concerns\ManagesInbox;
use App\Models\UserNotification;
use App\Notifications\Inbox;
use App\Notifications\NotificationPresenter;
use App\Notifications\UnreadCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('titles.notifications')] class extends Component {
    use ManagesInbox;

    private const PageSize = 25;

    #[Url(except: '')]
    public string $module = '';

    #[Url(except: '')]
    public string $kind = '';

    #[Url(except: '')]
    public string $priority = '';

    #[Url(except: 'all')]
    public string $state = 'all';

    #[Url(except: '')]
    public string $search = '';

    public int $perPage = self::PageSize;

    /** @var list<string> */
    public array $selected = [];

    public function updated(string $property): void
    {
        if (in_array($property, ['module', 'kind', 'priority', 'state', 'search'], true)) {
            $this->perPage = self::PageSize;
            $this->selected = [];
        }
    }

    /**
     * @return Builder<UserNotification>
     */
    private function filteredQuery(): Builder
    {
        $term = trim($this->search);

        return UserNotification::query()
            ->forUser(auth()->user())
            ->visible()
            ->when($this->module !== '', fn (Builder $query) => $query->where('module', $this->module))
            ->when(in_array($this->kind, NotificationKind::values(), true), fn (Builder $query) => $query->where('kind', $this->kind))
            ->when(in_array($this->priority, NotificationPriority::values(), true), fn (Builder $query) => $query->where('priority', $this->priority))
            ->when($this->state === 'unread', fn (Builder $query) => $query->whereNull('read_at'))
            ->when($this->state === 'read', fn (Builder $query) => $query->whereNotNull('read_at'))
            ->when($term !== '', fn (Builder $query) => $query->where('data', 'like', '%'.addcslashes($term, '%_\\').'%'));
    }

    /**
     * One row more than shown, so hasMore needs no second query.
     *
     * @return Collection<int, UserNotification>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->filteredQuery()->with('actor:id,name')->latest()->limit($this->perPage + 1)->get();
    }

    /**
     * @return Collection<string, Collection<int, UserNotification>> date label => rows
     */
    #[Computed]
    public function days(): Collection
    {
        return $this->rows->take($this->perPage)->groupBy(
            fn (UserNotification $notification): string => $notification->created_at->translatedFormat(__('formats.date')),
        );
    }

    #[Computed]
    public function hasMore(): bool
    {
        return $this->rows->count() > $this->perPage;
    }

    /**
     * Modules present in my inbox, for the module filter.
     *
     * @return list<string>
     */
    #[Computed]
    public function moduleOptions(): array
    {
        return UserNotification::query()->forUser(auth()->user())->distinct()->orderBy('module')->pluck('module')->all();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return app(UnreadCounts::class)->for(auth()->user())['total'];
    }

    public function loadMore(): void
    {
        $this->perPage += self::PageSize;
    }

    public function markSelectedRead(): void
    {
        app(Inbox::class)->markManyRead(auth()->user(), $this->selected);
        $this->selected = [];
        $this->dispatch('notification-received');
    }

    #[On('notification-received')]
    public function refreshList(): void
    {
        unset($this->rows, $this->days, $this->hasMore, $this->unreadCount);
    }
}; ?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="notifications-heading">
    <x-ui.page-header heading-id="notifications-heading" :title="__('notifications.inbox')" :count="$this->unreadCount">
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('notifications.search')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('notifications.filters.module')" wire:model.live="module" :value="$module">
                <flux:select.option value="">{{ __('notifications.filters.all_modules') }}</flux:select.option>
                @foreach ($this->moduleOptions as $option)
                    <flux:select.option :value="$option" :wire:key="'notification-module-'.$option">{{ app(NotificationPresenter::class)->moduleLabel($option) }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('notifications.filters.kind')" wire:model.live="kind" :value="$kind">
                <flux:select.option value="">{{ __('notifications.filters.all_kinds') }}</flux:select.option>
                @foreach (NotificationKind::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('notifications.filters.priority')" wire:model.live="priority" :value="$priority">
                <flux:select.option value="">{{ __('notifications.filters.all_priorities') }}</flux:select.option>
                @foreach (NotificationPriority::cases() as $case)
                    <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('notifications.filters.state')" wire:model.live="state" :value="$state">
                @foreach (['all', 'unread', 'read'] as $option)
                    <flux:select.option :value="$option">{{ __('notifications.states.'.$option) }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.button tone="ghost" icon="check" wire:click="markSelectedRead" :disabled="$selected === []">{{ __('notifications.mark_selected_read') }}</x-ui.button>
                <x-ui.button tone="primary" icon="check-circle" wire:click="markAllRead">{{ __('notifications.mark_all_read') }}</x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('notification-missing'))
        <p class="flex items-center gap-2 text-sm font-semibold text-status-critical" role="status">
            <span class="size-2 rounded-full bg-primary" aria-hidden="true"></span>
            {{ __('notifications.item_no_longer_exists') }}
        </p>
    @endif

    @forelse ($this->days as $day => $rows)
        <div class="grid gap-1 @4xl/directory:grid-cols-[10rem_minmax(0,1fr)]" wire:key="inbox-day-{{ $loop->index }}">
            <p class="type-label pt-3">{{ $day }}</p>
            <ol class="border-s border-secondary-soft ps-3 dark:border-secondary-medium">
                @foreach ($rows as $notification)
                    <x-notifications.row :notification="$notification" :selectable="true" wire:key="inbox-{{ $notification->id }}" />
                @endforeach
            </ol>
        </div>
    @empty
        <x-ui.empty-state icon="bell" :heading="__('notifications.empty')" :body="__('notifications.empty_body')" />
    @endforelse

    @if ($this->hasMore)
        <div x-intersect="$wire.loadMore()" data-load-more class="py-6 text-center text-sm text-secondary-medium dark:text-secondary-soft">
            <button type="button" wire:click="loadMore" class="font-semibold hover:underline">{{ __('notifications.load_more') }}</button>
        </div>
    @endif
</section>
```
(Before writing, open `components/ui/button.blade.php`, `ui/input.blade.php`, `ui/empty-state.blade.php` and match their prop names exactly.)

Lang (en): `'search' => 'Search notifications'`, `'mark_selected_read' => 'Mark selected read'`, `'load_more' => 'Load more'`, `'empty_body' => 'New activity and alerts will appear here.'`, `'filters' => ['module' => 'Module', 'all_modules' => 'All modules', 'kind' => 'Type', 'all_kinds' => 'All types', 'priority' => 'Priority', 'all_priorities' => 'All priorities', 'state' => 'Status']`, `'states' => ['all' => 'All', 'unread' => 'Unread', 'read' => 'Read']`.
(ar): `'search' => 'البحث في الإشعارات'`, `'mark_selected_read' => 'تعليم المحدد كمقروء'`, `'load_more' => 'تحميل المزيد'`, `'empty_body' => 'سيظهر هنا كل نشاط أو تنبيه جديد.'`, `'filters' => ['module' => 'الوحدة', 'all_modules' => 'كل الوحدات', 'kind' => 'النوع', 'all_kinds' => 'كل الأنواع', 'priority' => 'الأولوية', 'all_priorities' => 'كل الأولويات', 'state' => 'الحالة']`, `'states' => ['all' => 'الكل', 'unread' => 'غير المقروءة', 'read' => 'المقروءة']`.
`titles.php`: `'notifications' => 'Notifications'` / `'الإشعارات'`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/NotificationInboxPageTest.php tests/Feature/Notifications/OpenNotificationTest.php tests/Feature/LocalizationTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add resources/views/pages/notifications lang tests/Feature/Notifications/NotificationInboxPageTest.php`

### Task 2.6: Browser side — toasts, OS popups, polling fallback, focus re-sync; popup opt-in

**Files:**
- Create: `resources/js/notifications.js`; migration `add_notification_popups_to_users_table`; `resources/views/pages/notifications/⚡settings.blade.php`
- Modify: `resources/js/app.js`, `routes/settings.php`, `resources/views/pages/settings/layout.blade.php`, `app/Models/User.php`, `resources/views/layouts/app/sidebar.blade.php`, `lang/{en,ar}/notifications.php`, `lang/{en,ar}/titles.php`
- Test: `tests/Feature/Notifications/NotificationSettingsPageTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\User;
use Livewire\Livewire;

test('a user can opt in to desktop popups and the page tells the script', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('pages::notifications.settings')
        ->assertSet('popups', false)
        ->set('popups', true);

    expect($user->fresh()->notification_popups)->toBeTrue();

    $this->actingAs($user->fresh())->get(route('dashboard'))->assertSeeHtml('data-notification-popups="on"');
});

test('the settings navigation links to notification settings', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('notification-settings.edit'))
        ->assertOk()
        ->assertSee(route('notification-settings.edit'));
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

Migration (`php artisan make:migration add_notification_popups_to_users_table --no-interaction`):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the user opted in to operating-system popups for important and
 * critical notifications while the tab is hidden. Off by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notification_popups')->default(false)->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_popups');
        });
    }
};
```
`User`: `@property bool $notification_popups`; `$attributes` gains `'notification_popups' => false` (strict-mode mirror of the column default); `casts()` gains `'notification_popups' => 'boolean'`. Not fillable (written with `forceFill`).

`routes/settings.php`, inside the `auth, verified` group:
```php
    Route::livewire('notifications-settings', 'pages::notifications.settings')->name('notification-settings.edit');
```
`pages/settings/layout.blade.php` navlist gains `<flux:navlist.item :href="route('notification-settings.edit')" wire:navigate>{{ __('notifications.settings_link') }}</flux:navlist.item>`.

`resources/views/pages/notifications/⚡settings.blade.php`:
```blade
<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.notification_settings')] class extends Component {
    public bool $popups = false;

    public function mount(): void
    {
        $this->popups = auth()->user()->notification_popups;
    }

    public function updatedPopups(): void
    {
        auth()->user()->forceFill(['notification_popups' => $this->popups])->save();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('notifications.settings_link')" :subheading="__('notifications.settings_subheading')">
        <div class="grid gap-2" x-data="{ permission: 'Notification' in window ? Notification.permission : 'unsupported' }" data-popup-preference>
            <label class="flex items-start gap-3">
                <input
                    type="checkbox"
                    wire:model.live="popups"
                    x-on:change="if ($event.target.checked && permission === 'default') { Notification.requestPermission().then((result) => permission = result) }"
                    class="mt-1 size-4 accent-primary"
                    data-test="notification-popups-toggle"
                />
                <span>
                    <span class="block text-sm font-semibold">{{ __('notifications.popups_label') }}</span>
                    <span class="block text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.popups_help') }}</span>
                </span>
            </label>
            <p x-show="permission === 'denied'" x-cloak class="text-sm text-status-critical">{{ __('notifications.popups_blocked') }}</p>
            <p x-show="permission === 'unsupported'" x-cloak class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.popups_unsupported') }}</p>
        </div>
    </x-pages::settings.layout>
</section>
```

Layout `<body>` attribute becomes `data-notification-popups="{{ auth()->user()->notification_popups ? 'on' : 'off' }}"`.

`resources/js/notifications.js`:
```js
/**
 * The real-time half of the inbox.
 *
 * One private-channel subscription per page load (the bell renders twice —
 * mobile and desktop header — so subscribing from the component would double
 * every toast). A push refreshes every inbox view through a Livewire event,
 * shows a toast (important: 6s, critical: sticky) and, when the tab is hidden
 * and the user opted in, an OS popup. While the socket is not connected the
 * inbox is polled every 60s; returning to the tab always re-syncs.
 */
const POLL_INTERVAL_MS = 60_000;

let subscribed = false;
let pollTimer = null;

function refreshInbox() {
    window.Livewire?.dispatch('notification-received');
}

function showToast(payload) {
    if (payload.priority === 'info') {
        return;
    }

    const critical = payload.priority === 'critical';

    window.Flux?.toast({
        heading: payload.module_label,
        text: payload.text,
        variant: critical ? 'danger' : 'warning',
        duration: critical ? 0 : 6000,
    });
}

function showPopup(payload) {
    const optedIn = document.body.dataset.notificationPopups === 'on';

    if (!optedIn || !document.hidden || payload.priority === 'info') {
        return;
    }

    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return;
    }

    const popup = new Notification(payload.module_label, { body: payload.text, tag: payload.id });

    popup.onclick = () => {
        window.focus();
        window.location.assign(payload.url);
        popup.close();
    };
}

function startPolling() {
    if (pollTimer === null) {
        pollTimer = window.setInterval(refreshInbox, POLL_INTERVAL_MS);
    }
}

function stopPolling() {
    if (pollTimer !== null) {
        window.clearInterval(pollTimer);
        pollTimer = null;
    }
}

function subscribe() {
    const userId = document.body.dataset.userId;

    if (subscribed || !userId) {
        return;
    }

    subscribed = true;

    if (!window.Echo) {
        startPolling();

        return;
    }

    window.Echo.private(`App.Models.User.${userId}`).listen('.notification.pushed', (payload) => {
        refreshInbox();
        window.dispatchEvent(new CustomEvent('hc-notification', { detail: payload }));
        showToast(payload);
        showPopup(payload);
    });

    const connection = window.Echo.connector?.pusher?.connection;

    connection?.bind('state_change', ({ current }) => {
        if (current === 'connected') {
            stopPolling();
            refreshInbox();
        } else {
            startPolling();
        }
    });

    if (connection?.state !== 'connected') {
        startPolling();
    }
}

document.addEventListener('livewire:navigated', subscribe);

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        refreshInbox();
    }
});

document.addEventListener('alpine:init', () => {
    window.Alpine.data('notificationBell', () => ({
        open: false,
        pulse: false,

        init() {
            window.addEventListener('hc-notification', () => {
                this.pulse = true;
                window.setTimeout(() => (this.pulse = false), 1200);
            });
        },
    }));
});
```
`resources/js/app.js` — add `import './notifications';` after the `./echo` import.

Lang (en): `'settings_subheading' => 'Choose how notifications reach you.'`, `'popups_label' => 'Desktop popups'`, `'popups_help' => 'Show important and critical notifications as system popups while this tab is in the background.'`, `'popups_blocked' => 'Your browser is blocking notifications for this site. Allow them in the browser settings.'`, `'popups_unsupported' => 'This browser does not support desktop popups.'`.
(ar): `'settings_subheading' => 'اختر كيف تصلك الإشعارات.'`, `'popups_label' => 'النوافذ المنبثقة على سطح المكتب'`, `'popups_help' => 'عرض الإشعارات المهمة والحرجة كنوافذ منبثقة من النظام عندما تكون هذه الصفحة في الخلفية.'`, `'popups_blocked' => 'المتصفح يمنع الإشعارات لهذا الموقع. اسمح بها من إعدادات المتصفح.'`, `'popups_unsupported' => 'هذا المتصفح لا يدعم النوافذ المنبثقة.'`.
`titles.php`: `'notification_settings' => 'Notification settings'` / `'إعدادات الإشعارات'`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/Settings tests/Feature/NavigationTest.php` → PASS; `npm run build` → succeeds.
- [ ] **Step 5: Browser check** — with `npm run build`, `php artisan reverb:start` and `php artisan queue:work` running, render the dashboard (see `.ai/rules/pages.md` for previewing an auth page), open the bell in light, dark, RTL and at 375px; confirm the panel is fully on-screen and the counter reads correctly. Live push is proven by the Event tests; if a signed-in browser session is available, also change a task as another user and watch the toast. Share a screenshot.
- [ ] **Step 6: Stage** — `git add database/migrations/*notification_popups* app/Models/User.php resources/js resources/views/pages/settings routes/settings.php resources/views/layouts/app/sidebar.blade.php lang tests/Feature/Notifications/NotificationSettingsPageTest.php`

### Phase 2 close-out

- [ ] `php artisan test --compact tests/Feature/Notifications tests/Feature/Settings tests/Feature/NavigationTest.php tests/Feature/DashboardTest.php tests/Feature/LocalizationTest.php` → PASS.
- [ ] `vendor/bin/pint --dirty --format agent`.
- [ ] `record-rule` (glob `resources/js/**`): "notifications.js owns the single Echo subscription; components listen to the Livewire `notification-received` event and never subscribe themselves".
- [ ] Post progress update, continue to Phase 3.

---

# PHASE 3 — All modules' action events, no-account warning, badges, Activity tabs, global log

Delivers: every catalog row of spec §4 except Cities (interpretation 1); actor warning for assigned employees without an account; unread badges on sidebar modules, list rows and record headers; Activity tab/section on project, task, milestone, report, proposal, program and funding-agreement (transfers) pages; global activity log for Super Admin and General Manager.

Shared test helpers go in `tests/Pest.php` (factory-backed; they return models because these tests assert behaviour, not column defaults):
```php
/**
 * A user holding one role, optionally linked to an HR employee. Seeds the
 * access-control catalog first when it is missing.
 */
function userWithRole(string $role, ?int $employeeId = null): \App\Models\User
{
    if (! \App\Models\Permission::query()->exists()) {
        test()->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    return \App\Models\User::factory()->role($role)->create(['employee_id' => $employeeId]);
}

/**
 * Every notification a user received, as rendered English sentences.
 *
 * @return list<string>
 */
function sentencesFor(\App\Models\User $user): array
{
    $presenter = app(\App\Notifications\NotificationPresenter::class);

    return \App\Models\UserNotification::query()->forUser($user)->oldest()->get()
        ->map(fn (\App\Models\UserNotification $n): string => $presenter->sentence($n->data, 'en'))
        ->all();
}
```

### Task 3.1: Morph map, presenter and recorder extensions

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`, `tests/Feature/Models/MorphMapTest.php`, `app/Notifications/ActivityParameters.php`, `app/Notifications/NotificationPresenter.php`, `app/Notifications/SubjectLinks.php`, `config/notifications.php`, `tests/Pest.php`
- Test: `tests/Feature/Notifications/SubjectLinksTest.php`

**Interfaces — Produces:**
- Morph keys: `strategy`, `risk`, `indicator`, `plan_indicator`, `result`, `report`, `donor`, `donor_proposal`, `donor_report`, `transfer` (TransfersTracking), `account` (plus existing `annual_plan, proposal, project, milestone, program, department, user, task`).
- `ActivityParameters::typedChange(Activity $activity, string $field, mixed $value, int $side): mixed` (public; arrays become `', '`-joined strings); pass-through keys gain `baseline`, `value`.
- Presenter typed param `['localized_list' => list<array{en, ar}>]` → names joined with `, ` (en) / `، ` (ar).
- `SubjectLinks::routeFor` covers every morph key; `SubjectLinks::existing(array<string, list<int>> $idsByType): array<string, list<int>>` (one query per type).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorReport;
use App\Models\Risk;
use App\Notifications\NotificationPresenter;
use App\Notifications\SubjectLinks;

test('every subject type leads to its page', function (string $type, Closure $expected) {
    expect(app(SubjectLinks::class)->routeFor($type, 5))->toBe($expected());
})->with([
    'report' => ['report', fn () => route('reports.show', 5)],
    'donor' => ['donor', fn () => route('donors.show', 5)],
    'strategy' => ['strategy', fn () => route('strategies.show', 5)],
    'funding agreement' => ['donor_proposal', fn () => route('funding-agreements.show', 5)],
    'risk' => ['risk', fn () => route('risks.index')],
    'indicator' => ['indicator', fn () => route('indicators.index', ['inspect' => 5])],
    'target' => ['plan_indicator', fn () => route('targets.index')],
    'result' => ['result', fn () => route('results.index')],
    'transfer' => ['transfer', fn () => route('transfers.index')],
    'account' => ['account', fn () => route('admin.accounts.index')],
]);

test('a donor report leads to its funding agreement', function () {
    $donorReport = Activity::withoutRecording(fn () => DonorReport::factory()->create());

    expect(app(SubjectLinks::class)->routeFor('donor_report', $donorReport->id))
        ->toBe(route('funding-agreements.show', $donorReport->donor_proposal_id));
});

test('existing() keeps only records that are still there', function () {
    $risk = Activity::withoutRecording(fn () => Risk::factory()->create());
    $donor = Activity::withoutRecording(fn () => Donor::factory()->create());

    expect(app(SubjectLinks::class)->existing(['risk' => [$risk->id, 999999], 'donor' => [$donor->id]]))
        ->toBe(['risk' => [$risk->id], 'donor' => [$donor->id]]);
});

test('a list of names renders in the reader language', function () {
    $names = ['localized_list' => [['en' => 'Nour', 'ar' => 'نور'], ['en' => 'Rami', 'ar' => 'رامي']]];

    expect(app(NotificationPresenter::class)->param($names, 'en'))->toBe('Nour, Rami')
        ->and(app(NotificationPresenter::class)->param($names, 'ar'))->toBe('نور، رامي');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`AppServiceProvider::configureMorphMap()` map becomes (imports added):
```php
        Relation::enforceMorphMap([
            'annual_plan' => AnnualPlan::class,
            'proposal' => Proposal::class,
            'project' => Project::class,
            'milestone' => Milestone::class,
            'program' => Program::class,
            'department' => HrDepartment::class,
            'user' => User::class,
            'task' => Task::class,
            'strategy' => Strategy::class,
            'risk' => Risk::class,
            'indicator' => Indicator::class,
            'plan_indicator' => PlanIndicator::class,
            'result' => Result::class,
            'report' => Report::class,
            'donor' => Donor::class,
            'donor_proposal' => DonorProposal::class,
            'donor_report' => DonorReport::class,
            'transfer' => TransfersTracking::class,
            'account' => Account::class,
        ]);
```
Docblock gains: "Every model activity is recorded against (see App\Notifications\TracksActivity) is mapped, because `activities.subject_type` and `notifications.subject_type` store these keys." `MorphMapTest`: expected array updated to the list above; the "unmapped model" test uses `ReportFile` instead of `Strategy`; `getMorphClass()` assertions extended for `task`, `strategy`, `transfer`.

`ActivityParameters`: rename private `typed()` to public `typedChange()`; first line inside becomes:
```php
        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }
```
and `PassThrough` becomes `['subtask', 'file', 'note', 'role', 'baseline', 'value']`.

`NotificationPresenter::param()` match gains, before `default`:
```php
            isset($param['localized_list']) => implode(
                $locale === 'ar' ? '، ' : ', ',
                array_map(fn (array $names): string => $this->localized($names, $locale), $param['localized_list']),
            ),
```

`SubjectLinks::routeFor()` match becomes:
```php
        return match ($type) {
            'task' => route('tasks.show', $id),
            'milestone' => route('milestones.show', $id),
            'project' => route('projects.show', $id),
            'program' => route('programs.show', $id),
            'annual_plan' => route('annual-plans.show', $id),
            'strategy' => route('strategies.show', $id),
            'proposal' => route('proposals.show', $id),
            'report' => route('reports.show', $id),
            'donor' => route('donors.show', $id),
            'donor_proposal' => route('funding-agreements.show', $id),
            'donor_report' => ($agreementId = DonorReport::query()->whereKey($id)->value('donor_proposal_id')) === null ? null : route('funding-agreements.show', $agreementId),
            'risk' => route('risks.index'),
            'indicator' => route('indicators.index', ['inspect' => $id]),
            'plan_indicator' => route('targets.index'),
            'result' => route('results.index'),
            'transfer' => route('transfers.index'),
            'account' => route('admin.accounts.index'),
            'user' => route('admin.users.index'),
            default => null,
        };
```
and add:
```php
    /**
     * Which of the given records still exist — one query per type.
     *
     * @param  array<string, list<int>>  $idsByType
     * @return array<string, list<int>>
     */
    public function existing(array $idsByType): array
    {
        $existing = [];

        foreach ($idsByType as $type => $ids) {
            $class = Relation::getMorphedModel($type);
            $existing[$type] = $class === null ? [] : $class::query()->whereKey(array_values(array_unique($ids)))->pluck('id')->map(fn (int|string $id): int => (int) $id)->sort()->values()->all();
        }

        return $existing;
    }
```
Add the two helpers above to `tests/Pest.php`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/Models/MorphMapTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Providers/AppServiceProvider.php tests/Feature/Models/MorphMapTest.php app/Notifications tests/Pest.php tests/Feature/Notifications/SubjectLinksTest.php`

### Task 3.2: Milestones, projects (+ schedule baseline), programs, annual plans, strategies

**Files:**
- Modify: `app/Models/Milestone.php`, `Project.php`, `ScheduleBaseline.php`, `Program.php`, `AnnualPlan.php`, `Strategy.php`; `config/notifications.php`; `lang/{en,ar}/notifications.php`
- Create: `app/Notifications/Recipients/MilestoneRecipients.php`, `ProjectRecipients.php`, `ManagedRecipients.php`, `WatcherRecipients.php`, `StrategyRecipients.php`
- Test: `tests/Feature/Notifications/PlanningActivityTest.php`

**Interfaces — Produces:** `Project::programIdFor(mixed $projectId): ?int`, `Project::ownerEmployeeIdFor(mixed $projectId): ?int`; abstract `WatcherRecipients` (`abstract protected function roles(): array`). Events: `milestone.created|responsible_changed|status_changed|dates_changed|updated|deleted`, `project.created|status_changed|owner_changed|dates_changed|baseline_saved|updated|deleted`, `program.created|manager_changed|updated|deleted`, `annual_plan.created|manager_changed|updated|deleted`, `strategy.created|updated|deleted`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\MilestoneStatus;
use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\ScheduleBaseline;
use App\Models\Strategy;
use App\Models\UserNotification;

test('a new responsible person is told they are responsible, managers watch', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    $newEmployee = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali');
    $newResponsible = userWithRole('MEAL', $newEmployee);
    $watcher = userWithRole('Managers');
    $actor = userWithRole('Managers');
    $milestone = Activity::withoutRecording(fn () => Milestone::factory()->create([
        'project_id' => Project::factory()->create(['user_id' => $ownerEmployee])->id,
        'name' => 'Baseline survey',
    ]));

    $this->actingAs($actor);
    $milestone->update(['responsible_user_id' => $newEmployee]);

    expect(sentencesFor($newResponsible))->toBe([$actor->name.' made you responsible for “Baseline survey”'])
        ->and(UserNotification::query()->forUser($newResponsible)->sole()->priority)->toBe(NotificationPriority::Important)
        ->and(sentencesFor($owner))->toHaveCount(1)
        ->and(sentencesFor($watcher))->toHaveCount(1)
        ->and(sentencesFor($actor))->toBe([]);
});

test('a postponed milestone reaches the general manager as important', function () {
    $generalManager = userWithRole('General Manager');
    $milestone = Activity::withoutRecording(fn () => Milestone::factory()->create());

    $this->actingAs(userWithRole('Managers'));
    $milestone->update(['status' => MilestoneStatus::Postponed]);

    expect(UserNotification::query()->forUser($generalManager)->sole()->priority)->toBe(NotificationPriority::Important);
});

test('a routine milestone edit does not reach the general manager', function () {
    $generalManager = userWithRole('General Manager');
    $milestone = Activity::withoutRecording(fn () => Milestone::factory()->create());

    $this->actingAs(userWithRole('Managers'));
    $milestone->update(['name' => 'Renamed']);

    expect(sentencesFor($generalManager))->toBe([]);
});

test('approving a project is key, deleting it is critical', function () {
    $generalManager = userWithRole('General Manager');
    $superAdmin = userWithRole('Super Admin');
    $project = Activity::withoutRecording(fn () => Project::factory()->create(['name' => 'Winter Support']));

    $this->actingAs(userWithRole('Managers'));
    $project->update(['status' => ProjectStatus::Approved]);
    $this->travel(6)->minutes();
    $project->delete();

    $priorities = UserNotification::query()->forUser($generalManager)->oldest()->pluck('priority')->all();

    expect($priorities)->toBe([NotificationPriority::Important, NotificationPriority::Critical])
        ->and(sentencesFor($superAdmin))->toHaveCount(2);
});

test('a new project owner is told, and the schedule baseline is recorded on the project', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    $project = Activity::withoutRecording(fn () => Project::factory()->create(['name' => 'Winter Support']));

    $this->actingAs(userWithRole('Managers'));
    $project->update(['user_id' => $ownerEmployee]);
    ScheduleBaseline::query()->create(['project_id' => $project->id, 'name' => 'Baseline 1']);

    expect(Activity::query()->where('subject_type', 'project')->orderBy('id')->pluck('event')->all())
        ->toBe(['project.owner_changed', 'project.baseline_saved'])
        ->and(sentencesFor($owner)[0])->toContain('made you the owner of “Winter Support”');
});

test('program and annual plan managers hear about a manager change', function () {
    $employee = insertHrEmployee();
    $manager = userWithRole('Managers', $employee);
    $program = Activity::withoutRecording(fn () => Program::factory()->unassigned()->create());
    $plan = Activity::withoutRecording(fn () => AnnualPlan::factory()->create(['program_id' => $program->id]));

    $this->actingAs(userWithRole('Managers'));
    $program->update(['manager_id' => $employee]);
    $plan->update(['manager_id' => $employee]);

    expect(UserNotification::query()->forUser($manager)->pluck('module')->sort()->values()->all())->toBe(['annual-plans', 'programs']);
});

test('strategies are watched by managers', function () {
    $watcher = userWithRole('Managers');
    $strategy = Activity::withoutRecording(fn () => Strategy::factory()->create(['name' => 'Country Strategy']));

    $this->actingAs(userWithRole('Managers'));
    $strategy->update(['end_year' => 2030]);

    expect(sentencesFor($watcher))->toHaveCount(1);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement models**

Every model below: `implements TracksActivity`, `use ... RecordsActivity;` (imports `App\Concerns\RecordsActivity`, `App\Notifications\TracksActivity`). Methods:

`Project`:
```php
    public function activityModule(): string
    {
        return 'projects';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        $programId = $this->activityAttribute('program_id');

        return $programId === null ? null : (int) $programId;
    }

    public function activityEvents(): array
    {
        return [
            'status' => 'status_changed',
            'user_id' => 'owner_changed',
            'start_date' => 'dates_changed',
            'end_date' => 'dates_changed',
            'name' => 'updated',
        ];
    }

    public function activityEmployeeFields(): array
    {
        return ['user_id'];
    }

    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_values(array_filter([(int) $this->activityAttribute('user_id')]))];
    }

    /**
     * The program a project belongs to, without loading the project.
     */
    public static function programIdFor(mixed $projectId): ?int
    {
        $programId = $projectId === null ? null : static::query()->whereKey($projectId)->value('program_id');

        return $programId === null ? null : (int) $programId;
    }

    /**
     * The HR employee who owns a project, without loading it.
     */
    public static function ownerEmployeeIdFor(mixed $projectId): ?int
    {
        $ownerId = $projectId === null ? null : static::query()->whereKey($projectId)->value('user_id');

        return $ownerId === null ? null : (int) $ownerId;
    }
```

`Milestone`:
```php
    public function activityModule(): string
    {
        return 'milestones';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return Project::programIdFor($this->activityAttribute('project_id'));
    }

    public function activityEvents(): array
    {
        return [
            'status' => 'status_changed',
            'responsible_user_id' => 'responsible_changed',
            'planned_start_date' => 'dates_changed',
            'planned_end_date' => 'dates_changed',
            'name' => 'updated',
        ];
    }

    public function activityEmployeeFields(): array
    {
        return ['responsible_user_id'];
    }

    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_values(array_filter([
            (int) $this->activityAttribute('responsible_user_id'),
            Project::ownerEmployeeIdFor($this->activityAttribute('project_id')),
        ]))];
    }
```

`ScheduleBaseline` — `use RecordsParentActivity;`:
```php
    public function activityParent(): ?Project
    {
        return $this->project()->first();
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'project.baseline_saved' : null;
    }

    protected function parentActivityProperties(string $hook): array
    {
        return ['baseline' => (string) $this->name];
    }
```

`Program` (`activityProgramId` returns its own id):
```php
    public function activityModule(): string
    {
        return 'programs';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return $this->getKey() === null ? null : (int) $this->getKey();
    }

    public function activityEvents(): array
    {
        return ['manager_id' => 'manager_changed', 'strategy_id' => 'updated', 'name' => 'updated'];
    }

    public function activityEmployeeFields(): array
    {
        return ['manager_id'];
    }

    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_values(array_filter([(int) $this->activityAttribute('manager_id')]))];
    }
```

`AnnualPlan`:
```php
    public function activityModule(): string
    {
        return 'annual-plans';
    }

    public function activityLabel(): string
    {
        return Program::query()->whereKey($this->activityAttribute('program_id'))->value('name').' · '.$this->activityAttribute('year');
    }

    public function activityProgramId(): ?int
    {
        $programId = $this->activityAttribute('program_id');

        return $programId === null ? null : (int) $programId;
    }

    public function activityEvents(): array
    {
        return ['manager_id' => 'manager_changed', 'year' => 'updated'];
    }

    public function activityEmployeeFields(): array
    {
        return ['manager_id'];
    }

    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_values(array_filter([(int) $this->activityAttribute('manager_id')]))];
    }
```

`Strategy`:
```php
    public function activityModule(): string
    {
        return 'strategies';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    public function activityEvents(): array
    {
        return ['name' => 'updated', 'start_year' => 'updated', 'end_year' => 'updated'];
    }
```
(If `Program`, `AnnualPlan` or `Strategy` does not declare `strict_types`/`HasFactory` the same way, keep each file's existing header — only add the interface, trait and methods.)

- [ ] **Step 4: Recipient maps**

`app/Notifications/Recipients/WatcherRecipients.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;

/**
 * A module heard about only by the roles that watch it.
 */
abstract class WatcherRecipients extends ModuleRecipients
{
    /**
     * @return list<string>
     */
    abstract protected function roles(): array;

    public function watcherRoles(Activity $activity): array
    {
        return $this->roles();
    }
}
```

`StrategyRecipients`:
```php
final class StrategyRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Managers'];
    }
}
```

`MilestoneRecipients`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;

/**
 * Milestones: the responsible person and the project owner are direct;
 * Managers watch; postponed / cancelled is ★.
 */
final class MilestoneRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        $milestone = Milestone::query()->select(['id', 'project_id', 'responsible_user_id'])->find($activity->subject_id);

        $ids = $milestone === null
            ? $this->idsFromProperty($activity, 'employee_ids')
            : [(int) $milestone->responsible_user_id, (int) Project::ownerEmployeeIdFor($milestone->project_id)];

        return array_values(array_unique(array_filter([...$ids, (int) $this->changedFrom($activity, 'responsible_user_id')])));
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        $assigned = match ($activity->event) {
            'milestone.responsible_changed' => $this->changedTo($activity, 'responsible_user_id'),
            'milestone.created' => Milestone::query()->whereKey($activity->subject_id)->value('responsible_user_id'),
            default => null,
        };

        return $assigned === null ? [] : [(int) $assigned];
    }

    public function watcherRoles(Activity $activity): array
    {
        return ['Managers'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'milestone.status_changed'
            && in_array($this->changedTo($activity, 'status'), ['postponed', 'cancelled'], true);
    }
}
```

`ProjectRecipients`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Project;

/**
 * Projects: the owner is direct; Managers watch; approved / closed is ★,
 * and a deleted project is critical.
 */
final class ProjectRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        $owner = Project::ownerEmployeeIdFor($activity->subject_id);

        return array_values(array_unique(array_filter([
            $owner,
            ...$this->idsFromProperty($activity, 'employee_ids'),
            (int) $this->changedFrom($activity, 'user_id'),
        ])));
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        $assigned = match ($activity->event) {
            'project.owner_changed' => $this->changedTo($activity, 'user_id'),
            'project.created' => Project::ownerEmployeeIdFor($activity->subject_id),
            default => null,
        };

        return $assigned === null ? [] : [(int) $assigned];
    }

    public function watcherRoles(Activity $activity): array
    {
        return ['Managers'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'project.deleted'
            || ($activity->event === 'project.status_changed' && in_array($this->changedTo($activity, 'status'), ['approved', 'closed'], true));
    }

    public function priority(Activity $activity): NotificationPriority
    {
        return match (true) {
            $activity->event === 'project.deleted' => NotificationPriority::Critical,
            $this->isKeyEvent($activity) => NotificationPriority::Important,
            default => NotificationPriority::Info,
        };
    }
}
```

`ManagedRecipients` (programs and annual plans):
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Programs and annual plans: the manager is direct; Managers watch.
 */
final class ManagedRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        return array_values(array_unique(array_filter([
            $this->currentManager($activity),
            ...$this->idsFromProperty($activity, 'employee_ids'),
            (int) $this->changedFrom($activity, 'manager_id'),
        ])));
    }

    public function assignedEmployeeIds(Activity $activity): array
    {
        $assigned = match (true) {
            str_ends_with($activity->event, '.manager_changed') => $this->changedTo($activity, 'manager_id'),
            str_ends_with($activity->event, '.created') => $this->currentManager($activity),
            default => null,
        };

        return $assigned === null ? [] : [(int) $assigned];
    }

    public function watcherRoles(Activity $activity): array
    {
        return ['Managers'];
    }

    private function currentManager(Activity $activity): ?int
    {
        $class = Relation::getMorphedModel($activity->subject_type);
        $managerId = $class === null ? null : $class::query()->whereKey($activity->subject_id)->value('manager_id');

        return $managerId === null ? null : (int) $managerId;
    }
}
```

`config/notifications.php` `modules` gains:
```php
        'milestones' => MilestoneRecipients::class,
        'projects' => ProjectRecipients::class,
        'programs' => ManagedRecipients::class,
        'annual-plans' => ManagedRecipients::class,
        'strategies' => StrategyRecipients::class,
```

Lang — add to `events` (en):
```php
        'milestone' => [
            'created' => ':actor created the milestone “:subject”',
            'created_you' => ':actor made you responsible for the new milestone “:subject”',
            'responsible_changed' => ':actor made :to responsible for “:subject”',
            'responsible_changed_you' => ':actor made you responsible for “:subject”',
            'status_changed' => ':actor moved “:subject” from :from to :to',
            'dates_changed' => ':actor changed the dates of “:subject”',
            'updated' => ':actor updated the milestone “:subject”',
            'deleted' => ':actor deleted the milestone “:subject”',
        ],
        'project' => [
            'created' => ':actor created the project “:subject”',
            'created_you' => ':actor made you the owner of the new project “:subject”',
            'status_changed' => ':actor moved “:subject” from :from to :to',
            'owner_changed' => ':actor made :to the owner of “:subject”',
            'owner_changed_you' => ':actor made you the owner of “:subject”',
            'dates_changed' => ':actor changed the dates of “:subject”',
            'baseline_saved' => ':actor saved the schedule baseline “:baseline” for “:subject”',
            'updated' => ':actor updated the project “:subject”',
            'deleted' => ':actor deleted the project “:subject”',
        ],
        'program' => [
            'created' => ':actor added the program “:subject”',
            'created_you' => ':actor made you the manager of the new program “:subject”',
            'manager_changed' => ':actor made :to the manager of “:subject”',
            'manager_changed_you' => ':actor made you the manager of “:subject”',
            'updated' => ':actor updated the program “:subject”',
            'deleted' => ':actor deleted the program “:subject”',
        ],
        'annual_plan' => [
            'created' => ':actor created the annual plan “:subject”',
            'created_you' => ':actor made you the manager of the new annual plan “:subject”',
            'manager_changed' => ':actor made :to the manager of the annual plan “:subject”',
            'manager_changed_you' => ':actor made you the manager of the annual plan “:subject”',
            'updated' => ':actor updated the annual plan “:subject”',
            'deleted' => ':actor deleted the annual plan “:subject”',
        ],
        'strategy' => [
            'created' => ':actor created the strategy “:subject”',
            'updated' => ':actor updated the strategy “:subject”',
            'deleted' => ':actor deleted the strategy “:subject”',
        ],
```
(ar):
```php
        'milestone' => [
            'created' => 'أنشأ :actor المرحلة «:subject»',
            'created_you' => 'جعلك :actor مسؤولًا عن المرحلة الجديدة «:subject»',
            'responsible_changed' => 'جعل :actor :to مسؤولًا عن المرحلة «:subject»',
            'responsible_changed_you' => 'جعلك :actor مسؤولًا عن المرحلة «:subject»',
            'status_changed' => 'نقل :actor المرحلة «:subject» من :from إلى :to',
            'dates_changed' => 'غيّر :actor تواريخ المرحلة «:subject»',
            'updated' => 'حدّث :actor المرحلة «:subject»',
            'deleted' => 'حذف :actor المرحلة «:subject»',
        ],
        'project' => [
            'created' => 'أنشأ :actor المشروع «:subject»',
            'created_you' => 'جعلك :actor مالكًا للمشروع الجديد «:subject»',
            'status_changed' => 'نقل :actor المشروع «:subject» من :from إلى :to',
            'owner_changed' => 'جعل :actor :to مالكًا للمشروع «:subject»',
            'owner_changed_you' => 'جعلك :actor مالكًا للمشروع «:subject»',
            'dates_changed' => 'غيّر :actor تواريخ المشروع «:subject»',
            'baseline_saved' => 'حفظ :actor خط الأساس الزمني «:baseline» للمشروع «:subject»',
            'updated' => 'حدّث :actor المشروع «:subject»',
            'deleted' => 'حذف :actor المشروع «:subject»',
        ],
        'program' => [
            'created' => 'أضاف :actor البرنامج «:subject»',
            'created_you' => 'جعلك :actor مديرًا للبرنامج الجديد «:subject»',
            'manager_changed' => 'جعل :actor :to مديرًا للبرنامج «:subject»',
            'manager_changed_you' => 'جعلك :actor مديرًا للبرنامج «:subject»',
            'updated' => 'حدّث :actor البرنامج «:subject»',
            'deleted' => 'حذف :actor البرنامج «:subject»',
        ],
        'annual_plan' => [
            'created' => 'أنشأ :actor الخطة السنوية «:subject»',
            'created_you' => 'جعلك :actor مديرًا للخطة السنوية الجديدة «:subject»',
            'manager_changed' => 'جعل :actor :to مديرًا للخطة السنوية «:subject»',
            'manager_changed_you' => 'جعلك :actor مديرًا للخطة السنوية «:subject»',
            'updated' => 'حدّث :actor الخطة السنوية «:subject»',
            'deleted' => 'حذف :actor الخطة السنوية «:subject»',
        ],
        'strategy' => [
            'created' => 'أنشأ :actor الاستراتيجية «:subject»',
            'updated' => 'حدّث :actor الاستراتيجية «:subject»',
            'deleted' => 'حذف :actor الاستراتيجية «:subject»',
        ],
```

- [ ] **Step 5: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/Milestone*Test.php tests/Feature/Project*Test.php tests/Feature/Program*Test.php tests/Feature/AnnualPlan*Test.php tests/Feature/Strategy*Test.php tests/Feature/Gantt*Test.php tests/Feature/Models` → PASS.
- [ ] **Step 6: Stage** — `git add app/Models app/Notifications config/notifications.php lang tests/Feature/Notifications/PlanningActivityTest.php`

### Task 3.3: Risks, indicators (+ readings), targets, results

**Files:**
- Modify: `app/Models/Risk.php`, `Indicator.php`, `IndicatorProgress.php`, `PlanIndicator.php`, `Result.php`; `config/notifications.php`; `lang/{en,ar}/notifications.php`
- Create: `app/Notifications/Recipients/ProjectOwnedRecipients.php`, `RiskRecipients.php`, `IndicatorRecipients.php`, `ResultRecipients.php`
- Test: `tests/Feature/Notifications/MonitoringActivityTest.php`

**Interfaces — Produces:** `PlanIndicator::programIdFor(mixed $planIndicatorId): ?int`. Events: `risk.created|updated|happened|deleted`, `indicator.created|target_changed|progress_recorded|updated|deleted`, `plan_indicator.created|target_changed|updated|deleted`, `result.created|updated|deleted`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Result;
use App\Models\Risk;
use App\Models\UserNotification;

test('a risk that happens is critical for the owner, managers, meal and the top roles', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    $meal = userWithRole('MEAL');
    $generalManager = userWithRole('General Manager');
    $risk = Activity::withoutRecording(fn () => Risk::factory()->create([
        'project_id' => Project::factory()->create(['user_id' => $ownerEmployee])->id,
        'name' => 'Road closure',
    ]));

    $this->actingAs(userWithRole('Managers'));
    $risk->update(['happened' => true]);

    foreach ([$owner, $meal, $generalManager] as $recipient) {
        expect(UserNotification::query()->forUser($recipient)->sole()->priority)->toBe(NotificationPriority::Critical);
    }

    expect(sentencesFor($meal)[0])->toEndWith('marked the risk “Road closure” as happened');
});

test('a risk returning to potential is an ordinary update', function () {
    $risk = Activity::withoutRecording(fn () => Risk::factory()->create(['happened' => true]));

    $this->actingAs(userWithRole('Managers'));
    $risk->update(['happened' => false]);

    expect(Activity::query()->sole()->event)->toBe('risk.updated');
});

test('meal hears about indicator targets and readings; a reading is recorded on its indicator', function () {
    $meal = userWithRole('MEAL');
    $indicator = Activity::withoutRecording(fn () => Indicator::factory()->create(['name' => 'People reached', 'target' => '100']));
    $recorder = userWithRole('MEAL');

    $this->actingAs($recorder);
    $indicator->update(['target' => '150']);
    $this->travel(6)->minutes();
    IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '40', 'recorded_by' => $recorder->id]);

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['indicator.target_changed', 'indicator.progress_recorded'])
        ->and(sentencesFor($meal))->toHaveCount(2)
        ->and(sentencesFor($meal)[1])->toContain('recorded 40 for “People reached”');
});

test('a target change on a plan indicator reaches meal as a targets notification', function () {
    $meal = userWithRole('MEAL');
    $target = Activity::withoutRecording(fn () => PlanIndicator::factory()->create());

    $this->actingAs(userWithRole('Managers'));
    $target->update(['target' => '999.00']);

    expect(UserNotification::query()->forUser($meal)->sole()->module)->toBe('targets');
});

test('results are watched by meal', function () {
    $meal = userWithRole('MEAL');

    $this->actingAs(userWithRole('Managers'));
    Result::factory()->create(['statement' => 'Improved access to water']);

    expect(sentencesFor($meal))->toHaveCount(1);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement models** (same pattern: `implements TracksActivity`, `use RecordsActivity`)

`Risk`:
```php
    public function activityModule(): string
    {
        return 'risks';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return Project::programIdFor($this->activityAttribute('project_id'));
    }

    public function activityEvents(): array
    {
        return ['happened' => 'happened', 'name' => 'updated', 'impact' => 'updated', 'solution' => 'updated'];
    }

    /**
     * Only becoming a challenge is "happened"; going back is a plain update.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field === 'happened') {
            return (bool) $new ? 'happened' : 'updated';
        }

        return $this->activityEvents()[$field] ?? null;
    }

    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_values(array_filter([Project::ownerEmployeeIdFor($this->activityAttribute('project_id'))]))];
    }
```

`Indicator`:
```php
    public function activityModule(): string
    {
        return 'indicators';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        $programId = Result::query()->whereKey($this->activityAttribute('result_id'))->value('program_id');

        return $programId !== null ? (int) $programId : Project::programIdFor($this->activityAttribute('project_id'));
    }

    public function activityEvents(): array
    {
        return [
            'target' => 'target_changed',
            'name' => 'updated',
            'baseline' => 'updated',
            'unit' => 'updated',
            'measure' => 'updated',
            'frequency' => 'updated',
        ];
    }

    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_values(array_filter([Project::ownerEmployeeIdFor($this->activityAttribute('project_id'))]))];
    }
```

`IndicatorProgress` — `use RecordsParentActivity;`:
```php
    public function activityParent(): ?Indicator
    {
        return $this->indicator()->first();
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'indicator.progress_recorded' : null;
    }

    protected function parentActivityProperties(string $hook): array
    {
        return ['value' => rtrim(rtrim((string) $this->value, '0'), '.')];
    }
```

`PlanIndicator`:
```php
    public function activityModule(): string
    {
        return 'targets';
    }

    public function activityLabel(): string
    {
        return (string) Indicator::query()->whereKey($this->activityAttribute('indicator_id'))->value('name');
    }

    public function activityProgramId(): ?int
    {
        return static::programIdFor($this->getKey(), $this->activityAttribute('project_id'), $this->activityAttribute('source_type'), $this->activityAttribute('source_id'));
    }

    public function activityEvents(): array
    {
        return ['target' => 'target_changed', 'period' => 'updated', 'project_id' => 'updated', 'indicator_id' => 'updated'];
    }

    protected function activityPropertiesBeforeDelete(): array
    {
        return ['employee_ids' => array_values(array_filter([Project::ownerEmployeeIdFor($this->activityAttribute('project_id'))]))];
    }

    /**
     * The program a target rolls up to: its project's, else its annual plan's.
     * Pass the known columns to avoid a query; omit them to look the row up.
     */
    public static function programIdFor(mixed $planIndicatorId, mixed $projectId = null, mixed $sourceType = null, mixed $sourceId = null): ?int
    {
        if ($projectId === null && $sourceType === null) {
            $row = static::query()->select(['project_id', 'source_type', 'source_id'])->find($planIndicatorId);
            [$projectId, $sourceType, $sourceId] = [$row?->project_id, $row?->source_type, $row?->source_id];
        }

        return Project::programIdFor($projectId)
            ?? ($sourceType === 'annual_plan' ? (($id = AnnualPlan::query()->whereKey($sourceId)->value('program_id')) === null ? null : (int) $id) : null);
    }
```

`Result`:
```php
    public function activityModule(): string
    {
        return 'results';
    }

    public function activityLabel(): string
    {
        return Str::limit((string) $this->activityAttribute('statement'), 80);
    }

    public function activityProgramId(): ?int
    {
        $programId = $this->activityAttribute('program_id');

        return $programId === null ? null : (int) $programId;
    }

    public function activityEvents(): array
    {
        return ['statement' => 'updated', 'type' => 'updated', 'parent_id' => 'updated', 'program_id' => 'updated'];
    }
```

- [ ] **Step 4: Recipient maps**

`ProjectOwnedRecipients`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Records hanging off a project (risks, indicators, targets): the project
 * owner is direct.
 */
abstract class ProjectOwnedRecipients extends ModuleRecipients
{
    public function directEmployeeIds(Activity $activity): array
    {
        $class = Relation::getMorphedModel($activity->subject_type);
        $projectId = $class === null ? null : $class::query()->whereKey($activity->subject_id)->value('project_id');

        return array_values(array_unique(array_filter([
            Project::ownerEmployeeIdFor($projectId),
            ...$this->idsFromProperty($activity, 'employee_ids'),
        ])));
    }
}
```

`RiskRecipients`:
```php
final class RiskRecipients extends ProjectOwnedRecipients
{
    public function watcherRoles(Activity $activity): array
    {
        return ['Managers', 'MEAL'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'risk.happened';
    }

    public function priority(Activity $activity): NotificationPriority
    {
        return $this->isKeyEvent($activity) ? NotificationPriority::Critical : NotificationPriority::Info;
    }
}
```

`IndicatorRecipients` (indicators and targets):
```php
final class IndicatorRecipients extends ProjectOwnedRecipients
{
    public function watcherRoles(Activity $activity): array
    {
        return ['MEAL'];
    }
}
```

`ResultRecipients`:
```php
final class ResultRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['MEAL'];
    }
}
```

`config/notifications.php` `modules` gains `'risks' => RiskRecipients::class, 'indicators' => IndicatorRecipients::class, 'targets' => IndicatorRecipients::class, 'results' => ResultRecipients::class`.

Lang (en):
```php
        'risk' => [
            'created' => ':actor logged the risk “:subject”',
            'updated' => ':actor updated the risk “:subject”',
            'happened' => ':actor marked the risk “:subject” as happened',
            'deleted' => ':actor deleted the risk “:subject”',
        ],
        'indicator' => [
            'created' => ':actor created the indicator “:subject”',
            'target_changed' => ':actor changed the target of “:subject” from :from to :to',
            'progress_recorded' => ':actor recorded :value for “:subject”',
            'updated' => ':actor updated the indicator “:subject”',
            'deleted' => ':actor deleted the indicator “:subject”',
        ],
        'plan_indicator' => [
            'created' => ':actor set a target for “:subject”',
            'target_changed' => ':actor changed a target for “:subject” from :from to :to',
            'updated' => ':actor updated a target for “:subject”',
            'deleted' => ':actor deleted a target for “:subject”',
        ],
        'result' => [
            'created' => ':actor added the result “:subject”',
            'updated' => ':actor updated the result “:subject”',
            'deleted' => ':actor deleted the result “:subject”',
        ],
```
(ar):
```php
        'risk' => [
            'created' => 'سجّل :actor الخطر «:subject»',
            'updated' => 'حدّث :actor الخطر «:subject»',
            'happened' => 'أشار :actor إلى أن الخطر «:subject» قد وقع',
            'deleted' => 'حذف :actor الخطر «:subject»',
        ],
        'indicator' => [
            'created' => 'أنشأ :actor المؤشر «:subject»',
            'target_changed' => 'غيّر :actor مستهدف المؤشر «:subject» من :from إلى :to',
            'progress_recorded' => 'سجّل :actor القيمة :value للمؤشر «:subject»',
            'updated' => 'حدّث :actor المؤشر «:subject»',
            'deleted' => 'حذف :actor المؤشر «:subject»',
        ],
        'plan_indicator' => [
            'created' => 'حدّد :actor مستهدفًا للمؤشر «:subject»',
            'target_changed' => 'غيّر :actor مستهدفًا للمؤشر «:subject» من :from إلى :to',
            'updated' => 'حدّث :actor مستهدفًا للمؤشر «:subject»',
            'deleted' => 'حذف :actor مستهدفًا للمؤشر «:subject»',
        ],
        'result' => [
            'created' => 'أضاف :actor النتيجة «:subject»',
            'updated' => 'حدّث :actor النتيجة «:subject»',
            'deleted' => 'حذف :actor النتيجة «:subject»',
        ],
```

- [ ] **Step 5: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/Risk*Test.php tests/Feature/Indicator*Test.php tests/Feature/PlanIndicatorTest.php tests/Feature/Target*Test.php tests/Feature/Result*Test.php tests/Feature/Models` → PASS.
- [ ] **Step 6: Stage** — `git add app/Models app/Notifications config/notifications.php lang tests/Feature/Notifications/MonitoringActivityTest.php`

### Task 3.4: Reports (+ files)

**Files:**
- Modify: `app/Models/Report.php`, `app/Models/ReportFile.php`, `config/notifications.php`, `lang/{en,ar}/notifications.php`
- Create: `app/Notifications/Recipients/ReportRecipients.php`
- Test: `tests/Feature/Notifications/ReportActivityTest.php`

**Interfaces — Produces:** events `report.created|submitted|approved|status_changed|seen_by_meal|file_added|updated|deleted`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ReportStatus;
use App\Models\Activity;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\UserNotification;
use Livewire\Livewire;

test('submitting, approving and meal seeing a report are distinct events for the author and meal', function () {
    $author = userWithRole('Public Relations');
    $meal = userWithRole('MEAL');
    $report = Activity::withoutRecording(fn () => Report::factory()->create(['user_id' => $author->id]));

    $this->actingAs($author);
    $report->update(['status' => ReportStatus::Reviewed, 'submit_date' => now()->toDateString()]);

    $this->actingAs(userWithRole('MEAL'));
    $report->update(['status' => ReportStatus::Approved]);
    $report->update(['is_seen_by_meal' => true]);

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['report.submitted', 'report.approved', 'report.seen_by_meal'])
        ->and(UserNotification::query()->forUser($author)->count())->toBe(1)
        ->and(UserNotification::query()->forUser($meal)->count())->toBeGreaterThanOrEqual(1);
});

test('public relations watch reports written by public relations, not by others', function () {
    $prWatcher = userWithRole('Public Relations');
    $prAuthor = userWithRole('Public Relations');
    $mealAuthor = userWithRole('MEAL');
    [$prReport, $mealReport] = Activity::withoutRecording(fn () => [
        Report::factory()->create(['user_id' => $prAuthor->id]),
        Report::factory()->create(['user_id' => $mealAuthor->id]),
    ]);

    $this->actingAs(userWithRole('MEAL'));
    $prReport->update(['status' => ReportStatus::Reviewed]);
    $mealReport->update(['status' => ReportStatus::Reviewed]);

    expect(UserNotification::query()->forUser($prWatcher)->pluck('subject_id')->all())->toBe([$prReport->id]);
});

test('a file added to a report is recorded on the report', function () {
    $report = Activity::withoutRecording(fn () => Report::factory()->create());

    ReportFile::factory()->create(['report_id' => $report->id]);

    expect(Activity::query()->sole()->event)->toBe('report.file_added');
});

test('submitting from the report page records the submission', function () {
    $author = userWithRole('MEAL');
    $report = Activity::withoutRecording(fn () => Report::factory()->create(['user_id' => $author->id]));

    Livewire::actingAs($author)->test('pages::reports.show', ['report' => $report])->call('submitForReview');

    expect(Activity::query()->sole()->event)->toBe('report.submitted');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`Report`:
```php
    public function activityModule(): string
    {
        return 'reports';
    }

    public function activityLabel(): string
    {
        return (string) PlanIndicator::query()
            ->join('indicators', 'indicators.id', '=', 'plan_indicators.indicator_id')
            ->where('plan_indicators.id', $this->activityAttribute('plan_indicator_id'))
            ->value('indicators.name');
    }

    public function activityProgramId(): ?int
    {
        return PlanIndicator::programIdFor($this->activityAttribute('plan_indicator_id'));
    }

    public function activityEvents(): array
    {
        return [
            'status' => 'status_changed',
            'is_seen_by_meal' => 'seen_by_meal',
            'value' => 'updated',
            'planned_date' => 'updated',
        ];
    }

    /**
     * Draft → Reviewed is the submission; → Approved is the approval; seeing
     * a report only counts when it becomes seen.
     */
    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        return match (true) {
            $field === 'status' && $old === ReportStatus::Draft->value && $new === ReportStatus::Reviewed->value => 'submitted',
            $field === 'status' && $new === ReportStatus::Approved->value => 'approved',
            $field === 'is_seen_by_meal' => (bool) $new ? 'seen_by_meal' : null,
            default => $this->activityEvents()[$field] ?? null,
        };
    }

    protected function activityPropertiesBeforeDelete(): array
    {
        return ['author_id' => $this->activityAttribute('user_id')];
    }
```

`ReportFile` — `use RecordsParentActivity;`:
```php
    public function activityParent(): ?Report
    {
        return $this->report()->first();
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'report.file_added' : null;
    }

    protected function parentActivityProperties(string $hook): array
    {
        return ['file' => (string) ($this->original_name ?? basename((string) $this->path))];
    }
```

`ReportRecipients`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\Report;
use App\Models\User;

/**
 * Reports: the author is direct; MEAL watch all reports; Public Relations
 * and Media Team watch reports written by someone holding their own role.
 */
final class ReportRecipients extends ModuleRecipients
{
    private const TeamRoles = ['Public Relations', 'Media Team'];

    public function directUserIds(Activity $activity): array
    {
        $author = $this->authorId($activity);

        return $author === null ? [] : [$author];
    }

    public function watcherRoles(Activity $activity): array
    {
        $author = $this->authorId($activity);
        $authorRoles = $author === null ? [] : User::query()->with('roles:id,name')->find($author)?->roles->pluck('name')->all() ?? [];

        return ['MEAL', ...array_values(array_intersect(self::TeamRoles, $authorRoles))];
    }

    private function authorId(Activity $activity): ?int
    {
        $author = $activity->properties['author_id'] ?? Report::query()->whereKey($activity->subject_id)->value('user_id');

        return $author === null ? null : (int) $author;
    }
}
```
`config/notifications.php` `modules` gains `'reports' => ReportRecipients::class`.

Lang (en):
```php
        'report' => [
            'created' => ':actor started a report on “:subject”',
            'submitted' => ':actor submitted the report on “:subject” for review',
            'approved' => ':actor approved the report on “:subject”',
            'status_changed' => ':actor moved the report on “:subject” from :from to :to',
            'seen_by_meal' => ':actor (MEAL) has seen the report on “:subject”',
            'file_added' => ':actor added :file to the report on “:subject”',
            'updated' => ':actor updated the report on “:subject”',
            'deleted' => ':actor deleted the report on “:subject”',
        ],
```
(ar):
```php
        'report' => [
            'created' => 'بدأ :actor تقريرًا عن «:subject»',
            'submitted' => 'قدّم :actor التقرير عن «:subject» للمراجعة',
            'approved' => 'اعتمد :actor التقرير عن «:subject»',
            'status_changed' => 'نقل :actor التقرير عن «:subject» من :from إلى :to',
            'seen_by_meal' => 'اطّلع :actor (المتابعة والتقييم) على التقرير عن «:subject»',
            'file_added' => 'أضاف :actor الملف :file إلى التقرير عن «:subject»',
            'updated' => 'حدّث :actor التقرير عن «:subject»',
            'deleted' => 'حذف :actor التقرير عن «:subject»',
        ],
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/Report*Test.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Models/Report.php app/Models/ReportFile.php app/Notifications config/notifications.php lang tests/Feature/Notifications/ReportActivityTest.php`

### Task 3.5: Donors, proposals (+ status notes), funding agreements, donor reports, transfers, accounts

**Files:**
- Modify: `app/Models/Donor.php`, `Proposal.php`, `ProposalStatusNote.php`, `DonorProposal.php`, `DonorReport.php`, `TransfersTracking.php`, `Account.php`; `config/notifications.php`; `lang/{en,ar}/notifications.php`
- Create: `app/Notifications/Recipients/DonorRecipients.php`, `ProposalRecipients.php`, `FundingAgreementRecipients.php`, `TransferRecipients.php`, `AccountRecipients.php`
- Test: `tests/Feature/Notifications/FinanceActivityTest.php`

**Interfaces — Produces:** `DonorProposal::labelFor(mixed $id): string`. Events: `donor.created|updated|deleted`, `proposal.created|status_changed|status_note_added|updated|deleted`, `donor_proposal.created|updated|deleted`, `donor_report.created|submitted|updated|deleted`, `transfer.scheduled|received|failed|status_changed|updated|deleted`, `account.created|closed|updated|deleted`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ArStatus;
use App\Enums\NotificationPriority;
use App\Enums\ProposalStatus;
use App\Models\Account;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\TransfersTracking;
use App\Models\UserNotification;

test('a rejected proposal is critical for finance, the pr manager and the top roles', function () {
    $financial = userWithRole('Financial');
    $prManager = userWithRole('Public Relations Manager');
    $generalManager = userWithRole('General Manager');
    $proposal = Activity::withoutRecording(fn () => Proposal::factory()->create());

    $this->actingAs(userWithRole('Financial'));
    $proposal->update(['status' => ProposalStatus::Rejected]);

    foreach ([$financial, $prManager, $generalManager] as $recipient) {
        expect(UserNotification::query()->forUser($recipient)->sole()->priority)->toBe(NotificationPriority::Critical);
    }
});

test('an approved proposal is important; a status note is recorded on the proposal', function () {
    $financial = userWithRole('Financial');
    $proposal = Activity::withoutRecording(fn () => Proposal::factory()->create());

    $this->actingAs(userWithRole('Financial'));
    $proposal->update(['status' => ProposalStatus::Approved]);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'context' => 'Signed by donor']);

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['proposal.status_changed', 'proposal.status_note_added'])
        ->and(UserNotification::query()->forUser($financial)->sole()->priority)->toBe(NotificationPriority::Important);
});

test('a failed transfer is critical and a received one is not key', function () {
    $financial = userWithRole('Financial');
    $generalManager = userWithRole('General Manager');
    [$failing, $landing] = Activity::withoutRecording(fn () => TransfersTracking::factory()->count(2)->create());

    $this->actingAs(userWithRole('Financial'));
    $failing->update(['ar_status' => ArStatus::Failed]);
    $landing->update(['ar_status' => ArStatus::Received, 'amount_received' => '10', 'net_amount_received' => '10', 'receive_date' => now()->toDateString()]);

    expect(UserNotification::query()->forUser($generalManager)->sole()->subject_id)->toBe($failing->id)
        ->and(UserNotification::query()->forUser($financial)->count())->toBe(2);
});

test('scheduling a tranche, submitting a donor report and closing an account are recorded', function () {
    $agreement = Activity::withoutRecording(fn () => DonorProposal::factory()->create());
    $donorReport = Activity::withoutRecording(fn () => DonorReport::factory()->create(['donor_proposal_id' => $agreement->id]));
    $account = Activity::withoutRecording(fn () => Account::factory()->create());

    $this->actingAs(userWithRole('Financial'));
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id]);
    $donorReport->update(['submitted_on' => now()->toDateString()]);
    $account->close();

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['transfer.scheduled', 'donor_report.submitted', 'account.closed']);
});

test('a user holding several roles hears every stream they qualify for, whatever their active role', function () {
    $both = userWithRole('Financial');
    $both->assignRole('MEAL');
    $both->setActiveRole(\App\Models\Role::findByName('Financial')->id);
    [$risk, $proposal] = Activity::withoutRecording(fn () => [\App\Models\Risk::factory()->create(), Proposal::factory()->create()]);

    $this->actingAs(userWithRole('Managers'));
    $risk->update(['name' => 'Renamed risk']);
    $proposal->update(['name' => 'Renamed proposal']);

    expect(UserNotification::query()->forUser($both)->pluck('module')->sort()->values()->all())->toBe(['proposals', 'risks']);
});

test('media team members never hear about finance modules', function () {
    $media = userWithRole('Media Team');

    $this->actingAs(userWithRole('Financial'));
    Proposal::factory()->create();

    expect(UserNotification::query()->forUser($media)->count())->toBe(0);
});
```
(Check `Account::close()`'s real signature and factory state names in `AccountFactory`, `TransfersTrackingFactory`, `DonorReportFactory` before writing; adjust calls, not behaviour.)

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement models**

`Donor`:
```php
    public function activityModule(): string
    {
        return 'donors';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    public function activityEvents(): array
    {
        return ['name' => 'updated', 'contact_person' => 'updated', 'next_step' => 'updated', 'next_step_due_on' => 'updated'];
    }
```

`Proposal`:
```php
    public function activityModule(): string
    {
        return 'proposals';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    public function activityEvents(): array
    {
        return ['status' => 'status_changed', 'name' => 'updated', 'planned_date_begin' => 'updated', 'planned_date_end' => 'updated'];
    }
```

`ProposalStatusNote` — `use RecordsParentActivity;`:
```php
    public function activityParent(): ?Proposal
    {
        return $this->proposal()->first();
    }

    protected function parentActivityEvent(string $hook): ?string
    {
        return $hook === 'created' ? 'proposal.status_note_added' : null;
    }

    protected function parentActivityProperties(string $hook): array
    {
        return ['note' => Str::limit((string) $this->context, 120)];
    }
```

`DonorProposal`:
```php
    public function activityModule(): string
    {
        return 'funding-agreements';
    }

    public function activityLabel(): string
    {
        return static::labelFor($this->getKey());
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    public function activityEvents(): array
    {
        return ['budget' => 'updated', 'currency' => 'updated', 'project_num' => 'updated'];
    }

    /**
     * "Donor · Proposal", read without loading the agreement.
     */
    public static function labelFor(mixed $id): string
    {
        $row = static::query()
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->where('donor_proposal.id', $id)
            ->toBase()
            ->first(['donors.name as donor', 'proposals.name as proposal']);

        return $row === null ? '' : $row->donor.' · '.$row->proposal;
    }
```

`DonorReport`:
```php
    public function activityModule(): string
    {
        return 'funding-agreements';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('title');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    public function activityEvents(): array
    {
        return ['submitted_on' => 'submitted', 'due_on' => 'updated', 'title' => 'updated'];
    }

    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field === 'submitted_on') {
            return $new !== null ? 'submitted' : 'updated';
        }

        return $this->activityEvents()[$field] ?? null;
    }
```

`TransfersTracking`:
```php
    public function activityModule(): string
    {
        return 'transfers';
    }

    public function activityLabel(): string
    {
        return DonorProposal::labelFor($this->activityAttribute('donor_proposal_id'))
            .' · '.$this->activityAttribute('payment_amount').' '.$this->getRawOriginal('amount_transfered_currency', $this->getAttributes()['amount_transfered_currency'] ?? '');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    public function activityEvents(): array
    {
        return ['ar_status' => 'status_changed', 'scheduled_date' => 'updated', 'payment_amount' => 'updated'];
    }

    protected function activityCreatedEvent(): string
    {
        return 'transfer.scheduled';
    }

    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        return match (true) {
            $field === 'ar_status' && $new === ArStatus::Received->value => 'received',
            $field === 'ar_status' && $new === ArStatus::Failed->value => 'failed',
            default => $this->activityEvents()[$field] ?? null,
        };
    }
```

`Account`:
```php
    public function activityModule(): string
    {
        return 'accounts';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    public function activityEvents(): array
    {
        return ['is_active' => 'closed', 'name' => 'updated'];
    }

    protected function activityEventForChange(string $field, mixed $old, mixed $new): ?string
    {
        if ($field === 'is_active') {
            return (bool) $new ? 'updated' : 'closed';
        }

        return $this->activityEvents()[$field] ?? null;
    }
```

- [ ] **Step 4: Recipient maps**
```php
final class DonorRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Financial', 'Public Relations Manager'];
    }
}

final class FundingAgreementRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Financial', 'Public Relations Manager'];
    }
}

final class AccountRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Financial'];
    }
}

final class ProposalRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Financial', 'Public Relations Manager'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'proposal.status_changed'
            && in_array($this->changedTo($activity, 'status'), ['approved', 'rejected'], true);
    }

    public function priority(Activity $activity): NotificationPriority
    {
        return match (true) {
            $this->isKeyEvent($activity) && $this->changedTo($activity, 'status') === 'rejected' => NotificationPriority::Critical,
            $this->isKeyEvent($activity) => NotificationPriority::Important,
            default => NotificationPriority::Info,
        };
    }
}

final class TransferRecipients extends WatcherRecipients
{
    protected function roles(): array
    {
        return ['Financial'];
    }

    public function isKeyEvent(Activity $activity): bool
    {
        return $activity->event === 'transfer.failed';
    }

    public function priority(Activity $activity): NotificationPriority
    {
        return $this->isKeyEvent($activity) ? NotificationPriority::Critical : NotificationPriority::Info;
    }
}
```
(one file each under `app/Notifications/Recipients/`, each with `declare(strict_types=1)`, namespace, imports and a one-line class docblock naming its watchers.)

`config/notifications.php` `modules` gains `'donors' => DonorRecipients::class, 'proposals' => ProposalRecipients::class, 'funding-agreements' => FundingAgreementRecipients::class, 'transfers' => TransferRecipients::class, 'accounts' => AccountRecipients::class`.

Lang (en):
```php
        'donor' => [
            'created' => ':actor added the donor “:subject”',
            'updated' => ':actor updated the donor “:subject”',
            'deleted' => ':actor deleted the donor “:subject”',
        ],
        'proposal' => [
            'created' => ':actor created the proposal “:subject”',
            'status_changed' => ':actor moved “:subject” from :from to :to',
            'status_note_added' => ':actor added a status note to “:subject”: :note',
            'updated' => ':actor updated the proposal “:subject”',
            'deleted' => ':actor deleted the proposal “:subject”',
        ],
        'donor_proposal' => [
            'created' => ':actor recorded the funding agreement “:subject”',
            'updated' => ':actor updated the funding agreement “:subject”',
            'deleted' => ':actor deleted the funding agreement “:subject”',
        ],
        'donor_report' => [
            'created' => ':actor added the donor report “:subject”',
            'submitted' => ':actor submitted the donor report “:subject”',
            'updated' => ':actor updated the donor report “:subject”',
            'deleted' => ':actor deleted the donor report “:subject”',
        ],
        'transfer' => [
            'scheduled' => ':actor scheduled the tranche “:subject”',
            'received' => ':actor recorded the tranche “:subject” as received',
            'failed' => ':actor recorded the tranche “:subject” as failed',
            'status_changed' => ':actor moved the tranche “:subject” from :from to :to',
            'updated' => ':actor updated the tranche “:subject”',
            'deleted' => ':actor deleted the tranche “:subject”',
        ],
        'account' => [
            'created' => ':actor opened the account “:subject”',
            'closed' => ':actor closed the account “:subject”',
            'updated' => ':actor updated the account “:subject”',
            'deleted' => ':actor deleted the account “:subject”',
        ],
```
(ar):
```php
        'donor' => [
            'created' => 'أضاف :actor المانح «:subject»',
            'updated' => 'حدّث :actor المانح «:subject»',
            'deleted' => 'حذف :actor المانح «:subject»',
        ],
        'proposal' => [
            'created' => 'أنشأ :actor المقترح «:subject»',
            'status_changed' => 'نقل :actor المقترح «:subject» من :from إلى :to',
            'status_note_added' => 'أضاف :actor ملاحظة حالة إلى «:subject»: :note',
            'updated' => 'حدّث :actor المقترح «:subject»',
            'deleted' => 'حذف :actor المقترح «:subject»',
        ],
        'donor_proposal' => [
            'created' => 'سجّل :actor اتفاقية التمويل «:subject»',
            'updated' => 'حدّث :actor اتفاقية التمويل «:subject»',
            'deleted' => 'حذف :actor اتفاقية التمويل «:subject»',
        ],
        'donor_report' => [
            'created' => 'أضاف :actor تقرير المانح «:subject»',
            'submitted' => 'قدّم :actor تقرير المانح «:subject»',
            'updated' => 'حدّث :actor تقرير المانح «:subject»',
            'deleted' => 'حذف :actor تقرير المانح «:subject»',
        ],
        'transfer' => [
            'scheduled' => 'جدول :actor الدفعة «:subject»',
            'received' => 'سجّل :actor استلام الدفعة «:subject»',
            'failed' => 'سجّل :actor فشل الدفعة «:subject»',
            'status_changed' => 'نقل :actor الدفعة «:subject» من :from إلى :to',
            'updated' => 'حدّث :actor الدفعة «:subject»',
            'deleted' => 'حذف :actor الدفعة «:subject»',
        ],
        'account' => [
            'created' => 'فتح :actor الحساب «:subject»',
            'closed' => 'أغلق :actor الحساب «:subject»',
            'updated' => 'حدّث :actor الحساب «:subject»',
            'deleted' => 'حذف :actor الحساب «:subject»',
        ],
```

- [ ] **Step 5: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/Donor*Test.php tests/Feature/Proposal*Test.php tests/Feature/Funding*Test.php tests/Feature/Transfer*Test.php tests/Feature/Account*Test.php tests/Feature/DossierSnapshotTest.php tests/Feature/Admin tests/Feature/Models` → PASS.
- [ ] **Step 6: Stage** — `git add app/Models app/Notifications config/notifications.php lang tests/Feature/Notifications/FinanceActivityTest.php`

### Task 3.6: Users & roles

**Files:**
- Modify: `app/Models/User.php` (`TracksActivity`, `RecordsActivity`, role-change recording in `createFromHrAccount`, kill switch in `pruneInactiveHrAccounts`), `resources/views/pages/admin/⚡users.blade.php` (`saveUserAccess`), `config/notifications.php`, `lang/{en,ar}/notifications.php`
- Create: `app/Notifications/Recipients/UserRecipients.php`
- Test: `tests/Feature/Notifications/UserActivityTest.php`

**Interfaces — Produces:** `User::recordRoleChange(array $before, array $after): void`; events `user.created`, `user.role_changed`, `user.deleted`. User updates are not logged (`activityRecordsUpdates(): false` — logins refresh the name every time).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Activity;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use Livewire\Livewire;

test('changing roles tells the affected user even without user-management permission, and super admins', function () {
    $superAdmin = userWithRole('Super Admin');
    $affected = Activity::withoutRecording(fn () => userWithRole('Media Team'));

    Livewire::actingAs($superAdmin)->test('pages::admin.users')
        ->call('selectUser', $affected->id)
        ->set('userRoleIds', [Role::findByName('MEAL')->id])
        ->call('saveUserAccess');

    expect(Activity::query()->sole()->event)->toBe('user.role_changed')
        ->and(sentencesFor($affected)[0])->toContain('MEAL');
});

test('saving access without a role change records nothing', function () {
    $superAdmin = userWithRole('Super Admin');
    $affected = Activity::withoutRecording(fn () => userWithRole('MEAL'));

    Livewire::actingAs($superAdmin)->test('pages::admin.users')
        ->call('selectUser', $affected->id)
        ->call('saveUserAccess');

    expect(Activity::query()->count())->toBe(0);
});

test('a login refreshing the user name records nothing', function () {
    $user = Activity::withoutRecording(fn () => User::factory()->create());

    $user->forceFill(['name' => 'New Name'])->save();

    expect(Activity::query()->count())->toBe(0);
});

test('creating an account from hr records the account and its role; super admins hear about it', function () {
    $superAdmin = userWithRole('Super Admin');
    $hrUserId = insertHrUser(insertHrEmployee(), 'nour@example.org');

    $this->actingAs($superAdmin);
    User::createFromHrAccount(\App\Models\HrUser::query()->findOrFail($hrUserId), 'MEAL');

    expect(Activity::query()->orderBy('id')->pluck('event')->all())->toBe(['user.created', 'user.role_changed'])
        ->and(UserNotification::query()->forUser($superAdmin)->count())->toBe(0); // actor
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`User` — `implements PasskeyUser, HasLocalePreference, TracksActivity`, add `RecordsActivity` to its `use` list, and:
```php
    public function activityModule(): string
    {
        return 'access-management';
    }

    public function activityLabel(): string
    {
        return (string) $this->activityAttribute('name');
    }

    public function activityProgramId(): ?int
    {
        return null;
    }

    public function activityEvents(): array
    {
        return [];
    }

    /**
     * Logins refresh the name and HR ids on every sign-in; that is not news.
     */
    protected function activityRecordsUpdates(): bool
    {
        return false;
    }

    /**
     * Record a change of roles (role pivots fire no model events).
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    public function recordRoleChange(array $before, array $after): void
    {
        sort($before);
        sort($after);

        if ($before === $after) {
            return;
        }

        $this->recordActivity('user.role_changed', ['roles' => [$before, $after]], ['role' => $after === [] ? '—' : implode(', ', $after)]);
    }
```
In `createFromHrAccount()`, after `$user->setActiveRole($role->id);` add `$user->recordRoleChange([], [$role->name]);`. Wrap the body of `pruneInactiveHrAccounts()` in `Activity::withoutRecording(...)` (HR sync).

`admin/⚡users.blade.php` `saveUserAccess()` — around the existing `$user->roles()->sync(...)`:
```php
        $rolesBefore = $user->roles()->pluck('name')->all();
        $user->roles()->sync($validated['userRoleIds']);
        $user->recordRoleChange($rolesBefore, $user->roles()->pluck('name')->all());
```

`UserRecipients`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Recipients;

use App\Models\Activity;
use App\Models\User;

/**
 * Users & roles: the affected user is direct and hears about their own
 * account even without user-management permission; Super Admins watch.
 */
final class UserRecipients extends ModuleRecipients
{
    public function directUserIds(Activity $activity): array
    {
        return User::query()->whereKey($activity->subject_id)->exists() ? [$activity->subject_id] : [];
    }

    public function permissionExemptUserIds(Activity $activity): array
    {
        return [$activity->subject_id];
    }

    public function watcherRoles(Activity $activity): array
    {
        return ['Super Admin'];
    }
}
```
`config/notifications.php` `modules` gains `'access-management' => UserRecipients::class`.

Lang (en): `'user' => ['created' => ':actor created the account of :subject', 'role_changed' => ':actor changed the roles of :subject to :role', 'deleted' => ':actor removed the account of :subject']`. (ar): `'user' => ['created' => 'أنشأ :actor حساب :subject', 'role_changed' => 'غيّر :actor أدوار :subject إلى :role', 'deleted' => 'أزال :actor حساب :subject']`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/Admin tests/Feature/Auth tests/Feature/CreateUserCommandTest.php tests/Feature/SyncHrUsersCommandTest.php tests/Feature/Policies` → PASS.
- [ ] **Step 5: Stage** — `git add app/Models/User.php "resources/views/pages/admin/⚡users.blade.php" app/Notifications config/notifications.php lang tests/Feature/Notifications/UserActivityTest.php`

### Task 3.7: Warning the actor about employees with no account

**Files:**
- Modify: `app/Jobs/FanOutActivity.php`, `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/UnnotifiedWarningTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationPriority;
use App\Jobs\FanOutActivity;
use App\Models\Activity;
use App\Models\Task;
use App\Models\UserNotification;

test('assigning someone with no login warns the actor, naming them', function () {
    $actor = userWithRole('Managers');
    $withoutAccount = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali', firstNameAr: 'نور', lastNameAr: 'علي');
    $task = Activity::withoutRecording(fn () => Task::factory()->create(['title' => 'Plan']));

    $this->actingAs($actor);
    $task->taskAssignees()->create(['employee_id' => $withoutAccount]);

    $warning = UserNotification::query()->forUser($actor)->sole();

    expect($warning->priority)->toBe(NotificationPriority::Important)
        ->and(sentencesFor($actor))->toBe(['Nour Ali could not be notified about “Plan” because they have no login account'])
        ->and(app(\App\Notifications\NotificationPresenter::class)->sentence($warning->data, 'ar'))->toContain('نور علي');
});

test('the warning is not repeated when the fan-out is retried', function () {
    $actor = userWithRole('Managers');
    $task = Activity::withoutRecording(fn () => Task::factory()->create());

    $this->actingAs($actor);
    $task->taskAssignees()->create(['employee_id' => insertHrEmployee()]);
    dispatch_sync(new FanOutActivity(Activity::query()->value('id')));

    expect(UserNotification::query()->forUser($actor)->count())->toBe(1);
});

test('nobody is warned about system changes', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create());

    $task->taskAssignees()->create(['employee_id' => insertHrEmployee()]);

    expect(UserNotification::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement** — at the end of `FanOutActivity::handle()` (and change the early `return` on empty priorities so it falls through to the warning):
```php
        $this->warnActorAboutUnnotified($activity, $recipients, $employees);
```
with `EmployeeRecipients $employees` added to `handle()`'s parameters, and:
```php
    /**
     * Assigned people without a login account are skipped; the actor is told
     * who, so they can pass the news on themselves.
     */
    private function warnActorAboutUnnotified(Activity $activity, ResolvedRecipients $recipients, EmployeeRecipients $employees): void
    {
        if ($recipients->unnotifiedEmployeeIds === [] || $activity->actor_id === null) {
            return;
        }

        $actor = User::query()->find($activity->actor_id);

        if ($actor === null || UserNotification::query()->forUser($actor)->whereJsonContains('data->activity_ids', $activity->id)->exists()) {
            return;
        }

        $actor->notify(new AppNotification(new NotificationPayload(
            kind: NotificationKind::Action,
            module: $activity->module,
            priority: NotificationPriority::Important,
            key: 'notifications.warnings.not_notified',
            params: [
                'subject' => $activity->label(),
                'names' => ['localized_list' => array_values($employees->names($recipients->unnotifiedEmployeeIds))],
            ],
            subjectType: $activity->subject_type,
            subjectId: $activity->subject_id,
            actorId: $activity->actor_id,
            activityId: $activity->id,
        )));
    }
```
Lang (en): `'warnings' => ['not_notified' => ':names could not be notified about “:subject” because they have no login account']`; (ar): `'warnings' => ['not_notified' => 'تعذّر إشعار :names بشأن «:subject» لعدم امتلاكهم حساب دخول']`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications` → PASS.
- [ ] **Step 5: Stage** — `git add app/Jobs/FanOutActivity.php lang tests/Feature/Notifications/UnnotifiedWarningTest.php`

### Task 3.8: Badges — sidebar modules, list rows, record headers

**Files:**
- Create: `app/Notifications/UnreadSubjects.php`, `resources/views/components/notifications/module-badge.blade.php`
- Modify: `resources/views/components/navigation/sidebar.blade.php`, `resources/views/components/ui/detail-header.blade.php`; show pages `tasks/⚡show`, `milestones/⚡show`, `projects/⚡show`, `reports/⚡show`, `proposals/⚡show`, `programs/⚡show`, `funding-agreements/⚡show`; list pages `tasks/⚡index` (+ `_list-row`, `_list-card`), `milestones/⚡index` (+ `_list`), `projects/⚡index`, `reports/⚡index`, `proposals/⚡index`; `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/UnreadBadgesTest.php`

**Interfaces — Produces:** `UnreadSubjects::ids(User $user, string $subjectType): list<int>`, `::has(User $user, string $subjectType, int $subjectId): bool`; `<x-notifications.module-badge module="tasks" :count="3" />` (Alpine keeps it live from the `notification-counts` window event); `x-ui.detail-header` prop `unread` (bool, default false) renders `data-unread-dot`; list rows render `data-unread-dot` before the record name.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Notifications\UnreadSubjects;

function notifyAbout(User $user, string $module, string $type, int $id): void
{
    $user->notify(new AppNotification(new NotificationPayload(
        kind: NotificationKind::Action, module: $module, priority: NotificationPriority::Info,
        key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => 'x'],
        subjectType: $type, subjectId: $id,
    )));
}

test('unread subjects are listed per type', function () {
    $user = User::factory()->create();
    notifyAbout($user, 'tasks', 'task', 4);
    notifyAbout($user, 'tasks', 'task', 9);
    notifyAbout($user, 'projects', 'project', 4);

    expect(app(UnreadSubjects::class)->ids($user, 'task'))->toBe([4, 9])
        ->and(app(UnreadSubjects::class)->has($user, 'project', 4))->toBeTrue()
        ->and(app(UnreadSubjects::class)->has($user, 'project', 9))->toBeFalse();
});

test('the sidebar shows the unread count of each module', function () {
    $user = User::factory()->create();
    notifyAbout($user, 'tasks', 'task', 1);
    notifyAbout($user, 'tasks', 'task', 2);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertSeeHtml('data-module-badge="tasks"')
        ->assertSeeInOrder(['data-module-badge="tasks"', '>2<'], false);
});

test('list rows carry a dot only for records with unread notifications', function (string $route, string $type, Closure $make) {
    $viewer = userWithRole('Super Admin');
    [$flagged, $quiet] = Activity::withoutRecording(fn () => [$make(), $make()]);
    notifyAbout($viewer, 'tasks', $type, $flagged->id);

    $this->actingAs($viewer)->get(route($route))
        ->assertSeeHtml('data-unread-dot="'.$type.'-'.$flagged->id.'"')
        ->assertDontSeeHtml('data-unread-dot="'.$type.'-'.$quiet->id.'"');
})->with([
    'tasks' => ['tasks.index', 'task', fn () => Task::factory()->create()],
    'milestones' => ['milestones.index', 'milestone', fn () => Milestone::factory()->create()],
    'projects' => ['projects.index', 'project', fn () => Project::factory()->create()],
    'reports' => ['reports.index', 'report', fn () => Report::factory()->create()],
    'proposals' => ['proposals.index', 'proposal', fn () => Proposal::factory()->create()],
]);

test('record headers carry the dot only while the record has unread notifications', function (string $route, string $type, Closure $make) {
    $viewer = userWithRole('Super Admin');
    [$flagged, $quiet] = Activity::withoutRecording(fn () => [$make(), $make()]);
    notifyAbout($viewer, 'tasks', $type, $flagged->id);

    $this->actingAs($viewer)->get(route($route, $flagged))->assertSeeHtml('data-unread-dot');
    $this->actingAs($viewer)->get(route($route, $quiet))->assertDontSeeHtml('data-unread-dot');
})->with([
    'task' => ['tasks.show', 'task', fn () => Task::factory()->create()],
    'milestone' => ['milestones.show', 'milestone', fn () => Milestone::factory()->create()],
    'project' => ['projects.show', 'project', fn () => Project::factory()->create()],
    'report' => ['reports.show', 'report', fn () => Report::factory()->create()],
    'proposal' => ['proposals.show', 'proposal', fn () => Proposal::factory()->create()],
    'program' => ['programs.show', 'program', fn () => Program::factory()->create()],
    'funding agreement' => ['funding-agreements.show', 'donor_proposal', fn () => DonorProposal::factory()->create()],
]);
```
(Imports for this file: `DonorProposal`, `Milestone`, `Program`, `Proposal`, `Report` in addition to those shown.)

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Notifications/UnreadSubjects.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Models\UserNotification;

/**
 * Which records have unread notifications for a user, for the dots on list
 * rows and record headers. One query per list.
 */
final class UnreadSubjects
{
    /**
     * @return list<int>
     */
    public function ids(User $user, string $subjectType): array
    {
        return UserNotification::query()
            ->forUser($user)
            ->visible()
            ->whereNull('read_at')
            ->where('subject_type', $subjectType)
            ->distinct()
            ->orderBy('subject_id')
            ->pluck('subject_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();
    }

    public function has(User $user, string $subjectType, int $subjectId): bool
    {
        return UserNotification::query()
            ->forUser($user)
            ->visible()
            ->whereNull('read_at')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->exists();
    }
}
```

`resources/views/components/notifications/module-badge.blade.php`:
```blade
@props(['module', 'count' => 0])

<span
    x-data="{ count: {{ (int) $count }} }"
    x-on:notification-counts.window="count = $event.detail.counts.modules[@js($module)] ?? 0"
    x-show="count > 0"
    x-text="count > 99 ? '99+' : count"
    @if ((int) $count === 0) style="display: none" @endif
    class="ms-auto inline-flex min-w-5 items-center justify-center rounded-full bg-secondary-soft px-1.5 text-[10px] font-bold leading-5 tabular-nums text-secondary-dark in-data-current:bg-white/25 in-data-current:text-white in-data-flux-sidebar-collapsed-desktop:hidden dark:bg-white/10 dark:text-white"
    data-module-badge="{{ $module }}"
    aria-label="{{ trans_choice('notifications.unread_in_module', (int) $count, ['count' => (int) $count]) }}"
>{{ (int) $count > 99 ? '99+' : (int) $count }}</span>
```

Sidebar: in the top `@php` block add `$moduleCounts = auth()->check() ? app(\App\Notifications\UnreadCounts::class)->for(auth()->user())['modules'] : [];`. In every item whose route maps to a module, append the badge after the label text inside the item slot, e.g. tasks:
```blade
                {{ __('common.tasks') }}
                <x-notifications.module-badge module="tasks" :count="$moduleCounts['tasks'] ?? 0" />
```
Item → module: strategies→`strategies`, programs→`programs`, annual-plans→`annual-plans`, projects→`projects`, milestones→`milestones`, tasks→`tasks`, risks→`risks`, results→`results`, indicators→`indicators`, targets→`targets`, reports→`reports`, proposals→`proposals`, donors→`donors`, funding agreements→`funding-agreements`, transfers→`transfers`, accounts→`accounts`, user directory→`access-management`. Dashboard, calendar, timeline, executive summary, cities: no badge. Verify in the browser that the badge sits at the row end, hides when collapsed, and is legible on the active (red) row in light and dark.

`ui/detail-header.blade.php`: add `'unread' => false` to `@props`; directly after the title heading render:
```blade
@if ($unread)
    <span class="size-2.5 shrink-0 rounded-full bg-primary" data-unread-dot title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
@endif
```
Each of the seven show pages passes `:unread="app(\App\Notifications\UnreadSubjects::class)->has(auth()->user(), '{type}', {id})"` (`task`, `milestone`, `project`, `report`, `proposal`, `program`, `donor_proposal`).

List pages: add to each component
```php
    /**
     * Records with unread notifications for me, for the row dots.
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadIds(): array
    {
        return app(UnreadSubjects::class)->ids(auth()->user(), '{type}');
    }
```
and immediately before the record name in each row (desktop row and mobile card):
```blade
@if (in_array($task->id, $this->unreadIds, true))
    <span class="size-2 shrink-0 rounded-full bg-primary" data-unread-dot="task-{{ $task->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
@endif
```
(variable and type per page). Partials rendered inside the component (`_list-row`, `_list-card`, milestones `_list`) can read `$this->unreadIds`.

Lang (en): `'has_unread' => 'Has unread notifications'`, `'unread_in_module' => '{0} No unread notifications|{1} 1 unread notification|[2,*] :count unread notifications'`. (ar): `'has_unread' => 'يوجد إشعارات غير مقروءة'`, `'unread_in_module' => '{0} لا إشعارات غير مقروءة|{1} إشعار واحد غير مقروء|{2} إشعاران غير مقروءين|[3,10] :count إشعارات غير مقروءة|[11,*] :count إشعارًا غير مقروء'`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/NavigationTest.php tests/Feature/Task*Test.php tests/Feature/Milestone*Test.php tests/Feature/Project*Test.php tests/Feature/Report*Test.php tests/Feature/Proposal*Test.php tests/Feature/Program*Test.php tests/Feature/FundingAgreementTest.php tests/Feature/UiComponentsTest.php` → PASS (fix any query-count assertion broken by the new computed by checking whether the test measures render queries; the dot adds exactly one query per list page).
- [ ] **Step 5: Stage** — `git add app/Notifications/UnreadSubjects.php resources/views lang tests/Feature/Notifications/UnreadBadgesTest.php`

### Task 3.9: Activity tab / section

**Files:**
- Create: `app/Notifications/ActivityPresenter.php`, `resources/views/components/activity/⚡timeline.blade.php` (via `php artisan make:livewire activity.timeline --sfc --no-interaction`), `lang/en/activity.php`, `lang/ar/activity.php`
- Modify: `projects/⚡show` (new `activity` tab), `tasks/⚡show`, `milestones/⚡show`, `reports/⚡show`, `proposals/⚡show`, `programs/⚡show`, `funding-agreements/⚡show` (section at the end)
- Test: `tests/Feature/Notifications/ActivityTimelineTest.php`

**Interfaces — Produces:** `ActivityPresenter::sentence(Activity $a, ?string $locale = null): string`, `::changes(Activity $a, ?string $locale = null): list<array{field: string, from: string, to: string}>`; `<livewire:activity.timeline :subjects="['project' => [5]]" />` (`#[Locked] array $subjects`, `limit` 20, `loadMore()`).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

test('the timeline shows who did what and each field change', function () {
    $actor = User::factory()->create(['name' => 'Sami']);
    $task = Activity::withoutRecording(fn () => Task::factory()->create(['title' => 'Plan']));

    $this->actingAs($actor);
    $task->update(['status' => TaskStatus::InProgress, 'description' => 'Details']);

    Livewire::actingAs($actor)->test('activity.timeline', ['subjects' => ['task' => [$task->id]]])
        ->assertSeeText('Sami moved “Plan” from '.__('enums.task_status.todo').' to '.__('enums.task_status.in_progress'))
        ->assertSeeText(__('activity.fields.status'))
        ->assertSeeText(__('activity.fields.description'));
});

test('the timeline never shows activity of other records', function () {
    [$mine, $other] = Activity::withoutRecording(fn () => Task::factory()->count(2)->create());
    $mine->update(['title' => 'Mine']);
    $other->update(['title' => 'Not mine']);

    Livewire::actingAs(User::factory()->create())->test('activity.timeline', ['subjects' => ['task' => [$mine->id]]])
        ->assertSeeText('Mine')
        ->assertDontSeeText('Not mine');
});

test('the timeline with no subjects shows nothing', function () {
    Task::factory()->create(['title' => 'Stray']);

    Livewire::actingAs(User::factory()->create())->test('activity.timeline', ['subjects' => []])->assertDontSeeText('Stray');
});

test('the timeline loads more on demand', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create());
    foreach (range(1, 25) as $n) {
        $task->update(['title' => "Title {$n}"]);
    }

    Livewire::actingAs(User::factory()->create())->test('activity.timeline', ['subjects' => ['task' => [$task->id]]])
        ->assertSeeHtml('data-timeline-more')
        ->call('loadMore')
        ->assertSet('limit', 40)
        ->assertDontSeeHtml('data-timeline-more');
});

test('each record page carries its activity', function (string $route, Closure $record) {
    $this->actingAs(userWithRole('Super Admin'))
        ->get(route($route, Activity::withoutRecording($record)))
        ->assertSeeLivewire('activity.timeline');
})->with([
    'task' => ['tasks.show', fn () => Task::factory()->create()],
    'milestone' => ['milestones.show', fn () => Milestone::factory()->create()],
    'report' => ['reports.show', fn () => Report::factory()->create()],
    'proposal' => ['proposals.show', fn () => Proposal::factory()->create()],
    'program' => ['programs.show', fn () => Program::factory()->create()],
    'funding agreement' => ['funding-agreements.show', fn () => DonorProposal::factory()->create()],
]);

test('the project page has an activity tab', function () {
    $project = Activity::withoutRecording(fn () => Project::factory()->create());

    Livewire::actingAs(User::factory()->create())->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'activity')
        ->assertSeeLivewire('activity.timeline');
});

test('the funding agreement timeline includes its tranches', function () {
    $agreement = Activity::withoutRecording(fn () => DonorProposal::factory()->create());
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id]);
    $label = Activity::query()->sole()->label();

    $this->actingAs(userWithRole('Super Admin'))
        ->get(route('funding-agreements.show', $agreement))
        ->assertSeeText(__('notifications.events.transfer.scheduled', ['actor' => __('notifications.system'), 'subject' => $label]));
});
```
(A Super Admin viewer is used because the proposal and funding-agreement pages authorize through permissions.)

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Notifications/ActivityPresenter.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Activity;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Renders an activity row for the Activity tab and the global log, in the
 * reader's language, with the same sentences as notifications.
 */
final class ActivityPresenter
{
    public function __construct(
        private ActivityParameters $parameters,
        private NotificationPresenter $presenter,
    ) {}

    public function sentence(Activity $activity, ?string $locale = null): string
    {
        return $this->presenter->sentence([
            'key' => 'notifications.events.'.$activity->event,
            'params' => $this->parameters->for($activity),
            'count' => 1,
        ], $locale);
    }

    /**
     * @return list<array{field: string, from: string, to: string}>
     */
    public function changes(Activity $activity, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $rows = [];

        foreach ($activity->changes as $field => [$old, $new]) {
            $rows[] = [
                'field' => Lang::has("activity.fields.{$field}", $locale) ? (string) __("activity.fields.{$field}", [], $locale) : Str::headline($field),
                'from' => $this->presenter->param($this->parameters->typedChange($activity, $field, $old, 0), $locale),
                'to' => $this->presenter->param($this->parameters->typedChange($activity, $field, $new, 1), $locale),
            ];
        }

        return $rows;
    }
}
```

`resources/views/components/activity/⚡timeline.blade.php`:
```blade
<?php

use App\Models\Activity;
use App\Notifications\ActivityPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /**
     * Morph key => record ids whose activity this timeline shows.
     *
     * @var array<string, list<int>>
     */
    #[Locked]
    public array $subjects = [];

    public int $limit = 20;

    /**
     * One row more than shown, so "load more" needs no count query.
     *
     * @return Collection<int, Activity>
     */
    #[Computed]
    public function entries(): Collection
    {
        $subjects = array_filter($this->subjects);

        if ($subjects === []) {
            return collect();
        }

        return Activity::query()
            ->where(function (Builder $query) use ($subjects): void {
                foreach ($subjects as $type => $ids) {
                    $query->orWhere(fn (Builder $query) => $query->where('subject_type', $type)->whereIn('subject_id', $ids));
                }
            })
            ->with('actor:id,name')
            ->latest('id')
            ->limit($this->limit + 1)
            ->get();
    }

    public function loadMore(): void
    {
        $this->limit += 20;
    }
}; ?>

@php
    $presenter = app(ActivityPresenter::class);
@endphp

<div data-activity-timeline>
    @if ($this->entries->isEmpty())
        <p class="py-6 text-sm text-secondary-medium dark:text-secondary-soft">{{ __('activity.empty') }}</p>
    @else
        <ol class="relative ms-1.5 border-s border-secondary-soft dark:border-secondary-medium">
            @foreach ($this->entries->take($limit) as $activity)
                <li class="relative ps-5 pb-5 last:pb-0" wire:key="activity-{{ $activity->id }}">
                    <span @class([
                        'absolute -start-[5px] top-1.5 size-2.5 rounded-full ring-4 ring-white dark:ring-secondary-dark',
                        'bg-primary' => $activity->shouldNotify(),
                        'bg-secondary-light dark:bg-secondary-medium' => ! $activity->shouldNotify(),
                    ]) aria-hidden="true"></span>
                    <p class="text-sm font-semibold leading-snug">{{ $presenter->sentence($activity) }}</p>
                    <p class="mt-0.5 text-xs text-secondary-medium dark:text-secondary-soft">
                        <time datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->translatedFormat(__('formats.date')) }} · {{ $activity->created_at?->format('H:i') }}</time>
                    </p>
                    @if ($activity->changes !== [])
                        <dl class="mt-2 grid gap-1 text-xs">
                            @foreach ($presenter->changes($activity) as $change)
                                <div class="flex flex-wrap gap-x-1.5">
                                    <dt class="font-semibold text-secondary-dark dark:text-white">{{ $change['field'] }}:</dt>
                                    <dd class="text-secondary-medium dark:text-secondary-soft">
                                        <span class="line-through decoration-secondary-light">{{ $change['from'] }}</span>
                                        <span class="inline-block rtl:-scale-x-100" aria-hidden="true">→</span>
                                        <span class="sr-only">{{ __('activity.changed_to') }}</span>
                                        <span class="font-medium text-secondary-dark dark:text-white">{{ $change['to'] }}</span>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </li>
            @endforeach
        </ol>

        @if ($this->entries->count() > $limit)
            <button type="button" wire:click="loadMore" class="mt-3 text-sm font-semibold text-status-critical hover:underline" data-timeline-more>{{ __('activity.load_more') }}</button>
        @endif
    @endif
</div>
```

Embedding:
- `projects/⚡show`: `tabs()` gains `['key' => 'activity', 'label' => __('activity.title'), 'count' => null]`; template gains `@elseif ($tab === 'activity') <livewire:activity.timeline :subjects="['project' => [$this->project->id]]" :key="'project-activity-'.$this->project->id" />`.
- `tasks/⚡show`, `milestones/⚡show`, `reports/⚡show`, `proposals/⚡show`, `programs/⚡show`: at the end of the page's main column:
```blade
<section aria-labelledby="record-activity-heading" class="grid gap-3">
    <h2 id="record-activity-heading" class="text-base font-bold">{{ __('activity.title') }}</h2>
    <livewire:activity.timeline :subjects="['task' => [$this->task->id]]" :key="'task-activity-'.$this->task->id" />
</section>
```
(use each page's record accessor and morph key).
- `funding-agreements/⚡show`: same section with `:subjects="['donor_proposal' => [$agreementId], 'transfer' => $transferIds, 'donor_report' => $donorReportIds]"`, ids taken from the page's already-loaded tranches and donor reports (`->pluck('id')->all()`), keyed by the agreement id.

`lang/en/activity.php`:
```php
<?php

return [
    'title' => 'Activity',
    'empty' => 'Nothing has happened here yet.',
    'load_more' => 'Show older activity',
    'changed_to' => 'changed to',
    'log_title' => 'Activity log',
    'filters' => [
        'module' => 'Module',
        'all_modules' => 'All modules',
        'actor' => 'Person',
        'all_actors' => 'Everyone',
        'search' => 'Search activity',
        'from' => 'From',
        'to' => 'To',
    ],
    'open_record' => 'Open record',
    'record_gone' => 'Deleted',
    'fields' => [
        'title' => 'Title',
        'name' => 'Name',
        'description' => 'Description',
        'status' => 'Status',
        'priority' => 'Priority',
        'start_date' => 'Start date',
        'due_date' => 'Due date',
        'end_date' => 'End date',
        'completed_date' => 'Completed on',
        'progress_percentage' => 'Progress',
        'responsible_user_id' => 'Responsible',
        'planned_start_date' => 'Planned start',
        'planned_end_date' => 'Planned end',
        'actual_start_date' => 'Actual start',
        'actual_end_date' => 'Actual end',
        'user_id' => 'Owner',
        'manager_id' => 'Manager',
        'strategy_id' => 'Strategy',
        'year' => 'Year',
        'start_year' => 'Start year',
        'end_year' => 'End year',
        'happened' => 'Happened',
        'impact' => 'Impact',
        'solution' => 'Mitigation',
        'target' => 'Target',
        'baseline' => 'Baseline',
        'unit' => 'Unit',
        'measure' => 'Measure',
        'frequency' => 'Frequency',
        'period' => 'Period',
        'statement' => 'Statement',
        'type' => 'Type',
        'value' => 'Value',
        'planned_date' => 'Planned date',
        'submit_date' => 'Submitted on',
        'is_seen_by_meal' => 'Seen by MEAL',
        'budget' => 'Budget',
        'currency' => 'Currency',
        'project_num' => 'Project number',
        'submitted_on' => 'Submitted on',
        'due_on' => 'Due on',
        'ar_status' => 'Status',
        'scheduled_date' => 'Scheduled date',
        'payment_amount' => 'Amount',
        'is_active' => 'Active',
        'roles' => 'Roles',
        'contact_person' => 'Contact person',
        'next_step' => 'Next step',
        'next_step_due_on' => 'Next step due',
        'planned_date_begin' => 'Planned start',
        'planned_date_end' => 'Planned end',
    ],
];
```
`lang/ar/activity.php`:
```php
<?php

return [
    'title' => 'النشاط',
    'empty' => 'لم يحدث شيء هنا بعد.',
    'load_more' => 'عرض النشاط الأقدم',
    'changed_to' => 'تغيّر إلى',
    'log_title' => 'سجل النشاط',
    'filters' => [
        'module' => 'الوحدة',
        'all_modules' => 'كل الوحدات',
        'actor' => 'الشخص',
        'all_actors' => 'الجميع',
        'search' => 'البحث في النشاط',
        'from' => 'من',
        'to' => 'إلى',
    ],
    'open_record' => 'فتح السجل',
    'record_gone' => 'محذوف',
    'fields' => [
        'title' => 'العنوان',
        'name' => 'الاسم',
        'description' => 'الوصف',
        'status' => 'الحالة',
        'priority' => 'الأولوية',
        'start_date' => 'تاريخ البدء',
        'due_date' => 'تاريخ الاستحقاق',
        'end_date' => 'تاريخ الانتهاء',
        'completed_date' => 'تاريخ الإنجاز',
        'progress_percentage' => 'نسبة التقدم',
        'responsible_user_id' => 'المسؤول',
        'planned_start_date' => 'البدء المخطط',
        'planned_end_date' => 'الانتهاء المخطط',
        'actual_start_date' => 'البدء الفعلي',
        'actual_end_date' => 'الانتهاء الفعلي',
        'user_id' => 'المالك',
        'manager_id' => 'المدير',
        'strategy_id' => 'الاستراتيجية',
        'year' => 'السنة',
        'start_year' => 'سنة البدء',
        'end_year' => 'سنة الانتهاء',
        'happened' => 'وقع',
        'impact' => 'الأثر',
        'solution' => 'إجراء التخفيف',
        'target' => 'المستهدف',
        'baseline' => 'خط الأساس',
        'unit' => 'الوحدة',
        'measure' => 'نوع القياس',
        'frequency' => 'التواتر',
        'period' => 'الفترة',
        'statement' => 'النص',
        'type' => 'النوع',
        'value' => 'القيمة',
        'planned_date' => 'التاريخ المخطط',
        'submit_date' => 'تاريخ التقديم',
        'is_seen_by_meal' => 'اطّلعت عليه المتابعة والتقييم',
        'budget' => 'الميزانية',
        'currency' => 'العملة',
        'project_num' => 'رقم المشروع',
        'submitted_on' => 'تاريخ التقديم',
        'due_on' => 'تاريخ الاستحقاق',
        'ar_status' => 'الحالة',
        'scheduled_date' => 'التاريخ المجدول',
        'payment_amount' => 'المبلغ',
        'is_active' => 'نشط',
        'roles' => 'الأدوار',
        'contact_person' => 'جهة الاتصال',
        'next_step' => 'الخطوة التالية',
        'next_step_due_on' => 'موعد الخطوة التالية',
        'planned_date_begin' => 'البدء المخطط',
        'planned_date_end' => 'الانتهاء المخطط',
    ],
];
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/LocalizationTest.php tests/Feature/ProjectDetailTest.php tests/Feature/TaskDetailTest.php tests/Feature/MilestoneShowTest.php tests/Feature/ReportDetailTest.php tests/Feature/ProposalDetailTest.php tests/Feature/ProgramDetailTest.php tests/Feature/FundingAgreementTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/ActivityPresenter.php resources/views lang tests/Feature/Notifications/ActivityTimelineTest.php`

### Task 3.10: Global activity log

**Files:**
- Create: `resources/views/pages/activity/⚡index.blade.php`
- Modify: `app/Providers/AppServiceProvider.php` (gate), `routes/web.php`, `resources/views/components/navigation/sidebar.blade.php`, `lang/{en,ar}/titles.php`, `lang/{en,ar}/navigation.php`
- Test: `tests/Feature/Notifications/ActivityLogPageTest.php`

**Interfaces — Produces:** gate `view-activity-log` (Super Admin, General Manager); route `activity.index` (`GET activity`, `->can('view-activity-log')`); page filters `module`, `actor`, `search`, `from`, `to` (all `#[Url]`), paginated 50.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Activity;
use App\Models\Risk;
use App\Models\Task;
use Livewire\Livewire;

test('only super admins and the general manager can open the activity log', function (string $role, int $status) {
    $this->actingAs(userWithRole($role))->get(route('activity.index'))->assertStatus($status);
})->with([
    ['Super Admin', 200],
    ['General Manager', 200],
    ['Managers', 403],
    ['MEAL', 403],
]);

test('the log filters by module, person, text and date range', function () {
    $sami = userWithRole('Managers');
    $sami->forceFill(['name' => 'Sami'])->save();
    $rana = userWithRole('Managers');

    $this->actingAs($sami);
    Task::factory()->create(['title' => 'Water trucking']);
    $this->travel(2)->days();
    $this->actingAs($rana);
    Risk::factory()->create(['name' => 'Road closure']);

    $page = fn () => Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index');

    $page()->set('module', 'risks')->assertSeeText('Road closure')->assertDontSeeText('Water trucking');
    $page()->set('actor', (string) $sami->id)->assertSeeText('Water trucking')->assertDontSeeText('Road closure');
    $page()->set('search', 'trucking')->assertSeeText('Water trucking')->assertDontSeeText('Road closure');
    $page()->set('from', now()->toDateString())->assertSeeText('Road closure')->assertDontSeeText('Water trucking');
});

test('a deleted record shows as deleted instead of a link', function () {
    $task = Activity::withoutRecording(fn () => Task::factory()->create(['title' => 'Gone']));
    $this->actingAs(userWithRole('Managers'));
    $task->delete();

    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::activity.index')
        ->assertSeeText(__('activity.record_gone'))
        ->assertDontSeeHtml(route('tasks.show', $task->id));
});

test('the sidebar links the log only for those who may open it', function () {
    $this->actingAs(userWithRole('General Manager'))->get(route('dashboard'))->assertSee(route('activity.index'));
    $this->actingAs(userWithRole('Managers'))->get(route('dashboard'))->assertDontSee(route('activity.index'));
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`AppServiceProvider::boot()` gains `$this->configureGates();`:
```php
    /**
     * Abilities that are role-based rather than permission-based.
     */
    protected function configureGates(): void
    {
        Gate::define('view-activity-log', fn (User $user): bool => $user->hasAnyRole(['Super Admin', 'General Manager']));
    }
```
`routes/web.php`:
```php
    Route::livewire('activity', 'pages::activity.index')
        ->can('view-activity-log')
        ->name('activity.index');
```
Sidebar admin group, before the user directory item:
```blade
            @can('view-activity-log')
                <flux:sidebar.item
                    icon="clock"
                    :href="route('activity.index')"
                    :current="request()->routeIs('activity.*')"
                    :class="$navigationItemClasses"
                    wire:navigate
                >
                    {{ __('navigation.activity_log') }}
                </flux:sidebar.item>
            @endcan
```
`resources/views/pages/activity/⚡index.blade.php`:
```blade
<?php

use App\Models\Activity;
use App\Models\User;
use App\Notifications\ActivityPresenter;
use App\Notifications\NotificationPresenter;
use App\Notifications\SubjectLinks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.activity_log')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $module = '';

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['module', 'actor', 'search', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function activities(): LengthAwarePaginator
    {
        $term = trim($this->search);

        return Activity::query()
            ->with('actor:id,name')
            ->when($this->module !== '', fn (Builder $query) => $query->where('module', $this->module))
            ->when(ctype_digit($this->actor), fn (Builder $query) => $query->where('actor_id', (int) $this->actor))
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('event', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('properties', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->when($this->validDate($this->from), fn (Builder $query) => $query->where('created_at', '>=', CarbonImmutable::parse($this->from)->startOfDay()))
            ->when($this->validDate($this->to), fn (Builder $query) => $query->where('created_at', '<=', CarbonImmutable::parse($this->to)->endOfDay()))
            ->latest('id')
            ->paginate(50);
    }

    /**
     * Records on this page that still exist, one query per type.
     *
     * @return array<string, list<int>>
     */
    #[Computed]
    public function existing(): array
    {
        $idsByType = [];

        foreach ($this->activities->items() as $activity) {
            $idsByType[$activity->subject_type][] = $activity->subject_id;
        }

        return app(SubjectLinks::class)->existing($idsByType);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function actorOptions(): Collection
    {
        return User::query()
            ->whereIn('id', Activity::query()->select('actor_id')->whereNotNull('actor_id')->distinct())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}; ?>

@php
    $presenter = app(ActivityPresenter::class);
    $links = app(SubjectLinks::class);
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="activity-log-heading">
    <x-ui.page-header heading-id="activity-log-heading" :title="__('activity.log_title')" :count="$this->activities->total()">
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('activity.filters.search')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('activity.filters.module')" wire:model.live="module" :value="$module">
                <flux:select.option value="">{{ __('activity.filters.all_modules') }}</flux:select.option>
                @foreach (array_keys(config('notifications.modules')) as $option)
                    <flux:select.option :value="$option">{{ app(NotificationPresenter::class)->moduleLabel($option) }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('activity.filters.actor')" wire:model.live="actor" :value="$actor" searchable>
                <flux:select.option value="">{{ __('activity.filters.all_actors') }}</flux:select.option>
                @foreach ($this->actorOptions as $person)
                    <flux:select.option :value="(string) $person->id" :wire:key="'activity-actor-'.$person->id">{{ $person->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.input type="date" :label="__('activity.filters.from')" wire:model.live="from" />
            <x-ui.input type="date" :label="__('activity.filters.to')" wire:model.live="to" />
        </x-slot:filters>
    </x-ui.page-header>

    @if ($this->activities->isEmpty())
        <x-ui.empty-state icon="clock" :heading="__('activity.empty')" />
    @else
        <ol class="relative ms-1.5 border-s border-secondary-soft dark:border-secondary-medium">
            @foreach ($this->activities as $activity)
                @php
                    $exists = in_array($activity->subject_id, $this->existing[$activity->subject_type] ?? [], true);
                    $url = $exists ? $links->routeFor($activity->subject_type, $activity->subject_id) : null;
                @endphp
                <li class="relative grid gap-1 ps-5 pb-5 @3xl/directory:grid-cols-[minmax(0,1fr)_auto]" wire:key="log-{{ $activity->id }}">
                    <span class="absolute -start-[5px] top-1.5 size-2.5 rounded-full bg-primary ring-4 ring-white dark:ring-secondary-dark" aria-hidden="true"></span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold leading-snug">{{ $presenter->sentence($activity) }}</p>
                        <p class="mt-0.5 flex flex-wrap gap-x-2 text-xs text-secondary-medium dark:text-secondary-soft">
                            <time datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->translatedFormat(__('formats.date')) }} · {{ $activity->created_at?->format('H:i') }}</time>
                            <span aria-hidden="true">·</span>
                            <span>{{ app(NotificationPresenter::class)->moduleLabel($activity->module) }}</span>
                        </p>
                    </div>
                    <div class="text-xs">
                        @if ($url !== null)
                            <a href="{{ $url }}" wire:navigate class="font-semibold text-status-critical hover:underline">{{ __('activity.open_record') }}</a>
                        @elseif (! $exists)
                            <span class="text-secondary-medium dark:text-secondary-soft">{{ __('activity.record_gone') }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>

        <flux:pagination :paginator="$this->activities" />
    @endif
</section>
```
(Check `x-ui.input` supports `type="date"`; if not, use `flux:input type="date"` as other pages' date filters do.)

Lang: `titles.activity_log` = `'Activity log'` / `'سجل النشاط'`; `navigation.activity_log` = `'Activity log'` / `'سجل النشاط'`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/NavigationTest.php tests/Feature/LocalizationTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Providers/AppServiceProvider.php routes/web.php resources/views lang tests/Feature/Notifications/ActivityLogPageTest.php`

### Phase 3 close-out

- [ ] `php artisan test --compact tests/Feature/Notifications` plus every module test file touched in 3.2–3.10 (listed in each task) → PASS.
- [ ] `vendor/bin/pint --dirty --format agent`.
- [ ] `record-rule` (glob `app/Notifications/Recipients/**`): "One recipient map per module, registered in config/notifications.php `modules`; module knowledge (direct people, watchers, ★, priority) lives only there, never in RecipientResolver."
- [ ] Post progress update, continue to Phase 4.

---

# PHASE 4 — Smart alerts, ledger, working days, admin thresholds

Delivers: `notification_alert_ledger` + `notification_settings`; admin-editable thresholds with config defaults; working-day calendar; due-soon, overdue, escalation, stalled, off-track indicator and data-gap scanners; queued scanner runs on the scheduler; Admin → Notification settings page.

All scanners share one path: build a `NotificationPayload` (kind `alert`, group key null) → `AlertDispatcher` filters recipients through `RecipientFilter` (view permission, mutes for non-direct) → `AlertLedger::claim()` per recipient (unique rule + subject + stage + user) → `notify()`. Stage keys embed the date they depend on (due date, stall start, ISO week, year), so moving a due date or changing a status re-arms naturally; recovering indicators are re-armed by deleting their ledger rows.

### Task 4.1: Ledger and settings tables; NotificationSettings

**Files:**
- Create (via `php artisan make:migration --no-interaction`): `create_notification_alert_ledger_table`, `create_notification_settings_table`; `app/Notifications/Alerts/NotificationSettings.php`
- Modify: `config/notifications.php` (`defaults`), `app/Providers/AppServiceProvider.php` (`scoped` binding)
- Test: `tests/Feature/Notifications/NotificationSettingsTest.php`

**Interfaces — Produces:** `NotificationSettings::get(string $key): mixed`, `::all(): array<string, mixed>`, `::save(array $values): void` (cached forever under `notifications.settings`, forgotten on save). Default keys: `working_days` (ISO 1=Mon…7=Sun), `due_soon_days`, `overdue_repeat_days`, `escalate_manager_after_days`, `escalate_top_after_days`, `stalled_task_in_progress_days`, `stalled_task_under_review_days`, `stalled_report_draft_days`, `stalled_report_reviewed_days`, `stalled_transfer_pending_days`, `stalled_proposal_days`, `off_track_pace_percent`, `daily_brief_time`, `weekly_digest_time`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Notifications\Alerts\NotificationSettings;

test('settings fall back to the shipped defaults', function () {
    $settings = app(NotificationSettings::class);

    expect($settings->get('working_days'))->toBe([1, 2, 3, 4, 5])
        ->and($settings->get('due_soon_days'))->toBe([7, 3, 1])
        ->and($settings->get('overdue_repeat_days'))->toBe(3)
        ->and($settings->get('escalate_manager_after_days'))->toBe(5)
        ->and($settings->get('escalate_top_after_days'))->toBe(10)
        ->and($settings->get('daily_brief_time'))->toBe('07:30')
        ->and($settings->get('weekly_digest_time'))->toBe('08:00');
});

test('a saved value wins over the default and survives a fresh instance', function () {
    app(NotificationSettings::class)->save(['working_days' => [7, 1, 2, 3, 4], 'overdue_repeat_days' => 2]);

    $fresh = new NotificationSettings;

    expect($fresh->get('working_days'))->toBe([7, 1, 2, 3, 4])
        ->and($fresh->get('overdue_repeat_days'))->toBe(2)
        ->and($fresh->get('stalled_proposal_days'))->toBe(30)
        ->and($fresh->all()['overdue_repeat_days'])->toBe(2);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`create_notification_alert_ledger_table`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which scheduled alert stage already went to which user, so every alert
 * fires once per stage. The name is the spec's (a ledger, not a list of
 * ledgers). Rows are deleted to re-arm a rule for a record that recovered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_alert_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('rule', 50);
            $table->string('subject_type', 50);
            $table->unsignedBigInteger('subject_id');
            $table->string('stage', 100);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('fired_at');
            $table->timestamps();

            $table->unique(['rule', 'subject_type', 'subject_id', 'stage', 'user_id'], 'notification_alert_ledger_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_alert_ledger');
    }
};
```

`create_notification_settings_table`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-edited notification thresholds, working days and digest times. A key
 * with no row uses the default in config/notifications.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
```

`config/notifications.php` gains:
```php
    /*
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
        'stalled_task_in_progress_days' => 7,
        'stalled_task_under_review_days' => 3,
        'stalled_report_draft_days' => 7,
        'stalled_report_reviewed_days' => 5,
        'stalled_transfer_pending_days' => 7,
        'stalled_proposal_days' => 30,
        'off_track_pace_percent' => 80,
        'daily_brief_time' => '07:30',
        'weekly_digest_time' => '08:00',
    ],
```

`app/Notifications/Alerts/NotificationSettings.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin-edited values from notification_settings, falling back to
 * config('notifications.defaults'). Read through the cache; saving forgets it.
 */
final class NotificationSettings
{
    private const CacheKey = 'notifications.settings';

    public function get(string $key): mixed
    {
        $stored = $this->stored();

        return array_key_exists($key, $stored) ? $stored[$key] : config("notifications.defaults.{$key}");
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_replace(config('notifications.defaults'), $this->stored());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $now = now();

        DB::table('notification_settings')->upsert(
            array_map(fn (string $key): array => ['key' => $key, 'value' => json_encode($values[$key]), 'created_at' => $now, 'updated_at' => $now], array_keys($values)),
            ['key'],
            ['value', 'updated_at'],
        );

        Cache::forget(self::CacheKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        return Cache::rememberForever(self::CacheKey, fn (): array => DB::table('notification_settings')
            ->pluck('value', 'key')
            ->map(fn (string $value): mixed => json_decode($value, true))
            ->all());
    }
}
```
`AppServiceProvider::register()`: `$this->app->scoped(NotificationSettings::class);` (one instance per request/job, so queue workers never hold stale values).

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/NotificationSettingsTest.php tests/Feature/DatabaseEncodingTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add database/migrations/*notification_alert_ledger* database/migrations/*notification_settings* app/Notifications/Alerts config/notifications.php app/Providers/AppServiceProvider.php tests/Feature/Notifications/NotificationSettingsTest.php`

### Task 4.2: Working-day calendar

**Files:**
- Create: `app/Notifications/Alerts/WorkingCalendar.php`
- Modify: `app/Notifications/Inbox.php` (`next_week` → first working day of next week)
- Test: `tests/Feature/Notifications/WorkingCalendarTest.php`

**Interfaces — Produces:** `WorkingCalendar::isWorkingDay(CarbonInterface $day): bool`, `::workingDaysBetween(CarbonInterface $from, CarbonInterface $to): int` (working days `d` with `from < d <= to`, 0 when `to <= from`), `::lastWorkingDayOnOrBefore(CarbonInterface $day): CarbonImmutable`, `::firstWorkingDayOnOrAfter(CarbonInterface $day): CarbonImmutable`, `::addWorkingDays(CarbonInterface $from, int $days): CarbonImmutable`, `::firstWorkingDayOfNextWeek(CarbonInterface $now): CarbonImmutable`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WorkingCalendar;
use App\Notifications\Inbox;
use Carbon\CarbonImmutable;

// 2026-09-21 is a Monday.
function day(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date);
}

test('monday to friday is the default working week', function () {
    $calendar = app(WorkingCalendar::class);

    expect($calendar->isWorkingDay(day('2026-09-25')))->toBeTrue()
        ->and($calendar->isWorkingDay(day('2026-09-26')))->toBeFalse()
        ->and($calendar->isWorkingDay(day('2026-09-27')))->toBeFalse();
});

test('working days between two dates skip the weekend', function () {
    $calendar = app(WorkingCalendar::class);

    expect($calendar->workingDaysBetween(day('2026-09-25'), day('2026-09-28')))->toBe(1)
        ->and($calendar->workingDaysBetween(day('2026-09-23'), day('2026-09-30')))->toBe(5)
        ->and($calendar->workingDaysBetween(day('2026-09-21'), day('2026-10-19')))->toBe(20)
        ->and($calendar->workingDaysBetween(day('2026-09-23'), day('2026-09-23')))->toBe(0)
        ->and($calendar->workingDaysBetween(day('2026-09-24'), day('2026-09-23')))->toBe(0);
});

test('a date on a weekend moves back to the last working day', function () {
    $calendar = app(WorkingCalendar::class);

    expect($calendar->lastWorkingDayOnOrBefore(day('2026-09-27'))->toDateString())->toBe('2026-09-25')
        ->and($calendar->lastWorkingDayOnOrBefore(day('2026-09-24'))->toDateString())->toBe('2026-09-24')
        ->and($calendar->addWorkingDays(day('2026-09-25'), 2)->toDateString())->toBe('2026-09-29');
});

test('a custom working week is honoured', function () {
    app(NotificationSettings::class)->save(['working_days' => [7, 1, 2, 3, 4]]); // Sunday–Thursday
    $calendar = app(WorkingCalendar::class);

    expect($calendar->isWorkingDay(day('2026-09-27')))->toBeTrue()
        ->and($calendar->isWorkingDay(day('2026-09-25')))->toBeFalse()
        ->and($calendar->lastWorkingDayOnOrBefore(day('2026-09-26'))->toDateString())->toBe('2026-09-24')
        ->and($calendar->workingDaysBetween(day('2026-09-24'), day('2026-09-27')))->toBe(1);
});

test('snoozing until next week lands on its first working day', function () {
    app(NotificationSettings::class)->save(['working_days' => [2, 3, 4, 5, 6]]); // Tuesday–Saturday

    expect(Inbox::snoozeUntil('next_week', day('2026-09-23 10:00'))->format('Y-m-d H:i'))->toBe('2026-09-29 08:00');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement** `app/Notifications/Alerts/WorkingCalendar.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Counts in the admin-configured working days. Every deadline, overdue,
 * escalation and stall offset goes through here.
 */
final class WorkingCalendar
{
    public function __construct(private NotificationSettings $settings) {}

    public function isWorkingDay(CarbonInterface $day): bool
    {
        return in_array($day->dayOfWeekIso, $this->workingDays(), true);
    }

    /**
     * Working days d with $from < d <= $to, by whole calendar dates.
     */
    public function workingDaysBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($end->lte($start)) {
            return 0;
        }

        $calendarDays = (int) $start->diffInDays($end);
        $fullWeeks = intdiv($calendarDays, 7);
        $count = $fullWeeks * count($this->workingDays());

        for ($day = $start->addDays($fullWeeks * 7 + 1); $day->lte($end); $day = $day->addDay()) {
            if ($this->isWorkingDay($day)) {
                $count++;
            }
        }

        return $count;
    }

    public function lastWorkingDayOnOrBefore(CarbonInterface $day): CarbonImmutable
    {
        $candidate = CarbonImmutable::parse($day)->startOfDay();

        for ($step = 0; $step < 7 && ! $this->isWorkingDay($candidate); $step++) {
            $candidate = $candidate->subDay();
        }

        return $candidate;
    }

    public function firstWorkingDayOnOrAfter(CarbonInterface $day): CarbonImmutable
    {
        $candidate = CarbonImmutable::parse($day)->startOfDay();

        for ($step = 0; $step < 7 && ! $this->isWorkingDay($candidate); $step++) {
            $candidate = $candidate->addDay();
        }

        return $candidate;
    }

    public function addWorkingDays(CarbonInterface $from, int $days): CarbonImmutable
    {
        $day = CarbonImmutable::parse($from)->startOfDay();

        while ($days > 0) {
            $day = $day->addDay();

            if ($this->isWorkingDay($day)) {
                $days--;
            }
        }

        return $day;
    }

    public function firstWorkingDayOfNextWeek(CarbonInterface $now): CarbonImmutable
    {
        return $this->firstWorkingDayOnOrAfter(CarbonImmutable::parse($now)->startOfWeek()->addWeek());
    }

    /**
     * @return list<int> ISO weekday numbers; all seven when misconfigured empty
     */
    private function workingDays(): array
    {
        $days = array_values(array_map('intval', (array) $this->settings->get('working_days')));

        return $days === [] ? [1, 2, 3, 4, 5, 6, 7] : $days;
    }
}
```
`Inbox::snoozeUntil()`'s `next_week` arm becomes `'next_week' => app(WorkingCalendar::class)->firstWorkingDayOfNextWeek($now)->setTime(8, 0),` and its docblock drops "(Phase 4 …)".

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/WorkingCalendarTest.php tests/Feature/Notifications/InboxTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications tests/Feature/Notifications/WorkingCalendarTest.php`

### Task 4.3: Ledger and alert dispatcher

**Files:**
- Create: `app/Notifications/Alerts/AlertLedger.php`, `app/Notifications/Alerts/AlertDispatcher.php`, `app/Notifications/Alerts/DeadlineItem.php`, `app/Notifications/Alerts/Scanner.php`
- Test: `tests/Feature/Notifications/AlertDispatcherTest.php`

**Interfaces — Produces:**
- `AlertLedger::claim(string $rule, string $subjectType, int $subjectId, string $stage, int $userId): bool` (true only the first time), `::release(string $rule, string $subjectType, int $subjectId, ?string $stage = null): int`.
- `AlertDispatcher::send(string $rule, string $stage, NotificationPayload $payload, array $directUserIds, array $otherUserIds = [], array $exemptUserIds = []): int` (sent count); `::sendForItem(string $rule, string $stage, NotificationPayload $payload, DeadlineItem $item): int` (direct people, or the item's watcher roles when it has none).
- `DeadlineItem(string $subjectType, int $subjectId, string $module, string $label, CarbonImmutable $dueDate, list<int> $directUserIds, list<string> $watcherRoles = [])`.
- interface `Scanner { public function scan(CarbonImmutable $now): int; }`.
- `AlertDispatcher::payload(string $module, NotificationPriority $priority, string $key, array $params, string $subjectType, int $subjectId, array $actions = []): NotificationPayload` (kind Alert, group key null).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationPriority;
use App\Models\NotificationMute;
use App\Models\UserNotification;
use App\Notifications\Alerts\AlertDispatcher;
use App\Notifications\Alerts\AlertLedger;
use App\Notifications\Alerts\DeadlineItem;
use Carbon\CarbonImmutable;

function alertPayload(): \App\Notifications\NotificationPayload
{
    return app(AlertDispatcher::class)->payload('tasks', NotificationPriority::Info, 'notifications.alerts.due_soon.today', ['subject' => 'Plan'], 'task', 7);
}

test('a stage fires once per user and can be re-armed', function () {
    $user = userWithRole('Managers');
    $dispatcher = app(AlertDispatcher::class);

    expect($dispatcher->send('due_soon', 'd0:2026-09-30', alertPayload(), [$user->id]))->toBe(1)
        ->and($dispatcher->send('due_soon', 'd0:2026-09-30', alertPayload(), [$user->id]))->toBe(0);

    app(AlertLedger::class)->release('due_soon', 'task', 7);

    expect($dispatcher->send('due_soon', 'd0:2026-09-30', alertPayload(), [$user->id]))->toBe(1)
        ->and(UserNotification::query()->forUser($user)->count())->toBe(2);
});

test('alerts respect the view permission and mutes, except for direct people', function () {
    $direct = userWithRole('Managers');
    $mutedWatcher = userWithRole('Managers');
    $blind = userWithRole('Financial');
    NotificationMute::query()->create(['user_id' => $direct->id, 'module' => 'tasks']);
    NotificationMute::query()->create(['user_id' => $mutedWatcher->id, 'module' => 'tasks']);

    app(AlertDispatcher::class)->send('due_soon', 'd0:x', alertPayload(), [$direct->id], [$mutedWatcher->id, $blind->id]);

    expect(UserNotification::query()->pluck('notifiable_id')->all())->toBe([$direct->id]);
});

test('an item with nobody named goes to its watcher roles', function () {
    $financial = userWithRole('Financial');
    $item = new DeadlineItem('transfer', 3, 'transfers', 'Tranche', CarbonImmutable::today(), [], ['Financial']);
    $payload = app(AlertDispatcher::class)->payload('transfers', NotificationPriority::Info, 'notifications.alerts.due_soon.today', ['subject' => 'Tranche'], 'transfer', 3);

    expect(app(AlertDispatcher::class)->sendForItem('due_soon', 'd0', $payload, $item))->toBe(1)
        ->and(UserNotification::query()->forUser($financial)->sole()->kind->value)->toBe('alert');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Notifications/Alerts/Scanner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Carbon\CarbonImmutable;

/**
 * One scheduled alert rule. Returns how many notifications it sent.
 */
interface Scanner
{
    public function scan(CarbonImmutable $now): int;
}
```

`app/Notifications/Alerts/DeadlineItem.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Carbon\CarbonImmutable;

/**
 * One open item with a date, as the deadline scanners see it.
 */
final readonly class DeadlineItem
{
    /**
     * @param  list<int>  $directUserIds  people named on the item
     * @param  list<string>  $watcherRoles  who hears when nobody is named
     */
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $module,
        public string $label,
        public CarbonImmutable $dueDate,
        public array $directUserIds,
        public array $watcherRoles = [],
    ) {}
}
```

`app/Notifications/Alerts/AlertLedger.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Illuminate\Support\Facades\DB;

/**
 * The once-per-stage memory of scheduled alerts. The unique key makes a
 * claim atomic, so overlapping scanner runs can never double-send.
 */
final class AlertLedger
{
    public function claim(string $rule, string $subjectType, int $subjectId, string $stage, int $userId): bool
    {
        $now = now();

        return DB::table('notification_alert_ledger')->insertOrIgnore([
            'rule' => $rule,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'stage' => $stage,
            'user_id' => $userId,
            'fired_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }

    /**
     * Re-arm a rule for a record (every stage, or one).
     */
    public function release(string $rule, string $subjectType, int $subjectId, ?string $stage = null): int
    {
        return DB::table('notification_alert_ledger')
            ->where('rule', $rule)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->when($stage !== null, fn ($query) => $query->where('stage', $stage))
            ->delete();
    }
}
```

`app/Notifications/Alerts/AlertDispatcher.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use App\Notifications\RecipientResolver;

/**
 * The shared delivery path of every scheduled alert: filter, claim the
 * ledger stage per person, notify.
 */
final class AlertDispatcher
{
    public function __construct(
        private RecipientFilter $filter,
        private RecipientResolver $resolver,
        private AlertLedger $ledger,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @param  list<array<string, mixed>>  $actions
     */
    public function payload(string $module, NotificationPriority $priority, string $key, array $params, string $subjectType, int $subjectId, array $actions = []): NotificationPayload
    {
        return new NotificationPayload(
            kind: NotificationKind::Alert,
            module: $module,
            priority: $priority,
            key: $key,
            params: $params,
            subjectType: $subjectType,
            subjectId: $subjectId,
            actions: $actions,
        );
    }

    /**
     * @param  array<int, int>  $directUserIds
     * @param  array<int, int>  $otherUserIds
     * @param  array<int, int>  $exemptUserIds
     */
    public function send(string $rule, string $stage, NotificationPayload $payload, array $directUserIds, array $otherUserIds = [], array $exemptUserIds = []): int
    {
        $recipients = $this->filter->apply([...$directUserIds, ...$otherUserIds], $payload->module, null, $directUserIds, $exemptUserIds);
        $sent = 0;

        foreach (User::query()->whereIn('id', $recipients)->get() as $user) {
            if (! $this->ledger->claim($rule, (string) $payload->subjectType, (int) $payload->subjectId, $stage, $user->id)) {
                continue;
            }

            $user->notify(new AppNotification($payload));
            $sent++;
        }

        return $sent;
    }

    /**
     * Direct people when the item names any, otherwise its watcher roles.
     */
    public function sendForItem(string $rule, string $stage, NotificationPayload $payload, DeadlineItem $item): int
    {
        return $item->directUserIds !== []
            ? $this->send($rule, $stage, $payload, $item->directUserIds)
            : $this->send($rule, $stage, $payload, [], $this->resolver->usersWithRoles($item->watcherRoles));
    }
}
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/AlertDispatcherTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/Alerts tests/Feature/Notifications/AlertDispatcherTest.php`

### Task 4.4: Deadline catalog (the seven dated items)

**Files:**
- Create: `app/Notifications/Alerts/DeadlineCatalog.php`
- Test: `tests/Feature/Notifications/DeadlineCatalogTest.php`

**Interfaces — Produces:** `DeadlineCatalog::programIdFor(DeadlineItem $item): ?int`; `DeadlineCatalog::items(CarbonImmutable $dueOnOrBefore): Collection<int, DeadlineItem>` — open items only (interpretation 8): task `due_date` (not done; direct = assignees' users + creator), milestone `planned_end_date` (not reached/cancelled; responsible + project owner), report `planned_date` (draft; author), donor report `due_on` (not submitted; watchers Financial + Public Relations Manager), proposal `planned_date_end` (not completed/rejected; same watchers), transfer `scheduled_date` (pending and not sent; watchers Financial), project `end_date` (open status; owner). Employee → user mapping is batched once per call.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Notifications\Alerts\DeadlineCatalog;
use Carbon\CarbonImmutable;

test('the catalog lists open dated items with their direct people and closes the finished ones', function () {
    $employee = insertHrEmployee();
    $assignee = userWithRole('Managers', $employee);

    Activity::withoutRecording(function () use ($employee): void {
        $open = Task::factory()->create(['due_date' => '2026-09-30', 'title' => 'Open task']);
        $open->taskAssignees()->delete();
        $open->taskAssignees()->create(['employee_id' => $employee]);
        Task::factory()->create(['due_date' => '2026-09-30', 'status' => TaskStatus::Done, 'title' => 'Done task']);
        Task::factory()->create(['due_date' => '2026-12-31', 'title' => 'Far task']);
        Milestone::factory()->create(['planned_end_date' => '2026-09-29', 'status' => MilestoneStatus::Cancelled]);
        Report::factory()->reviewed()->create(['planned_date' => '2026-09-29']);
        DonorReport::factory()->create(['due_on' => '2026-09-28', 'title' => 'Interim report']);
        Proposal::factory()->create(['planned_date_end' => '2026-09-28']);
        TransfersTracking::factory()->create(['scheduled_date' => '2026-09-28']);
        Project::factory()->status(ProjectStatus::Closed)->create(['end_date' => '2026-09-28']);
    });

    $items = app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2026-10-15'));
    $labels = $items->pluck('label')->all();

    expect($labels)->toContain('Open task', 'Interim report')
        ->not->toContain('Done task', 'Far task')
        ->and($items->firstWhere('label', 'Open task')->directUserIds)->toContain($assignee->id)
        ->and($items->firstWhere('label', 'Interim report')->watcherRoles)->toBe(['Financial', 'Public Relations Manager'])
        ->and($items->where('subjectType', 'milestone'))->toBeEmpty()
        ->and($items->where('subjectType', 'report'))->toBeEmpty()
        ->and($items->where('subjectType', 'project'))->toBeEmpty()
        ->and($items->where('subjectType', 'proposal'))->toHaveCount(1)
        ->and($items->where('subjectType', 'transfer'))->toHaveCount(1);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement** `app/Notifications/Alerts/DeadlineCatalog.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\ArStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Notifications\EmployeeRecipients;
use App\Notifications\TracksActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;

/**
 * Every open item that carries a date the deadline scanners watch. Closed
 * states follow the app's own definitions (Report::isOverdue(),
 * TransfersTracking::state(), ProjectStatus::isOpen()).
 */
final class DeadlineCatalog
{
    private const FinanceWatchers = ['Financial', 'Public Relations Manager'];

    public function __construct(private EmployeeRecipients $employees) {}

    /**
     * @return Collection<int, DeadlineItem>
     */
    public function items(CarbonImmutable $dueOnOrBefore): Collection
    {
        $limit = $dueOnOrBefore->toDateString();

        return collect([
            ...$this->tasks($limit),
            ...$this->milestones($limit),
            ...$this->reports($limit),
            ...$this->donorReports($limit),
            ...$this->proposals($limit),
            ...$this->transfers($limit),
            ...$this->projects($limit),
        ]);
    }

    /**
     * @return list<DeadlineItem>
     */
    private function tasks(string $limit): array
    {
        $tasks = Task::query()
            ->select(['id', 'title', 'due_date', 'created_by'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $limit)
            ->where('status', '!=', TaskStatus::Done->value)
            ->with('taskAssignees:id,task_id,employee_id')
            ->get();

        $users = $this->employees->usersFor($tasks->flatMap(fn (Task $task) => $task->taskAssignees->pluck('employee_id'))->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $tasks->map(fn (Task $task): DeadlineItem => new DeadlineItem(
            'task', $task->id, 'tasks', $task->title, $task->due_date,
            $this->unique([...$task->taskAssignees->map(fn ($assignee): ?int => $users[(int) $assignee->employee_id] ?? null)->all(), $task->created_by]),
        ))->all();
    }

    /**
     * @return list<DeadlineItem>
     */
    private function milestones(string $limit): array
    {
        $milestones = Milestone::query()
            ->join('projects', 'projects.id', '=', 'milestones.project_id')
            ->whereNotNull('milestones.planned_end_date')
            ->whereDate('milestones.planned_end_date', '<=', $limit)
            ->whereNotIn('milestones.status', [MilestoneStatus::Reached->value, MilestoneStatus::Cancelled->value])
            ->toBase()
            ->get(['milestones.id', 'milestones.name', 'milestones.planned_end_date', 'milestones.responsible_user_id', 'projects.user_id as owner_id']);

        $users = $this->employees->usersFor($milestones->flatMap(fn (object $row): array => [(int) $row->responsible_user_id, (int) $row->owner_id])->unique()->values()->all());

        return $milestones->map(fn (object $row): DeadlineItem => new DeadlineItem(
            'milestone', (int) $row->id, 'milestones', (string) $row->name, CarbonImmutable::parse($row->planned_end_date),
            $this->unique([$users[(int) $row->responsible_user_id] ?? null, $users[(int) $row->owner_id] ?? null]),
        ))->all();
    }

    /**
     * @return list<DeadlineItem>
     */
    private function reports(string $limit): array
    {
        return Report::query()
            ->join('plan_indicators', 'plan_indicators.id', '=', 'reports.plan_indicator_id')
            ->join('indicators', 'indicators.id', '=', 'plan_indicators.indicator_id')
            ->where('reports.status', ReportStatus::Draft->value)
            ->whereDate('reports.planned_date', '<=', $limit)
            ->toBase()
            ->get(['reports.id', 'reports.user_id', 'reports.planned_date', 'indicators.name'])
            ->map(fn (object $row): DeadlineItem => new DeadlineItem(
                'report', (int) $row->id, 'reports', (string) $row->name, CarbonImmutable::parse($row->planned_date), [(int) $row->user_id],
            ))->all();
    }

    /**
     * @return list<DeadlineItem>
     */
    private function donorReports(string $limit): array
    {
        return DonorReport::query()
            ->whereNull('submitted_on')
            ->whereDate('due_on', '<=', $limit)
            ->get(['id', 'title', 'due_on'])
            ->map(fn (DonorReport $report): DeadlineItem => new DeadlineItem(
                'donor_report', $report->id, 'funding-agreements', $report->title, $report->due_on, [], self::FinanceWatchers,
            ))->all();
    }

    /**
     * @return list<DeadlineItem>
     */
    private function proposals(string $limit): array
    {
        return Proposal::query()
            ->whereNotIn('status', [ProposalStatus::Completed->value, ProposalStatus::Rejected->value])
            ->whereDate('planned_date_end', '<=', $limit)
            ->get(['id', 'name', 'planned_date_end', 'status'])
            ->map(fn (Proposal $proposal): DeadlineItem => new DeadlineItem(
                'proposal', $proposal->id, 'proposals', $proposal->name, $proposal->planned_date_end, [], self::FinanceWatchers,
            ))->all();
    }

    /**
     * @return list<DeadlineItem>
     */
    private function transfers(string $limit): array
    {
        return TransfersTracking::query()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'transfers_tracking.donor_proposal_id')
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->where('transfers_tracking.ar_status', ArStatus::Pending->value)
            ->whereNull('transfers_tracking.transfer_date')
            ->whereNotNull('transfers_tracking.scheduled_date')
            ->whereDate('transfers_tracking.scheduled_date', '<=', $limit)
            ->toBase()
            ->get(['transfers_tracking.id', 'transfers_tracking.scheduled_date', 'transfers_tracking.payment_amount', 'transfers_tracking.amount_transfered_currency', 'donors.name as donor', 'proposals.name as proposal'])
            ->map(fn (object $row): DeadlineItem => new DeadlineItem(
                'transfer', (int) $row->id, 'transfers', $row->donor.' · '.$row->proposal.' · '.$row->payment_amount.' '.$row->amount_transfered_currency,
                CarbonImmutable::parse($row->scheduled_date), [], ['Financial'],
            ))->all();
    }

    /**
     * @return list<DeadlineItem>
     */
    private function projects(string $limit): array
    {
        $open = array_map(fn (ProjectStatus $status): string => $status->value, array_filter(ProjectStatus::cases(), fn (ProjectStatus $status): bool => $status->isOpen()));

        $projects = Project::query()
            ->whereIn('status', $open)
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', $limit)
            ->get(['id', 'name', 'end_date', 'user_id']);

        $users = $this->employees->usersFor($projects->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $projects->map(fn (Project $project): DeadlineItem => new DeadlineItem(
            'project', $project->id, 'projects', $project->name, $project->end_date, $this->unique([$users[(int) $project->user_id] ?? null]),
        ))->all();
    }

    /**
     * The program an item rolls up to (for escalation and the weekly digest),
     * read through the subject's own TracksActivity::activityProgramId().
     */
    public function programIdFor(DeadlineItem $item): ?int
    {
        $class = Relation::getMorphedModel($item->subjectType);
        $model = $class === null ? null : $class::query()->find($item->subjectId);

        return $model instanceof TracksActivity ? $model->activityProgramId() : null;
    }

    /**
     * @param  array<int, int|null>  $ids
     * @return list<int>
     */
    private function unique(array $ids): array
    {
        return array_values(array_unique(array_map('intval', array_filter($ids))));
    }
}
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/DeadlineCatalogTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/Alerts/DeadlineCatalog.php tests/Feature/Notifications/DeadlineCatalogTest.php`

### Task 4.5: Due-soon scanner

**Files:**
- Create: `app/Notifications/Alerts/DueSoonScanner.php`
- Modify: `lang/{en,ar}/notifications.php` (`alerts.due_soon.*`)
- Test: `tests/Feature/Notifications/DueSoonScannerTest.php`

**Behaviour:** on a working day only; for each open item due today or later, effective date = last working day on or before the due date; `remaining` = working days after today up to the effective date; stage = the smallest of `{0} ∪ due_soon_days` that is `>= remaining` (so a missed run still fires the current stage once); stage key `d{stage}:{due date}`; priority Info, stage 0 Important; key `today` (due today), `on_day` (due on a non-working day, warned on the effective day, with weekday), `in_one_day`, `in_days`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationPriority;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\Alerts\DueSoonScanner;
use App\Notifications\Alerts\NotificationSettings;
use Carbon\CarbonImmutable;

// 2026-09-21 is a Monday; working days default Monday–Friday.
function dueTask(string $due, ?\App\Models\User $assignee = null, TaskStatus $status = TaskStatus::Todo): Task
{
    return Activity::withoutRecording(function () use ($due, $assignee, $status): Task {
        $task = Task::factory()->create(['due_date' => $due, 'status' => $status, 'title' => 'Plan']);

        if ($assignee !== null) {
            $task->taskAssignees()->delete();
            $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);
        }

        return $task;
    });
}

function scanDueSoon(string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(DueSoonScanner::class)->scan(CarbonImmutable::now());
}

test('each stage fires once, counted in working days', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-30', $assignee); // Wednesday

    expect(scanDueSoon('2026-09-21 09:00'))->toBe(1)   // 7 working days
        ->and(scanDueSoon('2026-09-21 09:15'))->toBe(0) // same stage, deduplicated
        ->and(scanDueSoon('2026-09-24 09:00'))->toBe(0) // 4 left: no stage
        ->and(scanDueSoon('2026-09-25 09:00'))->toBe(1) // 3 left (Mon, Tue, Wed)
        ->and(scanDueSoon('2026-09-29 09:00'))->toBe(1) // 1 left
        ->and(scanDueSoon('2026-09-30 09:00'))->toBe(1); // today

    $priorities = UserNotification::query()->forUser($assignee)->oldest()->pluck('priority')->all();

    expect($priorities)->toBe([NotificationPriority::Info, NotificationPriority::Info, NotificationPriority::Info, NotificationPriority::Important])
        ->and(sentencesFor($assignee)[3])->toBe('“Plan” is due today');
});

test('an item due on a weekend is warned on the last working day before it', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-26', $assignee); // Saturday

    expect(scanDueSoon('2026-09-25 09:00'))->toBe(1);

    expect(sentencesFor($assignee)[0])->toContain('due on Saturday');
});

test('no deadline alerts on non-working days', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-28', $assignee);

    expect(scanDueSoon('2026-09-27 09:00'))->toBe(0); // Sunday
});

test('a custom working week moves the warning', function () {
    app(NotificationSettings::class)->save(['working_days' => [7, 1, 2, 3, 4]]); // Sunday–Thursday
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-25', $assignee); // Friday is now a weekend

    expect(scanDueSoon('2026-09-24 09:00'))->toBe(1)
        ->and(sentencesFor($assignee)[0])->toContain('due on Friday');
});

test('finished items stop alerting and a moved due date re-arms the stages', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    $task = dueTask('2026-09-22', $assignee);

    expect(scanDueSoon('2026-09-21 09:00'))->toBe(1);

    Activity::withoutRecording(fn () => $task->update(['due_date' => '2026-09-23']));
    expect(scanDueSoon('2026-09-22 09:00'))->toBe(1);

    Activity::withoutRecording(fn () => $task->update(['status' => TaskStatus::Done]));
    expect(scanDueSoon('2026-09-23 09:00'))->toBe(0);
});

test('a missed run still fires the current stage once', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-23', $assignee); // created with 2 working days left

    expect(scanDueSoon('2026-09-21 09:00'))->toBe(1)
        ->and(scanDueSoon('2026-09-21 18:00'))->toBe(0);
});

test('the day boundary follows the app timezone', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    dueTask('2026-09-25', $assignee);

    scanDueSoon('2026-09-24 23:59');
    scanDueSoon('2026-09-25 00:01');

    expect(sentencesFor($assignee))->toHaveCount(2)
        ->and(sentencesFor($assignee)[1])->toBe('“Plan” is due today');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement** `app/Notifications/Alerts/DueSoonScanner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use Carbon\CarbonImmutable;

/**
 * "Due soon": 7 / 3 / 1 working days before, and on the day (defaults).
 */
final class DueSoonScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $stages = collect((array) $this->settings->get('due_soon_days'))->map(fn ($days): int => (int) $days)->push(0)->unique()->sort()->values();
        $horizon = $this->calendar->addWorkingDays($today, (int) $stages->max() + 1)->subDay();
        $sent = 0;

        foreach ($this->catalog->items($horizon) as $item) {
            if ($item->dueDate->lt($today)) {
                continue;
            }

            $effective = $this->calendar->lastWorkingDayOnOrBefore($item->dueDate);
            $remaining = $this->calendar->workingDaysBetween($today, $effective);
            $stage = $stages->first(fn (int $threshold): bool => $remaining <= $threshold);

            if ($stage === null) {
                continue;
            }

            $key = match (true) {
                $remaining === 0 && $item->dueDate->isSameDay($today) => 'today',
                $remaining === 0 => 'on_day',
                $remaining === 1 => 'in_one_day',
                default => 'in_days',
            };

            $payload = $this->dispatcher->payload(
                $item->module,
                $remaining === 0 ? NotificationPriority::Important : NotificationPriority::Info,
                'notifications.alerts.due_soon.'.$key,
                [
                    'subject' => $item->label,
                    'days' => (string) $remaining,
                    'date' => ['date' => $item->dueDate->toDateString()],
                    'weekday' => ['weekday' => $item->dueDate->toDateString()],
                ],
                $item->subjectType,
                $item->subjectId,
            );

            $sent += $this->dispatcher->sendForItem('due_soon', "d{$stage}:{$item->dueDate->toDateString()}", $payload, $item);
        }

        return $sent;
    }
}
```
Lang (en) under `notifications.alerts`:
```php
    'alerts' => [
        'due_soon' => [
            'today' => '“:subject” is due today',
            'on_day' => '“:subject” is due on :weekday (:date)',
            'in_one_day' => '“:subject” is due in 1 working day (:date)',
            'in_days' => '“:subject” is due in :days working days (:date)',
        ],
    ],
```
(ar):
```php
    'alerts' => [
        'due_soon' => [
            'today' => 'موعد «:subject» اليوم',
            'on_day' => 'موعد «:subject» يوم :weekday (:date)',
            'in_one_day' => 'يتبقى يوم عمل واحد على موعد «:subject» (:date)',
            'in_days' => 'يتبقى على موعد «:subject» أيام عمل عددها :days (:date)',
        ],
    ],
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/DueSoonScannerTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/Alerts/DueSoonScanner.php lang tests/Feature/Notifications/DueSoonScannerTest.php`

### Task 4.6: Overdue and escalation scanners

**Files:**
- Create: `app/Notifications/Alerts/OverdueScanner.php`, `app/Notifications/Alerts/EscalationScanner.php`
- Modify: `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/OverdueScannerTest.php`

**Behaviour:** working days only. `overdueDays` = working days after the due date up to today (an item due Saturday is day 1 on Monday). Overdue: day 1, then every `overdue_repeat_days`; stage `day{mark}:{due}`; Important; direct (watchers when none); carries the quick action `alert.snooze` from Phase 5 (added there). Escalation: `>= escalate_manager_after_days` → the program manager (stage `manager:{due}`; skipped when no program/manager); `>= escalate_top_after_days` → top roles (stage `top:{due}`); Critical; recipients are treated as direct (the escalation is addressed to them).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\NotificationPriority;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\Alerts\EscalationScanner;
use App\Notifications\Alerts\OverdueScanner;
use Carbon\CarbonImmutable;

function overdueTaskFor(\App\Models\User $assignee, string $due, ?int $programId = null): Task
{
    return Activity::withoutRecording(function () use ($assignee, $due, $programId): Task {
        $milestone = Milestone::factory()->create(['project_id' => Project::factory()->create($programId === null ? [] : ['program_id' => $programId])->id]);
        $task = Task::factory()->create(['due_date' => $due, 'title' => 'Plan', 'source_type' => 'milestone', 'source_id' => $milestone->id]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);

        return $task;
    });
}

function runScanner(string $class, string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app($class)->scan(CarbonImmutable::now());
}

test('overdue fires on day one then every three working days', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    overdueTaskFor($assignee, '2026-09-18'); // Friday

    expect(runScanner(OverdueScanner::class, '2026-09-21 09:00'))->toBe(1) // day 1 (Monday)
        ->and(runScanner(OverdueScanner::class, '2026-09-23 09:00'))->toBe(0) // day 3
        ->and(runScanner(OverdueScanner::class, '2026-09-24 09:00'))->toBe(1) // day 4
        ->and(runScanner(OverdueScanner::class, '2026-09-27 09:00'))->toBe(0) // Sunday
        ->and(runScanner(OverdueScanner::class, '2026-09-29 09:00'))->toBe(1); // day 7

    expect(UserNotification::query()->forUser($assignee)->pluck('priority')->unique()->all())->toBe([NotificationPriority::Important]);
});

test('an item due on a weekend starts its overdue count on the next working day', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    overdueTaskFor($assignee, '2026-09-26'); // Saturday

    expect(runScanner(OverdueScanner::class, '2026-09-28 09:00'))->toBe(1);
    expect(sentencesFor($assignee)[0])->toContain('1 working day');
});

test('escalation reaches the program manager at five and the top roles at ten working days', function () {
    $managerEmployee = insertHrEmployee();
    $manager = userWithRole('Managers', $managerEmployee);
    $generalManager = userWithRole('General Manager');
    $program = Activity::withoutRecording(fn () => Program::factory()->create(['manager_id' => $managerEmployee]));
    overdueTaskFor(userWithRole('Managers', insertHrEmployee()), '2026-09-11', $program->id); // Friday

    expect(runScanner(EscalationScanner::class, '2026-09-17 09:00'))->toBe(0) // day 4
        ->and(runScanner(EscalationScanner::class, '2026-09-18 09:00'))->toBe(1) // day 5 → manager
        ->and(runScanner(EscalationScanner::class, '2026-09-25 09:00'))->toBeGreaterThanOrEqual(1); // day 10 → top

    expect(UserNotification::query()->forUser($manager)->sole()->priority)->toBe(NotificationPriority::Critical)
        ->and(UserNotification::query()->forUser($generalManager)->sole()->priority)->toBe(NotificationPriority::Critical);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`OverdueScanner`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use Carbon\CarbonImmutable;

/**
 * "Overdue": day 1, then every N working days (default 3) until done.
 */
final class OverdueScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $repeat = max(1, (int) $this->settings->get('overdue_repeat_days'));
        $sent = 0;

        foreach ($this->catalog->items($today->subDay()) as $item) {
            $days = $this->calendar->workingDaysBetween($item->dueDate, $today);

            if ($days < 1) {
                continue;
            }

            $mark = 1 + intdiv($days - 1, $repeat) * $repeat;

            $payload = $this->dispatcher->payload(
                $item->module,
                NotificationPriority::Important,
                'notifications.alerts.overdue.'.($days === 1 ? 'one' : 'many'),
                ['subject' => $item->label, 'days' => (string) $days, 'date' => ['date' => $item->dueDate->toDateString()]],
                $item->subjectType,
                $item->subjectId,
            );

            $sent += $this->dispatcher->sendForItem('overdue', "day{$mark}:{$item->dueDate->toDateString()}", $payload, $item);
        }

        return $sent;
    }
}
```

`EscalationScanner`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Still overdue: +5 working days to the program manager, +10 to the top
 * roles (defaults). Critical.
 */
final class EscalationScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $managerAfter = (int) $this->settings->get('escalate_manager_after_days');
        $topAfter = (int) $this->settings->get('escalate_top_after_days');
        $sent = 0;

        foreach ($this->catalog->items($today->subDay()) as $item) {
            $days = $this->calendar->workingDaysBetween($item->dueDate, $today);
            $due = $item->dueDate->toDateString();
            $params = ['subject' => $item->label, 'days' => (string) $days, 'date' => ['date' => $due]];

            if ($days >= $managerAfter) {
                $managers = $this->resolver->programManagerUserIds($this->catalog->programIdFor($item));

                if ($managers !== []) {
                    $payload = $this->dispatcher->payload($item->module, NotificationPriority::Critical, 'notifications.alerts.escalation.manager', $params, $item->subjectType, $item->subjectId);
                    $sent += $this->dispatcher->send('escalation', "manager:{$due}", $payload, $managers);
                }
            }

            if ($days >= $topAfter) {
                $payload = $this->dispatcher->payload($item->module, NotificationPriority::Critical, 'notifications.alerts.escalation.top', $params, $item->subjectType, $item->subjectId);
                $sent += $this->dispatcher->send('escalation', "top:{$due}", $payload, $this->resolver->usersWithRoles(config('notifications.top_roles')));
            }
        }

        return $sent;
    }
}
```
Lang (en) inside `alerts`:
```php
        'overdue' => [
            'one' => '“:subject” is overdue by 1 working day (due :date)',
            'many' => '“:subject” is overdue by :days working days (due :date)',
        ],
        'escalation' => [
            'manager' => '“:subject” is still overdue after :days working days (due :date) — escalated to you as program manager',
            'top' => '“:subject” is still overdue after :days working days (due :date) — escalated to management',
        ],
```
(ar):
```php
        'overdue' => [
            'one' => '«:subject» متأخر يوم عمل واحد (كان موعده :date)',
            'many' => '«:subject» متأخر أيام عمل عددها :days (كان موعده :date)',
        ],
        'escalation' => [
            'manager' => '«:subject» ما زال متأخرًا بعد :days أيام عمل (كان موعده :date) — صُعّد إليك بصفتك مدير البرنامج',
            'top' => '«:subject» ما زال متأخرًا بعد :days أيام عمل (كان موعده :date) — صُعّد إلى الإدارة',
        ],
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/OverdueScannerTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/Alerts lang tests/Feature/Notifications/OverdueScannerTest.php`

### Task 4.7: Stalled scanner

**Files:**
- Create: `app/Notifications/Alerts/StalledScanner.php`
- Modify: `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/StalledScannerTest.php`

**Behaviour:** working days only. Stall start = latest status-change activity (the record's `created` activity counts) or `updated_at` when none. Rules: task `in progress` ≥ 7, `under review` ≥ 3 (direct assignees + creator); report `draft` ≥ 7, `reviewed` ≥ 5 (author + MEAL); proposal any open status ≥ 30 (Financial); transfer `pending` ≥ 7 past `scheduled_date` (Financial, start = scheduled date). Stage `{status}:{start timestamp}` — a status change starts a new stall; other edits never do. Important. Also exposes `stalledSubjects(CarbonImmutable $now): list<array{type: string, id: int, userIds: list<int>}>` for the daily brief (Phase 5).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\UserNotification;
use App\Notifications\Alerts\StalledScanner;
use Carbon\CarbonImmutable;

function scanStalled(string $at): int
{
    test()->travelTo(CarbonImmutable::parse($at));

    return app(StalledScanner::class)->scan(CarbonImmutable::now());
}

test('a task in progress for seven working days without a status change stalls once', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    $task = Activity::withoutRecording(function () use ($assignee) {
        $task = Task::factory()->create(['title' => 'Plan']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);

        return $task;
    });

    $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00')); // Monday
    $task->update(['status' => TaskStatus::InProgress]);
    $this->travelTo(CarbonImmutable::parse('2026-09-18 10:00'));
    $task->update(['title' => 'Plan v2']); // not a status change

    expect(scanStalled('2026-09-22 09:00'))->toBe(0) // 6 working days
        ->and(scanStalled('2026-09-23 09:00'))->toBe(1) // 7
        ->and(scanStalled('2026-09-24 09:00'))->toBe(0);

    expect(UserNotification::query()->forUser($assignee)->where('kind', 'alert')->sole()->data['key'])->toBe('notifications.alerts.stalled.task');
});

test('a status change re-arms the stall', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    $task = Activity::withoutRecording(function () use ($assignee) {
        $task = Task::factory()->create(['status' => TaskStatus::InProgress]);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);

        return $task;
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    $task->update(['status' => TaskStatus::UnderReview]);

    expect(scanStalled('2026-09-17 09:00'))->toBe(1); // 3 working days under review

    $this->travelTo(CarbonImmutable::parse('2026-09-17 10:00'));
    $task->update(['status' => TaskStatus::InProgress]);
    $task->update(['status' => TaskStatus::UnderReview]);

    expect(scanStalled('2026-09-18 09:00'))->toBe(0)
        ->and(scanStalled('2026-09-22 09:00'))->toBe(1);
});

test('a draft report stalls for its author and meal; nothing fires on a weekend', function () {
    $author = userWithRole('Public Relations');
    $meal = userWithRole('MEAL');
    $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
    Report::factory()->create(['user_id' => $author->id, 'status' => ReportStatus::Draft]);

    expect(scanStalled('2026-09-26 09:00'))->toBe(0) // Saturday
        ->and(scanStalled('2026-09-23 09:00'))->toBeGreaterThanOrEqual(2);

    expect(UserNotification::query()->forUser($meal)->where('kind', 'alert')->count())->toBe(1);
});

test('a pending transfer stalls seven working days after its scheduled date', function () {
    $financial = userWithRole('Financial');
    Activity::withoutRecording(fn () => TransfersTracking::factory()->create(['scheduled_date' => '2026-09-14']));

    expect(scanStalled('2026-09-22 09:00'))->toBe(0)
        ->and(scanStalled('2026-09-23 09:00'))->toBe(1);

    expect(UserNotification::query()->forUser($financial)->where('kind', 'alert')->count())->toBe(1);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement** `app/Notifications/Alerts/StalledScanner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\ArStatus;
use App\Enums\NotificationPriority;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Notifications\EmployeeRecipients;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Work sitting in one status too long. Only a status change resets the
 * timer, and each stall fires once (its stage is keyed on when it started).
 */
final class StalledScanner implements Scanner
{
    public function __construct(
        private WorkingCalendar $calendar,
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $sent = 0;

        foreach ($this->stalledSubjects($today) as $stall) {
            $payload = $this->dispatcher->payload(
                $stall['module'],
                NotificationPriority::Important,
                'notifications.alerts.stalled.'.$stall['type'],
                ['subject' => $stall['label'], 'status' => $stall['status'], 'days' => (string) $stall['days']],
                $stall['type'],
                $stall['id'],
            );

            $sent += $this->dispatcher->send('stalled', $stall['stage'], $payload, $stall['userIds'], $this->resolver->usersWithRoles($stall['reviewerRoles']));
        }

        return $sent;
    }

    /**
     * Every record stalled today.
     *
     * @return list<array{type: string, id: int, module: string, label: string, status: array{enum: string, value: string}|string, days: int, stage: string, userIds: list<int>, reviewerRoles: list<string>}>
     */
    public function stalledSubjects(CarbonImmutable $today): array
    {
        return [
            ...$this->tasks($today, TaskStatus::InProgress, (int) $this->settings->get('stalled_task_in_progress_days')),
            ...$this->tasks($today, TaskStatus::UnderReview, (int) $this->settings->get('stalled_task_under_review_days')),
            ...$this->reports($today, ReportStatus::Draft, (int) $this->settings->get('stalled_report_draft_days')),
            ...$this->reports($today, ReportStatus::Reviewed, (int) $this->settings->get('stalled_report_reviewed_days')),
            ...$this->proposals($today, (int) $this->settings->get('stalled_proposal_days')),
            ...$this->transfers($today, (int) $this->settings->get('stalled_transfer_pending_days')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tasks(CarbonImmutable $today, TaskStatus $status, int $threshold): array
    {
        $tasks = Task::query()->select(['id', 'title', 'created_by', 'updated_at'])->where('status', $status->value)->with('taskAssignees:id,task_id,employee_id')->get();
        $since = $this->statusSince('task', $tasks->modelKeys(), ['task.created', 'task.status_changed']);
        $users = $this->employees->usersFor($tasks->flatMap(fn (Task $task) => $task->taskAssignees->pluck('employee_id'))->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $this->stalls($tasks, $since, $today, $threshold, 'task', 'tasks', $status->value, ['enum' => 'task_status', 'value' => $status->value], [],
            fn (Task $task): array => [...$task->taskAssignees->map(fn ($assignee): ?int => $users[(int) $assignee->employee_id] ?? null)->all(), $task->created_by],
            fn (Task $task): string => $task->title);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reports(CarbonImmutable $today, ReportStatus $status, int $threshold): array
    {
        $reports = Report::query()->select(['id', 'user_id', 'plan_indicator_id', 'updated_at'])->where('status', $status->value)->get();
        $since = $this->statusSince('report', $reports->modelKeys(), ['report.created', 'report.submitted', 'report.approved', 'report.status_changed']);

        return $this->stalls($reports, $since, $today, $threshold, 'report', 'reports', $status->value, ['enum' => 'report_status', 'value' => $status->value], ['MEAL'],
            fn (Report $report): array => [$report->user_id],
            fn (Report $report): string => $report->activityLabel());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function proposals(CarbonImmutable $today, int $threshold): array
    {
        $proposals = Proposal::query()->select(['id', 'name', 'status', 'updated_at'])->whereNotIn('status', [ProposalStatus::Completed->value, ProposalStatus::Rejected->value])->get();
        $since = $this->statusSince('proposal', $proposals->modelKeys(), ['proposal.created', 'proposal.status_changed']);

        return collect($proposals)->flatMap(fn (Proposal $proposal): array => $this->stalls(
            collect([$proposal]), $since, $today, $threshold, 'proposal', 'proposals', $proposal->status->value,
            ['enum' => 'proposal_status', 'value' => $proposal->status->value], ['Financial'],
            fn (): array => [], fn (Proposal $proposal): string => $proposal->name,
        ))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transfers(CarbonImmutable $today, int $threshold): array
    {
        return TransfersTracking::query()
            ->select(['id', 'donor_proposal_id', 'payment_amount', 'amount_transfered_currency', 'scheduled_date'])
            ->where('ar_status', ArStatus::Pending->value)
            ->whereNotNull('scheduled_date')
            ->whereDate('scheduled_date', '<', $today->toDateString())
            ->get()
            ->map(function (TransfersTracking $transfer) use ($today, $threshold): ?array {
                $days = $this->calendar->workingDaysBetween($transfer->scheduled_date, $today);

                return $days < $threshold ? null : [
                    'type' => 'transfer', 'id' => $transfer->id, 'module' => 'transfers', 'label' => $transfer->activityLabel(),
                    'status' => ['enum' => 'ar_status', 'value' => ArStatus::Pending->value], 'days' => $days,
                    'stage' => 'pending:'.$transfer->scheduled_date->toDateString(), 'userIds' => [], 'reviewerRoles' => ['Financial'],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * When each record's current status began: its latest status activity,
     * else its last update.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $events
     * @return Collection<int, string>
     */
    private function statusSince(string $type, array $ids, array $events): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Activity::query()
            ->where('subject_type', $type)
            ->whereIn('subject_id', $ids)
            ->whereIn('event', $events)
            ->groupBy('subject_id')
            ->toBase()
            ->selectRaw('subject_id, max(created_at) as since')
            ->pluck('since', 'subject_id');
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $records
     * @param  Collection<int, string>  $since
     * @param  array{enum: string, value: string}  $status
     * @param  list<string>  $reviewerRoles
     * @return list<array<string, mixed>>
     */
    private function stalls(Collection $records, Collection $since, CarbonImmutable $today, int $threshold, string $type, string $module, string $statusValue, array $status, array $reviewerRoles, callable $people, callable $label): array
    {
        $stalls = [];

        foreach ($records as $record) {
            $start = CarbonImmutable::parse($since[$record->getKey()] ?? $record->updated_at);
            $days = $this->calendar->workingDaysBetween($start, $today);

            if ($days < $threshold) {
                continue;
            }

            $stalls[] = [
                'type' => $type,
                'id' => (int) $record->getKey(),
                'module' => $module,
                'label' => $label($record),
                'status' => $status,
                'days' => $days,
                'stage' => $statusValue.':'.$start->format('YmdHis'),
                'userIds' => array_values(array_unique(array_map('intval', array_filter($people($record))))),
                'reviewerRoles' => $reviewerRoles,
            ];
        }

        return $stalls;
    }
}
```
Lang (en) inside `alerts`:
```php
        'stalled' => [
            'task' => '“:subject” has been :status for :days working days without a status change',
            'report' => 'The report on “:subject” has been :status for :days working days',
            'proposal' => 'The proposal “:subject” has been :status for :days working days',
            'transfer' => 'The tranche “:subject” is still pending :days working days after its scheduled date',
        ],
```
(ar):
```php
        'stalled' => [
            'task' => 'بقيت المهمة «:subject» في حالة :status مدة :days أيام عمل دون تغيير',
            'report' => 'بقي التقرير عن «:subject» في حالة :status مدة :days أيام عمل',
            'proposal' => 'بقي المقترح «:subject» في حالة :status مدة :days أيام عمل',
            'transfer' => 'ما زالت الدفعة «:subject» معلّقة بعد :days أيام عمل من موعدها المجدول',
        ],
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/StalledScannerTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/Alerts/StalledScanner.php lang tests/Feature/Notifications/StalledScannerTest.php`

### Task 4.8: Off-track indicators and data gaps

**Files:**
- Create: `app/Notifications/Alerts/OffTrackIndicatorScanner.php`, `app/Notifications/Alerts/DataGapScanner.php`
- Modify: `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/IndicatorAndGapScannerTest.php`

**Behaviour:**
- Off pace: `Indicator::percentAchieved()` (baseline → target) below `off_track_pace_percent`% of the calendar year's elapsed share (the same window the indicators page rates against); stage `pace:{year}`; re-armed (ledger rows deleted) as soon as it is back on pace. Silent: no reading within its `frequency` window (monthly 1, quarterly 3, semi-annual 6, annual 12 months; an indicator with no readings counts from its creation); stage `silent`; re-armed when a reading arrives. Recipients: project owner (direct) + MEAL. Important. Public `isOffPace(Indicator $indicator, CarbonImmutable $now): bool` for the weekly digest.
- Data gaps (stage `{gap}:{ISO year-week}` → at most weekly): active project with no milestones (owner) / no indicators (owner + MEAL) / past `end_date` still active (owner); approved report with no files (author). Info.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ProjectStatus;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\Project;
use App\Models\Report;
use App\Models\UserNotification;
use App\Notifications\Alerts\DataGapScanner;
use App\Notifications\Alerts\OffTrackIndicatorScanner;
use Carbon\CarbonImmutable;

test('an indicator behind pace alerts once and re-arms after recovering', function () {
    $meal = userWithRole('MEAL');
    $recorder = userWithRole('MEAL');
    $indicator = Activity::withoutRecording(function () use ($recorder) {
        $indicator = Indicator::factory()->create(['baseline' => '0', 'target' => '100', 'name' => 'People reached']);
        IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '10', 'date' => '2026-06-30', 'recorded_by' => $recorder->id]);

        return $indicator;
    });
    $scanner = app(OffTrackIndicatorScanner::class);
    $mid = CarbonImmutable::parse('2026-07-01 06:00'); // ~50% of the year elapsed

    expect($scanner->scan($mid))->toBeGreaterThanOrEqual(1)
        ->and($scanner->scan($mid))->toBe(0);

    Activity::withoutRecording(fn () => IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '60', 'date' => '2026-07-02', 'recorded_by' => $recorder->id]));
    $scanner->scan($mid->addDays(2)); // back on pace → re-armed

    Activity::withoutRecording(fn () => IndicatorProgress::factory()->create(['indicator_id' => $indicator->id, 'value' => '20', 'date' => '2026-07-05', 'recorded_by' => $recorder->id]));

    expect($scanner->scan($mid->addDays(5)))->toBeGreaterThanOrEqual(1);
    expect(UserNotification::query()->forUser($meal)->where('data', 'like', '%off_track.pace%')->count())->toBe(2);
});

test('an indicator with no reading inside its frequency window is flagged silent', function () {
    $meal = userWithRole('MEAL');
    Activity::withoutRecording(fn () => Indicator::factory()->create(['frequency' => 'monthly', 'target' => null, 'created_at' => '2026-01-01']));

    app(OffTrackIndicatorScanner::class)->scan(CarbonImmutable::parse('2026-03-01 06:00'));

    expect(UserNotification::query()->forUser($meal)->where('data', 'like', '%off_track.silent%')->count())->toBe(1);
});

test('data gaps fire at most once a week', function () {
    $ownerEmployee = insertHrEmployee();
    $owner = userWithRole('Managers', $ownerEmployee);
    Activity::withoutRecording(fn () => Project::factory()->status(ProjectStatus::Active)->create(['user_id' => $ownerEmployee, 'end_date' => '2026-01-31']));
    $scanner = app(DataGapScanner::class);

    $first = $scanner->scan(CarbonImmutable::parse('2026-09-21 06:00'));
    $sameWeek = $scanner->scan(CarbonImmutable::parse('2026-09-23 06:00'));
    $nextWeek = $scanner->scan(CarbonImmutable::parse('2026-09-28 06:00'));

    expect($first)->toBe(3) // no milestones, no indicators, past end date
        ->and($sameWeek)->toBe(0)
        ->and($nextWeek)->toBe(3)
        ->and(UserNotification::query()->forUser($owner)->pluck('priority')->unique()->map->value->all())->toBe(['info']);
});

test('an approved report without files is a data gap for its author', function () {
    $author = userWithRole('MEAL');
    Activity::withoutRecording(fn () => Report::factory()->approved()->create(['user_id' => $author->id]));

    app(DataGapScanner::class)->scan(CarbonImmutable::parse('2026-09-21 06:00'));

    expect(UserNotification::query()->forUser($author)->where('data', 'like', '%report_without_files%')->count())->toBe(1);
});
```
(Check `IndicatorFactory` accepts `frequency`/`created_at` and `Period`'s case for 'monthly'; adjust the fixture only.)

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`OffTrackIndicatorScanner`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Enums\Period;
use App\Models\Indicator;
use App\Models\Project;
use App\Notifications\EmployeeRecipients;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Indicators behind pace or silent past their reporting frequency.
 */
final class OffTrackIndicatorScanner implements Scanner
{
    public function __construct(
        private NotificationSettings $settings,
        private AlertDispatcher $dispatcher,
        private AlertLedger $ledger,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $indicators = Indicator::query()->with('progress')->get();
        $owners = $this->ownerUsers($indicators->pluck('project_id')->filter()->unique()->values()->all());
        $meal = $this->resolver->usersWithRoles(['MEAL']);
        $paceStage = 'pace:'.$now->year;
        $sent = 0;

        foreach ($indicators as $indicator) {
            $direct = array_values(array_filter([$owners[$indicator->project_id] ?? null]));

            if ($this->isOffPace($indicator, $now)) {
                $payload = $this->dispatcher->payload('indicators', NotificationPriority::Important, 'notifications.alerts.off_track.pace', [
                    'subject' => $indicator->name,
                    'achieved' => (string) (int) floor((float) $indicator->percentAchieved()),
                    'expected' => (string) (int) floor($this->elapsedShare($now) * 100),
                ], 'indicator', $indicator->id);

                $sent += $this->dispatcher->send('off_track', $paceStage, $payload, $direct, $meal);
            } else {
                $this->ledger->release('off_track', 'indicator', $indicator->id, $paceStage);
            }

            $lastReading = $indicator->progress->last()?->date ?? $indicator->created_at;

            if ($lastReading !== null && $lastReading->lt($now->subMonths($this->windowMonths($indicator->frequency)))) {
                $payload = $this->dispatcher->payload('indicators', NotificationPriority::Important, 'notifications.alerts.off_track.silent', [
                    'subject' => $indicator->name,
                    'date' => ['date' => $lastReading->toDateString()],
                ], 'indicator', $indicator->id);

                $sent += $this->dispatcher->send('off_track', 'silent', $payload, $direct, $meal);
            } else {
                $this->ledger->release('off_track', 'indicator', $indicator->id, 'silent');
            }
        }

        return $sent;
    }

    /**
     * Below the configured share of the pace expected by now in the calendar
     * year. Unmeasurable indicators (no target, no reading) are never off pace.
     */
    public function isOffPace(Indicator $indicator, CarbonImmutable $now): bool
    {
        $achieved = $indicator->percentAchieved();
        $expected = $this->elapsedShare($now) * 100;

        return $achieved !== null && $expected > 0
            && $achieved < $expected * ((int) $this->settings->get('off_track_pace_percent') / 100);
    }

    private function elapsedShare(CarbonImmutable $now): float
    {
        $start = $now->startOfYear();

        return ($now->getTimestamp() - $start->getTimestamp()) / ($now->endOfYear()->getTimestamp() - $start->getTimestamp());
    }

    private function windowMonths(Period $frequency): int
    {
        return match ($frequency) {
            Period::Monthly => 1,
            Period::Quarterly => 3,
            Period::SemiAnnual => 6,
            Period::Annual => 12,
        };
    }

    /**
     * @param  list<int>  $projectIds
     * @return array<int, int> project id => owner user id
     */
    private function ownerUsers(array $projectIds): array
    {
        $ownerEmployees = Project::query()->whereIn('id', $projectIds)->pluck('user_id', 'id')->map(fn ($id): int => (int) $id)->all();
        $users = $this->employees->usersFor(array_values($ownerEmployees));

        return array_filter(array_map(fn (int $employeeId): ?int => $users[$employeeId] ?? null, $ownerEmployees));
    }
}
```
(Match `Period`'s real case names; the `match` must stay exhaustive with no default arm.)

`DataGapScanner`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Enums\ReportStatus;
use App\Models\Project;
use App\Models\Report;
use App\Notifications\EmployeeRecipients;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Weekly nudges about missing data: active projects with no milestones or
 * indicators, projects past their end still active, approved reports with no
 * files.
 */
final class DataGapScanner implements Scanner
{
    public function __construct(
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $week = $now->format('o-\WW');
        $meal = $this->resolver->usersWithRoles(['MEAL']);
        $sent = 0;

        $active = Project::query()
            ->where('status', ProjectStatus::Active->value)
            ->withCount(['milestones', 'indicators'])
            ->get(['id', 'name', 'user_id', 'end_date']);

        $owners = $this->employees->usersFor($active->pluck('user_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        foreach ($active as $project) {
            $owner = array_values(array_filter([$owners[(int) $project->user_id] ?? null]));
            $gaps = array_filter([
                'no_milestones' => $project->milestones_count === 0 ? [] : null,
                'no_indicators' => $project->indicators_count === 0 ? $meal : null,
                'past_end' => $project->end_date !== null && $project->end_date->lt($now->startOfDay()) ? [] : null,
            ], fn (?array $others): bool => $others !== null);

            foreach ($gaps as $gap => $others) {
                $payload = $this->dispatcher->payload('projects', NotificationPriority::Info, "notifications.alerts.data_gap.{$gap}", ['subject' => $project->name], 'project', $project->id);
                $sent += $this->dispatcher->send('data_gap', "{$gap}:{$week}", $payload, $owner, $others);
            }
        }

        $reports = Report::query()->where('status', ReportStatus::Approved->value)->doesntHave('files')->get(['id', 'user_id', 'plan_indicator_id']);

        foreach ($reports as $report) {
            $payload = $this->dispatcher->payload('reports', NotificationPriority::Info, 'notifications.alerts.data_gap.report_without_files', ['subject' => $report->activityLabel()], 'report', $report->id);
            $sent += $this->dispatcher->send('data_gap', "report_without_files:{$week}", $payload, [$report->user_id]);
        }

        return $sent;
    }
}
```
Lang (en) inside `alerts`:
```php
        'off_track' => [
            'pace' => '“:subject” is behind pace: :achieved% achieved against :expected% expected by now',
            'silent' => 'No progress has been recorded for “:subject” since :date',
        ],
        'data_gap' => [
            'no_milestones' => 'The active project “:subject” has no milestones',
            'no_indicators' => 'The active project “:subject” has no indicators',
            'past_end' => 'The project “:subject” is past its end date but still active',
            'report_without_files' => 'The approved report on “:subject” has no files attached',
        ],
```
(ar):
```php
        'off_track' => [
            'pace' => '«:subject» متأخر عن الوتيرة: أُنجز :achieved% مقابل :expected% متوقعة حتى الآن',
            'silent' => 'لم يُسجَّل أي تقدم للمؤشر «:subject» منذ :date',
        ],
        'data_gap' => [
            'no_milestones' => 'المشروع النشط «:subject» بلا مراحل',
            'no_indicators' => 'المشروع النشط «:subject» بلا مؤشرات',
            'past_end' => 'تجاوز المشروع «:subject» تاريخ انتهائه وما زال نشطًا',
            'report_without_files' => 'التقرير المعتمد عن «:subject» بلا ملفات مرفقة',
        ],
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/IndicatorAndGapScannerTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/Alerts lang tests/Feature/Notifications/IndicatorAndGapScannerTest.php`

### Task 4.9: Running scanners on the queue and the scheduler

**Files:**
- Create: `app/Jobs/RunScanner.php` (via `php artisan make:job RunScanner --no-interaction`), `app/Console/Commands/ScanNotificationAlerts.php` (via `php artisan make:command ScanNotificationAlerts --no-interaction`)
- Modify: `config/notifications.php` (`scanners`), `routes/console.php`, `lang/{en,ar}/notifications.php` (`console.*`)
- Test: `tests/Feature/Notifications/ScannerSchedulingTest.php`

**Interfaces — Produces:** `RunScanner(string $scanner)` (`$tries = 3`, `$backoff = [60, 300, 900]`); command `notifications:scan {group}` (`frequent` | `daily`); schedule: `notifications:scan frequent` every 15 minutes, `notifications:scan daily` at 06:00, both `withoutOverlapping()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\RunScanner;
use App\Notifications\Alerts\DueSoonScanner;
use App\Notifications\Alerts\OffTrackIndicatorScanner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

test('a scan group queues one job per scanner', function () {
    Queue::fake();

    $this->artisan('notifications:scan frequent')->assertSuccessful();

    Queue::assertPushed(RunScanner::class, 4);
    Queue::assertPushed(RunScanner::class, fn (RunScanner $job): bool => $job->scanner === DueSoonScanner::class);
});

test('an unknown scan group is refused', function () {
    $this->artisan('notifications:scan weekly')->assertFailed();
});

test('the scheduler runs the frequent group every fifteen minutes and the daily group at six', function () {
    $events = collect(app(Schedule::class)->events());

    expect($events->first(fn ($event) => str_contains($event->command, 'notifications:scan frequent'))->expression)->toBe('*/15 * * * *')
        ->and($events->first(fn ($event) => str_contains($event->command, 'notifications:scan daily'))->expression)->toBe('0 6 * * *');
});

test('a scanner job runs its scanner', function () {
    expect(fn () => (new RunScanner(OffTrackIndicatorScanner::class))->handle())->not->toThrow(Throwable::class);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`config/notifications.php`:
```php
    /*
    | Scanner groups run by `notifications:scan {group}`.
    */
    'scanners' => [
        'frequent' => [DueSoonScanner::class, OverdueScanner::class, EscalationScanner::class, StalledScanner::class],
        'daily' => [OffTrackIndicatorScanner::class, DataGapScanner::class],
    ],
```

`app/Jobs/RunScanner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Notifications\Alerts\Scanner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs one alert scanner on the queue. Safe to retry: the ledger stops a
 * stage going twice to the same person.
 */
final class RunScanner implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * @param  class-string<Scanner>  $scanner
     */
    public function __construct(public string $scanner) {}

    public function handle(): void
    {
        app($this->scanner)->scan(CarbonImmutable::now());
    }
}
```

`app/Console/Commands/ScanNotificationAlerts.php`:
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunScanner;
use Illuminate\Console\Command;

/**
 * Queues every scanner of one group (see config notifications.scanners).
 */
class ScanNotificationAlerts extends Command
{
    protected $signature = 'notifications:scan {group : frequent or daily}';

    protected $description = 'Queue the notification alert scanners of one group';

    public function handle(): int
    {
        $scanners = config('notifications.scanners.'.$this->argument('group'));

        if (! is_array($scanners)) {
            $this->error(__('notifications.console.unknown_group', ['group' => $this->argument('group')]));

            return self::FAILURE;
        }

        foreach ($scanners as $scanner) {
            RunScanner::dispatch($scanner);
        }

        $this->info(__('notifications.console.queued', ['count' => count($scanners)]));

        return self::SUCCESS;
    }
}
```

`routes/console.php`:
```php
Schedule::command('notifications:scan frequent')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('notifications:scan daily')->dailyAt('06:00')->withoutOverlapping();
```
Lang `notifications.console`: en `'unknown_group' => 'Unknown scan group [:group].'`, `'queued' => ':count scanner(s) queued.'`; ar `'unknown_group' => 'مجموعة فحص غير معروفة [:group].'`, `'queued' => 'أُضيف :count من الفاحصات إلى قائمة الانتظار.'`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/ScannerSchedulingTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Jobs/RunScanner.php app/Console/Commands/ScanNotificationAlerts.php config/notifications.php routes/console.php lang tests/Feature/Notifications/ScannerSchedulingTest.php`

### Task 4.10: Admin → Notification settings

**Files:**
- Create: `resources/views/pages/admin/⚡notification-settings.blade.php`
- Modify: `app/Providers/AppServiceProvider.php` (gate), `routes/web.php`, `resources/views/components/navigation/sidebar.blade.php`, `lang/{en,ar}/notifications.php` (`admin.*`), `lang/{en,ar}/titles.php`, `lang/{en,ar}/navigation.php`
- Test: `tests/Feature/Notifications/NotificationSettingsAdminTest.php`

**Interfaces — Produces:** gate `manage-notification-settings` (roles in `access-control.administrator_roles`); route `admin.notification-settings.edit` (`GET admin/notification-settings`, `->can(...)`); page saves every `defaults` key through `NotificationSettings::save()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WorkingCalendar;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

test('only administrators open notification settings', function (string $role, int $status) {
    $this->actingAs(userWithRole($role))->get(route('admin.notification-settings.edit'))->assertStatus($status);
})->with([
    ['Super Admin', 200],
    ['General Manager', 403],
    ['Managers', 403],
]);

test('an administrator changes thresholds and working days and they take effect', function () {
    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->assertSet('workingDays', [1, 2, 3, 4, 5])
        ->assertSet('dueSoonDays', '7, 3, 1')
        ->set('workingDays', [7, 1, 2, 3, 4])
        ->set('dueSoonDays', '10, 5, 2')
        ->set('overdueRepeat', 2)
        ->set('dailyBriefTime', '07:00')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(NotificationSettings::class);

    expect($settings->get('due_soon_days'))->toBe([10, 5, 2])
        ->and($settings->get('overdue_repeat_days'))->toBe(2)
        ->and($settings->get('daily_brief_time'))->toBe('07:00')
        ->and(app(WorkingCalendar::class)->isWorkingDay(CarbonImmutable::parse('2026-09-27')))->toBeTrue();
});

test('settings are validated', function (string $property, mixed $value) {
    Livewire::actingAs(userWithRole('Super Admin'))->test('pages::admin.notification-settings')
        ->set($property, $value)
        ->call('save')
        ->assertHasErrors($property);
})->with([
    'no working day' => ['workingDays', []],
    'a bad weekday' => ['workingDays', [8]],
    'a bad stage list' => ['dueSoonDays', '7, soon'],
    'a zero repeat' => ['overdueRepeat', 0],
    'top before manager' => ['escalateTopAfter', 3],
    'a pace over 100' => ['offTrackPace', 150],
    'a bad time' => ['weeklyDigestTime', '8am'],
]);

test('a non-administrator cannot save by calling the action', function () {
    Livewire::actingAs(userWithRole('Managers'))->test('pages::admin.notification-settings')->call('save')->assertForbidden();
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

Gate in `configureGates()`:
```php
        Gate::define('manage-notification-settings', fn (User $user): bool => $user->hasAnyRole(config('access-control.administrator_roles')));
```
Route (`routes/web.php`, auth group):
```php
    Route::livewire('admin/notification-settings', 'pages::admin.notification-settings')
        ->can('manage-notification-settings')
        ->name('admin.notification-settings.edit');
```
Sidebar admin group: `@can('manage-notification-settings')` item, icon `adjustments-horizontal`, label `__('navigation.notification_settings')`, current `request()->routeIs('admin.notification-settings.*')`.

`resources/views/pages/admin/⚡notification-settings.blade.php`:
```blade
<?php

use App\Notifications\Alerts\NotificationSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.notification_rules')] class extends Component {
    /** @var list<int> ISO weekdays */
    public array $workingDays = [];

    public string $dueSoonDays = '';

    public int $overdueRepeat = 3;

    public int $escalateManagerAfter = 5;

    public int $escalateTopAfter = 10;

    public int $stalledTaskInProgress = 7;

    public int $stalledTaskUnderReview = 3;

    public int $stalledReportDraft = 7;

    public int $stalledReportReviewed = 5;

    public int $stalledTransferPending = 7;

    public int $stalledProposal = 30;

    public int $offTrackPace = 80;

    public string $dailyBriefTime = '07:30';

    public string $weeklyDigestTime = '08:00';

    /**
     * Form property => settings key.
     */
    private const Keys = [
        'overdueRepeat' => 'overdue_repeat_days',
        'escalateManagerAfter' => 'escalate_manager_after_days',
        'escalateTopAfter' => 'escalate_top_after_days',
        'stalledTaskInProgress' => 'stalled_task_in_progress_days',
        'stalledTaskUnderReview' => 'stalled_task_under_review_days',
        'stalledReportDraft' => 'stalled_report_draft_days',
        'stalledReportReviewed' => 'stalled_report_reviewed_days',
        'stalledTransferPending' => 'stalled_transfer_pending_days',
        'stalledProposal' => 'stalled_proposal_days',
        'offTrackPace' => 'off_track_pace_percent',
        'dailyBriefTime' => 'daily_brief_time',
        'weeklyDigestTime' => 'weekly_digest_time',
    ];

    public function mount(NotificationSettings $settings): void
    {
        $values = $settings->all();

        $this->workingDays = array_map('intval', $values['working_days']);
        $this->dueSoonDays = implode(', ', $values['due_soon_days']);

        foreach (self::Keys as $property => $key) {
            $this->{$property} = $values[$key];
        }
    }

    public function save(NotificationSettings $settings): void
    {
        Gate::authorize('manage-notification-settings');

        $validated = $this->validate([
            'workingDays' => ['required', 'array', 'min:1'],
            'workingDays.*' => ['integer', 'between:1,7'],
            'dueSoonDays' => ['required', 'regex:/^\s*\d{1,2}(\s*,\s*\d{1,2})*\s*$/'],
            'overdueRepeat' => ['required', 'integer', 'between:1,30'],
            'escalateManagerAfter' => ['required', 'integer', 'between:1,60'],
            'escalateTopAfter' => ['required', 'integer', 'gt:escalateManagerAfter', 'max:90'],
            'stalledTaskInProgress' => ['required', 'integer', 'between:1,365'],
            'stalledTaskUnderReview' => ['required', 'integer', 'between:1,365'],
            'stalledReportDraft' => ['required', 'integer', 'between:1,365'],
            'stalledReportReviewed' => ['required', 'integer', 'between:1,365'],
            'stalledTransferPending' => ['required', 'integer', 'between:1,365'],
            'stalledProposal' => ['required', 'integer', 'between:1,365'],
            'offTrackPace' => ['required', 'integer', 'between:1,100'],
            'dailyBriefTime' => ['required', 'date_format:H:i'],
            'weeklyDigestTime' => ['required', 'date_format:H:i'],
        ]);

        $stages = collect(explode(',', $validated['dueSoonDays']))->map(fn (string $days): int => (int) trim($days))->filter()->unique()->sortDesc()->values()->all();
        $days = array_values(array_unique(array_map('intval', $validated['workingDays'])));

        $values = ['working_days' => $days, 'due_soon_days' => $stages];

        foreach (self::Keys as $property => $key) {
            $values[$key] = $validated[$property];
        }

        $settings->save($values);

        Flux::toast(variant: 'success', text: __('notifications.admin.saved'));
    }
}; ?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="notification-rules-heading">
    <x-ui.page-header heading-id="notification-rules-heading" :title="__('notifications.admin.title')">
        <x-slot:actions>
            <x-ui.button tone="primary" icon="check" wire:click="save">{{ __('notifications.admin.save') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="grid max-w-4xl gap-10">
        <fieldset class="grid gap-3">
            <legend class="type-heading">{{ __('notifications.admin.working_days') }}</legend>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.admin.working_days_help') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach (range(1, 7) as $weekday)
                    <label class="inline-flex min-h-10 items-center gap-2 rounded-full border border-secondary-soft px-3 text-sm has-checked:border-primary has-checked:bg-primary/5 dark:border-secondary-medium">
                        <input type="checkbox" wire:model="workingDays" value="{{ $weekday }}" class="size-4 accent-primary" />
                        {{ \Carbon\CarbonImmutable::now()->startOfWeek()->addDays($weekday - 1)->translatedFormat('l') }}
                    </label>
                @endforeach
            </div>
            @error('workingDays') <p class="text-sm text-status-critical">{{ $message }}</p> @enderror
            @error('workingDays.*') <p class="text-sm text-status-critical">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset class="grid gap-4 @3xl/directory:grid-cols-2">
            <legend class="type-heading">{{ __('notifications.admin.deadlines') }}</legend>
            <x-ui.input :label="__('notifications.admin.due_soon_days')" :description="__('notifications.admin.due_soon_days_help')" wire:model="dueSoonDays" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.overdue_repeat')" wire:model="overdueRepeat" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.escalate_manager')" wire:model="escalateManagerAfter" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.escalate_top')" wire:model="escalateTopAfter" />
        </fieldset>

        <fieldset class="grid gap-4 @3xl/directory:grid-cols-2">
            <legend class="type-heading">{{ __('notifications.admin.stalled') }}</legend>
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_task_in_progress')" wire:model="stalledTaskInProgress" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_task_under_review')" wire:model="stalledTaskUnderReview" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_report_draft')" wire:model="stalledReportDraft" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_report_reviewed')" wire:model="stalledReportReviewed" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_transfer_pending')" wire:model="stalledTransferPending" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_proposal')" wire:model="stalledProposal" />
        </fieldset>

        <fieldset class="grid gap-4 @3xl/directory:grid-cols-2">
            <legend class="type-heading">{{ __('notifications.admin.indicators_and_digests') }}</legend>
            <x-ui.input type="number" min="1" max="100" :label="__('notifications.admin.off_track_pace')" wire:model="offTrackPace" />
            <x-ui.input type="time" :label="__('notifications.admin.daily_brief_time')" wire:model="dailyBriefTime" />
            <x-ui.input type="time" :label="__('notifications.admin.weekly_digest_time')" wire:model="weeklyDigestTime" />
        </fieldset>
    </form>
</section>
```
(Open `ui/input.blade.php` first: if it has no `description` prop, render the help text as a `<p>` under the input instead. Flux inputs surface `@error` for the bound property automatically through `flux:field`; confirm and add explicit `@error` lines where they do not.)

Lang (en) `notifications.admin`:
```php
    'admin' => [
        'title' => 'Notification rules',
        'save' => 'Save rules',
        'saved' => 'Notification rules saved.',
        'working_days' => 'Working days',
        'working_days_help' => 'Deadline, overdue, escalation and stalled alerts count only these days and are never sent on other days.',
        'deadlines' => 'Deadlines',
        'due_soon_days' => 'Warn before a due date (working days)',
        'due_soon_days_help' => 'Comma-separated, for example 7, 3, 1. A warning on the day itself is always sent.',
        'overdue_repeat' => 'Repeat overdue reminders every (working days)',
        'escalate_manager' => 'Escalate to the program manager after (working days overdue)',
        'escalate_top' => 'Escalate to management after (working days overdue)',
        'stalled' => 'Stalled work (working days without a status change)',
        'stalled_task_in_progress' => 'Task in progress',
        'stalled_task_under_review' => 'Task under review',
        'stalled_report_draft' => 'Report in draft',
        'stalled_report_reviewed' => 'Report reviewed, awaiting approval',
        'stalled_transfer_pending' => 'Tranche pending after its scheduled date',
        'stalled_proposal' => 'Proposal in the same status',
        'indicators_and_digests' => 'Indicators and digests',
        'off_track_pace' => 'Off track below this % of the expected pace',
        'daily_brief_time' => 'Daily brief time',
        'weekly_digest_time' => 'Weekly digest time',
    ],
```
(ar):
```php
    'admin' => [
        'title' => 'قواعد الإشعارات',
        'save' => 'حفظ القواعد',
        'saved' => 'حُفظت قواعد الإشعارات.',
        'working_days' => 'أيام العمل',
        'working_days_help' => 'تُحتسب تنبيهات المواعيد والتأخير والتصعيد والتوقف على هذه الأيام فقط، ولا تُرسل في غيرها.',
        'deadlines' => 'المواعيد',
        'due_soon_days' => 'التنبيه قبل الموعد (بأيام العمل)',
        'due_soon_days_help' => 'مفصولة بفواصل، مثل 7, 3, 1. يُرسل دائمًا تنبيه في يوم الموعد نفسه.',
        'overdue_repeat' => 'تكرار تذكير التأخير كل (أيام عمل)',
        'escalate_manager' => 'التصعيد إلى مدير البرنامج بعد (أيام عمل من التأخير)',
        'escalate_top' => 'التصعيد إلى الإدارة بعد (أيام عمل من التأخير)',
        'stalled' => 'العمل المتوقف (أيام عمل دون تغيير في الحالة)',
        'stalled_task_in_progress' => 'مهمة قيد التنفيذ',
        'stalled_task_under_review' => 'مهمة قيد المراجعة',
        'stalled_report_draft' => 'تقرير في المسودة',
        'stalled_report_reviewed' => 'تقرير مراجَع بانتظار الاعتماد',
        'stalled_transfer_pending' => 'دفعة معلّقة بعد موعدها المجدول',
        'stalled_proposal' => 'مقترح في الحالة نفسها',
        'indicators_and_digests' => 'المؤشرات والملخصات',
        'off_track_pace' => 'خارج المسار تحت هذه النسبة % من الوتيرة المتوقعة',
        'daily_brief_time' => 'وقت الموجز اليومي',
        'weekly_digest_time' => 'وقت الملخص الأسبوعي',
    ],
```
`titles.notification_rules` = `'Notification rules'` / `'قواعد الإشعارات'`; `navigation.notification_settings` = `'Notification rules'` / `'قواعد الإشعارات'`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/NavigationTest.php tests/Feature/LocalizationTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Providers/AppServiceProvider.php routes/web.php resources/views lang tests/Feature/Notifications/NotificationSettingsAdminTest.php`

### Phase 4 close-out

- [ ] `php artisan test --compact tests/Feature/Notifications` → PASS.
- [ ] `vendor/bin/pint --dirty --format agent`.
- [ ] `record-rule` (glob `app/Notifications/Alerts/**`): "Every scheduled alert goes through AlertDispatcher (filter → ledger claim → notify). Stage keys embed the date they depend on; day counts always use WorkingCalendar; thresholds always come from NotificationSettings, never literals."
- [ ] Post progress update, continue to Phase 5.

---

# PHASE 5 — Digests and quick actions

Delivers: the daily brief (07:30 on working days, only when non-empty), the weekly digest (08:00 on the first working day of the week: program managers per program, the General Manager across all), and inline quick actions (task Start / Mark done, report Mark reviewed / Approve, transfer Mark received → edit form, overdue alert Snooze reminder), each re-authorised and state-checked on the server and recorded as an activity.

Digests use module `system` and skip the view-permission check (their content is built only from the reader's own items / managed programs); they are never mutable.

### Task 5.1: Daily brief

**Files:**
- Create: `app/Notifications/Alerts/DailyBriefScanner.php`
- Modify: `lang/{en,ar}/notifications.php` (`digests.daily`)
- Test: `tests/Feature/Notifications/DailyBriefTest.php`

**Behaviour:** working days only; per user who is direct on something: due today, overdue, stalled (from `StalledScanner::stalledSubjects()`), new assignments (their `*_you` action notifications from the last 24 hours); sent once per day (ledger stage = date) only when the total is above zero; subject `user` / the recipient; link `dashboard`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Activity;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\Alerts\DailyBriefScanner;
use Carbon\CarbonImmutable;

test('the daily brief counts my due, overdue and new assignments and goes once a day', function () {
    $me = userWithRole('Managers', insertHrEmployee());
    Activity::withoutRecording(function () use ($me): void {
        foreach (['2026-09-23', '2026-09-18'] as $due) {
            $task = Task::factory()->create(['due_date' => $due]);
            $task->taskAssignees()->delete();
            $task->taskAssignees()->create(['employee_id' => $me->employee_id]);
        }
    });
    $this->travelTo(CarbonImmutable::parse('2026-09-23 07:30'));

    expect(app(DailyBriefScanner::class)->scan(CarbonImmutable::now()))->toBe(1)
        ->and(app(DailyBriefScanner::class)->scan(CarbonImmutable::now()))->toBe(0);

    $brief = UserNotification::query()->forUser($me)->sole();

    expect($brief->kind->value)->toBe('digest')
        ->and(sentencesFor($me)[0])->toBe('Your day: 1 due today, 1 overdue, 0 stalled, 0 new assignments');
});

test('nobody gets an empty brief and nothing goes on a weekend', function () {
    $me = userWithRole('Managers', insertHrEmployee());

    expect(app(DailyBriefScanner::class)->scan(CarbonImmutable::parse('2026-09-23 07:30')))->toBe(0)
        ->and(app(DailyBriefScanner::class)->scan(CarbonImmutable::parse('2026-09-26 07:30')))->toBe(0)
        ->and(UserNotification::query()->forUser($me)->count())->toBe(0);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement** `app/Notifications/Alerts/DailyBriefScanner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\UserNotification;
use App\Notifications\NotificationPayload;
use Carbon\CarbonImmutable;

/**
 * Each person's morning summary of their own items. Sent only when there is
 * something in it.
 */
final class DailyBriefScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private StalledScanner $stalled,
        private WorkingCalendar $calendar,
        private AlertDispatcher $dispatcher,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->isWorkingDay($today)) {
            return 0;
        }

        $empty = ['due_today' => 0, 'overdue' => 0, 'stalled' => 0, 'assigned' => 0];
        $counts = [];

        foreach ($this->catalog->items($today) as $item) {
            $bucket = $item->dueDate->isSameDay($today) ? 'due_today' : 'overdue';

            foreach ($item->directUserIds as $userId) {
                $counts[$userId] ??= $empty;
                $counts[$userId][$bucket]++;
            }
        }

        foreach ($this->stalled->stalledSubjects($today) as $stall) {
            foreach ($stall['userIds'] as $userId) {
                $counts[$userId] ??= $empty;
                $counts[$userId]['stalled']++;
            }
        }

        UserNotification::query()
            ->where('kind', NotificationKind::Action->value)
            ->where('created_at', '>=', $now->subDay())
            ->where('data->key', 'like', '%\_you')
            ->toBase()
            ->selectRaw('notifiable_id, count(*) as aggregate')
            ->groupBy('notifiable_id')
            ->pluck('aggregate', 'notifiable_id')
            ->each(function (int|string $count, int|string $userId) use (&$counts, $empty): void {
                $counts[(int) $userId] ??= $empty;
                $counts[(int) $userId]['assigned'] = (int) $count;
            });

        $sent = 0;

        foreach ($counts as $userId => $count) {
            if (array_sum($count) === 0) {
                continue;
            }

            $payload = new NotificationPayload(
                kind: NotificationKind::Digest,
                module: 'system',
                priority: NotificationPriority::Info,
                key: 'notifications.digests.daily',
                params: array_map('strval', $count),
                subjectType: 'user',
                subjectId: $userId,
                link: ['route' => 'dashboard'],
            );

            $sent += $this->dispatcher->send('daily_brief', $today->toDateString(), $payload, [$userId], [], [$userId]);
        }

        return $sent;
    }
}
```
Lang (en): `'digests' => ['daily' => 'Your day: :due_today due today, :overdue overdue, :stalled stalled, :assigned new assignments']`. (ar): `'digests' => ['daily' => 'يومك: :due_today مستحقة اليوم، :overdue متأخرة، :stalled متوقفة، :assigned إسنادات جديدة']`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/DailyBriefTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/Alerts/DailyBriefScanner.php lang tests/Feature/Notifications/DailyBriefTest.php`

### Task 5.2: Weekly digest and the digest clock

**Files:**
- Create: `app/Notifications/Alerts/WeeklyDigestScanner.php`, `app/Console/Commands/SendNotificationDigests.php` (via `php artisan make:command SendNotificationDigests --no-interaction`)
- Modify: `routes/console.php`, `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/WeeklyDigestTest.php`

**Behaviour:** first working day of the week only. Per program: overdue items, off-pace indicators, open risks (happened with no mitigation — the risks page's "challenges without solution"), deadlines in the next 14 days. Each program manager gets one digest per managed program (subject `program`, link `programs.show`); every General Manager gets one across all programs (subject `user`, link `executive-summary.index`). Stage = ISO week. `notifications:digests` runs every minute and queues each digest once per day/week when its configured time has passed (`Cache::add` guard; the ledger is the real dedupe).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Jobs\RunScanner;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Risk;
use App\Models\Task;
use App\Models\UserNotification;
use App\Notifications\Alerts\DailyBriefScanner;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WeeklyDigestScanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

test('program managers get their program digest and the general manager the whole picture, on monday only', function () {
    $managerEmployee = insertHrEmployee();
    $manager = userWithRole('Managers', $managerEmployee);
    $generalManager = userWithRole('General Manager');
    Activity::withoutRecording(function () use ($managerEmployee): void {
        $program = Program::factory()->create(['manager_id' => $managerEmployee, 'name' => 'WASH']);
        $project = Project::factory()->create(['program_id' => $program->id]);
        $milestone = Milestone::factory()->create(['project_id' => $project->id]);
        Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'due_date' => '2026-09-15']);
        Task::factory()->create(['source_type' => 'milestone', 'source_id' => $milestone->id, 'due_date' => '2026-09-25']);
        Risk::factory()->create(['project_id' => $project->id, 'happened' => true, 'solution' => null]);
    });

    expect(app(WeeklyDigestScanner::class)->scan(CarbonImmutable::parse('2026-09-22 08:00')))->toBe(0); // Tuesday

    app(WeeklyDigestScanner::class)->scan(CarbonImmutable::parse('2026-09-21 08:00'));
    app(WeeklyDigestScanner::class)->scan(CarbonImmutable::parse('2026-09-21 09:00'));

    expect(sentencesFor($manager))->toBe(['WASH this week: 1 overdue, 0 indicators off track, 1 open risks, 1 deadlines in the next 14 days'])
        ->and(UserNotification::query()->forUser($generalManager)->sole()->kind->value)->toBe('digest');
});

test('the digest clock queues each digest once when its time has passed', function () {
    Queue::fake();
    app(NotificationSettings::class)->save(['daily_brief_time' => '07:30', 'weekly_digest_time' => '08:00']);

    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:29'));
    $this->artisan('notifications:digests')->assertSuccessful();
    Queue::assertNothingPushed();

    $this->travelTo(CarbonImmutable::parse('2026-09-21 08:05'));
    $this->artisan('notifications:digests');
    $this->artisan('notifications:digests');

    Queue::assertPushed(RunScanner::class, fn (RunScanner $job): bool => $job->scanner === DailyBriefScanner::class);
    Queue::assertPushed(RunScanner::class, 2);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Notifications/Alerts/WeeklyDigestScanner.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\Indicator;
use App\Models\Program;
use App\Models\Risk;
use App\Notifications\EmployeeRecipients;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;

/**
 * Monday-morning (first working day) picture per program.
 */
final class WeeklyDigestScanner implements Scanner
{
    public function __construct(
        private DeadlineCatalog $catalog,
        private WorkingCalendar $calendar,
        private OffTrackIndicatorScanner $offTrack,
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
        private EmployeeRecipients $employees,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $today = $now->startOfDay();

        if (! $this->calendar->firstWorkingDayOnOrAfter($today->startOfWeek())->isSameDay($today)) {
            return 0;
        }

        $week = $now->format('o-\WW');
        $stats = $this->statsByProgram($today, $now);
        $programs = Program::query()->whereIn('id', array_keys($stats))->get(['id', 'name', 'manager_id']);
        $managers = $this->employees->usersFor($programs->pluck('manager_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $sent = 0;

        foreach ($programs as $program) {
            $managerId = $managers[(int) $program->manager_id] ?? null;

            if ($managerId === null) {
                continue;
            }

            $payload = $this->digest('notifications.digests.weekly_program', ['program' => $program->name, ...$stats[$program->id]], 'program', $program->id, ['route' => 'programs.show', 'parameters' => ['program' => $program->id]]);
            $sent += $this->dispatcher->send('weekly_digest', $week, $payload, [$managerId], [], [$managerId]);
        }

        $totals = array_reduce($stats, fn (array $carry, array $row): array => array_map(fn (string $key): int => $carry[$key] + $row[$key], array_combine(array_keys($carry), array_keys($carry))), ['overdue' => 0, 'off_track' => 0, 'open_risks' => 0, 'upcoming' => 0]);

        foreach ($this->resolver->usersWithRoles(['General Manager']) as $userId) {
            $payload = $this->digest('notifications.digests.weekly_all', ['programs' => count($stats), ...$totals], 'user', $userId, ['route' => 'executive-summary.index']);
            $sent += $this->dispatcher->send('weekly_digest', $week, $payload, [$userId], [], [$userId]);
        }

        return $sent;
    }

    /**
     * @return array<int, array{overdue: int, off_track: int, open_risks: int, upcoming: int}>
     */
    private function statsByProgram(CarbonImmutable $today, CarbonImmutable $now): array
    {
        $empty = ['overdue' => 0, 'off_track' => 0, 'open_risks' => 0, 'upcoming' => 0];
        $stats = [];

        foreach ($this->catalog->items($today->addDays(14)) as $item) {
            $programId = $this->catalog->programIdFor($item);

            if ($programId === null) {
                continue;
            }

            $stats[$programId] ??= $empty;
            $stats[$programId][$item->dueDate->lt($today) ? 'overdue' : 'upcoming']++;
        }

        Indicator::query()->with('progress')->get()->each(function (Indicator $indicator) use (&$stats, $empty, $now): void {
            $programId = $indicator->activityProgramId();

            if ($programId !== null && $this->offTrack->isOffPace($indicator, $now)) {
                $stats[$programId] ??= $empty;
                $stats[$programId]['off_track']++;
            }
        });

        Risk::query()
            ->join('projects', 'projects.id', '=', 'risks.project_id')
            ->where('risks.happened', true)
            ->where(fn ($query) => $query->whereNull('risks.solution')->orWhere('risks.solution', ''))
            ->groupBy('projects.program_id')
            ->toBase()
            ->selectRaw('projects.program_id, count(*) as aggregate')
            ->pluck('aggregate', 'program_id')
            ->each(function (int|string $count, int|string $programId) use (&$stats, $empty): void {
                $stats[(int) $programId] ??= $empty;
                $stats[(int) $programId]['open_risks'] = (int) $count;
            });

        return $stats;
    }

    /**
     * @param  array<string, int|string>  $params
     * @param  array{route: string, parameters?: array<string, int>}  $link
     */
    private function digest(string $key, array $params, string $subjectType, int $subjectId, array $link): NotificationPayload
    {
        return new NotificationPayload(
            kind: NotificationKind::Digest,
            module: 'system',
            priority: NotificationPriority::Info,
            key: $key,
            params: array_map('strval', $params),
            subjectType: $subjectType,
            subjectId: $subjectId,
            link: $link,
        );
    }
}
```

`app/Console/Commands/SendNotificationDigests.php`:
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunScanner;
use App\Notifications\Alerts\DailyBriefScanner;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\Alerts\WeeklyDigestScanner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Runs every minute; queues the daily brief and the weekly digest once their
 * admin-configured time has passed. The scanners themselves decide working
 * days and the ledger stops duplicates.
 */
class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:digests';

    protected $description = 'Queue the daily brief and weekly digest when their time has come';

    public function handle(NotificationSettings $settings): int
    {
        $now = CarbonImmutable::now();
        $clock = $now->format('H:i');

        if ($clock >= (string) $settings->get('daily_brief_time') && Cache::add('notifications.digests.daily.'.$now->toDateString(), true, $now->endOfDay())) {
            RunScanner::dispatch(DailyBriefScanner::class);
        }

        if ($clock >= (string) $settings->get('weekly_digest_time') && Cache::add('notifications.digests.weekly.'.$now->toDateString(), true, $now->endOfDay())) {
            RunScanner::dispatch(WeeklyDigestScanner::class);
        }

        return self::SUCCESS;
    }
}
```
`routes/console.php`: `Schedule::command('notifications:digests')->everyMinute()->withoutOverlapping();`

Lang (en) `digests`: `'weekly_program' => ':program this week: :overdue overdue, :off_track indicators off track, :open_risks open risks, :upcoming deadlines in the next 14 days'`, `'weekly_all' => 'This week across :programs programs: :overdue overdue, :off_track indicators off track, :open_risks open risks, :upcoming deadlines in the next 14 days'`. (ar): `'weekly_program' => ':program هذا الأسبوع: :overdue متأخرة، :off_track مؤشرات خارج المسار، :open_risks مخاطر قائمة، :upcoming مواعيد خلال 14 يومًا'`, `'weekly_all' => 'هذا الأسبوع في :programs برامج: :overdue متأخرة، :off_track مؤشرات خارج المسار، :open_risks مخاطر قائمة، :upcoming مواعيد خلال 14 يومًا'`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/WeeklyDigestTest.php tests/Feature/Notifications/DailyBriefTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications/Alerts/WeeklyDigestScanner.php app/Console/Commands/SendNotificationDigests.php routes/console.php lang tests/Feature/Notifications/WeeklyDigestTest.php`

### Task 5.3: Quick actions

**Files:**
- Create: `app/Notifications/QuickActions.php`
- Modify: `app/Notifications/NotificationPayload.php` (`fromActivity` gains `array $actions = []`), `app/Jobs/FanOutActivity.php` (actions per recipient), `app/Notifications/Alerts/OverdueScanner.php` (snooze action), `app/Livewire/Concerns/ManagesInbox.php` (`runQuickAction`), `resources/views/components/notifications/row.blade.php` (buttons), `resources/views/pages/transfers/⚡index.blade.php` (`?edit=` deep link), `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/QuickActionsTest.php`

**Interfaces — Produces:** `QuickActions::forSubject(?string $subjectType, ?int $subjectId, User $user): list<array{type: string}>`; `QuickActions::run(User $user, UserNotification $notification, string $type): array{ok: bool, message: string, redirect: ?string}`; types `task.start`, `task.mark_done`, `report.mark_reviewed`, `report.approve`, `transfer.mark_received`, `alert.snooze`; `ManagesInbox::runQuickAction(string $id, string $type)`; transfers index `#[Url(as: 'edit')] $editTransfer`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\ArStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\UserNotification;
use App\Notifications\QuickActions;
use Livewire\Livewire;

function taskNotificationFor(\App\Models\User $assignee, TaskStatus $status = TaskStatus::Todo): array
{
    $task = Activity::withoutRecording(function () use ($assignee, $status) {
        $task = Task::factory()->create(['status' => $status, 'title' => 'Plan']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);

        return $task;
    });
    test()->actingAs(userWithRole('Managers'));
    $task->update(['priority' => 'high']);

    return [$task, UserNotification::query()->forUser($assignee)->sole()];
}

test('a task notification offers start and mark done to someone who may update tasks', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [, $notification] = taskNotificationFor($assignee);

    expect(array_column($notification->data['actions'], 'type'))->toBe(['task.start', 'task.mark_done']);
});

test('marking a task done from the bell records the actor and reads the notification', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [$task, $notification] = taskNotificationFor($assignee);

    Livewire::actingAs($assignee)->test('notifications.bell')->call('runQuickAction', $notification->id, 'task.mark_done');

    expect($task->fresh()->status)->toBe(TaskStatus::Done)
        ->and($task->fresh()->completed_date)->not->toBeNull()
        ->and(Activity::query()->latest('id')->first()->actor_id)->toBe($assignee->id)
        ->and($notification->fresh()->read_at)->not->toBeNull();
});

test('a quick action on work already done changes nothing and says so', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [$task, $notification] = taskNotificationFor($assignee);
    Activity::withoutRecording(fn () => $task->update(['status' => TaskStatus::Done]));

    $result = app(QuickActions::class)->run($assignee, $notification, 'task.mark_done');

    expect($result['ok'])->toBeFalse()->and($result['message'])->toBe('notifications.quick.already_done');
});

test('a quick action is re-authorised on the server', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [, $notification] = taskNotificationFor($assignee);
    $assignee->syncRoles(['Media Team']); // no tasks.update any more

    $result = app(QuickActions::class)->run($assignee->fresh(), $notification, 'task.mark_done');

    expect($result['ok'])->toBeFalse()->and($result['message'])->toBe('notifications.quick.forbidden');
});

test('an action that was never offered cannot be run', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    [, $notification] = taskNotificationFor($assignee);

    expect(app(QuickActions::class)->run($assignee, $notification, 'report.approve')['message'])->toBe('notifications.quick.unavailable');
});

test('meal can mark a draft report reviewed and then approve it', function () {
    $meal = userWithRole('MEAL');
    $report = Activity::withoutRecording(fn () => Report::factory()->create(['status' => ReportStatus::Draft]));
    $this->actingAs(userWithRole('MEAL'));
    $report->update(['value' => '10']);
    $this->travel(6)->minutes();
    $notification = UserNotification::query()->forUser($meal)->sole();

    expect(array_column($notification->data['actions'], 'type'))->toBe(['report.mark_reviewed']);

    $this->actingAs($meal);
    expect(app(QuickActions::class)->run($meal, $notification, 'report.mark_reviewed')['ok'])->toBeTrue()
        ->and($report->fresh()->status)->toBe(ReportStatus::Reviewed)
        ->and($report->fresh()->submit_date)->not->toBeNull();
});

test('mark received opens the tranche edit form instead of guessing amounts', function () {
    $financial = userWithRole('Financial');
    $transfer = Activity::withoutRecording(fn () => TransfersTracking::factory()->create());
    $this->actingAs(userWithRole('Financial'));
    $transfer->update(['payment_amount' => '1500.00']);
    $notification = UserNotification::query()->forUser($financial)->sole();

    $result = app(QuickActions::class)->run($financial, $notification, 'transfer.mark_received');

    expect($result['redirect'])->toBe(route('transfers.index', ['edit' => $transfer->id]))
        ->and($transfer->fresh()->ar_status)->toBe(ArStatus::Pending);

    Livewire::actingAs($financial)->withQueryParams(['edit' => $transfer->id])->test('pages::transfers.index')
        ->assertSet('showTransferModal', true)
        ->assertSet('editingTransferId', $transfer->id);
});

test('an overdue alert can be snoozed until tomorrow', function () {
    $assignee = userWithRole('Managers', insertHrEmployee());
    Activity::withoutRecording(function () use ($assignee): void {
        $task = Task::factory()->create(['due_date' => '2026-09-18']);
        $task->taskAssignees()->delete();
        $task->taskAssignees()->create(['employee_id' => $assignee->employee_id]);
    });
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-21 09:00'));
    app(\App\Notifications\Alerts\OverdueScanner::class)->scan(\Carbon\CarbonImmutable::now());
    $alert = UserNotification::query()->forUser($assignee)->sole();

    expect(array_column($alert->data['actions'], 'type'))->toContain('alert.snooze');

    app(QuickActions::class)->run($assignee, $alert, 'alert.snooze');

    expect($alert->fresh()->snoozed_until->format('Y-m-d H:i'))->toBe('2026-09-22 08:00');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`app/Notifications/QuickActions.php`:
```php
<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ArStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;

/**
 * One-click actions offered inside a notification. Offered only to people
 * whose permissions allow them; re-checked (permission and current state)
 * when run, because the record may have moved on since. Every write goes
 * through the model, so it is recorded as an activity by the acting user.
 */
final class QuickActions
{
    public function __construct(private Inbox $inbox) {}

    /**
     * @return list<array{type: string}>
     */
    public function forSubject(?string $subjectType, ?int $subjectId, User $user): array
    {
        if ($subjectType === null || $subjectId === null) {
            return [];
        }

        $types = match ($subjectType) {
            'task' => $user->can('tasks.update') ? match (Task::query()->whereKey($subjectId)->value('status')) {
                TaskStatus::Todo->value => ['task.start', 'task.mark_done'],
                TaskStatus::InProgress->value, TaskStatus::UnderReview->value => ['task.mark_done'],
                default => [],
            } : [],
            'report' => $user->can('reports.update') ? match (Report::query()->whereKey($subjectId)->value('status')) {
                ReportStatus::Draft->value => ['report.mark_reviewed'],
                ReportStatus::Reviewed->value => ['report.approve'],
                default => [],
            } : [],
            'transfer' => $user->can('transfers.update') && TransfersTracking::query()->whereKey($subjectId)->value('ar_status') === ArStatus::Pending->value
                ? ['transfer.mark_received'] : [],
            default => [],
        };

        return array_map(fn (string $type): array => ['type' => $type], $types);
    }

    /**
     * @return array{ok: bool, message: string, redirect: string|null}
     */
    public function run(User $user, UserNotification $notification, string $type): array
    {
        if (! in_array($type, array_column($notification->data['actions'] ?? [], 'type'), true)) {
            return $this->result(false, 'unavailable');
        }

        $id = (int) $notification->subject_id;

        return match ($type) {
            'task.start', 'task.mark_done' => $this->runTask($user, $id, $type),
            'report.mark_reviewed', 'report.approve' => $this->runReport($user, $id, $type),
            'transfer_mark_received' => $user->can('transfers.update')
                ? ['ok' => true, 'message' => 'notifications.quick.opening', 'redirect' => route('transfers.index', ['edit' => $id])]
                : $this->result(false, 'forbidden'),
            'alert_snooze' => $this->snooze($user, $notification),
            default => $this->result(false, 'unavailable'),
        };
    }

    /**
     * @return array{ok: bool, message: string, redirect: string|null}
     */
    private function runTask(User $user, int $id, string $type): array
    {
        if (! $user->can('tasks.update')) {
            return $this->result(false, 'forbidden');
        }

        $task = Task::query()->find($id);

        if ($task === null) {
            return $this->result(false, 'missing');
        }

        if ($type === 'task.start') {
            if ($task->status !== TaskStatus::Todo) {
                return $this->result(false, 'already_started');
            }

            $task->status = TaskStatus::InProgress;
        } else {
            if ($task->status === TaskStatus::Done) {
                return $this->result(false, 'already_done');
            }

            $task->status = TaskStatus::Done;
            $task->completed_date ??= CarbonImmutable::today();
            $task->progress_percentage = 100;
        }

        $task->save();

        return $this->result(true, 'done');
    }

    /**
     * @return array{ok: bool, message: string, redirect: string|null}
     */
    private function runReport(User $user, int $id, string $type): array
    {
        if (! $user->can('reports.update')) {
            return $this->result(false, 'forbidden');
        }

        $report = Report::query()->find($id);

        if ($report === null) {
            return $this->result(false, 'missing');
        }

        if ($type === 'report.mark_reviewed') {
            if ($report->status !== ReportStatus::Draft) {
                return $this->result(false, 'already_done');
            }

            $report->fill(['status' => ReportStatus::Reviewed, 'submit_date' => now()->toDateString()])->save();
        } else {
            if ($report->status !== ReportStatus::Reviewed) {
                return $this->result(false, 'already_done');
            }

            $report->fill(['status' => ReportStatus::Approved])->save();
        }

        return $this->result(true, 'done');
    }

    /**
     * @return array{ok: bool, message: string, redirect: string|null}
     */
    private function snooze(User $user, UserNotification $notification): array
    {
        $this->inbox->snooze($user, $notification->id, 'tomorrow');

        return $this->result(true, 'snoozed');
    }

    /**
     * @return array{ok: bool, message: string, redirect: null}
     */
    private function result(bool $ok, string $message): array
    {
        return ['ok' => $ok, 'message' => 'notifications.quick.'.$message, 'redirect' => null];
    }
}
```
(`report.mark_reviewed` / `approve` apply the same state rules as `reports/⚡show`'s `submitForReview()` / `approve()`; `task.mark_done` mirrors `tasks/⚡index`'s `quickSetStatus(Done)`.)

`NotificationPayload::fromActivity()` gains a trailing `array $actions = []` parameter passed to `actions:`. `FanOutActivity::handle()` injects `QuickActions $quickActions` and passes `$activity->event === $activity->subject_type.'.deleted' ? [] : $quickActions->forSubject($activity->subject_type, $activity->subject_id, $user)`.

`OverdueScanner`'s payload call passes `actions: [['type' => 'alert.snooze']]` (7th argument of `AlertDispatcher::payload`).

`ManagesInbox` gains:
```php
    public function runQuickAction(string $id, string $type): void
    {
        $user = auth()->user();
        $notification = UserNotification::query()->forUser($user)->find($id);

        if ($notification === null) {
            return;
        }

        $result = app(QuickActions::class)->run($user, $notification, $type);

        if ($type !== 'alert.snooze') {
            app(Inbox::class)->markRead($user, $id);
        }

        $this->dispatch('notification-received');

        if ($result['redirect'] !== null) {
            $this->redirect($result['redirect'], navigate: true);

            return;
        }

        Flux::toast(variant: $result['ok'] ? 'success' : 'warning', text: __($result['message']));
    }
```
(imports `App\Models\UserNotification`, `App\Notifications\QuickActions`, `Flux\Flux`.)

`row.blade.php` — inside the `min-w-0 flex-1` column, replace `{{ $slot }}` with:
```blade
        @if (! empty($notification->data['actions']))
            <div class="mt-2 flex flex-wrap gap-2" data-quick-actions>
                @foreach ($notification->data['actions'] as $action)
                    <button
                        type="button"
                        wire:click="runQuickAction('{{ $notification->id }}', '{{ $action['type'] }}')"
                        wire:loading.attr="disabled"
                        class="inline-flex min-h-8 items-center rounded-full border border-secondary-soft px-3 text-xs font-semibold text-secondary-dark hover:border-primary hover:text-status-critical dark:border-secondary-medium dark:text-white"
                    >{{ __('notifications.quick.labels.'.str_replace('.', '_', $action['type'])) }}</button>
                @endforeach
            </div>
        @endif
```

`transfers/⚡index.blade.php`:
```php
    /**
     * Deep link from a notification's "Mark received": opens that tranche's
     * edit form once, then clears itself.
     */
    #[Url(as: 'edit', except: '')]
    public string $editTransfer = '';
```
and at the end of `mount()`:
```php
        if (ctype_digit($this->editTransfer)) {
            $this->openTransferEdit((int) $this->editTransfer);
        }

        $this->editTransfer = '';
```

Lang (en) `quick`:
```php
    'quick' => [
        'labels' => [
            'task_start' => 'Start',
            'task_mark_done' => 'Mark done',
            'report_mark_reviewed' => 'Mark reviewed',
            'report_approve' => 'Approve',
            'transfer_mark_received' => 'Mark received',
            'alert_snooze' => 'Snooze reminder',
        ],
        'done' => 'Done.',
        'snoozed' => 'Reminder snoozed until tomorrow.',
        'opening' => 'Opening the tranche…',
        'already_done' => 'Already done — nothing changed.',
        'already_started' => 'Already started — nothing changed.',
        'forbidden' => 'You are not allowed to do that.',
        'missing' => 'This item no longer exists.',
        'unavailable' => 'That action is not available here.',
    ],
```
(ar):
```php
    'quick' => [
        'labels' => [
            'task_start' => 'بدء',
            'task_mark_done' => 'تعليم كمنجزة',
            'report_mark_reviewed' => 'تعليم كمراجَع',
            'report_approve' => 'اعتماد',
            'transfer_mark_received' => 'تعليم كمستلمة',
            'alert_snooze' => 'تأجيل التذكير',
        ],
        'done' => 'تم.',
        'snoozed' => 'أُجّل التذكير حتى الغد.',
        'opening' => 'جارٍ فتح الدفعة…',
        'already_done' => 'أُنجز مسبقًا — لم يتغير شيء.',
        'already_started' => 'بدأ مسبقًا — لم يتغير شيء.',
        'forbidden' => 'ليس لديك صلاحية لذلك.',
        'missing' => 'هذا العنصر لم يعد موجودًا.',
        'unavailable' => 'هذا الإجراء غير متاح هنا.',
    ],
```
(Label keys use underscores — `task_start` for the `task.start` action — because lang keys cannot contain dots; the row maps them with `str_replace('.', '_', ...)`.)

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications tests/Feature/TransferTrackingTest.php tests/Feature/TransfersTrackingTest.php tests/Feature/FundingWorkflowTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Notifications app/Jobs/FanOutActivity.php app/Livewire/Concerns/ManagesInbox.php resources/views lang tests/Feature/Notifications/QuickActionsTest.php`

### Phase 5 close-out

- [ ] `php artisan test --compact tests/Feature/Notifications` → PASS.
- [ ] `vendor/bin/pint --dirty --format agent`.
- [ ] Post progress update, continue to Phase 6.

---

# PHASE 6 — Preferences and housekeeping

Delivers: per-module mute toggles on Settings → Notifications; pruning (read notifications after 61 days, activities after 2 years; unread kept forever); `composer run dev` gains Reverb; an index/performance pass; Supervisor configs and the cron line (in the final report).

### Task 6.1: Module mutes

**Files:**
- Modify: `resources/views/pages/notifications/⚡settings.blade.php`, `lang/{en,ar}/notifications.php`
- Test: `tests/Feature/Notifications/NotificationMutesTest.php`

**Interfaces — Produces:** on `pages::notifications.settings`: computed `mutableModules(): list<string>` (modules in `config('notifications.modules')` whose view permission the user holds), `muted: list<string>`, `toggleMute(string $module): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\NotificationMute;
use Livewire\Livewire;

test('only modules I can see are offered for muting', function () {
    $financial = userWithRole('Financial');

    Livewire::actingAs($financial)->test('pages::notifications.settings')
        ->assertSeeText(__('notifications.modules.transfers'))
        ->assertDontSeeText(__('notifications.modules.tasks'));
});

test('muting and unmuting a module', function () {
    $user = userWithRole('Managers');

    $page = Livewire::actingAs($user)->test('pages::notifications.settings')->call('toggleMute', 'tasks');

    expect(NotificationMute::query()->where('user_id', $user->id)->pluck('module')->all())->toBe(['tasks']);
    $page->assertSet('muted', ['tasks'])->call('toggleMute', 'tasks');
    expect(NotificationMute::query()->count())->toBe(0);
});

test('a module outside my permissions cannot be muted by a crafted call', function () {
    $user = userWithRole('Media Team');

    Livewire::actingAs($user)->test('pages::notifications.settings')->call('toggleMute', 'transfers');

    expect(NotificationMute::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement** — the settings page component gains (imports `App\Models\NotificationMute`, `App\Models\Permission`, `Livewire\Attributes\Computed`):
```php
    /** @var list<string> */
    public array $muted = [];

    public function mount(): void
    {
        $this->popups = auth()->user()->notification_popups;
        $this->muted = NotificationMute::query()->where('user_id', auth()->id())->orderBy('module')->pluck('module')->all();
    }

    /**
     * Modules I receive notifications about, and so may mute.
     *
     * @return list<string>
     */
    #[Computed]
    public function mutableModules(): array
    {
        $user = auth()->user();

        return array_values(array_filter(array_keys(config('notifications.modules')), function (string $module) use ($user): bool {
            $permission = config("notifications.view_permissions.{$module}", "{$module}.view");

            return Permission::query()->where('name', $permission)->exists() && $user->can($permission);
        }));
    }

    public function toggleMute(string $module): void
    {
        if (! in_array($module, $this->mutableModules, true)) {
            return;
        }

        $mute = NotificationMute::query()->where('user_id', auth()->id())->where('module', $module)->first();

        $mute === null
            ? NotificationMute::query()->create(['user_id' => auth()->id(), 'module' => $module])
            : $mute->delete();

        $this->muted = NotificationMute::query()->where('user_id', auth()->id())->orderBy('module')->pluck('module')->all();
    }
```
Template, after the popup preference:
```blade
        <fieldset class="mt-8 grid gap-2" data-module-mutes>
            <legend class="text-sm font-semibold">{{ __('notifications.mutes_label') }}</legend>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.mutes_help') }}</p>
            <ul class="mt-2 grid gap-1">
                @foreach ($this->mutableModules as $module)
                    <li class="flex items-center justify-between gap-3 py-1.5" wire:key="mute-{{ $module }}">
                        <span class="text-sm">{{ __('notifications.modules.'.$module) }}</span>
                        <label class="inline-flex items-center gap-2 text-sm text-secondary-medium dark:text-secondary-soft">
                            <input type="checkbox" wire:click="toggleMute('{{ $module }}')" @checked(in_array($module, $muted, true)) class="size-4 accent-primary" />
                            {{ __('notifications.muted') }}
                        </label>
                    </li>
                @endforeach
            </ul>
        </fieldset>
```
Lang (en): `'mutes_label' => 'Mute modules'`, `'mutes_help' => 'Muted modules stop sending you team-wide updates. Anything assigned to you, or about items you own, still reaches you.'`, `'muted' => 'Muted'`. (ar): `'mutes_label' => 'كتم الوحدات'`, `'mutes_help' => 'الوحدات المكتومة لا ترسل إليك تحديثات الفريق العامة. أما ما يُسند إليك أو يخص عناصرك فيصلك دائمًا.'`, `'muted' => 'مكتومة'`.

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/NotificationMutesTest.php tests/Feature/Notifications/NotificationSettingsPageTest.php tests/Feature/Notifications/RecipientResolverTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add resources/views/pages/settings lang tests/Feature/Notifications/NotificationMutesTest.php`

### Task 6.2: Pruning

**Files:**
- Modify: `app/Models/Activity.php`, `app/Models/UserNotification.php` (`Prunable`), `config/notifications.php` (`retention`), `routes/console.php`
- Test: `tests/Feature/Notifications/PruningTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Activity;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

function storedNotification(User $user, ?string $readAt): UserNotification
{
    return UserNotification::query()->create([
        'id' => (string) Str::uuid(), 'type' => 'test', 'notifiable_type' => 'user', 'notifiable_id' => $user->id,
        'data' => [], 'module' => 'tasks', 'read_at' => $readAt,
    ]);
}

test('read notifications go after 61 days; unread ones stay forever', function () {
    $user = User::factory()->create();
    $old = storedNotification($user, now()->subDays(62)->toDateTimeString());
    $recent = storedNotification($user, now()->subDays(60)->toDateTimeString());
    $unread = storedNotification($user, null);
    $unread->forceFill(['created_at' => now()->subYears(3)])->save();

    $this->artisan('model:prune', ['--model' => [UserNotification::class]])->assertSuccessful();

    expect(UserNotification::query()->pluck('id')->sort()->values()->all())->toBe(collect([$recent->id, $unread->id])->sort()->values()->all());
});

test('activities go after two years', function () {
    $make = fn (string $at) => Activity::withoutRecording(fn () => Activity::query()->forceCreate([
        'subject_type' => 'task', 'subject_id' => 1, 'module' => 'tasks', 'event' => 'task.updated',
        'changes' => [], 'properties' => [], 'created_at' => $at, 'updated_at' => $at,
    ]));
    $expired = $make(now()->subYears(2)->subDay()->toDateTimeString());
    $kept = $make(now()->subYears(2)->addDay()->toDateTimeString());

    $this->artisan('model:prune', ['--model' => [Activity::class]])->assertSuccessful();

    expect(Activity::query()->pluck('id')->all())->toBe([$kept->id]);
});

test('pruning is scheduled daily', function () {
    $prune = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command, 'model:prune'));

    expect($prune)->not->toBeNull()
        ->and($prune->command)->toContain('Activity')->toContain('UserNotification')
        ->and($prune->expression)->toBe('0 0 * * *');
});
```

- [ ] **Step 2: Run** — FAIL.

- [ ] **Step 3: Implement**

`config/notifications.php`:
```php
    /*
    | Housekeeping: read notifications and old activity are pruned daily.
    | Unread notifications are never pruned.
    */
    'retention' => [
        'read_notification_days' => 61,
        'activity_years' => 2,
    ],
```
`Activity` — `use Prunable;` and:
```php
    /**
     * Activity older than the retention period (default two years).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subYears((int) config('notifications.retention.activity_years')));
    }
```
`UserNotification` — `use Prunable;` and:
```php
    /**
     * Read notifications past the retention period (default 61 days). Unread
     * ones are kept however old.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->whereNotNull('read_at')->where('read_at', '<', now()->subDays((int) config('notifications.retention.read_notification_days')));
    }
```
`routes/console.php`:
```php
Schedule::command('model:prune', ['--model' => [Activity::class, UserNotification::class]])->daily();
```

- [ ] **Step 4: Run** — `php artisan test --compact tests/Feature/Notifications/PruningTest.php` → PASS.
- [ ] **Step 5: Stage** — `git add app/Models/Activity.php app/Models/UserNotification.php config/notifications.php routes/console.php tests/Feature/Notifications/PruningTest.php`

### Task 6.3: `composer run dev` runs Reverb

**Files:**
- Modify (only if Reverb does not register itself): `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Notifications/DevProcessesTest.php`

- [ ] **Step 1: Check** — `grep -rn "DevCommands" vendor/laravel/reverb/src`. If Reverb already registers `reverb:start` with `artisan dev`, this task is only the test.
- [ ] **Step 2: Write the test** (read `Illuminate\Foundation\DevCommands::commands()` first and assert on the key it really returns for a command's artisan string):
```php
<?php

use Illuminate\Foundation\DevCommands;

test('artisan dev starts the reverb server beside the queue listener', function () {
    $commands = collect(DevCommands::commands())->map(fn ($command): string => json_encode($command));

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'reverb:start')))->toBeTrue()
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'queue:')))->toBeTrue();
});
```
- [ ] **Step 3: Implement (if needed)** — `AppServiceProvider::boot()`:
```php
        if ($this->app->runningInConsole()) {
            DevCommands::artisan('reverb:start', 'reverb');
        }
```
The default `queue:listen` process already covers the queue in development; production uses `queue:work` under Supervisor.
- [ ] **Step 4: Run** — PASS.
- [ ] **Step 5: Stage** — `git add app/Providers/AppServiceProvider.php tests/Feature/Notifications/DevProcessesTest.php`

### Task 6.4: Index and performance pass

**Files:**
- Test: `tests/Feature/Notifications/NotificationPerformanceTest.php`
- Modify only if a check fails: the query or migration it points at.

- [ ] **Step 1: Write the tests**

```php
<?php

use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Notifications\NotificationPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

function fillInbox(User $user, int $count): void
{
    foreach (range(1, $count) as $n) {
        $user->notify(new AppNotification(new NotificationPayload(
            kind: NotificationKind::Action, module: 'tasks', priority: NotificationPriority::Info,
            key: 'notifications.events.task.created', params: ['actor' => 'Sami', 'subject' => "Task {$n}"],
            subjectType: 'task', subjectId: $n, actorId: User::factory()->create()->id,
        )));
    }
}

test('the bell costs the same number of queries however full the inbox is', function () {
    $user = User::factory()->create();
    $measure = function () use ($user): int {
        cache()->flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($user)->test('notifications.bell');

        return count(DB::getQueryLog());
    };

    fillInbox($user, 3);
    $few = $measure();
    fillInbox($user, 25);

    expect($measure())->toBe($few);
});

test('the inbox lookups are indexed', function () {
    $indexed = collect(Schema::getIndexes('notifications'))->pluck('columns')->map(fn (array $columns): string => implode(',', $columns));

    expect($indexed)->toContain('notifiable_type,notifiable_id,read_at')
        ->toContain('notifiable_id,group_key,read_at')
        ->toContain('notifiable_id,subject_type,subject_id,read_at')
        ->toContain('snoozed_until');

    $activity = collect(Schema::getIndexes('activities'))->pluck('columns')->map(fn (array $columns): string => implode(',', $columns));

    expect($activity)->toContain('subject_type,subject_id,created_at')->toContain('program_id,created_at')->toContain('actor_id,created_at')->toContain('created_at');
});
```
- [ ] **Step 2: Run** — `php artisan test --compact tests/Feature/Notifications/NotificationPerformanceTest.php`. If the bell count grows with the inbox, an eager load is missing (`actor`) — fix it, never loosen the test.
- [ ] **Step 3: EXPLAIN check** (read-only, via the Boost `database-query` tool) on the local database for: the unread-count grouped query, the bell list query, the grouper lookup and `UnreadSubjects::ids()`; each must use one of the indexes above (no full scan of `notifications`). Record any index added as a new migration with a test assertion above.
- [ ] **Step 4: Stage** — `git add tests/Feature/Notifications/NotificationPerformanceTest.php` (+ any migration).

### Task 6.5: Final verification and report

- [ ] Run every notification test and every module test touched across the phases:
```bash
php artisan test --compact tests/Feature/Notifications
```
```bash
php artisan test --compact tests/Feature/Task*Test.php tests/Feature/Milestone*Test.php tests/Feature/Project*Test.php tests/Feature/Program*Test.php tests/Feature/AnnualPlan*Test.php tests/Feature/Strategy*Test.php tests/Feature/Risk*Test.php tests/Feature/Indicator*Test.php tests/Feature/Target*Test.php tests/Feature/Result*Test.php tests/Feature/Report*Test.php tests/Feature/Donor*Test.php tests/Feature/Proposal*Test.php tests/Feature/Funding*Test.php tests/Feature/Transfer*Test.php tests/Feature/Account*Test.php tests/Feature/Admin tests/Feature/Auth tests/Feature/Settings tests/Feature/Models tests/Feature/NavigationTest.php tests/Feature/DashboardTest.php tests/Feature/LocalizationTest.php tests/Feature/DatabaseSeederTest.php tests/Feature/DatabaseEncodingTest.php tests/Feature/UiComponentsTest.php
```
- [ ] `vendor/bin/pint --dirty --format agent`; `npm run build`.
- [ ] `git status` — everything staged, nothing committed.
- [ ] Write the final report (below) with the real test counts.

**Final report — ops content to include**

Commands (run once on each environment):
```bash
composer install
```
```bash
npm install
```
```bash
npm run build
```
```bash
php artisan reverb:install
```
(generates `REVERB_APP_ID/KEY/SECRET` in `.env` if missing.) `.env` keys:
```
BROADCAST_CONNECTION=reverb
QUEUE_CONNECTION=database
REVERB_APP_ID=<generated>
REVERB_APP_KEY=<generated>
REVERB_APP_SECRET=<generated>
REVERB_HOST=<public hostname, e.g. tracking.example.org>
REVERB_PORT=443
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```
(rebuild assets after changing any `VITE_*` value.)
```bash
php artisan migrate --force
```
```bash
php artisan config:cache
```
Queue worker (development: `composer run dev` already runs `queue:listen` and `reverb:start`):
```bash
php artisan queue:work --tries=3
```
Scheduler cron (VPS, `crontab -e` as the web user):
```
* * * * * cd /var/www/hc-tracking && php artisan schedule:run >> /dev/null 2>&1
```
Supervisor — `/etc/supervisor/conf.d/hc-tracking-reverb.conf`:
```ini
[program:hc-tracking-reverb]
process_name=%(program_name)s
command=php /var/www/hc-tracking/artisan reverb:start --host=0.0.0.0 --port=8080
directory=/var/www/hc-tracking
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
minfds=10000
redirect_stderr=true
stdout_logfile=/var/www/hc-tracking/storage/logs/reverb.log
stopwaitsecs=10
```
`/etc/supervisor/conf.d/hc-tracking-queue.conf`:
```ini
[program:hc-tracking-queue]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/hc-tracking/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
directory=/var/www/hc-tracking
user=www-data
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
redirect_stderr=true
stdout_logfile=/var/www/hc-tracking/storage/logs/queue.log
stopwaitsecs=3600
```
Then:
```bash
sudo supervisorctl reread
```
```bash
sudo supervisorctl update
```
After every deploy: `php artisan queue:restart` and `sudo supervisorctl restart hc-tracking-reverb`. Web server: proxy `wss://<host>/app` and `/apps` to `127.0.0.1:8080` with the `Upgrade`/`Connection` headers (Nginx `location /app { proxy_http_version 1.1; proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "Upgrade"; proxy_set_header Host $host; proxy_pass http://127.0.0.1:8080; }`, same for `/apps`). Paths, user and host are placeholders to adapt to the VPS.

Close with: "Please run the full suite: `php artisan test --compact`."
