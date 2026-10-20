<?php

use App\Actions\NarrativeQuestions\DeactivateNarrativeQuestion;
use App\Actions\NarrativeQuestions\DeleteNarrativeQuestion;
use App\Actions\NarrativeQuestions\MoveNarrativeQuestion;
use App\Actions\NarrativeQuestions\ReactivateNarrativeQuestion;
use App\Actions\NarrativeQuestions\SaveNarrativeQuestion;
use App\Enums\NarrativeQuestionFrequency;
use App\Models\NarrativeQuestion;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The org-wide narrative question bank (contracts.md §12.4, D-43). Only MEAL
 * (narrative-questions.manage) opens it: there is no view permission. Every
 * write goes through an App\Actions\NarrativeQuestions action, which
 * authorizes again under lock. Questions are ordered within their frequency.
 */
new #[Layout('layouts::desk'), Title('titles.narrative_questions')] class extends Component {
    /** The frequency filter: '' shows both groups; an unknown value reads as ''. */
    #[Url(except: '')]
    public string $frequency = '';

    public bool $showQuestionModal = false;

    public ?int $editingQuestionId = null;

    public string $textAr = '';

    public string $textEn = '';

    public string $questionFrequency = '';

    public bool $showDeactivateModal = false;

    public ?int $deactivatingQuestionId = null;

    public bool $showDeleteModal = false;

    public ?int $deletingQuestionId = null;

    /** A used question cannot be deleted: the delete dialog offers to deactivate it instead. */
    public bool $deletingQuestionInUse = false;

    /** Read out by the polite live region after a move, so a keyboard user hears the new position. */
    public string $announcement = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', NarrativeQuestion::class);
    }

    /**
     * The whole bank in one query; the frequency filter and the groups are
     * applied in memory (a bank holds a handful of questions).
     *
     * @return Collection<int, NarrativeQuestion>
     */
    #[Computed]
    public function questions(): Collection
    {
        return NarrativeQuestion::query()->ordered()->get();
    }

    #[Computed]
    public function selectedFrequency(): ?NarrativeQuestionFrequency
    {
        return NarrativeQuestionFrequency::tryFrom($this->frequency);
    }

    /**
     * The frequencies shown, each with its questions in order.
     *
     * @return array<string, array{frequency: NarrativeQuestionFrequency, questions: Collection<int, NarrativeQuestion>}>
     */
    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach (NarrativeQuestionFrequency::cases() as $case) {
            if ($this->selectedFrequency !== null && $this->selectedFrequency !== $case) {
                continue;
            }

            $groups[$case->value] = [
                'frequency' => $case,
                'questions' => $this->questions->filter(fn (NarrativeQuestion $question): bool => $question->frequency === $case)->values(),
            ];
        }

        return $groups;
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', NarrativeQuestion::class);

        $this->resetQuestionForm();
        $this->questionFrequency = ($this->selectedFrequency ?? NarrativeQuestionFrequency::Monthly)->value;
        $this->showQuestionModal = true;
    }

    public function openEditModal(int $questionId): void
    {
        $question = NarrativeQuestion::query()->findOrFail($questionId);

        Gate::authorize('update', $question);

        $this->resetQuestionForm();
        $this->editingQuestionId = $question->id;
        $this->textAr = $question->text_ar;
        $this->textEn = (string) $question->text_en;
        $this->questionFrequency = $question->frequency->value;
        $this->showQuestionModal = true;
    }

    public function saveQuestion(): void
    {
        try {
            app(SaveNarrativeQuestion::class)->handle($this->editingQuestionId, [
                'text_ar' => $this->textAr,
                'text_en' => $this->textEn,
                'frequency' => $this->questionFrequency,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [match ($key) {
                    'text_en' => 'textEn',
                    'frequency' => 'questionFrequency',
                    default => 'textAr',
                } => $messages])->all(),
            );
        }

        $isCreate = $this->editingQuestionId === null;

        $this->closeQuestionModal();
        unset($this->questions, $this->groups);

        Flux::toast(variant: 'success', text: __($isCreate ? 'narrative_questions.created' : 'narrative_questions.updated'));
    }

    public function closeQuestionModal(): void
    {
        $this->showQuestionModal = false;
        $this->resetQuestionForm();
    }

    public function confirmDeactivate(int $questionId): void
    {
        Gate::authorize('update', NarrativeQuestion::query()->findOrFail($questionId));

        $this->deactivatingQuestionId = $questionId;
        $this->showDeactivateModal = true;
    }

    public function deactivateQuestion(): void
    {
        if ($this->deactivatingQuestionId === null) {
            return;
        }

        $questionId = $this->deactivatingQuestionId;
        $this->cancelDeactivate();

        $this->runQuestionAction(fn (): mixed => app(DeactivateNarrativeQuestion::class)->handle($questionId), 'narrative_questions.deactivated');
    }

    public function cancelDeactivate(): void
    {
        $this->showDeactivateModal = false;
        $this->deactivatingQuestionId = null;
    }

    /**
     * Turning a question back on loses nothing, so it needs no confirmation.
     */
    public function reactivateQuestion(int $questionId): void
    {
        $this->runQuestionAction(fn (): mixed => app(ReactivateNarrativeQuestion::class)->handle($questionId), 'narrative_questions.reactivated');
    }

    public function confirmDelete(int $questionId): void
    {
        $question = NarrativeQuestion::query()->findOrFail($questionId);

        Gate::authorize('delete', $question);

        $this->deletingQuestionId = $question->id;
        $this->deletingQuestionInUse = $question->isInUse();
        $this->showDeleteModal = true;
    }

    public function deleteQuestion(): void
    {
        if ($this->deletingQuestionId === null) {
            return;
        }

        $questionId = $this->deletingQuestionId;
        $this->cancelDelete();

        $this->runQuestionAction(fn (): mixed => app(DeleteNarrativeQuestion::class)->handle($questionId), 'narrative_questions.deleted');
    }

    /**
     * The delete dialog of a used question: deactivate it, as the message explains.
     */
    public function deactivateInsteadOfDelete(): void
    {
        if ($this->deletingQuestionId === null) {
            return;
        }

        $questionId = $this->deletingQuestionId;
        $this->cancelDelete();

        $this->runQuestionAction(fn (): mixed => app(DeactivateNarrativeQuestion::class)->handle($questionId), 'narrative_questions.deactivated');
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingQuestionId = null;
        $this->deletingQuestionInUse = false;
    }

    /**
     * @param  'up'|'down'  $direction
     */
    public function moveQuestion(int $questionId, string $direction): void
    {
        try {
            $position = app(MoveNarrativeQuestion::class)->handle($questionId, towardsStart: $direction === 'up');
        } catch (ValidationException $exception) {
            unset($this->questions, $this->groups);

            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        unset($this->questions, $this->groups);

        $question = $this->questions->firstWhere('id', $questionId);

        $this->announcement = $question === null ? '' : __('narrative_questions.moved_to', [
            'text' => Str::limit($question->localizedText(), 80),
            'position' => $position,
            'total' => $this->questions->where('frequency', $question->frequency)->count(),
            'frequency' => $question->frequency->label(),
        ]);

        // Flux disables the clicked button while the request runs, which
        // drops keyboard focus; the list puts it back on the moved row.
        $this->dispatch('narrative-question-moved', questionId: $questionId, direction: $direction === 'up' ? 'up' : 'down');
    }

    /**
     * Run a deactivate, reactivate or delete; a refusal (in use, stale)
     * becomes a toast rather than a field error, since there is no form.
     */
    private function runQuestionAction(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            unset($this->questions, $this->groups);

            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        unset($this->questions, $this->groups);

        Flux::toast(variant: 'success', text: __($successKey));
    }

    private function resetQuestionForm(): void
    {
        $this->resetValidation();
        $this->editingQuestionId = null;
        $this->textAr = '';
        $this->textEn = '';
        $this->questionFrequency = '';
    }
};
?>

