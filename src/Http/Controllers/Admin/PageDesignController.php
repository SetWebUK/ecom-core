<?php

namespace Pine\Commerce\Http\Controllers\Admin;

use Pine\Commerce\Content\PageDesign;
use Pine\Commerce\Http\Controllers\Controller;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Theme\Storefront;
use Pine\Commerce\View\Components\Admin\IconSet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The visual page builder (GrapesJS, admin/js/builder.js): a full-screen designer for one page. The canvas shows the
 * page with the shop's own CSS (theme editor_css + css/page-design.css + the colours and fonts from Admin › Settings ›
 * Theme), so what staff build is what customers see. Saving stores the project data, cleaned HTML and scoped CSS
 * (Content\PageDesign); "Use the text editor instead" removes the design and the page shows its content again.
 */
class PageDesignController extends Controller
{
    /** Heroicons the builder's blocks and placeholders use. */
    public const ICONS = ['photo', 'rectangle-group', 'squares-2x2', 'megaphone', 'chat-bubble-left-right', 'question-mark-circle', 'document-text',
        'shopping-bag', 'envelope', 'rectangle-stack', 'view-columns', 'arrows-up-down', 'minus', 'h1', 'bars-3-bottom-left', 'list-bullet',
        'cursor-arrow-rays', 'play-circle', 'map', 'truck', 'arrow-path', 'chat-bubble-left-ellipsis'];

    public function edit(Page $page): View|RedirectResponse
    {
        if (! $this->designable($page)) {
            return redirect()->route('admin.pages.edit', $page)->with('error', 'The home page and the blog page are built their own way, not with the visual builder.');
        }

        $styles = theme()->editorCssUrls(['css/page-design.css']);
        // Admin › Settings › Theme colours and fonts come after the theme's base CSS (app.css), as on the site
        array_splice($styles, min(1, count($styles)), 0, [route('admin.pages.design.css', ['v' => substr(md5(Storefront::cssVariables().Storefront::fontFaces()), 0, 10)])]);

        return view('commerce::admin.pages.design', [
            'page' => $page,
            'config' => [
                'project' => $page->design,
                'starter' => $page->design ? null : PageDesign::starter($page),
                'styles' => $styles,
                'saveUrl' => route('admin.pages.design.update', $page),
                'pageUrl' => $page->url,
                'status' => $page->status,
                // theme colours are saved as var(--c-…), so a design follows later changes in Admin › Settings › Theme
                'palette' => [
                    ['label' => 'Brand colour', 'value' => 'var(--c-primary)', 'hex' => Storefront::color('primary_color', '#1d4ed8')],
                    ['label' => 'Accent colour', 'value' => 'var(--c-accent)', 'hex' => Storefront::color('accent_color', '#e11d48')],
                    ['label' => 'Text colour', 'value' => 'var(--c-text)', 'hex' => Storefront::color('text_color', '#0f172a')],
                    ['label' => 'Panel colour', 'value' => 'var(--c-surface)', 'hex' => Storefront::color('surface_color', '#f4f5f7')],
                    ['label' => 'White', 'value' => '#ffffff', 'hex' => '#ffffff'],
                    ['label' => 'Black', 'value' => '#000000', 'hex' => '#000000'],
                ],
                'productSources' => PageDesign::PRODUCT_SOURCES,
                'categories' => Category::query()->orderBy('name')->limit(500)->pluck('name', 'id')->all(),
                'mapAddress' => trim((string) preg_replace('/\s*[\r\n]+\s*/', ', ', strip_tags((string) setting('store.address', ''))), ', '),
                'icons' => collect(self::ICONS)->mapWithKeys(fn (string $name) => [$name => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" '
                    .'stroke="currentColor" stroke-width="1.5" aria-hidden="true">'.IconSet::svg($name).'</svg>'])->all(),
            ],
        ]);
    }

    public function update(Request $request, Page $page): JsonResponse
    {
        abort_unless($this->designable($page), 422, 'This page can’t be designed in the visual builder.');
        $data = $request->validate([
            'project' => ['required', 'string', 'max:'.PageDesign::MAX_PROJECT],
            'html' => ['present', 'nullable', 'string', 'max:'.PageDesign::MAX_HTML],
            'css' => ['present', 'nullable', 'string', 'max:'.PageDesign::MAX_CSS],
            'publish' => ['sometimes', 'boolean'],
        ], [
            'project.max' => 'This design is too big to save. Remove some sections or large pictures and try again.',
            'html.max' => 'This design is too big to save. Remove some sections and try again.',
        ]);
        $project = json_decode($data['project'], true);
        if (! is_array($project) || ! isset($project['pages'])) {
            return response()->json(['message' => 'The design could not be read. Reload the builder and try again.'], 422);
        }

        $page->forceFill([
            'design' => $project,
            'design_html' => PageDesign::cleanHtml((string) $data['html']),
            'design_css' => PageDesign::cleanCss((string) $data['css']),
        ]);
        if ($request->boolean('publish')) {
            $page->status = 'published';
        }
        $page->save();

        return response()->json([
            'message' => $request->boolean('publish') ? 'Design saved and published' : 'Design saved',
            'status' => $page->status,
        ]);
    }

    public function destroy(Page $page): RedirectResponse
    {
        $page->forceFill(['design' => null, 'design_html' => null, 'design_css' => null])->save();

        return redirect()->route('admin.pages.edit', $page)->with('success', 'The design was removed. The page shows the text below again.');
    }

    /** The shop's colours and fonts (Admin › Settings › Theme) for the builder's canvas. */
    public function css(): Response
    {
        return response(Storefront::fontFaces().Storefront::cssVariables(), 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    protected function designable(Page $page): bool
    {
        return $page->template !== 'home' && $page->template !== 'blog' && $page->path !== '' && $page->path !== 'blog';
    }
}
