{{-- What needs chasing on this agreement, most urgent first. Shown in both views of ⚡show.blade.php. --}}
            @if ($this->attention !== [])
                <section class="mt-7 border-t pt-5 {{ $hairline }}" aria-labelledby="attention-heading" data-attention>
                    <h2 id="attention-heading" class="type-label {{ $inkText }}">{{ trans_choice('funding_agreements.count_things_need_attention', count($this->attention), ['count' => count($this->attention)]) }}</h2>
                    <ul class="mt-3 flex flex-col gap-2">
                        @foreach ($this->attention as $item)
                            <li class="flex items-start gap-2.5 text-sm" wire:key="attention-{{ $item['key'] }}" data-attention-item="{{ $item['key'] }}">
                                <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-primary dark:bg-status-critical' => $item['critical'], 'border-2 border-status-warn' => ! $item['critical']]) aria-hidden="true"></span>
                                <span @class(['min-w-0', 'font-semibold text-status-critical' => $item['critical'], $inkText => ! $item['critical']])>{{ $item['text'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
