<?php

namespace Pine\Commerce\Services\Admin;

/**
 * Safe HTML for order notes. Notes imported from WooCommerce contain a little markup (<strong>, links to
 * payment dashboards, <br>); staff notes are plain text. Only a tiny tag allowlist survives, all attributes
 * are dropped except http(s) link targets.
 */
class NoteFormatter
{
    public static function html(?string $note): string
    {
        $note = (string) $note;
        if (! preg_match('/<[a-z][^>]*>/i', $note)) {
            return nl2br(e($note));
        }

        $clean = strip_tags($note, '<strong><b><em><i><br><a><code><p><ul><ol><li>');
        // Links: keep only an http(s) href
        $clean = preg_replace_callback('/<a\b[^>]*>/i', function (array $m): string {
            if (preg_match('/href\s*=\s*(["\'])(https?:\/\/[^"\']+)\1/i', $m[0], $href)) {
                return '<a href="'.e(html_entity_decode($href[2])).'" target="_blank" rel="noopener noreferrer" class="note-link">';
            }

            return '<a>';
        }, $clean);
        // Every other allowed tag loses its attributes
        $clean = preg_replace('/<(strong|b|em|i|br|code|p|ul|ol|li)\b[^>]*?(\/?)>/i', '<$1$2>', $clean);

        return trim($clean);
    }
}
