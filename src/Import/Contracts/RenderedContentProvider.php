<?php

namespace Pine\Commerce\Import\Contracts;

use Pine\Commerce\Import\Data\ContentItem;

/** Body HTML of a page/post taken from somewhere better than post_content (rendered page, page-builder data). First non-null wins. */
interface RenderedContentProvider
{
    public function renderedHtml(ContentItem $item): ?string;
}
