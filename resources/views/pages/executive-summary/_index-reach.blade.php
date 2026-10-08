    <div class="border-t pt-8 {{ $hairline }}">
        <div class="grid gap-x-10 gap-y-7 @3xl/ledger:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
            <div class="min-w-0">
                <p class="type-label {{ $muted }}">{{ __('executive_summary.people_this_year_sets_out_to_reach') }}</p>
                <p class="mt-2 text-3xl leading-none font-extrabold tracking-tight tabular-nums {{ $ink }} @xl/ledger:text-5xl">
                    {{ $amount($this->reach['beneficiaries']) }}
                </p>
                <p class="mt-2.5 type-meta {{ $muted }}">
                    {{ trans_choice('executive_summary.planned_across_count_projects', $this->reach['projects'], ['count' => $this->reach['projects']]) }}
                </p>
            </div>

            <div class="min-w-0">
                @if ($this->reach['beneficiaries'] <= 0)
                    <p class="type-meta {{ $muted }}">{{ __('executive_summary.no_beneficiary_targets_recorded') }}</p>
                @else
                    <div class="measure-track flex h-2.5 w-full overflow-hidden" role="presentation">
                        @foreach ($this->reach['groups'] as $group)
                            <div
                                class="ledger-fill h-full {{ ['women' => 'bg-funding', 'men' => 'bg-status-active', 'girls' => 'bg-status-warn', 'boys' => 'bg-secondary-light'][$group['key']] }}"
                                style="width: {{ $group['share'] }}%; --row-order: {{ $loop->index }}"
                            ></div>
                        @endforeach
                    </div>

                    <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-3 @xl/ledger:grid-cols-4">
                        @foreach ($this->reach['groups'] as $group)
                            <div class="min-w-0" wire:key="group-{{ $group['key'] }}">
                                <dt class="flex items-center gap-1.5 truncate type-meta {{ $muted }}">
                                    <span class="size-1.5 shrink-0 rounded-full {{ ['women' => 'bg-funding', 'men' => 'bg-status-active', 'girls' => 'bg-status-warn', 'boys' => 'bg-secondary-light'][$group['key']] }}" aria-hidden="true"></span>
                                    {{ $group['label'] }}
                                </dt>
                                <dd class="mt-0.5 text-lg leading-none font-bold tabular-nums {{ $ink }}">
                                    {{ $amount($group['count']) }}<span class="ms-1.5 text-[11px] font-semibold {{ $muted }}">{{ $group['share'] }}%</span>
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </div>
        </div>

        @if ($this->reach['cityCount'] > 0)
            <div class="mt-7 flex flex-wrap items-baseline gap-x-2 gap-y-1.5">
                <span class="type-label {{ $muted }}">{{ trans_choice('executive_summary.across_count_cities', $this->reach['cityCount'], ['count' => $this->reach['cityCount']]) }}</span>
                @foreach ($this->reach['cities'] as $city)
                    <span class="type-meta {{ $ink }}" wire:key="city-{{ $city->id }}">{{ $city->name }}@if (! $loop->last)<span class="{{ $muted }}"> ·</span>@endif</span>
                @endforeach
            </div>
        @endif
    </div>
