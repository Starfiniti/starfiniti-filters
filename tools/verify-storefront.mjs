import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = await readFile(path.join(root, 'plugin', 'starfiniti-search', 'assets', 'search.js'), 'utf8');
const required = [
  ['AbortController', 'abortable requests'], ['requestSequence !== sequence', 'late-response guard'],
  ['compositionstart', 'IME composition handling'], ['popstate', 'browser history restoration'],
  ['pushState', 'filter URL state'], ['document.createTextNode', 'text-only rendering'],
  ['aria-busy', 'loading accessibility'], ["'results-rendered'", 'documented event surface'],
  ['safeInternalUrl', 'same-origin redirect validation'], ["raw.startsWith('//')", 'protocol-relative redirect denial'],
  ['url.origin === window.location.origin', 'redirect origin equality check'], ['window.location.assign(redirect)', 'validated redirect application'],
  ['Object.freeze({ initialize })', 'headless initialization API'], ["starfinitiSearchInitialized === 'true'", 'idempotent search initialization'],
  ["starfinitiDiscoveryInitialized === 'true'", 'idempotent discovery initialization'],
  ["root.dataset.mobileOverlay = 'open'", 'mobile overlay state'], ["root.setAttribute('aria-modal', 'true')", 'mobile modal semantics'],
  ["activeOverlayClose(false)", 'single active mobile overlay'], ["input.focus({ preventScroll: true })", 'mobile focus restoration'],
  ["event.key !== 'Tab'", 'mobile focus containment'], ["detailsToggle.setAttribute('aria-expanded'", 'accessible details disclosure'],
  ["details.setAttribute('role', 'region')", 'details region semantics'], ["projection.content?.excerpt", 'typed details projection'],
  ["image.addEventListener('error', () => image.remove()", 'failed-image recovery'],
];
const failures = required.filter(([needle]) => !source.includes(needle)).map(([, label]) => `missing ${label}`);
for (const forbidden of ['innerHTML', 'insertAdjacentHTML', 'eval(', 'new Function', 'document.write']) {
  if (source.includes(forbidden)) failures.push(`unsafe renderer primitive: ${forbidden}`);
}
if (failures.length) {
  failures.forEach((failure) => console.error(`STOREFRONT FAILURE: ${failure}`));
  process.exitCode = 1;
} else {
  console.log('Storefront static contract passed: aborts, sequencing, IME, history, safe rendering, mobile overlay, details disclosure, accessibility, and events.');
}
