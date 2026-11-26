<?php

declare(strict_types=1);

namespace App\Support\Locations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Re-point every stored notification, activity and alert ledger entry whose
 * subject is a **project** at the location row that took that project's place
 * (Y-16, D-93, D-94).
 *
 * Until this runs, an old notification opens a page that no longer exists: the
 * project page is the row's now, so `subject_type = 'project'` has nowhere to
 * lead. `SubjectLinks` and `QuickActions` answer a leftover project subject
 * through `ProjectSuccessor` so nothing breaks before or during the run; this
 * backfill is what finally makes that fallback unnecessary.
 *
 * It invents no rule of its own: which row carries a project on a given day is
 * `ProjectSuccessor`'s answer and nothing else (wave4/AGENT.md §8.1). The date
 * D-94 asks for is each record's own `created_at` — when the notification was
 * sent, the activity recorded or the alert raised — so a notification from last
 * year reaches the row that carried the project then, not the one that carries
 * it today.
 *
 * `module` is left as `projects`: the module names an ability and a screen, not
 * a table, and the `projects.*` permissions keep their names (Y-16).
 *
 * Records no activity and dispatches nothing: this is history, not news. Safe
 * to re-run — only a record still naming a project is written. A project no
 * row carries is counted as unmapped and left alone (D-80), where
 * `SubjectLinks` keeps answering for it.
 *
 * @deprecated Y-18 freezes what is left in `project_redirects`.
 */
final class StoredSubjectBackfill
{
    /**
     * The tables that store a subject, each with the column holding the time
     * the record was made (D-94's date).
     *
     * @var array<string, string>
     */
    private const array Tables = [
        'notifications' => 'created_at',
        'activities' => 'created_at',
        'notification_alert_ledger' => 'created_at',
    ];

    public function __construct(private ProjectSuccessor $successor) {}

    /**
     * Run the backfill, writing only when $apply is true; a dry run does the
     * same work inside a transaction it rolls back, so its counts are exact
     * (the shape LocationBackfill established for Y-11).
     *
     * @return array{
     *     moved: array<string, int>,
     *     rows: array<int, int>,
     *     unmapped: array<int, int>,
     * }
     */
    public function run(bool $apply): array
    {
        $moved = [];
        $rows = [];
        $unmapped = [];

        DB::beginTransaction();

        try {
            foreach (self::Tables as $table => $dateColumn) {
                $moved[$table] = 0;

                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                DB::table($table)
                    ->where('subject_type', 'project')
                    ->whereNotNull('subject_id')
                    ->orderBy('id')
                    ->select(['id', 'subject_id', $dateColumn])
                    ->chunk(500, function ($records) use ($table, $dateColumn, &$moved, &$rows, &$unmapped): void {
                        foreach ($records as $record) {
                            $projectId = (int) $record->subject_id;
                            $on = $record->{$dateColumn} === null ? null : CarbonImmutable::parse($record->{$dateColumn});
                            $row = $this->successor->rowFor($projectId, $on);

                            if ($row === null) {
                                $unmapped[$projectId] = ($unmapped[$projectId] ?? 0) + 1;

                                continue;
                            }

                            DB::table($table)->where('id', $record->id)->update([
                                'subject_type' => 'proposal_location',
                                'subject_id' => $row->id,
                            ]);

                            $moved[$table]++;
                            $rows[$row->id] = ($rows[$row->id] ?? 0) + 1;
                        }
                    });
            }

            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        return ['moved' => $moved, 'rows' => $rows, 'unmapped' => $unmapped];
    }
}
