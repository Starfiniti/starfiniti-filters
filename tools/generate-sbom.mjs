import { createHash } from 'node:crypto';
import { readFile, readdir, stat, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pluginRoot = path.join(root, 'plugin', 'starfiniti-search');
const dist = path.join(root, 'dist');
const zipPath = path.join(dist, 'starfiniti-search.zip');

async function walk(directory, relative = '') {
  const entries = await readdir(path.join(directory, relative), { withFileTypes: true });
  const files = [];
  for (const entry of entries.sort((a, b) => a.name.localeCompare(b.name, 'en'))) {
    const name = path.posix.join(relative.split(path.sep).join('/'), entry.name);
    if (entry.isDirectory()) files.push(...await walk(directory, name));
    else if (entry.isFile()) files.push(name);
  }
  return files;
}

const files = await walk(pluginRoot);
const tree = createHash('sha256');
let sourceBytes = 0;
for (const relative of files) {
  const content = await readFile(path.join(pluginRoot, ...relative.split('/')));
  sourceBytes += content.length;
  tree.update(relative).update('\0').update(createHash('sha256').update(content).digest('hex')).update('\n');
}
const treeHash = tree.digest('hex');
const zip = await readFile(zipPath);
const zipHash = createHash('sha256').update(zip).digest('hex');
const header = await readFile(path.join(pluginRoot, 'starfiniti-search.php'), 'utf8');
const version = header.match(/Version:\s*([^\r\n]+)/i)?.[1].trim() || 'unknown';
const skillZipPath = path.join(dist, `starfiniti-search-ai-skill-${version}.zip`);
const skillZip = await readFile(skillZipPath);
const skillZipHash = createHash('sha256').update(skillZip).digest('hex');
const serialHex = treeHash.slice(0, 32).split('');
serialHex[12] = '5';
serialHex[16] = ['8', '9', 'a', 'b'][Number.parseInt(serialHex[16], 16) % 4];
const serial = `${serialHex.slice(0, 8).join('')}-${serialHex.slice(8, 12).join('')}-${serialHex.slice(12, 16).join('')}-${serialHex.slice(16, 20).join('')}-${serialHex.slice(20).join('')}`;
const component = {
  type: 'application', 'bom-ref': `pkg:wordpress/starfiniti-search@${version}`,
  group: 'Starfiniti', name: 'starfiniti-search', version,
  hashes: [{ alg: 'SHA-256', content: zipHash }],
  licenses: [{ license: { id: 'GPL-3.0-only' } }],
  purl: `pkg:wordpress/starfiniti-search@${version}`,
  externalReferences: [{ type: 'vcs', url: 'https://github.com/Starfiniti/starfiniti-filters' }],
  properties: [
    { name: 'starfiniti:artifact:file-count', value: String(files.length) },
    { name: 'starfiniti:artifact:source-bytes', value: String(sourceBytes) },
    { name: 'starfiniti:artifact:source-tree-sha256', value: treeHash },
    { name: 'starfiniti:artifact:contains-third-party-runtime', value: 'false' },
    { name: 'starfiniti:artifact:contains-customer-skill', value: 'true' },
    { name: 'starfiniti:runtime:php-minimum', value: '8.1' },
    { name: 'starfiniti:runtime:wordpress-minimum', value: '6.7' },
    { name: 'starfiniti:runtime:woocommerce-minimum', value: '9.0' },
  ],
};
const skillComponent = {
  type: 'application',
  'bom-ref': `urn:starfiniti:skill:starfiniti-search-assistant:${version}`,
  group: 'Starfiniti',
  name: 'starfiniti-search-assistant',
  version,
  hashes: [{ alg: 'SHA-256', content: skillZipHash }],
  licenses: [{ license: { id: 'GPL-3.0-only' } }],
  properties: [
    { name: 'starfiniti:skill:guidance-only', value: 'true' },
    { name: 'starfiniti:skill:requires-site-credentials', value: 'false' },
  ],
};
const bom = {
  bomFormat: 'CycloneDX', specVersion: '1.6', serialNumber: `urn:uuid:${serial}`, version: 1,
  metadata: { timestamp: '2026-08-09T00:00:00Z', tools: { components: [{ type: 'application', name: 'starfiniti-search-build', version: '1' }] }, component },
  components: [skillComponent], dependencies: [{ ref: component['bom-ref'], dependsOn: [] }, { ref: skillComponent['bom-ref'], dependsOn: [] }],
};
const bomText = `${JSON.stringify(bom, null, 2)}\n`;
await writeFile(path.join(dist, 'starfiniti-search.cdx.json'), bomText, 'utf8');
const manifest = {
  schemaVersion: 1, version, reproducibleTimestamp: '2026-08-09T00:00:00Z',
  artifacts: [
    { path: 'starfiniti-search.zip', bytes: zip.length, sha256: zipHash },
    { path: `starfiniti-search-ai-skill-${version}.zip`, bytes: skillZip.length, sha256: skillZipHash },
    { path: 'starfiniti-search.cdx.json', bytes: Buffer.byteLength(bomText), sha256: createHash('sha256').update(bomText).digest('hex') },
  ],
  source: { files: files.length, bytes: sourceBytes, treeSha256: treeHash },
};
await writeFile(path.join(dist, 'release-manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
console.log(`CycloneDX SBOM and release manifest generated for ${version}; ZIP SHA256 ${zipHash.toUpperCase()}`);
