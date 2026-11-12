<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PlanUpdateFile;
use App\Policies\PlanUpdatePolicy;
use App\Support\UploadName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PlanUpdateFileDownloadController extends Controller
{
    /**
     * Open a supporting file of a monthly update: everyone involved can see
     * them at every stage (the reviewers open them while checking). Images
     * and PDFs open in the browser; other files download.
     */
    public function __invoke(Request $request, PlanUpdateFile $planUpdateFile): StreamedResponse
    {
        abort_unless(PlanUpdatePolicy::canRead($request->user()), 403);
        abort_unless(Storage::disk($planUpdateFile->disk)->exists($planUpdateFile->path), 404);

        $name = UploadName::clean($planUpdateFile->original_name);
        $inline = in_array($planUpdateFile->mime_type, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);

        return Storage::disk($planUpdateFile->disk)->response($planUpdateFile->path, $name, [
            'Content-Type' => $planUpdateFile->mime_type ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
    }
}
