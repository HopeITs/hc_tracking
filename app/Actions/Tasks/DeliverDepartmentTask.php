<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Enums\DeliverableKind;
use App\Enums\DeliveryState;
use App\Enums\FileType;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Rules\SecureUpload;
use App\Support\StoredFiles;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;
use Throwable;

/**
 * Hand over a media deliverable (contracts.md §12.2; T31): requested or
 * returned → delivered, and the task moves to `under review`.
 *
 * One transaction: the task is re-read through the visibility scope and
 * locked, compared with the state the caller saw (a second identical call
 * writes nothing), authorized, then the evidence each requested kind needs is
 * validated before any file is stored: video → a link; photos → a jpg/png file
 * or a link; file → any file or a link; text → the text. Files are stored
 * encrypted as task attachments and removed again when the transaction fails.
 *
 * A re-delivery keeps the last `returned_reason` so the reviewer can check the
 * fix against it; accepting clears it. The move records one
 * `department_task.delivered` activity, never a generic status change or an
 * attachment entry per file.
 */
final class DeliverDepartmentTask
{
    private const MaxFiles = 10;

    private const MaxTextLength = 10000;

    /**
     * @param  array{delivered_on: string, delivered_link?: ?string, delivered_text?: ?string}  $input
     * @param  list<UploadedFile>  $files
     *
     * @throws ModelNotFoundException<Task> when the task is not visible to the user
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Task $task, DeliveryState $expectedState, array $input, array $files = []): Task
    {
        /** @var list<string> $storedPaths */
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($task, $expectedState, $input, $files, &$storedPaths): Task {
                $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->kind !== TaskKind::MediaDeliverable) {
                    throw ValidationException::withMessages(['kind' => __('department_tasks.only_media_deliverables_are_delivered')]);
                }

                Gate::authorize('deliver', $locked);

                $this->assertDeliverable($locked, $expectedState);

                $kinds = array_values($locked->media_kinds?->all() ?? []);
                $delivery = $this->validate($kinds, $input, $files);

                $fromState = $locked->delivery_state;
                $fromStatus = $locked->status;

                Activity::withoutRecording(function () use ($locked, $delivery, $files, &$storedPaths): void {
                    foreach ($files as $upload) {
                        $attachment = new TaskAttachment(['task_id' => $locked->id]);

                        StoredFiles::save($attachment, $upload, 'task-attachments/'.$locked->id, '', ['date' => $delivery['delivered_on']], [], 'task attachment');

                        $storedPaths[] = $attachment->path;
                    }

                    $locked->delivery_state = DeliveryState::Delivered;
                    $locked->status = TaskStatus::UnderReview;
                    $locked->delivered_on = CarbonImmutable::parse($delivery['delivered_on']);
                    $locked->delivered_link = $delivery['delivered_link'];
                    $locked->delivered_text = $delivery['delivered_text'];
                    $locked->save();
                });

                $locked->recordActivity('department_task.delivered', [
                    'delivery_state' => [$fromState?->value, DeliveryState::Delivered->value],
                    'status' => [$fromStatus->value, TaskStatus::UnderReview->value],
                ], [
                    'delivered_on' => $delivery['delivered_on'],
                    'kinds' => array_map(fn (DeliverableKind $kind): string => $kind->value, $kinds),
                    'files' => count($files),
                ]);

                return $locked;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                StoredFiles::forget('local', $path, 'task attachment');
            }

            throw $exception;
        }
    }

    /**
     * The caller's view must still be current, and only a requested or
     * returned deliverable is handed over.
     */
    private function assertDeliverable(Task $task, DeliveryState $expectedState): void
    {
        if ($task->delivery_state !== $expectedState) {
            throw ValidationException::withMessages(['delivery_state' => __('department_tasks.stale_delivery_state')]);
        }

        if ($expectedState !== DeliveryState::Requested && $expectedState !== DeliveryState::Returned) {
            throw ValidationException::withMessages(['delivery_state' => __('department_tasks.already_delivered')]);
        }
    }

    /**
     * Validate the delivery against the kinds the task asks for, before any
     * file touches the disk.
     *
     * @param  list<DeliverableKind>  $kinds
     * @param  array<string, mixed>  $input
     * @param  list<UploadedFile>  $files
     * @return array{delivered_on: string, delivered_link: ?string, delivered_text: ?string}
     */
    private function validate(array $kinds, array $input, array $files): array
    {
        $delivery = [
            'delivered_on' => is_string($input['delivered_on'] ?? null) ? trim($input['delivered_on']) : null,
            'delivered_link' => $this->cleanText($input['delivered_link'] ?? null),
            'delivered_text' => $this->cleanText($input['delivered_text'] ?? null),
        ];

        $allowedTypes = $this->allowedFileTypes($kinds);

        $rules = [
            'delivered_on' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'delivered_link' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'delivered_text' => ['nullable', 'string', 'max:'.self::MaxTextLength],
            'files' => $allowedTypes === [] ? ['prohibited'] : ['array', 'max:'.self::MaxFiles],
        ];

        if ($allowedTypes !== []) {
            $types = implode(',', $allowedTypes);
            $rules['files.*'] = ['bail', 'file', 'max:5120', 'mimes:'.$types, 'extensions:'.$types, new SecureUpload];
        }

        $validator = Validator::make([...$delivery, 'files' => $files], $rules, [
            'delivered_on.required' => __('department_tasks.delivered_on_required'),
            'delivered_on.date_format' => __('department_tasks.delivered_on_required'),
            'delivered_on.before_or_equal' => __('department_tasks.delivered_on_future'),
            'delivered_link.url' => __('department_tasks.link_invalid'),
            'files.prohibited' => __('department_tasks.files_not_accepted'),
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($kinds, $delivery, $files): void {
            $hasLink = $delivery['delivered_link'] !== null;

            foreach ($kinds as $kind) {
                $missing = match ($kind) {
                    DeliverableKind::Video => $hasLink ? null : ['delivered_link', 'video_link_required'],
                    DeliverableKind::Photos => $hasLink || $this->hasPhoto($files) ? null : ['files', 'photos_required'],
                    DeliverableKind::File => $hasLink || $files !== [] ? null : ['files', 'file_required'],
                    DeliverableKind::Text => $delivery['delivered_text'] !== null ? null : ['delivered_text', 'text_required'],
                };

                if ($missing !== null) {
                    $validator->errors()->add($missing[0], __('department_tasks.'.$missing[1]));
                }
            }
        });

        $validator->validate();

        /** @var array{delivered_on: string, delivered_link: ?string, delivered_text: ?string} */
        return $delivery;
    }

    /**
     * The file types the requested kinds take: any type for a file, jpg/png
     * for photos, none when only a video link or text is asked for.
     *
     * @param  list<DeliverableKind>  $kinds
     * @return list<string>
     */
    private function allowedFileTypes(array $kinds): array
    {
        if (in_array(DeliverableKind::File, $kinds, true)) {
            return array_values(FileType::values());
        }

        if (in_array(DeliverableKind::Photos, $kinds, true)) {
            return [FileType::Jpg->value, FileType::Png->value];
        }

        return [];
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function hasPhoto(array $files): bool
    {
        foreach ($files as $file) {
            if (in_array(Str::lower($file->getClientOriginalExtension()), [FileType::Jpg->value, FileType::Png->value], true)) {
                return true;
            }
        }

        return false;
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
