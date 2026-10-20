// Tours explain the actual SeaPass workflow without submitting forms or changing pages.
export function pageGuide(path) {
  const heading = ['main h1', 'Page overview', 'Start with the page title and its summary. The walkthrough explains controls without saving changes.'];
  const guides = {
    '/admin/dashboard': [heading,
      ['#needs-attention', 'Things to do', 'Start here for booking checks, departure preparation and passenger updates. Each row opens the screen where that work happens.'],
      ['#upcoming-departures', 'Upcoming departures', 'Check the departure time and vessel. Manifest downloads contain confirmed passengers for that specific trip.'],
      ['#recent-bookings', 'Recent bookings', 'Compare the passenger, journey and booking state. Pending payment and pending discount verification are different states.'],
      ['.port-day-summary', 'Today at the port', 'Use these current database totals for a quick check. Collected amounts are booking records, not a bank settlement statement.']],
    '/admin/boats': [heading,
      ['#openBoatModal', 'Add a vessel', 'Open this form to enter the vessel name, capacity and availability. Opening it does not save a vessel.'],
      ['.boats-grid', 'Vessel availability', 'Review capacity and maintenance state before scheduling a boat. Resolve upcoming passenger bookings before taking a vessel out of service.']],
    '/admin/trip-schedules': [heading,
      ['#openScheduleModal', 'Create a schedule', 'Choose an active vessel, route and departure time. You can create one departure or a recurring schedule. Review capacity before saving.'],
      ['.trip-tabs', 'Trip states', 'Switch between scheduled, active, completed and recurring trips. A recurring template generates individual departures.'],
      ['#scheduled-trip-grid', 'Departure details', 'Review each departure and its available seats. Use its manifest link for the confirmed passenger list.']],
    '/admin/tickets': [heading,
      ['.ticket-tabs', 'Booking states', 'Separate unpaid reservations from paid tickets awaiting an ID check and confirmed tickets. Only confirmed tickets can be boarded.'],
      ['.ticket-section', 'Booking counter', 'Use the counter to help walk-in passengers. Select the correct departure and passenger fare categories, then review the amount and seat allocation.']],
    '/admin/travel-advisory': [heading,
      ['#advisoryForm', 'Prepare an advisory', 'Enter the affected route, effective time and clear travel instructions. Weather notices are written by port staff.'],
      ['#adv_save_draft', 'Save without publishing', 'Save as Draft keeps the message out of the passenger feed. Review it before publishing.'],
      ['#advAlertsTable', 'Published notices and sending status', 'Review the notice and its actual sending state. A queued or accepted SMS does not prove that a passenger received it.']],
    '/admin/fare-management': [heading,
      ['main form', 'Edit route fares', 'Set regular, student and senior fares for the correct direction. Changes apply only after you save. Checkout calculates the fare on the server.'],
      ['main table', 'Current fare matrix', 'Check both directions and discount rates here before helping a passenger book.']],
    '/admin/reports-audit': [heading,
      ['.reports-filters', 'Choose the reporting period', 'Set the date filters before reading or exporting totals. Empty results are different from an unavailable report.'],
      ['.reports-section', 'Sales and activity', 'Review recorded collections and refunds separately. Use the audit log to inspect actual actions instead of inferring a bank payout.']],
    '/admin/sms': [heading,
      ['#sms-connection-heading', 'Sending connection', 'SMS needs configured TextLink access and its connected Android phone with a working SIM. Not configured means SeaPass cannot send yet.'],
      ['#sms-log-heading', 'Message activity', 'Check queued, accepted, failed or unknown states. Before retrying an uncertain message, verify in TextLink that it was not already sent.']],
  };
  return guides[path] || [heading,
    ['main form', 'Account controls', 'Review the current values, then use the explicit save action. Validation errors must be resolved before changes are saved.'],
    ['main table', 'Account records', 'Use these records only for the port work permitted by your account.']];
}
