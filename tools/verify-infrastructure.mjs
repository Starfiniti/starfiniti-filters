import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const root = resolve(import.meta.dirname, '..');

function read(relativePath) {
  return readFileSync(resolve(root, relativePath), 'utf8');
}

function requireCondition(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const lock = JSON.parse(read('infra/runtime-lock.json'));
requireCondition(lock.platform === 'linux/amd64', 'runtime lock must target linux/amd64');

const digestPattern = /^sha256:[a-f0-9]{64}$/;
for (const [name, image] of Object.entries(lock.images)) {
  requireCondition(digestPattern.test(image.manifest_digest), `${name} manifest digest is invalid`);
}

requireCondition(
  digestPattern.test(lock.images.typesense.amd64_digest),
  'Typesense amd64 child digest is invalid',
);
requireCondition(
  /^[a-f0-9]{64}$/.test(lock.images.typesense.validation_binary_sha256),
  'Typesense validation binary digest is invalid',
);

const composeFiles = [
  'infra/certification/compose.yaml',
  'infra/observability/compose.yaml',
  'infra/observability/cert-agent.compose.yaml',
];

const lockedDigests = new Map(
  Object.values(lock.images).map((image) => [image.image, image.manifest_digest]),
);
let imageReferenceCount = 0;

for (const composeFile of composeFiles) {
  const compose = read(composeFile);
  for (const line of compose.split(/\r?\n/)) {
    const imageLine = line.match(/^\s*image:\s*([^@\s]+)@(sha256:[a-f0-9]{64})\s*$/);
    if (!imageLine) {
      requireCondition(!/^\s*image:\s*/.test(line), `${composeFile} contains an unpinned image`);
      continue;
    }
    const [, imageName, digest] = imageLine;
    requireCondition(lockedDigests.has(imageName), `${composeFile} uses unlocked image ${imageName}`);
    requireCondition(
      lockedDigests.get(imageName) === digest,
      `${composeFile} digest for ${imageName} differs from runtime-lock.json`,
    );
    imageReferenceCount += 1;
  }
}

const typesenseCompose = read('infra/certification/compose.yaml');
requireCondition(
  typesenseCompose.includes('"127.0.0.1:8108:8108"'),
  'Typesense must bind only to loopback',
);
requireCondition(typesenseCompose.includes('mem_limit: 3g'), 'Typesense memory cap changed');
requireCondition(typesenseCompose.includes('cap_drop:\n      - ALL'), 'Typesense capabilities are not dropped');

const observabilityCompose = read('infra/observability/compose.yaml');
for (const loopbackPort of ['"127.0.0.1:9090:9090"', '"127.0.0.1:3000:3000"']) {
  requireCondition(observabilityCompose.includes(loopbackPort), `${loopbackPort} is not loopback-only`);
}
requireCondition(
  observabilityCompose.includes('"10.10.10.61:3100:3100"'),
  'Loki must bind to the exact private ops address',
);

const memoryValues = [...observabilityCompose.matchAll(/^\s+mem_limit:\s+(\d+)m\s*$/gm)].map(
  (match) => Number.parseInt(match[1], 10),
);
const totalMemoryMiB = memoryValues.reduce((sum, value) => sum + value, 0);
requireCondition(memoryValues.length === 5, 'observability service memory limits are incomplete');
requireCondition(totalMemoryMiB <= 1536, `observability caps total ${totalMemoryMiB} MiB`);

const backupScript = read('infra/backup/pve-borg-backup.sh');
for (const requiredFragment of [
  '--content-from-command',
  '--compress 0',
  'PVE_MAX_DATA_PERCENT',
  'PVE_MAX_METADATA_PERCENT',
  'BORG_RELOCATED_REPO_ACCESS_IS_OK=no',
]) {
  requireCondition(backupScript.includes(requiredFragment), `backup safety fragment missing: ${requiredFragment}`);
}

const secretTemplate = read('infra/certification/typesense-server.ini.example');
requireCondition(
  secretTemplate.includes('<generate-and-store-outside-the-repository>'),
  'Typesense template must retain the non-secret marker',
);
requireCondition(
  !/^api-key\s*=\s*[A-Za-z0-9_-]{32,}\s*$/m.test(secretTemplate),
  'Typesense template appears to contain a real key',
);

const certifier = read('infra/certification/certify_typesense.py');
for (const cleanupPath of ['/aliases/', '/synonym_sets/', '/curation_sets/', '/collections/', '/keys/']) {
  requireCondition(certifier.includes(cleanupPath), `certifier cleanup path missing: ${cleanupPath}`);
}

console.log(
  JSON.stringify({
    status: 'passed',
    locked_images: Object.keys(lock.images).length,
    compose_image_references: imageReferenceCount,
    observability_memory_mib: totalMemoryMiB,
  }),
);
