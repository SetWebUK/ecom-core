<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\Storage;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/**
 * Attachments -> media library rows for files present on the public disk under uploads/ (copied by --copy-uploads
 * or beforehand); rows are keyed on the unique public-disk path. Missing files are reported, private attachments
 * (plugin zips / data exports) are never exposed.
 */
class MediaStep extends AbstractStep
{
    public function key(): string
    {
        return 'media';
    }

    public function section(): string
    {
        return 'media';
    }

    public function after(): array
    {
        return ['media.files'];
    }

    protected function clear(): void
    {
        $this->ctx->owned('media')->delete();
    }

    protected function import(): void
    {
        // Private attachments are plugin zips / data exports – never expose them in the media library.
        $posts = $this->wp->posts('attachment', ['inherit']);
        $meta = $this->wp->postMeta($posts->pluck('ID')->all(), ['_wp_attached_file', '_wp_attachment_image_alt', '_wp_attachment_metadata']);
        $skippedPrivate = $this->wp->table('posts')->where('post_type', 'attachment')->where('post_status', '!=', 'inherit')->count();

        $rows = [];
        $missing = [];
        foreach ($posts as $post) {
            $m = $meta[$post->ID] ?? [];
            $file = $m['_wp_attached_file'] ?? null;
            if (! $file) {
                $missing[] = $post->ID.' (no file)';

                continue;
            }
            $path = 'uploads/'.ltrim($file, '/');
            $absolute = Storage::disk('public')->path($path);
            if (! is_file($absolute)) {
                $missing[] = $post->ID.' '.$path;

                continue;
            }
            $info = WordPressSource::unserialize($m['_wp_attachment_metadata'] ?? '');
            $info = is_array($info) ? $info : [];
            $width = $info['width'] ?? null;
            $height = $info['height'] ?? null;
            if ((! $width || ! $height) && str_starts_with((string) $post->post_mime_type, 'image/') && $post->post_mime_type !== 'image/svg+xml') {
                $size = @getimagesize($absolute);
                [$width, $height] = $size ? [$size[0], $size[1]] : [null, null];
            }
            $dir = trim(dirname($file), './');

            $rows[$path] = [
                'path' => $path,
                'filename' => basename($path),
                'title' => Formatter::decode($post->post_title) ?: null,
                'alt' => Formatter::decode($m['_wp_attachment_image_alt'] ?? '') ?: null,
                'mime_type' => $post->post_mime_type ?: (mime_content_type($absolute) ?: null),
                'size' => filesize($absolute) ?: null,
                'width' => $width ? (int) $width : null,
                'height' => $height ? (int) $height : null,
                'folder' => $dir !== '' ? $dir : null,
                'wp_id' => $post->ID,
                'created_at' => WordPressSource::gmt($post->post_date_gmt) ?? $this->now(),
                'updated_at' => WordPressSource::gmt($post->post_modified_gmt) ?? $this->now(),
            ];
        }

        $this->ctx->save('media', array_values($rows), 'path');

        foreach ($missing as $item) {
            $this->ctx->warn('Media file missing from the public disk: attachment '.$item);
        }
        $this->ctx->count('Media (attachments)', $posts->count().' (+'.$skippedPrivate.' private)', $this->ctx->owned('media')->count(),
            count($missing).' missing files, private zips/CSV exports skipped');
    }
}
