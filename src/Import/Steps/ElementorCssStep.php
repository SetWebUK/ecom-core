<?php

namespace Pine\Commerce\Import\Steps;

use Pine\Commerce\Import\ImportContext;

/**
 * --copy-uploads with Elementor: the generated per-document stylesheets uploads/elementor/css/post-{id}.css that
 * rendered Elementor content relies on are copied to `commerce-import.elementor.css_path` (default
 * storage/app/import/elementor-css – move them into the client theme, e.g. themes/{slug}/assets/css/elementor/).
 * Existing files with identical content are left alone.
 */
class ElementorCssStep extends AbstractStep
{
    public function key(): string
    {
        return 'content.elementor-css';
    }

    public function section(): string
    {
        return 'media';
    }

    public function after(): array
    {
        return ['media.files'];
    }

    public function shouldRun(ImportContext $ctx): bool
    {
        return ! empty($ctx->options['copy_uploads']);
    }

    protected function import(): void
    {
        $uploads = MediaFilesStep::sourceDir($this->ctx);
        $dir = $uploads ? $uploads.'/elementor/css' : null;
        if (! $dir || ! is_dir($dir)) {
            return;
        }
        $target = rtrim((string) ($this->ctx->config('elementor.css_path') ?? storage_path('app/import/elementor-css')), '/');
        $copied = 0;
        $same = 0;
        foreach (glob($dir.'/post-*.css') ?: [] as $file) {
            $dest = $target.'/'.basename($file);
            if (is_file($dest) && md5_file($dest) === md5_file($file)) {
                $same++;

                continue;
            }
            if (! $this->ctx->dryRun) {
                @mkdir($target, 0775, true);
                copy($file, $dest);
            }
            $copied++;
        }
        $this->ctx->count('Elementor CSS files', $copied + $same, $copied + $same, ($this->ctx->dryRun ? 'would copy ' : 'copied ').$copied.' to '.str_replace(base_path().'/', '', $target));
    }
}
