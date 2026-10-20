<?php
namespace App\Http\Controllers;
use App\Models\AuditLog;
use App\Models\TripSchedule;
use Illuminate\Http\Request;
class ManifestController extends Controller {
 public function show(Request $request, TripSchedule $tripSchedule) {
  $tripSchedule->load('boat');
  $rows = [];
  foreach ($tripSchedule->bookings()->earning()->orderBy('id')->get() as $booking) {
   foreach ($booking->seat_numbers ?? [] as $i => $seat) {
    $person = $booking->seat_breakdown[$i] ?? [];
    $rows[] = ['reference'=>$booking->reference_number, 'passenger'=>$person['passenger_name'] ?? $booking->passenger_name, 'seat'=>$seat, 'category'=>$person['category'] ?? 'regular', 'boarded'=>($booking->is_boarded || isset(($booking->boarded_seats ?? [])[$seat])) ? 'Yes' : 'No'];
   }
  }
  AuditLog::record('manifest', 'Passenger Manifest Exported', 'Exported manifest for trip #'.$tripSchedule->id.' ('.count($rows).' passengers).');
  if ($request->query('format') === 'json') return response()->json(['trip_id'=>$tripSchedule->id, 'vessel'=>$tripSchedule->boat?->name, 'route'=>$tripSchedule->route, 'departure'=>$tripSchedule->departure_time, 'passenger_count'=>count($rows), 'passengers'=>$rows]);
  return response()->streamDownload(function() use ($rows, $tripSchedule) {
   $out = fopen('php://output', 'w');
   fputcsv($out, ['SeaPass Passenger Manifest', 'Trip '.$tripSchedule->id, $tripSchedule->boat?->name, $tripSchedule->route, $tripSchedule->departure_time->format('Y-m-d H:i')], ',', '"', '');
   fputcsv($out, ['Reference','Passenger','Seat','Category','Boarded'], ',', '"', '');
   foreach ($rows as $row) fputcsv($out, array_map(fn($v) => preg_match('/^[=+@\-\t\r]/', (string)$v) ? "'".$v : $v, array_values($row)), ',', '"', '');
   fclose($out);
  }, 'seapass-manifest-'.$tripSchedule->id.'.csv', ['Content-Type'=>'text/csv; charset=UTF-8']);
 }
}
