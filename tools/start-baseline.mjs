import { runCLI } from '@wp-playground/cli';
import { cp, mkdir, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const requestedBaseline = process.argv[2] ?? 'filters';

if (!['filters', 'search'].includes(requestedBaseline)) {
  throw new Error(`Unknown baseline "${requestedBaseline}". Expected "filters" or "search".`);
}

const sourceMounts = [
  {
    hostPath: path.join(repositoryRoot, 'audit', 'source', 'woocommerce-10.7.0', 'woocommerce'),
    stagingName: 'woocommerce',
    vfsPath: '/wordpress/wp-content/plugins/woocommerce'
  },
  {
    hostPath: path.join(repositoryRoot, 'tests', 'fixtures', 'starfiniti-golden-fixtures'),
    stagingName: 'starfiniti-golden-fixtures',
    vfsPath: '/wordpress/wp-content/plugins/starfiniti-golden-fixtures'
  }
];

const baselineConfiguration = {
  filters: {
    port: 9400,
    blueprint: path.join(repositoryRoot, 'tests', 'playground', 'filters-baseline.blueprint.json'),
    mount: {
      hostPath: path.join(repositoryRoot, 'audit', 'source', 'fibofilters-pro'),
      stagingName: 'fibofilters',
      vfsPath: '/wordpress/wp-content/plugins/fibofilters'
    }
  },
  search: {
    port: 9401,
    blueprint: path.join(repositoryRoot, 'tests', 'playground', 'search-baseline.blueprint.json'),
    mount: {
      hostPath: path.join(repositoryRoot, 'audit', 'source', 'fibosearch-free', 'ajax-search-for-woocommerce'),
      stagingName: 'ajax-search-for-woocommerce',
      vfsPath: '/wordpress/wp-content/plugins/ajax-search-for-woocommerce'
    }
  }
}[requestedBaseline];

const stagingRoot = path.join(tmpdir(), 'starfiniti-playground', 'starfiniti-filters', requestedBaseline);
const normalizedTempRoot = path.resolve(tmpdir()) + path.sep;
if (!(path.resolve(stagingRoot) + path.sep).startsWith(normalizedTempRoot)) {
  throw new Error(`Refusing to stage outside the operating-system temporary directory: ${stagingRoot}`);
}

console.log(`Staging pinned plugin trees in ${stagingRoot}`);
await rm(stagingRoot, { recursive: true, force: true });
await mkdir(stagingRoot, { recursive: true });

const stagedMounts = [];
for (const mount of [...sourceMounts, baselineConfiguration.mount]) {
  const stagedPath = path.join(stagingRoot, mount.stagingName);
  console.log(`Staging ${mount.stagingName}...`);
  await cp(mount.hostPath, stagedPath, { recursive: true, force: true, preserveTimestamps: true });
  stagedMounts.push({ hostPath: stagedPath, vfsPath: mount.vfsPath });
}

const cliServer = await runCLI({
  command: 'server',
  php: '8.3',
  wp: '7.0.3',
  port: baselineConfiguration.port,
  workers: '1',
  login: true,
  debug: true,
  verbosity: 'normal',
  blueprint: baselineConfiguration.blueprint,
  'mount-before-install': stagedMounts
});

console.log(`STARFINITI_BASELINE_READY ${requestedBaseline} ${cliServer.serverUrl}`);
console.log('Press Ctrl+C to stop the ephemeral baseline server.');
await new Promise(() => {});
