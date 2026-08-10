import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const sourceRoot = path.join(repoRoot, 'plugin', 'starfiniti-search', 'src');
const domainRoot = path.join(sourceRoot, 'Domain');
const storefrontRoot = path.join(sourceRoot, 'Infrastructure', 'WordPress', 'Storefront');
const extensionDocumentation = path.join(repoRoot, 'docs', 'EXTENSION_POINTS.md');

function filesUnder(directory, extension) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const target = path.join(directory, entry.name);
    return entry.isDirectory() ? filesUnder(target, extension) : (target.endsWith(extension) ? [target] : []);
  });
}

function fail(message, file) {
  const relative = path.relative(repoRoot, file).replaceAll('\\', '/');
  throw new Error(`${message}: ${relative}`);
}

for (const file of filesUnder(domainRoot, '.php')) {
  const source = fs.readFileSync(file, 'utf8');
  if (/Starfiniti\\Search\\Infrastructure/.test(source)) fail('Domain layer imports infrastructure', file);
  if (/\b(?:wpdb|WP_[A-Za-z0-9_]*|WC_[A-Za-z0-9_]*|WooCommerce|wp_[a-z0-9_]+|get_option|update_option|add_action|add_filter)\b/.test(source)) {
    fail('Domain layer depends on a WordPress or WooCommerce runtime symbol', file);
  }
}

for (const file of filesUnder(storefrontRoot, '.php')) {
  const source = fs.readFileSync(file, 'utf8');
  if (/Infrastructure\\(?:Typesense|WordPress\\LocalIndex)/.test(source) || /\b(?:TypesenseSearchProvider|LocalSearchProvider)\b/.test(source)) {
    fail('Storefront branches on a concrete search provider', file);
  }
}

const interfacePath = path.join(domainRoot, 'Provider', 'SearchProvider.php');
const providerInterface = fs.readFileSync(interfacePath, 'utf8');
for (const method of ['id', 'capabilities', 'search']) {
  if (!new RegExp(`function\\s+${method}\\s*\\(`).test(providerInterface)) {
    fail(`SearchProvider is missing required method ${method}`, interfacePath);
  }
}

const implementations = filesUnder(path.join(sourceRoot, 'Infrastructure'), '.php').filter((file) => {
  return /implements\s+SearchProvider\b/.test(fs.readFileSync(file, 'utf8'));
});
if (implementations.length < 2) {
  throw new Error(`Expected at least two independent SearchProvider adapters; found ${implementations.length}.`);
}

const documentedExtensions = fs.readFileSync(extensionDocumentation, 'utf8');
for (const required of ['Contract version: `1.0`', 'SearchProvider', 'IntegrationRegistry', 'CONTROL_API.md']) {
  if (!documentedExtensions.includes(required)) {
    fail(`Extension-point documentation is missing ${required}`, extensionDocumentation);
  }
}

console.log(`Architecture boundaries verified: ${filesUnder(domainRoot, '.php').length} domain files are infrastructure-free; ${implementations.length} provider adapters implement the versioned provider port; storefront has no concrete-provider branch; extension contracts are documented at version 1.0.`);
