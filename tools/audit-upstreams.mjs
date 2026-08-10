import { createHash } from 'node:crypto';
import { mkdir, readFile, readdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const configurationPath = path.join(repositoryRoot, 'config', 'upstreams.json');
const outputPath = path.join(repositoryRoot, 'audit', 'generated', 'upstream-manifest.json');
const checkOnly = process.argv.includes('--check');

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

async function main() {
  const configuration = JSON.parse(await readFile(configurationPath, 'utf8'));
  const results = [];
  const failures = [];

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

