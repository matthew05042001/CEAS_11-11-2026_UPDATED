<?php

use App\Models\ComprehensiveExamSchedule;
use App\Models\CoordinatorProgram;
use App\Models\ExamApplication;
use App\Models\Notification;
use App\Models\PaymentSubmission;
use App\Models\User;

function validComprehensiveScheduleData(User $coordinator, array $overrides = []): array
{
    $student = User::factory()->create([
        'role' => 'Student',
        'program' => 'Master in Technology',
        'school_id' => 'SCHEDULE-STUDENT-'.$coordinator->id,
    ]);
    CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => $student->program]);
    ExamApplication::create([
        'student_id' => $student->school_id,
        'school_year' => '2026-2027',
        'program' => $student->program,
        'permit_status' => 'approved',
        'final_approval_status' => 'approved',
        'registrar_approval_status' => 'approved',
        'created_at' => now(),
    ]);
    PaymentSubmission::create([
        'student_id' => $student->id,
        'or_number' => 'OR-SCHEDULE-'.$student->id,
        'amount_paid' => 500,
        'payment_date' => today(),
        'payment_type' => 'exam',
        'receipt_image' => 'receipt.pdf',
        'status' => 'approved',
    ]);
    $proctor = User::factory()->create(['role' => 'Proctor']);

    return array_merge([
        'student_id' => $student->id,
        'program' => 'Master in Technology',
        'date' => now()->addDay()->toDateString(),
        'time' => '17:41',
        'venue' => 'Room 15',
        'proctor_ids' => [$proctor->id],
        'teacher_assigned' => 'John',
    ], $overrides);
}

it('rejects schedule dates with years longer than four digits before database insert', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', validComprehensiveScheduleData($coordinator, ['date' => '55555-04-05']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    expect(ComprehensiveExamSchedule::count())->toBe(0);
});

it('rejects exam dates before today', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', validComprehensiveScheduleData($coordinator, [
            'date' => now()->subDay()->toDateString(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    expect(ComprehensiveExamSchedule::count())->toBe(0);
});

it('allows scheduling an exam for today', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', validComprehensiveScheduleData($coordinator, [
            'date' => today()->toDateString(),
        ]))
        ->assertCreated();
});

it('assigns real Proctor accounts when creating a schedule', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $proctor = User::factory()->create([
        'role' => 'Proctor',
        'first_name' => 'Demo Proctor',
        'middle_name' => null,
        'last_name' => '1',
    ]);

    $data = validComprehensiveScheduleData($coordinator, ['proctor_ids' => [$proctor->id]]);
    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', $data)
        ->assertCreated()
        ->assertJsonPath('schedule.venue', 'Room 15')
        ->assertJsonPath('schedule.proctor_ids.0', $proctor->id)
        ->assertJsonPath('schedule.proctors.0', $proctor->display_name);

    $schedule = ComprehensiveExamSchedule::firstOrFail();
    expect($schedule->student_id)->toBe((int) $data['student_id'])
        ->and($schedule->change_reason)->toBeNull()
        ->and($schedule->proctorUsers->modelKeys())->toBe([$proctor->id]);
});

it('notifies the student when their comprehensive exam schedule is created and updated', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $data = validComprehensiveScheduleData($coordinator);

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', $data)
        ->assertCreated();

    $schedule = ComprehensiveExamSchedule::firstOrFail();
    $student = User::findOrFail($data['student_id']);
    $createdNotification = Notification::where('user_id', $student->id)->firstOrFail();
    expect($createdNotification->type)->toBe('comprehensive-exam-scheduled')
        ->and($createdNotification->title)->toBe('Comprehensive exam scheduled')
        ->and($createdNotification->message)->toContain('Room 15')
        ->and($createdNotification->link)->toBe('/dashboard')
        ->and($createdNotification->read)->toBeFalse();

    $this->putJson('/coordinator/compre-schedule/'.$schedule->id, array_merge($data, [
        'date' => now()->addDays(2)->toDateString(),
        'change_reason' => 'The original date is no longer available',
    ]))->assertOk();

    $updatedNotification = Notification::where('user_id', $student->id)->latest('id')->firstOrFail();
    expect($updatedNotification->type)->toBe('comprehensive-exam-schedule-updated')
        ->and($updatedNotification->title)->toBe('Comprehensive exam schedule updated')
        ->and($updatedNotification->message)->toContain('updated')
        ->and($updatedNotification->message)->toContain('The original date is no longer available')
        ->and(Notification::where('user_id', $student->id)->count())->toBe(2);
});

