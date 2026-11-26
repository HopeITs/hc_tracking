@props([
    'partner' => null,
    'for' => 'agreement',
])

{{--
    One plain line saying who the money and paperwork go through when a proposal
    is funded via a Main Partner (PR, 2026-11-24): the agreement is signed with
    them, the transfers pass through them and the reports go only to them. The
    back donor stays the donor everywhere else. Renders nothing for a direct grant.

    `for` picks the sentence: agreement | transfers | reports | all.
--}}
@if ($partner !== null)
    <span {{ $attributes->class(['text-secondary-medium dark:text-ink-muted']) }} dir="auto" data-main-partner-note="{{ $for }}">{{ __('proposals.partner_note_'.$for, ['name' => $partner->name]) }}</span>
@endif
