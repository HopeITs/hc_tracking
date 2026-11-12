<?php

use App\Actions\Proposals\DeclineProgramRequest;
use App\Actions\Proposals\LinkProgramRequest;
use App\Models\HrCity;
use App\Models\HrProgram;
use App\Models\Program;
use App\Models\ProgramRequest;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Program requests waiting on HR (contracts.md §7, W-34a slice 3). A
 * proposal location row that needs a program HR does not have yet opens a
 * request and blocks the proposal's approval. Here a `programs.update`
 * holder links the HR program once HR has created it, or declines the
 * request with a reason. Every write goes through an action that
 * authorizes again under the proposal lock.
 */
new #[Layout('layouts::desk'), Title('titles.program_requests')] class extends Component {
    public bool $showLinkModal = false;

    public ?int $linkingRequestId = null;

    public string $linkingRequestName = '';

    public string $programId = '';

    public bool $showDeclineModal = false;

    public ?int $decliningRequestId = null;

    public string $decliningRequestName = '';

    public string $declineReason = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', ProgramRequest::class);
    }

    /** @return Collection<int, ProgramRequest> */
    #[Computed]
    public function requests(): Collection
    {
        return ProgramRequest::query()
            ->pending()
            ->with(['location.proposal', 'requestedBy'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * City names of the listed rows, read from HR in one query.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function cityNames(): array
    {
        $ids = $this->requests->map(fn (ProgramRequest $request): ?int => $request->location?->city_id)->filter()->unique()->values()->all();

        return $ids === [] ? [] : HrCity::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (HrCity $city): array => [$city->id => (string) $city->name])->all();
    }

    /**
     * Every program the tracking mirror holds, by HR name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function programOptions(): array
    {
        $names = HrProgram::namesById();
        $local = array_flip(Program::query()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        $options = [];

        foreach (HrProgram::idsOrderedByName() as $id) {
            if (isset($local[$id])) {
                $options[$id] = $names[$id] !== '' ? $names[$id] : '#'.$id;
            }
        }

        return $options;
    }

    public function openLinkModal(int $requestId): void
    {
        $request = ProgramRequest::query()->findOrFail($requestId);

        Gate::authorize('update', $request);

        $this->resetValidation();
        $this->linkingRequestId = $request->id;
        $this->linkingRequestName = $request->localizedName();
        $this->programId = '';
        $this->showLinkModal = true;
    }

    public function linkRequest(): void
    {
        if ($this->linkingRequestId === null) {
            return;
        }

        if ($this->programId === '' || ! ctype_digit($this->programId)) {
            throw ValidationException::withMessages(['programId' => __('program_requests.choose_program')]);
        }

        $request = ProgramRequest::query()->findOrFail($this->linkingRequestId);

        try {
            $materialised = app(LinkProgramRequest::class)->handle($request, (int) $this->programId);
        } catch (ValidationException $exception) {
            $this->failOrClose($exception, 'programId');

            return;
        }

        $this->closeLinkModal();
        unset($this->requests, $this->cityNames);

        Flux::toast(variant: 'success', text: $materialised === null
            ? __('program_requests.linked', ['name' => $request->localizedName()])
            : trans_choice('program_requests.linked_and_materialised', count($materialised->createdProjectIds) + count($materialised->linkedProjectIds), [
                'name' => $request->localizedName(),
                'count' => count($materialised->createdProjectIds) + count($materialised->linkedProjectIds),
            ]));
    }

    public function closeLinkModal(): void
    {
        $this->showLinkModal = false;
        $this->linkingRequestId = null;
        $this->linkingRequestName = '';
        $this->programId = '';
        $this->resetValidation();
    }

    public function openDeclineModal(int $requestId): void
    {
        $request = ProgramRequest::query()->findOrFail($requestId);

        Gate::authorize('update', $request);

        $this->resetValidation();
        $this->decliningRequestId = $request->id;
        $this->decliningRequestName = $request->localizedName();
        $this->declineReason = '';
        $this->showDeclineModal = true;
    }

    public function declineRequest(): void
    {
        if ($this->decliningRequestId === null) {
            return;
        }

        $request = ProgramRequest::query()->findOrFail($this->decliningRequestId);

        try {
            app(DeclineProgramRequest::class)->handle($request, $this->declineReason);
        } catch (ValidationException $exception) {
            $this->failOrClose($exception, 'declineReason');

            return;
        }

        $this->closeDeclineModal();
        unset($this->requests, $this->cityNames);

        Flux::toast(variant: 'success', text: __('program_requests.declined', ['name' => $request->localizedName()]));
    }

    public function closeDeclineModal(): void
    {
        $this->showDeclineModal = false;
        $this->decliningRequestId = null;
        $this->decliningRequestName = '';
        $this->declineReason = '';
        $this->resetValidation();
    }

    /**
     * A field error stays in its dialog; any other refusal (the request was
     * handled meanwhile, the proposal cannot get its projects) closes the
     * dialogs and becomes a toast.
     */
    private function failOrClose(ValidationException $exception, string $field): void
    {
        $errors = $exception->errors();

        if (array_key_exists($field, $errors)) {
            throw ValidationException::withMessages([$field => $errors[$field]]);
        }

        $this->closeLinkModal();
        $this->closeDeclineModal();
        unset($this->requests, $this->cityNames);

        Flux::toast(variant: 'danger', text: (string) collect($errors)->flatten()->first());
    }
};
?>

@php
    $requests = $this->requests;
    $cityNames = $this->cityNames;
    $canViewProposals = auth()->user()->can('proposals.view');
@endphp

<x-desk.register class="@container/directory" heading-id="program-requests-heading" :title="__('titles.program_requests')" :count="$requests->count()" data-tour="program-requests-page">
    <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('program_requests.intro') }}</p>

    @if ($requests->isEmpty())
        <x-ui.empty-state icon="inbox" :heading="__('program_requests.empty_heading')" :body="__('program_requests.empty_body')" />
    @else
        <x-ui.panel as="ul" :clip="false" class="divide-y divide-secondary-soft dark:divide-line" aria-label="{{ __('program_requests.list_label') }}" data-program-requests-list>
            @foreach ($requests as $request)
                @php
                    $proposal = $request->location?->proposal;
                    $cityId = $request->location?->city_id;
                    $cityName = $cityId !== null ? ($cityNames[$cityId] ?? '#'.$cityId) : '';
                @endphp
                <li wire:key="program-request-{{ $request->id }}" data-program-request="{{ $request->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <div class="min-w-0 flex-1 basis-64">
                        <p class="text-[15px] font-semibold leading-snug"><bdi>{{ $request->localizedName() }}</bdi></p>
                        <p class="mt-0.5 text-sm text-secondary-medium dark:text-ink-muted"><bdi>{{ $request->otherLanguageName() }}</bdi></p>
                        <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 type-meta text-secondary-medium dark:text-ink-muted">
                            @if ($proposal !== null)
                                <span>
                                    {{ __('program_requests.for_proposal') }}
                                    @if ($canViewProposals)
                                        <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="underline underline-offset-2"><bdi>{{ $proposal->name }}</bdi></a>
                                    @else
                                        <bdi>{{ $proposal->name }}</bdi>
                                    @endif
                                </span>
                            @endif
                            @if ($cityName !== '')
                                <span>{{ __('program_requests.in_city', ['city' => $cityName]) }}</span>
                            @endif
                            @if ($request->requestedBy !== null)
                                <span>{{ __('program_requests.requested_by', ['name' => $request->requestedBy->name]) }}</span>
                            @endif
                            @if ($request->created_at !== null)
                                <span>{{ $request->created_at->translatedFormat(__('formats.date')) }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <x-ui.button tone="primary" size="sm" icon="link" wire:click="openLinkModal({{ $request->id }})" data-test="link-program-request">
                            {{ __('program_requests.link_program') }}
                        </x-ui.button>
                        <x-ui.button tone="ghost" size="sm" wire:click="openDeclineModal({{ $request->id }})" data-test="decline-program-request">
                            {{ __('program_requests.decline') }}
                        </x-ui.button>
                    </div>
                </li>
            @endforeach
        </x-ui.panel>
    @endif

    @include('pages.admin._program-requests-modals')
</x-desk.register>
