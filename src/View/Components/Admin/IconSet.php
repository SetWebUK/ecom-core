<?php

namespace Pine\Commerce\View\Components\Admin;

/**
 * Heroicons v2 lookup for <x-admin.icon name="…">. Every Heroicon is available:
 *   variant "outline" (24px stroke, default) and "mini" (20px solid) – browse names at https://heroicons.com.
 * Data lives in ./Icons/{outline,mini}.php (generated from the heroicons@2.2.0 npm package).
 */
class IconSet
{
    /** @var array<string, array<string, string>> */
    protected static array $sets = [];

    public static function svg(string $name, string $variant = 'outline'): ?string
    {
        $variant = $variant === 'mini' || $variant === 'solid' ? 'mini' : 'outline';
        static::$sets[$variant] ??= require __DIR__.'/Icons/'.$variant.'.php';

        return static::$sets[$variant][$name] ?? null;
    }

    public static function exists(string $name, string $variant = 'outline'): bool
    {
        return static::svg($name, $variant) !== null;
    }

    /** @return list<string> */
    public static function names(string $variant = 'outline'): array
    {
        static::svg('home', $variant);

        return array_keys(static::$sets[$variant === 'mini' ? 'mini' : 'outline']);
    }
}
