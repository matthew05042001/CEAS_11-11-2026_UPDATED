<?php

// app/Http/Controllers/ComprehensiveExamController.php

namespace App\Http\Controllers;

use App\Models\ExamApplication;
use App\Models\ComprehensiveExamSchedule;
use App\Models\Notification;
use App\Models\PaymentSubmission;
use App\Models\StudentResponse;
use App\Services\ComprehensiveEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ComprehensiveExamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $studentId = $user->student_id ?? $user->school_id ?? $user->id;

        // COORDINATOR VIEW
        if (method_exists($user, 'isCoordinator') && $user->isCoordinator()) {
            $programs = method_exists($user, 'allowedProgramNames') ? $user->allowedProgramNames() : [];
            $applications = ExamApplication::withCount('subjects')
                ->whereIn('program', $programs)
                ->latest('application_id')
                ->paginate(20);

            return Inertia::render('coordinator/compre-exam/Index', [
                'applications' => $applications,
                'programs' => $programs,
            ]);
        }

        // STUDENT VIEW
        $application = ExamApplication::with('subjects')
            ->where('student_id', $studentId)
            ->latest('created_at')
            ->first();

        $response = null;
        if ($application) {
            $response = [
                'application_id' => $application->application_id,
                'school_year' => $application->school_year,
                'permit_status' => $application->permit_status,
                'final_approval_status' => $application->final_approval_status,
                'registrar_approval_status' => $application->registrar_approval_status ?? 'pending',
                'contact_number' => $application->contact_number,
                'telephone_number' => $application->telephone_number,
                'office_address' => $application->office_address,
                'program' => $application->program,
                'created_at' => $application->created_at,
                // subjects show up now
                'subjects' => $application->subjects->map(fn ($s) => [
                    'subject' => $s->subject_name,
                    'date' => $s->exam_date,
                    'startTime' => $s->start_time,
                    'endTime' => $s->end_time,
                ]),
                // personal info from users table
                'first_name' => $user->first_name ?? '',
                'middle_name' => $user->middle_name ?? null,
                'last_name' => $user->last_name ?? '',
                'email' => $user->email ?? '',
                'student_id' => $studentId,
            ];
        }

        return Inertia::render('student/submissions/comprehensive-exam/Index', [
            // 👇 pass the built response instead of null
            'application' => $response,
            // Remove hardcoded eligibility - let frontend call API dynamically
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->role === 'Student', 403);
        $validated = $request->validate([
            'schoolYear' => ['required', 'string', 'max:20'],
            'program' => ['required', 'string', 'max:255'],
            'family_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'email_address' => ['required', 'email', 'max:255'],
            'id_number' => ['required', 'string', 'max:100'],
            'officeAddress' => ['nullable', 'string', 'max:255'],
            'mobileNo' => ['required', 'string', 'max:50'],
            'telephoneNo' => ['nullable', 'string', 'max:50'],
            'subjects' => ['sometimes', 'array'],
            'subjects.*.subject' => ['required', 'string', 'max:255'],
            'subjects.*.date' => ['required', 'date'],
            'subjects.*.startTime' => ['required', 'date_format:H:i'],
            'subjects.*.endTime' => ['required', 'date_format:H:i', 'after:subjects.*.startTime'],
        ]);

        $user = $request->user();
        $studentId = $user->student_id ?? $user->school_id ?? $user->id;
        if (! $studentId) {
            return back()->withErrors(['student_id' => 'Your account has no student ID associated.']);
        }

        if (ExamApplication::where('student_id', $studentId)->where('school_year', $validated['schoolYear'])->exists()) {
            return to_route('comprehensive-exam.index')->with('info', 'You already have an application for this school year.');
        }
        $eligibility = app(ComprehensiveEligibilityService::class)->check($request);
        if (($eligibility['eligible'] ?? false) !== true) {
            throw ValidationException::withMessages(['eligibility' => 'Eligibility has not been verified. Check your grades, documents, tuition clearance, and school portal session before applying.']);
        }

        return DB::transaction(function () use ($studentId, $validated, $user) {
            // Enforce one application per student per school year
            $application = ExamApplication::firstOrCreate(
                ['student_id' => $studentId, 'school_year' => $validated['schoolYear']],
                [
                    'permit_status' => 'pending',
                    'final_approval_status' => 'pending',
                    'registrar_approval_status' => 'pending',
                    'contact_number' => $validated['mobileNo'],
                    'telephone_number' => $validated['telephoneNo'] ?? null,
                    'office_address' => $validated['officeAddress'] ?? null,
                    'program' => $validated['program'],
                ]
            );

            // Only create subjects if this is a new application
            if (! $application->wasRecentlyCreated) {
                return to_route('comprehensive-exam.index')
                    ->with('info', 'You already have an application for this school year.');
            }

            foreach ($validated['subjects'] ?? [] as $s) {
                $application->subjects()->create([
                    'subject_name' => $s['subject'],
                    'exam_date' => $s['date'],
                    'start_time' => $s['startTime'],
                    'end_time' => $s['endTime'],
                ]);
            }

            StudentResponse::create([
                'user_id' => $user->id,
                'family_name' => $validated['family_name'],
                'first_name' => $validated['first_name'],
                'program' => $validated['program'],
                'contact_number' => $validated['mobileNo'],
                'email_address' => $validated['email_address'],
                'id_number' => $validated['id_number'],
            ]);

            return to_route('comprehensive-exam.index')->with('success', 'Application submitted.');
        });
    }

    public function reset(Request $request, int $applicationId)
    {
        abort_unless($request->user()?->role === 'Student', 403);

        $user = $request->user();
        $studentId = $user->student_id ?? $user->school_id ?? $user->id;
        $receiptPaths = [];

        DB::transaction(function () use ($applicationId, $studentId, $user, &$receiptPaths) {
            $application = ExamApplication::query()
                ->where('application_id', $applicationId)
                ->where('student_id', $studentId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                strtolower((string) $application->registrar_approval_status) === 'approved'
                    && strtolower((string) $application->final_approval_status) === 'approved',
                409,
                'Only an approved application can be reset here.',
            );

            $payments = PaymentSubmission::query()
                ->where('student_id', $user->id)
                ->where('payment_type', 'exam');
            $receiptPaths = $payments->pluck('receipt_image')->filter()->values()->all();

            $application->subjects()->delete();
            $application->delete();
            $payments->delete();
            ComprehensiveExamSchedule::query()->where('student_id', $user->id)->delete();
            Notification::query()
                ->where('user_id', $user->id)
                ->whereIn('type', [
                    'comprehensive-exam-payment-ready',
                    'comprehensive-exam-reviewed',
                    'comprehensive-exam-scheduled',
                    'comprehensive-exam-schedule-updated',
                ])
                ->delete();
        });

        $disk = Storage::disk('public');
        $failedReceiptDeletes = [];
        foreach ($receiptPaths as $path) {
            if ($disk->exists($path) && ! $disk->delete($path)) {
                $failedReceiptDeletes[] = $path;
            }
        }
        if ($failedReceiptDeletes !== []) {
            Log::error('Comprehensive exam reset could not remove receipt files.', [
                'user_id' => $user->id,
                'application_id' => $applicationId,
                'paths' => $failedReceiptDeletes,
            ]);

            return to_route('comprehensive-exam.index')
                ->with('warning', 'Your application was reset, but a receipt file could not be removed. Please contact support.');
        }

        return to_route('comprehensive-exam.index')->with('success', 'Your application was reset. You can submit a new one now.');
    }
}
