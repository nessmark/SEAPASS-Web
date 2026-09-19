<?php

namespace App\Http\Controllers;

use App\Models\Boat;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketController extends Controller
{
    /**
     * Display the admin ticketing and bookings dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request): View
    {
        $boats = Boat::where('status', 'Active')->orderBy('name')->get();

        $selectedDate = $request->query('booking_date');

        $query = Booking::with(['tripSchedule.boat', 'passenger'])->orderBy('created_at', 'desc');

        if ($selectedDate) {
            $query->whereDate('trip_date', $selectedDate);
        }

        $bookings = $query->get();

        $pendingBookings = $bookings->filter(fn ($b) => in_array(strtolower((string) $b->status), ['pending', 'to_be_confirmed']));
        $confirmedBookings = $bookings->filter(fn ($b) => strtolower((string) $b->status) === 'confirmed');
        $cancelledBookings = $bookings->filter(fn ($b) => strtolower((string) $b->status) === 'cancelled');
        $toBeConfirmedCount = $bookings->filter(fn ($b) => strtolower((string) $b->status) === 'to_be_confirmed')->count();

        return view('admin.ticket_book_dashboard', [
            'boats' => $boats,
            'selectedDate' => $selectedDate,
            'pendingBookings' => $pendingBookings,
            'confirmedBookings' => $confirmedBookings,
            'cancelledBookings' => $cancelledBookings,
            'toBeConfirmedCount' => $toBeConfirmedCount,
        ]);
    }
}
