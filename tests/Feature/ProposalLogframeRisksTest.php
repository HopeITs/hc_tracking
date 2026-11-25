<?php

use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create();
    DB::table('project_proposal')->insert(['project_id' => Project::factory()->create(['program_id' => $this->program->id])->id, 'proposal_id' => $this->proposal->id]);

    $this->outcome = riskNode($this->proposal, ResultType::Outcome, null, $this->program->id);
    $this->output = riskNode($this->proposal, ResultType::Output, $this->outcome);

    $this->editor = mealManager();

    $this->programRisk = Risk::factory()->onProgram($this->program)->rated(2, 3)->create(['name' => 'تأخر التمويل', 'name_en' => 'Funding delay']);
    $this->copied = Risk::factory()->onProposalFramework($this->proposal)->rated(2, 3)->create(['copied_from_id' => $this->programRisk->id, 'name' => 'تأخر التمويل', 'name_en' => 'Funding delay']);
    $this->specific = Risk::factory()->onProposalFramework($this->proposal)->rated(1, 2)->create(['name' => 'منع الوصول', 'name_en' => 'Access denied']);
});

function riskNode(Proposal $proposal, ResultType $type, ?Result $parent, ?int $programId = null): Result
{
    return Result::factory()->type($type)->create([
        'proposal_id' => $proposal->id,
        'parent_id' => $parent?->id,
        'program_id' => $parent?->program_id ?? $programId,
    ]);
}

function riskTab(User $user, Proposal $proposal): Testable
{
    return Livewire::actingAs($user)->test('proposals.logframe', ['proposalId' => $proposal->id]);
}

/**
 * @return array{name: string, name_en: string, likelihood: int, severity: int, solution: string}
 */
function typedRisk(string $english = 'Border closure'): array
{
    return ['name' => 'إغلاق الحدود', 'name_en' => $english, 'likelihood' => 2, 'severity' => 2, 'solution' => 'Pre-position stock.'];
}

describe('the Risks section', function () {
    it('lists copied and proposal-specific risks once under the whole framework, copied ones remove only', function () {
        riskTab($this->editor, $this->proposal)
            ->assertSeeHtml('data-test="proposal-risks"')
            ->assertSee(__('results_framework.risks_heading').' · 2')
            ->assertSee('Funding delay')
            ->assertSee('Access denied')
            ->assertSee(__('results_framework.risk_from_program'))
            ->assertDontSeeHtml('data-test="edit-proposal-risk-'.$this->copied->id.'"')
            ->assertSeeHtml('data-test="remove-proposal-risk-'.$this->copied->id.'"')
            ->assertSeeHtml('data-test="edit-proposal-risk-'.$this->specific->id.'"')
            ->assertSeeHtml('data-test="remove-proposal-risk-'.$this->specific->id.'"')
            ->assertSeeHtml('data-test="add-proposal-risk"')
            ->assertDontSeeHtml('data-test="result-risks-');
    });

    it('still lists a legacy risk that sits on one result', function () {
        $legacy = Risk::factory()->onProposalFramework($this->proposal)->create(['result_id' => $this->output->id, 'name_en' => 'Old result risk']);

        riskTab($this->editor, $this->proposal)
            ->assertSee('Old result risk')
            ->assertSeeHtml('data-test="edit-proposal-risk-'.$legacy->id.'"');
    });

    it('never lists the program risk itself or another proposal’s risks', function () {
        $foreign = Risk::factory()->onProposalFramework(Proposal::factory()->create())->create(['name_en' => 'Somewhere else']);

        riskTab($this->editor, $this->proposal)
            ->assertDontSeeHtml('data-test="risk-row-'.$this->programRisk->id.'"')
            ->assertDontSee('Somewhere else')
            ->assertDontSeeHtml('data-test="risk-row-'.$foreign->id.'"');
    });
});

