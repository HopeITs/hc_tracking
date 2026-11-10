<?php

namespace App\Support\Desk;

use App\Models\User;

/**
 * The Desk's Quick access list: pages and "New …" actions a person dragged out
 * of the Ask bar onto the Quick access button beside the greeting, kept on
 * users.desk_quick_access in their order.
 *
 * Entries are the Ask bar's own row keys (`go:<route>` for a page, `new:<key>`
 * for an action) and are resolved against AskCommands, so the list offers
 * exactly what the Ask bar offers this viewer: a page they may no longer open
 * drops out of the list without being erased from what they saved.
 *
 * It is also where the Desk's buttons live: the Desk itself holds only panels
 * and page cards, so a button (`a-…` / `g-…`, Shortcuts) put on the Desk from
 * anywhere is kept here instead, by the same row key (addItem / removeItem).
 */
class QuickAccess
{
    /** The most entries the list keeps; a new one past it is refused. */
    public const int MaxEntries = 20;

    public function __construct(
        private readonly AskCommands $commands,
        private readonly Shortcuts $shortcuts,
    ) {}

    /**
     * The viewer's list, in their order, each entry ready to draw.
     *
     * @return list<array{key: string, kind: 'page'|'action', label: string, icon: string, href: string}>
     */
    public function for(User $user): array
    {
        $offered = $this->offeredTo($user);
        $entries = [];

        foreach ($this->savedKeys($user) as $key) {
            if (isset($offered[$key])) {
                $entries[] = $offered[$key];
            }
        }

        return $entries;
    }

    /**
     * Put one page or action at the end of the list. Unknown keys, keys
     * already there and a full list change nothing.
     */
    public function add(User $user, string $key): bool
    {
        $saved = $this->savedKeys($user);
        $visible = array_column($this->for($user), 'key');

        if (! isset($this->offeredTo($user)[$key]) || in_array($key, $saved, true) || count($visible) >= self::MaxEntries) {
            return false;
        }

        $this->store($user, [...$saved, $key]);

        return true;
    }

    public function remove(User $user, string $key): void
    {
        $saved = $this->savedKeys($user);

        if (in_array($key, $saved, true)) {
            $this->store($user, array_values(array_filter($saved, fn (string $each): bool => $each !== $key)));
        }
    }

    /**
     * The row key that stands for a Desk button (Shortcuts item id): `new:<key>`
     * for an action, `go:<route>` for a page; null when it is no button the
     * viewer may use.
     */
    public function keyForItem(User $user, string $id): ?string
    {
        $button = $this->shortcuts->for($user)[$id] ?? null;

        return $button === null ? null : self::keyOf($button);
    }

    /**
     * Keep a Desk button here. True when it is in the list afterwards (just
     * added or already there), false when it is no button the viewer may use
     * or the list is full.
     */
    public function addItem(User $user, string $id): bool
    {
        $key = $this->keyForItem($user, $id);

        if ($key === null) {
            return false;
        }

        return in_array($key, $this->savedKeys($user), true) || $this->add($user, $key);
    }

    public function removeItem(User $user, string $id): void
    {
        $key = $this->keyForItem($user, $id);

        if ($key !== null) {
            $this->remove($user, $key);
        }
    }

    /**
     * The Desk button ids (Shortcuts) whose row key is kept here, for the
     * Ask bar's "already there" and the Customize sheet's Buttons tab.
     *
     * @return list<string>
     */
    public function itemIds(User $user): array
    {
        $saved = array_flip($this->savedKeys($user));
        $ids = [];

        foreach ($this->shortcuts->for($user) as $id => $button) {
            if (isset($saved[self::keyOf($button)])) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * The list in a new order. Only keys already saved move; anything the
     * new order leaves out (a hidden entry, a stale browser) keeps its place
     * after them.
     *
     * @param  array<mixed>  $order
     */
    public function reorder(User $user, array $order): void
    {
        $saved = $this->savedKeys($user);
        $moved = array_values(array_unique(array_filter($order, fn (mixed $key): bool => is_string($key) && in_array($key, $saved, true))));

        $this->store($user, [...$moved, ...array_values(array_diff($saved, $moved))]);
    }

    /**
     * @param  array{kind: 'action'|'page', key: string, route: string}  $button
     */
    private static function keyOf(array $button): string
    {
        return $button['kind'] === 'action' ? 'new:'.$button['key'] : 'go:'.$button['route'];
    }

    /**
     * Every page and action the Ask bar offers this viewer, by row key.
     *
     * @return array<string, array{key: string, kind: 'page'|'action', label: string, icon: string, href: string}>
     */
    private function offeredTo(User $user): array
    {
        $index = $this->commands->for($user);
        $offered = [];

        foreach ($index['pages'] as $page) {
            $key = 'go:'.$page['route'];
            $offered[$key] = ['key' => $key, 'kind' => 'page', 'label' => $page['label'], 'icon' => $page['icon'], 'href' => $page['href']];
        }

        foreach ($index['actions'] as $action) {
            $key = 'new:'.$action['key'];
            $offered[$key] = ['key' => $key, 'kind' => 'action', 'label' => $action['label'], 'icon' => 'plus', 'href' => $action['href']];
        }

        return $offered;
    }

    /**
     * @return list<string>
     */
    private function savedKeys(User $user): array
    {
        return array_values(array_unique(array_filter($user->desk_quick_access ?? [], is_string(...))));
    }

    /**
     * @param  list<string>  $keys
     */
    private function store(User $user, array $keys): void
    {
        $user->forceFill(['desk_quick_access' => $keys === [] ? null : $keys])->saveQuietly();
    }
}
