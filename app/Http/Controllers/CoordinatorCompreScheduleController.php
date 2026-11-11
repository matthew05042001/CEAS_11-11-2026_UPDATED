<?php

namespace App\Http\Controllers;

use App\Models\ComprehensiveExamSchedule;
use App\Models\ExamApplication;
use App\Models\Notification;
use App\Models\PaymentSubmission;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CoordinatorCompreScheduleController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeCoordinator($request);

        return to_route('coordinator.compre-exam.index');
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCoordinator($request);

        $data = $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'Student')],
            'program' => ['required', 'string', 'max:255'],
            'date' => ['required', 'regex:/^\d{4}-\d{2}-\d{2}$/', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:255'],
            'proctor_ids' => ['required', 'array', 'min:1'],
            'proctor_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('role', 'Proctor')],
            'teacher_assigned' => ['required', 'string', 'max:255'],
        ]);
        $student = User::query()->findOrFail($data['student_id']);
        $this->ensureStudentCanBeScheduled($request, $student);
        $proctors = User::query()->whereIn('id', $data['proctor_ids'])->get()->keyBy('id');
        $proctorNames = collect($data['proctor_ids'])->map(fn (int $id) => $proctors->get($id)->display_name)->all();

        $schedule = ComprehensiveExamSchedule::create([
            'student_id' => $student->id,
            'exam_id' => (string) Str::uuid(),
            'program' => $student->program,
            'exam_date' => $data['date'],
            'exam_time' => $data['time'],
            'venue' => $data['venue'],
            'proctor_name' => $proctorNames[0],
            'proctors' => $proctorNames,
            'teacher_assigned' => $data['teacher_assigned'],
        ]);
        $schedule->proctorUsers()->sync($data['proctor_ids']);
        $schedule->load('proctorUsers:id,first_name,last_name');
        $this->notifyStudent($schedule, false);

        return response()->json(['schedule' => $this->scheduleData($schedule)], 201);
    }

    public function update(Request $request, ComprehensiveExamSchedule $schedule): JsonResponse
    {
        $this->authorizeCoordinator($request);

        $data = $request->validate([
            'program' => ['required', 'string', 'max:255'],
            'date' => ['required', 'regex:/^\d{4}-\d{2}-\d{2}$/', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'venue' => ['required', 'string', 'max:255'],
            'proctor_ids' => ['required', 'array', 'min:1'],
            'proctor_ids.*' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('role', 'Proctor')],
            'teacher_assigned' => ['required', 'string', 'max:255'],
            'change_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        if ($schedule->student_id) {
            $student = User::query()->findOrFail($schedule->student_id);
            $this->ensureStudentCanBeScheduled($request, $student);
        }
        $proctors = User::query()->whereIn('id', $data['proctor_ids'])->get()->keyBy('id');
        $proctorNames = collect($data['proctor_ids'])->map(fn (int $id) => $proctors->get($id)->display_name)->all();

        $schedule->update([
            'program' => $schedule->student_id ? $student->program : $data['program'],
            'exam_date' => $data['date'],
            'exam_time' => $data['time'],
            'venue' => $data['venue'],
            'proctor_name' => $proctorNames[0],
            'proctors' => $proctorNames,
            'teacher_assigned' => $data['teacher_assigned'],
            'change_reason' => $data['change_reason'],
        ]);
        $schedule->proctorUsers()->sync($data['proctor_ids']);
        $schedule->load('proctorUsers:id,first_name,last_name');
        $this->notifyStudent($schedule, true);

        return response()->json(['schedule' => $this->scheduleData($schedule)]);
    }

    private function notifyStudent(ComprehensiveExamSchedule $schedule, bool $updated): void
    {
        if (! $schedule->student_id) {
            return;
        }

        $date = $schedule->exam_date?->format('F j, Y');
        $time = Carbon::parse($schedule->exam_time)->format('g:i A');
        $proctors = $schedule->proctorUsers->map(fn (User $proctor) => $proctor->display_name)->implode(', ');
        $details = [
            "Date: {$date}",
            "Time: {$time}",
            "Venue: {$schedule->venue}",
            "Teacher: {$schedule->teacher_assigned}",
        ];
        if ($proctors !== '') {
            $details[] = "Proctors: {$proctors}";
        }
        if ($updated && filled($schedule->change_reason)) {
            $details[] = 'Reason: '.$schedule->change_reason;
        }

        Notification::create([
            'user_id' => $schedule->student_id,
            'type' => $updated ? 'comprehensive-exam-schedule-updated' : 'comprehensive-exam-scheduled',
            'title' => $updated ? 'Comprehensive exam schedule updated' : 'Comprehensive exam scheduled',
            'message' => ($updated ? 'Your comprehensive exam schedule has been updated. ' : 'Your comprehensive exam has been scheduled. ')
                .implode('; ', $details),
            'link' => '/dashboard',
            'read' => false,
        ]);
    }

    private function scheduleData(ComprehensiveExamSchedule $schedule): array
    {
        $assignedProctors = $schedule->proctorUsers;

        return [
            'id' => $schedule->id,
            'program' => $schedule->program,
            'exam_date' => $schedule->exam_date?->format('Y-m-d'),
            'exam_time' => $schedule->exam_time,
            'venue' => $schedule->venue,
            'proctors' => $assignedProctors->isNotEmpty()
                ? $assignedProctors->map(fn (User $proctor) => $proctor->display_name)->all()
                : ($schedule->proctors ?: [$schedule->proctor_name]),
            'proctor_ids' => $assignedProctors->pluck('id')->all(),
            'teacher_assigned' => $schedule->teacher_assigned,
            'change_reason' => $schedule->change_reason,
        ];
    }

    private function authorizeCoordinator(Request $request): void
    {
        abort_unless(in_array($request->user()?->role, ['Coordinator', 'Administrative Assistant', 'Dean'], true), 403);
    }

    private function ensureStudentCanBeScheduled(Request $request, User $student): void
    {
        $user = $request->user();
        if ($user->role === 'Coordinator') {
            abort_unless(in_array($student->program, $user->allowedProgramNames(), true), 403);
        }

        $application = ExamApplication::query()
            ->whereIn('student_id', array_filter([$student->school_id, $student->id], fn ($id) => filled($id)))
            ->latest('application_id')
            ->first();
        $payment = PaymentSubmission::query()
            ->where('student_id', $student->id)
            ->where('payment_type', 'exam')
            ->latest('payment_id')
            ->first();

        if (
            ! $application
            || strtolower($application->registrar_approval_status ?? 'pending') !== 'approved'
            || strtolower($application->final_approval_status ?? 'pending') !== 'approved'
            || strtolower($application->permit_status ?? 'pending') !== 'approved'
            || ! $payment
            || strtolower($payment->status) !== 'approved'
        ) {
            throw ValidationException::withMessages([
                'student_id' => 'The student can be scheduled after the application and payment are approved and the Administrative Assistant allows them to take the exam.',
            ]);
        }
    }
}
