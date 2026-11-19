{{--
    "Start from program framework": the program's framework as a checklist.
    Ticking runs in the browser (instant); copySelection() checks it all again.
    Already here = greyed and locked; ticking a child ticks its parents,
    unticking a parent unticks its new children.
--}}
@php
    $tree = $showPicker ? $this->pickerTree : ['results' => [], 'programs' => 0];
@endphp

<x-ui.modal wire:model="showPicker" size="lg" :title="__('proposals.picker.title')">
    @if ($showPicker)
        @if ($tree['results'] === [])
            <div class="pp-empty">{{ $tree['programs'] === 0 ? __('proposals.picker.no_program') : __('proposals.picker.empty') }}</div>
        @else
            <div class="pp-pk" data-test="program-picker"
                x-data="{
                    rows: @js($tree['results']),
                    res: {}, ind: {}, targets: true,
                    init() {
                        this.rows.forEach(r => { this.res[r.id] = ! r.have; r.indicators.forEach(i => this.ind[i.id] = ! i.have) });
                    },
                    row(id) { return this.rows.find(r => r.id === id) },
                    kids(id) { return this.rows.filter(r => r.parent === id) },
                    up(id) { let r = this.row(id); while (r && r.parent) { const p = this.row(r.parent); if (! p) break; if (! p.have) this.res[p.id] = true; r = p } },
                    down(id, on) { this.kids(id).forEach(k => { if (! k.have) this.res[k.id] = on; k.indicators.forEach(i => { if (! i.have) this.ind[i.id] = on }); this.down(k.id, on) }) },
                    tickRes(r, on) { this.res[r.id] = on; r.indicators.forEach(i => { if (! i.have) this.ind[i.id] = on }); this.down(r.id, on); if (on) this.up(r.id) },
                    tickInd(r, i, on) { this.ind[i.id] = on; if (on) { if (! r.have) this.res[r.id] = true; this.up(r.id) } },
                    all(on) { this.rows.forEach(r => { if (! r.have) this.res[r.id] = on; r.indicators.forEach(i => { if (! i.have) this.ind[i.id] = on }) }); if (! on) this.targets = false },
                    willRes() { return this.rows.filter(r => ! r.have && this.res[r.id]).length },
                    willInd() { return this.rows.reduce((n, r) => n + r.indicators.filter(i => ! i.have && this.ind[i.id] && (r.have || this.res[r.id])).length, 0) },
                    fillable() { return this.rows.reduce((n, r) => n + r.indicators.filter(i => i.fillable).length, 0) },
                    willTargets() { return this.targets ? this.fillable() : 0 },
                    anyNew() { return this.rows.some(r => ! r.have || r.indicators.some(i => ! i.have)) },
                    picked() { return { results: this.rows.filter(r => ! r.have && this.res[r.id]).map(r => r.id), indicators: this.rows.flatMap(r => r.indicators.filter(i => (! i.have && this.ind[i.id]) || (i.fillable && this.targets)).map(i => i.id)) } },
                    copy() { const p = this.picked(); $wire.copySelection(p.results, p.indicators, this.targets) },
                }">
                <p class="pp-small">{{ __('proposals.picker.intro') }}</p>
                <div class="pp-pk-tools">
                    <span class="pp-pk-btns">
                        <button type="button" class="pp-btn pp-btn-sm" x-on:click="all(true)" x-bind:disabled="! anyNew()">{{ __('proposals.picker.tick_all_new') }}</button>
                        <button type="button" class="pp-btn pp-btn-sm" x-on:click="all(false)">{{ __('proposals.picker.untick_all') }}</button>
                    </span>
                    <label class="pp-toggle"><input type="checkbox" x-model="targets" data-test="picker-targets"> <span>{{ __('proposals.picker.copy_targets') }} (<span x-text="fillable()"></span> {{ __('proposals.picker.empty_here') }})</span></label>
                </div>
                <div class="pp-pk-list">
                    <template x-for="r in rows" :key="r.id">
                        <div>
                            <label class="pp-pk-row pp-pk-res" x-bind:class="r.have && 'pp-pk-dis'" x-bind:style="'padding-inline-start:' + (12 + r.depth * 20) + 'px'">
                                <input type="checkbox" x-bind:checked="r.have || res[r.id]" x-bind:disabled="r.have" x-on:change="tickRes(r, $event.target.checked)">
                                <span class="pp-lvl" x-bind:class="'pp-lvl-' + r.type" x-text="r.typeLabel"></span>
                                <span dir="auto" x-text="r.statement"></span>
                                <span class="pp-pk-tag" x-bind:class="! r.have && 'pp-pk-new'" x-text="r.have ? @js(__('proposals.picker.already_here')) : @js(__('proposals.picker.new'))"></span>
                            </label>
                            <template x-for="i in r.indicators" :key="i.id">
                                <label class="pp-pk-row" x-bind:class="i.have && 'pp-pk-dis'" x-bind:style="'padding-inline-start:' + (44 + r.depth * 20) + 'px'">
                                    <input type="checkbox" x-bind:checked="i.have || ind[i.id]" x-bind:disabled="i.have" x-on:change="tickInd(r, i, $event.target.checked)">
                                    <span class="pp-small">•</span>
                                    <span dir="auto"><span x-text="i.name"></span> <span class="pp-small">· <span x-text="i.frequency"></span><template x-if="i.target !== null"><span> · {{ __('proposals.picker.program_target') }} <b x-text="i.target"></b></span></template></span></span>
                                    <span class="pp-pk-tag" x-bind:class="(! i.have || (i.fillable && targets)) && 'pp-pk-new'" x-text="! i.have ? @js(__('proposals.picker.new')) : (i.fillable && targets ? @js(__('proposals.picker.target_filled')) : @js(__('proposals.picker.already_here')))"></span>
                                </label>
                            </template>
                        </div>
                    </template>
                </div>
                <div class="pp-pk-sum" data-test="picker-summary">{{ __('proposals.picker.will_copy') }} <span x-text="willRes()"></span> {{ __('proposals.picker.results') }}, <span x-text="willInd()"></span> {{ __('proposals.picker.indicators') }}, <span x-text="willTargets()"></span> {{ __('proposals.picker.targets') }}.</div>
                @error('picker')<p class="pp-err" role="alert">{{ $message }}</p>@enderror
                <div class="pp-pk-acts">
                    <button type="button" class="pp-btn pp-btn-primary" x-on:click="copy()" x-bind:disabled="willRes() + willInd() + willTargets() === 0" data-test="picker-copy">{{ __('proposals.picker.copy') }}</button>
                </div>
            </div>
        @endif
    @endif
</x-ui.modal>
