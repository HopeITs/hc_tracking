{{--
    The cards view of indicators/index: one _index-card per indicator on
    this page, then the pager. Included by ⚡index.blade.php, whose top PHP
    block defines $paged, $overview, $levelMarks and $levelAccents.
--}}
        {{--
            One card per indicator. The rail under the name is the point of the
            page: it puts where the indicator stands and where the calendar says
            it should stand on the same line, so "behind" is visible before any
            number is read.
        --}}
        <div class="grid gap-3 @3xl/directory:grid-cols-2 @6xl/directory:grid-cols-3" data-test="indicator-cards">
            @foreach ($paged as $row)
                @include('pages.indicators._index-card', ['row' => $row])
            @endforeach
        </div>

        @if ($paged->hasPages())
            <x-ui.panel>
                <div class="px-4 py-2">
                    <flux:pagination :paginator="$paged" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#indicator-directory-heading" />
                </div>
            </x-ui.panel>
        @endif
