{{--
    The Department Tasks dialogs: hand over a media deliverable (fields follow
    the kinds it asks for), accept it, or return it with a reason. Included by
    ⚡index.blade.php.
--}}
@php
    $deliveringTask = $deliveringTaskId === null ? null : $this->departmentTasks->firstWhere('id', $deliveringTaskId);
    $deliveryKinds = $deliveringTask?->media_kinds?->all() ?? [];
    $wantsLink = collect($deliveryKinds)->contains(fn ($kind): bool => in_array($kind, [\App\Enums\DeliverableKind::Video, \App\Enums\DeliverableKind::Photos, \App\Enums\DeliverableKind::File], true));
    $wantsFiles = collect($deliveryKinds)->contains(fn ($kind): bool => in_array($kind, [\App\Enums\DeliverableKind::Photos, \App\Enums\DeliverableKind::File], true));
    $wantsText = in_array(\App\Enums\DeliverableKind::Text, $deliveryKinds, true);
    $onlyPhotos = $wantsFiles && ! in_array(\App\Enums\DeliverableKind::File, $deliveryKinds, true);
    $accept = $onlyPhotos ? '.jpg,.jpeg,.png' : collect(\App\Enums\FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',');
@endphp

    <x-ui.modal
        wire:model="showDeliveryModal"
        size="md"
        :title="__('department_tasks.deliver_title')"
        :description="$deliveringTask !== null ? __('department_tasks.deliver_description', ['kinds' => collect($deliveryKinds)->map->label()->implode('، ')]) : null"
        submit="deliver"
        close="closeDeliveryModal"
        required-note
    >
        @if ($showDeliveryModal)
            @error('delivery')
                <p class="mb-4 text-sm font-semibold text-status-critical" role="alert" data-delivery-error>{{ $message }}</p>
            @enderror

            <div class="grid gap-y-4">
                <x-ui.form-input wire:model="deliveredOn" :label="__('department_tasks.delivered_on')" type="date" :max="today()->toDateString()" required />

                @if ($wantsLink)
                    <x-ui.form-input wire:model="deliveredLink" :label="__('department_tasks.delivered_link')" type="url" placeholder="https://" maxlength="2048" :help="in_array(\App\Enums\DeliverableKind::Video, $deliveryKinds, true) ? __('department_tasks.video_link_help') : null" />
                @endif

                @if ($wantsFiles)
                    @for ($slot = 0; $slot < $deliveryFileSlots; $slot++)
                        <x-ui.form-file
                            wire:model="deliveryFiles.{{ $slot }}"
                            wire:key="delivery-file-{{ $slot }}"
                            :label="$slot === 0 ? __('department_tasks.delivered_files') : __('department_tasks.another_file')"
                            :accept="$accept"
                            :types="$onlyPhotos ? 'JPG, PNG' : null"
                            max-size="5 MB"
                        />
                    @endfor
                    @error('deliveryFiles')
                        <p class="text-sm text-status-critical" role="alert">{{ $message }}</p>
                    @enderror
                    @if ($deliveryFileSlots < 10)
                        <div>
                            <x-ui.button size="sm" tone="ghost" icon="plus" wire:click="addDeliveryFileSlot">{{ __('department_tasks.add_another_file') }}</x-ui.button>
                        </div>
                    @endif
                @endif

                @if ($wantsText)
                    <x-ui.form-textarea wire:model="deliveredText" :label="__('department_tasks.delivered_text')" rows="5" maxlength="10000" />
                @endif
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="deliver, deliveryFiles" data-test="submit-delivery">{{ __('department_tasks.deliver') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showAcceptModal"
        tone="neutral"
        :title="__('department_tasks.accept_title')"
        :message="__('department_tasks.accept_message')"
        confirm="accept"
        :confirm-label="__('department_tasks.accept')"
        confirm-test="confirm-accept"
        close="closeReview"
    />

    <x-ui.modal
        wire:model="showReturnModal"
        size="sm"
        :title="__('department_tasks.return_title')"
        :description="__('department_tasks.return_message')"
        submit="returnDelivery"
        close="closeReview"
        required-note
    >
        @if ($showReturnModal)
            <x-ui.form-textarea wire:model="returnReason" :label="__('department_tasks.return_reason')" rows="4" maxlength="2000" required autofocus />
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" wire:loading.attr="disabled" wire:target="returnDelivery" data-test="submit-return">{{ __('department_tasks.return') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
