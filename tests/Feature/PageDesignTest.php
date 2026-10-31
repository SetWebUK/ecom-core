<?php

namespace Pine\Commerce\Tests\Feature;

use Pine\Commerce\Content\PageDesign;
use Pine\Commerce\Models\Page;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * The visual page builder (1.11): a full-screen designer per page; what it saves is cleaned (no scripts, event
 * handlers, javascript: links or foreign iframes) and its CSS scoped to .pb-page; the shop shows the design with
 * live products and the contact form; "Use the text editor instead" brings the page's text back.
 */
class PageDesignTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function aboutPage(array $attributes = []): Page
    {
        return Page::forceCreate($attributes + ['title' => 'About us', 'slug' => 'about-us', 'path' => 'about-us', 'template' => 'default',
            'status' => 'published', 'content' => '<p>We have sold big suits since 1986.</p>']);
    }

    public function test_html_is_cleaned_and_css_is_scoped_to_the_page(): void
    {
        $html = PageDesign::cleanHtml('<section class="pb-section" onclick="steal()"><h1>Hi</h1><script>alert(1)</script>'
            .'<a href="javascript:alert(1)" target="_blank">x</a><a href="/shop/">Shop</a><a href="mailto:a@b.test">Mail</a>'
            .'<img src="/storage/a.jpg" alt="A"><img src="https://cdn.example.test/b.jpg" onerror="x()">'
            .'<img src="data:image/svg+xml;base64,PHN2Zy8+" alt=""><img src="data:text/html;base64,PHN2Zy8+">'
            .'<iframe src="https://evil.example.test/"></iframe><iframe srcdoc="<b>x</b>"></iframe>'
            .'<div class="pb-embed" data-pb="video"><iframe class="pb-video" src="https://www.youtube-nocookie.com/embed/abcdefghijk"></iframe></div>'
            .'<div class="pb-embed" data-pb="map"><iframe src="https://maps.google.com/maps?q=Chester&amp;output=embed"></iframe></div>'
            .'<div id="p1" class="pb-products" data-pb="products" data-source="category" data-category="7" data-limit="99"><span>preview</span></div>'
            .'<div class="pb-contact" data-pb="contact-form"></div><p style="color:red;background:url(javascript:x)">Café – £5</p>'
            .'<svg><a><animate attributeName="href" values="javascript:alert(2)"/><text>Hi</text></a></svg>'
            .'<style>body{display:none}</style></section>');

        $this->assertStringNotContainsString('script', $html);
        $this->assertStringNotContainsString('animate', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('evil.example', $html);
        $this->assertStringNotContainsString('srcdoc', $html);
        $this->assertStringNotContainsString('display:none', $html);
        $this->assertStringNotContainsString('data:text/html', $html);
        $this->assertStringContainsString('<a target="_blank" rel="noopener">x</a>', $html);
        $this->assertStringContainsString('<a href="/shop/">Shop</a>', $html);
        $this->assertStringContainsString('href="mailto:a@b.test"', $html);
        $this->assertStringContainsString('<img src="/storage/a.jpg" alt="A" decoding="async">', $html); // the first picture loads straight away
        $this->assertStringContainsString('<img src="https://cdn.example.test/b.jpg" loading="lazy" decoding="async">', $html);
        $this->assertStringContainsString('src="data:image/svg+xml;base64,PHN2Zy8+"', $html);
        $this->assertStringContainsString('<iframe class="pb-video" src="https://www.youtube-nocookie.com/embed/abcdefghijk" loading="lazy"></iframe>', $html);
        $this->assertStringContainsString('src="https://maps.google.com/maps?q=Chester&amp;output=embed"', $html);
        $this->assertStringContainsString('<div id="p1" class="pb-products" data-pb="products" data-source="category" data-category="7" data-limit="99">'
            .'<div data-component="product-grid" data-source="category" data-category="7" data-limit="24" data-columns="4"></div></div>', $html);
        $this->assertStringContainsString('<div class="pb-contact" data-pb="contact-form"><div data-component="contact-form"></div></div>', $html);
        $this->assertStringContainsString('<p style="color:red">Café – £5</p>', $html);

        $css = PageDesign::cleanCss('@import url(https://evil.example.test/x.css); body{background-color:var(--c-surface)} #a:hover, .b :is(.c, .d){color:red}'
            .'/* note */ @media (max-width: 767px){#a{font-size:2rem;background:url("data:image/png;base64,iVBO;RK")}} html body #x{width:expression(alert(1));margin:0}'
            .'#y{background-image:url(javascript:alert(1))} @font-face{font-family:Brand;src:url(/fonts/brand.woff2)} @keyframes spin{to{transform:rotate(1turn)}}'
            .'@page{margin:0} * {box-sizing:border-box} #z{content:"</style><script>"}');

        $this->assertSame('.pb-page{background-color:var(--c-surface)}.pb-page #a:hover, .pb-page .b :is(.c, .d){color:red}'
            .'@media (max-width: 767px){.pb-page #a{font-size:2rem;background:url("data:image/png;base64,iVBO;RK")}}.pb-page #x{margin:0}'
            .'@font-face{font-family:Brand;src:url(/fonts/brand.woff2)}@keyframes spin{to{transform:rotate(1turn)}}.pb-page *{box-sizing:border-box}', $css);
    }

    public function test_the_builder_opens_with_the_shop_look_and_the_page_text(): void
    {
        $page = $this->aboutPage();
        $this->actingAs($this->neutralAdmin());

        $html = $this->get(route('admin.pages.design', $page))->assertOk()->getContent();
        $this->assertStringContainsString('grapesjs-0.23.6/grapes.min.js', $html);
        $this->assertStringContainsString('id="pb-config"', $html);
        $this->assertStringContainsString('How would you like to start?', $html);
        preg_match('#<script type="application/json" id="pb-config">(.*?)</script>#s', $html, $m);
        $config = json_decode($m[1], true);
        $this->assertNull($config['project']);
        $this->assertStringContainsString('<h1>About us</h1>', $config['starter']);
        $this->assertStringContainsString('We have sold big suits since 1986.', $config['starter']);
        $this->assertSame('var(--c-primary)', $config['palette'][0]['value']);
        $this->assertStringContainsString('/admin/pages/design.css', implode(' ', $config['styles']));

        $css = $this->get(route('admin.pages.design.css'))->assertOk();
        $this->assertStringStartsWith('text/css', $css->headers->get('Content-Type'));
        $this->assertStringContainsString('--c-primary:', $css->getContent());

        // the home page is built from its own sections
        $home = Page::where('path', '')->firstOrFail();
        $this->get(route('admin.pages.design', $home))->assertRedirect(route('admin.pages.edit', $home));

        // the page form offers the builder; a new page can go straight to it
        $this->assertStringContainsString('Design this page', $this->get(route('admin.pages.edit', $page))->getContent());
        $this->assertStringContainsString('Save and design', $this->get(route('admin.pages.create'))->getContent());
        $response = $this->post(route('admin.pages.store'), ['title' => 'Summer sale', 'slug' => 'summer-sale', 'template' => 'default',
            'status' => 'draft', 'content' => '', 'content_changed' => 1, 'after' => 'design']);
        $response->assertRedirect(route('admin.pages.design', Page::where('slug', 'summer-sale')->firstOrFail()));

        // staff only
        auth()->logout();
        $this->get(route('admin.pages.design', $page))->assertRedirect();
        $this->putJson(route('admin.pages.design.update', $page), ['project' => '{"pages":[]}', 'html' => '', 'css' => ''])->assertStatus(401);
    }

    public function test_a_saved_design_is_what_customers_see(): void
    {
        [$product, $category] = $this->catalogue();
        $page = $this->aboutPage(['status' => 'draft']);
        $this->actingAs($this->neutralAdmin());

        $this->putJson(route('admin.pages.design.update', $page), ['project' => 'not json', 'html' => '', 'css' => ''])->assertStatus(422);

        $project = ['pages' => [['component' => ['type' => 'wrapper']]], 'styles' => []];
        $this->putJson(route('admin.pages.design.update', $page), [
            'project' => json_encode($project),
            'html' => '<section class="pb-section pb-hero" id="hero"><h1>Big on style</h1><a class="pb-btn" href="/shop/" onclick="x()">Shop now</a></section>'
                .'<section class="pb-section"><div class="pb-products" data-pb="products" data-source="category" data-category="'.$category->id.'" data-limit="4" data-columns="3"></div>'
                .'<div class="pb-contact" data-pb="contact-form"></div></section><script>alert(1)</script>',
            'css' => '#hero{background-image:url("/storage/uploads/hero.jpg");--pb-overlay:.25} body{background-color:#fafafa}',
            'publish' => true,
        ])->assertOk()->assertJson(['message' => 'Design saved and published', 'status' => 'published']);

        $page->refresh();
        $this->assertTrue($page->isDesigned());
        $this->assertSame($project, $page->design);
        $this->assertSame('published', $page->status);
        $this->assertSame('.pb-page #hero{background-image:url("/storage/uploads/hero.jpg");--pb-overlay:.25}.pb-page{background-color:#fafafa}', $page->design_css);
        $this->assertStringNotContainsString('onclick', $page->design_html);
        $this->assertSame('<p>We have sold big suits since 1986.</p>', $page->content); // the text stays, for switching back

        auth()->logout();
        $html = $this->get($page->url)->assertOk()->getContent();
        $this->assertStringContainsString('<div class="pb-page"><section class="pb-section pb-hero" id="hero"><h1>Big on style</h1>', $html);
        $this->assertStringContainsString('<style>.pb-page #hero{background-image:url("/storage/uploads/hero.jpg")', $html);
        $this->assertStringContainsString('page-design.css', $html);
        $this->assertStringContainsString('product-grid product-grid--3 pb-product-grid', $html);
        $this->assertStringContainsString($product->name, $html);
        $this->assertStringContainsString('contact-form', $html);
        $this->assertStringNotContainsString('alert(1)', $html);
        $this->assertStringNotContainsString('We have sold big suits', $html);
        $this->assertStringContainsString('<meta name="description" content="Big on style Shop now', $html); // excerpt of the design

        // the admin page form switches the text editor off and offers the way back
        $this->actingAs($this->neutralAdmin());
        $form = $this->get(route('admin.pages.edit', $page))->assertOk()->getContent();
        $this->assertStringContainsString('Designed with the visual builder', $form);
        $this->assertStringNotContainsString('name="content_changed"', $form);
        $config = $this->get(route('admin.pages.design', $page))->getContent();
        $this->assertStringContainsString('"project":{"pages"', $config);

        // saving the page settings keeps the design
        $this->put(route('admin.pages.update', $page), ['title' => 'About us', 'slug' => 'about-us', 'template' => 'default', 'status' => 'published'])
            ->assertRedirect();
        $this->assertTrue($page->fresh()->isDesigned());

        // "Use the text editor instead"
        $this->delete(route('admin.pages.design.destroy', $page))->assertRedirect(route('admin.pages.edit', $page));
        $this->assertNull($page->fresh()->design_html);
        auth()->logout();
        $this->assertStringContainsString('We have sold big suits since 1986.', $this->get($page->url)->assertOk()->getContent());
    }

    public function test_product_grid_sources(): void
    {
        [$product, $category] = $this->catalogue();

        $this->assertSame([$product->id], PageDesign::products(['source' => 'category', 'category' => $category->id])->pluck('id')->all());
        $this->assertSame([], PageDesign::products(['source' => 'category', 'category' => 0])->pluck('id')->all());
        $this->assertSame([$product->id], PageDesign::products(['source' => 'sale'])->pluck('id')->all());
        $this->assertSame([$product->id], PageDesign::products(['source' => 'newest', 'limit' => 500])->pluck('id')->all());
        $this->assertSame([], PageDesign::products(['source' => 'featured'])->pluck('id')->all());
        $product->forceFill(['sale_price' => null])->save();
        $this->assertSame([], PageDesign::products(['source' => 'sale'])->pluck('id')->all());
        // an empty grid prints nothing
        $this->assertSame('', trim(\Pine\Commerce\View\Components\PageContent::expandShortcodes('<div data-component="product-grid" data-source="featured"></div>', [], false)));
    }
}
