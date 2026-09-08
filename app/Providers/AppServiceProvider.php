<?php

namespace App\Providers;

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
     * Map the polymorphic sources a plan_indicators row can be committed in.
     *
     * `plan_indicators.source_type` is VARCHAR(50), so short keys are stored
     * rather than fully qualified class names.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'yearly_plan' => YearlyPlan::class,
            'proposal' => Proposal::class,
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
