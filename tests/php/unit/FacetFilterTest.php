<?php

use Starfiniti\Search\Domain\Search\FacetFilter;

test('alternative facet counts remove only positive same-field shopper selections', static function (): void {
    $color = ['field' => 'attributes.color', 'op' => 'in', 'value' => ['Red']];
    $stock = ['field' => 'inventory.stock_status', 'op' => 'eq', 'value' => 'instock'];
    $price = ['field' => 'pricing.active_min_minor', 'op' => 'between', 'value' => [100, 2000]];
    assertSameValue(null, FacetFilter::withoutSelection($color, 'attributes.color'), 'Single facet should be removed.');
    assertSameValue(['and' => [$stock, $price]], FacetFilter::withoutSelection(['and' => [$color, ['and' => [$stock, $price]]]], 'attributes.color'), 'Other restrictions must remain.');
    foreach ([['not' => $color], ['or' => [$color, $stock]], ['field' => 'attributes.color', 'op' => 'not_in', 'value' => ['Secret']]] as $restriction) {
        assertSameValue($restriction, FacetFilter::withoutSelection($restriction, 'attributes.color'), 'Complex or negative restrictions must not be relaxed.');
    }
});
