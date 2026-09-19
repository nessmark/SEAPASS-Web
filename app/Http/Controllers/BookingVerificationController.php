<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookingVerificationController extends Controller
{
    /**
     * Verify ticket and mark passenger as boarded.
     * POST /api/bookings/verify-ticket
     */
    public function verifyTicket(Request $request): JsonResponse
    {
        // 1. Extract reference number from direct field or raw QR payload
        $rawPayload = $request->input('reference_number') 
            ?? $request->input('booking_ref')
            ?? $request->input('qr_code') 
            ?? $request->input('code')
            ?? $request->getContent();

        $referenceNumber = null;
        $fallbackPassengerName = null;
        $fallbackVessel = null;
        $fallbackSeat = null;

        if (is_string($rawPayload)) {
            $trimmed = trim($rawPayload);
            // Check if string is a JSON object from generated QR payload
            if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $referenceNumber = trim((string) ($decoded['booking_ref'] ?? $decoded['reference_number'] ?? $decoded['ref'] ?? ''));
                    $fallbackPassengerName = $decoded['passenger_name'] ?? null;
                    $fallbackVessel = $decoded['vessel'] ?? $decoded['vessel_name'] ?? null;
                    $fallbackSeat = $decoded['seat'] ?? $decoded['seat_number'] ?? null;
                }
            }
            if (!$referenceNumber) {
                $referenceNumber = $trimmed;
            }
        } elseif (is_array($rawPayload)) {
            $referenceNumber = trim((string) ($rawPayload['booking_ref'] ?? $rawPayload['reference_number'] ?? $rawPayload['ref'] ?? ''));
            $fallbackPassengerName = $rawPayload['passenger_name'] ?? null;
            $fallbackVessel = $rawPayload['vessel'] ?? $rawPayload['vessel_name'] ?? null;
            $fallbackSeat = $rawPayload['seat'] ?? $rawPayload['seat_number'] ?? null;
        }

        if (empty($referenceNumber)) {
            return response()->json([
                'success' => false,
                'status' => 'INVALID_PAYLOAD',
                'message' => 'No booking reference number provided in QR scan.',
            ], 422);
        }

        // Clean any quotes or extraneous whitespace
        $referenceNumber = trim($referenceNumber, " \t\n\r\0\x0B\"'");

        // 2. Find booking with related schedule and vessel
        $booking = Booking::with(['tripSchedule.boat', 'passenger'])
            ->where('reference_number', $referenceNumber)
            ->orWhere('id', is_numeric($referenceNumber) ? (int)$referenceNumber : 0)
            ->first();

        if (!$booking) {
            return response()->json([
                'success' => false,
                'status' => 'NOT_FOUND',
                'message' => "Ticket reference {$referenceNumber} was not found in the system.",
                'data' => [
                    'reference_number' => $referenceNumber,
                    'passenger_name' => $fallbackPassengerName,
                    'route' => null,
                    'vessel_name' => $fallbackVessel,
                    'seat_number' => $fallbackSeat,
                    'departure_time' => null,
                    'booking_status' => null,
                ],
            ], 404);
        }

        $vesselName = $booking->tripSchedule?->boat?->name ?? 'Commercial Vessel';
        $seatList = is_array($booking->seat_numbers) 
            ? implode(', ', $booking->seat_numbers) 
            : ($booking->seat_numbers ?: 'General Allocation');

        $tripDateFormatted = $booking->trip_date ? $booking->trip_date->format('Y-m-d') : null;
        $todayFormatted = now('Asia/Manila')->format('Y-m-d');

        // Parse scheduled departure datetime in Asia/Manila timezone
        $tripDateStr = $booking->trip_date ? $booking->trip_date->format('Y-m-d') : $todayFormatted;
        $rawDepartureTime = $booking->departure_time_slot ?? $booking->tripSchedule?->departure_time ?? '07:30';

        try {
            $departureDateTime = \Carbon\Carbon::parse("{$tripDateStr} {$rawDepartureTime}", 'Asia/Manila');
        } catch (\Throwable $e) {
            $departureDateTime = now('Asia/Manila');
        }

        $now = now('Asia/Manila');
        $boardingOpenTime = $departureDateTime->copy()->subHours(2);
        $boardingCloseTime = $departureDateTime->copy()->subMinutes(15);

        $departureTimeFormatted = $departureDateTime->format('h:i A');
        $boardingOpenFormatted = $boardingOpenTime->format('h:i A');
        $boardingCloseFormatted = $boardingCloseTime->format('h:i A');

        $bookingStatusUpper = strtoupper(trim((string)$booking->status));

        // 3. Validation Check 1: Check Booking Status (PENDING, CANCELLED, CONFIRMED)
        if ($bookingStatusUpper === 'PENDING') {
            return response()->json([
                'success' => false,
                'status' => 'STATUS_PENDING',
                'message' => 'Boarding Declined: Ticket payment is still PENDING / Unconfirmed.',
                'data' => [
                    'reference_number' => $booking->reference_number,
                    'passenger_name' => $booking->passenger_name,
                    'route' => $booking->route,
                    'vessel_name' => $vesselName,
                    'seat_number' => $seatList,
                    'departure_time' => $departureTimeFormatted,
                    'trip_date' => $tripDateFormatted,
                    'booking_status' => 'PENDING',
                    'boarding_open_time' => $boardingOpenFormatted,
                    'boarding_close_time' => $boardingCloseFormatted,
                    'boarded_at' => null,
                ],
            ], 422);
        }

        if ($bookingStatusUpper === 'CANCELLED' || $bookingStatusUpper === 'CANCELED') {
            return response()->json([
                'success' => false,
                'status' => 'STATUS_CANCELLED',
                'message' => 'Boarding Declined: Ticket has been CANCELLED.',
                'data' => [
                    'reference_number' => $booking->reference_number,
                    'passenger_name' => $booking->passenger_name,
                    'route' => $booking->route,
                    'vessel_name' => $vesselName,
                    'seat_number' => $seatList,
                    'departure_time' => $departureTimeFormatted,
                    'trip_date' => $tripDateFormatted,
                    'booking_status' => $bookingStatusUpper,
                    'boarding_open_time' => $boardingOpenFormatted,
                    'boarding_close_time' => $boardingCloseFormatted,
                    'boarded_at' => null,
                ],
            ], 422);
        }

        if ($bookingStatusUpper !== 'CONFIRMED') {
            return response()->json([
                'success' => false,
                'status' => 'INVALID_STATUS',
                'message' => "Ticket is {$bookingStatusUpper}. Only CONFIRMED bookings are eligible for boarding.",
                'data' => [
                    'reference_number' => $booking->reference_number,
                    'passenger_name' => $booking->passenger_name,
                    'route' => $booking->route,
                    'vessel_name' => $vesselName,
                    'seat_number' => $seatList,
                    'departure_time' => $departureTimeFormatted,
                    'trip_date' => $tripDateFormatted,
                    'booking_status' => $bookingStatusUpper,
                    'boarding_open_time' => $boardingOpenFormatted,
                    'boarding_close_time' => $boardingCloseFormatted,
                    'boarded_at' => null,
                ],
            ], 422);
        }

        // 4. Validation Check 2: Reject if passenger has already boarded
        if ($booking->is_boarded || !empty($booking->boarded_at)) {
            $boardedTime = $booking->boarded_at ? $booking->boarded_at->format('h:i A') : 'earlier';
            $boardedDate = $booking->boarded_at ? $booking->boarded_at->format('M d, Y') : '';

            return response()->json([
                'success' => false,
                'status' => 'ALREADY_BOARDED',
                'message' => "Ticket already used/boarded at {$boardedTime} ({$boardedDate}).",
                'data' => [
                    'reference_number' => $booking->reference_number,
                    'passenger_name' => $booking->passenger_name,
                    'route' => $booking->route,
                    'vessel_name' => $vesselName,
                    'seat_number' => $seatList,
                    'departure_time' => $departureTimeFormatted,
                    'trip_date' => $tripDateFormatted,
                    'booking_status' => 'CONFIRMED',
                    'boarding_open_time' => $boardingOpenFormatted,
                    'boarding_close_time' => $boardingCloseFormatted,
                    'boarded_at' => $booking->boarded_at ? $booking->boarded_at->format('Y-m-d H:i:s') : null,
                ],
            ], 409);
        }

        // 5. Validation Check 3: Schedule Date Match Check
        if ($tripDateFormatted && $tripDateFormatted !== $todayFormatted) {
            $humanTripDate = $booking->trip_date->format('M d, Y');
            if ($tripDateFormatted < $todayFormatted) {
                return response()->json([
                    'success' => false,
                    'status' => 'EXPIRED_TRIP_DATE',
                    'message' => "Ticket is EXPIRED. Scheduled departure was on {$humanTripDate}.",
                    'data' => [
                        'reference_number' => $booking->reference_number,
                        'passenger_name' => $booking->passenger_name,
                        'route' => $booking->route,
                        'vessel_name' => $vesselName,
                        'seat_number' => $seatList,
                        'departure_time' => $departureTimeFormatted,
                        'trip_date' => $tripDateFormatted,
                        'booking_status' => 'CONFIRMED',
                        'boarding_open_time' => $boardingOpenFormatted,
                        'boarding_close_time' => $boardingCloseFormatted,
                        'boarded_at' => null,
                    ],
                ], 422);
            } else {
                return response()->json([
                    'success' => false,
                    'status' => 'FUTURE_TRIP_DATE',
                    'message' => "Ticket is for a FUTURE TRIP on {$humanTripDate}. Not valid for today's departure.",
                    'data' => [
                        'reference_number' => $booking->reference_number,
                        'passenger_name' => $booking->passenger_name,
                        'route' => $booking->route,
                        'vessel_name' => $vesselName,
                        'seat_number' => $seatList,
                        'departure_time' => $departureTimeFormatted,
                        'trip_date' => $tripDateFormatted,
                        'booking_status' => 'CONFIRMED',
                        'boarding_open_time' => $boardingOpenFormatted,
                        'boarding_close_time' => $boardingCloseFormatted,
                        'boarded_at' => null,
                    ],
                ], 422);
            }
        }

        // 6. Validation Check 4: Boarding Time Window Check
        // Status 1: TOO EARLY (More than 2 hours / > 120 minutes before departure)
        if ($now->lt($boardingOpenTime)) {
            return response()->json([
                'success' => false,
                'status' => 'TOO_EARLY',
                'message' => "Boarding not open yet. Please wait until {$boardingOpenFormatted} (boarding opens from {$boardingOpenFormatted} to {$boardingCloseFormatted}).",
                'data' => [
                    'reference_number' => $booking->reference_number,
                    'passenger_name' => $booking->passenger_name,
                    'route' => $booking->route,
                    'vessel_name' => $vesselName,
                    'seat_number' => $seatList,
                    'departure_time' => $departureTimeFormatted,
                    'trip_date' => $tripDateFormatted,
                    'booking_status' => 'CONFIRMED',
                    'boarding_open_time' => $boardingOpenFormatted,
                    'boarding_close_time' => $boardingCloseFormatted,
                    'boarded_at' => null,
                ],
            ], 422);
        }

        // Status 3: LATE / BOARDING CLOSED (14 minutes or less before departure, or post-departure)
        if ($now->gt($boardingCloseTime)) {
            return response()->json([
                'success' => false,
                'status' => 'BOARDING_CLOSED',
                'message' => 'Boarding Declined: Gate closed. Tickets must be scanned at least 15 minutes prior to departure.',
                'data' => [
                    'reference_number' => $booking->reference_number,
                    'passenger_name' => $booking->passenger_name,
                    'route' => $booking->route,
                    'vessel_name' => $vesselName,
                    'seat_number' => $seatList,
                    'departure_time' => $departureTimeFormatted,
                    'trip_date' => $tripDateFormatted,
                    'booking_status' => 'CONFIRMED',
                    'boarding_open_time' => $boardingOpenFormatted,
                    'boarding_close_time' => $boardingCloseFormatted,
                    'boarded_at' => null,
                ],
            ], 422);
        }

        // Status 2: VALID BOARDING WINDOW (Between 2 hours down to 15 minutes before departure)
        // Mark Boarded: Update record atomically
        $scannerUser = $request->user('sanctum') ?? $request->user();
        $boardedTimestamp = now();

        $booking->is_boarded = true;
        $booking->boarded_at = $boardedTimestamp;
        $booking->boarded_by = $scannerUser?->id;
        $booking->save();

        // 7. Record Boarding Audit Log
        AuditLog::record(
            'boarding',
            'Passenger Boarded',
            "Passenger {$booking->passenger_name} verified and boarded {$vesselName} (Ticket #{$booking->reference_number}, Seat: {$seatList})",
            $scannerUser?->name ?? 'Scanner Staff'
        );

        Log::info("Ticket verified and boarded: #{$booking->reference_number} for {$booking->passenger_name} by " . ($scannerUser?->email ?? 'staff'));

        // 8. Return standardized response payload
        return response()->json([
            'success' => true,
            'status' => 'BOARDED_SUCCESS',
            'message' => 'Boarding Verified! Passenger cleared for departure.',
            'data' => [
                'reference_number' => $booking->reference_number,
                'passenger_name' => $booking->passenger_name,
                'route' => $booking->route,
                'vessel_name' => $vesselName,
                'seat_number' => $seatList,
                'departure_time' => $departureTimeFormatted,
                'trip_date' => $tripDateFormatted,
                'booking_status' => 'CONFIRMED',
                'boarding_open_time' => $boardingOpenFormatted,
                'boarding_close_time' => $boardingCloseFormatted,
                'boarded_at' => $boardedTimestamp->format('Y-m-d H:i:s'),
            ],
        ], 200);
    }
}