@php
    $groups = $this->groups;
    $visibleCount = collect($groups)->sum(fn (array $group): int => $group['questions']->count());
    $bankIsEmpty = $this->questions->isEmpty();
@endphp

<x-desk.register class="@container/directory" heading-id="narrative-questions-heading" :title="__('titles.narrative_questions')" :count="$visibleCount" data-tour="narrative-questions-page">
    
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('narrative_questions.filter_label')" wire:model.live="frequency" :value="$this->selectedFrequency?->value ?? ''">
                <option value="">{{ __('narrative_questions.all_frequencies') }}</option>
                @foreach (NarrativeQuestionFrequency::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-narrative-question" data-tour="narrative-questions-create">
                {{ __('narrative_questions.add_question') }}
            </x-ui.button>
        </x-slot:actions>
    
    <p class="sr-only" aria-live="polite" aria-atomic="true">{{ $announcement }}</p>

    @if ($bankIsEmpty)
        <x-ui.empty-state icon="chat-bubble-bottom-center-text" :heading="__('narrative_questions.empty_heading')" :body="__('narrative_questions.empty_body')">
            <x-slot:action>
                <x-ui.button tone="primary" icon="plus" wire:click="openCreateModal" data-test="empty-create-narrative-question">{{ __('narrative_questions.add_question') }}</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
    @elseif ($visibleCount === 0)
        <x-ui.empty-state icon="funnel" :heading="__('narrative_questions.empty_filtered_heading')" :body="__('narrative_questions.empty_filtered_body')" />
    @else
        @include('pages.admin._narrative-questions-list')
    @endif

    @include('pages.admin._narrative-questions-modal')

    @include('pages.admin._narrative-questions-confirm-modals')
</x-desk.register>
