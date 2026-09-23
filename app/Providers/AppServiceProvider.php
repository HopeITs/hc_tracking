<?php

namespace App\Providers;

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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request or job, so queue workers never hold stale values.
        $this->app->scoped(NotificationSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configureGates();
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
