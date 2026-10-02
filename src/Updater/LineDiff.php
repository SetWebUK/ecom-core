<?php

namespace Pine\Commerce\Updater;

/**
 * A small line diff for the skeleton update screen: added/removed line counts and a unified diff (3 lines of
 * context). Common head and tail lines are skipped first; very large middles fall back to counts only.
 */
final class LineDiff
{
    /** Cells of the LCS table above which only counts are produced. */
    public const MAX_CELLS = 400000;

    /** @return array{added:int, removed:int, diff:?string} */
    public static function compare(string $old, string $new, int $maxLines = 300): array
    {
        $a = $old === '' ? [] : explode("\n", rtrim(str_replace("\r\n", "\n", $old), "\n"));
        $b = $new === '' ? [] : explode("\n", rtrim(str_replace("\r\n", "\n", $new), "\n"));

        $start = 0;
        while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) {
            $start++;
        }
        $endA = count($a);
        $endB = count($b);
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            $endA--;
            $endB--;
        }
        $midA = array_slice($a, $start, $endA - $start);
        $midB = array_slice($b, $start, $endB - $start);

        if (count($midA) * count($midB) > self::MAX_CELLS) {
            return ['added' => count(array_diff($midB, $midA)), 'removed' => count(array_diff($midA, $midB)), 'diff' => null];
        }

        // LCS table on the middle part
        $n = count($midA);
        $m = count($midB);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $midA[$i] === $midB[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        // edit script over the whole file: [op, line, oldNo, newNo]
        $ops = [];
        for ($k = 0; $k < $start; $k++) {
            $ops[] = [' ', $a[$k], $k + 1, $k + 1];
        }
        $i = $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $midA[$i] === $midB[$j]) {
                $ops[] = [' ', $midA[$i], $start + $i + 1, $start + $j + 1];
                $i++;
                $j++;
            } elseif ($j < $m && ($i === $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $ops[] = ['+', $midB[$j], null, $start + $j + 1];
                $j++;
            } else {
                $ops[] = ['-', $midA[$i], $start + $i + 1, null];
                $i++;
            }
        }
        for ($k = 0; $k < count($a) - $endA; $k++) {
            $ops[] = [' ', $a[$endA + $k], $endA + $k + 1, $endB + $k + 1];
        }

        $added = count(array_filter($ops, fn ($o) => $o[0] === '+'));
        $removed = count(array_filter($ops, fn ($o) => $o[0] === '-'));

        return ['added' => $added, 'removed' => $removed, 'diff' => static::unified($ops, $maxLines)];
    }

    /** @param list<array{0:string,1:string,2:?int,3:?int}> $ops */
    protected static function unified(array $ops, int $maxLines): ?string
    {
        $changed = array_keys(array_filter($ops, fn ($o) => $o[0] !== ' '));
        if (! $changed) {
            return null;
        }
        $keep = [];
        foreach ($changed as $index) {
            for ($k = max(0, $index - 3); $k <= min(count($ops) - 1, $index + 3); $k++) {
                $keep[$k] = true;
            }
        }
        ksort($keep);
        $out = [];
        $previous = null;
        foreach (array_keys($keep) as $k) {
            if ($previous === null || $k !== $previous + 1) {
                $out[] = '@@ line '.($ops[$k][2] ?? $ops[$k][3]).' @@';
            }
            $out[] = $ops[$k][0].$ops[$k][1];
            $previous = $k;
            if (count($out) >= $maxLines) {
                $out[] = '… (diff shortened)';
                break;
            }
        }

        return implode("\n", $out);
    }
}
