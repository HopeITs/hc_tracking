{{--
    The Table view of the funding agreements page: the per-currency totals,
    the sortable register of agreements and its pager. Included by
    ⚡index.blade.php, which supplies $chosen, $selectedYear, $yearOnly,
    $sortKey and $sortDirection.
--}}
@use('App\Models\DonorProposal')
@use('App\Support\AgreementHealth')
@use('App\Support\AgreementMoney')
        @php
            $totals = $this->totals;
            $columns = AgreementMoney::registerColumns(year: $selectedYear, yearOnly: $yearOnly);

            /*
             * One quiet line per currency: what was promised, what landed, and
             * what is still owed. A figure that is only ever an alarm — money
             * past its date, money that failed — earns its place only when
             * there is some, so a healthy currency reads as three numbers.
             */
            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('common.count_agreement', $line['agreements'], ['count' => $line['agreements']]),
                'figures' => array_values(array_filter([
                    ['key' => 'budget', 'label' => $yearOnly ? __('finance_tables.budget_whole_agreement') : __('finance_tables.budget'), 'value' => $line['budget']],
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $yearOnly ? $line['scheduled'] : $line['budget']).'%'],
                    $selectedYear !== null && ! $yearOnly ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $selectedYear !== null && ! $yearOnly ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    $line['overdue'] > 0 ? ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => 'critical'] : null,
                    $line['failed'] > 0 ? ['key' => 'failed', 'label' => __('finance_tables.failed'), 'value' => $line['failed'], 'tone' => 'critical'] : null,
                    $yearOnly ? null : ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']],
                ])),
            ])->all();
        @endphp

        <x-funding.totals :lines="$totalLines" />

        {{-- 2xl (104rem) was the min-width fourteen money columns needed. Six fit in
             sm (56rem), so the table stops forcing a sideways scroll and its heading
             row sticks again; the agreement name takes whatever slack is left. --}}
        <x-funding.register size="sm" :caption="__('finance_tables.agreements_caption')">
            <x-slot:head>
                <x-funding.register.th sort="agreement" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.agreement') }}</x-funding.register.th>
                <x-funding.register.th>{{ __('common.status') }}</x-funding.register.th>
                <x-funding.agreement-head :columns="$columns" sortable :active="$sortKey" :dir="$sortDirection" />
            </x-slot:head>

            @foreach ($this->rows as $agreement)
                @php
                    $agreementName = $agreement->donor->name.' · '.$agreement->proposal->name;
                @endphp
                <x-funding.agreement-row :agreement="$agreement" :columns="$columns" :year-only="$yearOnly">
                    <x-slot:lead>
                        <td data-label="{{ __('finance_tables.agreement') }}" data-wide>
                            @can('donors.view')
                                <a href="{{ route('donors.show', $agreement->donor) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline">{{ $agreement->donor->name }}</a>
                            @else
                                <span class="font-semibold">{{ $agreement->donor->name }}</span>
                            @endcan
                            <span class="sub">
                                @can('proposals.view')
                                    <a href="{{ route('proposals.show', $agreement->proposal) }}" wire:navigate class="underline-offset-2 hover:underline">{{ $agreement->proposal->name }}</a>
                                @else
                                    {{ $agreement->proposal->name }}
                                @endcan
                                · {{ $agreement->proposal->status->label() }}
                            </span>
                            {{-- An agreement with no external number says nothing about it: a whole
                             line of "no external project number" on most rows is not information. --}}
                        @if (filled($agreement->external_project_number))
                            <span class="sub">{{ $agreement->external_project_number }}</span>
                        @endif
                        </td>

                        @php
                            $health = AgreementHealth::of($agreement);
                        @endphp
                        <td data-label="{{ __('common.status') }}" data-cell="health" data-health="{{ $health }}">
                            <span @class([
                                'inline-flex items-center gap-1.5 font-semibold whitespace-nowrap',
                                {{-- The text token, not the surface one: this is 14px type. --}}
                                'text-status-critical-text' => $health === AgreementHealth::ATTENTION,
                                'text-funding' => $health === AgreementHealth::MOVING,
                                'text-secondary-medium dark:text-ink-muted' => $health === AgreementHealth::NOT_STARTED,
                            ])>
                                <span @class([
                                    'size-2 shrink-0 rounded-full',
                                    'bg-primary dark:bg-status-critical' => $health === AgreementHealth::ATTENTION,
                                    'bg-funding' => $health === AgreementHealth::MOVING,
                                    'border border-dashed border-secondary-medium dark:border-ink-faint' => $health === AgreementHealth::NOT_STARTED,
                                ]) aria-hidden="true"></span>
                                {{ AgreementHealth::label($health) }}
                            </span>
                        </td>
                    </x-slot:lead>

                    <x-slot:actions>
                        {{-- No delete here: an agreement is deleted from its edit form, and only while nothing is filed under it. --}}
                        <td>
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8! max-sm:size-11! touch-manipulation" :href="route('funding-agreements.show', $agreement)" wire:navigate :aria-label="__('common.view_name', ['name' => $agreementName])" />
                                @can('funding-agreements.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8! max-sm:size-11! touch-manipulation" wire:click="openEditModal({{ $agreement->id }})" :aria-label="__('common.edit_name', ['name' => $agreementName])" data-test="edit-agreement-{{ $agreement->id }}" />
                                @endcan
                            </span>
                        </td>
                    </x-slot:actions>
                </x-funding.agreement-row>
            @endforeach
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#agreement-directory-heading" />
