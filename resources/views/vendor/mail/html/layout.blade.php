{{--
    Laravel's mail layout, published for two reasons:

    - The brand card: the header (logo) sits inside the white card with the
      body, under a red rule, and the footer sits below the card.
    - The language direction: Arabic mail (e.g. NewDeviceSignIn) renders right
      to left. The theme inlines text-align: left on paragraphs and headings,
      so the right-to-left rule needs !important to win over those styles.

    The optional $preheader is the inbox preview line. It is followed by
    invisible padding so clients don't pull body text (such as a sign-in code)
    into the preview shown on lock screens.
--}}
@php
    $direction = \App\Enums\Locale::current()->direction();
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $direction }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}

.content-cell,
.header {
padding-left: 24px !important;
padding-right: 24px !important;
}

.code-value {
font-size: 30px !important;
letter-spacing: 6px !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
@if ($direction === 'rtl')

.header,
.content-cell p,
.content-cell h1,
.content-cell h2,
.content-cell h3,
.content-cell ul,
.content-cell ol,
.content-cell blockquote {
text-align: right !important;
}

.content-cell .code-content p,
.footer .content-cell p {
text-align: center !important;
}

.panel {
border-left: 0 !important;
border-right: 3px solid #be1f24 !important;
}
@endif
</style>
{!! $head ?? '' !!}
</head>
<body dir="{{ $direction }}">
@if (filled($preheader ?? null))
<div class="preheader" style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">{{ $preheader }}{!! str_repeat('&#847;&zwnj;&nbsp;', 90) !!}</div>
@endif

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<!-- Email Card -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
