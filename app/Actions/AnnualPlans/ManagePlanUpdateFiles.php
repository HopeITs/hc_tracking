<?php

declare(strict_types=1);

namespace App\Actions\AnnualPlans;

use App\Models\ActivityPlan;
use App\Models\PlanUpdate;
use App\Models\PlanUpdateFile;
use App\Models\Project;
use App\Models\User;
use App\Policies\PlanUpdatePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * The supporting files of a whole monthly update (Rami, 2026-11-11: one set
 * for the report, not per activity): attendance sheets, photos, lists. They
 * live on the private local disk under plan-updates/{id}; a returned update
 * keeps them (and they can be removed or added again).
 */
final class ManagePlanUpdateFiles
{
    public const MAX_KILOBYTES = 10240;

    public function add(User $user, ActivityPlan $plan, Project $project, CarbonImmutable $month, UploadedFile $file): PlanUpdateFile
    {
        $month = $month->startOfMonth();
        $update = PlanUpdate::query()->where('activity_plan_id', $plan->id)->where('project_id', $project->id)->where('month', $month->toDateString())->first();

        if (! PlanUpdatePolicy::canFill($user, $plan, $project, $update)) {
            throw new AuthorizationException;
        }

        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES, 'mimes:pdf,jpg,jpeg,png,webp,xlsx,xls,csv,docx,doc,zip']])->validate();

        $update ??= PlanUpdate::query()->create(['activity_plan_id' => $plan->id, 'project_id' => $project->id, 'month' => $month->toDateString()]);
        $path = $file->store('plan-updates/'.$update->id, 'local');

        return $update->files()->create([
            'disk' => 'local',
            'path' => (string) $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $user->id,
        ]);
    }

    public function remove(User $user, PlanUpdateFile $file): void
    {
        $update = $file->planUpdate()->with(['plan', 'project'])->firstOrFail();

        if (! PlanUpdatePolicy::canFill($user, $update->plan, $update->project, $update)) {
            throw new AuthorizationException;
        }

        Storage::disk($file->disk)->delete($file->path);
        $file->delete();
    }
}
