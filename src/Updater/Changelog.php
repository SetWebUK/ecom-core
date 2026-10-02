<?php

namespace Pine\Commerce\Updater;

/**
 * Reads CHANGELOG.md (Keep a Changelog: "## [1.3.0] - 2026-10-02" sections, "### Client actions required"
 * sub-sections) for the update screen.
 */
final class Changelog
{
    /**
     * Sections of the versions after $installed up to and including $latest, newest first.
     *
     * @return list<array{version:string, date:?string, body:string, client_actions:?string, actions_required:bool}>
     */
    public static function between(string $markdown, string $installed, string $latest): array
    {
        $sections = [];
        foreach (static::sections($markdown) as $section) {
            if (Versions::greater($section['version'], $installed) && ! Versions::greater($section['version'], $latest)) {
                $sections[] = $section;
            }
        }
        usort($sections, fn ($a, $b) => Versions::compare($b['version'], $a['version']));

        return $sections;
    }

    /** @return list<array{version:string, date:?string, body:string, client_actions:?string, actions_required:bool}> */
    public static function sections(string $markdown): array
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $parts = preg_split('/^## \[/m', $markdown) ?: [];
        array_shift($parts); // the preamble
        $sections = [];
        foreach ($parts as $part) {
            if (! preg_match('/^(\d+\.\d+\.\d+)\](?:\s*-\s*(\S+))?[^\n]*\n?/', $part, $m)) {
                continue; // [Unreleased] and anything that is not a release
            }
            $body = substr($part, strlen($m[0]));
            // link reference definitions at the bottom of the file ("[1.2.0]: https://…")
            $body = trim(preg_replace('/^\[[^\]]+\]:\s*\S+\s*$/m', '', $body));
            $actions = static::clientActions($body);
            $sections[] = [
                'version' => $m[1],
                'date' => $m[2] ?? null,
                'body' => $body,
                'client_actions' => $actions,
                'actions_required' => $actions !== null && ! preg_match('/^(?:[-*]\s*)?none\b/i', trim($actions)),
            ];
        }

        return $sections;
    }

    /** Text of the "### Client actions required" sub-section, or null. */
    public static function clientActions(string $body): ?string
    {
        if (! preg_match('/^###\s+Client actions required\s*$/mi', $body, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $rest = substr($body, $m[0][1] + strlen($m[0][0]));
        $end = preg_match('/^#{2,3}\s/m', $rest, $n, PREG_OFFSET_CAPTURE) ? $n[0][1] : strlen($rest);
        $text = trim(substr($rest, 0, $end));

        return $text === '' ? null : $text;
    }
}
