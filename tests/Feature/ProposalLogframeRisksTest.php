<?php

use App\Actions\Proposals\AttachLibraryRisk;
use App\Enums\ResultType;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\RiskLibrary;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create();
    DB::table('project_proposal')->insert(['project_id' => Project::factory()->create(['program_id' => $program->id])->id, 'proposal_id' => $this->proposal->id]);

    $this->impact = riskNode($this->proposal, ResultType::Impact, null, $program->id);
    $this->outcome = riskNode($this->proposal, ResultType::Outcome, $this->impact);
    $this->output = riskNode($this->proposal, ResultType::Output, $this->outcome);
    $this->activity = riskNode($this->proposal, ResultType::Activity, $this->output);

    $this->editor = User::factory()->role('Public Relations Manager')->create();
    $this->delay = RiskLibrary::factory()->create(['title_en' => 'Funding delay', 'title_ar' => 'تأخر التمويل', 'category' => 'financial', 'default_likelihood' => 2, 'default_severity' => 3]);
    $this->access = RiskLibrary::factory()->create(['title_en' => 'Access denied', 'title_ar' => 'منع الوصول', 'category' => 'security', 'default_likelihood' => 1, 'default_severity' => 3]);
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

describe('the node', function () {
    it('offers the risks drawer on outcomes and outputs only, with a count badge in text', function () {
        $this->actingAs($this->editor);
        app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->delay->id, []);

        riskTab($this->editor, $this->proposal)
            ->assertSeeHtml('data-test="result-risks-'.$this->outcome->id.'"')
            ->assertSeeHtml('data-test="result-risks-'.$this->output->id.'"')
            ->assertDontSeeHtml('data-test="result-risks-'.$this->impact->id.'"')
            ->assertDontSeeHtml('data-test="result-risks-'.$this->activity->id.'"')
            ->assertSee(trans_choice('risks.node_risk_count', 1, ['count' => 1]));
    });

    it('opens the drawer on one result and lists only its risks, with rating words', function () {
        $this->actingAs($this->editor);
        $here = app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->delay->id, []);
        $elsewhere = app(AttachLibraryRisk::class)->handle($this->proposal, $this->outcome->id, $this->access->id, []);

        riskTab($this->editor, $this->proposal)
            ->call('openRisks', $this->output->id)
            ->assertSet('showRisksDrawer', true)
            ->assertSet('riskResultId', $this->output->id)
            ->assertSeeHtml('data-risk="'.$here->id.'"')
            ->assertDontSeeHtml('data-risk="'.$elsewhere->id.'"')
            ->assertSee(__('risks.likelihood').': '.__('risks.rating_medium'))
            ->assertSee(__('risks.severity').': '.__('risks.rating_high'));
    });

    it('never opens the drawer on a result of another proposal', function () {
        $foreign = riskNode(Proposal::factory()->create(), ResultType::Output, null);

        riskTab($this->editor, $this->proposal)->call('openRisks', $foreign->id)->assertNotFound();
    });
});

describe('the picker', function () {
    it('offers only active library entries, grouped by category, and no free-text field', function () {
        RiskLibrary::factory()->inactive()->create(['title_en' => 'Retired risk', 'title_ar' => 'خطر موقوف']);

        riskTab($this->editor, $this->proposal)
            ->call('openRisks', $this->output->id)
            ->assertSee('Funding delay')
            ->assertSee('Access denied')
            ->assertDontSee('Retired risk')
            ->assertSeeHtml('data-picker-risk="'.$this->delay->id.'"')
            ->assertSee('financial')
            ->assertSee('security')
            ->assertDontSeeHtml('wire:model="riskName"');
    });

    it('attaches the picked library risks and closes the drawer on success', function () {
        riskTab($this->editor, $this->proposal)
            ->call('openRisks', $this->output->id)
            ->set('riskPicks', [$this->delay->id, $this->access->id])
            ->call('attachRisks')
            ->assertHasNoErrors()
            ->assertSet('riskPicks', []);

        expect(Risk::query()->where('result_id', $this->output->id)->orderBy('library_risk_id')->pluck('library_risk_id')->all())
            ->toBe([$this->delay->id, $this->access->id])
            ->and(Risk::query()->first()->project_id)->toBeNull();
    });

    it('refuses a risk already on the result and attaches nothing from that batch', function () {
        $this->actingAs($this->editor);
        app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->delay->id, []);

        riskTab($this->editor, $this->proposal)
            ->call('openRisks', $this->output->id)
            ->set('riskPicks', [$this->delay->id, $this->access->id])
            ->call('attachRisks')
            ->assertHasErrors(['riskPicks' => __('risk_library.already_on_result')]);

        expect(Risk::query()->count())->toBe(1);
    });

    it('asks for at least one pick', function () {
        riskTab($this->editor, $this->proposal)
            ->call('openRisks', $this->output->id)
            ->set('riskPicks', [])
            ->call('attachRisks')
            ->assertHasErrors(['riskPicks' => __('risks.pick_at_least_one')]);
    });
});

