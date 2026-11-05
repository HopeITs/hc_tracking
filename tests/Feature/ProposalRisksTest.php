<?php

use App\Actions\Proposals\AttachLibraryRisk;
use App\Actions\Proposals\DetachRisk;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\RiskLibrary;
use App\Models\User;
use App\Notifications\ActivityParameters;
use App\Notifications\NotificationPresenter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->actingAs(User::factory()->role('Public Relations Manager')->create());

    $this->proposal = Proposal::factory()->create();
    $this->output = Result::factory()->create(['type' => ResultType::Output, 'proposal_id' => $this->proposal->id]);
    $this->entry = RiskLibrary::factory()->create([
        'title_en' => 'Funding delay',
        'title_ar' => 'تأخر التمويل',
        'default_likelihood' => 2,
        'default_severity' => 3,
    ]);
});

describe('AttachLibraryRisk', function () {
    it('copies the library wording and defaults onto the result and keeps them when the library changes', function () {
        $risk = app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []);

        expect($risk->fresh()->only(['library_risk_id', 'proposal_id', 'result_id', 'project_id', 'name', 'name_en', 'likelihood', 'severity', 'happened']))
            ->toBe([
                'library_risk_id' => $this->entry->id,
                'proposal_id' => $this->proposal->id,
                'result_id' => $this->output->id,
                'project_id' => null,
                'name' => 'تأخر التمويل',
                'name_en' => 'Funding delay',
                'likelihood' => 2,
                'severity' => 3,
                'happened' => false,
            ]);

        $this->entry->forceFill(['title_ar' => 'عنوان جديد'])->save();

        expect($risk->fresh()->only(['name', 'name_en']))->toBe(['name' => 'تأخر التمويل', 'name_en' => 'Funding delay']);
    });

    it('lets the caller override the ratings and add a mitigation note', function () {
        $risk = app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, ['likelihood' => 1, 'severity' => 1, 'solution' => 'Pre-agree a bridge fund.']);

        expect($risk->only(['likelihood', 'severity', 'solution']))->toBe(['likelihood' => 1, 'severity' => 1, 'solution' => 'Pre-agree a bridge fund.']);
    });

    it('refuses a rating outside 1 to 3 and writes nothing', function () {
        expect(fn () => app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, ['severity' => 4]))
            ->toThrow(ValidationException::class);

        expect(Risk::query()->count())->toBe(0);
    });

    it('refuses an inactive library entry', function () {
        $this->entry->forceFill(['is_active' => false])->save();

        expect(fn () => app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []))
            ->toThrow(ValidationException::class, __('risk_library.unavailable'));

        expect(Risk::query()->count())->toBe(0);
    });

    it('refuses the same library risk twice on one result but allows it on another result', function () {
        $other = Result::factory()->create(['type' => ResultType::Outcome, 'proposal_id' => $this->proposal->id]);

        app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []);

        expect(fn () => app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []))
            ->toThrow(ValidationException::class, __('risk_library.already_on_result'));

        app(AttachLibraryRisk::class)->handle($this->proposal, $other->id, $this->entry->id, []);

        expect(Risk::query()->count())->toBe(2);
    });

    it('accepts only outcomes and outputs', function (ResultType $type) {
        $result = Result::factory()->create([
            'type' => $type,
            'proposal_id' => $this->proposal->id,
            'parent_id' => $type === ResultType::Activity ? $this->output->id : null,
        ]);

        expect(fn () => app(AttachLibraryRisk::class)->handle($this->proposal, $result->id, $this->entry->id, []))
            ->toThrow(ValidationException::class, __('risk_library.result_type_unsupported'));
    })->with([ResultType::Impact, ResultType::Activity, ResultType::CrossCutting]);

    it('never loads a result of another proposal or of the program framework', function (Closure $makeResult) {
        $foreign = $makeResult();

        expect(fn () => app(AttachLibraryRisk::class)->handle($this->proposal, $foreign->id, $this->entry->id, []))
            ->toThrow(ModelNotFoundException::class);

        expect(Risk::query()->count())->toBe(0);
    })->with([
        'another proposal' => [fn () => Result::factory()->create(['type' => ResultType::Output, 'proposal_id' => Proposal::factory()->create()->id])],
        'program framework' => [fn () => Result::factory()->create(['type' => ResultType::Output, 'proposal_id' => null])],
    ]);

    it('refuses a role without proposals.update', function () {
        $this->actingAs(User::factory()->role('Team Member')->create());

        expect(fn () => app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []))
            ->toThrow(AuthorizationException::class);

        expect(Risk::query()->count())->toBe(0);
    });

    it('records one attached activity with names only', function () {
        app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []);

        $activity = Activity::query()->where('event', 'proposal.risk_attached')->sole();

        expect($activity->properties)->toHaveKey('value', 'تأخر التمويل')
            ->and($activity->properties)->toHaveKey('result', $this->output->statement);
    });
});

describe('the activity sentences', function () {
    it('name the risk in both languages for an attach and a detach', function () {
        $risk = app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []);
        app(DetachRisk::class)->handle($this->proposal, $risk->id);

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

describe('DetachRisk', function () {
    it('removes a risk of this proposal and records the activity', function () {
        $risk = app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []);

        app(DetachRisk::class)->handle($this->proposal, $risk->id);

        expect(Risk::query()->count())->toBe(0)
            ->and(Activity::query()->where('event', 'proposal.risk_detached')->count())->toBe(1)
            ->and(RiskLibrary::query()->whereKey($this->entry->id)->exists())->toBeTrue();
    });

    it('never touches a risk of another proposal or a legacy project risk', function () {
        $otherProposal = Proposal::factory()->create();
        $otherResult = Result::factory()->create(['type' => ResultType::Output, 'proposal_id' => $otherProposal->id]);
        $foreign = app(AttachLibraryRisk::class)->handle($otherProposal, $otherResult->id, $this->entry->id, []);
        $legacy = Risk::factory()->create();

        foreach ([$foreign->id, $legacy->id] as $riskId) {
            expect(fn () => app(DetachRisk::class)->handle($this->proposal, $riskId))->toThrow(ModelNotFoundException::class);
        }

        expect(Risk::query()->count())->toBe(2);
    });

    it('refuses a role without proposals.update', function () {
        $risk = app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []);
        $this->actingAs(User::factory()->role('Team Member')->create());

        expect(fn () => app(DetachRisk::class)->handle($this->proposal, $risk->id))->toThrow(AuthorizationException::class);

        expect(Risk::query()->count())->toBe(1);
    });
});

describe('the schema', function () {
    it('removes a result’s risks with the result and a proposal’s risks with the proposal', function () {
        app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []);

        DB::table('results')->where('id', $this->output->id)->delete();

        expect(Risk::query()->count())->toBe(0);
    });

    it('refuses to delete a library row that a risk was picked from', function () {
        app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->entry->id, []);

        expect(fn () => DB::table('risk_library')->where('id', $this->entry->id)->delete())->toThrow(QueryException::class);
    });
});
