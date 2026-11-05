<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\UserTodo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * My to-do: a private list on user_todos. The viewer adds, ticks, drags and
 * removes their own lines; done ones sink below the open ones until "Clear
 * done". Every action reads through auth()->user()->todos(), so another
 * user's id finds nothing. No sharing, no activity, no notifications.
 */
new class extends Component {
    use IsDeskPanel;

    public const int MaxLength = 200;

    public const int MaxItems = 100;

    /**
     * Adds a line at the end of the open ones; true once it is saved.
     */
    public function add(string $title): bool
    {
        $title = trim($title);
        $user = auth()->user();

        Validator::make(
            ['title' => $title],
            ['title' => ['required', 'string', 'max:'.self::MaxLength]],
            [
                'title.required' => __('desk-cards.todo.required'),
                'title.max' => __('desk-cards.todo.too_long', ['max' => self::MaxLength]),
            ],
        )->after(function ($validator) use ($user): void {
            if ($user->todos()->count() >= self::MaxItems) {
                $validator->errors()->add('title', __('desk-cards.todo.full'));
            }
        })->validate();

        $user->todos()->create([
            'title' => $title,
            'sort_order' => (int) $user->todos()->open()->max('sort_order') + 1,
        ]);

        unset($this->todos);

        return true;
    }

    /**
     * Ticks an open line or brings a done one back, last among the open ones.
     */
    public function toggle(int $id): void
    {
        $user = auth()->user();
        $todo = $user->todos()->whereKey($id)->firstOrFail();

        $todo->forceFill($todo->is_done
            ? ['is_done' => false, 'done_at' => null, 'sort_order' => (int) $user->todos()->open()->max('sort_order') + 1]
            : ['is_done' => true, 'done_at' => now()]
        )->save();

        unset($this->todos);
    }

    public function remove(int $id): void
    {
        auth()->user()->todos()->whereKey($id)->firstOrFail()->delete();

        unset($this->todos);
    }

    /**
     * Puts the open lines in the given order. Ids that are not the viewer's
     * open lines are ignored; open lines left out keep their order after them.
     *
     * @param  array<int, mixed>  $ids
     */
    public function reorder(array $ids): void
    {
        $open = auth()->user()->todos()->open()->ordered()->pluck('id')->all();
        $given = array_values(array_intersect(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))), $open));
        $order = array_merge($given, array_values(array_diff($open, $given)));

        DB::transaction(function () use ($order): void {
            foreach ($order as $position => $id) {
                UserTodo::query()->whereKey($id)->update(['sort_order' => $position]);
            }
        });

        unset($this->todos);
    }

    /**
     * A dragged open line dropped at a zero-based position (wire:sort).
     */
    public function move(int $id, int $position): void
    {
        $user = auth()->user();
        $user->todos()->open()->whereKey($id)->firstOrFail();

        $order = array_values(array_diff($user->todos()->open()->ordered()->pluck('id')->all(), [$id]));
        array_splice($order, max(0, min($position, count($order))), 0, [$id]);

        $this->reorder($order);
    }

    public function clearDone(): void
    {
        auth()->user()->todos()->done()->delete();

        unset($this->todos);
    }

    /**
     * @return Collection<int, UserTodo>
     */
    #[Computed]
    public function todos(): Collection
    {
        return auth()->user()->todos()->ordered()->get();
    }
};
?>

@php
    [$done, $open] = $this->todos->partition(fn (\App\Models\UserTodo $todo): bool => $todo->is_done);
    $empty = $this->todos->isEmpty();
@endphp