describe('removing and permissions', function () {
    it('detaches a risk of this proposal', function () {
        $this->actingAs($this->editor);
        $risk = app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->delay->id, []);

        riskTab($this->editor, $this->proposal)->call('openRisks', $this->output->id)->call('detachRisk', $risk->id)->assertHasNoErrors();

        expect(Risk::query()->count())->toBe(0);
    });

    it('never detaches a risk of another proposal', function () {
        $other = Proposal::factory()->create();
        $otherOutput = riskNode($other, ResultType::Output, null);
        $this->actingAs($this->editor);
        $foreign = app(AttachLibraryRisk::class)->handle($other, $otherOutput->id, $this->delay->id, []);

        riskTab($this->editor, $this->proposal)->call('detachRisk', $foreign->id)->assertNotFound();

        expect(Risk::query()->whereKey($foreign->id)->exists())->toBeTrue();
    });

    it('lets a viewer read the drawer but refuses every write', function () {
        $this->actingAs($this->editor);
        $risk = app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->delay->id, []);
        $viewer = User::factory()->role('Team Member')->create();

        riskTab($viewer, $this->proposal)
            ->call('openRisks', $this->output->id)
            ->assertSee('Funding delay')
            ->assertDontSeeHtml('data-test="risk-picker"');

        riskTab($viewer, $this->proposal)->set('riskResultId', $this->output->id)->set('riskPicks', [$this->access->id])->call('attachRisks')->assertForbidden();
        riskTab($viewer, $this->proposal)->call('detachRisk', $risk->id)->assertForbidden();

        expect(Risk::query()->count())->toBe(1);
    });
});

describe('the heat map', function () {
    it('counts only this proposal’s rated risks and lists the unrated ones apart', function () {
        $this->actingAs($this->editor);
        app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->delay->id, []);
        app(AttachLibraryRisk::class)->handle($this->proposal, $this->outcome->id, $this->delay->id, []);
        app(AttachLibraryRisk::class)->handle($this->proposal, $this->outcome->id, $this->access->id, ['likelihood' => null, 'severity' => null]);

        $other = Proposal::factory()->create();
        app(AttachLibraryRisk::class)->handle($other, riskNode($other, ResultType::Output, null)->id, $this->delay->id, []);

        riskTab($this->editor, $this->proposal)
            ->assertSeeHtml('data-risk-heat')
            ->assertSeeHtml('data-risk-cell="2:3"')
            ->assertSeeHtml('data-risk-count="2:3:2"')
            ->assertSee(trans_choice('risks.heat_unrated', 1, ['count' => 1]))
            ->assertSee(__('risks.heat_table_caption'));
    });

    it('is absent while the proposal has no risks', function () {
        riskTab($this->editor, $this->proposal)->assertDontSeeHtml('data-risk-heat');
    });
});

it('keeps the logframe tab at a flat query count whatever the number of risks', function () {
    $this->actingAs($this->editor);

    $count = function (): int {
        riskTab($this->editor, $this->proposal);
        DB::flushQueryLog();
        DB::enableQueryLog();
        riskTab($this->editor, $this->proposal);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    app(AttachLibraryRisk::class)->handle($this->proposal, $this->output->id, $this->delay->id, []);
    $small = $count();

    foreach (range(1, 6) as $index) {
        $result = riskNode($this->proposal, ResultType::Output, $this->outcome);
        app(AttachLibraryRisk::class)->handle($this->proposal, $result->id, $this->delay->id, []);
        app(AttachLibraryRisk::class)->handle($this->proposal, $result->id, $this->access->id, []);
    }

    expect($count())->toBe($small);
});
