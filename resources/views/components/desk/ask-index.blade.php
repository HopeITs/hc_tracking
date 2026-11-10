@php
    $askUser = auth()->user();
    $current = \App\Enums\Locale::current();
    $otherLocale = $current === \App\Enums\Locale::Arabic ? \App\Enums\Locale::English : \App\Enums\Locale::Arabic;
    $askTexts = collect([
        'instant', 'recent', 'corrected', 'badge_open', 'badge_new', 'badge_add', 'badge_there', 'badge_remove', 'badge_switch', 'badge_run', 'badge_done',
        'opens_form', 'open_tab', 'add', 'add_button', 'remove', 'switch_tab', 'close_tab', 'close_all_tabs', 'theme_dark', 'theme_light', 'language',
        'customize', 'layout', 'tour', 'desk', 'placed', 'unplaced', 'already', 'failed',
        'add_quick', 'remove_quick', 'badge_quick', 'quick_placed', 'quick_unplaced', 'quick_already',
    ])->mapWithKeys(fn (string $key): array => [$key => __('desk-workspace.ask.'.$key, ['page' => '__P__', 'tab' => '__T__', 'layout' => '__L__', 'language' => '__L__', 'query' => '__Q__'])])->all();
    $askPayload = [
        'user' => (int) $askUser->id,
        'index' => app(\App\Support\Desk\AskCommands::class)->for($askUser),
        'texts' => $askTexts,
        'otherLanguage' => $otherLocale === \App\Enums\Locale::Arabic ? 'العربية' : 'English',
        'desk' => route('dashboard'),
    ];
@endphp

{{--
    The Ask bar's instant index (App\Support\Desk\AskCommands), read once by resources/js/desk-ask.js.
    It sits outside the Ask component, so a search round trip never sends it again.
--}}
<script type="application/json" data-ask-index>@json($askPayload)</script>
