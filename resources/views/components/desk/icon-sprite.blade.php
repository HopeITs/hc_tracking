{{-- The Desk icon set as symbols, for icons drawn in the browser (the Desk tab strip): <svg class="dk-i"><use href="#dk-sym-folder"/></svg>. --}}
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    @foreach (\App\Support\Desk\Icons::Paths as $name => $path)
        <symbol id="dk-sym-{{ $name }}" viewBox="0 0 24 24">{!! $path !!}</symbol>
    @endforeach
</svg>
