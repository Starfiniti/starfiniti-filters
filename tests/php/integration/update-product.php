<?php

$entityId = (int) (getenv('STARFINITI_TEST_ENTITY_ID') ?: 0);
$name = (string) (getenv('STARFINITI_TEST_PRODUCT_NAME') ?: '');
$product = wc_get_product($entityId);
if (!$product instanceof \WC_Product || $name === '') {
    throw new RuntimeException('Valid STARFINITI_TEST_ENTITY_ID and STARFINITI_TEST_PRODUCT_NAME are required.');
}
$product->set_name($name);
$product->save();
echo $product->get_id() . PHP_EOL;
