                    @if ($isOpen)
                        <div id="breakdown-{{ $row['key'] }}" class="ledger-reveal border-b bg-secondary-soft/20 px-1 pb-5 {{ $hairline }} dark:bg-white/[0.04]">
                            @if ($this->breakdown === [])
                                <p class="py-4 text-center type-meta {{ $muted }}">{{ __('executive_summary.nothing_behind_this_figure') }}</p>
                            @else
                                <p class="pb-3 type-label {{ $muted }}">{{ __('executive_summary.widest_gap_first') }}</p>

                                <ul class="grid gap-3">
                                    @foreach ($this->breakdown as $item)
                                        <li class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1.5 @2xl/ledger:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] @2xl/ledger:gap-x-8" wire:key="breakdown-{{ $row['key'] }}-{{ $item['key'] }}">
                                            <span class="min-w-0 @2xl/ledger:text-end">
                                                @if ($item['href'] !== null)
                                                    <a href="{{ $item['href'] }}" wire:navigate class="block truncate text-sm font-semibold underline decoration-secondary-light underline-offset-4 hover:decoration-primary {{ $ink }}">{{ $item['name'] }}</a>
                                                @else
                                                    <span class="block truncate text-sm font-semibold {{ $ink }}">{{ $item['name'] }}</span>
                                                @endif
                                                <span class="block truncate type-meta {{ $muted }}">{{ $item['context'] }}</span>
                                            </span>

                                            <span class="ledger-strike flex shrink-0 items-center justify-center self-stretch @2xl/ledger:w-28">
                                                <span class="ledger-chip relative z-10 px-2 text-xs font-bold tabular-nums {{ $item['share'] === null ? $muted : $toneInk[$item['tone']] }}">
                                                    {{ $item['share'] === null ? '—' : $item['share'].'%' }}
                                                </span>
                                            </span>

                                            <span class="col-span-2 min-w-0 @2xl/ledger:col-span-1">
                                                <span class="block type-meta tabular-nums {{ $muted }}">
                                                    {{ $row['kind'] === 'money' ? $amount($item['delivered']).' / '.$amount($item['committed']) : $item['delivered'].' / '.$item['committed'] }}
                                                </span>
                                                <span class="measure-track mt-1.5 block h-1 w-full overflow-hidden">
                                                    <span class="block h-full {{ $toneFill[$item['tone']] }}" style="width: {{ $item['share'] ?? 0 }}%"></span>
                                                </span>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>

                                <p class="pt-4 text-end">
                                    <a href="{{ $row['href'] }}" wire:navigate class="type-meta font-semibold underline decoration-secondary-light underline-offset-4 hover:decoration-primary {{ $ink }}">{{ $row['hrefLabel'] }}</a>
                                </p>
                            @endif
                        </div>
                    @endif
