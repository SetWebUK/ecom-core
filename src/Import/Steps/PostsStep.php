<?php

namespace Pine\Commerce\Import\Steps;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Data\ContentItem;
use Pine\Commerce\Import\Data\WpPost;
use Pine\Commerce\Import\Source\WordPressSource;
use Pine\Commerce\Import\Support\Formatter;

/** Blog posts + blog categories (category taxonomy). Body via ResolvesContent, looked up at the post's real source URL. */
class PostsStep extends AbstractStep
{
    use Concerns\ResolvesContent;

    public function key(): string
    {
        return 'content.posts';
    }

    public function section(): string
    {
        return 'content';
    }

    public function after(): array
    {
        return ['users'];
    }

    protected function clear(): void
    {
        $this->ctx->owned('posts')->delete();
    }

    protected function import(): void
    {
        $terms = $this->wp->terms('category');
        $catRows = [];
        foreach ($terms as $t) {
            $catRows[] = ['slug' => urldecode($t->slug), 'name' => Formatter::decode($t->name), 'created_at' => $this->now(), 'updated_at' => $this->now()];
        }
        $catIds = $this->ctx->save('post_categories', $catRows, 'slug', ['created_at']);
        $catByTerm = [];
        foreach ($terms as $t) {
            $catByTerm[$t->term_id] = $catIds[urldecode($t->slug)] ?? null;
        }

        $posts = $this->wp->posts('post', ['publish', 'draft', 'private', 'future', 'pending']);
        $meta = $this->wp->postMeta($posts->pluck('ID')->all());
        $objectTerms = $this->wp->objectTerms($posts->pluck('ID')->all(), 'category');
        $authors = $this->ctx->owned('users')->pluck('id', 'wp_id')->all();
        $sourcePaths = $this->ctx->permalinks()->posts();
        $builder = $this->ctx->permalinks()->builder();
        $rendered = (bool) $this->ctx->config('seo.rendered_fallback', true);

        $rows = [];
        $sources = [];
        foreach ($posts as $post) {
            $m = $meta[$post->ID] ?? [];
            $title = Formatter::decode($post->post_title);
            $slug = $post->post_name !== '' ? urldecode($post->post_name) : Str::slug($title);
            $sourcePath = $sourcePaths[$post->ID] ?? $builder->postPath(['slug' => $slug, 'id' => (int) $post->ID, 'date' => $post->post_date]) ?? 'blog/'.$slug;
            [$content, $source] = $this->body(new ContentItem('post', (int) $post->ID, $sourcePath, (string) $post->post_content, $m));
            $sources[$source] = ($sources[$source] ?? 0) + 1;

            $cats = collect($objectTerms[$post->ID] ?? [])->pluck('term_id')->all();
            $wpPost = WpPost::fromRow($post);
            $primary = (int) $this->ctx->primaryTermId($wpPost, $m, 'category');
            $categoryTerm = in_array($primary, $cats, false) ? $primary : ($cats[0] ?? null);
            $excerpt = trim(Formatter::decode($post->post_excerpt)) ?: Formatter::excerpt($content, 55);
            $vars = ['title' => $title, 'excerpt' => $excerpt];
            $seo = $this->ctx->postSeo($wpPost, $m, $vars);
            $renderedSeo = $rendered ? $this->ctx->renderedSeo($sourcePath) : ['title' => null, 'description' => null];

            $rows[] = [
                'wp_id' => $post->ID,
                'post_category_id' => $categoryTerm ? ($catByTerm[$categoryTerm] ?? null) : null,
                'author_id' => $authors[$post->post_author] ?? null,
                'title' => $title,
                'slug' => $slug,
                'excerpt' => $excerpt,
                'content' => $content,
                'featured_image' => $this->ctx->attachmentPath($m['_thumbnail_id'] ?? 0),
                'status' => $post->post_status === 'publish' || $post->post_status === 'future' ? 'published' : 'draft',
                'meta_title' => $this->metaTitle($seo?->title, $renderedSeo, $title),
                'meta_description' => $seo?->description ?? $renderedSeo['description'],
                'published_at' => WordPressSource::gmt($post->post_date_gmt) ?? WordPressSource::gmt($post->post_modified_gmt),
                'created_at' => WordPressSource::gmt($post->post_date_gmt) ?? $this->now(),
                'updated_at' => WordPressSource::gmt($post->post_modified_gmt) ?? $this->now(),
            ];
        }
        $this->ctx->adopt('posts', $rows, 'slug');
        $this->ctx->save('posts', $rows, 'wp_id', ['created_at']);

        $this->ctx->count('Blog categories', $terms->count(), DB::table('post_categories')->count());
        $this->ctx->count('Blog posts', $posts->count(), $this->ctx->owned('posts')->count(),
            collect($sources)->map(fn ($c, $s) => "$s $c")->implode(', '));
    }
}
