<?php

namespace App\Http\Controllers;

use App\Models\ExamApplication;
use App\Services\ExamReviewAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class CoordinatorComprePaymentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        app(ExamReviewAccess::class)->authorize($user);

        $programs = app(ExamReviewAccess::class)->programs($user);
        if (empty($programs) && $user->role !== 'Administrative Assistant') {
            return Inertia::render('coordinator/compre-payment/Index', [
                'programs' => [],
                'pending' => [],
                'approved' => [],
                'rejected' => [],
                'counts' => ['pending' => 0, 'approved' => 0, 'rejected' => 0],
                'canSetExamPermit' => $user->role === 'Administrative Assistant',
            ]);
        }

        // Prefer the concrete table used by the student PaymentForm
        $candidates = ['payment_submissions', 'exam_payment', 'compre_payments', 'payments', 'payment', 'payment_forms', 'exam_application_payments'];
        $table = null;
        foreach ($candidates as $t) {
            if (Schema::hasTable($t)) {
                $table = $t;
                break;
            }
        }
        if (! $table) {
            return Inertia::render('coordinator/compre-payment/Index', [
                'programs' => $programs,
                'pending' => [],
                'approved' => [],
                'rejected' => [],
                'counts' => ['pending' => 0, 'approved' => 0, 'rejected' => 0],
                'canSetExamPermit' => $user->role === 'Administrative Assistant',
            ]);
        }

        // Columns (match PaymentForm: or_number, payment_date, receipt_image, amount_paid, status, remarks)
        $idCol = Schema::hasColumn($table, 'payment_id') ? 'payment_id' : (Schema::hasColumn($table, 'id') ? 'id' : null);
        $studentCol = Schema::hasColumn($table, 'student_id') ? 'student_id' : (Schema::hasColumn($table, 'user_id') ? 'user_id' : null);
        $programCol = Schema::hasColumn($table, 'program') ? 'program' : null;
        $orCol = collect(['or_number', 'reference_no', 'receipt_no', 'transaction_id'])->first(fn ($c) => Schema::hasColumn($table, $c));
        $amountCol = collect(['amount_paid', 'amount', 'fee', 'total_amount', 'paid_amount'])->first(fn ($c) => Schema::hasColumn($table, $c));
        $statusCol = collect(['status', 'approval_status', 'finance_status'])->first(fn ($c) => Schema::hasColumn($table, $c));
        $remarksCol = collect(['remarks', 'notes', 'comment'])->first(fn ($c) => Schema::hasColumn($table, $c));
        $proofCol = collect(['receipt_image', 'proof_url', 'file_url', 'receipt_url', 'attachment'])->first(fn ($c) => Schema::hasColumn($table, $c));
        $createdCol = Schema::hasColumn($table, 'created_at') ? 'created_at'
                        : (Schema::hasColumn($table, 'submitted_at') ? 'submitted_at'
                        : (Schema::hasColumn($table, 'uploaded_at') ? 'uploaded_at' : null));

        // Join to users; filter strictly by user's program to avoid missing rows due to missing program on payments
        $q = DB::table("$table as p")
            ->leftJoin('users as u', function ($join) use ($studentCol) {
                if ($studentCol) {
                    $join->on('u.id', '=', "p.$studentCol")
                        ->orOn('u.school_id', '=', "p.$studentCol");
                }
            })
            ->when($user->role !== 'Administrative Assistant', fn ($query) => $query->whereIn('u.program', $programs))
            ->when(Schema::hasColumn($table, 'payment_type'), fn ($qq) => $qq->where('p.payment_type', 'exam'))
            ->where('u.id', '<>', $user->id) // exclude coordinator
            ->selectRaw(implode(', ', array_filter([
                $idCol ? "p.$idCol as id" : 'COALESCE(p.payment_id, p.id) as id',
                'u.id as user_id', 'u.first_name', 'u.middle_name', 'u.last_name', 'u.email', 'u.school_id',
                $programCol ? "p.$programCol as program" : 'u.program as program',
                $orCol ? "p.$orCol as reference" : 'NULL as reference',
                $amountCol ? "p.$amountCol as amount" : 'NULL as amount',
                $statusCol ? "LOWER(p.$statusCol) as status" : "LOWER('pending') as status",
                $remarksCol ? "p.$remarksCol as remarks" : 'NULL as remarks',
                $proofCol ? "p.$proofCol as proof_url" : 'NULL as proof_url',
                $createdCol ? "p.$createdCol as submitted_at" : 'p.created_at as submitted_at',
            ])));

        // Avoid ambiguous order by
        if ($idCol) {
            $q->orderByDesc("p.$idCol");
        } elseif ($createdCol) {
            $q->orderByDesc("p.$createdCol");
        }

        $paymentRows = $q->get();
        $applicationStudentIds = $paymentRows->flatMap(fn ($row) => [
            (string) $row->user_id,
            (string) ($row->school_id ?? ''),
        ])->filter()->unique()->values();
        $applicationsByStudentId = ExamApplication::query()
            ->whereIn('student_id', $applicationStudentIds)
            ->orderByDesc('application_id')
            ->get(['student_id', 'permit_status'])
            ->unique('student_id')
            ->keyBy('student_id');

        $rows = $paymentRows->map(function ($r) use ($table, $applicationsByStudentId) {
            $status = in_array($r->status, ['approved', 'rejected', 'pending']) ? $r->status : 'pending';
            $proofUrl = $r->proof_url ?? null;
            if ($proofUrl && $table === 'payment_submissions') {
                $proofUrl = route('payment-submissions.receipt', ['payment' => $r->id]);
            }
            $application = $applicationsByStudentId->get((string) $r->school_id)
                ?? $applicationsByStudentId->get((string) $r->user_id);

            return [
                'id' => (int) $r->id,
                'first_name' => $r->first_name ?? '',
                'middle_name' => $r->middle_name ?? null,
                'last_name' => $r->last_name ?? '',
                'email' => $r->email ?? null,
                'school_id' => $r->school_id ?? null,
                'program' => $r->program ?? null,
                'reference' => $r->reference ?? null,
                'amount' => is_null($r->amount) ? null : (float) $r->amount,
                'submitted_at' => $r->submitted_at ?? null,
                'status' => $status,
                'exam_permit_status' => strtolower((string) ($application?->permit_status ?? 'pending')),
                'remarks' => $r->remarks ?? null,
                'proof_url' => $proofUrl,
            ];
        });

        $pending = $rows->where('status', 'pending')->values();
        $approved = $rows->where('status', 'approved')->values();
        $rejected = $rows->where('status', 'rejected')->values();

        return Inertia::render('coordinator/compre-payment/Index', [
            'programs' => $programs,
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'counts' => [
                'pending' => $pending->count(),
                'approved' => $approved->count(),
                'rejected' => $rejected->count(),
            ],
            'canSetExamPermit' => $user->role === 'Administrative Assistant',
        ]);
    }

    public function setExamPermit(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()?->role === 'Administrative Assistant', 403);
        $data = $request->validate(['decision' => ['required', 'in:allowed,not_allowed']]);

        $payment = DB::table('payment_submissions')
            ->where('payment_id', $id)
            ->where('payment_type', 'exam')
            ->where('status', 'approved')
            ->first();
        abort_unless($payment, 404, 'An approved exam payment was not found.');

        $student = \App\Models\User::query()->findOrFail($payment->student_id);
        $application = ExamApplication::query()
            ->whereIn('student_id', array_filter([(string) $student->school_id, (string) $student->id]))
            ->orderByDesc('application_id')
            ->firstOrFail();
        abort_unless(
            strtolower((string) $application->registrar_approval_status) === 'approved'
                && strtolower((string) $application->final_approval_status) === 'approved',
            409,
            'The application must be approved before setting exam permission.',
        );

        $allowed = $data['decision'] === 'allowed';
        $application->update([
            'permit_status' => $allowed ? 'approved' : 'rejected',
            'permit_DATE' => now(),
            'permit_reason' => $allowed ? 'Allowed by Administrative Assistant' : 'Not allowed by Administrative Assistant',
        ]);

        return redirect()->route('coordinator.compre-payment.index')
            ->with('success', $allowed ? 'Student is allowed to take the exam.' : 'Student is not allowed to take the exam.');
    }

    public function approve(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        app(ExamReviewAccess::class)->authorize($user);

        if (! Schema::hasTable('payment_submissions')) {
            return back()->withErrors(['status' => 'Payments table not found.']);
        }

        // Limit to students within coordinator programs
        $programs = method_exists($user, 'allowedProgramNames')
            ? $user->allowedProgramNames() : array_filter([$user->program]);

        $row = DB::table('payment_submissions as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.student_id')
            ->where('p.payment_id', $id)
            ->where('p.payment_type', 'exam')
            ->when($user->role !== 'Administrative Assistant', fn ($query) => $query->whereIn('u.program', $programs))
            ->select('p.payment_id', 'p.status')
            ->first();

        if (! $row) {
            return back()->withErrors(['status' => 'Record not found or not within your programs.']);
        }
        if (strtolower($row->status) !== 'pending') {
            return back()->withErrors(['status' => 'Only pending payments can be approved.']);
        }

        $changed = DB::table('payment_submissions')
            ->where('payment_id', $id)->where('status', 'pending')
            ->update([
                'status' => 'approved',
                'checked_by' => $user->id, 'checked_at' => now(),
                'updated_at' => now(),
            ]);

        if ($changed !== 1) {
            return back()->withErrors(['status' => 'This payment has already been reviewed.']);
        }

        return redirect()->route('coordinator.compre-payment.index')->with('success', 'Payment approved.');
    }

    public function reject(Request $request, int $id): RedirectResponse
    {
        $user = $request->user();
        app(ExamReviewAccess::class)->authorize($user);

        $request->validate([
            'remarks' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if (! Schema::hasTable('payment_submissions')) {
            return back()->withErrors(['status' => 'Payments table not found.']);
        }

        $programs = method_exists($user, 'allowedProgramNames')
            ? $user->allowedProgramNames() : array_filter([$user->program]);

        $row = DB::table('payment_submissions as p')
            ->leftJoin('users as u', 'u.id', '=', 'p.student_id')
            ->where('p.payment_id', $id)
            ->where('p.payment_type', 'exam')
            ->when($user->role !== 'Administrative Assistant', fn ($query) => $query->whereIn('u.program', $programs))
            ->select('p.payment_id', 'p.status')
            ->first();

        if (! $row) {
            return back()->withErrors(['status' => 'Record not found or not within your programs.']);
        }
        if (strtolower($row->status) !== 'pending') {
            return back()->withErrors(['status' => 'Only pending payments can be rejected.']);
        }

        $changed = DB::table('payment_submissions')
            ->where('payment_id', $id)->where('status', 'pending')
            ->update([
                'status' => 'rejected',
                'checked_by' => $user->id, 'checked_at' => now(),
                'remarks' => $request->input('remarks'),
                'updated_at' => now(),
            ]);

        if ($changed !== 1) {
            return back()->withErrors(['status' => 'This payment has already been reviewed.']);
        }

        return redirect()->route('coordinator.compre-payment.index')->with('success', 'Payment rejected.');
    }
}
