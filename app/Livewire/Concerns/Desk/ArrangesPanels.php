<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use App\Support\Desk\Panels;

/**
 * Add, remove, resize and reorder a set of Desk panels. Shared by the Desk
 * (pages::desk.index, saved on users.desk_layout) and the top bar's Panels
 * popup (desk.page-panels, saved per page on users.page_panels). The panels'
 * own menus call these through $wire.$parent, and desk-grid.js calls
 * arrangePanels() after a drag or resize, so both hosts answer to the same names.
 *
 * @property-read list<array{panel: string, size: string, cols: int, rows: int}> $layout
 * @property-read array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null}> $library
 */
trait ArrangesPanels
{
    /**
     * Save a new arrangement; null puts the host back on its default.
     *
     * @param  list<array{panel: string, size: string, cols: int, rows: int}>|null  $layout
     */
    abstract protected function storeLayout(?array $layout): void;

    public function resizePanel(string $panel, string $size): void
    {
        if (! isset(Panels::Sizes[$size])) {
            return;
        }

        $panels = app(Panels::class);

        $this->storeLayout(array_map(fn (array $entry): array => $entry['panel'] === $panel ? $panels->sized($panel, $size, Panels::Sizes[$size], $entry['rows'] ?? null) : $entry, $this->layout));
    }

    /**
     * A drag or an edge pulled on the Desk (desk-grid.js): the whole
     * arrangement it previewed, in order, as [panel, cols, rows]. Ignored
     * unless it holds exactly the panels already placed; it is read back
     * through Panels::flush(), so rows stay full whatever arrives.
     *
     * @param  array<mixed>  $entries
     */
    public function arrangePanels(array $entries): void
    {
        $arranged = $this->arranged($entries, array_column($this->layout, 'size', 'panel'));

        if ($arranged !== null) {
            $this->storeLayout($arranged);
        }
    }

    /**
     * Something new dropped on the Desk (a sidebar page dragged in): the
     * whole arrangement the drop previewed (desk-rows.js placedNew), in
     * order, as [panel, cols, rows]. Saved only when it holds the panels
     * already placed plus exactly this one, which must be available and not
     * placed yet; it is read back flush like any arrangement.
     *
     * @param  array<mixed>  $entries
     */
    public function dropItem(string $panel, array $entries): void
    {
        $library = $this->library;
        $placed = array_column($this->layout, 'size', 'panel');

        if (! isset($library[$panel]) || isset($placed[$panel])) {
            return;
        }

        $arranged = $this->arranged($entries, [...$placed, $panel => $library[$panel]['size']]);

        if ($arranged !== null) {
            $this->storeLayout(app(Panels::class)->flush($arranged));
            $this->dispatch('dk-item-placed', panel: $panel, title: $library[$panel]['title']);
        }
    }

    /**
     * Put a page on the Desk by its route (the Ask bar's "add … to desk", a
     * keyboard drop): whatever stands for it (Panels::itemForRoute) lands at
     * $position, the end when null. Returns the item id, or null when the
     * page cannot be added or is already there.
     */
    public function addRoute(string $route, ?int $position = null): ?string
    {
        $id = app(Panels::class)->itemForRoute(auth()->user(), $route);

        return $id !== null && $this->addItem($id, $position) ? $id : null;
    }

    /**
     * Put one item (panel, page card or button) on the Desk at $position,
     * the end when null. False when it is not available or already placed.
     */
    public function addItem(string $panel, ?int $position = null): bool
    {
        $library = $this->library;

        if (! isset($library[$panel]) || in_array($panel, array_column($this->layout, 'panel'), true)) {
            return false;
        }

        $this->storeLayout(app(Panels::class)->withItem(auth()->user(), $this->layout, $panel, $position));
        $this->dispatch('dk-item-placed', panel: $panel, title: $library[$panel]['title']);

        return true;
    }

