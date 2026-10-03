<?php

namespace Pine\Commerce\Import\WooApi\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pine\Commerce\Import\Support\Formatter;
use Pine\Commerce\Import\WooApi\ApiMap;

/**
 * Pages, blog posts (+ blog categories) and the media library through the WordPress REST API (wp/v2). Without a
 * WordPress application password only published items are visible; with one, drafts and private items too. Bodies
 * are the rendered HTML (page builders included), cleaned like the database importer's: links to the old site made
 * relative, upload URLs pointed at /storage/uploads/.
 */
trait ImportsContent
{
    protected function wpQuery(array $query = []): array
    {
        if ($this->client->connection->hasWpPassword()) {
            $query += ['status' => 'publish,future,draft,pending,private'];
        }
        if (($since = $this->option('since'))) {
            $query['modified_after'] = gmdate('Y-m-d\TH:i:s', strtotime((string) $since));
        }

        return $query + ['orderby' => 'id', 'order' => 'asc'];
    }

    protected function importPages(): void
    {
        $entity = 'pages';
        $items = $this->all($entity, 'pages', $this->wpQuery(), 'wp/v2');
        $this->bump($entity, 'fetched', count($items));
        $front = (int) ($this->site['page_on_front'] ?? 0);
        $postsPage = (int) ($this->site['page_for_posts'] ?? 0);
        $byId = [];
        foreach ($items as $p) {
            $byId[(int) $p['id']] = $p;
        }
        $slugPath = function (int $id, int $guard = 0) use (&$slugPath, $byId, $front): string {
            if ($id === $front) {
                return '';
            }
            $p = $byId[$id];
            $slug = urldecode((string) ($p['slug'] ?? '')) ?: 'page-'.$id;
            $parent = (int) ($p['parent'] ?? 0);
            $parentPath = $parent && isset($byId[$parent]) && $guard < 20 ? $slugPath($parent, $guard + 1) : '';

            return ($parentPath !== '' ? $parentPath.'/' : '').$slug;
        };
        $system = array_flip((array) config('commerce-import.content.system_pages', []));
        $templates = (array) config('commerce-import.content.page_templates', []);
        $own = $this->u->map('pages');

        $rows = [];
        foreach ($byId as $id => $p) {
            $path = $id === $front ? '' : (ApiMap::path($p['link'] ?? null, $this->home) ?? $slugPath($id));
            if ($id !== $front && $path === '') {
                $path = $slugPath($id);
            }
            if (! isset($own[$id]) && $path !== '' && isset($this->foreign('pages', 'path', [$path])[$path])) {
                $taken = $path;
                $path = $this->unique('pages', 'path', $path);
                $this->issue('warning', $entity, $id, "/$taken/ already belongs to another shop's page – imported as /$path/");
            }
            $title = Formatter::decode((string) ($p['title']['rendered'] ?? ''));
            $protected = ! empty($p['content']['protected']);
            if ($protected) {
                $this->issue('warning', $entity, $id, "'$title' is password-protected – imported without its content");
            }
            $content = isset($system[$path]) || $protected ? null : Formatter::clean((string) ($p['content']['rendered'] ?? ''));
            $text = trim(strip_tags((string) ($p['excerpt']['rendered'] ?? ''))) ?: $content;
            $seo = $this->seo->read($p, ['title' => $title, 'excerpt' => Formatter::excerpt($text, 30)], $p['link'] ?? null, $text);
            $status = (string) ($p['status'] ?? 'publish');
            $rows[$id] = [
                'wp_id' => $id,
                'parent_id' => null,
                'title' => $title,
                'slug' => $path === '' ? (urldecode((string) ($p['slug'] ?? '')) ?: 'home') : Str::afterLast($path, '/'),
                'path' => $path,
                'template' => match (true) {
                    $id === $front => 'home',
                    isset($templates[$path]) => $templates[$path],
                    isset($templates[$id]) => $templates[$id],
                    $postsPage && $id === $postsPage => 'blog',
                    default => 'default',
                },
                'content' => $content,
                'status' => $status === 'publish' ? 'published' : 'draft',
                'meta_title' => Str::limit((string) $seo->title, 250, '') ?: null,
                'meta_description' => $seo->description,
                'noindex' => $seo->noindex,
                'sort_order' => (int) ($p['menu_order'] ?? 0),
                'created_at' => ApiMap::date($p['date_gmt'] ?? null) ?? $this->now(),
                'updated_at' => ApiMap::date($p['modified_gmt'] ?? null) ?? $this->now(),
            ];
        }
        $rows = $this->withoutExisting($entity, 'pages', array_values($rows));
        $this->transaction(function () use ($rows, $byId, $entity) {
            if (! $this->dryRun) {
                $this->u->adopt('pages', $rows, 'path');
            }
            $map = $this->saveRows($entity, 'pages', $rows, 'wp_id', ['created_at']);
            if ($this->dryRun) {
                return;
            }
            foreach ($rows as $row) {
                $parent = (int) ($byId[$row['wp_id']]['parent'] ?? 0);
                if ($parent && isset($map[$parent], $map[$row['wp_id']])) {
                    DB::table('pages')->where('id', $map[$row['wp_id']])->update(['parent_id' => $map[$parent]]);
                }
            }
        });
    }

