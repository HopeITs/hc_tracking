<?php

namespace App\Providers;

use App\Actions\Fortify\DisableTwoFactorAuthenticationUnlessRequired;
use App\Http\Middleware\EnsureTwoFactorIsEnabled;
use App\Models\Account;
use App\Models\AnnualPlan;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\HrDepartment;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\Risk;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Notifications\Alerts\NotificationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Component;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->disableDebugInProduction();

        // One instance per request or job, so queue workers never hold stale values.
        $this->app->scoped(NotificationSettings::class);

        $this->registerDevCommands();

        $this->configureFortifyActions();
    }

    /**
     * Debug error pages show code, SQL with its bindings, file paths and
     * request data, so production never renders them, even when
     * APP_DEBUG=true slips into the live .env. This runs in register(), before
     * any provider boots, so an error thrown during boot is covered too.
     *
     * The misconfiguration is logged once a day rather than on every request;
     * a cache failure falls back to logging, never to breaking the request.
     */
    protected function disableDebugInProduction(): void
    {
        if (! $this->app->isProduction() || ! config('app.debug')) {
            return;
        }

        config(['app.debug' => false]);

        $this->app->booted(function (): void {
            if (rescue(fn (): bool => Cache::add('security.debug-forced-off', true, now()->addDay()), true, report: false)) {
                Log::critical('APP_DEBUG=true is set in production; debug mode was forced off. Set APP_DEBUG=false in the live .env.');
            }
        });
    }

    /**
     * `php artisan dev` also runs the scheduler, so notification alerts,
     * digests and snooze wake-ups fire locally exactly as they will in
     * production (where cron runs schedule:run every minute).
     */
    protected function registerDevCommands(): void
    {
        DevCommands::artisan('schedule:work', 'scheduler');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configureGates();
        $this->configureLivewire();
        $this->configureRateLimiting();
    }

    /**
     * Livewire re-runs only its own list of route middleware (auth and
     * authorization) on update requests. The two-factor requirement joins it,
     * so a component rendered on a page that requires two-factor cannot be
     * driven by someone who still has to turn it on. Components rendered on
     * the security page itself (the layout's notification bell, say) carry
     * that page's route instead, so every hydrated component outside the
     * enrolment set is checked as well.
     */
    protected function configureLivewire(): void
    {
        Livewire::addPersistentMiddleware([EnsureTwoFactorIsEnabled::class]);

        Livewire::listen('hydrate', function (mixed $component): void {
            if ($component instanceof Component) {
                EnsureTwoFactorIsEnabled::guardLivewireComponent($component);
            }
        });

        // Every wire:click/wire:model/etc in the app posts to this single
        // endpoint, so it's the highest-value target for a volumetric
        // (resource-exhaustion) attack. Persistent middleware only re-applies
        // middleware already present on the page route being driven, so it
        // can't rate limit the endpoint itself; the update route has to be
        // throttled directly instead. $path keeps Livewire's obfuscated,
        // per-install endpoint path rather than hardcoding it.
        Livewire::setUpdateRoute(fn ($handle, $path) => Route::post($path, $handle)
            ->middleware(['throttle:livewire']));
    }

    /**
     * Fortify's own "disable two-factor" endpoint cannot switch it off for a
     * role that requires it; half-finished set-ups are still cleared.
     */
    protected function configureFortifyActions(): void
    {
        $this->app->bind(DisableTwoFactorAuthentication::class, DisableTwoFactorAuthenticationUnlessRequired::class);
    }

    /**
     * Abilities that are role-based rather than permission-based.
     */
    protected function configureGates(): void
    {
        Gate::define('view-activity-log', fn (User $user): bool => $user->hasAnyRole(['Super Admin', 'General Manager']));
        Gate::define('manage-notification-settings', fn (User $user): bool => $user->hasAnyRole(config('access-control.administrator_roles')));
    }

    /**
     * Rate limiters shared across the app (outside Fortify's own auth
     * limiters, which live in FortifyServiceProvider).
     *
     * Keyed by user id where available, falling back to IP for guests, so an
     * office sharing one IP never throttles each other the way a plain
     * per-IP limit would.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('livewire', fn (Request $request) => Limit::perMinute(180)->by(
            (string) ($request->user()?->id ?? $request->ip()),
        ));

        // File download/preview routes had no limiter at all: a signed-in
        // user (or a hijacked session) could otherwise script sequential
        // requests to exfiltrate every attachment in the system.
        RateLimiter::for('files', fn (Request $request) => Limit::perMinute(60)->by(
            (string) ($request->user()?->id ?? $request->ip()),
        ));

        RateLimiter::for('locale', fn (Request $request) => Limit::perMinute(10)->by(
            (string) ($request->user()?->id ?? $request->ip()),
        ));

        // The resend endpoint used a flat, IP-only throttle: a distributed
        // caller could spam resends for one still-authenticating login from
        // many IPs. Stacked with a per-IP limit so a single IP can't spam
        // resends across many guest sessions either.
        RateLimiter::for('email-two-factor-resend', fn (Request $request) => [
            Limit::perMinute(2)->by((string) $request->session()->get('login.id')),
            Limit::perMinute(5)->by($request->ip()),
        ]);
    }

    /**
     * Map every polymorphic type this application stores.
     *
     * Every morph column — `plan_indicators.source_type`, `media.mediable_type`
     * and `tasks.source_type` — is VARCHAR(50), so short keys are stored rather
     * than fully qualified class names.
     *
     * enforceMorphMap() is global: any model on either side of ANY morph must
     * appear here or getMorphClass() throws ClassMorphViolationException.
     * `annual_plan` and `proposal` are the sources a target can be committed
     * in; `project` and `milestone` are also owners a file can be attached to;
     * `milestone`, `proposal`, `project`, `program` and `department` are the
     * sources a task can be raised against; `user` is the model side of
     * spatie/laravel-permission's `model_has_roles`/`model_has_permissions`
     * polymorphic pivots and the notifiable side of the inbox. Every model
     * activity is recorded against (see App\Notifications\TracksActivity) is
     * mapped too, because `activities.subject_type` and
     * `notifications.subject_type` store these keys.
     */
    protected function configureMorphMap(): void
    {
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
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        // Turns three silent classes of bug into loud failures outside
        // production: lazy loading (N+1), mass assignment that quietly discards
        // attributes, and reading an attribute that was never retrieved.
        Model::shouldBeStrict(! app()->isProduction());

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
