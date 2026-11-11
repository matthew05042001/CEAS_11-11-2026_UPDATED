<?php

namespace App\Http\Controllers;

use App\Models\ExamApplication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RegistrarExamApprovalController extends Controller
{
    public function approve(Request $request, ExamApplication $application)
    {
        abort_unless($request->user()?->role === 'Registrar', 403);

        return DB::transaction(function () use ($request, $application) {
            $application = ExamApplication::whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            abort_unless(strtolower($application->registrar_approval_status ?? 'pending') === 'pending', 409, 'This application has already received Registrar review.');

            $application->update([
                'registrar_approval_status' => 'approved',
                'registrar_approved_at' => now(),
                'registrar_approved_by' => $request->user()->id,
            ]);

            return to_route('dashboard')->with('success', 'Application approved and sent to the Administrative Assistant for payment approval.');
        });
    }
}