<x-desk.panel id="todo" class="dk-todo-panel" :filterable="false" :count="$open->count()" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total">
    <div class="dk-todo" x-data="{
        draft: '',
        save() { $wire.add(this.draft).then((saved) => { if (saved === true) { this.draft = '' } }) },
        drop(event, id) {
            const rows = [...this.$root.querySelectorAll('[data-todo-row]')];
            const next = rows[rows.indexOf(event.target) + 1] ?? rows[rows.indexOf(event.target) - 1];
            $wire.remove(id).then(() => (next?.isConnected ? next : this.$refs.input).focus({ preventScroll: true }));
        },
    }">
        <form class="dk-todo-add" x-on:submit.prevent="save()">
            <label for="dk-todo-{{ $index }}" class="sr-only">{{ __('desk-cards.todo.label') }}</label>
            <input
                id="dk-todo-{{ $index }}"
                type="text"
                class="dk-todo-input"
                x-ref="input"
                x-model="draft"
                maxlength="{{ $this::MaxLength }}"
                autocomplete="off"
                placeholder="{{ __('desk-cards.todo.placeholder') }}"
                data-todo-input
                @if ($empty) x-init="$el.focus({ preventScroll: true })" data-todo-autofocus @endif
                @error('title') aria-invalid="true" aria-describedby="dk-todo-err-{{ $index }}" @enderror
            >
            <button type="submit" class="dk-iconbtn dk-sm" aria-label="{{ __('desk-cards.todo.add') }}" title="{{ __('desk-cards.todo.add') }}" wire:loading.attr="disabled" wire:target="add"><x-desk.icon name="plus" /></button>
        </form>
        @error('title')
            <p id="dk-todo-err-{{ $index }}" class="dk-todo-err dk-bad" role="alert">{{ $message }}</p>
        @enderror

        @if ($empty)
            <p class="dk-todo-empty">{{ __('desk-cards.todo.empty') }}</p>
        @else
            <div class="dk-todo-scroll">
                <ul class="dk-todo-list" wire:sort="move" aria-label="{{ __('desk-cards.todo.open_list') }}">
                    @foreach ($open as $todo)
                        <li class="dk-todo-row" wire:key="todo-{{ $todo->id }}" wire:sort:item="{{ $todo->id }}">
                            <span class="dk-todo-grip" wire:sort:handle title="{{ __('desk-cards.todo.drag') }}" aria-hidden="true"><x-desk.icon name="grip" /></span>
                            <button type="button" class="dk-todo-check" role="checkbox" aria-checked="false" wire:click="toggle({{ $todo->id }})" x-on:keydown.delete.prevent="drop($event, {{ $todo->id }})" aria-keyshortcuts="Delete" data-todo-row>
                                <i aria-hidden="true"></i>
                                <span class="dk-todo-t">{{ $todo->title }}</span>
                            </button>
                            <button type="button" class="dk-iconbtn dk-sm dk-todo-x" wire:click="remove({{ $todo->id }})" tabindex="-1" aria-label="{{ __('desk-cards.todo.remove', ['title' => $todo->title]) }}"><x-desk.icon name="x" /></button>
                        </li>
                    @endforeach
                </ul>

                @if ($done->isNotEmpty())
                    <div class="dk-todo-done-h">
                        <span>{{ trans_choice('desk-cards.todo.done_count', $done->count(), ['count' => $done->count()]) }}</span>
                        <button type="button" class="dk-todo-clear" wire:click="clearDone">{{ __('desk-cards.todo.clear_done') }}</button>
                    </div>
                    <ul class="dk-todo-list dk-todo-done" aria-label="{{ __('desk-cards.todo.done_list') }}">
                        @foreach ($done as $todo)
                            <li class="dk-todo-row" wire:key="todo-{{ $todo->id }}">
                                <span class="dk-todo-grip" aria-hidden="true"></span>
                                <button type="button" class="dk-todo-check" role="checkbox" aria-checked="true" wire:click="toggle({{ $todo->id }})" x-on:keydown.delete.prevent="drop($event, {{ $todo->id }})" aria-keyshortcuts="Delete" data-todo-row>
                                    <i aria-hidden="true"><x-desk.icon name="check" /></i>
                                    <span class="dk-todo-t">{{ $todo->title }}</span>
                                </button>
                                <button type="button" class="dk-iconbtn dk-sm dk-todo-x" wire:click="remove({{ $todo->id }})" tabindex="-1" aria-label="{{ __('desk-cards.todo.remove', ['title' => $todo->title]) }}"><x-desk.icon name="x" /></button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </div>
</x-desk.panel>
