<?php

namespace Pine\Commerce\Tests\Feature;

use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\Category;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Catalog\ProductListing;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/**
 * Search mode "precise" (config commerce.catalog.search): "black" finds black products – by name, SKU, colour or
 * category – not a navy one whose description mentions black; with no precise match it falls back to descriptions.
 */
class PreciseSearchTest extends TestCase
{
    use InstallsNeutralStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpNeutralStore();
        $make = fn (string $name, string $description = '') => Product::forceCreate(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'sku' => strtoupper(substr(md5($name), 0, 8)),
            'type' => 'simple', 'status' => 'published', 'regular_price' => 20, 'price' => 20, 'stock_status' => 'instock', 'description' => $description]);
        $make('Cotton Tee BLACK T015');
        $make('Cotton Tee NAVY T015', '<p>Also available in black, white and grey.</p>');
        $colourless = $make('Jersey Polo', '<p>Soft jersey.</p>');
        $colour = Attribute::create(['name' => 'Colour', 'slug' => 'colour', 'is_filterable' => true]);
        $colourless->attributeValues()->attach($colour->values()->create(['value' => 'Black', 'slug' => 'black'])->id);
        // WooCommerce tags imported as an attribute: search phrases, not facts about the product
        $tags = Attribute::create(['name' => 'Tags', 'slug' => 'tags', 'is_filterable' => false]);
        Product::where('name', 'Cotton Tee NAVY T015')->first()->attributeValues()->attach($tags->values()->create(['value' => 'CHEAP BIG BLACK COATS', 'slug' => 'cheap-big-black-coats'])->id);
        $make('Cord Shirt TAN', '<p>Made with a rare herringbone weave.</p>');
    }

    protected function tearDown(): void
    {
        $this->tearDownNeutralStore();
        parent::tearDown();
    }

    private function names(string $term): array
    {
        return ProductListing::make()->searching($term)->filteredQuery()->orderBy('name')->pluck('name')->all();
    }

    public function test_broad_search_also_matches_descriptions_as_before(): void
    {
        $this->assertSame(['Cotton Tee BLACK T015', 'Cotton Tee NAVY T015'], $this->names('black'), 'the navy tee by its description; the black polo (colour only) is missed');
    }

    public function test_precise_search_matches_names_and_colours_not_descriptions(): void
    {
        config(['commerce.catalog.search' => 'precise']);

        $this->assertSame(['Cotton Tee BLACK T015', 'Jersey Polo'], $this->names('black'), 'the navy tee only mentions black in its description and its tags');
        $this->assertSame(['Cotton Tee BLACK T015'], $this->names('black t015'), 'every word must match');
        $this->assertSame(['Cord Shirt TAN'], $this->names('herringbone'), 'nothing precise: falls back to descriptions');
        $this->assertSame('precise', ProductListing::make()->searching('black')->searchMode());
        $this->assertSame('broad', ProductListing::make()->searching('herringbone')->searchMode());

        $json = $this->getJson(route('search.suggest', ['q' => 'black']))->assertOk()->json('products');
        $this->assertNotContains('Cotton Tee Navy T015', array_column($json, 'name'), 'live suggestions follow the same rule');
    }
}
