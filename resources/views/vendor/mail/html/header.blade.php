{{--
    The brand header inside the email card: the HOPE CENTER Tracking wordmark
    as a PNG (mail clients don't render SVG). The alt text is styled by the
    theme, so the name still reads as a heading when images are blocked.
--}}
@props([
    'url',
    'logo',
])
<tr>
<td class="header">
<a href="{{ $url }}" target="_blank" rel="noopener" style="display: inline-block;">
<img src="{{ $logo }}" class="logo" width="160" height="37" alt="{{ trim(strip_tags($slot)) }}">
</a>
</td>
</tr>
