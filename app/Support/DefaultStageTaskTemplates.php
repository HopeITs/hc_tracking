<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\HrDepartment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The starting stage task templates of `config/stage-templates.php` (D-63).
 *
 * Query builder only, so this writes no activity and fires no model event,
 * and it is idempotent: every row is upserted on the unique key, so running
 * it twice changes nothing and a template the PR Manager has since edited
 * keeps the parts the template does not own (`is_active`, `sort_order`,
 * `repeat_on_reentry`).
 *
 * Departments are resolved from HR by `departments.code`; a code HR does not
 * have is logged and skipped rather than guessed, so a half-configured HR
 * never blocks the seed.
 */
final class DefaultStageTaskTemplates
{
    /**
     * Write the configured templates, and report what happened.
     *
     * @return array{written: int, skipped: list<string>}
     */
    public static function ensure(): array
    {
        $departments = self::departmentIdsBySlug();
        $now = now();
        $rows = [];
        $skipped = [];

        foreach (self::configuredTemplates() as $index => $template) {
            $departmentId = $departments[$template['department']] ?? null;

            if ($departmentId === null) {
                $skipped[] = $template['department'].' · '.$template['title_en'];

                continue;
            }

            $rows[] = [
                'status' => $template['status'],
                'department_id' => $departmentId,
                'kind' => $template['kind'],
                'creates' => $template['creates'],
                'title_en' => $template['title_en'],
                'title_ar' => $template['title_ar'],
                'due_offset_days' => $template['due_offset_days'],
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($skipped !== []) {
            Log::warning('Stage task templates skipped: HR has no department with that code.', ['skipped' => $skipped]);
        }

        if ($rows !== []) {
            // The unique key is (status, department_id, kind, title_en), so a
            // re-run refreshes only the wording and the offset.
            DB::table('proposal_stage_task_templates')->upsert(
                $rows,
                ['status', 'department_id', 'kind', 'title_en'],
                ['creates', 'title_ar', 'due_offset_days', 'updated_at'],
            );
        }

        return ['written' => count($rows), 'skipped' => $skipped];
    }

    /**
     * What the seed would do, without writing: one line per configured row.
     *
     * @return list<string>
     */
    public static function preview(): array
    {
        $departments = self::departmentIdsBySlug();
        $lines = [];

        foreach (self::configuredTemplates() as $template) {
            $departmentId = $departments[$template['department']] ?? null;
            $exists = $departmentId !== null && DB::table('proposal_stage_task_templates')
                ->where('status', $template['status'])
                ->where('department_id', $departmentId)
                ->where('kind', $template['kind'])
                ->where('title_en', $template['title_en'])
                ->exists();

            $lines[] = match (true) {
                $departmentId === null => 'skip    '.$template['title_en'].' — HR has no department `'.$template['department'].'`',
                $exists => 'keep    '.$template['title_en'].' ('.$template['status'].', department '.$departmentId.')',
                default => 'create  '.$template['title_en'].' ('.$template['status'].', department '.$departmentId.')',
            };
        }

        return $lines;
    }

    /**
     * HR department ids by the slug the config uses, read once. A department
     * HR cannot be reached for leaves the map empty, which the callers above
     * report as skipped rather than failing the seed.
     *
     * @return array<string, int>
     */
    private static function departmentIdsBySlug(): array
    {
        /** @var array<string, string> $codes */
        $codes = config('stage-templates.departments', []);

        try {
            $idsByCode = HrDepartment::query()
                ->toBase()
                ->whereIn('code', array_values($codes))
                ->pluck('id', 'code');
        } catch (QueryException $exception) {
            Log::warning('Stage task templates: HR could not be read.', ['message' => $exception->getMessage()]);

            return [];
        }

        $map = [];

        foreach ($codes as $slug => $code) {
            $id = $idsByCode[$code] ?? null;

            if ($id !== null) {
                $map[$slug] = (int) $id;
            }
        }

        return $map;
    }

    /**
     * @return list<array{status: string, department: string, kind: string, creates: string, title_en: string, title_ar: string, due_offset_days: int}>
     */
    private static function configuredTemplates(): array
    {
        /** @var list<array{status: string, department: string, kind: string, creates: string, title_en: string, title_ar: string, due_offset_days: int}> $templates */
        $templates = config('stage-templates.templates', []);

        return $templates;
    }
}
