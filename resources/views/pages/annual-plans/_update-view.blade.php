{{--
    A monthly update read by everyone involved, at every stage (2026-11-11):
    the review chain, the whole content of both parts read only, the MEAL
    officer's Accountability & Feedback, the program manager's note and the
    questions, then the decision of whoever holds it now.
--}}
@use('App\Enums\PlanUpdateStatus')

@php
    $chainStage = match ($status) {
        PlanUpdateStatus::Sent => 0,
        PlanUpdateStatus::OfficerApproved => 1,
        PlanUpdateStatus::Reviewed => 2,
        PlanUpdateStatus::Seen => 3,
        default => -1,
    };
    $officerName = \App\Models\HrEmployee::query()->whereKey(\App\Support\MealCoverage::responsibleFor($project))->first()?->name;
    $chain = [
        [__('annual_plans.up.role_project_manager'), $record?->sender?->name ?? \App\Models\HrEmployee::query()->whereKey($project->user_id)->first()?->name, __('annual_plans.up.chain_fills'), $record?->sent_at],
        [__('annual_plans.up.role_officer'), $record?->officer?->name ?? $officerName, __('annual_plans.up.chain_officer'), $record?->officer_approved_at],
        [__('annual_plans.up.role_meal_manager'), $record?->reviewer?->name, __('annual_plans.up.chain_meal'), $record?->reviewed_at],
        [__('annual_plans.up.role_program_manager'), $record?->seer?->name ?? $plan->program?->manager?->name, __('annual_plans.up.chain_pm'), $record?->seen_at],
    ];
    $doneWords = [__('annual_plans.up.done_sent'), __('annual_plans.up.done_approved'), __('annual_plans.up.done_reviewed'), __('annual_plans.up.done_seen')];
@endphp

@if ($record === null || $status === PlanUpdateStatus::Draft)
    <section class="ap-card" data-update-not-sent>
        <h2>{{ __('annual_plans.up.not_sent_title') }}</h2>
        <p class="ap-q">{{ __('annual_plans.up.not_sent_body', ['date' => $formatMoment($this->dueDate())]) }}</p>
    </section>
