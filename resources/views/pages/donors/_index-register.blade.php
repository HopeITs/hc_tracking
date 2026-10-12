@use('App\Models\DonorProposal')

        @php
            $totals = $this->totals;
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';
            $today = \Carbon\CarbonImmutable::today();
            $showsYearColumns = $selectedYear !== null && ! $yearOnly;

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('finance_tables.count_donors', $line['donors'], ['count' => $line['donors']]),
                'figures' => array_values(array_filter([
                    ['key' => 'committed', 'label' => __('common.committed'), 'value' => $line['committed']],
                    $canTransfers ? ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $line['committed']).'%'] : null,
                    $showsYearColumns ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $showsYearColumns ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    $canTransfers && ! $yearOnly ? ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']] : null,
                    $canTransfers ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null] : null,
                ])),
            ])->all();

            $previousDonorId = null;
        @endphp

        @if ($canAgreements)
            <x-funding.totals :lines="$totalLines" />
        @endif

        {{-- A slim register: who, when to follow up, and the money at a glance. Contact details, the next step and the rest of the rhythm wait on the donor's own page. --}}
        <x-funding.register :size="! $canTransfers ? 'sm' : ($showsYearColumns ? 'lg' : 'md')" :caption="__('finance_tables.donors_caption')">
            <x-slot:head>
                <x-funding.register.th sort="donor" :active="$sortKey" :dir="$sortDirection">{{ __('donors.donor') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('donors.section_contact') }}</x-funding.register.th>
                <x-funding.register.th sort="follow_up" :active="$sortKey" :dir="$sortDirection">{{ __('donors.follow_up') }}</x-funding.register.th>
                @if ($canAgreements)
                    <x-funding.register.th sort="committed" :active="$sortKey" :dir="$sortDirection" numeric>{{ $yearOnly ? __('finance_tables.committed_whole') : __('common.committed') }}</x-funding.register.th>
                @endif
                @if ($canTransfers)
                    <x-funding.register.th sort="received" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received') }}</x-funding.register.th>
                    @if ($showsYearColumns)
                        <x-funding.register.th sort="due_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                        <x-funding.register.th sort="received_in_year" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}</x-funding.register.th>
                    @endif
                    @unless ($yearOnly)
                        <x-funding.register.th sort="outstanding" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.outstanding') }}</x-funding.register.th>
                    @endunless
                    <x-funding.register.th sort="overdue" :active="$sortKey" :dir="$sortDirection" numeric>{{ __('finance_tables.overdue') }}</x-funding.register.th>
                    <x-funding.register.th sort="next_transfer" :active="$sortKey" :dir="$sortDirection">{{ __('donors.next_transfer') }}</x-funding.register.th>
                @endif
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $donor)
                @php
                    // A donor funding in two currencies has two lines; its identity is printed on the first only.
                    $isRepeat = $previousDonorId === $donor->id;
                    $previousDonorId = $donor->id;
                    $isProspect = $donor->donor_proposals_count === 0;
                    $lineCurrency = $canAgreements ? $donor->money_currency : null;
                    $hasMoney = $lineCurrency !== null;
                    $next = $canTransfers && $hasMoney ? $this->nextTransfers->get($donor->id.'|'.$lineCurrency) : null;
                    $nextDate = $next === null ? null : \Carbon\CarbonImmutable::parse($next->scheduled_date);
                    $overdue = $canTransfers && $hasMoney ? (float) $donor->money_overdue : 0.0;
                    // Several people share one field, split by slashes: the row names the first and counts the rest.
                    $contacts = collect(preg_split('/\s*\/\s*/u', (string) $donor->contact_person) ?: [])->map(fn (string $name): string => trim($name))->filter()->values();
                    $identity = collect([$donor->type?->label() ?? __('donors.not_classified'), $donor->country])->filter()->implode(' · ');
                @endphp
                <tr wire:key="donor-{{ $donor->id }}-{{ $lineCurrency ?? 'none' }}" data-donor-row data-donor="{{ $donor->id }}">
                    <td data-label="{{ __('donors.donor') }}" data-wide>
                        <span @class(['line-clamp-2 max-w-64 font-semibold', $mutedText => $isRepeat]) title="{{ $donor->name }}">{{ $donor->name }}</span>
                        @unless ($isRepeat)
                            <span class="sub max-w-64 truncate">{{ $identity }}</span>
                        @endunless
                    </td>
                    <td data-label="{{ __('donors.section_contact') }}">
                        @unless ($isRepeat)
                            @if ($contacts->isNotEmpty())
                                <span class="mx-auto block max-w-48 truncate" title="{{ $contacts->first() }}">{{ $contacts->first() }}</span>
                                @if ($contacts->count() > 1)
                                    <span class="sub">{{ __('donors.count_more_contacts', ['count' => $contacts->count() - 1]) }}</span>
                                @endif
                            @elseif (filled($donor->email) || filled($donor->phone))
                                <span class="mx-auto block max-w-48 truncate">{{ $donor->email ?: $donor->phone }}</span>
                            @else
                                <span class="{{ $mutedText }}">—</span>
                            @endif
                        @endunless
                    </td>
                    <td class="date" data-label="{{ __('donors.follow_up') }}">
                        @if (! $isRepeat && $donor->next_step_due_on !== null)
                            <span @class(['font-semibold text-status-critical' => $donor->isFollowUpOverdue()]) @if ($donor->isFollowUpOverdue()) data-follow-up-overdue @endif>{{ $date($donor->next_step_due_on) }}</span>
                        @elseif (! $isRepeat)
                            —
                        @endif
                    </td>

                    @if ($canAgreements)
                        <td class="num" data-label="{{ __('common.committed') }}" data-cell="committed">
                            @if ($hasMoney)
                                <span class="fig font-semibold">{{ $money($donor->money_committed) }}</span>
                                <span class="sub">{{ $lineCurrency }} · {{ trans_choice('finance_tables.count_agreements', (int) $donor->money_agreements, ['count' => (int) $donor->money_agreements]) }}</span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    @if ($canTransfers)
                        <td class="num" data-label="{{ __('finance_tables.received') }}" data-cell="received">
                            @if ($hasMoney)
                                <span class="fig font-semibold text-funding">{{ $money($donor->money_received) }}</span>
                                <span class="sub"><span class="fig">{{ DonorProposal::percentLabel((float) $donor->money_received, (float) $donor->money_committed) }}%</span></span>
                            @else
                                —
                            @endif
                        </td>
                        @if ($showsYearColumns)
                            <td class="num" data-label="{{ __('finance_tables.due_in_year', ['year' => $selectedYear]) }}" data-cell="due_in_year"><span class="fig">{{ $money($donor->money_due_in_year) }}</span></td>
                            <td class="num" data-label="{{ __('finance_tables.received_in_year', ['year' => $selectedYear]) }}" data-cell="received_in_year"><span class="fig font-semibold text-funding">{{ $money($donor->money_received_in_year) }}</span></td>
                        @endif
                        @unless ($yearOnly)
                            <td class="num" data-label="{{ __('finance_tables.outstanding') }}" data-cell="outstanding">
                                @if ($hasMoney)
                                    <span class="fig">{{ $money($donor->money_outstanding) }}</span>
                                @else
                                    —
                                @endif
                            </td>
                        @endunless
                        <td class="num" data-label="{{ __('finance_tables.overdue') }}" data-cell="overdue">
                            @if ($hasMoney)
                                <span @class(['fig', 'font-semibold text-status-critical' => $overdue > 0])>{{ $money($overdue) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="date" data-label="{{ __('donors.next_transfer') }}">
                            @if ($nextDate !== null)
                                <span @class(['font-semibold text-status-critical' => $nextDate->lt($today)]) data-next-transfer>{{ $date($nextDate) }}</span>
                                <span class="sub"><span class="fig">{{ $money($next->payment_amount) }} {{ $next->sent_currency }}</span></span>
                            @else
                                —
                            @endif
                        </td>
                    @endif

                    <td>
                        @unless ($isRepeat)
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('donors.show', $donor)" wire:navigate :aria-label="__('common.view_name', ['name' => $donor->name])" />
                                @can('donors.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $donor->id }})" :aria-label="__('common.edit_name', ['name' => $donor->name])" />
                                @endcan
                                @can('donors.delete')
                                    @if ($isProspect)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" icon:variant="outline" class="size-8!" wire:click="confirmDelete({{ $donor->id }})" :aria-label="__('common.delete_name', ['name' => $donor->name])" />
                                    @else
                                        <flux:tooltip :content="__('donors.donors_holding_an_agreement_cannot_be_deleted')">
                                            <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                                <flux:icon.lock-closed class="size-4" />
                                            </span>
                                        </flux:tooltip>
                                    @endif
                                @endcan
                            </span>
                        @endunless
                    </td>
                </tr>
            @endforeach
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#donor-directory-heading" />