    protected function importPosts(): void
    {
        $entity = 'posts';
        $categories = $this->all('', 'categories', ['orderby' => 'id', 'order' => 'asc'], 'wp/v2');
        $catRows = [];
        foreach ($categories as $c) {
            $catRows[] = ['slug' => urldecode((string) ($c['slug'] ?? '')), 'name' => Formatter::decode((string) ($c['name'] ?? '')),
                'created_at' => $this->now(), 'updated_at' => $this->now()];
        }
        $catIds = $this->dryRun ? [] : $this->transaction(fn () => $this->u->save('post_categories', $catRows, 'slug', ['created_at']));
        $catByRemote = [];
        foreach ($categories as $c) {
            $catByRemote[(int) $c['id']] = $catIds[urldecode((string) ($c['slug'] ?? ''))] ?? null;
        }

        $this->paged($entity, 'posts', $this->wpQuery(['_embed' => 'wp:featuredmedia']), 'wp/v2', function (array $items) use ($entity, $catByRemote) {
            $authors = $this->u->map('users');
            $rows = $sources = [];
            foreach ($items as $p) {
                $id = (int) $p['id'];
                $title = Formatter::decode((string) ($p['title']['rendered'] ?? ''));
                $slug = urldecode((string) ($p['slug'] ?? '')) ?: Str::slug($title) ?: 'post-'.$id;
                $content = ! empty($p['content']['protected']) ? null : Formatter::clean((string) ($p['content']['rendered'] ?? ''));
                $excerpt = trim(Formatter::decode(strip_tags((string) ($p['excerpt']['rendered'] ?? '')))) ?: Formatter::excerpt($content, 55);
                $seo = $this->seo->read($p, ['title' => $title, 'excerpt' => $excerpt], $p['link'] ?? null, $excerpt);
                $media = $p['_embedded']['wp:featuredmedia'][0] ?? null;
                $image = is_array($media) && ! empty($media['source_url'])
                    ? $this->image($entity, $id, ['id' => $media['id'] ?? null, 'src' => $media['source_url'], 'alt' => $media['alt_text'] ?? null,
                        'name' => $media['title']['rendered'] ?? null]) : null;
                $status = (string) ($p['status'] ?? 'publish');
                $rows[] = [
                    'wp_id' => $id,
                    'post_category_id' => $catByRemote[(int) (($p['categories'] ?? [])[0] ?? 0)] ?? null,
                    'author_id' => $authors[(int) ($p['author'] ?? 0)] ?? null,
                    'title' => $title,
                    'slug' => $slug,
                    'excerpt' => $excerpt,
                    'content' => $content,
                    'featured_image' => $image,
                    'status' => in_array($status, ['publish', 'future'], true) ? 'published' : 'draft',
                    'meta_title' => Str::limit((string) $seo->title, 250, '') ?: null,
                    'meta_description' => $seo->description,
                    'published_at' => ApiMap::date($p['date_gmt'] ?? null),
                    'created_at' => ApiMap::date($p['date_gmt'] ?? null) ?? $this->now(),
                    'updated_at' => ApiMap::date($p['modified_gmt'] ?? null) ?? $this->now(),
                ];
                $sources[$id] = ApiMap::path($p['link'] ?? null, $this->home);
            }
            if (! $this->images()) {
                $rows = array_map(fn ($r) => array_diff_key($r, ['featured_image' => 1]), $rows);
            }
            $rows = $this->withoutExisting($entity, 'posts', $rows);
            [$own] = $this->u->plan('posts', $rows);
            $foreign = $this->foreign('posts', 'slug', array_column($rows, 'slug'));
            foreach ($rows as $i => $row) {
                if (! isset($own[$row['wp_id']]) && isset($foreign[$row['slug']])) {
                    $rows[$i]['slug'] = $this->unique('posts', 'slug', $row['slug']);
                    $this->issue('warning', $entity, $row['wp_id'], "slug '{$row['slug']}' already belongs to another shop's post – imported as '{$rows[$i]['slug']}'");
                }
            }
            $this->transaction(function () use ($rows, $sources, $entity) {
                if (! $this->dryRun) {
                    $this->u->adopt('posts', $rows, 'slug');
                }
                $map = $this->saveRows($entity, 'posts', $rows, 'wp_id', ['created_at']);
                foreach ($rows as $row) {
                    $source = $sources[$row['wp_id']] ?? null;
                    if (isset($map[$row['wp_id']]) && $source !== null && $source !== 'blog/'.$row['slug']) {
                        $this->redirect($source, 'blog/'.$row['slug']);
                    }
                }
            });
        });
    }

    protected function importMedia(): void
    {
        $entity = 'media';
        if (! $this->images()) {
            $this->log->info('Media: "download images" is off – the media library is not imported.');
            $this->entity($entity, 'skipped');

            return;
        }
        $this->paged($entity, 'media', $this->wpQuery(['media_type' => 'image']), 'wp/v2', function (array $items) use ($entity) {
            foreach ($items as $m) {
                $before = [$this->media->downloaded, $this->media->reused];
                $path = $this->media->fetch((string) ($m['source_url'] ?? ''), (int) $m['id'], (string) ($m['alt_text'] ?? ''),
                    (string) ($m['title']['rendered'] ?? ''), $error);
                if ($path === null) {
                    $this->issue('warning', $entity, $m['id'] ?? null, 'not imported ('.$error.')');

                    continue;
                }
                $this->bump($entity, $this->media->downloaded > $before[0] || $this->dryRun ? 'created' : 'updated');
            }
        });
    }
}
