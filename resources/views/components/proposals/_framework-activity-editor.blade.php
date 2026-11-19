{{-- The in-place activity editor on the board. Each change saves on its own. --}}
<article class="pp-acard-open" id="ac-{{ $activity['id'] }}" wire:key="aedit-{{ $activity['id'] }}" data-test="activity-editor"
    x-on:keydown.escape="$wire.closeActivityEditor()">
    <label class="pp-af">{{ __('proposals.board.activity') }}
        <input type="text" wire:model="actName" wire:blur="saveActivityName" x-on:keydown.enter.prevent="$wire.saveActivityName()" maxlength="2000" data-test="activity-name">
    </label>
    @error('actName')<p class="pp-err" role="alert">{{ $message }}</p>@enderror

    <div class="pp-af">{{ __('proposals.board.when') }}
        <span class="pp-seg" role="group" aria-label="{{ __('proposals.board.when') }}">
            @foreach (\App\Actions\Results\SaveActivityTiming::MODES as $mode)
                <button type="button" @class(['pp-on' => $actMode === $mode]) wire:click="setActivityMode('{{ $mode }}')" aria-pressed="{{ $actMode === $mode ? 'true' : 'false' }}" data-test="activity-mode-{{ $mode }}">{{ __('proposals.board.mode_'.$mode) }}</button>
            @endforeach
        </span>
    </div>

    @if ($actMode === 'dates')
        <div class="pp-af2">
            <label class="pp-af">{{ __('proposals.board.start') }}<input type="date" wire:model="actStart" wire:change="saveActivityDates" data-test="activity-start"></label>
            <label class="pp-af">{{ __('proposals.board.end') }}<input type="date" wire:model="actEnd" wire:change="saveActivityDates" data-test="activity-end"></label>
        </div>
        @error('actEnd')<p class="pp-err" role="alert">{{ $message }}</p>@enderror
    @endif

    <div class="pp-af">{{ __('proposals.board.status') }}
        <span class="pp-small pp-af-plain">
            <span @class(['pp-spill', 'pp-st-'.$activity['status']])>{{ __('proposals.framework.status_'.$activity['status']) }}</span>
            {{ __('proposals.board.status_from_projects') }}
        </span>
    </div>

    <div class="pp-af">{{ __('proposals.board.what_it_counts') }}
        <div class="pp-ai-edit">
            @foreach ($actCounts as $countId => $count)
                <div class="pp-ai-row" wire:key="count-{{ $countId }}">
                    <input type="text" wire:model="actCounts.{{ $countId }}.name" wire:blur="saveCount({{ $countId }})" aria-label="{{ __('proposals.board.what_it_counts') }}">
                    <input type="number" min="0" step="any" @class(['pp-unset' => $count['target'] === '']) wire:model="actCounts.{{ $countId }}.target" wire:blur="saveCount({{ $countId }})" placeholder="{{ __('proposals.board.target') }}" aria-label="{{ __('proposals.board.target') }}">
                    <button type="button" class="pp-btn pp-btn-ghost" wire:click="deleteCount({{ $countId }})" wire:confirm="{{ __('proposals.board.delete_count_confirm') }}" aria-label="{{ __('proposals.board.remove_count') }}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button>
                </div>
                @error('actCounts.'.$countId.'.name')<p class="pp-err" role="alert">{{ $message }}</p>@enderror
            @endforeach
            <div class="pp-ai-row pp-ai-add">
                <input type="text" wire:model="newCountName" x-on:keydown.enter.prevent="$wire.addCount()" placeholder="{{ __('proposals.board.add_count') }}" aria-label="{{ __('proposals.board.add_count') }}" data-test="activity-new-count">
                <input type="number" min="0" step="any" wire:model="newCountTarget" x-on:keydown.enter.prevent="$wire.addCount()" placeholder="{{ __('proposals.board.target') }}" aria-label="{{ __('proposals.board.target') }}">
                <button type="button" class="pp-btn pp-btn-ghost" wire:click="addCount" aria-label="{{ __('proposals.board.add') }}" data-test="activity-add-count">+</button>
            </div>
            @error('newCountName')<p class="pp-err" role="alert">{{ $message }}</p>@enderror
        </div>
    </div>

    <label class="pp-af">{{ __('proposals.board.delivers_output') }}
        <select wire:change="linkActivity({{ $activity['id'] }}, $event.target.value)" @class(['pp-unset' => $loose]) data-test="activity-output">
            <option value="0" @selected($loose)>{{ __('proposals.board.not_linked') }}</option>
            @foreach ($outputIds as $outId)
                <option value="{{ $outId }}" @selected($activity['parent'] === $outId)>{{ \Illuminate\Support\Str::limit($fw['results'][$outId]['statement'], 60) }}</option>
            @endforeach
        </select>
    </label>

    <div class="pp-acard-foot">
        <button type="button" class="pp-link pp-link-mute" wire:click="confirmDelete({{ $activity['id'] }})">{{ __('proposals.board.delete_activity') }}</button>
        <button type="button" class="pp-btn pp-btn-sm pp-btn-primary" wire:click="closeActivityEditor" data-test="activity-done">{{ __('proposals.board.done') }}</button>
    </div>
</article>
