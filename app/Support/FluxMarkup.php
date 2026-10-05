<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use Illuminate\Support\HtmlString;

/**
 * Plain-markup versions of the few Flux pieces a task list row repeats dozens
 * of times (icons, ghost icon buttons, menu items, the status pill).
 *
 * A Flux icon or button costs a component render (0.2 to 0.7 ms) and one task
 * row has ~140 of them; the list took ~34 ms per row. The strings here are what
 * those components render, minus the parts these spots never use, so the page
 * looks the same for a fraction of the render time. Icon paths are copied from
 * vendor/livewire/flux/stubs/resources/views/flux/icon.
 */
final class FluxMarkup
{
    /** The classes flux:menu.item renders for a default item. */
    public const MenuItem = 'flex items-center px-2 py-1.5 w-full focus:outline-hidden select-none rounded-md text-start text-sm font-medium [&[disabled]]:opacity-50 text-zinc-800 data-active:bg-zinc-50 dark:text-white dark:data-active:bg-zinc-600 **:data-flux-menu-item-icon:text-zinc-400 dark:**:data-flux-menu-item-icon:text-white/60 [&[data-active]_[data-flux-menu-item-icon]]:text-current';

    /** x-ui.button tone="ghost" size="sm" with an icon and no label (the size is added by the caller). */
    public const GhostIconButton = 'relative inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md font-medium text-sm disabled:cursor-default disabled:opacity-50 disabled:pointer-events-none dark:disabled:opacity-50 h-8 w-8 border-0! bg-transparent! text-secondary-dark! hover:bg-secondary-soft! dark:text-ink! dark:hover:bg-white/[0.06]!';

