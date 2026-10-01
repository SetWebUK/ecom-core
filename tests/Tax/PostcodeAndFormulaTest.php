<?php

namespace Pine\Commerce\Tests\Tax;

use PHPUnit\Framework\Attributes\DataProvider;
use Pine\Commerce\Services\Shipping\CostExpression;
use Pine\Commerce\Support\PostcodeMatcher;
use Pine\Commerce\Tests\TestCase;

/** Postcode patterns (zones, tax rates) and flat-rate cost formulas – pure functions, no database. */
class PostcodeAndFormulaTest extends TestCase
{
    public static function postcodes(): array
    {
        return [
            'exact with spaces/case' => ['sw1a 1aa', 'SW1A1AA', 'GB', true],
            'outcode matches the whole outcode' => ['BT1 1AA', 'BT1', 'GB', true],
            'outcode is not a prefix match' => ['BT12 1AA', 'BT1', 'GB', false],
            'wildcard area' => ['BT48 7NN', 'BT*', 'GB', true],
            'wildcard other area' => ['B1 1AA', 'BT*', 'GB', false],
            'district range inside' => ['HS2 9AB', 'HS1-HS9', 'GB', true],
            'district range bounds' => ['HS9 1AA', 'HS1-HS9', 'GB', true],
            'district range excludes HS10' => ['HS10 1AA', 'HS1-HS9', 'GB', false],
            'district range other area' => ['KW1 1AA', 'HS1-HS9', 'GB', false],
            'partial postcode in range' => ['IV51', 'IV40-IV56', 'GB', true],
            'numeric range (WooCommerce)' => ['90210', '90000...90299', 'US', true],
            'numeric range outside' => ['90300', '90000...90299', 'US', false],
            'numeric range with dash' => ['12345', '10000-19999', 'DE', true],
            'single character wildcard' => ['JE2 3AB', 'JE? 3AB', 'JE', true],
        ];
    }

    #[DataProvider('postcodes')]
    public function test_postcode_patterns(string $postcode, string $pattern, string $country, bool $expected): void
    {
        $this->assertSame($expected, PostcodeMatcher::matches($postcode, $pattern, $country));
    }

    public function test_pattern_lists_and_exclusions(): void
    {
        $highlands = ['IV*', 'KW*', 'PH17-PH26', '!IV1', '!IV2'];
        $this->assertTrue(PostcodeMatcher::matchesAny('IV51 9XX', $highlands, 'GB'));
        $this->assertTrue(PostcodeMatcher::matchesAny('PH20 1AA', $highlands, 'GB'));
        $this->assertFalse(PostcodeMatcher::matchesAny('PH1 1AA', $highlands, 'GB'));
        $this->assertFalse(PostcodeMatcher::matchesAny('IV1 1AA', $highlands, 'GB'), 'excluded outcode');
        $this->assertFalse(PostcodeMatcher::matchesAny('', $highlands, 'GB'), 'no postcode never matches a postcode list');
        $this->assertTrue(PostcodeMatcher::matchesAny('EH1 1AA', ['!IV*'], 'GB'), 'only exclusions: everything else matches');
        $this->assertSame('SW1A', PostcodeMatcher::outcode('sw1a1aa', 'GB'));
        $this->assertNull(PostcodeMatcher::outcode('75001', 'FR'));
    }

    public function test_cost_formulas(): void
    {
        $this->assertSame(9.5, CostExpression::evaluate('5 + 1.50 * [qty]', 3, 40.0));
        $this->assertSame(4.0, CostExpression::evaluate('[cost] * 0.1', 1, 40.0));
        $this->assertSame(3.0, CostExpression::evaluate('[fee percent="5" min_fee="3"]', 1, 40.0), 'min fee');
        $this->assertSame(5.0, CostExpression::evaluate('[fee percent="10" max_fee="5"]', 1, 200.0), 'max fee');
        $this->assertSame(12.0, CostExpression::evaluate('2 * (3 + [qty])', 3, 0.0));
        $this->assertSame(0.0, CostExpression::evaluate('-5', 1, 0.0), 'never negative');
        $this->assertSame(7.5, CostExpression::evaluate('7,50', 1, 0.0), 'decimal comma');
        $this->assertTrue(CostExpression::valid(''));
        $this->assertTrue(CostExpression::valid('10 + [qty] * 2'));
        $this->assertFalse(CostExpression::valid('system("rm -rf /")'));
        $this->assertFalse(CostExpression::valid('10 / 0'));
        $this->assertFalse(CostExpression::valid('(5 + 2'));
        $this->assertSame(0.0, CostExpression::evaluate('phpinfo()', 1, 0.0));
    }
}
