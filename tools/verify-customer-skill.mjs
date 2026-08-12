import { spawnSync } from 'node:child_process';
import { readFile, readdir, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const pluginRoot = path.join(root, 'plugin', 'starfiniti-search');
const skillRoot = path.join(pluginRoot, 'ai', 'starfiniti-search-assistant');
const mainHeader = await readFile(path.join(pluginRoot, 'starfiniti-search.php'), 'utf8');
const version = mainHeader.match(/Version:\s*([^\r\n]+)/i)?.[1].trim();
const failures = [];
const requireCondition = (condition, message) => { if (!condition) failures.push(message); };

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

const requiredFiles = [
  'SKILL.md', 'agents/openai.yaml', 'assets/diagnostic-intake.md',
  'assets/prototype-manifest.example.json', 'references/current-capabilities.md',
  'references/setup-and-troubleshooting.md', 'references/prototype-compatibility.md',
  'scripts/validate-prototype.mjs',
];
const files = await walk(skillRoot);
for (const required of requiredFiles) requireCondition(files.includes(required), `customer skill is missing ${required}`);
requireCondition(files.length === requiredFiles.length, `customer skill contains unexpected files: ${files.filter((file) => !requiredFiles.includes(file)).join(', ')}`);

const skill = await readFile(path.join(skillRoot, 'SKILL.md'), 'utf8');
requireCondition(/^---\r?\n[\s\S]+?\r?\n---/m.test(skill), 'SKILL.md frontmatter is invalid');
requireCondition(/name:\s*starfiniti-search-assistant/.test(skill), 'SKILL.md name is invalid');
requireCondition(/description:[^\n]+setup readiness[^\n]+prototypes/i.test(skill), 'SKILL.md description does not cover customer triggers');
requireCondition(new RegExp(`plugin-version:\\s*["']?${version.replaceAll('.', '\\.')}`).test(skill), 'customer skill version differs from the plugin');

const manifest = JSON.parse(await readFile(path.join(skillRoot, 'assets', 'prototype-manifest.example.json'), 'utf8'));
requireCondition(manifest.plugin_version === version, 'prototype manifest version differs from the plugin');
const validator = await readFile(path.join(skillRoot, 'scripts', 'validate-prototype.mjs'), 'utf8');
requireCondition(validator.includes(`const pluginVersion = '${version}'`), 'prototype validator version differs from the plugin');

const allText = (await Promise.all(files.map(async (file) => {
  const absolute = path.join(skillRoot, ...file.split('/'));
  requireCondition((await stat(absolute)).size <= 100_000, `${file} exceeds the bounded customer-skill file size`);
  return readFile(absolute, 'utf8');
}))).join('\n');
for (const forbidden of ['AGENTS.md', 'HANDOFF.md', 'spec/', '.agents/', '.claude/', 'C:\\Users\\', 'audit/packages', 'audit/source']) {
  requireCondition(!allText.includes(forbidden), `customer skill contains repository-only reference: ${forbidden}`);
}
for (const required of [
  'guidance only', 'Never request', 'Application Passwords', 'database dumps',
  'available_now', 'planned', 'unsupported', 'server-authoritative',
  'plan -> approve -> execute', 'does not connect to or modify',
]) requireCondition(allText.toLowerCase().includes(required.toLowerCase()), `customer skill is missing safety/capability phrase: ${required}`);

const linkPattern = /\[[^\]]+\]\(([^)]+)\)/g;
for (const match of skill.matchAll(linkPattern)) {
  const target = match[1];
  if (/^[a-z]+:/i.test(target) || target.startsWith('#')) continue;
  try { await stat(path.resolve(skillRoot, ...target.split('/'))); } catch { failures.push(`SKILL.md reference does not exist: ${target}`); }
}

const scenarios = JSON.parse(await readFile(path.join(root, 'tests', 'skills', 'customer-skill-scenarios.json'), 'utf8'));
const routeMarkers = {
  setup: '### Setup', troubleshooting: '### Troubleshooting', storefront: '### Storefront',
  prototype: '### Prototype', relevance: '### Relevance and analytics', capability: 'available_now', safety: '## Non-negotiable boundaries',
};
const routeContent = {
  setup: [skill, await readFile(path.join(skillRoot, 'references', 'setup-and-troubleshooting.md'), 'utf8')].join('\n'),
  troubleshooting: [skill, await readFile(path.join(skillRoot, 'references', 'setup-and-troubleshooting.md'), 'utf8')].join('\n'),
  storefront: [skill, await readFile(path.join(skillRoot, 'references', 'current-capabilities.md'), 'utf8')].join('\n'),
  prototype: [skill, await readFile(path.join(skillRoot, 'references', 'prototype-compatibility.md'), 'utf8')].join('\n'),
  relevance: [skill, await readFile(path.join(skillRoot, 'references', 'current-capabilities.md'), 'utf8')].join('\n'),
  capability: [skill, await readFile(path.join(skillRoot, 'references', 'current-capabilities.md'), 'utf8')].join('\n'),
  safety: [skill, await readFile(path.join(skillRoot, 'references', 'setup-and-troubleshooting.md'), 'utf8')].join('\n'),
};
for (const scenario of scenarios) {
  requireCondition(typeof scenario.prompt === 'string' && scenario.prompt.length > 10, `${scenario.id}: prompt is missing`);
  requireCondition(skill.includes(routeMarkers[scenario.route]), `${scenario.id}: route ${scenario.route} is not represented in SKILL.md`);
  requireCondition(Array.isArray(scenario.required_behavior) && scenario.required_behavior.length >= 2, `${scenario.id}: expected behavior is incomplete`);
  for (const behavior of scenario.required_behavior || []) {
    requireCondition(routeContent[scenario.route].toLowerCase().includes(behavior.toLowerCase()), `${scenario.id}: required behavior is not encoded in the route-specific customer guidance: ${behavior}`);
  }
}

for (const wrapper of [
  '.agents/skills/starfiniti-search-assistant/SKILL.md', '.claude/skills/starfiniti-search-assistant/SKILL.md',
  '.agents/skills/starfiniti-prototype-design/SKILL.md', '.claude/skills/starfiniti-prototype-design/SKILL.md',
]) {
  const text = await readFile(path.join(root, ...wrapper.split('/')), 'utf8');
  requireCondition(text.includes('plugin/starfiniti-search/ai/starfiniti-search-assistant/SKILL.md'), `${wrapper} does not point to the packaged canonical skill`);
}

const validation = spawnSync(process.execPath, [path.join(skillRoot, 'scripts', 'validate-prototype.mjs'), '--self-test'], { encoding: 'utf8' });
if (validation.stdout) process.stdout.write(validation.stdout);
if (validation.stderr) process.stderr.write(validation.stderr);
requireCondition(validation.status === 0, 'customer prototype validator self-test failed');

if (failures.length) {
  failures.forEach((failure) => console.error(`CUSTOMER SKILL FAILURE: ${failure}`));
  process.exitCode = 1;
} else {
  console.log(`Customer skill verification passed: ${files.length} files, ${scenarios.length} instruction-coverage cases, plugin version ${version}.`);
}
