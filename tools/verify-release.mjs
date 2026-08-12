import { createHash } from 'node:crypto';
import { readFile, readdir, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const plugin = path.join(root, 'plugin', 'starfiniti-search');
const forbiddenExtensions = new Set(['.exe', '.dll', '.phar', '.zip', '.tar', '.gz', '.7z']);
const forbiddenPatterns = [
  [/freemius\.com/i, 'commercial Freemius endpoint'],
  [/api\.fibofilters\.com/i, 'upstream commercial endpoint'],
  [/ajax-search-for-woocommerce/i, 'FiboSearch production slug'],
  [/FiboFiltersVendor\\/i, 'prefixed upstream namespace'],
  [/BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY/i, 'private key material'],
  [/(?:typesense|api)[_-]?key\s*[:=]\s*["'][^"']{8,}/i, 'embedded API credential'],
];

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

const failures = [];
const files = await walk(plugin);
const tree = createHash('sha256');
let bytes = 0;
for (const relative of files) {
  const absolute = path.join(plugin, ...relative.split('/'));
  const metadata = await stat(absolute);
  const content = await readFile(absolute);
  bytes += metadata.size;
  tree.update(relative).update('\0').update(createHash('sha256').update(content).digest('hex')).update('\n');
  if (forbiddenExtensions.has(path.extname(relative).toLowerCase())) {
    failures.push(`${relative}: prohibited binary/archive extension`);
  }
  if (content.includes(0)) {
    failures.push(`${relative}: unexpected binary content`);
    continue;
  }
  const text = content.toString('utf8');
  if (path.extname(relative).toLowerCase() === '.json') {
    try { JSON.parse(text); } catch (error) { failures.push(`${relative}: invalid JSON (${error.message})`); }
  }
  for (const [pattern, label] of forbiddenPatterns) {
    if (pattern.test(text)) failures.push(`${relative}: ${label}`);
  }
}

if (!files.includes('LICENSE') || !files.includes('readme.txt')) failures.push('plugin package must contain LICENSE and readme.txt');
for (const requiredSkillFile of [
  'ai/starfiniti-search-assistant/SKILL.md',
  'ai/starfiniti-search-assistant/agents/openai.yaml',
  'ai/starfiniti-search-assistant/assets/diagnostic-intake.md',
  'ai/starfiniti-search-assistant/assets/prototype-manifest.example.json',
  'ai/starfiniti-search-assistant/scripts/validate-prototype.mjs',
]) if (!files.includes(requiredSkillFile)) failures.push(`plugin package must contain ${requiredSkillFile}`);
const mainHeader = await readFile(path.join(plugin, 'starfiniti-search.php'), 'utf8');
const readme = await readFile(path.join(plugin, 'readme.txt'), 'utf8');
const field = (source, label) => source.match(new RegExp(`^\\s*(?:\\*\\s*)?${label}:\\s*(.+?)\\s*$`, 'mi'))?.[1] || '';
const mainVersion = field(mainHeader, 'Version');
const stableTag = field(readme, 'Stable tag');
const mainPhp = field(mainHeader, 'Requires PHP');
const readmePhp = field(readme, 'Requires PHP');
const mainWordPress = field(mainHeader, 'Requires at least');
const readmeWordPress = field(readme, 'Requires at least');
const wooFloor = field(mainHeader, 'WC requires at least');
const customerSkill = await readFile(path.join(plugin, 'ai', 'starfiniti-search-assistant', 'SKILL.md'), 'utf8');
const customerManifest = JSON.parse(await readFile(path.join(plugin, 'ai', 'starfiniti-search-assistant', 'assets', 'prototype-manifest.example.json'), 'utf8'));
if (!mainVersion || mainVersion !== stableTag) failures.push('plugin Version and readme Stable tag must match');
if (mainPhp !== '8.2' || readmePhp !== mainPhp) failures.push('PHP metadata floor must consistently declare 8.2');
if (mainWordPress !== '6.7' || readmeWordPress !== mainWordPress) failures.push('WordPress metadata floor must consistently declare 6.7');
if (wooFloor !== '9.0') failures.push('WooCommerce metadata floor must declare 9.0');
if (!customerSkill.includes(`plugin-version: "${mainVersion}"`) || customerManifest.plugin_version !== mainVersion) failures.push('customer skill version must match the plugin Version');
if (failures.length > 0) {
  failures.forEach((failure) => console.error(`RELEASE FAILURE: ${failure}`));
  process.exitCode = 1;
} else {
  console.log(`Release scan passed: ${files.length} files, ${bytes} bytes, tree ${tree.digest('hex').toUpperCase()}`);
}
