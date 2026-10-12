        {{-- Every tranche, with every figure of its journey in its own column. --}}
        <section class="mt-10 min-w-0" aria-labelledby="tranches-heading" data-tranches>
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                <h2 id="tranches-heading" class="text-xl font-bold tracking-tight {{ $inkText }}">
                    {{ __('funding_agreements.tranches') }}
                    <span class="ms-1 text-base font-semibold tabular-nums {{ $mutedText }}">{{ count($journeys) }}</span>
                </h2>
                @if ($journeys !== [])
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ route('transfers.index', ['agreement' => $agreement->id]) }}" wire:navigate class="text-xs font-semibold underline-offset-4 hover:underline {{ $inkText }}" data-transfers-ledger-link>{{ __('funding_agreements.open_in_transfers_ledger') }}</a>
                        <x-ui.button size="sm" icon="arrow-down-tray" class="min-h-10 touch-manipulation" wire:click="exportTranchesCsv" wire:loading.attr="disabled" wire:target="exportTranchesCsv" data-test="export-csv">{{ __('finance_tables.export_csv') }}</x-ui.button>
                    </div>
                @endif
            </div>

            @if ($journeys === [])
                <div class="mt-6">
                    <x-ui.empty-state class="rtl:[&_svg]:-scale-x-100" icon="arrow-right-circle" :heading="__('funding_agreements.no_tranches_scheduled')" :body="__('funding_agreements.payments_scheduled_under_this_agreement_will_appear_here')" />
                </div>
            @else
                <x-funding.tranche-table class="mt-4" :transfers="$this->transfers" :caption="__('funding_agreements.tranches_of_name', ['name' => $donor->name.' · '.$proposal->name])" editable />
            @endif

            @if ($money['unscheduled'] > 0.0)
                <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 py-4" data-unscheduled-line>
                    <p class="flex items-center gap-2 text-sm {{ $mutedText }}">
                        <span class="size-2 rounded-full border-2 border-dashed border-secondary-light" aria-hidden="true"></span>
                        {{ __('funding_agreements.not_scheduled_yet') }}
                        <span class="font-bold tabular-nums {{ $inkText }}">{{ $amount($money['unscheduled']) }} {{ $currency }}</span>
                    </p>
                    @can('transfers.create')
                    <button type="button" wire:click="openTransferCreate({{ $agreement->id }})" class="min-h-9 touch-manipulation text-xs font-semibold underline underline-offset-4 {{ $inkText }}">{{ __('funding_agreements.schedule_the_rest') }}</button>
                    @endcan
                </div>
            @endif
        </section>
