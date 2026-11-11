import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const configurationPath = path.join(repositoryRoot, 'config', 'upstreams.json');
const outputPath = path.join(repositoryRoot, 'audit', 'generated', 'upstream-manifest.json');
const checkOnly = process.argv.includes('--check');
const recordedOnly = process.env.STARFINITI_UPSTREAM_AUDIT_MODE === 'recorded';

function sha256(buffer) {
  return createHash('sha256').update(buffer).digest('hex').toUpperCase();
}

async function walkFiles(root, relative = '') {
  const directory = path.join(root, relative);
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];

  for (const entry of entries.sort((left, right) => left.name.localeCompare(right.name, 'en'))) {
    const relativePath = path.posix.join(relative.split(path.sep).join('/'), entry.name);
    if (entry.isDirectory()) {
      files.push(...await walkFiles(root, relativePath));
    } else if (entry.isFile()) {
      files.push(relativePath);
    }
  }

  return files;
}

async function auditTree(sourceRoot) {
  const files = await walkFiles(sourceRoot);
  const treeHasher = createHash('sha256');
  let totalBytes = 0;
  const extensions = new Map();

  for (const relativePath of files) {
    const absolutePath = path.join(sourceRoot, ...relativePath.split('/'));
    const [content, metadata] = await Promise.all([readFile(absolutePath), stat(absolutePath)]);
    const extension = path.extname(relativePath).toLowerCase() || '[none]';
    totalBytes += metadata.size;
    extensions.set(extension, (extensions.get(extension) ?? 0) + 1);
    treeHasher.update(relativePath);
    treeHasher.update('\0');
    treeHasher.update(sha256(content));
    treeHasher.update('\0');
    treeHasher.update(String(metadata.size));
    treeHasher.update('\n');
  }

  return {
    fileCount: files.length,
    totalBytes,
    treeSha256: treeHasher.digest('hex').toUpperCase(),
    extensions: Object.fromEntries([...extensions.entries()].sort(([left], [right]) => left.localeCompare(right, 'en')))
  };
}

function validateRecordedManifest(configuration, manifest) {
  const failures = [];
  if (!checkOnly) {
    failures.push('recorded upstream audit mode is valid only with --check');
  }
  if (manifest.schemaVersion !== 1 || manifest.deterministic !== true || !Array.isArray(manifest.upstreams)) {
    failures.push('recorded upstream manifest has an invalid envelope');
    return failures;
  }
  if (manifest.upstreams.length !== configuration.upstreams.length) {
    failures.push('recorded upstream manifest count differs from config/upstreams.json');
  }

  for (const upstream of configuration.upstreams) {
    const recorded = manifest.upstreams.find((candidate) => candidate.id === upstream.id);
    if (!recorded) {
      failures.push(`${upstream.id}: missing from recorded upstream manifest`);
      continue;
    }
    for (const field of ['name', 'version', 'role', 'package', 'sourceRoot', 'declaredLicense']) {
      if (recorded[field] !== upstream[field]) {
        failures.push(`${upstream.id}: recorded ${field} differs from config/upstreams.json`);
      }
    }
    if (recorded.packageSha256 !== upstream.sha256.toUpperCase()) {
      failures.push(`${upstream.id}: recorded package SHA-256 differs from config/upstreams.json`);
    }
    if (
      !recorded.source ||
      !Number.isInteger(recorded.source.fileCount) ||
      recorded.source.fileCount < 1 ||
      !Number.isInteger(recorded.source.totalBytes) ||
      recorded.source.totalBytes < 1 ||
      !/^[A-F0-9]{64}$/.test(recorded.source.treeSha256 ?? '') ||
      !recorded.source.extensions ||
      typeof recorded.source.extensions !== 'object'
    ) {
      failures.push(`${upstream.id}: recorded source evidence is incomplete`);
    }
  }

  return failures;
}

async function main() {
  const configuration = JSON.parse(await readFile(configurationPath, 'utf8'));
  const results = [];
  const failures = [];

  if (recordedOnly) {
    let manifest;
    try {
      manifest = JSON.parse(await readFile(outputPath, 'utf8'));
    } catch {
      console.error('AUDIT FAILURE: audit/generated/upstream-manifest.json is missing or invalid');
      process.exitCode = 1;
      return;
    }
    const recordedFailures = validateRecordedManifest(configuration, manifest);
    if (recordedFailures.length > 0) {
      for (const failure of recordedFailures) {
        console.error(`AUDIT FAILURE: ${failure}`);
      }
      process.exitCode = 1;
      return;
    }
    for (const result of manifest.upstreams) {
      console.log(`${result.id}: recorded ${result.packageSha256} (${result.source.fileCount} files, tree ${result.source.treeSha256})`);
    }
    console.log('Recorded upstream manifest is consistent; proprietary and ignored source bytes were not re-audited.');
    return;
  }

  for (const upstream of configuration.upstreams) {
    const packagePath = path.join(repositoryRoot, ...upstream.package.split('/'));
    const sourceRoot = path.join(repositoryRoot, ...upstream.sourceRoot.split('/'));
    const packageContent = await readFile(packagePath);
    const actualSha256 = sha256(packageContent);

    if (actualSha256 !== upstream.sha256.toUpperCase()) {
      failures.push(`${upstream.id}: expected ${upstream.sha256}, received ${actualSha256}`);
    }

    results.push({
      id: upstream.id,
      name: upstream.name,
      version: upstream.version,
      role: upstream.role,
      package: upstream.package,
      sourceRoot: upstream.sourceRoot,
      declaredLicense: upstream.declaredLicense,
      packageSha256: actualSha256,
      source: await auditTree(sourceRoot)
    });
  }

  const manifest = {
    schemaVersion: 1,
    deterministic: true,
    upstreams: results
  };
  const serialized = `${JSON.stringify(manifest, null, 2)}\n`;

  if (checkOnly) {
    try {
      const existing = await readFile(outputPath, 'utf8');
      if (existing !== serialized) {
        failures.push('audit/generated/upstream-manifest.json is stale; run npm run audit:upstreams');
      }
    } catch {
      failures.push('audit/generated/upstream-manifest.json is missing; run npm run audit:upstreams');
    }
  } else {
    await mkdir(path.dirname(outputPath), { recursive: true });
    await writeFile(outputPath, serialized, 'utf8');
  }

  if (failures.length > 0) {
    for (const failure of failures) {
      console.error(`AUDIT FAILURE: ${failure}`);
    }
    process.exitCode = 1;
    return;
  }

  for (const result of results) {
    console.log(`${result.id}: ${result.packageSha256} (${result.source.fileCount} files, tree ${result.source.treeSha256})`);
  }
  console.log(checkOnly ? 'Upstream manifest is current.' : `Wrote ${path.relative(repositoryRoot, outputPath)}.`);
}

await main();
