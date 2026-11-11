<?php

namespace App\Http\Controllers;

use App\Models\ComprehensiveExamSchedule;
use App\Models\DefenseRequest;
use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ScheduleEventController extends Controller
{
    public function list(Request $r)
    {
        $user = Auth::user();
        if (! $user) {
            abort(401);
        }

        $from = Carbon::parse($r->query('from', now()->startOfMonth()))->startOfDay();
        $to = Carbon::parse($r->query('to', now()->endOfMonth()))->endOfDay();

        $events = Event::between($from, $to)->get()->map(function ($e) {
            return [
                'id' => 'ev-'.$e->id,
                'title' => $e->title,
                // send local naive date-time strings (no Z / offset) to avoid client shift
                'start' => $e->start_at?->format('Y-m-d H:i:00'),
                'end' => $e->end_at?->format('Y-m-d H:i:00'),
                'allDay' => $e->all_day,
                'editable' => false,
                'type' => $e->type,
                'color' => $e->color ?: '#2563eb',
                'origin' => 'event',
                'description' => $e->description,          // <--- ADDED
            ];
        });

        $defQuery = DefenseRequest::query()
            ->whereNotNull('scheduled_date')
            ->whereIn('workflow_state', ['scheduled', 'completed']);

        if ($user->role === 'Coordinator') {
            $defQuery->where('coordinator_user_id', $user->id);
        } elseif ($user->role === 'Student') {
            $defQuery->where('submitted_by', $user->id);
        } elseif (in_array($user->role, ['Adviser'])) {
            $defQuery->where(function ($q) use ($user) {
                $q->where('adviser_user_id', $user->id)
                    ->orWhere('assigned_to_user_id', $user->id)
                    ->orWhere('submitted_by', $user->id);
            });
        }

        $defenses = $defQuery->get()->map(function ($d) {
            $startDate = $d->scheduled_date?->format('Y-m-d');
            if (! $startDate) {
                return null;
            }
            $startTime = $d->scheduled_time ?: '08:00:00';
            $endTime = $d->scheduled_end_time
                ?: Carbon::parse($startTime)->addHour()->format('H:i:s');

            return [
                'id' => 'def-'.$d->id,
                'title' => 'Defense: '.(mb_strimwidth($d->thesis_title ?? 'Untitled', 0, 60, '…')),
                'start' => Carbon::parse("$startDate $startTime", 'Asia/Manila')->toIso8601String(),
                'end' => Carbon::parse("$startDate $endTime", 'Asia/Manila')->toIso8601String(),
                'allDay' => false,
                'editable' => false,
                'type' => 'defense',
                'color' => '#16a34a',
                'origin' => 'defense',
                'data' => [
                    'defense_id' => $d->id,
                    'student' => $d->student_display_name ?? $d->user?->name,
                    'workflow_state' => $d->workflow_state,
                    'status' => $d->status,
                ],
            ];
        })->filter();

        $comprehensiveExams = collect();
        if ($user->role === 'Student') {
            $comprehensiveExams = ComprehensiveExamSchedule::query()
                ->where('student_id', $user->id)
                ->whereBetween('exam_date', [$from->toDateString(), $to->toDateString()])
                ->get()
                ->map(function (ComprehensiveExamSchedule $schedule) {
                    $start = Carbon::parse(
                        $schedule->exam_date->format('Y-m-d').' '.$schedule->exam_time,
                        'Asia/Manila',
                    );
                    $proctors = collect($schedule->proctors)->filter()->implode(', ');
                    $details = [
                        'Venue: '.$schedule->venue,
                        'Teacher: '.$schedule->teacher_assigned,
                    ];
                    if ($proctors !== '') {
                        $details[] = 'Proctors: '.$proctors;
                    }
                    if (filled($schedule->change_reason)) {
                        $details[] = 'Reason for update: '.$schedule->change_reason;
                    }

                    return [
                        'id' => 'compre-'.$schedule->id,
                        'title' => 'Comprehensive Exam',
                        'start' => $start->toIso8601String(),
                        'allDay' => false,
                        'editable' => false,
                        'type' => 'comprehensive-exam',
                        'color' => '#e11d48',
                        'origin' => 'comprehensive-exam',
                        'description' => implode('; ', $details),
                        'updated' => filled($schedule->change_reason),
                        'change_reason' => $schedule->change_reason,
                    ];
                });
        }

        return response()->json(collect($events->all())->concat($defenses)->concat($comprehensiveExams)->values());
    }

    public function store(Request $r)
    {
        $user = Auth::user();
        if (! $user) {
            abort(401);
        }
        if (! in_array($user->role, ['Coordinator', 'Administrative Assistant', 'Dean'])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $r->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'start' => 'required|date',
            'end' => 'nullable|date|after_or_equal:start',
            'allDay' => 'boolean',
            'color' => 'nullable|string|max:20',
        ]);

        $tz = config('app.timezone', 'Asia/Manila');
        $start = Carbon::parse($data['start'], $tz);
        $end = isset($data['end'])
            ? Carbon::parse($data['end'], $tz)
            : (clone $start);

        $ev = Event::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'start_at' => $start,
            'end_at' => $end,
            'all_day' => $data['allDay'] ?? false,
            'type' => 'general',
            'color' => $data['color'] ?? null,
            'created_by' => $user->id,
        ]);

        return response()->json([
            'ok' => true,
            'event_id' => $ev->id,
            'color' => $ev->color,
            'description' => $ev->description,
        ]);
    }

    public function update(Request $r, Event $event)
    {
        $user = Auth::user();
        if (! $user) {
            abort(401);
        }
        if (! in_array($user->role, ['Coordinator', 'Administrative Assistant', 'Dean'])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $data = $r->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'start' => 'sometimes|date',
            'end' => 'nullable|date|after_or_equal:start',
            'allDay' => 'boolean',
            'color' => 'nullable|string|max:20',
        ]);
        if (isset($data['color'])) {
            $event->color = $data['color'];
        }
        $event->fill([
            'title' => $data['title'] ?? $event->title,
            'description' => $data['description'] ?? $event->description,
        ]);
        if (isset($data['start'])) {
            $event->start_at = $data['start'];
        }
        if (array_key_exists('end', $data)) {
            $event->end_at = $data['end'];
        }
        if (isset($data['allDay'])) {
            $event->all_day = $data['allDay'];
        }
        $event->save();

        return response()->json([
            'ok' => true,
            'description' => $event->description,
            'color' => $event->color,
        ]);
    }

    public function move(Request $r, Event $event)
    {
        $user = Auth::user();
        if (! $user) {
            abort(401);
        }
        if (! in_array($user->role, ['Coordinator', 'Administrative Assistant', 'Dean'])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $data = $r->validate([
            'start' => 'required|date',
            'end' => 'nullable|date|after_or_equal:start',
            'allDay' => 'boolean',
        ]);
        $event->start_at = $data['start'];
        $event->end_at = $data['end'] ?? $data['start'];
        $event->all_day = $data['allDay'] ?? false;
        $event->save();

        return response()->json(['ok' => true]);
    }

    public function destroy(Event $event)
    {
        $user = Auth::user();
        if (! $user) {
            abort(401);
        }
        if (! in_array($user->role, ['Coordinator', 'Administrative Assistant', 'Dean'])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $event->delete();

        return response()->json(['ok' => true]);
    }
}
