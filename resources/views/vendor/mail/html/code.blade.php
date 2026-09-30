{{--
    A one-time code, shown large in a monospaced face so it is easy to read
    and to copy exactly. Always left to right, even in Arabic mail.
--}}
@props([
    'caption' => null,
])
<table class="code" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="code-content" align="center">
<p class="code-value" dir="ltr">{{ trim($slot) }}</p>
@if (filled($caption))
<p class="code-caption">{{ $caption }}</p>
@endif
</td>
</tr>
</table>
