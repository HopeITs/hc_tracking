{{--
    The square icon on the left of every desktop notification (256×256, shown
    at about 48px): urgency colour, the module's icon, the HOPE arch. Rendered
    to PNG by `php artisan notifications:banners`; never served as a page.
--}}
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    html, body { margin: 0; width: 256px; height: 256px; overflow: hidden; background: transparent; }
    .tile { position: relative; width: 256px; height: 256px; border-radius: 60px; overflow: hidden; display: flex; align-items: center; justify-content: center; color: #fff; }
    .critical { background: radial-gradient(130% 130% at 100% 0%, #e2393f 0%, #be1f24 50%, #7d1216 100%); }
    .important { background: radial-gradient(130% 130% at 100% 0%, #6d6e71 0%, #4a4b4e 55%, #2b2c2e 100%); }
    .watermark { position: absolute; right: -44px; bottom: -48px; width: 190px; height: 190px; opacity: 0.18; }
    .tile svg { position: relative; width: 132px; height: 132px; }
</style>
</head>
<body>
<div class="tile {{ $priority }}">
    <img class="watermark" src="{{ $watermark }}" alt="">
    {!! $iconSvg !!}
</div>
</body>
</html>
