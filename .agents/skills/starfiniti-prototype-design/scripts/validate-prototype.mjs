#!/usr/bin/env node

import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const allowedSurfaces = new Set([
  'autocomplete', 'discovery', 'mobile-overlay', 'admin-setup',
  'admin-operations', 'admin-relevance', 'admin-analytics',
]);
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
const forbiddenCurrentFeatures = new Set([
  'multiple-suggestion-groups', 'search-history', 'quick-add-to-cart',
  'variable-product-selector', 'provider-specific-storefront-controls',
]);
const certifiedIntegrations = new Set(['theme-twentytwentyfive']);
const allowedRenderModes = new Set(['text', 'url', 'minor-units', 'boolean']);

function list(value) {
  return Array.isArray(value) ? value : [];
}

function validate(manifest) {
  const errors = [];
  const warnings = [];
  const fail = (message) => errors.push(message);
  const warn = (message) => warnings.push(message);

  if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) return { errors: ['manifest must be a JSON object'], warnings };
  if (manifest.contract_version !== '1.0') fail('contract_version must be "1.0"');
  if (!manifest.prototype || typeof manifest.prototype !== 'object') fail('prototype metadata is required');
  if (!['current', 'planned'].includes(manifest.prototype?.implementation_target)) fail('prototype.implementation_target must be "current" or "planned"');

  const surfaces = list(manifest.surfaces);
  if (surfaces.length === 0) fail('surfaces must select at least one supported surface');
  for (const surface of surfaces) if (!allowedSurfaces.has(surface)) fail(`unsupported surface: ${surface}`);
  if (new Set(surfaces).size !== surfaces.length) fail('surfaces must not contain duplicates');

  const includesStorefront = surfaces.some((surface) => storefrontSurfaces.has(surface));
  const includesAdmin = surfaces.some((surface) => adminSurfaces.has(surface));
  const invariants = manifest.invariants || {};
  for (const key of ['canonical_contracts', 'server_authoritative_visibility', 'untrusted_data_text_only']) {
    if (invariants[key] !== true) fail(`invariants.${key} must be true`);
  }
  if (includesStorefront) {
    for (const key of ['provider_neutral_storefront', 'shared_renderer', 'normal_search_fallback', 'multiple_instances_unique_ids']) {
      if (invariants[key] !== true) fail(`invariants.${key} must be true for storefront designs`);
    }
  }
  if (includesAdmin) {
    for (const key of ['immutable_plan_approve_execute', 'secret_safe_diagnostics', 'truthful_readiness']) {
      if (invariants[key] !== true) fail(`invariants.${key} must be true for administrator designs`);
    }
  }

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
    if (!String(binding.source || '').trim()) fail(`bindings[${index}].source is required`);
    if (!allowedRenderModes.has(binding.render)) fail(`bindings[${index}].render must be text, url, minor-units, or boolean`);
    if (/typesense|innerhtml|provider_html|raw_html/i.test(String(binding.source || ''))) fail(`bindings[${index}] uses a provider-specific or HTML source`);
  }
  if (surfaces.includes('autocomplete')) {
    for (const source of ['hits[].projection.identity.title', 'hits[].projection.identity.url']) {
      if (!bindings.some((binding) => binding?.source === source)) fail(`autocomplete requires binding ${source}`);
    }
  }
  if (surfaces.includes('discovery')) {
    for (const source of ['hits[].projection.pricing.active_min_minor', 'hits[].projection.inventory.stock_status']) {
      if (!bindings.some((binding) => binding?.source === source)) fail(`discovery requires binding ${source}`);
    }
  }

  const currentFeatures = list(manifest.features?.current);
  if (manifest.prototype?.implementation_target === 'current') {
    for (const feature of currentFeatures) if (forbiddenCurrentFeatures.has(feature)) fail(`features.current claims planned capability as current: ${feature}`);
  }
  for (const claim of list(manifest.claims?.certified_integrations)) {
    if (!certifiedIntegrations.has(claim)) fail(`uncertified integration claimed as certified: ${claim}`);
  }
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
  const examplePath = path.resolve(here, '..', 'assets', 'prototype-manifest.example.json');
  const example = JSON.parse(await readFile(examplePath, 'utf8'));
  const passing = validate(example);
  if (passing.errors.length) throw new Error(`example manifest failed: ${passing.errors.join('; ')}`);
  const invalid = structuredClone(example);
  invalid.invariants.provider_neutral_storefront = false;
  invalid.states.autocomplete = invalid.states.autocomplete.filter((state) => state !== 'error');
  invalid.bindings[0].render = 'html';
  invalid.claims.certified_integrations.push('theme-storefront');
  const rejected = validate(invalid);
  if (rejected.errors.length < 4) throw new Error('invalid manifest was not rejected by all expected safeguards');
  console.log(`Prototype skill self-test passed: valid example accepted; invalid example rejected with ${rejected.errors.length} errors.`);
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
    const manifest = JSON.parse(await readFile(file, 'utf8'));
    const result = validate(manifest);
    printResult(result, path.relative(process.cwd(), file) || path.basename(file));
    if (result.errors.length) process.exitCode = 1;
  } catch (error) {
    console.error(`PROTOTYPE ERROR: ${error instanceof Error ? error.message : String(error)}`);
    process.exitCode = 1;
  }
}