it('provides only Proctor accounts as schedule assignment options', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);

    $this->actingAs($coordinator)
        ->get('/coordinator/compre-schedule')
        ->assertRedirect('/coordinator/compre-exam');
});

it('shows a student exam schedule and only Proctor accounts on the comprehensive exam page', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $proctor = User::factory()->create(['role' => 'Proctor', 'middle_name' => null]);
    $student = User::factory()->create([
        'role' => 'Student',
        'program' => 'Master in Technology',
        'school_id' => 'SCHEDULE-PAGE-STUDENT',
    ]);
    CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => $student->program]);
    ExamApplication::create([
        'student_id' => $student->school_id,
        'school_year' => '2026-2027',
        'program' => $student->program,
        'permit_status' => 'approved',
        'final_approval_status' => 'approved',
        'registrar_approval_status' => 'approved',
        'created_at' => now(),
    ]);
    PaymentSubmission::create([
        'student_id' => $student->id,
        'or_number' => 'OR-SCHEDULE-PAGE',
        'amount_paid' => 500,
        'payment_date' => today(),
        'payment_type' => 'exam',
        'receipt_image' => 'receipt.pdf',
        'status' => 'approved',
    ]);
    $schedule = ComprehensiveExamSchedule::create([
        'student_id' => $student->id,
        'exam_id' => 'test-exam-room',
        'program' => 'Master in Technology',
        'exam_date' => '2026-04-05',
        'exam_time' => '17:41',
        'venue' => 'Room 204B',
        'proctor_name' => $proctor->display_name,
        'proctors' => [$proctor->display_name],
        'teacher_assigned' => 'John',
    ]);
    $schedule->proctorUsers()->attach($proctor);

    $this->actingAs($coordinator)
        ->get('/coordinator/compre-exam')
        ->assertOk()
        ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
            ->component('coordinator/compre-exam/Index')
            ->where('notEligible.0.can_schedule', true)
            ->where('notEligible.0.permit_status', 'approved')
            ->where('notEligible.0.schedule.id', $schedule->id)
            ->where('notEligible.0.schedule.venue', 'Room 204B')
            ->where('notEligible.0.schedule.change_reason', null)
            ->has('proctorOptions', 1)
            ->where('proctorOptions.0.id', $proctor->id)
            ->where('proctorOptions.0.name', $proctor->display_name));
});

it('rejects assigning a non-Proctor account to a schedule', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $student = User::factory()->create(['role' => 'Student']);

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', validComprehensiveScheduleData($coordinator, ['proctor_ids' => [$student->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('proctor_ids.0');

    expect(ComprehensiveExamSchedule::count())->toBe(0);
});

it('allows scheduling only after the application and exam payment are approved', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $student = User::factory()->create([
        'role' => 'Student',
        'program' => 'Master in Technology',
        'school_id' => 'SCHEDULE-APPROVAL-STUDENT',
    ]);
    CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => $student->program]);
    $application = ExamApplication::create([
        'student_id' => $student->school_id,
        'school_year' => '2026-2027',
        'program' => $student->program,
        'permit_status' => 'pending',
        'final_approval_status' => 'pending',
        'registrar_approval_status' => 'approved',
        'created_at' => now(),
    ]);
    $payment = PaymentSubmission::create([
        'student_id' => $student->id,
        'or_number' => 'OR-SCHEDULE-APPROVAL',
        'amount_paid' => 500,
        'payment_date' => today(),
        'payment_type' => 'exam',
        'receipt_image' => 'receipt.pdf',
        'status' => 'pending',
    ]);
    $proctor = User::factory()->create(['role' => 'Proctor']);
    $data = [
        'student_id' => $student->id,
        'program' => $student->program,
        'date' => now()->addDays(9)->toDateString(),
        'time' => '09:00',
        'venue' => 'Room 15',
        'proctor_ids' => [$proctor->id],
        'teacher_assigned' => 'John',
    ];

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('student_id');
    expect(ComprehensiveExamSchedule::count())->toBe(0);

    $application->update(['final_approval_status' => 'approved']);
    $this->postJson('/coordinator/compre-schedule', $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('student_id');
    expect(ComprehensiveExamSchedule::count())->toBe(0);

    $payment->update(['status' => 'approved']);
    $this->postJson('/coordinator/compre-schedule', $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('student_id');
    expect(ComprehensiveExamSchedule::count())->toBe(0);

    $application->update(['permit_status' => 'approved']);
    $this->postJson('/coordinator/compre-schedule', $data)
        ->assertCreated()
        ->assertJsonPath('schedule.program', $student->program);
});