    /**
     * icon.variant => [svg attributes, inner markup].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const Icons = [
        'flag.outline' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"', '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5"/>'],
        'flag.solid' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path fill-rule="evenodd" d="M3 2.25a.75.75 0 0 1 .75.75v.54l1.838-.46a9.75 9.75 0 0 1 6.725.738l.108.054A8.25 8.25 0 0 0 18 4.524l3.11-.732a.75.75 0 0 1 .917.81 47.784 47.784 0 0 0 .005 10.337.75.75 0 0 1-.574.812l-3.114.733a9.75 9.75 0 0 1-6.594-.77l-.108-.054a8.25 8.25 0 0 0-5.69-.625l-2.202.55V21a.75.75 0 0 1-1.5 0V3A.75.75 0 0 1 3 2.25Z" clip-rule="evenodd"/>'],
        'check.outline' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"', '<path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>'],
        'check.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path fill-rule="evenodd" d="M12.416 3.376a.75.75 0 0 1 .208 1.04l-5 7.5a.75.75 0 0 1-1.154.114l-3-3a.75.75 0 0 1 1.06-1.06l2.353 2.353 4.493-6.74a.75.75 0 0 1 1.04-.207Z" clip-rule="evenodd"/>'],
        'check-circle.outline' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"', '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>'],
        'check-circle.solid' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path fill-rule="evenodd" d="M2.25 12c0-5.385 4.365-9.75 9.75-9.75s9.75 4.365 9.75 9.75-4.365 9.75-9.75 9.75S2.25 17.385 2.25 12Zm13.36-1.814a.75.75 0 1 0-1.22-.872l-3.236 4.53L9.53 12.22a.75.75 0 0 0-1.06 1.06l2.25 2.25a.75.75 0 0 0 1.14-.094l3.75-5.25Z" clip-rule="evenodd"/>'],
        'chevron-right.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path fill-rule="evenodd" d="M6.22 4.22a.75.75 0 0 1 1.06 0l3.25 3.25a.75.75 0 0 1 0 1.06l-3.25 3.25a.75.75 0 0 1-1.06-1.06L8.94 8 6.22 5.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>'],
        'rectangle-stack.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path d="M5 3.5A1.5 1.5 0 0 1 6.5 2h3A1.5 1.5 0 0 1 11 3.5H5ZM4.5 5A1.5 1.5 0 0 0 3 6.5v.041a3.02 3.02 0 0 1 .5-.041h9c.17 0 .337.014.5.041V6.5A1.5 1.5 0 0 0 11.5 5h-7ZM12.5 8h-9A1.5 1.5 0 0 0 2 9.5v3A1.5 1.5 0 0 0 3.5 14h9a1.5 1.5 0 0 0 1.5-1.5v-3A1.5 1.5 0 0 0 12.5 8Z"/>'],
        'x-mark.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path d="M5.28 4.22a.75.75 0 0 0-1.06 1.06L6.94 8l-2.72 2.72a.75.75 0 1 0 1.06 1.06L8 9.06l2.72 2.72a.75.75 0 1 0 1.06-1.06L9.06 8l2.72-2.72a.75.75 0 0 0-1.06-1.06L8 6.94 5.28 4.22Z"/>'],
        'user-plus.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path d="M8.5 4.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0ZM10 13c.552 0 1.01-.452.9-.994a5.002 5.002 0 0 0-9.802 0c-.109.542.35.994.902.994h8ZM12.5 3.5a.75.75 0 0 1 .75.75v1h1a.75.75 0 0 1 0 1.5h-1v1a.75.75 0 0 1-1.5 0v-1h-1a.75.75 0 0 1 0-1.5h1v-1a.75.75 0 0 1 .75-.75Z"/>'],
        'plus.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path d="M8.75 3.75a.75.75 0 0 0-1.5 0v3.5h-3.5a.75.75 0 0 0 0 1.5h3.5v3.5a.75.75 0 0 0 1.5 0v-3.5h3.5a.75.75 0 0 0 0-1.5h-3.5v-3.5Z"/>'],
        'plus.mini' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path d="M10.75 4.75a.75.75 0 0 0-1.5 0v4.5h-4.5a.75.75 0 0 0 0 1.5h4.5v4.5a.75.75 0 0 0 1.5 0v-4.5h4.5a.75.75 0 0 0 0-1.5h-4.5v-4.5Z"/>'],
        'trash.mini' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path fill-rule="evenodd" d="M8.75 1A2.75 2.75 0 0 0 6 3.75v.443c-.795.077-1.584.176-2.365.298a.75.75 0 1 0 .23 1.482l.149-.022.841 10.518A2.75 2.75 0 0 0 7.596 19h4.807a2.75 2.75 0 0 0 2.742-2.53l.841-10.52.149.023a.75.75 0 0 0 .23-1.482A41.03 41.03 0 0 0 14 4.193V3.75A2.75 2.75 0 0 0 11.25 1h-2.5ZM10 4c.84 0 1.673.025 2.5.075V3.75c0-.69-.56-1.25-1.25-1.25h-2.5c-.69 0-1.25.56-1.25 1.25v.325C8.327 4.025 9.16 4 10 4ZM8.58 7.72a.75.75 0 0 0-1.5.06l.3 7.5a.75.75 0 1 0 1.5-.06l-.3-7.5Zm4.34.06a.75.75 0 1 0-1.5-.06l-.3 7.5a.75.75 0 1 0 1.5.06l.3-7.5Z" clip-rule="evenodd"/>'],
        'pencil-square.mini' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path d="m5.433 13.917 1.262-3.155A4 4 0 0 1 7.58 9.42l6.92-6.918a2.121 2.121 0 0 1 3 3l-6.92 6.918c-.383.383-.84.685-1.343.886l-3.154 1.262a.5.5 0 0 1-.65-.65Z"/><path d="M3.5 5.75c0-.69.56-1.25 1.25-1.25H10A.75.75 0 0 0 10 3H4.75A2.75 2.75 0 0 0 2 5.75v9.5A2.75 2.75 0 0 0 4.75 18h9.5A2.75 2.75 0 0 0 17 15.25V10a.75.75 0 0 0-1.5 0v5.25c0 .69-.56 1.25-1.25 1.25h-9.5c-.69 0-1.25-.56-1.25-1.25v-9.5Z"/>'],
        'stop.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<rect width="10" height="10" x="3" y="3" rx="1.5"/>'],
        'play.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path d="M3 3.732a1.5 1.5 0 0 1 2.305-1.265l6.706 4.267a1.5 1.5 0 0 1 0 2.531l-6.706 4.268A1.5 1.5 0 0 1 3 12.267V3.732Z"/>'],
        'eye.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path d="M8 9.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/><path fill-rule="evenodd" d="M1.38 8.28a.87.87 0 0 1 0-.566 7.003 7.003 0 0 1 13.238.006.87.87 0 0 1 0 .566A7.003 7.003 0 0 1 1.379 8.28ZM11 8a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" clip-rule="evenodd"/>'],
        'calendar-days.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path d="M5.75 7.5a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5ZM5 10.25a.75.75 0 1 1 1.5 0 .75.75 0 0 1-1.5 0ZM10.25 7.5a.75.75 0 1 0 0 1.5.75.75 0 0 0 0-1.5ZM7.25 8.25a.75.75 0 1 1 1.5 0 .75.75 0 0 1-1.5 0ZM8 9.5A.75.75 0 1 0 8 11a.75.75 0 0 0 0-1.5Z"/><path fill-rule="evenodd" d="M4.75 1a.75.75 0 0 0-.75.75V3a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2V1.75a.75.75 0 0 0-1.5 0V3h-5V1.75A.75.75 0 0 0 4.75 1ZM3.5 7a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v4.5a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1V7Z" clip-rule="evenodd"/>'],
        'exclamation-triangle.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path fill-rule="evenodd" d="M6.701 2.25c.577-1 2.02-1 2.598 0l5.196 9a1.5 1.5 0 0 1-1.299 2.25H2.804a1.5 1.5 0 0 1-1.3-2.25l5.197-9ZM8 4a.75.75 0 0 1 .75.75v3a.75.75 0 1 1-1.5 0v-3A.75.75 0 0 1 8 4Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>'],
        'stop-circle.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path fill-rule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14ZM6.5 5.5a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1h-3Z" clip-rule="evenodd"/>'],
        'play-circle.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path fill-rule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14Zm-.847-9.766A.75.75 0 0 0 6 5.866v4.268a.75.75 0 0 0 1.153.633l3.353-2.134a.75.75 0 0 0 0-1.266L7.153 5.234Z" clip-rule="evenodd"/>'],
        'check-circle.micro' => ['data-flux-icon xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" data-slot="icon"', '<path fill-rule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14Zm3.844-8.791a.75.75 0 0 0-1.188-.918l-3.7 4.79-1.649-1.833a.75.75 0 1 0-1.114 1.004l2.25 2.5a.75.75 0 0 0 1.15-.043l4.25-5.5Z" clip-rule="evenodd"/>'],
    ];

    /**
     * An inline icon. `$class` is added after Flux's own defaults, so a size
     * such as `size-4` wins over the variant's default size the same way it
     * does with flux:icon.
     */
    public static function icon(string $name, string $variant = 'outline', string $class = ''): HtmlString
    {
        [$attributes, $inner] = self::Icons[$name.'.'.$variant] ?? throw new \InvalidArgumentException("No inline icon [{$name}.{$variant}].");
        $size = match ($variant) {
            'mini' => 'size-5',
            'micro' => 'size-4',
            default => 'size-6',
        };

        return new HtmlString('<svg class="shrink-0 [:where(&)]:'.$size.($class === '' ? '' : ' '.e($class)).'" '.$attributes.'>'.$inner.'</svg>');
    }

