<?php

namespace Tests;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\Attributes\Seeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

/**
 * Every Feature test starts from the seeded access-control catalog: the
 * permissions and roles exist exactly as config/access-control.php defines
 * them (seeded once per run by RefreshDatabase), so a test only has to pick
 * the role it acts as.
 */
#[Seeder(RolesAndPermissionsSeeder::class)]
abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
