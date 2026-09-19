<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Boat;
use App\Models\ScheduleTemplate;
use App\Services\RecurringScheduleCascadeService;
use App\Services\RecurringScheduleGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleTemplateController extends Controller
{
    public function __construct(
        private readonly RecurringScheduleGenerator $generator,
        private readonly RecurringScheduleCascadeService $cascadeService
    ) {
    }

    /**
     * Store a new recurring schedule template and instantly generate upcoming instances.
     */
    public function store(Request $request)
    {
        $templateId = $request->input('template_id') ?: $request->input('id');
        if ($templateId) {
            $template = ScheduleTemplate::findOrFail($templateId);
            return $this->update($request, $template);
        }

        if ($request->filled('departure_time_slot') && !$request->filled('departure_time')) {
            $request->merge(['departure_time' => $request->input('departure_time_slot')]);
        }

        $validated = $request->validate([
            'boat_id' => ['required', 'exists:boats,id'],
            'route' => ['required', 'string'],
            'departure_time' => ['required', 'string'],
            'arrival_time' => ['nullable', 'string'],
            'recurrence_type' => ['required', 'in:daily,weekly'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['string', 'in:Mon,Tue,Wed,Thu,Fri,Sat,Sun'],
            'available_seats' => ['nullable', 'integer', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['recurrence_type'] === 'weekly' && empty($validated['days_of_week'])) {
            return back()->withInput()->withErrors(['days_of_week' => 'Please select at least one day of the week for weekly recurrence.']);
        }

        $boat = Boat::findOrFail($validated['boat_id']);
        $maxSeats = (int) ($boat->passenger_capacity ?? 0);

        if (!empty($validated['available_seats']) && $validated['available_seats'] > $maxSeats) {
            return back()->withInput()->withErrors(['available_seats' => "Available seats cannot exceed boat capacity ({$maxSeats})."]);
        }

        if (empty($validated['available_seats'])) {
            $validated['available_seats'] = $maxSeats;
        }

        $template = ScheduleTemplate::create([
            'boat_id' => $validated['boat_id'],
            'route' => $validated['route'],
            'departure_time' => $validated['departure_time'],
            'arrival_time' => $validated['arrival_time'] ?? null,
            'recurrence_type' => $validated['recurrence_type'],
            'days_of_week' => $validated['recurrence_type'] === 'weekly' ? array_values($validated['days_of_week']) : null,
            'available_seats' => $validated['available_seats'],
            'effective_from' => $validated['effective_from'] ?? now()->toDateString(),
            'effective_until' => $validated['effective_until'] ?? null,
            'is_active' => true,
            'notes' => $validated['notes'] ?? null,
        ]);

        // Instantly generate upcoming trip instances for the next 3 months (90 days)
        $genResult = $this->generator->generateForTemplate($template, RecurringScheduleGenerator::LOOKAHEAD_DAYS);

        AuditLog::record(
            'schedule',
            'Recurring Schedule Created',
            "Created recurring rule #{$template->id}: {$template->route} on boat {$boat->name} ({$template->formatted_recurrence}, {$template->formatted_departure_time}). Auto-generated {$genResult['generated_count']} upcoming trip(s) across 3 months."
        );

        $msg = "Recurring schedule template created successfully! Automatically spawned {$genResult['generated_count']} upcoming trip(s) across the next 3 months.";

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'template' => $template->load('boat'),
                'generated_count' => $genResult['generated_count'],
            ]);
        }

        return redirect()->route('admin.trip-schedules', ['tab' => 'recurring'])
            ->with('success', $msg);
    }

    /**
     * Update an existing recurring schedule template.
     */
    public function update(Request $request, ScheduleTemplate $template)
    {
        if ($request->filled('departure_time_slot') && !$request->filled('departure_time')) {
            $request->merge(['departure_time' => $request->input('departure_time_slot')]);
        }

        $validated = $request->validate([
            'boat_id' => ['required', 'exists:boats,id'],
            'route' => ['required', 'string'],
            'departure_time' => ['required', 'string'],
            'arrival_time' => ['nullable', 'string'],
            'recurrence_type' => ['required', 'in:daily,weekly'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['string', 'in:Mon,Tue,Wed,Thu,Fri,Sat,Sun'],
            'available_seats' => ['nullable', 'integer', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['recurrence_type'] === 'weekly' && empty($validated['days_of_week'])) {
            return back()->withInput()->withErrors(['days_of_week' => 'Please select at least one day of the week for weekly recurrence.']);
        }

        $boat = Boat::findOrFail($validated['boat_id']);
        $maxSeats = (int) ($boat->passenger_capacity ?? 0);

        if (!empty($validated['available_seats']) && $validated['available_seats'] > $maxSeats) {
            return back()->withInput()->withErrors(['available_seats' => "Available seats cannot exceed boat capacity ({$maxSeats})."]);
        }

        $oldAttributes = [
            'boat_id' => $template->boat_id,
            'route' => $template->route,
            'departure_time' => $template->departure_time,
            'arrival_time' => $template->arrival_time,
            'recurrence_type' => $template->recurrence_type,
            'days_of_week' => $template->days_of_week,
            'available_seats' => $template->available_seats,
        ];

        $cascadeResult = DB::transaction(function () use ($template, $validated, $maxSeats, $oldAttributes) {
            $template->update([
                'boat_id' => $validated['boat_id'],
                'route' => $validated['route'],
                'departure_time' => $validated['departure_time'],
                'arrival_time' => $validated['arrival_time'] ?? null,
                'recurrence_type' => $validated['recurrence_type'],
                'days_of_week' => $validated['recurrence_type'] === 'weekly' ? array_values($validated['days_of_week']) : null,
                'available_seats' => $validated['available_seats'] ?: $maxSeats,
                'effective_from' => $validated['effective_from'] ?? $template->effective_from,
                'effective_until' => $validated['effective_until'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            return $this->cascadeService->cascadeUpdate($template, $oldAttributes, $validated);
        });

        $msg = "Recurring schedule template updated successfully! Cascaded: {$cascadeResult['time_updated_trips']} trip(s) synchronized ({$cascadeResult['time_updated_bookings']} passenger(s) notified), {$cascadeResult['removed_unbooked']} unbooked trip(s) cleaned, {$cascadeResult['rescheduled_booked']} booked trip(s) auto-rescheduled, and {$cascadeResult['newly_spawned']} new trip(s) spawned.";

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'template' => $template->load('boat'),
                'cascade' => $cascadeResult,
            ]);
        }

        return redirect()->route('admin.trip-schedules', ['tab' => 'recurring'])
            ->with('success', $msg);
    }

    /**
     * Toggle active/inactive status of a recurring rule.
     */
    public function toggle(ScheduleTemplate $template)
    {
        $template->update(['is_active' => !$template->is_active]);

        $statusLabel = $template->is_active ? 'Activated' : 'Deactivated';

        $generatedCount = 0;
        if ($template->is_active) {
            $genResult = $this->generator->generateForTemplate($template, RecurringScheduleGenerator::LOOKAHEAD_DAYS);
            $generatedCount = $genResult['generated_count'];
        }

        AuditLog::record(
            'schedule',
            "Recurring Schedule {$statusLabel}",
            "{$statusLabel} recurring rule #{$template->id} ({$template->route})"
        );

        $msg = "Recurring rule #{$template->id} has been {$statusLabel}." . ($generatedCount > 0 ? " Spawned {$generatedCount} upcoming trip(s) across 3 months." : "");

        return redirect()->route('admin.trip-schedules', ['tab' => 'recurring'])
            ->with('success', $msg);
    }

    /**
     * Manually trigger on-demand generation for a recurring template.
     */
    public function generateNow(ScheduleTemplate $template)
    {
        $genResult = $this->generator->generateForTemplate($template, RecurringScheduleGenerator::LOOKAHEAD_DAYS);

        $msg = "Generated {$genResult['generated_count']} new trip instance(s) across 3 months for rule #{$template->id} ({$genResult['skipped_count']} existing trips skipped).";

        return redirect()->route('admin.trip-schedules', ['tab' => 'recurring'])
            ->with('success', $msg);
    }

    /**
     * Delete a recurring template dynamically cascading-deleting unbooked future trips
     * and auto-rescheduling booked trips (Option A).
     */
    public function destroy(ScheduleTemplate $template)
    {
        $id = $template->id;
        $desc = "{$template->route} ({$template->formatted_recurrence})";

        $result = $this->cascadeService->cascadeDelete($template);

        $msg = "Recurring rule #{$id} deleted successfully. Cascaded: {$result['deleted_unbooked']} unbooked trip(s) deleted, {$result['rescheduled_booked']} booked trip(s) ({$result['affected_passengers']} passenger booking(s)) auto-rescheduled.";

        return redirect()->route('admin.trip-schedules', ['tab' => 'recurring'])
            ->with('success', $msg);
    }
}
