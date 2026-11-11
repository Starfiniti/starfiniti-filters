import assert from 'node:assert/strict';
import test from 'node:test';
import '../plugin/starfiniti-search/assets/discovery-state.js';

const state = globalThis.StarfinitiDiscoveryState;
const definitions = [
  { id: 'color', parameter: 'color', type: 'checkboxes', field: 'attributes.pa_color' },
  { id: 'price', parameter: 'price', type: 'range', field: 'pricing.active_min_minor' },
  { id: 'stock', parameter: 'stock-status', type: 'radio', field: 'inventory.stock_status' },
];

test('money stays exact for zero, two and six decimal currencies', () => {
  assert.equal(state.money('0', 2), 0);
  assert.equal(state.money('19.99', 2), 1999);
  assert.equal(state.money('10', 0), 10);
  assert.equal(state.money('0.000001', 6), 1);
  assert.equal(state.money('90071992547409.91', 2), Number.MAX_SAFE_INTEGER);
  for (const value of ['1e3', '-1', ' 1', '1.001', 'NaN', 'Infinity', '90071992547409.92']) assert.throws(() => state.money(value, 2));
});

test('URL bindings round-trip selections, open ranges and unrelated parameters', () => {
  const params = new URLSearchParams('utm_source=test&color=red&color=blue&color=red&price=19.99-40&stock-status=instock');
  const selections = state.read(definitions, params, 2);
  assert.deepEqual(selections, { color: ['red', 'blue'], price: ['19.99', '40'], stock: ['instock'] });
  const target = new URLSearchParams('utm_source=test&color=old');
  state.write(definitions, selections, target, 2);
  assert.deepEqual(state.read(definitions, target, 2), selections);
  assert.equal(target.get('utm_source'), 'test');
  assert.deepEqual(state.nodes(definitions, selections, 2), [
    { field: 'attributes.pa_color', op: 'in', value: ['red', 'blue'] },
    { field: 'pricing.active_min_minor', op: 'between', value: [1999, 4000] },
    { field: 'inventory.stock_status', op: 'in', value: ['instock'] },
  ]);
  for (const [raw, op, value] of [['0-', 'gte', 0], ['-40', 'lte', 4000]]) {
    assert.deepEqual(state.nodes(definitions, state.read(definitions, new URLSearchParams({ price: raw }), 2), 2), [{ field: 'pricing.active_min_minor', op, value }]);
  }
});

test('malformed ranges fail closed instead of returning an unfiltered catalog', () => {
  for (const price of ['40-20', '1.001-2', '-', '1-2-3', '-1-2', '1e2-200']) assert.throws(() => state.read(definitions, new URLSearchParams({ price }), 2));
  assert.throws(() => state.read(definitions, new URLSearchParams('price=1-2&price=3-4'), 2));
  assert.deepEqual(state.nodes(definitions, state.read(definitions, new URLSearchParams(), 2), 2), []);
});

test('single-choice and multi-choice values are bounded and clearing removes owned URL keys', () => {
  const params = new URLSearchParams('stock-status=instock&stock-status=outofstock');
  for (let i = 0; i < 40; i += 1) params.append('color', `value-${i}`);
  const values = state.read(definitions, params, 2);
  assert.equal(values.color.length, 16);
  assert.deepEqual(values.stock, ['instock']);
  state.write(definitions, state.read(definitions, new URLSearchParams(), 2), params, 2);
  assert.equal(params.toString(), '');
});