    /**
     * The filled task status chip (what x-ui.status-pill renders).
     */
    public static function statusPill(TaskStatus $case, string $extraClass = ''): HtmlString
    {
        [$classes, $icon] = match ($case) {
            TaskStatus::Todo => ['bg-white text-secondary-dark ring-1 ring-inset ring-secondary-soft dark:bg-surface-1 dark:text-ink dark:ring-line', 'stop'],
            TaskStatus::InProgress => ['bg-status-active dark:bg-status-active-soft dark:text-status-active text-white', 'play'],
            TaskStatus::UnderReview => ['bg-status-warn dark:bg-status-warn-soft dark:text-status-warn text-white', 'eye'],
            TaskStatus::Done => ['bg-status-good dark:bg-status-good-soft dark:text-status-good text-white', 'check'],
        };

        return new HtmlString(
            '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold tracking-wide uppercase whitespace-nowrap '.$classes.($extraClass === '' ? '' : ' '.e($extraClass)).'">'
            .self::icon($icon, 'micro', 'size-3 shrink-0')
            .'<span class="truncate">'.e($case->label()).'</span></span>'
        );
    }

    /**
     * The priority flag: solid and tinted for medium and high, an outline for low.
     */
    public static function priorityFlag(TaskPriority $case, string $size = 'size-4'): HtmlString
    {
        $tint = match ($case) {
            TaskPriority::High => 'text-primary dark:text-brand-text',
            TaskPriority::Medium => 'text-status-warn',
            TaskPriority::Low => 'text-secondary-light',
        };

        return self::icon('flag', $case === TaskPriority::Low ? 'outline' : 'solid', $size.' '.$tint);
    }

    /**
     * A ghost icon-only button (x-ui.button tone="ghost" size="sm" icon="..."), with `$attributes`
     * written out by the caller as ready-made HTML.
     */
    public static function ghostIconButton(string $icon, string $label, string $attributes = '', string $sizeClass = 'size-8!'): HtmlString
    {
        return new HtmlString('<button type="button" class="'.self::GhostIconButton.' '.$sizeClass.'" aria-label="'.e($label).'" '.$attributes.'>'.self::icon($icon, 'mini').'</button>');
    }
}
