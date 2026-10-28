<?php

namespace Pine\Commerce\Services\Admin;

use Throwable;

/**
 * The back office in the shop's own colours and logo, with no set-up: the storefront theme's colours (theme.json
 * "primary_color" / "accent_color", as edited in Settings › Theme) and Settings › Store details › Logo.
 * config commerce.admin.brand.{color, accent, logo} override them (color = false keeps the neutral look).
 *
 * Colours are adjusted for contrast, so any brand colour works:
 *   --shell*    sidebar: the brand colour, darkened until white text reads on it comfortably (10:1)
 *   --accent    active-page marker and alert counts: the accent, lightened until it shows on the sidebar (3:1)
 *   --primary*  buttons, links, focus: the brand colour only when white text reads on it (4.5:1), else the default blue
 */
class AdminBrand
{
    public const NEUTRAL = '#1a1a1a';

    protected static ?array $memo = null;

    /** @return array{shell:string, shell_hover:string, shell_active:string, accent:string, accent_text:string, primary:?string, primary_hover:?string, primary_active:?string, primary_soft:?string, primary_soft_hover:?string} */
    public static function palette(): array
    {
        if (static::$memo !== null) {
            return static::$memo;
        }

        $configured = config('commerce.admin.brand.color');
        $brand = $configured === false ? null : static::hex($configured) ?? static::hex(static::themeSetting('primary_color'));
        $accent = static::hex(config('commerce.admin.brand.accent')) ?? static::hex(static::themeSetting('accent_color'));

        $shell = static::darkenTo($brand ?? static::NEUTRAL, '#ffffff', 10.0);
        $marker = $accent && static::contrast($accent, $shell) >= 3.0 ? $accent
            : ($accent ? static::lightenTo($accent, $shell, 3.0) : '#ffffff');
        $primary = $brand && static::contrast($brand, '#ffffff') >= 4.5 ? $brand : null;

        return static::$memo = [
            'shell' => $shell,
            'shell_hover' => static::mix($shell, '#ffffff', .08),
            'shell_active' => static::mix($shell, '#ffffff', .15),
            'accent' => $marker,
            // the count badges' text on the accent: whichever of white or the sidebar colour reads better
            'accent_text' => static::contrast($marker, '#ffffff') >= static::contrast($marker, $shell) ? '#ffffff' : $shell,
            'primary' => $primary,
            'primary_hover' => $primary ? static::mix($primary, '#000000', .12) : null,
            'primary_active' => $primary ? static::mix($primary, '#000000', .25) : null,
            'primary_soft' => $primary ? static::mix($primary, '#ffffff', .92) : null,
            'primary_soft_hover' => $primary ? static::mix($primary, '#ffffff', .86) : null,
        ];
    }

    /** The :root custom properties for the layout's <style> – hex values only, so safe to print raw. */
    public static function css(): string
    {
        $p = static::palette();
        $vars = ['--shell' => $p['shell'], '--shell-hover' => $p['shell_hover'], '--shell-active' => $p['shell_active'], '--accent' => $p['accent'],
            '--accent-text' => $p['accent_text']];
        if ($p['primary']) {
            $vars += ['--brand' => $p['primary'], '--primary' => $p['primary'], '--primary-hover' => $p['primary_hover'], '--primary-active' => $p['primary_active'],
                '--primary-soft' => $p['primary_soft'], '--primary-soft-hover' => $p['primary_soft_hover'], '--link' => $p['primary'], '--focus' => $p['primary'],
                '--surface-selected' => $p['primary_soft']];
        }

        return ':root{'.implode('', array_map(fn ($k, $v) => $k.':'.$v.';', array_keys($vars), $vars)).'}';
    }

    /** The shop's logo (config commerce.admin.brand.logo, else Settings › Store details › Logo), or null. */
    public static function logo(): ?string
    {
        $configured = config('commerce.admin.brand.logo');
        if (is_string($configured) && $configured !== '') {
            return asset(ltrim($configured, '/'));
        }
        try {
            $path = setting('store.logo');
        } catch (Throwable) {
            return null;
        }

        return is_string($path) && $path !== '' ? media_url($path) : null;
    }

    public static function name(): string
    {
        return (string) (setting('store.name') ?: config('commerce.admin.brand.name') ?: config('app.name'));
    }

    /** Up to two initials of the shop's name, for the logo tile when there's no logo. */
    public static function initials(): string
    {
        $words = array_values(array_filter(preg_split('/[\s\-_.]+/u', static::name()) ?: []));

        return mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2)))) ?: '•';
    }

    /** Forget the per-request palette (tests, long-running workers after a theme change). */
    public static function flush(): void
    {
        static::$memo = null;
    }

    // ---------------------------------------------------------------------------------------------------- colour

    public static function hex(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $m)) {
            return null;
        }
        $h = strtolower($m[1]);

        return '#'.(strlen($h) === 3 ? $h[0].$h[0].$h[1].$h[1].$h[2].$h[2] : $h);
    }

    /** WCAG contrast ratio of two #rrggbb colours (1–21). */
    public static function contrast(string $a, string $b): float
    {
        [$l1, $l2] = [static::luminance($a), static::luminance($b)];

        return (max($l1, $l2) + .05) / (min($l1, $l2) + .05);
    }

    /** $a moved $amount (0–1) of the way towards $b. */
    public static function mix(string $a, string $b, float $amount): string
    {
        [$ra, $ga, $ba] = static::rgb($a);
        [$rb, $gb, $bb] = static::rgb($b);
        $c = fn ($x, $y) => (int) round($x + ($y - $x) * $amount);

        return sprintf('#%02x%02x%02x', $c($ra, $rb), $c($ga, $gb), $c($ba, $bb));
    }

    protected static function darkenTo(string $color, string $against, float $ratio): string
    {
        for ($i = 0; $i <= 20 && static::contrast($color, $against) < $ratio; $i++) {
            $color = static::mix($color, '#000000', .1);
        }

        return $color;
    }

    /** Raise the HSL lightness (keeps the hue and saturation, so a red stays red rather than turning pink). */
    protected static function lightenTo(string $color, string $against, float $ratio): string
    {
        [$h, $sat, $l] = static::hsl($color);
        for ($i = 0; $i <= 25 && static::contrast($color, $against) < $ratio; $i++) {
            $l = min(1.0, $l + .03);
            $color = static::fromHsl($h, $sat, $l);
        }

        return $color;
    }

    /** @return array{float, float, float} hue 0–360, saturation and lightness 0–1 */
    protected static function hsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn ($v) => $v / 255, static::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $sat = $l > .5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $sat, $l];
    }

    protected static function fromHsl(float $h, float $sat, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $sat;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
        };

        return sprintf('#%02x%02x%02x', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }

    protected static function luminance(string $hex): float
    {
        $channel = function (int $v): float {
            $v /= 255;

            return $v <= .03928 ? $v / 12.92 : (($v + .055) / 1.055) ** 2.4;
        };
        [$r, $g, $b] = static::rgb($hex);

        return .2126 * $channel($r) + .7152 * $channel($g) + .0722 * $channel($b);
    }

    /** @return array{int, int, int} */
    protected static function rgb(string $hex): array
    {
        $hex = ltrim(static::hex($hex) ?? static::NEUTRAL, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    protected static function themeSetting(string $key): mixed
    {
        try {
            return theme_setting($key);
        } catch (Throwable) {
            return null;
        }
    }
}
