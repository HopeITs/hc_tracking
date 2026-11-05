<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Actions\MonthlyReports\SaveMonthlyReportAnswers;
use App\Enums\AiDraftRating;
use App\Jobs\RunAiPrompt;
use App\Models\AiDraft;
use App\Models\MonthlyReportAnswer;
use App\Models\User;
use App\Support\Ai\Agents\MonthlyNarrativeDrafter;
use App\Support\Ai\MonthlyNarrativeBrief;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * "Draft with AI" on monthly-reports/show: queues the narrative drafter for
 * this month and, when the answer arrives (pushed live, or polled), fills
 * only the answers the officer left empty and saves them like typed ones.
 * Each drafted answer is kept as an AiDraft the officer can rate, so the
 * trial shows how helpful the drafts were and how much was changed.
 *
 * @property-read Collection<int, AiDraft> $aiDrafts
 *
 * Used next to EditsMonthlyReportActivities and ReadsMonthlyReport.
 */
trait DraftsMonthlyReportNarrative
{
    /**
     * The queued draft this page waits for, if any.
     */
    #[Locked]
    public ?string $aiDraftKey = null;

    /**
     * Whether the viewer may ask for a draft right now.
     */
    public function canDraftWithAi(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && $user->can('ai.draft')
            && $this->fillsNarrative()
            && $this->questions->isNotEmpty();
    }

    public function draftNarrativeWithAi(): void
    {
        if (! $this->canDraftWithAi()) {
            Flux::toast(variant: 'danger', text: __('monthly_reports.not_allowed'));

            return;
        }

        if ($this->aiDraftKey !== null) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $drafter = new MonthlyNarrativeDrafter(app()->getLocale() === 'ar' ? 'ar' : 'en');
        $brief = MonthlyNarrativeBrief::build($this->report, $this->rows(), $this->activities(), $this->questions);

        $this->aiDraftKey = RunAiPrompt::start(
            $drafter,
            $drafter->promptFor($brief),
            'monthly-report-narrative',
            $user,
        );
    }

    /**
     * Pick up the draft once it is ready; safe to call while still pending.
     */
    public function collectAiDraft(): void
    {
        if ($this->aiDraftKey === null) {
            return;
        }

        /** @var User $user */
        $user = Auth::user();
        $result = RunAiPrompt::resultFor($this->aiDraftKey, $user);

        if ($result !== null && $result['status'] === 'pending') {
            return;
        }

        $this->aiDraftKey = null;
        $drafts = $result === null || $result['status'] === 'failed' ? null : self::draftedAnswers((string) $result['text']);

        if ($drafts === null) {
            Flux::toast(variant: 'danger', text: __('monthly_reports.ai_draft_failed'));

            return;
        }

        $filled = $this->fillEmptyAnswers($drafts, $result['request_id'] ?? null);

        Flux::toast(variant: 'success', text: trans_choice('monthly_reports.ai_draft_filled', $filled, ['count' => $filled]));
    }

    /**
     * Live push from RunAiPrompt; the poll in the view is the fallback.
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $userId = Auth::id();

        return $userId === null ? [] : ["echo-private:App.Models.User.{$userId},.ai.finished" => 'collectAiDraft'];
    }

    /**
     * Save drafted text into answers that are still empty; never overwrite
     * what the officer wrote. Returns how many answers were filled.
     *
     * @param  array<int, string>  $drafts  Answer text keyed by question id.
     */
    protected function fillEmptyAnswers(array $drafts, ?int $aiRequestId = null): int
    {
        $toSave = [];

        foreach ($this->questions as $question) {
            $draft = trim($drafts[$question->id] ?? '');

            if ($draft === '' || trim((string) ($this->answers[$question->id] ?? '')) !== '') {
                continue;
            }

            $toSave[] = ['question_id' => $question->id, 'answer' => $draft];
        }

        if ($toSave === []) {
            return 0;
        }

        try {
            app(SaveMonthlyReportAnswers::class)->handle($this->report, $this->renderedStatus(), $toSave);
        } catch (ValidationException|ModelNotFoundException) {
            Flux::toast(variant: 'danger', text: __('monthly_reports.ai_draft_failed'));

            return 0;
        } catch (AuthorizationException) {
            $this->refuseNarrativeSave();

            return 0;
        }

        foreach ($toSave as $saved) {
            $this->answers[$saved['question_id']] = $saved['answer'];
        }

        $this->forgetMonthlyReport();
        $this->recordDrafts($toSave, $aiRequestId);

        return count($toSave);
    }

    /**
     * The latest AI draft of each answer on this report, keyed by question id.
     *
     * @return Collection<int, AiDraft>
     */
    #[Computed]
    public function aiDrafts(): Collection
    {
        $questionIdsByAnswer = $this->report->answers->pluck('report_template_question_id', 'id');

        return AiDraft::query()
            ->whereIn('monthly_report_answer_id', $questionIdsByAnswer->keys())
            ->orderBy('id')
            ->get()
            ->keyBy(fn (AiDraft $draft): int => (int) $questionIdsByAnswer[$draft->monthly_report_answer_id]);
    }

    /**
     * The officer's verdict on one drafted answer of this report.
     */
    public function rateAiDraft(int $questionId, string $rating): void
    {
        $verdict = AiDraftRating::tryFrom($rating);
        $draft = $this->aiDrafts->get($questionId);

        if ($verdict === null || $draft === null) {
            return;
        }

        Gate::authorize('update', $this->report);

        $draft->update(['rating' => $verdict]);
        unset($this->aiDrafts);
    }

    /**
     * Keep what the AI wrote for each saved answer, linked to its call.
     *
     * @param  list<array{question_id: int, answer: string}>  $saved
     */
    protected function recordDrafts(array $saved, ?int $aiRequestId): void
    {
        $answerIds = MonthlyReportAnswer::query()
            ->where('monthly_report_id', $this->monthlyReportId)
            ->whereIn('report_template_question_id', array_column($saved, 'question_id'))
            ->pluck('id', 'report_template_question_id');

        foreach ($saved as $draft) {
            AiDraft::create([
                'ai_request_id' => $aiRequestId,
                'user_id' => Auth::id(),
                'monthly_report_answer_id' => $answerIds[$draft['question_id']],
                'drafted_text' => $draft['answer'],
            ]);
        }

        unset($this->aiDrafts);
    }

    /**
     * The drafter's JSON as answer text keyed by question id, or null when
     * the model did not return the agreed shape.
     *
     * @return array<int, string>|null
     */
    protected static function draftedAnswers(string $text): ?array
    {
        $json = trim(preg_replace('/^```(?:json)?|```$/m', '', $text) ?? '');
        $decoded = json_decode($json, true);

        if (! is_array($decoded) || ! is_array($decoded['answers'] ?? null)) {
            return null;
        }

        $drafts = [];

        foreach ($decoded['answers'] as $answer) {
            if (is_array($answer) && is_numeric($answer['question_id'] ?? null) && is_string($answer['answer'] ?? null)) {
                $drafts[(int) $answer['question_id']] = mb_substr($answer['answer'], 0, 5000);
            }
        }

        return $drafts;
    }
}
