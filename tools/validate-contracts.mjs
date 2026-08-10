import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const contracts = path.join(root, 'spec', 'starfiniti-search-codex-spec', 'contracts');
const files = (await fs.readdir(contracts)).filter((name) => name.endsWith('.schema.json')).sort();
const ajv = new Ajv2020({ allErrors: true, allowUnionTypes: true, strict: true });

ajv.addFormat('uri', {
  type: 'string',
  validate(value) {
    try {
      const parsed = new URL(value);
      return parsed.protocol !== '';
    } catch (_) {
      return false;
    }
  },
});
ajv.addFormat('uuid', { type: 'string', validate: /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i });
ajv.addFormat('date-time', {
  type: 'string',
  validate: (value) => /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(value) && !Number.isNaN(Date.parse(value)),
});

const schemas = new Map();
for (const file of files) {
  const schema = JSON.parse(await fs.readFile(path.join(contracts, file), 'utf8'));
  schemas.set(file, schema);
  ajv.addSchema(schema);
}

const requestSchema = schemas.get('search-request.schema.json');
const responseSchema = schemas.get('search-response.schema.json');
const validateRequest = ajv.getSchema(requestSchema.$id);
const validateResponse = ajv.getSchema(responseSchema.$id);
const request = {
  contract_version: '1.0',
  query: 'EXACT-001',
  context: {
    site_id: 'qualification-site', blog_id: 1, locale: 'en_US', currency: 'EUR',
    customer_scope: ['public'], channel: 'storefront',
  },
  fields: ['identity.title', 'identity.sku', 'content.description_text'],
  filters: null,
  facets: [],
  sort: [],
  page: { number: 1, size: 8 },
  options: { highlight: false, include_explanation: false, suggestion_mode: 'autocomplete' },
};

if (!validateRequest(request)) {
  throw new Error(`Canonical request failed schema validation: ${ajv.errorsText(validateRequest.errors)}`);
}
if (validateRequest({ ...request, unexpected: true })) {
  throw new Error('Request schema accepted an unknown top-level property.');
}
if (validateRequest({ ...request, filters: { field: 'inventory.stock_status', op: 'execute', value: 'instock' } })) {
  throw new Error('Request schema accepted a prohibited filter operator.');
}

const endpoint = 'http://127.0.0.1:8088/wp-json/starfiniti-search/v1/search?q=EXACT-001&size=8';
const response = await fetch(endpoint, { headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(30000) });
if (!response.ok) throw new Error(`Live search returned HTTP ${response.status}.`);
const payload = await response.json();
if (!validateResponse(payload)) {
  throw new Error(`Live response failed schema validation: ${ajv.errorsText(validateResponse.errors)}`);
}
if (payload.hits?.[0]?.projection?.identity?.title !== 'Blue Alpine Shirt') {
  throw new Error('Live contract fixture did not return the expected exact-SKU product.');
}
if (validateResponse({ ...payload, redirect: { url: '//attacker.invalid', rule_id: 'unsafe' } })) {
  throw new Error('Response schema accepted a protocol-relative redirect.');
}

process.stdout.write(`JSON Schema contract validation passed: ${files.length} schemas compiled; canonical request, negative bounds, and live installed response conform.\n`);
