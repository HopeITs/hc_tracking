@props(['case'])

{{-- A bolder, filled status treatment for dense task rows/boards. Deliberately
     separate from x-ui.enum-badge (used app-wide) so this visual weight stays
     scoped to task views instead of rippling into every other page. The markup
     lives in FluxMarkup::statusPill() so list rows can emit it without a
     component render each. --}}
{{ \App\Support\FluxMarkup::statusPill($case, (string) $attributes->get('class')) }}