@else
    <div class="ap-flow" data-chain>
        @foreach ($chain as $index => [$role, $name, $todo, $at])
            @php $isDone = $index <= $chainStage; $isNow = $status !== PlanUpdateStatus::Returned && $index === $chainStage + 1; @endphp
            <div class="ap-fl {{ $isDone ? 'is-done' : ($isNow ? 'is-now' : '') }}">
                <span class="ap-n">{{ $index + 1 }} · {{ $role }}</span>
                <b dir="auto">{{ $name ?? '—' }}</b>
                <span>{{ $isDone ? '✓ '.$doneWords[$index].($at ? ' · '.$at->translatedFormat('j M') : '') : ($isNow ? __('annual_plans.up.now').' · '.$todo : $todo) }}</span>
            </div>
        @endforeach
    </div>

    @if ($status === PlanUpdateStatus::Returned)
        <div class="ap-info is-bad"><b>{{ __('annual_plans.up.returned_to_pm_by', ['name' => $record->returner?->name ?? '—']) }}</b> “<span dir="auto">{{ $record->return_reason }}</span>”</div>
    @elseif ($status === PlanUpdateStatus::Sent && $record->returned_to === 'officer')
        <div class="ap-info is-warn"><b>{{ __('annual_plans.up.returned_to_officer_by', ['name' => $record->returner?->name ?? '—']) }}</b> “<span dir="auto">{{ $record->return_reason }}</span>”</div>
    @endif
    @if ($record->resent)<p class="ap-xs ap-faint">{{ __('annual_plans.up.sent_again_note') }}</p>@endif

    <section class="ap-card" data-update-read>
        <div class="ap-partH">{{ __('annual_plans.up.part1') }}</div>
        @include('pages.annual-plans._update-weeks', ['readOnly' => true])
        @include('pages.annual-plans._update-numbers', ['readOnly' => true])
        <div class="ap-partH">{{ __('annual_plans.up.part2') }}</div>
        <div class="ap-grp">
            <span class="ap-lbl">{{ __('annual_plans.up.section_done') }}</span>
            <p class="ap-sm" dir="auto" style="white-space: pre-line">{{ $record->activities_implemented }}</p>
        </div>
        @include('pages.annual-plans._update-beneficiaries', ['readOnly' => true, 'total' => (int) $record->males + (int) $record->females])
        <div class="ap-grp"><span class="ap-lbl">{{ __('annual_plans.up.section_challenges') }}</span><p class="ap-sm" dir="auto" style="white-space: pre-line">{{ $record->challenges }}</p></div>
        <div class="ap-grp"><span class="ap-lbl">{{ __('annual_plans.up.section_next') }}</span><p class="ap-sm" dir="auto" style="white-space: pre-line">{{ $record->next_month }}</p></div>
        <div class="ap-grp" data-accountability>
            <span class="ap-lbl">{{ __('annual_plans.up.section_accountability') }} <span class="ap-xs ap-faint">({{ __('annual_plans.up.role_officer') }})</span></span>
            @if ($mode === 'officer')
                <textarea class="ap-inp @error('accountability') is-err @enderror" wire:model="accountability" placeholder="{{ __('annual_plans.up.accountability_placeholder') }}" dir="auto" data-test="accountability"></textarea>
                @error('accountability') <p class="ap-err">{{ $message }}</p> @enderror
            @else
                <p class="ap-sm" dir="auto" style="white-space: pre-line">{{ $record->accountability ?: __('annual_plans.up.not_written') }}</p>
            @endif
        </div>
        @if ($record->program_manager_note)
            <div class="ap-grp" data-pm-note><span class="ap-lbl">{{ __('annual_plans.up.pm_note_title', ['name' => $record->seer?->name ?? '—']) }}</span><p class="ap-sm" dir="auto">{{ $record->program_manager_note }}</p></div>
        @endif
        @if ($record->questions->isNotEmpty())
            <div class="ap-grp" data-questions>
                <span class="ap-lbl">{{ __('annual_plans.up.questions_title') }}</span>
                @foreach ($record->questions as $q)
                    <p class="ap-sm"><b>{{ $q->user?->name }}</b> <span class="ap-xs ap-faint">{{ $q->created_at?->translatedFormat('j M') }}</span>: <span dir="auto">{{ $q->body }}</span></p>
                @endforeach
            </div>
        @endif
        <div class="ap-row">
            <a class="dk-btn" href="{{ route('plan-updates.export', [$plan, $project, $this->month, 'format' => 'pdf']) }}" target="_blank" rel="noopener">{{ __('annual_plans.up.export_pdf') }}</a>
            <a class="dk-btn" href="{{ route('plan-updates.export', [$plan, $project, $this->month, 'format' => 'xlsx']) }}">{{ __('annual_plans.up.export_excel') }}</a>
        </div>
    </section>

    @if ($mode === 'officer')
        <section class="ap-card" data-decision="officer">
            <h2>{{ __('annual_plans.up.your_decision') }}</h2>
            <p class="ap-xs ap-faint">{{ __('annual_plans.up.officer_deadline', ['date' => $formatMoment(($record->sent_at ?? now())->addDays(3))]) }}</p>
            <textarea class="ap-inp @error('reason') is-err @enderror" wire:model="reason" placeholder="{{ __('annual_plans.up.reason_placeholder') }}" dir="auto" data-test="reason"></textarea>
            @error('reason') <p class="ap-err">{{ $message }}</p> @enderror
            <div class="ap-row">
                <button type="button" class="dk-btn dk-primary" wire:click="officerApprove" data-test="officer-approve">{{ __('annual_plans.up.approve') }} ✓</button>
                <button type="button" class="dk-btn" wire:click="officerReturn" data-test="officer-return">{{ __('annual_plans.up.return_to_pm') }}</button>
                <button type="button" class="dk-btn" x-on:click="$wire.question = $wire.reason; $wire.askQuestion()" data-test="officer-ask">{{ __('annual_plans.up.ask') }}</button>
            </div>
        </section>
    @elseif ($mode === 'meal')
        <section class="ap-card" data-decision="meal">
            <h2>{{ __('annual_plans.up.your_review') }}</h2>
            <p class="ap-xs ap-faint">{{ __('annual_plans.up.meal_deadline', ['date' => $formatMoment(($record->officer_approved_at ?? now())->addDays(3))]) }}</p>
            <textarea class="ap-inp @error('reason') is-err @enderror" wire:model="reason" placeholder="{{ __('annual_plans.up.reason_placeholder') }}" dir="auto" data-test="reason"></textarea>
            @error('reason') <p class="ap-err">{{ $message }}</p> @enderror
            <div class="ap-row ap-sm" role="radiogroup" aria-label="{{ __('annual_plans.up.return_to') }}">
                <b>{{ __('annual_plans.up.return_to') }}</b>
                <label><input type="radio" wire:model="returnTo" value="project_manager"> {{ __('annual_plans.up.role_project_manager') }}</label>
                <label><input type="radio" wire:model="returnTo" value="officer"> {{ __('annual_plans.up.role_officer') }}</label>
            </div>
            <div class="ap-row">
                <button type="button" class="dk-btn dk-primary" wire:click="mealReviewed" data-test="meal-reviewed">{{ __('annual_plans.up.mark_reviewed') }} ✓</button>
                <button type="button" class="dk-btn" wire:click="mealReturn" data-test="meal-return">{{ __('annual_plans.up.return') }}</button>
                <button type="button" class="dk-btn" x-on:click="$wire.question = $wire.reason; $wire.askQuestion()" data-test="meal-ask">{{ __('annual_plans.up.ask') }}</button>
            </div>
        </section>
    @elseif ($mode === 'program')
        <section class="ap-card" data-decision="program">
            <h2>{{ __('annual_plans.up.mark_seen_title') }}</h2>
            <textarea class="ap-inp" wire:model="pmNote" placeholder="{{ __('annual_plans.up.pm_note_placeholder') }}" dir="auto" data-test="pm-note"></textarea>
            <div class="ap-row"><button type="button" class="dk-btn dk-primary" wire:click="markSeen" data-test="mark-seen">{{ __('annual_plans.up.mark_seen') }} ✓</button></div>
        </section>
    @elseif ($mode === 'answer')
        <section class="ap-card" data-decision="answer">
            <h2>{{ __('annual_plans.up.answer_title') }}</h2>
            <textarea class="ap-inp @error('answer') is-err @enderror" wire:model="answer" dir="auto" data-test="answer"></textarea>
            <div class="ap-row"><button type="button" class="dk-btn" wire:click="sendAnswer" data-test="send-answer">{{ __('annual_plans.up.send_answer') }}</button></div>
        </section>
    @endif
@endif
