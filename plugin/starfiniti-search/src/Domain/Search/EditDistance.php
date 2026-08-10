<?php

declare(strict_types=1);

namespace Starfiniti\Search\Domain\Search;

final class EditDistance
{
    public static function damerauLevenshtein(string $left, string $right, int $maximum): ?int
    {
        $maximum = max(0, min(2, $maximum));
        $a = preg_split('//u', mb_substr($left, 0, 64), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $b = preg_split('//u', mb_substr($right, 0, 64), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $m = count($a);
        $n = count($b);
        if (abs($m - $n) > $maximum) {
            return null;
        }
        $matrix = [];
        for ($i = 0; $i <= $m; ++$i) { $matrix[$i] = [$i]; }
        for ($j = 0; $j <= $n; ++$j) { $matrix[0][$j] = $j; }
        for ($i = 1; $i <= $m; ++$i) {
            $rowMinimum = $maximum + 1;
            for ($j = 1; $j <= $n; ++$j) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $distance = min($matrix[$i - 1][$j] + 1, $matrix[$i][$j - 1] + 1, $matrix[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $distance = min($distance, $matrix[$i - 2][$j - 2] + 1);
                }
                $matrix[$i][$j] = $distance;
                $rowMinimum = min($rowMinimum, $distance);
            }
            if ($rowMinimum > $maximum && $i > $n + $maximum) {
                return null;
            }
        }
        return $matrix[$m][$n] <= $maximum ? $matrix[$m][$n] : null;
    }
}
