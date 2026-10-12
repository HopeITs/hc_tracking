{{--
    The Table view of the funding agreements page: the per-currency totals,
    the sortable register of agreements and its pager. Included by
    ⚡index.blade.php, which supplies $chosen, $selectedYear, $yearOnly,
    $sortKey and $sortDirection.
--}}
@use('App\Models\DonorProposal')
@use('App\Support\AgreementMoney')
        @php
            $totals = $this->totals;
            // A column nobody has money in is noise: failed money earns its column only when there is some.
            $showFailed = $totals->sum('failed') > 0 || $chosen['stage'] === 'failed';
            $columns = AgreementMoney::columns(year: $selectedYear, yearOnly: $yearOnly, withFailed: $showFailed);

            $totalLines = $totals->map(fn (array $line): array => [
                'currency' => $line['currency'],
                'caption' => trans_choice('common.count_agreement', $line['agreements'], ['count' => $line['agreements']]),
                'figures' => array_values(array_filter([
                    ['key' => 'budget', 'label' => $yearOnly ? __('finance_tables.budget_whole_agreement') : __('finance_tables.budget'), 'value' => $line['budget']],
                    ['key' => 'scheduled', 'label' => __('finance_tables.scheduled'), 'value' => $line['scheduled']],
                    ['key' => 'received', 'label' => __('finance_tables.received'), 'value' => $line['received'], 'tone' => 'good', 'note' => DonorProposal::percentLabel($line['received'], $yearOnly ? $line['scheduled'] : $line['budget']).'%'],
                    $selectedYear !== null && ! $yearOnly ? ['key' => 'due_in_year', 'label' => __('finance_tables.due_in_year', ['year' => $selectedYear]), 'value' => $line['due_in_year']] : null,
                    $selectedYear !== null && ! $yearOnly ? ['key' => 'received_in_year', 'label' => __('finance_tables.received_in_year', ['year' => $selectedYear]), 'value' => $line['received_in_year'], 'tone' => 'good'] : null,
                    ['key' => 'in_transit', 'label' => __('finance_tables.in_transit'), 'value' => $line['in_transit'], 'tone' => $line['in_transit'] > 0 ? 'warn' : null],
                    ['key' => 'overdue', 'label' => __('finance_tables.overdue'), 'value' => $line['overdue'], 'tone' => $line['overdue'] > 0 ? 'critical' : null],
                    $line['failed'] > 0 ? ['key' => 'failed', 'label' => __('finance_tables.failed'), 'value' => $line['failed'], 'tone' => 'critical'] : null,
                    $yearOnly ? null : ['key' => 'unscheduled', 'label' => __('finance_tables.not_scheduled'), 'value' => $line['unscheduled'], 'tone' => 'muted'],
                    $yearOnly ? null : ['key' => 'outstanding', 'label' => __('finance_tables.outstanding'), 'value' => $line['outstanding']],
                ])),
            ])->all();
        @endphp

        <x-funding.totals :lines="$totalLines" />

        <x-funding.register size="2xl" :caption="__('finance_tables.agreements_caption')">
            <x-slot:head>
                <x-funding.register.th sort="agreement" :active="$sortKey" :dir="$sortDirection">{{ __('finance_tables.agreement') }}</x-funding.register.th>
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
                            <span class="sub">{{ $agreement->external_project_number ?? __('common.no_external_project_number') }}</span>
                        </td>
                    </x-slot:lead>

                    <x-slot:actions>
                        {{-- No delete here: an agreement is deleted from its edit form, and only while nothing is filed under it. --}}
                        <td>
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('funding-agreements.show', $agreement)" wire:navigate :aria-label="__('common.view_name', ['name' => $agreementName])" />
                                @can('funding-agreements.update')
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $agreement->id }})" :aria-label="__('common.edit_name', ['name' => $agreementName])" data-test="edit-agreement-{{ $agreement->id }}" />
                                @endcan
                            </span>
                        </td>
                    </x-slot:actions>
                </x-funding.agreement-row>
            @endforeach
        </x-funding.register>

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#agreement-directory-heading" />
