#!/usr/bin/env node

import { constants } from 'node:fs';
import { lstat, mkdtemp, open, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const pluginVersion = '0.3.0-alpha.1';
const maxManifestBytes = 256 * 1024;
const maxCollectionItems = 100;
const maxErrors = 100;
const allowedSurfaces = new Set(['autocomplete', 'discovery', 'mobile-overlay', 'admin-setup', 'admin-operations', 'admin-relevance', 'admin-analytics']);
const storefrontSurfaces = new Set(['autocomplete', 'discovery', 'mobile-overlay']);
const adminSurfaces = new Set(['admin-setup', 'admin-operations', 'admin-relevance', 'admin-analytics']);
const requiredStates = {
  autocomplete: ['idle', 'focused_empty', 'debouncing', 'loading', 'success', 'no_results', 'degraded', 'error', 'closed'],
  discovery: ['loading', 'success', 'no_results', 'error'],
  'mobile-overlay': ['open', 'closed'],
  'admin-setup': ['not_started', 'in_progress', 'blocked', 'ready'],
  'admin-operations': ['healthy', 'degraded', 'building', 'verification_required', 'stale', 'misconfigured', 'unavailable'],
  'admin-relevance': ['draft', 'preview', 'approved', 'active', 'retired'],
  'admin-analytics': ['disabled', 'loading', 'ready', 'empty', 'error'],
};
const requiredInteractions = {
  autocomplete: ['normal-form-submit', 'abort-previous-request', 'ignore-late-response', 'ime-composition', 'arrow-navigation', 'escape-close', 'enter-activate', 'live-region-status'],
  discovery: ['submit-query', 'filter-stock', 'filter-category', 'sort', 'clear-filters', 'previous-page', 'next-page', 'url-state', 'browser-back', 'details-disclosure', 'failed-image-recovery'],
  'mobile-overlay': ['single-active-overlay', 'visible-close', 'focus-containment', 'escape-close', 'restore-opener-focus', 'lock-and-restore-scroll'],
  'admin-setup': ['validate-readiness', 'block-unsafe-activation', 'show-remediation'],
  'admin-operations': ['plan-change', 'approve-change', 'execute-change', 'verify-change', 'rollback'],
  'admin-relevance': ['preview-no-mutation', 'approve-change', 'activate-revision', 'rollback'],
  'admin-analytics': ['show-metric-definitions', 'show-retention', 'show-provider-version-context', 'disable-without-search-impact'],
};
const certifiedIntegrations = new Set(['theme-twentytwentyfive']);
const allowedCurrentFeatures = new Set([
  'product-title-autocomplete', 'stock-category-filters', 'bounded-pagination', 'details-disclosure',
  'normal-search-fallback', 'mobile-overlay', 'canonical-product-links', 'failed-image-recovery',
  'relevance-sorting', 'price-sorting', 'title-sorting',
]);
const allowedPlannedFeatures = new Set(['multiple-suggestion-groups', 'search-history', 'quick-add-to-cart', 'variable-product-selector']);
const allowedBindings = new Set([
  'autocomplete-title\0hits[].projection.identity.title\0text',
  'autocomplete-link\0hits[].projection.identity.url\0url',
  'product-price\0hits[].projection.pricing.active_min_minor\0minor-units',
  'product-stock\0hits[].projection.inventory.stock_status\0text',
  'product-image\0hits[].projection.media.thumbnail_url\0url',
  'product-image\0hits[].projection.media.primary_image_url\0url',
  'category-facets\0facets.classification.category_paths\0text',
]);

const list = (value) => Array.isArray(value) ? value.slice(0, maxCollectionItems) : [];

async function readManifest(file) {
  const pathMetadata = await lstat(file);
  if (!pathMetadata.isFile() || pathMetadata.isSymbolicLink()) throw new Error('manifest path must be a regular file, not a directory, symlink, or special file');
  const handle = await open(file, constants.O_RDONLY | (constants.O_NOFOLLOW || 0));
  try {
    const metadata = await handle.stat();
    if (!metadata.isFile()) throw new Error('manifest path must resolve to a regular file');
    if (metadata.size > maxManifestBytes) throw new Error(`manifest exceeds the ${maxManifestBytes}-byte limit`);
    const buffer = Buffer.alloc(maxManifestBytes + 1);
    let total = 0;
    while (total < buffer.length) {
      const { bytesRead } = await handle.read(buffer, total, buffer.length - total, null);
      if (bytesRead === 0) break;
      total += bytesRead;
    }
    if (total > maxManifestBytes) throw new Error(`manifest exceeds the ${maxManifestBytes}-byte limit`);
    return JSON.parse(buffer.subarray(0, total).toString('utf8').replace(/^\uFEFF/, ''));
  } finally {
    await handle.close();
  }
}

function validate(manifest) {
  const errors = [];
  const warnings = [];
  const fail = (message) => { if (errors.length < maxErrors) errors.push(message); };
  const warn = (message) => warnings.push(message);
  if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) return { errors: ['manifest must be a JSON object'], warnings };
  const inspectCollections = (value, location = 'manifest', depth = 0) => {
    if (depth > 20) { fail(`${location} exceeds the maximum nesting depth`); return; }
    if (Array.isArray(value)) {
      if (value.length > maxCollectionItems) fail(`${location} exceeds ${maxCollectionItems} items`);
      for (const [index, item] of value.slice(0, maxCollectionItems).entries()) inspectCollections(item, `${location}[${index}]`, depth + 1);
    } else if (value && typeof value === 'object') {
      const entries = Object.entries(value);
      if (entries.length > maxCollectionItems) fail(`${location} exceeds ${maxCollectionItems} properties`);
      for (const [key, item] of entries.slice(0, maxCollectionItems)) inspectCollections(item, `${location}.${key}`, depth + 1);
    }
  };
  inspectCollections(manifest);
  if (manifest.contract_version !== '1.0') fail('contract_version must be "1.0"');
  if (manifest.plugin_version !== pluginVersion) fail(`plugin_version must be "${pluginVersion}"`);
  if (!manifest.prototype || typeof manifest.prototype !== 'object') fail('prototype metadata is required');
  if (!['current', 'planned'].includes(manifest.prototype?.implementation_target)) fail('prototype.implementation_target must be "current" or "planned"');
  const surfaces = list(manifest.surfaces);
  if (surfaces.length === 0) fail('surfaces must select at least one supported surface');
  for (const surface of surfaces) if (!allowedSurfaces.has(surface)) fail(`unsupported surface: ${surface}`);
  if (new Set(surfaces).size !== surfaces.length) fail('surfaces must not contain duplicates');
  const includesStorefront = surfaces.some((surface) => storefrontSurfaces.has(surface));
  const includesAdmin = surfaces.some((surface) => adminSurfaces.has(surface));
  const invariants = manifest.invariants || {};
  for (const key of ['canonical_contracts', 'server_authoritative_visibility', 'untrusted_data_text_only']) if (invariants[key] !== true) fail(`invariants.${key} must be true`);
  if (includesStorefront) for (const key of ['provider_neutral_storefront', 'shared_renderer', 'normal_search_fallback', 'multiple_instances_unique_ids']) if (invariants[key] !== true) fail(`invariants.${key} must be true for storefront designs`);
  if (includesAdmin) for (const key of ['immutable_plan_approve_execute', 'secret_safe_diagnostics', 'truthful_readiness']) if (invariants[key] !== true) fail(`invariants.${key} must be true for administrator designs`);
  for (const surface of surfaces) {
    const actualStates = new Set(list(manifest.states?.[surface]));
    for (const state of requiredStates[surface] || []) if (!actualStates.has(state)) fail(`states.${surface} is missing ${state}`);
    const actualInteractions = new Set(list(manifest.interactions?.[surface]));
    for (const interaction of requiredInteractions[surface] || []) if (!actualInteractions.has(interaction)) fail(`interactions.${surface} is missing ${interaction}`);
  }
  const viewports = list(manifest.viewports);
  if (includesStorefront && !viewports.some((viewport) => Number(viewport?.width) >= 1024)) fail('storefront prototypes require a desktop viewport of at least 1024 px');
  if (surfaces.includes('mobile-overlay') && !viewports.some((viewport) => Number(viewport?.width) > 0 && Number(viewport.width) <= 360)) fail('mobile-overlay prototypes require a viewport of 360 px or narrower');
  const bindings = list(manifest.bindings);
  if (includesStorefront && bindings.length === 0) fail('storefront prototypes require canonical data bindings');
  for (const [index, binding] of bindings.entries()) {
    if (!binding || typeof binding !== 'object') { fail(`bindings[${index}] must be an object`); continue; }
    const component = String(binding.component || '').trim();
    const source = String(binding.source || '').trim();
    const render = String(binding.render || '').trim();
    if (!component) fail(`bindings[${index}].component is required`);
    if (!source) fail(`bindings[${index}].source is required`);
    if (!allowedBindings.has(`${component}\0${source}\0${render}`)) fail(`bindings[${index}] is not an allowed canonical component/source/render mapping`);
  }
  if (surfaces.includes('autocomplete')) for (const source of ['hits[].projection.identity.title', 'hits[].projection.identity.url']) if (!bindings.some((binding) => binding?.source === source)) fail(`autocomplete requires binding ${source}`);
  if (surfaces.includes('discovery')) for (const source of ['hits[].projection.pricing.active_min_minor', 'hits[].projection.inventory.stock_status']) if (!bindings.some((binding) => binding?.source === source)) fail(`discovery requires binding ${source}`);
  const currentFeatures = list(manifest.features?.current);
  const plannedFeatures = list(manifest.features?.planned);
  for (const feature of currentFeatures) {
    if (allowedPlannedFeatures.has(feature)) fail(`features.current claims planned capability as current: ${feature}`);
    else if (!allowedCurrentFeatures.has(feature)) fail(`features.current contains unsupported capability: ${feature}`);
  }
  for (const feature of plannedFeatures) if (!allowedPlannedFeatures.has(feature)) fail(`features.planned contains unsupported capability: ${feature}`);
  if (new Set(currentFeatures).size !== currentFeatures.length) fail('features.current must not contain duplicates');
  if (new Set(plannedFeatures).size !== plannedFeatures.length) fail('features.planned must not contain duplicates');
  for (const feature of currentFeatures) if (plannedFeatures.includes(feature)) fail(`feature cannot be both current and planned: ${feature}`);
  for (const claim of list(manifest.claims?.certified_integrations)) if (!certifiedIntegrations.has(claim)) fail(`uncertified integration claimed as certified: ${claim}`);
  if (list(manifest.features?.planned).length > 0) warn('planned features require implementation, tests, and qualification before current-compatibility claims');
  if (includesStorefront && !list(manifest.claims?.pending_qualification).includes('zoom-200-400')) warn('record 200%/400% zoom as pending qualification unless current evidence is attached');
  return { errors, warnings };
}

