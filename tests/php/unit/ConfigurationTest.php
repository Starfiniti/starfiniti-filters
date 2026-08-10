<?php

use Starfiniti\Search\Domain\Configuration\DesiredConfiguration;

test('desired configuration is deterministic and secret-free', static function (): void {
    $configuration = DesiredConfiguration::defaults('sl_SI');
    assertSameValue(1, $configuration->revision(), 'Default revision mismatch.');
    assertSameValue(64, strlen($configuration->checksum()), 'Configuration checksum must be SHA-256.');
    assertSameValue($configuration->checksum(), DesiredConfiguration::fromArray($configuration->toArray())->checksum(), 'Configuration checksum is not deterministic.');
});

test('desired configuration rejects embedded secrets and unsafe provider topology', static function (): void {
    $data = DesiredConfiguration::defaults()->toArray();
    $data['operations']['api_key'] = 'must-not-be-stored';
    try {
        DesiredConfiguration::fromArray($data);
        throw new TestFailure('Embedded secret was accepted.');
    } catch (InvalidArgumentException) {
    }

    $data = DesiredConfiguration::defaults()->toArray();
    $data['active_read_provider'] = 'typesense';
    try {
        DesiredConfiguration::fromArray($data);
        throw new TestFailure('Read provider absent from write targets was accepted.');
    } catch (InvalidArgumentException) {
    }
});

test('custom field projection is explicitly allow-listed and typed', static function (): void {
    $data = DesiredConfiguration::defaults()->toArray();
    $data['catalog']['custom_fields'] = [
        ['field' => 'supplier_code', 'meta_key' => '_supplier_code', 'type' => 'string'],
        ['field' => 'lead_days', 'meta_key' => '_lead_days', 'type' => 'integer'],
    ];
    $validated = DesiredConfiguration::fromArray($data)->toArray();
    assertSameValue('integer', $validated['catalog']['custom_fields'][1]['type'], 'Custom field type was not preserved.');

    foreach ([
        [['field' => 'supplier_code', 'meta_key' => '_supplier_code', 'type' => 'object']],
        [['field' => 'supplier_code', 'meta_key' => '_supplier_code', 'type' => 'string'], ['field' => 'supplier_code', 'meta_key' => '_other', 'type' => 'string']],
        [['field' => 'Bad Field', 'meta_key' => '_supplier_code', 'type' => 'string']],
    ] as $invalid) {
        $draft = DesiredConfiguration::defaults()->toArray();
        $draft['catalog']['custom_fields'] = $invalid;
        try {
            DesiredConfiguration::fromArray($draft);
            throw new TestFailure('Invalid custom field allow-list was accepted.');
        } catch (InvalidArgumentException) {
        }
    }
});
