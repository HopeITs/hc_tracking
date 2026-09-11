<?php

namespace App\Providers;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\YearlyPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
    }

    /**
     * Map every polymorphic type this application stores.
     *
     * Both morph columns — `plan_indicators.source_type` and
     * `media.mediable_type` — are VARCHAR(50), so short keys are stored rather
     * than fully qualified class names.
     *
     * enforceMorphMap() is global: any model on either side of ANY morph must
     * appear here or getMorphClass() throws ClassMorphViolationException. The
     * first two entries are the sources a target can be committed in; the last
     * two are the owners a file can be attached to.
     *
     * `User` is still deliberately absent. It uses Notifiable, so the day a
     * `notifications` table appears, `notifiable_type` will throw until
     * 'user' => User::class is added — which is the point of enforcing the map.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'yearly_plan' => YearlyPlan::class,
            'proposal' => Proposal::class,
            'project' => Project::class,
            'milestone' => Milestone::class,
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
