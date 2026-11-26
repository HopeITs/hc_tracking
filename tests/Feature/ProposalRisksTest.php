<?php

use App\Actions\Risks\DeleteFrameworkRisk;
use App\Actions\Risks\SaveFrameworkRisk;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Notifications\ActivityParameters;
use App\Notifications\NotificationPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(mealManager());

    $this->proposal = Proposal::factory()->create();
    $this->input = ['name' => 'تأخر التمويل', 'name_en' => 'Funding delay', 'likelihood' => 2, 'severity' => 3, 'solution' => ''];
});

describe('the activity sentences', function () {
    it('name the risk in both languages for an add and a remove', function () {
        $risk = app(SaveFrameworkRisk::class)->handle($this->proposal, null, $this->input);
        app(DeleteFrameworkRisk::class)->handle($this->proposal, $risk->id);

        foreach (['risk_attached', 'risk_detached'] as $event) {
            $activity = Activity::query()->where('event', 'proposal.'.$event)->sole();
            $data = ['key' => 'notifications.events.proposal.'.$event, 'params' => app(ActivityParameters::class)->for($activity), 'count' => 1];

            foreach (['en', 'ar'] as $locale) {
                $sentence = app(NotificationPresenter::class)->sentence($data, $locale);

                expect($sentence)->toContain('تأخر التمويل')->and($sentence)->not->toContain(':value')->and($sentence)->not->toContain(':subject');
            }
        }
    });
});

describe('the legacy rows', function () {
    it('never removes a legacy project risk through the logframe', function () {
        $rowRisk = Risk::factory()->create();

        expect(fn () => app(DeleteFrameworkRisk::class)->handle($this->proposal, $rowRisk->id))->toThrow(ModelNotFoundException::class);

        expect(Risk::query()->whereKey($rowRisk->id)->exists())->toBeTrue();
    });
});

describe('the schema', function () {
    it('removes a legacy result’s risks with the result and a proposal’s risks with the proposal', function () {
        $output = Result::factory()->create(['type' => ResultType::Output, 'proposal_id' => $this->proposal->id]);
        Risk::factory()->onProposalFramework($this->proposal)->create(['result_id' => $output->id]);

        DB::table('results')->where('id', $output->id)->delete();

        expect(Risk::query()->count())->toBe(0);

        app(SaveFrameworkRisk::class)->handle($this->proposal, null, $this->input);
        DB::table('proposals')->where('id', $this->proposal->id)->delete();

        expect(Risk::query()->count())->toBe(0);
    });
});
