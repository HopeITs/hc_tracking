<?php

declare(strict_types=1);

namespace App\Support\Locations;

use App\Models\ProposalLocation;
use Illuminate\Support\Facades\DB;

/**
 * Resolve the slug in a `projects/{project}` URL to the location row the
 * Projects screen shows (Y-16, D-93).
 *
 * The URL segment keeps its name: it is internal, and five call sites pass it
 * as `['project' => …]`. What changed is what it resolves to.
 *
 * Two answers, in order:
 *
 * 1. a **location row** with that slug — the new links;
 * 2. a **project** with that slug, answered by the row that took it over
 *    (`ProjectSuccessor`, D-94) so every saved link, and every page not yet
 *    moved to rows, keeps working. The caller redirects to the row's own URL.
 *
 * A slug that is neither, or a project no row carries (D-80), resolves to
 * nothing and the caller 404s — which is also the honest answer for a project
 * without a proposal, since one no longer exists (D-84, D-87).
 *
 * @deprecated Y-18 freezes these redirects in `project_redirects`.
 */
final class LocationFromSlug
{
    public function __construct(private ProjectSuccessor $successor) {}

    /**
     * The row whose own slug this is, or null.
     */
    public function row(string $slug): ?ProposalLocation
    {
        return ProposalLocation::query()->where('slug', $slug)->first();
    }

    /**
     * The row that took over the project with this slug, or null when no
     * project has it or no row carries that project.
     */
    public function successorOfProjectSlug(string $slug): ?ProposalLocation
    {
        // Through the query builder, not the model: this folder is scanned by
        // WaveFourProjectsGuardTest and only the mirror and the backfill may
        // name `Project` (Y-11).
        $projectId = DB::table('projects')->where('slug', $slug)->value('id');

        return $projectId === null ? null : $this->successor->rowFor((int) $projectId);
    }
}
