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
 * @property-read list<array{panel: string, size: string}> $layout
 * @property-read array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null}> $library
 */
trait ArrangesPanels
{
    /**
     * Save a new arrangement; null puts the host back on its default.
     *
     * @param  list<array{panel: string, size: string}>|null  $layout
     */
    abstract protected function storeLayout(?array $layout): void;

    public function resizePanel(string $panel, string $size): void
    {
        if (! isset(Panels::Sizes[$size])) {
            return;
        }

        $this->storeLayout(array_map(fn (array $entry): array => $entry['panel'] === $panel ? [...$entry, 'size' => $size] : $entry, $this->layout));
    }

    public function movePanel(string $panel, int $position): void
    {
        $layout = $this->layout;
        $from = array_search($panel, array_column($layout, 'panel'), true);

        if ($from === false) {
            return;
        }

        [$entry] = array_splice($layout, $from, 1);
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

        $this->storeLayout([...$this->layout, ['panel' => $panel, 'size' => $library[$panel]['size']]]);
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

            $kept[$panel] = ['panel' => $panel, 'size' => is_string($size) && isset(Panels::Sizes[$size]) ? $size : $library[$panel]['size']];
        }

        $this->storeLayout(array_values($kept));
    }

    public function resetLayout(): void
    {
        $this->storeLayout(null);
    }
}
