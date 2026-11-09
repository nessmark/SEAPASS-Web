import test from 'node:test';
import assert from 'node:assert/strict';
import { toolLabel, doneLabel, pendingLabel, stepList, stepsSummary, TURN_COPY } from '../../public/js/assistant/turn.js';

test('every running tool gets a human label, unknown tools stay generic', () => {
  assert.equal(toolLabel('search_trips'), 'Looking up trips…');
  assert.equal(toolLabel('get_analytics'), 'Pulling the numbers…');
  assert.equal(toolLabel('made_up_tool'), 'Working on it…');
  assert.equal(doneLabel('get_bookings'), 'Checked bookings');
  assert.equal(doneLabel('made_up_tool'), 'Used a tool');
});

test('pending line follows the turn and the connection', () => {
  assert.equal(pendingLabel({ phase: 'idle' }), '');
  assert.equal(pendingLabel({ phase: 'replying' }), '');
  assert.equal(pendingLabel({ phase: 'waiting' }), TURN_COPY.thinking);
  assert.equal(pendingLabel({ phase: 'waiting', connection: 'connecting' }), TURN_COPY.connecting);
  assert.equal(pendingLabel({ phase: 'waiting', connection: 'offline' }), TURN_COPY.offline);
  assert.equal(pendingLabel({ phase: 'tool', tool: 'lookup_ticket' }), 'Checking the ticket…');
  assert.equal(pendingLabel({ phase: 'tool', tool: 'update_trip', awaitingConfirm: true }), TURN_COPY.confirm);
});

test('steps keep order, collapse repeats and ignore junk', () => {
  const steps = stepList(['search_trips', 'search_trips', 'get_bookings', null, '', 42, 'search_trips']);
  assert.deepEqual(steps.map(s => [s.name, s.count]), [['search_trips', 2], ['get_bookings', 1], ['search_trips', 1]]);
});

test('steps summary is short and says how many more', () => {
  assert.equal(stepsSummary([]), '');
  assert.equal(stepsSummary(['search_trips', 'get_bookings']), 'Looked up trips · Checked bookings');
  assert.equal(stepsSummary(['list_fares', 'get_fleet', 'get_analytics', 'get_audit_logs', 'lookup_ticket']), 'Checked fares · Checked the fleet · Pulled the numbers and 2 more');
});

test('UI copy has no em or en dashes', () => {
  const all = ['search_trips', 'get_payment_reviews', 'approve_discount', 'x'].flatMap(n => [toolLabel(n), doneLabel(n)]).concat(Object.values(TURN_COPY));
  for (const line of all) assert.ok(!/[–—]/.test(line), line);
});
