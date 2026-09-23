{{--
    The banner on top of every desktop notification (728×364; Windows always
    shows it at 2:1 above the toast). Rendered to PNG by
    `php artisan notifications:banners`; never served as a page. Asset paths
    are file:// URLs so headless Chrome can read them straight from public/.
--}}
<!doctype html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<style>
    html, body { margin: 0; width: 728px; height: 364px; overflow: hidden; font-family: 'Segoe UI', Tahoma, system-ui, sans-serif; }
    .card { position: relative; width: 728px; height: 364px; box-sizing: border-box; padding: 40px 48px; color: #fff; overflow: hidden; }
    .critical { background: radial-gradient(130% 150% at 100% 0%, #e2393f 0%, #be1f24 45%, #6e0f13 100%); }
    .important { background: radial-gradient(130% 150% at 100% 0%, #6d6e71 0%, #4a4b4e 50%, #2b2c2e 100%); }
    [dir="rtl"] .critical { background: radial-gradient(130% 150% at 0% 0%, #e2393f 0%, #be1f24 45%, #6e0f13 100%); }
    [dir="rtl"] .important { background: radial-gradient(130% 150% at 0% 0%, #6d6e71 0%, #4a4b4e 50%, #2b2c2e 100%); }
    .watermark { position: absolute; inset-inline-end: -70px; bottom: -80px; width: 380px; height: 380px; opacity: 0.16; }
    .top { display: flex; align-items: center; justify-content: space-between; position: relative; }
    .badge { display: inline-flex; align-items: center; gap: 12px; padding: 10px 22px; border-radius: 999px; background: rgba(255, 255, 255, 0.16); font-size: 21px; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; }
    [dir="rtl"] .badge { letter-spacing: 0; font-size: 24px; }
    .dot { width: 13px; height: 13px; border-radius: 50%; background: #fff; box-shadow: 0 0 0 6px rgba(255, 255, 255, 0.25); }
    .wordmark { height: 46px; }
    .module { position: absolute; inset-inline-start: 48px; bottom: 44px; display: flex; align-items: center; gap: 24px; }
    .icon { width: 96px; height: 96px; border-radius: 26px; background: rgba(255, 255, 255, 0.14); display: flex; align-items: center; justify-content: center; color: #fff; }
    .icon svg { width: 56px; height: 56px; }
    h1 { margin: 0; font-size: 62px; font-weight: 800; letter-spacing: -0.02em; line-height: 1.05; }
    [dir="rtl"] h1 { letter-spacing: 0; font-size: 58px; }
    h1.long { font-size: 46px; }
    p { margin: 10px 0 0; font-size: 23px; opacity: 0.88; }
</style>
</head>
<body>
<div class="card {{ $priority }}">
    <img class="watermark" src="{{ $watermark }}" alt="">
    <div class="top">
        <span class="badge"><span class="dot"></span>{{ $label }}</span>
        <img class="wordmark" src="{{ $wordmark }}" alt="">
    </div>
    <div class="module">
        <div class="icon">{!! $iconSvg !!}</div>
        <div>
            <h1 @class(['long' => mb_strlen($title) > 12])>{{ $title }}</h1>
            <p>{{ $subtitle }}</p>
        </div>
    </div>
</div>
</body>
</html>
