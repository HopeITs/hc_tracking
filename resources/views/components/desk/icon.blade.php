@props(['name'])

{{--
    One Desk icon (App\Support\Desk\Icons), drawn from the page's sprite (x-desk.icon-sprite,
    in layouts/app/sidebar) so a page with hundreds of icons carries each drawing once.
    Unknown names render nothing rather than a broken glyph.
--}}
@if (array_key_exists($name, \App\Support\Desk\Icons::Paths))
    <svg {{ $attributes->class('dk-i') }} viewBox="0 0 24 24" aria-hidden="true"><use href="#dk-sym-{{ $name }}"></use></svg>
@endif
