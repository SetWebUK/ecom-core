<?php

namespace Pine\Commerce\Http\Controllers;

use Pine\Commerce\Models\Page;
use Pine\Commerce\Services\Admin\PageBlocks;
use Pine\Commerce\View\Components\Seo;
use Pine\Commerce\View\Components\PageContent;
use Illuminate\Http\Request;

/**
 * Content pages. Called by ResolveController for any path that matches a Page:
 *     return app(PageController::class)->show($page);
 *
 * Templates (pages.template):
 *   default / full-width  - stored HTML (Elementor pages render full width, classic pages get the theme title header)
 *   contact               - as default, and guarantees the enquiry form is present
 *   faq                   - accordion built from blocks.faq[] (legacy WPBakery accordions in the content render as toggles)
 *   {theme template}      - a template from the theme's config/blocks.php: theme view pages.{key} (+ $blocks)
 *   home / blog           - delegated to HomeController / BlogController
 */
class PageController extends Controller
{
    public function show(Page $page)
    {
        $request = request();
        if ($page->status !== 'published' && ! $this->canPreview($request)) {
            abort(404);
        }
        if ($page->template === 'home' || $page->path === '') {
            return app(HomeController::class)->index($request);
        }
        if ($page->template === 'blog' || $page->path === 'blog') {
            return app(BlogController::class)->index($request);
        }

        $html = PageContent::process((string) $page->content, $page);
        $isElementor = PageContent::isElementor($html);
        if (! $isElementor) {
            // imported classic pages start with a heading repeating the page title - the theme title already shows it
            $html = preg_replace_callback('#^\s*<h2 class="page-heading">(.*?)</h2>#s', function ($m) use ($page) {
                $heading = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                return mb_strtolower($heading) === mb_strtolower(trim($page->title)) ? '' : $m[0];
            }, $html, 1);
        }
        $template = $page->template ?: 'default';

        $data = [
            'page' => $page,
            'contentHtml' => $html,
            'isElementor' => $isElementor,
            // WordPress printed the page title above Elementor layouts unless "Hide Title" was set (all but a few
            // pages hide it) - blocks.show_title = true reproduces the title for those pages.
            'showTitle' => ! $isElementor || (bool) $page->block('show_title', false),
            'pageCss' => $this->pageCss($page),
            'seo' => $this->seo($page, $html),
            'bodyClass' => $this->bodyClass($page, $isElementor),
        ];

        if ($template === 'faq' && ! $isElementor) {
            $data['faqs'] = $this->faqItems($page);
            if ($data['faqs']) {
                return theme_view('pages.faq', $data);
            }
        }
        if ($template === 'contact') {
            $data['hasForm'] = (bool) preg_match('/class="[^"]*contact-form/', $html); // the contact_form shortcode's form (partials.contact-form)

            return theme_view('pages.contact', $data);
        }

        // a client template (Commerce::pageTemplate() with a 'view') renders that view with the page's blocks
        $registered = app(\Pine\Commerce\Extensions\ExtensionRegistry::class)->pageTemplates()[$template]['meta']['view'] ?? null;
        if (is_string($registered) && view()->exists($registered)) {
            return view($registered, $data + ['blocks' => is_array($page->blocks) ? $page->blocks : []]);
        }
        // a theme template (theme config blocks.templates) renders its own view with the page's blocks
        if (! PageBlocks::isCore($template) && array_key_exists($template, PageBlocks::templates()) && view()->exists('pages.'.$template)) {
            return theme_view('pages.'.$template, $data + ['blocks' => is_array($page->blocks) ? $page->blocks : []]);
        }

        return theme_view('pages.default', $data);
    }

    /** Legacy Elementor CSS for this page (theme asset css/elementor/post-{wp id}.css, published by the importer), if any. */
    protected function pageCss(Page $page): ?string
    {
        if (! $page->wp_id || ! commerce_feature('legacy_content')) {
            return null;
        }
        $relative = app(\Pine\Commerce\Theme\ThemeManager::class)->publishedAsset('css/elementor/post-'.(int) $page->wp_id.'.css');

        return $relative !== null ? asset($relative).'?v='.filemtime(public_path($relative)) : null;
    }

    protected function seo(Page $page, string $html = ''): array
    {
        // Rank Math (legacy SEO plugin) tagged pages og:type "article" and used the first content image for og:image
        $image = preg_match('#<img[^>]+src="[^"]*?(/storage/uploads/[^"]+\.(?:jpe?g|png|webp))"#i', $html, $m)
            ? url(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null;

        return array_filter([
            'title' => $page->meta_title ?: Seo::title($page->title),
            'description' => $page->meta_description ?: PageContent::excerpt($page->content, 28, ''),
            'canonical' => $page->url,
            'noindex' => (bool) $page->noindex,
            'modified_time' => optional($page->updated_at)->toIso8601String(),
            'type' => 'article',
            'image' => $image,
        ], fn ($v) => $v !== null);
    }

    protected function bodyClass(Page $page, bool $isElementor): string
    {
        $id = $page->wp_id ?: $page->id;
        $classes = ['page-template-default', 'page', 'page-id-'.$id, 'page-'.$page->slug];
        if ($page->path === 'privacy-policy') {
            $classes[] = 'privacy-policy';
        }
        if ($isElementor) {
            $classes[] = 'elementor-page';
            $classes[] = 'elementor-page-'.$id;
        }

        return implode(' ', $classes);
    }

    /** Structured FAQ items from $page->blocks['faq'] = [['question' => ..., 'answer' => html], ...]. */
    protected function faqItems(Page $page): array
    {
        return collect($page->block('faq', []))
            ->filter(fn ($i) => is_array($i) && filled($i['question'] ?? null))
            ->map(fn ($i) => ['question' => (string) $i['question'], 'answer' => (string) ($i['answer'] ?? '')])
            ->values()->all();
    }

    protected function canPreview(Request $request): bool
    {
        $user = $request->user();

        return $user && $user->canAccessAdmin();
    }
}
