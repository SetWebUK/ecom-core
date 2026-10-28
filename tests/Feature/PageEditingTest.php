<?php

namespace Pine\Commerce\Tests\Feature;

use Pine\Commerce\Models\Page;
use Pine\Commerce\Models\Post;
use Pine\Commerce\Models\Redirect;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;
use Pine\Commerce\View\Components\PageContent;

/**
 * Easier page editing (1.10): imported pages open without the old page builder's [vc_row …] code (the HTML customers
 * already see), encoded raw HTML / Google maps are unpacked, the Google preview reads plain text, and a page whose
 * address is redirected says so.
 */
class PageEditingTest extends TestCase
{
    use InstallsNeutralStore;

    /** WPBakery content as the WooCommerce API returns it: texturized quotes, an encoded map and an encoded raw HTML block. */
    protected const LEGACY = '<div class="wpb-content-wrapper"><p>[vc_row bg_type=&#8221;no_bg&#8221; el_class=&#8221;margin-top-30&#8243;]'
        .'[vc_column width=&#8221;5/12&#8243;][vc_gmaps link=&#8221;#E-8_%MAP%&#8221;][/vc_column][vc_column width=&#8221;7/12&#8243;]'
        .'<h4>STORE</h4>[vc_column_text css=&#8221;&#8221;]Unit 2 Big Yellow, Chester</p><p>Monday &#8211; Friday : 10am &#8211; 4pm[/vc_column_text]'
        .'[vc_empty_space height=&#8221;20px&#8221;][vc_raw_html]%RAW%[/vc_raw_html][/vc_column][/vc_row]</p></div>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        config(['commerce.features.legacy_content' => true, 'commerce.features.redirects' => true]);
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    protected function legacyHtml(string $mapSrc = 'https://www.google.com/maps/embed?pb=!1m18'): string
    {
        $encode = fn (string $html) => base64_encode(rawurlencode($html));

        return strtr(self::LEGACY, [
            '%MAP%' => $encode('<iframe src="'.$mapSrc.'" width="600" height="450" style="border:0;" loading="lazy"></iframe>'),
            '%RAW%' => $encode('<a href="https://www.feefo.com/reviews/shop"><img alt="Feefo logo" src="https://api.feefo.com/api/logo"></a>'),
        ]);
    }

    public function test_page_builder_code_is_tidied_into_the_html_customers_see(): void
    {
        $html = $this->legacyHtml();
        $tidy = PageContent::tidyForEditor($html);

        $this->assertNotNull($tidy);
        $this->assertFalse(PageContent::hasLegacyShortcodes($tidy));
        $this->assertStringContainsString('<h4>STORE</h4>', $tidy);
        $this->assertStringContainsString('Unit 2 Big Yellow, Chester', $tidy);
        $this->assertStringContainsString('<div class="wp-legacy-map" style="max-width:100%;overflow:hidden"><iframe src="https://www.google.com/maps/embed?pb=!1m18"', $tidy);
        $this->assertStringContainsString('<img alt="Feefo logo" src="https://api.feefo.com/api/logo">', $tidy);
        $this->assertStringNotContainsString('el_class', $tidy);
        // the storefront shows exactly the same page, so saving the tidy version changes nothing for customers
        $this->assertSame(PageContent::process($html), PageContent::process($tidy));
        // nothing to tidy -> null (the stored content is used as it is)
        $this->assertNull(PageContent::tidyForEditor('<p>Plain text</p>'));
        $this->assertNull(PageContent::tidyForEditor(null));
        // only Google map embeds are unpacked from [vc_gmaps]
        $this->assertStringNotContainsString('<iframe', PageContent::tidyForEditor($this->legacyHtml('https://evil.example.test/maps/')));
    }

    public function test_the_editor_opens_an_imported_page_without_page_builder_code(): void
    {
        $page = Page::forceCreate(['title' => 'Delivery', 'slug' => 'delivery', 'path' => 'delivery', 'template' => 'default',
            'status' => 'published', 'content' => $this->legacyHtml()]);

        $html = $this->actingAs($this->neutralAdmin())->get(route('admin.pages.edit', $page))->assertOk()->getContent();
        $this->assertStringContainsString('Old page-builder code hidden', $html);
        $this->assertStringContainsString('Unit 2 Big Yellow, Chester', $html);
        $this->assertStringNotContainsString('[vc_row bg_type', $html);
        $this->assertStringNotContainsString('vc_column', $html); // nor in the Google preview's fallback description
        $this->assertStringContainsString('STORE Unit 2 Big Yellow, Chester Monday – Friday : 10am – 4pm', PageContent::excerpt($page->content, 80, ''));
        $this->assertStringContainsString('STORE Unit 2 Big Yellow, Chester Monday', $html);

        // the tidy version is stored once the content is changed
        $this->put(route('admin.pages.update', $page), ['title' => 'Delivery', 'slug' => 'delivery', 'template' => 'default', 'status' => 'published',
            'content' => PageContent::tidyForEditor($page->content), 'content_changed' => 1])->assertRedirect(route('admin.pages.edit', $page));
        $this->assertFalse(PageContent::hasLegacyShortcodes($page->fresh()->content));
        $this->assertStringNotContainsString('Old page-builder code hidden', $this->get(route('admin.pages.edit', $page))->getContent());

        // blog posts use the same editor
        $post = Post::forceCreate(['title' => 'Old news', 'slug' => 'old-news', 'status' => 'published', 'published_at' => now(), 'content' => $this->legacyHtml()]);
        $html = $this->get(route('admin.posts.edit', $post))->assertOk()->getContent();
        $this->assertStringContainsString('This post came from the old website', $html);
        $this->assertStringNotContainsString('[vc_row bg_type', $html);
    }

    public function test_a_redirected_address_is_explained_on_the_page(): void
    {
        $contact = Page::where('path', 'contact')->firstOrFail(); // the neutral store's own contact page
        $old = Page::forceCreate(['title' => 'CONTACT BIGMEN', 'slug' => 'old-contact', 'path' => 'old-contact', 'template' => 'default', 'status' => 'draft', 'content' => '<p>Old</p>']);
        Redirect::create(['from_path' => 'old-contact', 'to_url' => '/contact/', 'status_code' => 301]);
        $this->actingAs($this->neutralAdmin());

        $html = $this->get(route('admin.pages.edit', $old))->assertOk()->getContent();
        $this->assertStringContainsString('Visitors to this address are sent to '.$contact->title.' (/contact/)', $html);
        $this->assertStringContainsString(route('admin.pages.edit', $contact), $html);

        $old->forceFill(['status' => 'published'])->save();
        $this->assertStringContainsString('This page is shown instead of a redirect', $this->get(route('admin.pages.edit', $old))->getContent());

        // no notice without a redirect, or with the redirects feature switched off
        $this->assertStringNotContainsString('redirect sends', $this->get(route('admin.pages.edit', $contact))->getContent());
        config(['commerce.features.redirects' => false]);
        $this->assertStringNotContainsString('redirect sends', $this->get(route('admin.pages.edit', $old))->getContent());
    }
}
