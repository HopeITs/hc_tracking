@props([
    'caption' => null,
])
{{ trim($slot) }}
@if (filled($caption))

{{ $caption }}
@endif
