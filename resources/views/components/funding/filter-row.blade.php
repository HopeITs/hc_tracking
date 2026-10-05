@props(['label', 'on' => false, 'pairs' => [], 'watch' => [], 'models' => [], 'defaults' => [], 'keep' => false])

@php
    /*
     * One section of the filters dialog. Sections hang off the dialog's thread
     * (.filter-thread); a section whose filter is set fills its dot, so the
     * thread reads as a map of what is on. The dialog edits a draft
     * (resources/js/finance-filters.js), so the dot follows the draft: on
     * when one of `pairs` ([property, value]) is chosen or one of `watch` is
     * filled. `on` is the server's answer, drawn before Alpine starts.
     * Flux puts w-full on an input's wrapper; here inputs sit inline at a
     * compact width instead.
     */
    $dotExpression = collect([
        $pairs === [] ? null : 'onAny('.Illuminate\Support\Js::encode($pairs).')',
        $watch === [] ? null : 'filled('.Illuminate\Support\Js::encode($watch).')',
    ])->filter()->implode(' || ');
@endphp

<section
    {{ $attributes->class('filter-section') }}
    @if ($on) data-on @endif
    @if ($dotExpression !== '') x-bind:data-on="({{ $dotExpression }}) || null" @endif
    @if ($models !== []) data-filter-models="{{ json_encode(array_values($models)) }}" @endif
    @if ($defaults !== []) data-filter-defaults="{{ json_encode($defaults) }}" @endif
    @if ($keep) data-filter-keep @endif
    role="group"
    aria-label="{{ $label }}"
>
    <h3 class="filter-section-label">{{ $label }}</h3>

    <div class="flex flex-wrap items-center gap-1.5 [&>[data-flux-input]]:w-32 [&>[data-flux-input]:has(input[type=date])]:w-40">
        {{ $slot }}
    </div>
</section>
