<?php

use Illuminate\Support\Facades\Schema;

/*
| Y-17: this file guarded the create form's duplicate detection — the
| one-open-project rule L94 and the "similar projects" confirmation. The
| [contract] withdraws rule L94 (two proposals may each deliver at one city,
| program and center; MEAL decides whether they share a monthly report, D-77)
| and §8 drops `projects.create`, so nothing creates a place by hand any
| more. Its six cases went with the feature and are named in the Y-17
| handoff; `LocationLifecycleTest` covers what survives of them ("a second
| row at the same place no longer blocks a restore").
|
| The one case left is about D-30, not about duplicates: a renewal is a new
| proposal with its own rows, never a chain of projects.
*/

test('there is no project chain column', function () {
    expect(Schema::hasColumn('projects', 'previous_project_id'))->toBeFalse();
});
