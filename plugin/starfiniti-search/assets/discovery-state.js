/* Pure, bounded URL/contract conversion shared by every discovery surface. */
(() => {
  'use strict';
  const money = (raw, decimals) => {
    if (raw === '') return null;
    if (!Number.isInteger(decimals) || decimals < 0 || decimals > 6 || !/^\d{1,16}(?:\.\d{1,6})?$/.test(raw)) throw new Error('invalid_price');
    const [whole, fraction = ''] = raw.split('.');
    if (fraction.length > decimals) throw new Error('invalid_price');
    const minor = BigInt(whole) * (10n ** BigInt(decimals)) + BigInt(fraction.padEnd(decimals, '0') || '0');
    if (minor > BigInt(Number.MAX_SAFE_INTEGER)) throw new Error('invalid_price');
    return Number(minor);
  };
  const range = (raw, decimals) => {
    if (!raw) return ['', ''];
    const parts = raw.split('-');
    if (parts.length !== 2 || parts.every((part) => part === '')) throw new Error('invalid_price');
    const [minimum, maximum] = parts.map((part) => money(part, decimals));
    if (minimum !== null && maximum !== null && minimum > maximum) throw new Error('invalid_price');
    return parts;
  };
  const read = (definitions, params, decimals) => Object.fromEntries(definitions.map((definition) => {
    const values = params.getAll(definition.parameter);
    if (definition.type === 'range') {
      if (values.length > 1) throw new Error('invalid_price');
      return [definition.id, range(values[0] || '', decimals)];
    }
    return [definition.id, [...new Set(values.filter((value) => value !== '' && value.length <= 191))].slice(0, definition.type === 'checkboxes' ? 16 : 1)];
  }));
  const write = (definitions, values, params, decimals) => {
    definitions.forEach((definition) => {
      params.delete(definition.parameter);
      const selected = values[definition.id] || [];
      if (definition.type === 'range') {
        if (selected.some((value) => value !== '')) {
          const raw = selected.join('-'); range(raw, decimals);
          params.set(definition.parameter, raw);
        }
      } else selected.forEach((value) => params.append(definition.parameter, value));
    });
  };
  const nodes = (definitions, values, decimals) => definitions.flatMap((definition) => {
    const selected = values[definition.id] || [];
    if (definition.type !== 'range') return selected.length ? [{ field: definition.field, op: 'in', value: selected }] : [];
    if (!selected.some((value) => value !== '')) return [];
    const [minimum, maximum] = range(selected.join('-'), decimals).map((value) => money(value, decimals));
    if (minimum !== null && maximum !== null) return [{ field: definition.field, op: 'between', value: [minimum, maximum] }];
    return [{ field: definition.field, op: minimum === null ? 'lte' : 'gte', value: minimum === null ? maximum : minimum }];
  });
  globalThis.StarfinitiDiscoveryState = Object.freeze({ money, range, read, write, nodes });
})();
