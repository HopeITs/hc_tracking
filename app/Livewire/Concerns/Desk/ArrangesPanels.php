<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use App\Support\Desk\Panels;

/**
 * Add, remove, resize and reorder a set of Desk panels. Shared by the Desk
 * (pages::desk.index, saved on users.desk_layout) and the top bar's Panels
 * popup (desk.page-panels, saved per page on users.page_panels). The panels'
 * own menus call these through $wire.$parent, and desk-grid.js calls
 * movePanel() after a drag, so both hosts answer to the same names.
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
     * The free cell grid's resize (desk-grid.js): an exact width in columns
     * and height in row units, clamped to the grid's limits. col pins the
     * card's start column, so pulling its start edge keeps its far edge still.
     */
    public function resizePanelTo(string $panel, int $cols, int $rows, ?int $col = null): void
    {
        $panels = app(Panels::class);

        $this->storeLayout(array_map(fn (array $entry): array => $entry['panel'] === $panel ? $panels->sized($panel, $entry['size'], $cols, $rows, $col) : $entry, $this->layout));
    }

    public function movePanel(string $panel, int $position): void
    {
        $layout = $this->layout;
        $from = array_search($panel, array_column($layout, 'panel'), true);

        if ($from === false) {
            return;
        }

        [$entry] = array_splice($layout, $from, 1);
        unset($entry['col']);
        array_splice($layout, max(0, min($position, count($layout))), 0, [$entry]);
        $this->storeLayout(array_values($layout));
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
            $kept[$panel] = app(Panels::class)->sized($panel, $size, $entry['cols'] ?? null, $entry['rows'] ?? null, $entry['col'] ?? null);
        }

        $this->storeLayout(array_values($kept));
    }

    public function resetLayout(): void
    {
        $this->storeLayout(null);
    }
}