    /**
     * The entries as a layout, or null unless they hold exactly the given
     * panels (panel => size), each once.
     *
     * @param  array<mixed>  $entries
     * @param  array<string, string>  $expected
     * @return list<array{panel: string, size: string, cols: int, rows: int}>|null
     */
    private function arranged(array $entries, array $expected): ?array
    {
        $panels = app(Panels::class);
        $arranged = [];

        foreach ($entries as $entry) {
            $panel = is_array($entry) ? ($entry['panel'] ?? null) : null;

            if (! is_string($panel) || ! isset($expected[$panel]) || isset($arranged[$panel])) {
                return null;
            }

            $arranged[$panel] = $panels->sized($panel, $expected[$panel], $entry['cols'] ?? null, $entry['rows'] ?? null);
        }

        return count($arranged) === count($expected) ? array_values($arranged) : null;
    }

    /**
     * The panel menu's Move to top / earlier / later: the cards trade
     * places, never shapes. Every slot keeps its size, so a move cannot
     * open a hole in the Desk.
     */
    public function movePanel(string $panel, int $position): void
    {
        $layout = $this->layout;
        $order = array_column($layout, 'panel');
        $from = array_search($panel, $order, true);

        if ($from === false) {
            return;
        }

        array_splice($order, $from, 1);
        array_splice($order, max(0, min($position, count($order))), 0, [$panel]);

        $panels = app(Panels::class);

        $this->storeLayout(array_map(fn (array $slot, string $id): array => $panels->sized($id, $slot['size'], $slot['cols'], $slot['rows']), $layout, $order));
    }

    public function removePanel(string $panel): void
    {
        $this->storeLayout(array_values(array_filter($this->layout, fn (array $entry): bool => $entry['panel'] !== $panel)));
    }

    public function addPanel(string $panel): void
    {
        $library = $this->library;

        if (! isset($library[$panel]) || in_array($panel, array_column($this->layout, 'panel'), true)) {
            return;
        }

        $this->storeLayout([...$this->layout, app(Panels::class)->sized($panel, $library[$panel]['size'])]);
    }

    /**
     * The Undo toast after a removal: put the panel back where it was, at
     * the size it had. Checked like addPanel, so only an available panel
     * that is not already on the desk comes back.
     */
    public function undoRemove(string $panel, int $position, string $size, ?int $cols = null, ?int $rows = null): void
    {
        $library = $this->library;

        if (! isset($library[$panel]) || in_array($panel, array_column($this->layout, 'panel'), true)) {
            return;
        }

        $layout = $this->layout;
        array_splice($layout, max(0, min($position, count($layout))), 0, [app(Panels::class)->sized($panel, isset(Panels::Sizes[$size]) ? $size : $library[$panel]['size'], $cols, $rows)]);
        $this->storeLayout(array_values($layout));
    }

    /**
     * "Done" in the Customize sheet: the whole edited arrangement in one
     * call, or null to go back to the default. Unknown, unavailable and
     * repeated panels and unknown sizes are dropped.
     *
     * @param  array<mixed>|null  $layout
     */
    public function saveLayout(?array $layout): void
    {
        if ($layout === null) {
            $this->storeLayout(null);

            return;
        }

        $library = $this->library;
        $kept = [];

        foreach ($layout as $entry) {
            $panel = is_array($entry) ? ($entry['panel'] ?? null) : null;
            $size = is_array($entry) ? ($entry['size'] ?? null) : null;

            if (! is_string($panel) || ! isset($library[$panel]) || isset($kept[$panel])) {
                continue;
            }

            $size = is_string($size) && isset(Panels::Sizes[$size]) ? $size : $library[$panel]['size'];
            $kept[$panel] = app(Panels::class)->sized($panel, $size, $entry['cols'] ?? null, $entry['rows'] ?? null);
        }

        $this->storeLayout(array_values($kept));
    }

    public function resetLayout(): void
    {
        $this->storeLayout(null);
    }
}
