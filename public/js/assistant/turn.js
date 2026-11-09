// What the assistant panel shows while a reply is being made, and the steps it took.
// DOM-free so it runs under plain `node --test`. UI copy: plain words, no em or en dashes.

// [while running, after it ran]
const TOOL_COPY = {
  search_trips: ['Looking up trips', 'Looked up trips'],
  list_fares: ['Checking fares', 'Checked fares'],
  get_advisories: ['Checking travel advisories', 'Checked advisories'],
  get_page_context: ['Reading this page', 'Read this page'],
  navigate_to: ['Opening the page', 'Opened the page'],
  highlight_element: ['Finding that on the page', 'Pointed at it on the page'],
  start_tour: ['Starting the walkthrough', 'Started the walkthrough'],
  list_guide_targets: ['Finding things on this page', 'Found things on this page'],
  update_profile: ['Preparing your profile change', 'Prepared a profile change'],
  start_booking: ['Preparing your booking', 'Prepared your booking'],
  get_bookings: ['Checking bookings', 'Checked bookings'],
  get_booking: ['Opening the booking', 'Opened the booking'],
  cancel_booking: ['Preparing the cancellation', 'Prepared a cancellation'],
  get_my_upcoming_trip: ['Checking your next trip', 'Checked your next trip'],
  get_notifications: ['Checking notifications', 'Checked notifications'],
  mark_notifications_read: ['Preparing to clear notifications', 'Prepared to clear notifications'],
  operator_needs_attention: ['Checking what needs attention', 'Checked what needs attention'],
  get_port_departures: ['Checking departures', 'Checked departures'],
  get_trip_manifest: ['Reading the passenger manifest', 'Read the manifest'],
  lookup_ticket: ['Checking the ticket', 'Checked the ticket'],
  get_fleet: ['Checking the fleet', 'Checked the fleet'],
  get_audit_logs: ['Reading the audit log', 'Read the audit log'],
  get_analytics: ['Pulling the numbers', 'Pulled the numbers'],
  get_payment_reviews: ['Checking payment reviews', 'Checked payment reviews'],
  get_delivery_status: ['Checking SMS delivery', 'Checked SMS delivery'],
  list_staff_accounts: ['Checking staff accounts', 'Checked staff accounts'],
  set_staff_active: ['Preparing the staff change', 'Prepared a staff change'],
  set_boat_status: ['Preparing the vessel change', 'Prepared a vessel change'],
  update_fare: ['Preparing the fare change', 'Prepared a fare change'],
  create_trip: ['Preparing the new trip', 'Prepared a new trip'],
  update_trip: ['Preparing the trip change', 'Prepared a trip change'],
  publish_advisory: ['Preparing the advisory', 'Prepared an advisory'],
  approve_discount: ['Preparing the discount approval', 'Prepared a discount approval'],
};

export const TURN_COPY = Object.freeze({
  connecting: 'Connecting…',
  thinking: 'Thinking…',
  offline: 'Waiting for a connection…',
  confirm: 'Waiting for your confirmation',
  stopped: 'Stopped.',
  working: 'Working on it',
  worked: 'Used a tool',
});

export function toolLabel(name) {
  return (TOOL_COPY[name]?.[0] || TURN_COPY.working) + '…';
}

export function doneLabel(name) {
  return TOOL_COPY[name]?.[1] || TURN_COPY.worked;
}

// The one line shown where the reply will land while a turn is running.
// phase: 'idle' | 'waiting' | 'tool' | 'replying'.
export function pendingLabel({ phase = 'idle', tool = null, connection = 'ready', awaitingConfirm = false } = {}) {
  if (phase === 'idle' || phase === 'replying') return '';
  if (awaitingConfirm) return TURN_COPY.confirm;
  if (phase === 'tool') return toolLabel(tool);
  if (connection === 'offline') return TURN_COPY.offline;
  if (connection === 'connecting' || connection === 'reconnecting') return TURN_COPY.connecting;
  return TURN_COPY.thinking;
}

// Steps a turn really took, in order, repeats of the same tool collapsed. Unknown names stay generic.
export function stepList(names) {
  const out = [];
  for (const name of Array.isArray(names) ? names : []) {
    if (typeof name !== 'string' || !name) continue;
    if (out[out.length - 1]?.name === name) { out[out.length - 1].count += 1; continue; }
    out.push({ name, label: doneLabel(name), count: 1 });
  }
  return out;
}

// One short line for the collapsed steps row: "Looked up trips · Checked bookings" (+ "and 2 more").
export function stepsSummary(names, max = 3) {
  const steps = stepList(names);
  if (!steps.length) return '';
  const shown = steps.slice(0, max).map(s => s.label).join(' · ');
  return steps.length > max ? `${shown} and ${steps.length - max} more` : shown;
}
