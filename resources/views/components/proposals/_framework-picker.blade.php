{{--
    "Start from program framework": the program's framework as a checklist.
    Ticking runs in the browser (instant); copySelection() checks it all again.
    Already here = greyed and locked; ticking a child ticks its parents,
    unticking a parent unticks its new children. Each outcome reads as one
    group with its outputs and indicators under it; the count and the Copy
    button sit in the dialog's own footer (shared through Alpine.store('pk')).
--}}
@php
    $tree = $showPicker ? $this->pickerTree : ['results' => [], 'risks' => [], 'programs' => 0];
    $copyLabel = __('proposals.picker.copy');
@endphp

<x-ui.modal wire:model="showPicker" size="lg" :title="__('proposals.picker.title')" :description="__('proposals.picker.intro')">
    @if ($showPicker)
        @if ($tree['results'] === [] && $tree['risks'] === [])
            <div class="pp-empty">{{ $tree['programs'] === 0 ? __('proposals.picker.no_program') : __('proposals.picker.empty') }}</div>
        @else
            <div class="pp-pk" data-test="program-picker"
                x-data="{
                    rows: @js($tree['results']),
                    risks: @js($tree['risks']),
                    res: {}, ind: {}, rk: {}, targets: true,
                    init() {
                        this.rows.forEach(r => { this.res[r.id] = ! r.have; r.indicators.forEach(i => this.ind[i.id] = ! i.have) });
                        this.risks.forEach(k => this.rk[k.id] = ! k.have);
                        Alpine.store('pk', { res: 0, ind: 0, tg: 0, rk: 0, copy: () => this.copy() });
                        Alpine.effect(() => { const s = Alpine.store('pk'); s.res = this.willRes(); s.ind = this.willInd(); s.tg = this.willTargets(); s.rk = this.willRisks() });
                    },
                    groups() { const out = []; this.rows.forEach(r => { if (r.depth === 0 || out.length === 0) out.push([]); out[out.length - 1].push(r) }); return out },
                    row(id) { return this.rows.find(r => r.id === id) },
                    kids(id) { return this.rows.filter(r => r.parent === id) },
                    up(id) { let r = this.row(id); while (r && r.parent) { const p = this.row(r.parent); if (! p) break; if (! p.have) this.res[p.id] = true; r = p } },
                    down(id, on) { this.kids(id).forEach(k => { if (! k.have) this.res[k.id] = on; k.indicators.forEach(i => { if (! i.have) this.ind[i.id] = on }); this.down(k.id, on) }) },
                    tickRes(r, on) { this.res[r.id] = on; r.indicators.forEach(i => { if (! i.have) this.ind[i.id] = on }); this.down(r.id, on); if (on) this.up(r.id) },
                    tickInd(r, i, on) { this.ind[i.id] = on; if (on) { if (! r.have) this.res[r.id] = true; this.up(r.id) } },
                    all(on) { this.rows.forEach(r => { if (! r.have) this.res[r.id] = on; r.indicators.forEach(i => { if (! i.have) this.ind[i.id] = on }) }); this.risks.forEach(k => { if (! k.have) this.rk[k.id] = on }); if (! on) this.targets = false },
                    willRes() { return this.rows.filter(r => ! r.have && this.res[r.id]).length },
                    willInd() { return this.rows.reduce((n, r) => n + r.indicators.filter(i => ! i.have && this.ind[i.id] && (r.have || this.res[r.id])).length, 0) },
                    fillable() { return this.rows.reduce((n, r) => n + r.indicators.filter(i => i.fillable).length, 0) },
                    willRisks() { return this.risks.filter(k => ! k.have && this.rk[k.id]).length },
                    willTargets() { return this.targets ? this.fillable() : 0 },
                    anyNew() { return this.rows.some(r => ! r.have || r.indicators.some(i => ! i.have)) || this.risks.some(k => ! k.have) },
                    picked() { return { results: this.rows.filter(r => ! r.have && this.res[r.id]).map(r => r.id), indicators: this.rows.flatMap(r => r.indicators.filter(i => (! i.have && this.ind[i.id]) || (i.fillable && this.targets)).map(i => i.id)), risks: this.risks.filter(k => ! k.have && this.rk[k.id]).map(k => k.id) } },
                    copy() { const p = this.picked(); $wire.copySelection(p.results, p.indicators, this.targets, p.risks) },
                }">
                <div class="pp-pk-tools">
                    <span class="pp-pk-sel" role="group" aria-label="{{ __('proposals.picker.select') }}">
                        <button type="button" class="pp-pk-pill" x-on:click="all(true)" x-bind:disabled="! anyNew()">{{ __('proposals.picker.tick_all_new') }}</button>
                        <button type="button" class="pp-pk-pill" x-on:click="all(false)">{{ __('proposals.picker.untick_all') }}</button>
                    </span>
                    <label class="pp-pk-opt" x-show="fillable() > 0">
                        <input type="checkbox" class="pp-pk-box" x-model="targets" data-test="picker-targets">
                        <span>{{ __('proposals.picker.copy_targets') }} <span class="pp-pk-meta">· <span x-text="fillable()"></span> {{ __('proposals.picker.empty_here') }}</span></span>
                    </label>
                </div>

                <div class="pp-pk-list">
                    <template x-for="g in groups()" :key="g[0].id">
                        <section class="pp-pk-card">
                            <template x-for="r in g" :key="r.id">
                                <div class="pp-pk-item" x-bind:class="{ 'pp-pk-dis': r.have, 'pp-pk-head': r.depth === 0 }" x-bind:style="'--d:' + r.depth">
                                    <label class="pp-pk-row">
                                        <input type="checkbox" class="pp-pk-box" x-bind:checked="r.have || res[r.id]" x-bind:disabled="r.have" x-on:change="tickRes(r, $event.target.checked)">
                                        <span class="pp-pk-txt">
                                            <span class="pp-pk-kind" x-bind:class="'pp-pk-kind-' + r.type" x-text="r.typeLabel"></span>
                                            <span class="pp-pk-st" dir="auto" x-text="r.statement"></span>
                                        </span>
                                        <span class="pp-pk-here" x-show="r.have">{{ __('proposals.picker.already_here') }}</span>
                                    </label>
                                    <template x-for="i in r.indicators" :key="i.id">
                                        <label class="pp-pk-row pp-pk-ind" x-bind:class="i.have && 'pp-pk-dis'">
                                            <input type="checkbox" class="pp-pk-box pp-pk-box-sm" x-bind:checked="i.have || ind[i.id]" x-bind:disabled="i.have" x-on:change="tickInd(r, i, $event.target.checked)">
                                            <span class="pp-pk-txt">
                                                <span dir="auto" x-text="i.name"></span>
                                                <span class="pp-pk-meta"><span x-text="i.frequency"></span><template x-if="i.target !== null"><span> · {{ __('proposals.picker.program_target') }} <b x-text="i.target"></b></span></template></span>
                                            </span>
                                            <span class="pp-pk-here" x-show="i.have" x-text="i.fillable && targets ? @js(__('proposals.picker.target_filled')) : @js(__('proposals.picker.already_here'))"></span>
                                        </label>
                                    </template>
                                </div>
                            </template>
                        </section>
                    </template>
                    <template x-if="risks.length > 0">
                        <section class="pp-pk-card" data-test="picker-risks">
                            <div class="pp-pk-item pp-pk-head" style="--d:0">
                                <span class="pp-pk-txt"><span class="pp-pk-kind">{{ __('proposals.picker.risks_title') }}</span></span>
                            </div>
                            <template x-for="k in risks" :key="k.id">
                                <div class="pp-pk-item" x-bind:class="{ 'pp-pk-dis': k.have }" style="--d:0">
                                    <label class="pp-pk-row">
                                        <input type="checkbox" class="pp-pk-box" x-bind:checked="k.have || rk[k.id]" x-bind:disabled="k.have" x-on:change="rk[k.id] = $event.target.checked">
                                        <span class="pp-pk-txt">
                                            <span class="pp-pk-st" dir="auto" x-text="k.name"></span>
                                        </span>
                                        <span class="pp-pk-here" x-show="k.have">{{ __('proposals.picker.already_here') }}</span>
                                    </label>
                                </div>
                            </template>
                        </section>
                    </template>
                </div>
                @error('picker')<p class="pp-err" role="alert">{{ $message }}</p>@enderror
            </div>
        @endif
    @endif

    @if ($showPicker && ($tree['results'] !== [] || $tree['risks'] !== []))
        <x-slot:footerMeta>
            <span class="pp-pk-sum" data-test="picker-summary">{{ __('proposals.picker.will_copy') }} <b x-text="$store.pk?.res ?? 0"></b> {{ __('proposals.picker.results') }} · <b x-text="$store.pk?.ind ?? 0"></b> {{ __('proposals.picker.indicators') }} · <b x-text="$store.pk?.tg ?? 0"></b> {{ __('proposals.picker.targets') }} · <b x-text="$store.pk?.rk ?? 0"></b> {{ __('proposals.picker.risks') }}</span>
        </x-slot:footerMeta>
        <x-slot:actions>
            <x-ui.button type="button" tone="primary" x-on:click="$store.pk?.copy()" x-bind:disabled="! $store.pk || ($store.pk.res + $store.pk.ind + $store.pk.tg + $store.pk.rk) === 0" data-test="picker-copy">{{ $copyLabel }}</x-ui.button>
        </x-slot:actions>
    @endif
</x-ui.modal>
