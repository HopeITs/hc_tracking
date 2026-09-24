<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Notifications\NotificationPresenter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Renders the artwork of desktop notifications, one PNG per module × urgency
 * (critical, important):
 * - the banner on top of the popup, per language:
 *   public/notification-banners/{locale}/{module}-{priority}.png
 * - the square tile used by the in-app toasts:
 *   public/notification-banners/tiles/{module}-{priority}.png
 *
 * The PNGs are committed, so servers never need a browser. Re-run this only
 * after changing a design (resources/views/notifications/banner.blade.php,
 * tile.blade.php), a module's name or icon, or the banner text. Needs Chrome
 * or Edge.
 */
class RenderNotificationBanners extends Command
{
    /**
     * @var string
     */
    protected $signature = 'notifications:banners {--browser= : Path to chrome.exe or msedge.exe}';

    /**
     * @var string
     */
    protected $description = 'Render the desktop notification banners and tiles (needs Chrome or Edge)';

    public const Priorities = ['critical', 'important'];

    public function handle(NotificationPresenter $presenter): int
    {
        $browser = $this->browser();

        if ($browser === null) {
            $this->error(__('notifications.console.no_browser'));

            return self::FAILURE;
        }

        $work = storage_path('framework/notification-banners');
        File::ensureDirectoryExists($work);
        $watermark = $this->fileUrl($this->watermark($work));
        $rendered = 0;

        foreach (array_keys(config('notifications.module_icons')) as $module) {
            $iconSvg = Blade::render('<flux:icon :name="$name" variant="outline" />', ['name' => config("notifications.module_icons.{$module}")]);

            foreach (self::Priorities as $priority) {
                foreach (['en', 'ar'] as $locale) {
                    $html = view('notifications.banner', [
                        'locale' => $locale,
                        'priority' => $priority,
                        'label' => __("notifications.banner.{$priority}", [], $locale),
                        'subtitle' => __("notifications.banner.{$priority}_subtitle", [], $locale),
                        'title' => $presenter->moduleLabel($module, $locale),
                        'iconSvg' => $iconSvg,
                        'wordmark' => $this->fileUrl(public_path('logo-tracking-white.svg')),
                        'watermark' => $watermark,
                    ])->render();

                    if (! $this->shoot($browser, $work, $html, public_path("notification-banners/{$locale}/{$module}-{$priority}.png"), 728, 364)) {
                        return self::FAILURE;
                    }

                    $rendered++;
                }

                $tile = view('notifications.tile', ['priority' => $priority, 'iconSvg' => $iconSvg, 'watermark' => $watermark])->render();

                if (! $this->shoot($browser, $work, $tile, public_path("notification-banners/tiles/{$module}-{$priority}.png"), 256, 256)) {
                    return self::FAILURE;
                }

                $rendered++;
            }
        }

        File::deleteDirectory($work);
        $this->info(__('notifications.console.banners_rendered', ['count' => $rendered]));

        return self::SUCCESS;
    }

    /**
     * Screenshot one rendered page to a PNG (transparent outside the artwork).
     */
    private function shoot(string $browser, string $work, string $html, string $png, int $width, int $height): bool
    {
        File::ensureDirectoryExists(dirname($png));
        $page = $work.'/'.basename($png, '.png').'-'.$width.'.html';
        File::put($page, $html);

        $result = Process::timeout(60)->run([
            $browser, '--headless=new', '--disable-gpu', '--hide-scrollbars', '--allow-file-access-from-files',
            '--virtual-time-budget=2000', "--window-size={$width},{$height}", '--default-background-color=00000000',
            '--user-data-dir='.$work.'/profile',
            '--screenshot='.$png,
            $this->fileUrl($page),
        ]);

        if (File::exists($png)) {
            return true;
        }

        $this->error($result->errorOutput());

        return false;
    }

    /**
     * The logo's arch and family in white, for the watermarks.
     */
    private function watermark(string $work): string
    {
        $svg = str_replace(
            ['fill: #fff;', 'fill: #be1f24;'],
            ['fill: #ffffff;', 'fill: transparent;'],
            File::get(public_path('logo-tracking-icon.svg')),
        );

        File::put("{$work}/watermark.svg", $svg);

        return "{$work}/watermark.svg";
    }

    private function browser(): ?string
    {
        $candidates = array_filter([
            $this->option('browser'),
            config('notifications.banner_browser'),
            'C:\Program Files\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Google\Chrome\Application\chrome.exe',
            'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && File::exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function fileUrl(string $path): string
    {
        return 'file:///'.ltrim(str_replace('\\', '/', $path), '/');
    }
}
