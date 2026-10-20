<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\Proposal;
use App\Support\Desk\ProjectStage;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The proposal page folds into the project Story (Tracking Desk stage 3,
 * user decision 2026-10-20): opening a proposal with no tab lands on its
 * project's Story at the chapter it is in.
 *
 * A link with ?tab=… (or the record-agreement deep link) still opens the
 * proposal page, which stays the edit screen until the side sheets arrive
 * (stage 4). A proposal on no project the viewer may open stays where it is.
 */
class RedirectProposalToStory
{
    public function handle(Request $request, Closure $next): Response
    {
        $proposal = $request->route('proposal');

        if (! $request->isMethod('GET') || ! $proposal instanceof Proposal || $request->query->has('tab') || $request->query->has('record_agreement')) {
            return $next($request);
        }

        if ($request->user()?->can('projects.view') !== true) {
            return $next($request);
        }

        $project = $proposal->projects()
            ->select(['projects.id', 'projects.slug', 'projects.status', 'projects.end_date'])
            ->with('proposals:proposals.id,proposals.status')
            ->orderBy('projects.id')
            ->first();

        if (! $project instanceof Project) {
            return $next($request);
        }

        $stage = ProjectStage::stage($project, ProjectStage::lead($project->proposals));

        return redirect()->to(route('projects.show', $project).'#dk-ch-'.min($stage, 6));
    }
}
