<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\Locale;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Renders stored notification data in a given language.
 */
final class NotificationPresenter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function sentence(array $data, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $params = [];

        foreach ($data['params'] ?? [] as $name => $param) {
            $params[$name] = $this->param($param, $locale);
        }

        $sentence = (string) __((string) ($data['key'] ?? ''), $params, $locale);
        $more = (int) ($data['count'] ?? 1) - 1;

        if ($more > 0) {
            $sentence .= ' '.trans_choice('notifications.more_updates', $more, ['count' => $more], $locale);
        }

        return $sentence;
    }

    /**
     * The sentence in every interface language, one per line: what inbox
     * search matches, so people find the words they actually read.
     *
     * @param  array<string, mixed>  $data
     */
    public function searchText(array $data): string
    {
        return implode("\n", array_map(
            fn (Locale $locale): string => $this->sentence([...$data, 'count' => 1], $locale->value),
            Locale::cases(),
        ));
    }

    /**
     * The push payload for the browser, rendered in the reader's language.
     *
     * @return array{id: string, text: string, module: string, module_label: string, priority: string, kind: string, url: string, read_url: string, icon: string, image: string|null, created_at: string}
     */
    public function toBroadcast(UserNotification $notification, string $locale): array
    {
        return [
            'id' => $notification->id,
            'text' => $this->sentence($notification->data, $locale),
            'module' => $notification->module,
            'module_label' => $this->moduleLabel($notification->module, $locale),
            'priority' => $notification->priority->value,
            'kind' => $notification->kind->value,
            'url' => route('notifications.open', $notification->id),
            'read_url' => route('notifications.read', $notification->id),
            'icon' => $this->desktopIcon($notification->module, $notification->priority->value),
            'image' => $this->desktopBanner($notification->module, $notification->priority->value, $locale),
            'created_at' => (string) $notification->created_at?->toIso8601String(),
        ];
    }

    /**
     * The banner on top of a desktop popup, in the reader's language
     * (public/notification-banners/{locale}); null for info, which never
     * pops up.
     */
    public function desktopBanner(string $module, string $priority, string $locale): ?string
    {
        $banner = "notification-banners/{$locale}/{$module}-{$priority}.png";

        return is_file(public_path($banner)) ? asset($banner) : null;
    }

    /**
     * The square icon of a desktop popup: the module's tile in its urgency
     * colour (public/notification-banners/tiles, rendered by
     * `notifications:banners`), else the app icon.
     */
    public function desktopIcon(string $module, string $priority): string
    {
        $tile = "notification-banners/tiles/{$module}-{$priority}.png";

        return asset(is_file(public_path($tile)) ? $tile : 'icons/icon-192.png');
    }

    public function moduleLabel(string $module, ?string $locale = null): string
    {
        return (string) __("notifications.modules.{$module}", [], $locale ?? app()->getLocale());
    }

    /**
     * One typed param as display text.
     */
    public function param(mixed $param, string $locale): string
    {
        if (! is_array($param)) {
            return (string) $param;
        }

        return match (true) {
            isset($param['trans']) => (string) __($param['trans'], [], $locale),
            isset($param['enum']) => (string) __('enums.'.$param['enum'].'.'.$this->enumKey((string) $param['value']), [], $locale),
            isset($param['date']) => CarbonImmutable::parse($param['date'])->locale($this->carbonLocale($locale))->translatedFormat((string) __('formats.date', [], $locale)),
            isset($param['weekday']) => CarbonImmutable::parse($param['weekday'])->locale($this->carbonLocale($locale))->translatedFormat('l'),
            isset($param['localized']) => $this->localized($param['localized'], $locale),
            isset($param['localized_list']) => implode(
                $locale === 'ar' ? '، ' : ', ',
                array_map(fn (array $names): string => $this->localized($names, $locale), $param['localized_list']),
            ),
            default => '',
        };
    }

    /**
     * The Carbon locale the rest of the app formats dates with (Levantine
     * month names in Arabic), so notifications read like every other page.
     */
    private function carbonLocale(string $locale): string
    {
        return Locale::tryFrom($locale)?->carbonLocale() ?? $locale;
    }

    /**
     * @param  array{en?: string, ar?: string}  $names
     */
    private function localized(array $names, string $locale): string
    {
        $preferred = $names[$locale] ?? '';

        return $preferred !== '' ? $preferred : (string) ($names['en'] ?? '');
    }

    /**
     * Same rule as HasTranslatedLabel::labelKey(): the lang key segment for a
     * stored enum value.
     */
    private function enumKey(string $value): string
    {
        return Str::lower((string) preg_replace('/[^A-Za-z0-9]+/', '_', $value));
    }
}
