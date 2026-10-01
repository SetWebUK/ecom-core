<?php

namespace Pine\Commerce\Import\Adapters;

use Pine\Commerce\Import\Contracts\ProductMapper;
use Pine\Commerce\Import\Contracts\TermMapper;
use Pine\Commerce\Import\Data\WpProduct;
use Pine\Commerce\Import\Data\WpTerm;
use Pine\Commerce\Import\ImportContext;
use Pine\Commerce\Import\Source\SiteProfile;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Advanced Custom Fields: field values (stored as term/post meta under the field name) copied into columns by the
 * mapping in `commerce-import.acf.term_fields` / `acf.product_fields`:
 *   'field_name' => 'column'                       (format html)
 *   'field_name' => ['column' => 'x', 'format' => 'html'|'text'|'raw'|'image'|'bool'|'decimal']
 * html = wpautop + URL rewriting; text = tags stripped; image = attachment id → public-disk path.
 */
class Acf extends AbstractAdapter implements ProductMapper, TermMapper
{
    public function key(): string
    {
        return 'acf';
    }

    public function label(): string
    {
        return 'Advanced Custom Fields';
    }

    public function priority(): int
    {
        return 30;
    }

    public function detect(SiteProfile $site, WordPressSource $wp): bool
    {
        return $site->hasPlugin('advanced-custom-fields/acf.php', 'advanced-custom-fields-pro/acf.php', 'secure-custom-fields/*');
    }

    public function mapCategory(array $row, WpTerm $term, array $termMeta, ImportContext $ctx): array
    {
        return $this->apply($row, (array) $ctx->config('acf.term_fields', []), $termMeta, $ctx);
    }

    public function mapProduct(array $row, WpProduct $product, ImportContext $ctx): array
    {
        return $this->apply($row, (array) $ctx->config('acf.product_fields', []), $product->meta, $ctx);
    }

    public function specRows(WpProduct $product, ImportContext $ctx): ?array
    {
        return null;
    }

    private function apply(array $row, array $fields, array $meta, ImportContext $ctx): array
    {
        foreach ($fields as $field => $target) {
            $target = is_array($target) ? $target : ['column' => $target];
            $column = $target['column'] ?? null;
            if (! $column || ! array_key_exists($field, $meta)) {
                continue;
            }
            $row[$column] = self::format($meta[$field], $target['format'] ?? 'html', $ctx);
        }

        return $row;
    }

    public static function format($value, string $format, ?ImportContext $ctx = null)
    {
        $value = WordPressSource::unserialize($value);
        if (is_array($value)) {
            $value = implode(', ', array_map('strval', $value));
        }
        $value = trim((string) $value);

        return match ($format) {
            'raw' => $value === '' ? null : $value,
            'text' => Formatter::text($value) ?: null,
            'image' => $ctx?->attachmentPath($value),
            'bool' => WordPressSource::yes($value),
            'decimal' => WordPressSource::decimal($value),
            default => $value !== '' ? Formatter::clean(Formatter::autop($value)) : null,
        };
    }
}
