@props(['caption' => null, 'size' => 'lg'])

{{--
    A Funding register: one real table, every figure in its own column. The
    outer element is a size container; while the table fits, nothing scrolls
    sideways and the heading row sticks under the app bar; below that the
    table scrolls inside itself with its first column pinned; on a phone each
    row becomes a block of labelled pairs (td data-label). See app.css.
--}}
<div {{ $attributes->class(['register', 'register-'.$size]) }} data-register data-data-table>
    <div class="register-scroll">
        <table>
            @if (filled($caption))
                <caption class="sr-only">{{ $caption }}</caption>
            @endif
            <thead>
                <tr>{{ $head }}</tr>
            </thead>
            <tbody>
                {{ $slot }}
            </tbody>
            @isset($foot)
                <tfoot>{{ $foot }}</tfoot>
            @endisset
        </table>
    </div>
</div>
