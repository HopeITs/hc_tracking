{{--
    The register table of proposals/index and its pager. Included by
    ⚡index.blade.php when the table view has rows.
--}}
        @php
            $money = fn (string|float|int|null $value): string => number_format((float) $value, 2);
            $date = fn (?\DateTimeInterface $value): string => $value?->translatedFormat(__('formats.date')) ?? '—';
            $unreadIds = $this->unreadIds;
            $previousProposalId = null;
        @endphp

        {{-- Few columns, no sideways scroll, no money totals or committed column (user decisions 2026-11-19); the figures are on the proposal page. --}}
        <x-funding.register size="sm" :caption="__('finance_tables.proposals_caption')">
            <x-slot:head>
                <x-funding.register.th sort="proposal" :active="$sortKey" :dir="$sortDirection">{{ __('common.proposal') }}</x-funding.register.th>
                <x-funding.register.th sort="status" :active="$sortKey" :dir="$sortDirection">{{ __('common.status') }}</x-funding.register.th>
                @if ($canAgreements)
                    <x-funding.register.th>{{ __('common.donors') }}</x-funding.register.th>
                @endif
                <x-funding.register.th sort="planned_start" :active="$sortKey" :dir="$sortDirection">{{ __('proposals.period') }}</x-funding.register.th>
                <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->rows as $proposal)
                @php
                    // A proposal funded in two currencies has two lines; its identity is printed on the first only.
                    $isRepeat = $previousProposalId === $proposal->id;
                    $previousProposalId = $proposal->id;
                    $lineCurrency = $canAgreements ? $proposal->money_currency : null;
                    $hasMoney = $lineCurrency !== null;
                    $lineAgreements = $hasMoney ? $proposal->donorProposals->filter(fn ($agreement): bool => $agreement->currency->value === $lineCurrency)->unique('donor_id') : collect();
                    $overdue = $canTransfers && $hasMoney ? (float) $proposal->money_overdue : 0.0;
                @endphp
                <tr wire:key="proposal-{{ $proposal->id }}-{{ $lineCurrency ?? 'none' }}" data-proposal-row data-proposal="{{ $proposal->id }}">
                    <td data-label="{{ __('common.proposal') }}" data-wide>
                        @if (! $isRepeat && in_array($proposal->id, $unreadIds, true))
                            <span class="me-1 inline-block size-2 rounded-full bg-primary align-middle dark:bg-ink" data-unread-dot="proposal-{{ $proposal->id }}" title="{{ __('notifications.has_unread') }}"><span class="sr-only">{{ __('notifications.has_unread') }}</span></span>
                        @endif
                        @if ($isRepeat)
                            <span class="font-semibold {{ $mutedText }}" dir="auto">{{ $proposal->name }}</span>
                        @else
                            <a href="{{ route('proposals.show', $proposal) }}" wire:navigate class="font-semibold underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium" dir="auto" data-test="proposal-name-link-{{ $proposal->id }}">{{ $proposal->name }}</a>
                        @endif
                        @if (! $isRepeat && $proposal->isPayroll())
                            <x-ui.enum-badge :case="$proposal->kind" class="ms-1.5 align-middle" data-payroll-chip="{{ $proposal->id }}" />
                        @endif
                        @unless ($isRepeat)
                            <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs {{ $mutedText }}">
                                <x-ui.project-number :number="$proposal->internal_project_number" />
                                @if ($overdue > 0)
                                    <span class="font-semibold text-status-critical" data-overdue-flag="{{ $proposal->id }}">{{ __('proposals.overdue_amount', ['amount' => $money($overdue), 'currency' => $lineCurrency]) }}</span>
                                @endif
                            </span>
                        @endunless
                        @if (! $isRepeat && $this->flag === 'no_center')
                            {{-- The rows still without a center, and where to give them one: the editor opens for an approved proposal only. --}}
                            <div class="mt-1.5 space-y-1 text-xs {{ $mutedText }}" data-test="missing-center-rows-{{ $proposal->id }}">
                                <p class="font-semibold">{{ __('proposals.missing_center_rows') }}</p>
                                <ul class="list-disc ps-4">
                                    @foreach ($proposal->locations as $missingRow)
                                        <li wire:key="missing-center-{{ $proposal->id }}-{{ $missingRow->id }}" dir="auto">{{ __('proposals.missing_center_row', ['city' => $missingRow->city?->name ?? '—', 'program' => $missingRow->program?->name ?? __('proposals.missing_center_no_program')]) }}</li>
                                    @endforeach
                                </ul>
                                @if ($proposal->status->isApprovedByDonor())
                                    <a href="{{ route('proposals.show', ['proposal' => $proposal, 'add-centers' => 1]) }}" wire:navigate class="inline-flex min-h-8 items-center gap-1 font-semibold underline underline-offset-2 hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium" aria-label="{{ __('proposals.add_centers_link_name', ['name' => $proposal->name]) }}" data-test="add-centers-link-{{ $proposal->id }}">
                                        <flux:icon.plus variant="micro" class="size-3.5" aria-hidden="true" />
                                        {{ __('proposals.add_centers') }}
                                    </a>
                                @endif
                            </div>
                        @endif
                    </td>
                    <td data-label="{{ __('common.status') }}">
                        @unless ($isRepeat)
                            <x-ui.enum-badge
                                :case="$proposal->status"
                                :label="$proposal->statusLabel()"
                                :tone="$proposal->isOnHold() ? \App\Enums\StatusTone::Warn : null"
                            />
                        @endunless
                    </td>
                    @if ($canAgreements)
                    <td data-label="{{ __('common.donors') }}">
                        @if ($lineAgreements->isNotEmpty())
                            @foreach ($lineAgreements as $lineAgreement)
                                @can('donors.view')
                                    <a href="{{ route('donors.show', $lineAgreement->donor) }}" wire:navigate class="block underline-offset-2 hover:underline" dir="auto">{{ $lineAgreement->donor->name }}</a>
                                @else
                                    <span class="block" dir="auto">{{ $lineAgreement->donor->name }}</span>
                                @endcan
                            @endforeach
                        @elseif (! $isRepeat)
                            <span dir="auto" @class([$mutedText => $proposal->donor === null])>{{ $proposal->donor?->name ?? '—' }}</span>
                        @endif
                    </td>
                    @endif
                    <td class="date" data-label="{{ __('proposals.period') }}">
                        @unless ($isRepeat)
                            <span class="whitespace-nowrap">{{ $date($proposal->planned_date_begin) }} – {{ $date($proposal->planned_date_end) }}</span>
                        @endunless
                    </td>
                    <td>
                        @unless ($isRepeat)
                            <span class="flex items-center justify-end">
                                <x-ui.button tone="ghost" size="sm" icon="eye" icon:variant="outline" class="size-8!" :href="route('proposals.show', $proposal)" wire:navigate :aria-label="__('common.view_name', ['name' => $proposal->name])" />
                                @can('update', $proposal)
                                    {{-- A status move writes its audit note; only proposals with an allowed move get the button. --}}
                                    @if ($proposal->transitionTargets() !== [])
                                    <x-ui.button tone="ghost" size="sm" icon="arrows-right-left" icon:variant="outline" class="size-8!" wire:click="openStatusModal({{ $proposal->id }})" :aria-label="__('proposals.change_status')" data-test="advance-proposal-{{ $proposal->id }}" />
                                    @endif
                                    <x-ui.button tone="ghost" size="sm" icon="pencil-square" icon:variant="outline" class="size-8!" wire:click="openEditModal({{ $proposal->id }})" :aria-label="__('common.edit_name', ['name' => $proposal->name])" />
                                @endcan
                                @can('delete', $proposal)
                                    @if ($proposal->donor_proposals_count === 0)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" icon:variant="outline" class="size-8!" wire:click="confirmDelete({{ $proposal->id }})" :aria-label="__('common.delete_name', ['name' => $proposal->name])" />
                                    @else
                                        <flux:tooltip :content="__('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem')">
                                            <span class="inline-flex size-8 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true" tabindex="0" aria-label="{{ __('proposals.funded_proposals_cannot_be_deleted_remove_the_funding_agreem') }}">
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

        <x-funding.pager :paginator="$this->rows" :per-page="(string) $this->registerPerPage()" :sizes="$this->registerPageSizes()" scroll-to="#proposal-pipeline-heading" />
