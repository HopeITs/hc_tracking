<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\Proposal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The proposal page lives inside the project Story (Tracking Desk stage 4,
 * user decision 2026-10-20 "remove the proposal page, redirect all"): every
 * GET of a proposal lands on its project's Story with that proposal shown in
 * the Proposal block, on the tab asked for (?tab= becomes ?ptab=), and the
 * record-agreement deep link carried over so its dialog opens there.
 *
 * A proposal on no project the viewer may open keeps the standalone page.
 */
class RedirectProposalToStory
{
    public function handle(Request $request, Closure $next): Response
    {
        $proposal = $request->route('proposal');

        if (! $request->isMethod('GET') || ! $proposal instanceof Proposal || $request->user()?->can('projects.view') !== true) {
            return $next($request);
        }

        $project = $proposal->projects()
            ->select(['projects.id', 'projects.slug'])
            ->orderBy('projects.id')
            ->get()
            ->first(fn (Project $project): bool => $request->user()->can('view', $project));

        if (! $project instanceof Project) {
            return $next($request);
        }

        $tab = $request->query('ptab', $request->query('tab'));

        return redirect()->to(route('projects.show', array_filter([
            'project' => $project,
            'proposal' => $proposal->id,
            'ptab' => is_string($tab) && $tab !== '' ? $tab : null,
            'record_agreement' => $request->boolean('record_agreement') ? 1 : null,
        ], fn (mixed $value): bool => $value !== null)).'#proposal');
    }
}