it('blocks scheduling when the AA has marked the student not allowed to take the exam', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $data = validComprehensiveScheduleData($coordinator);
    $student = User::findOrFail($data['student_id']);
    ExamApplication::where('student_id', $student->school_id)->update(['permit_status' => 'rejected']);

    $this->actingAs($coordinator)
        ->postJson('/coordinator/compre-schedule', $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('student_id');

    expect(ComprehensiveExamSchedule::count())->toBe(0);
});

it('rejects schedule updates with dates outside the MySQL date range', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $schedule = ComprehensiveExamSchedule::create([
        'exam_id' => 'test-exam-1',
        'program' => 'Master in Technology',
        'exam_date' => '2026-04-05',
        'exam_time' => '17:41',
        'venue' => 'Room 15',
        'proctor_name' => 'Elizabeth',
        'proctors' => ['Elizabeth'],
        'teacher_assigned' => 'John',
    ]);

    $this->actingAs($coordinator)
        ->putJson('/coordinator/compre-schedule/'.$schedule->id, validComprehensiveScheduleData($coordinator, ['date' => '0999-04-05']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    expect($schedule->fresh()->exam_date->format('Y-m-d'))->toBe('2026-04-05');
});

it('requires a reason when editing an exam schedule', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $schedule = ComprehensiveExamSchedule::create([
        'exam_id' => 'test-exam-reason',
        'program' => 'Master in Technology',
        'exam_date' => '2026-04-05',
        'exam_time' => '17:41',
        'venue' => 'Room 15',
        'proctor_name' => 'Elizabeth',
        'proctors' => ['Elizabeth'],
        'teacher_assigned' => 'John',
    ]);

    $this->actingAs($coordinator)
        ->putJson('/coordinator/compre-schedule/'.$schedule->id, validComprehensiveScheduleData($coordinator))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('change_reason');

    expect($schedule->fresh()->exam_date->format('Y-m-d'))->toBe('2026-04-05')
        ->and($schedule->fresh()->change_reason)->toBeNull();
});

it('rejects moving an exam schedule to a past date', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $schedule = ComprehensiveExamSchedule::create([
        'exam_id' => 'test-exam-past-update',
        'program' => 'Master in Technology',
        'exam_date' => today()->addDay(),
        'exam_time' => '17:41',
        'venue' => 'Room 15',
        'proctor_name' => 'Elizabeth',
        'proctors' => ['Elizabeth'],
        'teacher_assigned' => 'John',
    ]);

    $this->actingAs($coordinator)
        ->putJson('/coordinator/compre-schedule/'.$schedule->id, validComprehensiveScheduleData($coordinator, [
            'date' => today()->subDay()->toDateString(),
            'change_reason' => 'Reschedule due to a conflict',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');

    expect($schedule->fresh()->exam_date->toDateString())->toBe(today()->addDay()->toDateString());
});

it('updates a schedule from multipart form data using Laravel method spoofing', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $schedule = ComprehensiveExamSchedule::create([
        'exam_id' => 'test-exam-2',
        'program' => 'Old program',
        'exam_date' => '2026-04-05',
        'exam_time' => '17:41',
        'venue' => 'Room 1',
        'proctor_name' => 'Old proctor',
        'proctors' => ['Old proctor'],
        'teacher_assigned' => 'Old teacher',
    ]);

    $this->actingAs($coordinator)
        ->post('/coordinator/compre-schedule/'.$schedule->id, array_merge(validComprehensiveScheduleData($coordinator, [
            'program' => 'Updated program',
            'date' => now()->addDays(2)->toDateString(),
            'change_reason' => 'Updated due to a room conflict',
        ]), ['_method' => 'PUT']))
        ->assertOk()
        ->assertJsonPath('schedule.program', 'Updated program');

    expect($schedule->fresh()->program)->toBe('Updated program')
        ->and($schedule->fresh()->exam_date->format('Y-m-d'))->toBe(now()->addDays(2)->toDateString())
        ->and($schedule->fresh()->change_reason)->toBe('Updated due to a room conflict')
        ->and($schedule->fresh()->proctorUsers->count())->toBe(1);
});