function printResult(result, label) {
  for (const warning of result.warnings) console.warn(`PROTOTYPE WARNING: ${warning}`);
  for (const error of result.errors) console.error(`PROTOTYPE ERROR: ${error}`);
  if (result.errors.length === 0) console.log(`Prototype compatibility manifest passed: ${label}`);
}

async function selfTest() {
  const here = path.dirname(fileURLToPath(import.meta.url));
  const example = JSON.parse(await readFile(path.resolve(here, '..', 'assets', 'prototype-manifest.example.json'), 'utf8'));
  const passing = validate(example);
  if (passing.errors.length) throw new Error(`example manifest failed: ${passing.errors.join('; ')}`);
  const invalid = structuredClone(example);
  invalid.plugin_version = 'mismatched';
  invalid.invariants.provider_neutral_storefront = false;
  invalid.states.autocomplete = invalid.states.autocomplete.filter((state) => state !== 'error');
  invalid.bindings[0].render = 'html';
  invalid.claims.certified_integrations.push('theme-storefront');
  const rejected = validate(invalid);
  if (rejected.errors.length < 5) throw new Error('invalid manifest was not rejected by all expected safeguards');
  const rejectionCases = [
    ['wrong canonical render type', (candidate) => { candidate.bindings[0].render = 'url'; }, 'canonical component/source/render'],
    ['unknown binding source', (candidate) => { candidate.bindings.push({ component: 'invented', source: 'totally.noncanonical.payload', render: 'text' }); }, 'canonical component/source/render'],
    ['unknown current capability', (candidate) => { candidate.features.current.push('invented-current-capability'); }, 'unsupported capability'],
    ['planned capability labeled current', (candidate) => { candidate.prototype.implementation_target = 'planned'; candidate.features.current.push('quick-add-to-cart'); }, 'planned capability as current'],
    ['unknown planned capability', (candidate) => { candidate.features.planned.push('invented-planned-capability'); }, 'features.planned contains unsupported'],
  ];
  for (const [label, mutate, expected] of rejectionCases) {
    const candidate = structuredClone(example);
    mutate(candidate);
    const result = validate(candidate);
    if (!result.errors.some((error) => error.includes(expected))) throw new Error(`${label} was not rejected`);
  }
  const temporary = await mkdtemp(path.join(tmpdir(), 'starfiniti-prototype-'));
  try {
    const oversized = path.join(temporary, 'oversized.json');
    await writeFile(oversized, ' '.repeat(maxManifestBytes + 1));
    await readManifest(oversized).then(() => { throw new Error('oversized manifest was accepted'); }, (error) => {
      if (!String(error).includes('byte limit')) throw error;
    });
    const target = path.join(temporary, 'target.json');
    const linked = path.join(temporary, 'linked.json');
    await writeFile(target, JSON.stringify(example));
    try {
      await symlink(target, linked, 'file');
      await readManifest(linked).then(() => { throw new Error('symlink manifest was accepted'); }, (error) => {
        if (!/symlink|ELOOP/i.test(String(error))) throw error;
      });
    } catch (error) {
      if (!['EPERM', 'EACCES', 'ENOSYS'].includes(error?.code)) throw error;
    }
  } finally {
    await rm(temporary, { recursive: true, force: true });
  }
  console.log(`Customer skill prototype self-test passed: valid example accepted; unsafe example and ${rejectionCases.length} focused cases rejected.`);
}

const argument = process.argv[2];
if (argument === '--self-test') {
  await selfTest();
} else if (!argument) {
  console.error('Usage: validate-prototype.mjs <prototype-compatibility.json> | --self-test');
  process.exitCode = 2;
} else {
  try {
    const file = path.resolve(process.cwd(), argument);
    const manifest = await readManifest(file);
    const result = validate(manifest);
    printResult(result, path.relative(process.cwd(), file) || path.basename(file));
    if (result.errors.length) process.exitCode = 1;
  } catch (error) {
    console.error(`PROTOTYPE ERROR: ${error instanceof Error ? error.message : String(error)}`);
    process.exitCode = 1;
  }
}
