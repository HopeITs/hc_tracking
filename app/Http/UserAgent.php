<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A readable description of the browser and operating system behind a
 * User-Agent header, e.g. "Chrome on Windows".
 *
 * Only families are read, never version numbers, so a routine browser update
 * still describes (and fingerprints) the same device.
 */
final readonly class UserAgent
{
    /**
     * The browser or platform of a header no family matches.
     */
    public const Unknown = 'Unknown';

    /**
     * Browser families, checked in order: Edge, Opera and Samsung Internet
     * also carry "Chrome" in their header, and Chrome carries "Safari".
     *
     * @var array<string, string>
     */
    private const Browsers = [
        'Edg/' => 'Edge',
        'EdgA/' => 'Edge',
        'EdgiOS/' => 'Edge',
        'OPR/' => 'Opera',
        'SamsungBrowser/' => 'Samsung Internet',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /**
     * Platform families, checked in order: Android headers also say "Linux",
     * and iPhone/iPad headers also say "Mac OS X".
     *
     * @var array<string, string>
     */
    private const Platforms = [
        'Windows' => 'Windows',
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'CrOS' => 'ChromeOS',
        'Macintosh' => 'macOS',
        'Linux' => 'Linux',
    ];

    public function __construct(public string $browser, public string $platform) {}

    /**
     * Describe a User-Agent header; anything unrecognised reads as "Unknown".
     */
    public static function parse(?string $header): self
    {
        $header = (string) $header;

        return new self(
            self::firstMatch($header, self::Browsers),
            self::firstMatch($header, self::Platforms),
        );
    }

    /**
     * The browser and platform together, e.g. "Chrome on Windows".
     */
    public function label(): string
    {
        return __(':browser on :platform', ['browser' => $this->browser, 'platform' => $this->platform]);
    }

    /**
     * The browser and platform together in English whatever the current
     * language, e.g. "Chrome on Windows", for storing (KnownDevice::$label).
     * Show people label() instead.
     */
    public function description(): string
    {
        return "{$this->browser} on {$this->platform}";
    }

    /**
     * A stable identifier for this browser/platform pair.
     */
    public function fingerprint(): string
    {
        return hash('sha256', mb_strtolower($this->browser.'|'.$this->platform));
    }

    /**
     * @param  array<string, string>  $families
     */
    private static function firstMatch(string $header, array $families): string
    {
        foreach ($families as $needle => $family) {
            if (str_contains($header, $needle)) {
                return $family;
            }
        }

        return self::Unknown;
    }
}
