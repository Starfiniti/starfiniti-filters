import assert from 'node:assert/strict';
import test from 'node:test';
import { RequestControls } from '../src/request-controls.js';

test('request controls bound client identities and rotate once per minute', () => {
  const controls = new RequestControls({ maximumBodyConcurrency: 2, maximumConcurrency: 2, maximumTrackedClients: 3, requestsPerMinute: 2 });
  const firstWindow = 120_000;
  assert.equal(controls.admit('one', firstWindow), true);
  assert.equal(controls.admit('one', firstWindow), true);
  assert.equal(controls.admit('one', firstWindow), false);
  assert.equal(controls.admit('two', firstWindow), true);
  assert.equal(controls.admit('three', firstWindow), true);
  assert.equal(controls.admit('four', firstWindow), false);
  assert.equal(controls.trackedClients, 3);
  assert.equal(controls.admit('four', firstWindow + 60_000), true);
  assert.equal(controls.trackedClients, 1);
});

test('execution concurrency is separate, bounded, and released idempotently', () => {
  const controls = new RequestControls({ maximumBodyConcurrency: 1, maximumConcurrency: 1, maximumTrackedClients: 10, requestsPerMinute: 10 });
  const leaveBody = controls.enterBody();
  assert.ok(leaveBody);
  assert.equal(controls.enterBody(), undefined);
  leaveBody();
  leaveBody();
  assert.equal(controls.bodyReads, 0);
  controls.enterBody()?.();
  const leave = controls.enterExecution();
  assert.ok(leave);
  assert.equal(controls.enterExecution(), undefined);
  leave();
  leave();
  assert.equal(controls.inFlight, 0);
  assert.ok(controls.enterExecution());
});
