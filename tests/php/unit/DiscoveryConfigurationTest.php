<?php

use Starfiniti\Search\Domain\Configuration\DesiredConfiguration;

test('discovery filters preserve explicit order labels and URL bindings in an immutable revision', static function (): void {
    $original = DesiredConfiguration::defaults()->toArray();
    $draft = $original;
    $draft['discovery'] = ['filters' => [
        ['id' => 'color', 'field' => 'attributes.pa_color', 'label' => 'Colour', 'type' => 'checkboxes', 'parameter' => 'color'],
        ['id' => 'price', 'field' => 'pricing.active_min_minor', 'label' => 'Price', 'type' => 'range', 'parameter' => 'price'],
    ]];
    $configuration = DesiredConfiguration::fromArray($draft);
    assertSameValue($draft, $configuration->toArray(), 'Discovery configuration changed its bindings.');
    assertTrueValue(DesiredConfiguration::fromArray($original)->semanticChecksum() !== $configuration->semanticChecksum(), 'Discovery changes must affect operation preconditions.');
    assertSameValue($original, DesiredConfiguration::defaults()->toArray(), 'Legacy defaults must remain readable without implicit filter changes.');
});

test('discovery filters reject unsupported fields ambiguous URLs unsafe labels and excessive controls', static function (): void {
    $valid = ['id' => 'color', 'field' => 'attributes.pa_color', 'label' => 'Colour', 'type' => 'checkboxes', 'parameter' => 'color'];
    $invalid = [];
    foreach ([['parameter', 's'], ['parameter', "color\n"], ['field', 'visibility.scope_tokens'], ['field', 'custom.secret'], ['label', '<script>'], ['label', "Colour\n"], ['type', 'range']] as [$key, $value]) {
        $changed = $valid;
        $changed[$key] = $value;
        $invalid[] = [$changed];
    }
    $invalid[] = [$valid, $valid];
    $other = $valid;
    $other['id'] = 'another';
    $invalid[] = [$valid, $other];
    $maximum = [];
    for ($index = 0; $index < 15; ++$index) {
        $maximum[] = array_replace($valid, ['id' => 'filter-' . $index, 'parameter' => 'filter-' . $index]);
    }
    $atLimit = DesiredConfiguration::defaults()->toArray();
    $atLimit['discovery'] = ['filters' => array_slice($maximum, 0, 14)];
    DesiredConfiguration::fromArray($atLimit);
    $invalid[] = $maximum;
    $invalid[] = ['named' => $valid];
    foreach ($invalid as $filters) {
        $draft = DesiredConfiguration::defaults()->toArray();
        $draft['discovery'] = ['filters' => $filters];
        try {
            DesiredConfiguration::fromArray($draft);
            throw new TestFailure('Invalid discovery configuration was accepted.');
        } catch (InvalidArgumentException) {
        }
    }
});
