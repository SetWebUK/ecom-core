<?php

namespace Pine\Commerce\Tests\Feature;

use Pine\Commerce\Models\Attribute;
use Pine\Commerce\Models\Product;
use Pine\Commerce\Services\Admin\Catalogue\ProductCsv\Exporter;
use Pine\Commerce\Tests\Concerns\InstallsNeutralStore;
use Pine\Commerce\Tests\TestCase;

/** Product labels: set per product or in bulk, exported to CSV, plus automatic labels from attribute values. */
class ProductLabelsTest extends TestCase
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

    public function test_labels_are_trimmed_de_duplicated_and_capped(): void
    {
        $this->assertSame(['New Season', 'special offer'], Product::normaliseLabels(' New Season, special  offer ,new season,, '), 'kept as typed, first spelling wins');
        $this->assertSame(['Special offer'], Product::normaliseLabels(['Special offer', 'SPECIAL OFFER']));
        $this->assertCount(6, Product::normaliseLabels(range(1, 9)));
    }

    public function test_bulk_add_and_remove_a_label(): void
    {
        [$product] = $this->catalogue();
        $other = Product::forceCreate(['name' => 'Chinos', 'slug' => 'chinos', 'type' => 'simple', 'status' => 'published', 'regular_price' => 30, 'labels' => ['Tall fit']]);
        $admin = $this->neutralAdmin();

        $this->actingAs($admin)->post(route('admin.products.bulk'), ['action' => 'add_label', 'label' => ' New Season ', 'ids' => [$product->id, $other->id]])->assertSessionHas('success');
        $this->assertSame(['New Season'], $product->fresh()->labels);
        $this->assertSame(['Tall fit', 'New Season'], $other->fresh()->labels);

        $this->actingAs($admin)->post(route('admin.products.bulk'), ['action' => 'remove_label', 'label' => 'new season', 'ids' => [$product->id, $other->id]])->assertSessionHas('success');
        $this->assertSame([], $product->fresh()->labels ?? []);
        $this->assertSame(['Tall fit'], $other->fresh()->labels);

        $this->actingAs($admin)->post(route('admin.products.bulk'), ['action' => 'add_label', 'label' => '', 'ids' => [$product->id]])->assertSessionHasErrors('label');
    }

    public function test_attribute_values_give_automatic_labels_and_the_csv_has_the_labels(): void
    {
        config(['commerce.catalog.labels.attributes' => ['fitting' => ['tall' => 'Available in Tall fitting']]]);
        [$product] = $this->catalogue();
        $fitting = Attribute::create(['name' => 'Fitting', 'slug' => 'fitting']);
        $tall = $fitting->values()->create(['value' => 'Tall', 'slug' => 'tall']);
        $product->attributeValues()->attach($tall->id);
        $product->forceFill(['labels' => ['New Season']])->save();

        $this->assertSame(['New Season', 'Available in Tall fitting'], commerce_presenter()::labels($product->fresh()));

        $exporter = new Exporter(false);
        $row = array_combine($exporter->headers(), iterator_to_array($exporter->rows(Product::query()->whereKey($product->id)))[0]);
        $this->assertSame('New Season', $row['labels']);
    }
}
