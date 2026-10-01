<?php

namespace Pine\Commerce\Services\Shipping;

/**
 * Flat-rate cost formulas (WooCommerce syntax): numbers, + − * / and brackets, plus
 *
 *   [qty]                                   number of items
 *   [cost]                                  value of the items (as entered)
 *   [fee percent="10" min_fee="2" max_fee="20"]  percentage of [cost], clamped
 *
 * e.g. "5 + 1.50 * [qty]" or "[fee percent=\"5\" min_fee=\"3\"]". Anything else is rejected (valid() = false) and
 * evaluates to 0 – the formula is never passed to eval().
 */
class CostExpression
{
    public static function evaluate(?string $expression, int $qty = 1, float $cost = 0.0): float
    {
        $value = static::compute((string) $expression, $qty, $cost);

        return $value === null ? 0.0 : max(0.0, round($value, 2));
    }

    public static function valid(?string $expression): bool
    {
        return trim((string) $expression) === '' || static::compute((string) $expression, 1, 1.0) !== null;
    }

    protected static function compute(string $expression, int $qty, float $cost): ?float
    {
        $expression = trim($expression);
        if ($expression === '') {
            return 0.0;
        }
        $expression = (string) preg_replace_callback('/\[fee([^\]]*)\]/i', function ($m) use ($cost) {
            $args = [];
            preg_match_all('/(\w+)\s*=\s*["\']?([0-9.]*)["\']?/', $m[1], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $args[strtolower($pair[1])] = $pair[2] === '' ? null : (float) $pair[2];
            }
            $fee = $cost * (float) ($args['percent'] ?? 0) / 100;
            if (($args['min_fee'] ?? null) !== null && $fee < $args['min_fee']) {
                $fee = $args['min_fee'];
            }
            if (($args['max_fee'] ?? null) !== null && $args['max_fee'] > 0 && $fee > $args['max_fee']) {
                $fee = $args['max_fee'];
            }

            return '('.sprintf('%.6F', $fee).')';
        }, $expression);
        $expression = str_ireplace(['[qty]', '[cost]'], ['('.$qty.')', '('.sprintf('%.6F', $cost).')'], $expression);
        $expression = str_replace(',', '.', $expression);
        if (! preg_match('/^[0-9.+\-*\/()\s]+$/', $expression)) {
            return null;
        }
        $tokens = preg_split('/\s*([+\-*\/()])\s*|\s+/', $expression, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $pos = 0;
        try {
            $value = static::sum($tokens, $pos);
        } catch (\Throwable) {
            return null;
        }

        return $pos === count($tokens) && is_finite($value) ? $value : null;
    }

    protected static function sum(array $t, int &$pos): float
    {
        $value = static::product($t, $pos);
        while (isset($t[$pos]) && ($t[$pos] === '+' || $t[$pos] === '-')) {
            $op = $t[$pos++];
            $right = static::product($t, $pos);
            $value = $op === '+' ? $value + $right : $value - $right;
        }

        return $value;
    }

    protected static function product(array $t, int &$pos): float
    {
        $value = static::factor($t, $pos);
        while (isset($t[$pos]) && ($t[$pos] === '*' || $t[$pos] === '/')) {
            $op = $t[$pos++];
            $right = static::factor($t, $pos);
            if ($op === '/' && $right == 0.0) {
                throw new \DivisionByZeroError;
            }
            $value = $op === '*' ? $value * $right : $value / $right;
        }

        return $value;
    }

    protected static function factor(array $t, int &$pos): float
    {
        $token = $t[$pos] ?? null;
        if ($token === '-') {
            $pos++;

            return -static::factor($t, $pos);
        }
        if ($token === '(') {
            $pos++;
            $value = static::sum($t, $pos);
            if (($t[$pos] ?? null) !== ')') {
                throw new \InvalidArgumentException('Missing )');
            }
            $pos++;

            return $value;
        }
        if ($token !== null && is_numeric($token)) {
            $pos++;

            return (float) $token;
        }
        throw new \InvalidArgumentException('Unexpected token');
    }
}
