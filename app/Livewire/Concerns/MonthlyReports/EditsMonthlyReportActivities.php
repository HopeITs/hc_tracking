<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Actions\MonthlyReports\SaveMonthlyReportActivities;
use App\Actions\MonthlyReports\SaveMonthlyReportAnswers;
use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportAnswer;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Activities this month and the narrative answers of monthly-reports/show
 * (T17B slice 2; contracts §13, §13.1 item 3, D-43a, D-51). A status change
 * or a blur sends that one activity row, whole, to
 * SaveMonthlyReportActivities; a blur on an answer sends that answer to
 * SaveMonthlyReportAnswers. Both with the status the page rendered; errors
 * come back onto the field, and a row the server refuses stays in the form.
 *
 * Requires ReadsMonthlyReport.
 *
 * @property-read MonthlyReport $report  #[Computed] report()
 * @property-read list<array<string, mixed>> $activities  #[Computed] activities()
 */
trait EditsMonthlyReportActivities
{
    /**
     * The entered activity rows, keyed by activity (result) id:
     * {status, reason, started_on, completed_on, executed_on, note}.
     *
     * @var array<int|string, array<string, mixed>>
     */
    public array $activityEntries = [];

    /**
     * The entered answers, keyed by template question id.
     *
     * @var array<int|string, string>
     */
    public array $answers = [];

    /**
     * Save the activity row whose field just changed.
     */
    public function updatedActivityEntries(mixed $value, string $key): void
    {
        $resultId = (int) explode('.', $key)[0];
        $this->forgetErrorsUnder('activityEntries.'.$resultId);
        $state = $this->activityEntries[$resultId] ?? [];

        $entry = ['result_id' => $resultId];

        foreach (['status', 'reason', 'started_on', 'completed_on', 'executed_on', 'note'] as $field) {
            $entry[$field] = self::filledText($state[$field] ?? null);
        }

        try {
            app(SaveMonthlyReportActivities::class)->handle($this->report, $this->renderedStatus(), [$entry]);
        } catch (ValidationException $exception) {
            $this->showNarrativeErrors($exception->errors(), 'entries.0.', 'activityEntries.'.$resultId);

            return;
        } catch (AuthorizationException) {
            $this->refuseNarrativeSave();

            return;
        } catch (ModelNotFoundException) {
            $this->addError('activityEntries.'.$resultId, __('monthly_reports.activity_not_in_report'));

            return;
        }

        $this->forgetMonthlyReport();
    }

    /**
     * Save the answer that was just left.
     */
    public function updatedAnswers(mixed $value, string $key): void
    {
        $questionId = (int) explode('.', $key)[0];
        $this->forgetErrorsUnder('answers.'.$questionId);

        try {
            app(SaveMonthlyReportAnswers::class)->handle($this->report, $this->renderedStatus(), [
                ['question_id' => $questionId, 'answer' => self::filledText($value)],
            ]);
        } catch (ValidationException $exception) {
            $this->showNarrativeErrors($exception->errors(), 'answers.0.answer', 'answers.'.$questionId);

            return;
        } catch (AuthorizationException) {
            $this->refuseNarrativeSave();

            return;
        } catch (ModelNotFoundException) {
            unset($this->answers[$questionId]);

            return;
        }

        $this->forgetMonthlyReport();
    }

    /**
     * Fill the activity rows and answers from what is stored.
     */
    protected function seedNarrative(): void
    {
        $this->activityEntries = [];

        foreach ($this->activities as $activity) {
            $this->activityEntries[$activity['id']] = [
                'status' => $activity['status']->value ?? '',
                'reason' => $activity['reason'] ?? '',
                'started_on' => $activity['started_on'] ?? '',
                'completed_on' => $activity['completed_on'] ?? '',
                'executed_on' => $activity['executed_on'] ?? '',
                'note' => $activity['note'] ?? '',
            ];
        }

        $stored = $this->report->answers->keyBy('report_template_question_id');
        $this->answers = [];

        foreach ($this->questions as $question) {
            /** @var MonthlyReportAnswer|null $answer */
            $answer = $stored->get($question->id);
            $this->answers[$question->id] = $answer->answer ?? '';
        }
    }

    /**
     * Put `{prefix}{field}` errors on `{target}.{field}`; anything about the
     * report itself (stale) is a toast and a reload.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function showNarrativeErrors(array $errors, string $prefix, string $target): void
    {
        foreach ($errors as $key => $messages) {
            if (! str_starts_with($key, $prefix)) {
                Flux::toast(variant: 'danger', text: $messages[0]);
                $this->forgetMonthlyReport();
                $this->seedNarrative();

                return;
            }

            $field = ltrim(substr($key, strlen($prefix)), '.');
            $this->addError($field === '' ? $target : $target.'.'.$field, $messages[0]);
        }
    }

    /**
     * A save the policy refused: the report was submitted or the
     * permission is gone.
     */
    protected function refuseNarrativeSave(): void
    {
        $locked = $this->report->status !== MonthlyReportStatus::Draft;
        Flux::toast(variant: 'danger', text: $locked ? __('monthly_reports.locked') : __('monthly_reports.not_allowed'));
        $this->forgetMonthlyReport();
        $this->seedNarrative();
    }

    protected function forgetErrorsUnder(string $path): void
    {
        $bag = $this->getErrorBag();

        foreach ($bag->keys() as $key) {
            if ($key === $path || str_starts_with($key, $path.'.')) {
                $bag->forget($key);
            }
        }
    }

    protected static function filledText(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }
}
