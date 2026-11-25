@php
    $writable = ! $this->proposal->status->isTerminal();
    $mayCreate = $writable && $canCreate;
    $mayEdit = $writable && auth()->user()->can('results.update');
    $mayRemove = $writable && $canEdit && auth()->user()->can('results.delete');
    $mayAttach = $writable && auth()->user()->can('indicators.create');
    $mayEditIndicator = $writable && auth()->user()->can('indicators.update');
    $mayEditLibrary = $writable && auth()->user()->can('indicator-library.update');
    $mayManageRisks = $writable && $this->canManageRisks;
    $columnCount = $mayEditIndicator || $mayEditLibrary ? 5 : 4;
@endphp

<div class="max-h-[65vh] overflow-auto" tabindex="0" aria-label="{{ __('proposals.framework.title') }}">
    <table class="w-full min-w-[48rem] table-fixed border-separate border-spacing-0 text-sm" data-test="framework-table">
        <thead class="sticky top-0 z-20 bg-white dark:bg-surface-2">
            <tr>
                <th scope="col" class="sticky start-0 z-10 w-[40%] border-b bg-white dark:bg-surface-2 border-secondary-light px-4 py-3 text-start dark:border-line">{{ __('proposals.framework.indicator') }}</th>
                <th scope="col" class="w-24 border-b border-secondary-light px-4 py-3 text-end dark:border-line">{{ __('indicators.baseline') }}</th>
                <th scope="col" class="w-24 border-b border-secondary-light px-4 py-3 text-end dark:border-line">{{ __('indicators.target') }}</th>
                <th scope="col" class="border-b border-secondary-light px-4 py-3 text-start dark:border-line">{{ __('indicator_library.fields.means_of_verification') }}</th>
                @if ($columnCount === 5)
                    <th scope="col" class="w-24 border-b border-secondary-light dark:border-line"><span class="sr-only">{{ __('common.actions') }}</span></th>
                @endif
            </tr>
        </thead>
        @forelse ($this->tableResults as $result)
            @php
                $assumptions = $this->tableAssumptions->get($result->id, []);
                $riskScore = fn (\App\Models\Risk $risk): int => max(1, (int) $risk->likelihood) * max(1, (int) $risk->severity);
                $risks = collect($this->proposalRisks->get($result->id, []))->sortByDesc($riskScore)->values();
                $canRemoveRow = $mayRemove && $result->children_count === 0 && $result->indicators->isEmpty();
            @endphp
            <tbody wire:key="framework-group-{{ $result->id }}">
                <tr data-result-type="{{ $result->type->value }}" wire:key="framework-row-{{ $result->id }}-0">
                    <th scope="rowgroup" colspan="{{ $columnCount }}" @class([
                        'border-b border-secondary-light bg-secondary-soft px-4 py-3 text-start dark:border-line dark:bg-surface-2',
                        'border-t border-t-secondary-medium/55 dark:border-t-white/35' => ! $loop->first && $result->type === \App\Enums\ResultType::Impact,
                    ])>
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <div class="min-w-0 flex-1">
                                <span class="me-2 inline-block text-xs font-semibold text-secondary-medium dark:text-ink-muted">{{ $result->type->label() }}</span>
                                <bdi class="break-words">{{ $result->statement }}</bdi>
                                @if (count($assumptions) > 0 || count($risks) > 0)
                                    <div class="mt-1 text-xs font-normal text-secondary-medium dark:text-ink-muted">
                                        <div class="font-semibold">{{ __('proposals.framework.assumptions_risks') }}</div>
                                        @if (count($assumptions) > 0)
                                            <div x-data="{ all: false }">
                                                @foreach ($assumptions as $assumption)
                                                    <div dir="auto" @if ($loop->index >= 4) x-show="all" x-cloak @endif>{{ $assumption->statement }}</div>
                                                @endforeach
                                                @if (count($assumptions) > 4)
                                                    <button type="button" class="pp-link" x-on:click="all = ! all" x-bind:aria-expanded="all" x-text="all ? @js(__('proposals.framework.show_fewer')) : @js(__('proposals.framework.show_all', ['count' => count($assumptions)]))">{{ __('proposals.framework.show_all', ['count' => count($assumptions)]) }}</button>
                                                @endif
                                            </div>
                                        @endif
                                        @if ($risks->isNotEmpty())
                                            <div x-data="{ all: false }">
                                                @foreach ($risks as $risk)
                                                    @php
                                                        $score = $riskScore($risk);
                                                        $level = $score >= 6 ? 'high' : ($score >= 3 ? 'medium' : 'low');
                                                    @endphp
                                                    <div class="flex items-center gap-1.5" @if ($loop->index >= 4) x-show="all" x-cloak @endif>
                                                        <span class="pp-fw-score pp-fw-score-{{ $level }}" title="{{ __('proposals.framework.risk_score_'.$level) }}">{{ __('proposals.framework.risk_letter_'.$level) }}</span>
                                                        <bdi>{{ $risk->localizedName() }}</bdi>
                                                    </div>
                                                @endforeach
                                                @if ($risks->count() > 4)
                                                    <button type="button" class="pp-link" x-on:click="all = ! all" x-bind:aria-expanded="all" x-text="all ? @js(__('proposals.framework.show_fewer')) : @js(__('proposals.framework.show_all', ['count' => $risks->count()]))">{{ __('proposals.framework.show_all', ['count' => $risks->count()]) }}</button>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <div class="flex items-center gap-1">
                                @if (in_array($result->type, [\App\Enums\ResultType::Outcome, \App\Enums\ResultType::Output], true) && ($mayManageRisks || count($risks) > 0))
                                    <flux:button size="sm" variant="ghost" data-test="result-risks-{{ $result->id }}" wire:click="openRisks({{ $result->id }})">{{ __('proposals.framework.layer_risks') }} · {{ count($risks) }}</flux:button>
                                @endif
                                @if ($mayAttach)
                                    <flux:button size="sm" variant="ghost" icon="plus" :aria-label="__('indicators.add_indicator')" :title="__('indicators.add_indicator')" data-test="add-indicator-for-result-{{ $result->id }}" wire:click="$dispatch('add-indicator', { resultId: {{ $result->id }} })" />
                                @endif
                                @if ($mayEdit)
                                    <flux:button size="sm" icon="pencil-square" wire:click="openEditModal({{ $result->id }})">{{ __('common.edit') }}</flux:button>
                                @endif
                                @if ($mayCreate || $mayEdit || $canRemoveRow)
                                    <flux:dropdown align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('common.actions')" />
                                        <flux:menu>
                                            @if ($mayCreate)
                                                <flux:menu.item icon="arrow-down" wire:click="addRowBelow({{ $result->id }})">{{ __('proposals.framework.add_row_below') }}</flux:menu.item>
                                                @if ($result->type->acceptsChildren())
                                                    <flux:menu.item icon="plus" data-test="add-child-result-{{ $result->id }}" wire:click="openCreateModal({{ $result->id }})">{{ __('proposals.framework.add_child_row') }}</flux:menu.item>
                                                @endif
                                            @endif
                                            @if ($mayEdit)
                                                <flux:menu.item icon="chat-bubble-left" wire:click="openAssumption(null, {{ $result->id }})">{{ __('proposals.framework.add_assumption') }}</flux:menu.item>
                                            @endif
                                            @if ($canRemoveRow)
                                                <flux:menu.item icon="trash" variant="danger" data-test="delete-result-{{ $result->id }}" wire:click="confirmDelete({{ $result->id }})">{{ __('proposals.framework.remove_row') }}</flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                            </div>
                        </div>
                    </th>
                </tr>
                @forelse ($result->indicators as $indicator)
                    <tr wire:key="framework-row-{{ $result->id }}-{{ $indicator->id }}">
                        <td class="sticky start-0 z-10 border-b border-secondary-light bg-white px-4 py-2.5 align-middle dark:border-line dark:bg-surface-2" data-test="framework-indicator-{{ $indicator->id }}">
                            <bdi class="break-words">{{ $indicator->libraryIndicator?->localizedName() ?? $indicator->name }}</bdi>
                            @if ($indicator->project !== null)
                                <div class="text-xs text-secondary-medium dark:text-ink-muted" dir="auto">{{ $indicator->project->name }}</div>
                            @endif
                        </td>
                        <td class="border-b border-secondary-light px-4 py-2.5 text-end align-middle tabular-nums dark:border-line"><bdi>{{ $indicator->baseline ?? '—' }}</bdi></td>
                        <td class="border-b border-secondary-light px-4 py-2.5 text-end align-middle tabular-nums dark:border-line"><bdi>{{ $indicator->target ?? '—' }}</bdi></td>
                        <td class="break-words border-b border-secondary-light px-4 py-2.5 align-middle dark:border-line" dir="auto">{{ $indicator->libraryIndicator?->means_of_verification ?? '—' }}</td>
                        @if ($columnCount === 5)
                            <td class="border-b border-secondary-light px-4 py-2.5 align-middle dark:border-line">
                                <div class="grid grid-cols-2 justify-items-center">
                                    <div>
                                        @if ($mayEditIndicator)
                                            <flux:button size="sm" variant="ghost" icon="pencil-square" :aria-label="__('indicators.edit_indicator')" :title="__('indicators.edit_indicator')" data-test="edit-indicator-{{ $indicator->id }}" wire:click="$dispatch('edit-indicator', { indicatorId: {{ $indicator->id }} })" />
                                        @endif
                                    </div>
                                    @if ($mayEditLibrary && $indicator->libraryIndicator !== null)
                                        <flux:dropdown align="end">
                                            <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('common.actions')" />
                                            <flux:menu>
                                                <flux:menu.item icon="book-open" wire:click="$dispatch('edit-library-entry', { libraryIndicatorId: {{ $indicator->library_indicator_id }} })">{{ __('indicator_library.edit_entry') }}</flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    @endif
                                </div>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $columnCount }}" class="border-b border-secondary-light px-4 py-2.5 text-secondary-medium dark:border-line dark:text-ink-muted">{{ __('indicators.no_indicators_yet') }}</td></tr>
                @endforelse
            </tbody>
        @empty
            <tbody>
                <tr><td colspan="{{ $columnCount }}" class="px-4 py-5">{{ $search === '' ? __('proposals.framework.no_results') : __('proposals.framework.nothing_matches', ['search' => $search]) }}</td></tr>
            </tbody>
        @endforelse
    </table>
</div>
