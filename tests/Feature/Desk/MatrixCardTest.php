<?php

use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Project;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-16 09:00:00');

    Activity::withoutRecording(function (): void {
        $late = Project::factory()->create(['name' => 'Late Wells']);
        MonthlyReport::factory()->create(['project_id' => $late->id, 'period' => '2026-07-01', 'status' => MonthlyReportStatus::Draft]);
        MonthlyReport::factory()->create(['project_id' => $late->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Draft]);
        $waiting = Project::factory()->create(['name' => 'Waiting School']);
        MonthlyReport::factory()->create(['project_id' => $waiting->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Submitted]);
        $fine = Project::factory()->create(['name' => 'Fine Clinic']);
        MonthlyReport::factory()->create(['project_id' => $fine->id, 'period' => '2026-08-01', 'status' => MonthlyReportStatus::Validated]);
    });
});

test('the summary counts late reports and those waiting for validation', function () {
    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'XL'])
        ->assertSeeHtml('<b class="dk-num dk-bad">2</b>')
        ->assertSeeHtml('<b class="dk-num">1</b>')
        ->assertSee(['Late Wells', 'Waiting School', 'Fine Clinic']);
});

test('a legend state shows only the projects with it, and a second click shows all again', function () {
    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'XL'])
        ->call('toggleState', 'late')
        ->assertSet('state', 'late')
        ->assertSee('Late Wells')
        ->assertDontSee('Fine Clinic')
        ->call('toggleState', 'late')
        ->assertSet('state', '')
        ->assertSee('Fine Clinic');
});

test('at S the card lists the reports that need someone, late first', function () {
    Livewire::actingAs(userWithRole('General Manager', insertHrEmployee()))->test('desk.panels.matrix', ['size' => 'S'])
        ->assertDontSeeHtml('class="dk-mx2"')
        ->assertSeeInOrder(['Late Wells', 'July 2026', 'Late Wells', 'August 2026', 'Waiting School'])
        ->assertDontSee('Fine Clinic');
});
