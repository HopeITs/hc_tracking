<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a MEAL coverage row covers (`meal_coverages.scope_type`).
 */
enum MealCoverageScope: string
{
    /** Every project of a governorate (HR city). */
    case City = 'city';

    /** One project, overriding its governorate's officer. */
    case Project = 'project';
}
