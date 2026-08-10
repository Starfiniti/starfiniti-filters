<?php

declare(strict_types=1);

use Starfiniti\Search\Domain\Support\CanonicalJson;

test('canonical JSON sorts object keys recursively and preserves lists', static function (): void {
    $first = ['z' => 1, 'a' => ['y' => 2, 'x' => 3], 'list' => [['b' => 2, 'a' => 1], 4]];
    $second = ['list' => [['a' => 1, 'b' => 2], 4], 'a' => ['x' => 3, 'y' => 2], 'z' => 1];
    assertSameValue(CanonicalJson::encode($first), CanonicalJson::encode($second), 'Equivalent maps must have identical canonical JSON.');
    assertSameValue('{"a":{"x":3,"y":2},"list":[{"a":1,"b":2},4],"z":1}', CanonicalJson::encode($first), 'Unexpected canonical JSON.');
});

