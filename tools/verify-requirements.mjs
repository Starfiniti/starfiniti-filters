import fs from 'node:fs';
import crypto from 'node:crypto';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const registerPath = path.join(repoRoot, 'spec', 'starfiniti-search-codex-spec', 'requirements.csv');
const statusPath = path.join(repoRoot, 'spec', 'starfiniti-search-codex-spec', 'project-status.json');
const specificationRoot = path.dirname(registerPath);
const allowedStatuses = new Set(['complete', 'in_progress', 'blocked_external', 'blocked_license', 'not_applicable_disabled']);

function parseCsv(input) {
  const rows = [];
  let row = [];
  let field = '';
  let quoted = false;
  for (let index = 0; index < input.length; index += 1) {
    const character = input[index];
    if (quoted) {
      if (character === '"' && input[index + 1] === '"') {
        field += '"';
        index += 1;
      } else if (character === '"') {
        quoted = false;
      } else {
        field += character;
      }
    } else if (character === '"') {
      quoted = true;
    } else if (character === ',') {
      row.push(field);
      field = '';
    } else if (character === '\n') {
      row.push(field.replace(/\r$/, ''));
      rows.push(row);
      row = [];
      field = '';
    } else {
      field += character;
    }
  }
  if (quoted) throw new Error('requirements.csv contains an unterminated quoted field.');
  if (field !== '' || row.length > 0) {
    row.push(field.replace(/\r$/, ''));
    rows.push(row);
  }
  const headers = rows.shift();
  if (!headers) throw new Error('requirements.csv is empty.');
  return rows.filter((values) => values.some(Boolean)).map((values, rowIndex) => {
    if (values.length !== headers.length) {
      throw new Error(`requirements.csv row ${rowIndex + 2} has ${values.length} columns; expected ${headers.length}.`);
    }
    return Object.fromEntries(headers.map((header, index) => [header, values[index]]));
  });
}

function sorted(values) {
  return [...values].sort((left, right) => left.localeCompare(right));
}

function assertSameIds(label, actual, expected) {
  const normalized = sorted(actual);
  const wanted = sorted(expected);
  if (JSON.stringify(normalized) !== JSON.stringify(wanted)) {
    const missing = wanted.filter((id) => !normalized.includes(id));
    const extra = normalized.filter((id) => !wanted.includes(id));
    throw new Error(`${label} is out of sync (missing: ${missing.join(', ') || 'none'}; extra: ${extra.join(', ') || 'none'}).`);
  }
}

const requirements = parseCsv(fs.readFileSync(registerPath, 'utf8'));
if (requirements.length === 0) throw new Error('No requirements were found.');

const ids = requirements.map(({ id }) => id);
if (new Set(ids).size !== ids.length) throw new Error('Requirement IDs must be unique.');

for (const requirement of requirements) {
  if (requirement.priority !== 'MUST') throw new Error(`${requirement.id} is not marked MUST.`);
  if (!allowedStatuses.has(requirement.status)) throw new Error(`${requirement.id} has unsupported status ${requirement.status || '(empty)'}.`);
  for (const field of ['description', 'source', 'implementation', 'tests', 'evidence', 'release_gate']) {
    if (!requirement[field]?.trim()) throw new Error(`${requirement.id} is missing ${field}.`);
  }
  for (const field of ['implementation', 'tests', 'evidence']) {
    for (const reference of requirement[field].split(';').map((value) => value.trim())) {
      if (!reference || reference.startsWith('pnpm ')) continue;
      const localPath = reference.split('#', 1)[0];
      if (!fs.existsSync(path.join(repoRoot, localPath))) {
        throw new Error(`${requirement.id} ${field} reference does not exist: ${reference}.`);
      }
    }
  }
}

const projectStatus = JSON.parse(fs.readFileSync(statusPath, 'utf8'));
const groups = projectStatus.requirements ?? {};
const expected = {
  complete: requirements.filter(({ status }) => status === 'complete').map(({ id }) => id),
  in_progress: requirements.filter(({ status }) => status === 'in_progress').map(({ id }) => id),
  blocked: requirements.filter(({ status }) => status === 'blocked_external' || status === 'blocked_license').map(({ id }) => id),
  not_applicable: requirements.filter(({ status }) => status === 'not_applicable_disabled').map(({ id }) => id),
  not_started: [],
};

for (const [group, expectedIds] of Object.entries(expected)) {
  if (!Array.isArray(groups[group])) throw new Error(`project-status.json is missing requirements.${group}.`);
  assertSameIds(`requirements.${group}`, groups[group], expectedIds);
}

const groupedIds = Object.values(expected).flat();
assertSameIds('project-status requirement inventory', groupedIds, ids);
if (!projectStatus.updated_at_utc || Number.isNaN(Date.parse(projectStatus.updated_at_utc))) {
  throw new Error('project-status.json must contain a valid updated_at_utc timestamp.');
}
if (!projectStatus.last_test_runs || Object.keys(projectStatus.last_test_runs).length === 0) {
  throw new Error('project-status.json must record the latest qualification commands.');
}

const manifestLines = fs.readFileSync(path.join(specificationRoot, 'MANIFEST.sha256'), 'utf8').trim().split(/\r?\n/);
for (const line of manifestLines) {
  const match = line.match(/^([a-f0-9]{64}) {2}(.+)$/);
  if (!match) throw new Error(`Invalid specification manifest line: ${line}.`);
  const [, expectedHash, relativePath] = match;
  const artifactPath = path.join(specificationRoot, relativePath);
  if (!fs.existsSync(artifactPath)) throw new Error(`Specification manifest target is missing: ${relativePath}.`);
  const actualHash = crypto.createHash('sha256').update(fs.readFileSync(artifactPath)).digest('hex');
  if (actualHash !== expectedHash) throw new Error(`Specification manifest hash mismatch: ${relativePath}.`);
}

console.log(`Requirements register verified: ${requirements.length} MUST requirements; ${expected.complete.length} complete, ${expected.in_progress.length} in progress, ${expected.blocked.length} externally blocked, ${expected.not_applicable.length} disabled/not applicable; ${manifestLines.length} specification hashes valid.`);
