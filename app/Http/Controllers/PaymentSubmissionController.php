<?php

// filepath: c:\GSPS\Graduate_School_System\app\Http\Controllers\PaymentSubmissionController.php

namespace App\Http\Controllers;

use App\Models\PaymentSubmission;
use App\Models\ExamApplication;
use App\Services\ExamReviewAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PaymentSubmissionController extends Controller
{
    public function receipt(Request $request, PaymentSubmission $payment)
    {
        abort_unless($payment->payment_type === 'exam', 404);

        $user = $request->user();
        if ((int) $payment->student_id !== (int) $user->id) {
            $access = app(ExamReviewAccess::class);
            $access->authorize($user);

            if ($user->role !== 'Administrative Assistant') {
                $student = $payment->student;
                abort_unless($student && in_array($student->program, $access->programs($user), true), 403);
            }
        }

        $disk = Storage::disk('public');
        abort_unless(
            is_string($payment->receipt_image)
                && $payment->receipt_image !== ''
                && $disk->exists($payment->receipt_image),
            404,
            'Receipt file not found.'
        );

        return response()->file($disk->path($payment->receipt_image), [
            'Content-Type' => $disk->mimeType($payment->receipt_image) ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        // Accept either school_id or user id stored into exam_application.student_id
        $ids = array_values(array_filter([$user->school_id, $user->id], fn ($v) => filled($v)));

        $application = ExamApplication::query()
            ->whereIn('student_id', $ids)
            ->latest('application_id')
            ->first();
        $approvalStatus = match (true) {
            ! $application => 'not_submitted',
            strtolower($application->final_approval_status ?? '') === 'rejected' => 'rejected',
            strtolower($application->registrar_approval_status ?? 'pending') !== 'approved' => 'registrar_pending',
            strtolower($application->final_approval_status ?? '') !== 'approved' => 'aa_pending',
            default => 'approved',
        };

        $payment = PaymentSubmission::where('student_id', $user->id)
            ->where('payment_type', 'exam')
            ->orderByDesc('payment_id')
            ->first();

        return Inertia::render('payment/Index', [
            'student' => [
                'name' => trim($user->first_name.' '.($user->middle_name ? $user->middle_name.' ' : '').$user->last_name),
                'program' => $user->program ?? null,
                'email' => $user->email ?? null,
            ],
            'canSubmit' => $approvalStatus === 'approved',
            'approvalStatus' => $approvalStatus,
            'payment' => $payment ? [
                'payment_id' => $payment->payment_id,
                'or_number' => $payment->or_number,
                'payment_date' => optional($payment->payment_date)->toDateString(),
                'receipt_image' => $payment->receipt_image,
                'status' => $payment->status,
                'remarks' => $payment->remarks,
                'amount_paid' => $payment->amount_paid,
                'created_at' => optional($payment->created_at)->format('Y-m-d H:i:s'),
            ] : null,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        // Must have an application first (school_id or id in exam_application.student_id)
        $ids = array_values(array_filter([$user->school_id, $user->id], fn ($v) => filled($v)));
        $application = ExamApplication::query()
            ->whereIn('student_id', $ids)
            ->latest('application_id')
            ->first();

        if (! $application) {
            return back()->withErrors([
                'form' => 'You can only submit payment after submitting your comprehensive application.',
            ])->withInput();
        }
        if (
            strtolower($application->final_approval_status ?? '') !== 'approved'
            || strtolower($application->registrar_approval_status ?? 'pending') !== 'approved'
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'form' => 'Payment is available only after the Registrar and Administrative Assistant approve your comprehensive exam application.',
            ]);
        }

        // Existing record?
        $existing = PaymentSubmission::where('student_id', $user->id)
            ->where('payment_type', 'exam')
            ->first();

        $validated = $request->validate([
            'or_number' => ['required', 'string', 'max:50'],
            'payment_date' => ['required', 'date'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
            'receipt_image' => [$existing && $existing->receipt_image ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,gif,webp,pdf', 'max:5120'],
        ]);

        $payload = [
            'student_id' => $user->id,
            'payment_type' => 'exam',
            'or_number' => $validated['or_number'],
            'payment_date' => $validated['payment_date'],
            'amount_paid' => $validated['amount_paid'],
            'status' => 'pending',
            'checked_by' => null, 'checked_at' => null, 'remarks' => null,
        ];

        if ($request->hasFile('receipt_image')) {
            $payload['receipt_image'] = $request->file('receipt_image')->store('payment_receipts', 'public');
        }

        PaymentSubmission::updateOrCreate(
            ['student_id' => $user->id, 'payment_type' => 'exam'],
            $payload
        );

        return redirect()->route('payment.index')->with('success', 'Payment submitted successfully.');
    }
}
