@props([
    'caption' => null,
])

<div data-data-table {{ $attributes->class('@container overflow-x-auto rounded-xl border border-board-line bg-board shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] dark:shadow-none') }}>
    <table class="w-full border-collapse text-start text-sm">
        @if ($caption)
            <caption class="sr-only">{{ $caption }}</caption>
        @endif
        <thead class="@max-3xl:hidden bg-board-head">
            <tr class="[&>th]:type-label [&>th]:px-3.5 [&>th]:py-2.5 [&>th]:text-start [&>th]:text-secondary-medium [&>th]:dark:text-secondary-soft">
                {{ $head }}
            </tr>
        </thead>
        <tbody class="divide-y divide-board-line [&>tr]:@max-3xl:flex [&>tr]:@max-3xl:flex-col [&>tr]:@max-3xl:gap-1.5 [&>tr]:@max-3xl:p-3 [&>tr:hover]:bg-ground [&>tr]:transition-colors">
            {{ $slot }}
        </tbody>
    </table>
</div>
