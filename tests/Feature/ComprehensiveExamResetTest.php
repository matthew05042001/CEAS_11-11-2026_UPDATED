<?php

namespace Tests\Feature;

use App\Models\ComprehensiveExamSchedule;
use App\Models\ExamApplication;
use App\Models\ExamApplicationSubject;
use App\Models\Notification;
use App\Models\PaymentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ComprehensiveExamResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_student_can_reset_their_approved_application_and_associated_exam_records(): void
    {
        Storage::fake('public');
        $student = User::factory()->create([
            'role' => 'Student',
            'school_id' => 'RESET-STUDENT-001',
        ]);
        $application = ExamApplication::create([
            'student_id' => $student->school_id,
            'school_year' => '2026-2027',
            'program' => 'Master of Arts',
            'permit_status' => 'pending',
            'registrar_approval_status' => 'approved',
            'final_approval_status' => 'approved',
            'created_at' => now(),
        ]);
        ExamApplicationSubject::create([
            'application_id' => $application->application_id,
            'subject_name' => 'Research Methods',
        ]);
        $receiptPath = 'payment_receipts/reset-student.pdf';
        Storage::disk('public')->put($receiptPath, 'receipt');
        PaymentSubmission::create([
            'student_id' => $student->id,
            'or_number' => 'OR-RESET-001',
            'amount_paid' => 500,
            'payment_date' => today(),
            'payment_type' => 'exam',
            'receipt_image' => $receiptPath,
            'status' => 'approved',
        ]);
        $proctor = User::factory()->create(['role' => 'Proctor']);
        $schedule = ComprehensiveExamSchedule::create([
            'student_id' => $student->id,
            'exam_id' => 'RESET-EXAM',
            'program' => 'Master of Arts',
            'exam_date' => today()->addDays(3),
            'exam_time' => '09:00',
            'venue' => 'Room 1',
            'proctor_name' => $proctor->display_name,
            'proctors' => [$proctor->display_name],
            'teacher_assigned' => 'Teacher',
        ]);
        $schedule->proctorUsers()->attach($proctor);
        Notification::create([
            'user_id' => $student->id,
            'type' => 'comprehensive-exam-scheduled',
            'title' => 'Comprehensive exam scheduled',
            'message' => 'Your exam is scheduled.',
            'link' => '/dashboard',
            'read' => false,
        ]);
        Notification::create([
            'user_id' => $student->id,
            'type' => 'other',
            'title' => 'Keep this notification',
            'message' => 'Unrelated.',
            'read' => false,
        ]);

        $this->actingAs($student)
            ->delete('/comprehensive-exam/'.$application->application_id.'/reset')
            ->assertRedirect('/comprehensive-exam')
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('exam_application', ['application_id' => $application->application_id]);
        $this->assertDatabaseMissing('exam_application_subject', ['application_id' => $application->application_id]);
        $this->assertDatabaseMissing('payment_submissions', ['student_id' => $student->id, 'payment_type' => 'exam']);
        $this->assertDatabaseMissing('comprehensive_exam_schedules', ['id' => $schedule->id]);
        $this->assertDatabaseMissing('comprehensive_exam_schedule_proctors', ['schedule_id' => $schedule->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $student->id, 'type' => 'comprehensive-exam-scheduled']);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'type' => 'other']);
        Storage::disk('public')->assertMissing($receiptPath);

        $this->get('/comprehensive-exam')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('student/submissions/comprehensive-exam/Index')
                ->where('application', null));
    }

    public function test_student_cannot_reset_another_students_application(): void
    {
        $owner = User::factory()->create(['role' => 'Student', 'school_id' => 'RESET-OWNER']);
        $otherStudent = User::factory()->create(['role' => 'Student', 'school_id' => 'RESET-OTHER']);
        $application = ExamApplication::create([
            'student_id' => $owner->school_id,
            'school_year' => '2026-2027',
            'program' => 'Master of Arts',
            'registrar_approval_status' => 'approved',
            'final_approval_status' => 'approved',
            'created_at' => now(),
        ]);

        $this->actingAs($otherStudent)
            ->delete('/comprehensive-exam/'.$application->application_id.'/reset')
            ->assertNotFound();

        $this->assertDatabaseHas('exam_application', ['application_id' => $application->application_id]);
    }

    public function test_only_approved_applications_can_be_reset(): void
    {
        $student = User::factory()->create(['role' => 'Student', 'school_id' => 'RESET-PENDING']);
        $application = ExamApplication::create([
            'student_id' => $student->school_id,
            'school_year' => '2026-2027',
            'program' => 'Master of Arts',
            'registrar_approval_status' => 'approved',
            'final_approval_status' => 'pending',
            'created_at' => now(),
        ]);

        $this->actingAs($student)
            ->delete('/comprehensive-exam/'.$application->application_id.'/reset')
            ->assertConflict();

        $this->assertDatabaseHas('exam_application', ['application_id' => $application->application_id]);
    }

    public function test_non_student_cannot_reset_an_application(): void
    {
        $student = User::factory()->create(['role' => 'Student', 'school_id' => 'RESET-STUDENT-002']);
        $application = ExamApplication::create([
            'student_id' => $student->school_id,
            'school_year' => '2026-2027',
            'program' => 'Master of Arts',
            'registrar_approval_status' => 'approved',
            'final_approval_status' => 'approved',
            'created_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'Coordinator']))
            ->delete('/comprehensive-exam/'.$application->application_id.'/reset')
            ->assertForbidden();

        $this->assertDatabaseHas('exam_application', ['application_id' => $application->application_id]);
    }
}