describe('writing', function () {
    it('adds a proposal-specific risk through the typed form', function () {
        riskTab($this->editor, $this->proposal)
            ->call('openRiskForm')
            ->assertSet('showRiskModal', true)
            ->assertSeeHtml('data-test="risk-form"')
            ->set('riskForm', typedRisk())
            ->call('saveRisk')
            ->assertHasNoErrors()
            ->assertSet('showRiskModal', false)
            ->assertSee('Border closure');

        $risk = Risk::query()->where('name_en', 'Border closure')->sole();

        expect($risk->only(['proposal_id', 'proposal_location_id', 'copied_from_id', 'likelihood', 'severity']))
            ->toBe(['proposal_id' => $this->proposal->id, 'proposal_location_id' => null, 'copied_from_id' => null, 'likelihood' => 2, 'severity' => 2]);
    });

    it('edits a proposal-specific risk', function () {
        riskTab($this->editor, $this->proposal)
            ->call('openRiskForm', $this->specific->id)
            ->assertSet('editingRiskId', $this->specific->id)
            ->assertSet('riskForm.name_en', 'Access denied')
            ->set('riskForm.name_en', 'Access blocked')
            ->call('saveRisk')
            ->assertHasNoErrors();

        expect($this->specific->fresh()->name_en)->toBe('Access blocked');
    });

    it('refuses to open a copied risk for editing', function () {
        riskTab($this->editor, $this->proposal)
            ->call('openRiskForm', $this->copied->id)
            ->assertHasErrors('riskForm.name')
            ->assertSet('showRiskModal', false);

        expect($this->copied->fresh()->name_en)->toBe('Funding delay');
    });

    it('removes a copied and a proposal-specific risk, never the program risk', function () {
        riskTab($this->editor, $this->proposal)
            ->call('removeRisk', $this->copied->id)
            ->call('removeRisk', $this->specific->id)
            ->assertSee(__('results_framework.no_proposal_risks'));

        expect(Risk::query()->onLogframeOf($this->proposal->id)->count())->toBe(0)
            ->and($this->programRisk->fresh())->not->toBeNull();
    });

    it('never edits or removes a risk of another proposal or of the program', function () {
        $foreign = Risk::factory()->onProposalFramework(Proposal::factory()->create())->create();

        foreach ([$foreign->id, $this->programRisk->id] as $riskId) {
            riskTab($this->editor, $this->proposal)->call('openRiskForm', $riskId)->assertNotFound();
            riskTab($this->editor, $this->proposal)->call('removeRisk', $riskId)->assertNotFound();
        }

        expect(Risk::query()->whereKey([$foreign->id, $this->programRisk->id])->count())->toBe(2);
    });
});

describe('permissions', function () {
    it('lets a PR officer read the section but refuses every forged write', function () {
        $viewer = User::factory()->role('Public Relations Manager')->create();

        riskTab($viewer, $this->proposal)
            ->assertSee('Access denied')
            ->assertDontSeeHtml('data-test="add-proposal-risk"')
            ->assertDontSeeHtml('data-test="remove-proposal-risk-');

        riskTab($viewer, $this->proposal)->call('openRiskForm')->assertForbidden();
        riskTab($viewer, $this->proposal)->set('riskForm', typedRisk('Forged'))->call('saveRisk')->assertForbidden();
        riskTab($viewer, $this->proposal)->call('removeRisk', $this->specific->id)->assertForbidden();

        expect(Risk::query()->onLogframeOf($this->proposal->id)->count())->toBe(2)
            ->and(Risk::query()->where('name_en', 'Forged')->exists())->toBeFalse();
    });
});

it('keeps the logframe tab at a flat query count whatever the number of risks', function () {
    $count = function (): int {
        riskTab($this->editor, $this->proposal);
        DB::flushQueryLog();
        DB::enableQueryLog();
        riskTab($this->editor, $this->proposal);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $count();

    Risk::factory()->onProposalFramework($this->proposal)->count(6)->create();

    expect($count())->toBe($small);
});
