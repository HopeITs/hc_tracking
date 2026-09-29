<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarkTourSeenController extends Controller
{
    /**
     * Record that the guided tour has opened by itself for this person, so it
     * never does so again (it still opens whenever they press the tour button).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->tour_seen_at === null) {
            $user->forceFill(['tour_seen_at' => now()])->saveQuietly();
        }

        return response()->json(status: 204);
    }
}
