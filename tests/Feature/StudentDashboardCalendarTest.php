<?php

use App\Models\DefenseRequest;
use App\Models\ComprehensiveExamSchedule;
use App\Models\Event;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function dashboardScheduledDefense(User $student): DefenseRequest
{
    return DefenseRequest::withoutEvents(fn () => DefenseRequest::create(['submitted_by' => $student->id, 'first_name' => 'Test', 'last_name' => 'Student', 'school_id' => 'S-'.$student->id, 'program' => 'Master of Arts', 'thesis_title' => 'Student '.$student->id.' defense', 'defense_type' => 'Proposal', 'defense_adviser' => 'Test Adviser', 'proof_of_payment' => 'receipt.pdf', 'workflow_state' => 'scheduled', 'status' => 'Approved', 'scheduled_date' => '2026-10-27', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00']));
}

test('dashboard calendar feed returns own defenses and school events but excludes another student defense', function () {
    $student = User::factory()->create(['role' => 'Student']);
    $other = User::factory()->create(['role' => 'Student']);
    $mine = dashboardScheduledDefense($student);
    $theirs = dashboardScheduledDefense($other);
    Event::create(['title' => 'School activity', 'start_at' => '2026-10-27 13:00:00', 'end_at' => '2026-10-27 14:00:00', 'all_day' => false, 'type' => 'general', 'created_by' => $student->id]);
    $response = $this->actingAs($student)->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')->assertOk();
    $rows = collect($response->json());
    expect($rows->pluck('title')->all())->toContain('School activity')->toContain('Defense: '.$mine->thesis_title)->not->toContain('Defense: '.$theirs->thesis_title);
    expect($rows->where('origin', 'defense')->count())->toBe(1);
});

test('student calendar includes only their comprehensive exam schedule with location and staff details', function () {
    config(['app.timezone' => 'UTC']);
    $student = User::factory()->create(['role' => 'Student']);
    $other = User::factory()->create(['role' => 'Student']);
    $mine = ComprehensiveExamSchedule::create([
        'student_id' => $student->id,
        'exam_id' => 'student-calendar-exam',
        'program' => 'Master of Arts',
        'exam_date' => '2026-10-27',
        'exam_time' => '09:30',
        'venue' => 'Room 204B',
        'proctor_name' => 'Proctor One',
        'proctors' => ['Proctor One'],
        'teacher_assigned' => 'Teacher One',
        'change_reason' => 'Room was changed due to maintenance',
    ]);
    ComprehensiveExamSchedule::create([
        'student_id' => $other->id,
        'exam_id' => 'other-student-calendar-exam',
        'program' => 'Master of Arts',
        'exam_date' => '2026-10-27',
        'exam_time' => '10:30',
        'venue' => 'Private Room',
        'proctor_name' => 'Private Proctor',
        'proctors' => ['Private Proctor'],
        'teacher_assigned' => 'Private Teacher',
    ]);

    $response = $this->actingAs($student)
        ->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')
        ->assertOk();
    $rows = collect($response->json());
    $event = $rows->firstWhere('id', 'compre-'.$mine->id);

    expect($event['title'])->toBe('Comprehensive Exam')
        ->and($event['start'])->toBe('2026-10-27T09:30:00+08:00')
        ->and($event['origin'])->toBe('comprehensive-exam')
        ->and($event['description'])->toContain('Room 204B', 'Teacher One', 'Proctor One', 'Room was changed due to maintenance')
        ->and($event['updated'])->toBeTrue()
        ->and($event['change_reason'])->toBe('Room was changed due to maintenance')
        ->and($rows->pluck('title')->all())->not->toContain('Private Room');
});

test('adviser dashboard calendar includes assigned defenses and school events without other adviser defenses', function () {
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $other = User::factory()->create(['role' => 'Adviser']);
    $mine = dashboardScheduledDefense(User::factory()->create(['role' => 'Student']));
    $mine->update(['adviser_user_id' => $adviser->id]);
    $theirs = dashboardScheduledDefense(User::factory()->create(['role' => 'Student']));
    $theirs->update(['adviser_user_id' => $other->id]);
    Event::create(['title' => 'Graduate school event', 'start_at' => '2026-10-27 13:00:00', 'end_at' => '2026-10-27 14:00:00', 'all_day' => false, 'type' => 'general', 'created_by' => $adviser->id]);
    $response = $this->actingAs($adviser)->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')->assertOk();
    $rows = collect($response->json());
    expect($rows->pluck('title')->all())->toContain('Graduate school event')->toContain('Defense: '.$mine->thesis_title)->not->toContain('Defense: '.$theirs->thesis_title);
    expect($rows->where('origin', 'defense')->count())->toBe(1);
});

test('coordinator calendar returns their assigned defenses and excludes another coordinator schedule', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $other = User::factory()->create(['role' => 'Coordinator']);
    $mine = dashboardScheduledDefense(User::factory()->create(['role' => 'Student']));
    $mine->update(['coordinator_user_id' => $coordinator->id]);
    $theirs = dashboardScheduledDefense(User::factory()->create(['role' => 'Student']));
    $theirs->update(['coordinator_user_id' => $other->id]);
    $response = $this->actingAs($coordinator)->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')->assertOk();
    expect(collect($response->json())->pluck('title')->all())->toContain('Defense: '.$mine->thesis_title)->not->toContain('Defense: '.$theirs->thesis_title);
});

test('dashboard request feed includes the saved adviser and actual submission timestamp', function () {
    // The production query uses MySQL FIELD ordering; supply equivalent ordering in isolated SQLite tests.
    DB::connection()->getPdo()->sqliteCreateFunction('FIELD', static function ($value, ...$values) {
        $index = array_search($value, $values, true);

        return $index === false ? 0 : $index + 1;
    });
    $dean = User::factory()->create(['role' => 'Dean']);
    $request = dashboardScheduledDefense(User::factory()->create(['role' => 'Student']));
    $request->created_at = '2026-10-20 09:00:00';
    $request->adviser_reviewed_at = '2026-10-27 10:00:00';
    $request->save();
    $expectedDate = $request->fresh()->created_at->toIso8601String();
    $this->actingAs($dean)->getJson('/defense-requests')->assertOk()
        ->assertJsonPath('defenseRequests.0.adviser', 'Test Adviser')
        ->assertJsonPath('defenseRequests.0.submitted_at', $expectedDate)
        ->assertJsonPath('defenseRequests.0.created_at', $expectedDate);
});

test('assistant calendar includes scheduled defenses and school events but excludes unscheduled requests', function () {
    $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
    $scheduled = dashboardScheduledDefense(User::factory()->create(['role' => 'Student']));
    $unscheduled = dashboardScheduledDefense(User::factory()->create(['role' => 'Student']));
    $unscheduled->update(['scheduled_date' => null, 'workflow_state' => 'submitted']);
    Event::create(['title' => 'Graduate school meeting', 'start_at' => '2026-10-27 13:00:00', 'end_at' => '2026-10-27 14:00:00', 'all_day' => false, 'type' => 'general', 'created_by' => $assistant->id]);
    $response = $this->actingAs($assistant)->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')->assertOk();
    expect(collect($response->json())->pluck('title')->all())->toContain('Defense: '.$scheduled->thesis_title)->toContain('Graduate school meeting')->not->toContain('Defense: '.$unscheduled->thesis_title);
});

test('calendar feed labels saved Philippine defense hours correctly even when the app runs in UTC', function () {
    config(['app.timezone' => 'UTC']);
    $student = User::factory()->create(['role' => 'Student']);
    $defense = DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
        'submitted_by' => $student->id, 'first_name' => 'Time', 'last_name' => 'Test',
        'school_id' => 'TIME-TEST', 'program' => 'Master of Arts', 'thesis_title' => 'Philippine time fixture',
        'defense_type' => 'Proposal', 'defense_adviser' => 'Test Adviser', 'proof_of_payment' => 'receipt.pdf', 'status' => 'Approved', 'workflow_state' => 'scheduled',
        'scheduled_date' => '2026-10-30', 'scheduled_time' => '09:00:00', 'scheduled_end_time' => '12:00:00',
    ]));
    $response = $this->actingAs($student)->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')->assertOk();
    $event = collect($response->json())->firstWhere('id', 'def-'.$defense->id);
    expect($event['start'])->toBe('2026-10-30T09:00:00+08:00')
        ->and($event['end'])->toBe('2026-10-30T12:00:00+08:00');
    expect(CarbonImmutable::parse($event['start'])->setTimezone('Asia/Manila')->format('H:i'))->toBe('09:00');
});

test('calendar feed preserves the Philippine date for a defense shortly after midnight', function () {
    config(['app.timezone' => 'UTC']);
    $student = User::factory()->create(['role' => 'Student']);
    $defense = DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
        'submitted_by' => $student->id, 'first_name' => 'Time', 'last_name' => 'Test',
        'school_id' => 'MIDNIGHT-TEST', 'program' => 'Master of Arts', 'thesis_title' => 'Midnight fixture',
        'defense_type' => 'Proposal', 'defense_adviser' => 'Test Adviser', 'proof_of_payment' => 'receipt.pdf', 'status' => 'Approved', 'workflow_state' => 'scheduled',
        'scheduled_date' => '2026-10-30', 'scheduled_time' => '00:30:00', 'scheduled_end_time' => '01:30:00',
    ]));
    $response = $this->actingAs($student)->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')->assertOk();
    $event = collect($response->json())->firstWhere('id', 'def-'.$defense->id);
    expect(CarbonImmutable::parse($event['start'])->setTimezone('Asia/Manila')->format('Y-m-d H:i'))->toBe('2026-10-30 00:30');
});
