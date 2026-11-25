{{--
    The link and decline dialogs of admin/program-requests. Included by
    ⚡program-requests.blade.php. Linking picks the HR program HR created for
    the request; declining needs a reason, recorded on the proposal.
--}}
@php
    $programOptions = $showLinkModal ? $this->programOptions : [];
@endphp

<x-ui.modal
    wire:model="showLinkModal"
    size="sm"
    :title="__('program_requests.link_title')"
    :description="__('program_requests.link_description', ['name' => $linkingRequestName])"
    submit="linkRequest"
    close="closeLinkModal"
    required-note
>
    @if ($showLinkModal)
        <div class="grid gap-y-3">
            <x-ui.form-select wire:model="programId" :label="__('program_requests.hr_program')" :placeholder="__('common.select')" required data-test="program-request-program">
                @foreach ($programOptions as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </x-ui.form-select>

            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('program_requests.link_help') }}</p>
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-program-request-link">
            {{ __('program_requests.link_program') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>

<x-ui.modal
    wire:model="showDeclineModal"
    size="sm"
    :title="__('program_requests.decline_title')"
    :description="__('program_requests.decline_description', ['name' => $decliningRequestName])"
    submit="declineRequest"
    close="closeDeclineModal"
    required-note
>
    @if ($showDeclineModal)
        <x-ui.form-textarea wire:model="declineReason" :label="__('program_requests.decline_reason')" required maxlength="1000" data-test="program-request-decline-reason" />
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" data-test="save-program-request-decline">
            {{ __('program_requests.decline') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
