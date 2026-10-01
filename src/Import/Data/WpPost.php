<?php

namespace Pine\Commerce\Import\Data;

final class WpPost
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly string $status,
        public readonly string $title,
        public readonly string $name,
        public readonly string $content,
        public readonly string $excerpt,
        public readonly int $parentId,
        public readonly int $menuOrder,
        public readonly ?string $dateGmt,
        public readonly ?string $modifiedGmt,
        public readonly int $authorId,
        public readonly ?string $date = null,
        public readonly string $mimeType = '',
    ) {}

    public static function fromRow(object $p): self
    {
        return new self((int) $p->ID, (string) $p->post_type, (string) $p->post_status, (string) $p->post_title,
            (string) $p->post_name, (string) ($p->post_content ?? ''), (string) ($p->post_excerpt ?? ''), (int) ($p->post_parent ?? 0),
            (int) ($p->menu_order ?? 0), $p->post_date_gmt ?? null, $p->post_modified_gmt ?? null, (int) ($p->post_author ?? 0),
            $p->post_date ?? null, (string) ($p->post_mime_type ?? ''));
    }
}
