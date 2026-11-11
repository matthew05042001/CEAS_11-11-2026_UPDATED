<?php

use App\Helpers\ProgramLevel;
use App\Http\Controllers\AA\PaymentVerificationController;
use App\Http\Controllers\AcademicRecordController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\AdviserStudentController;
use App\Http\Controllers\AdviserStudentProgressController;
use App\Http\Controllers\Api\ComprehensiveExamEligibilityController;
use App\Http\Controllers\Api\StudentSearchController;
use App\Http\Controllers\ComprehensiveExamController;
use App\Http\Controllers\CoordinatorAdviserController;
use App\Http\Controllers\CoordinatorCompreExamController;
use App\Http\Controllers\CoordinatorComprePaymentController;
use App\Http\Controllers\CoordinatorCompreScheduleController;
use App\Http\Controllers\CoordinatorDefenseController;
use App\Http\Controllers\CoordinatorProgramAssignmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DebugController;
use App\Http\Controllers\DefenseAcademicOutcomeController;
use App\Http\Controllers\DefenseCertificateController;
use App\Http\Controllers\DefenseCorrectionController;
use App\Http\Controllers\DefenseRegisterController;
use App\Http\Controllers\DefenseRequestController;
use App\Http\Controllers\DefenseRequirementController;
use App\Http\Controllers\DefenseScheduleController;
use App\Http\Controllers\DocumentTemplateController;
use App\Http\Controllers\EmailsController;
use App\Http\Controllers\FinanceDocumentWorkflowController;
use App\Http\Controllers\GeneratedDocumentController;
use App\Http\Controllers\HonorariumLedgerController;
use App\Http\Controllers\HonorariumSummaryController;
use App\Http\Controllers\HonorariumWorkspaceController;
use App\Http\Controllers\InstructorClassListController;
use App\Http\Controllers\LegacyLinkController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PanelistController;
use App\Http\Controllers\PanelistHonorariumSpecController;
use App\Http\Controllers\PaymentRateController;
use App\Http\Controllers\PaymentSubmissionController;
use App\Http\Controllers\RegistrarExamApprovalController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\ScheduleEventController;
use App\Http\Controllers\StudentDocumentController;
use App\Http\Controllers\StudentResponseController;
use App\Http\Controllers\StudentRecordController;
use App\Http\Controllers\SystemStatusController;
use App\Http\Controllers\UserSignatureController;
use App\Mail\AdviserInvitation;
use App\Models\AaPaymentVerification;
use App\Models\DefenseRequest;
use App\Models\DocumentTemplate;
use App\Models\Panelist;
use App\Models\PaymentRate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Public / Guest
|--------------------------------------------------------------------------
*/
Route::get('/', function () {
    return Auth::check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware('guest')->group(function () {
    // BASIC login page (adjust Inertia component path to what you actually have)
    Route::get('/login', fn () => Inertia::render('auth/Login'))->name('login');
    // Uncomment if you allow registration
    // Route::get('/register', fn() => Inertia::render('auth/Register'))->name('register');
});

/*
|--------------------------------------------------------------------------
| Debug / Diagnostics (optional)
|--------------------------------------------------------------------------
*/
Route::get('/test-upload-limits', function () {
    return response()->json([
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'max_input_time' => ini_get('max_input_time'),
        'upload_config' => config('upload'),
    ]);
});

/*
|--------------------------------------------------------------------------
| Authenticated + Verified
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/coordinator/defense-requests/all-defense-requests', [DefenseRequestController::class, 'allForCoordinator'])->middleware('auth');
    Route::get('/api/coordinator/code', [CoordinatorAdviserController::class, 'getCoordinatorCode']);
    Route::post('/api/adviser/register-with-coordinator-code', [CoordinatorAdviserController::class, 'registerWithCode']);

    // Coordinator Adviser Management Routes
    Route::get('/api/coordinator/advisers', [CoordinatorAdviserController::class, 'index']);
    Route::get('/api/coordinator/advisers/search', [CoordinatorAdviserController::class, 'search']);
    Route::put('/api/coordinator/advisers/{id}', [CoordinatorAdviserController::class, 'update']);
    Route::post('/api/coordinator/advisers', [CoordinatorAdviserController::class, 'store']);
    Route::post('/api/coordinator/advisers/{id}/send-invitation', [CoordinatorAdviserController::class, 'sendInvitation']);
    Route::delete('/api/coordinator/advisers/{id}', [CoordinatorAdviserController::class, 'destroy']);

    // Coordinator manages adviser-student relationships (use CoordinatorAdviserController)
    Route::get('/api/coordinator/advisers/{adviser}/students', [CoordinatorAdviserController::class, 'students']);
    Route::get('/api/coordinator/advisers/{adviser}/pending-students', [CoordinatorAdviserController::class, 'pendingStudents']);
    Route::post('/api/coordinator/advisers/{adviser}/students', [CoordinatorAdviserController::class, 'storeStudent']);
    Route::delete('/api/coordinator/advisers/{adviser}/students/{student}', [CoordinatorAdviserController::class, 'destroyStudent']);
    Route::get('/api/coordinator/students/search', [CoordinatorAdviserController::class, 'searchStudents']);

    Route::get('/panelists/honorarium-specs', [PanelistHonorariumSpecController::class, 'index']);
    Route::put('/panelists/honorarium-specs', [PanelistHonorariumSpecController::class, 'update'])
        ->name('panelists.honorarium-specs.update');

    // PAYMENTVERIFIATION AA
    Route::prefix('aa')->group(function () {
        Route::get('/payment-verifications', [PaymentVerificationController::class, 'index'])->name('aa.payment-verifications');
        Route::post('/payment-verifications/{id}/status', [PaymentVerificationController::class, 'updateStatus'])->name('aa.payment-verifications.update-status');
        Route::post('/payment-verifications/batch', [PaymentVerificationController::class, 'addToBatch'])->name('aa.payment-verifications.batch');
        Route::get('/payment-batch/{batchId}/export', [PaymentVerificationController::class, 'exportBatch'])->name('aa.payment-batch.export');
        Route::post('/payment-verifications/bulk-update', [PaymentVerificationController::class, 'bulkUpdateStatus']);

    });

    // PAYMENT RATESS ETC.
    Route::get('/dean/payment-rates/export', [PaymentRateController::class, 'export'])->name('dean.payment-rates.export');
    Route::post('/dean/payment-rates/import', [PaymentRateController::class, 'import'])->name('dean.payment-rates.import');
    Route::post('/dean/payment-rates', [PaymentRateController::class, 'update'])
        ->name('dean.payment-rates.update');
    Route::get('/dean/payment-rates', [PaymentRateController::class, 'index'])
        ->name('dean.payment-rates.index');
    Route::get('/dean/payment-rates/data', [PaymentRateController::class, 'data'])
        ->name('dean.payment-rates.data');

    // Settings: Document Templates (Dean / Coordinator only)
    Route::get('/settings/documents', function () {
        abort_unless(in_array(Auth::user()->role, ['Dean', 'Super Admin']), 403);

        return Inertia::render('settings/documents/Index');
    })->name('settings.documents');

    Route::get('/settings/documents/{template}/edit', function (DocumentTemplate $template) {
        abort_unless(in_array(Auth::user()->role, ['Dean', 'Super Admin']), 403);

        return Inertia::render('settings/documents/TemplateEditor', [
            'templateId' => $template->id,
            'template' => $template,
        ]);
    })->name('settings.documents.edit');

    // Honorarium route
    // honorarium-summary route
    Route::get('/honorarium-summary', [HonorariumSummaryController::class, 'Index'])
        ->name('honorarium-summary.index');
    Route::get('/honorarium-summary/{record}/download', [HonorariumSummaryController::class, 'download'])
        ->name('honorarium-summary.download');
    // student-records route
    Route::get('/student-records', [StudentRecordController::class, 'index'])->name('student-records.index');
    Route::put('/student-records/{studentRecord}', [StudentRecordController::class, 'update'])->name('student-records.update');
    Route::delete('/student-records/{studentRecord}', [StudentRecordController::class, 'destroy'])->name('student-records.destroy');

    // Emails Controller
    Route::get('send-mail', [EmailsController::class, 'welcomeEmail']);

    // Settings: Signatures (any staff who can sign)
    Route::get('/settings/signatures', function () {
        return Inertia::render('settings/signatures/Index');
    })->name('settings.signatures');

    // Manual (debug) generation for a defense request
    Route::post(
        '/defense-requests/{defenseRequest}/generate-docs',
        [GeneratedDocumentController::class, 'generateNow']
    )
        ->name('defense-requests.generate-docs');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/registrar/comprehensive-exam/{application}/approve', [RegistrarExamApprovalController::class, 'approve'])
        ->name('registrar.comprehensive-exam.approve');

    Route::patch('/admin/users/{user}/role', [UserRoleController::class, 'update'])
        ->middleware('throttle:30,1')->name('admin.users.role.update');

    /* Coordinator Program Assignments - Super Admin only */
    Route::prefix('api/coordinator-assignments')->group(function () {
        Route::get('/', [CoordinatorProgramAssignmentController::class, 'index'])->name('coordinator-assignments.index');
        Route::get('/coordinators', [CoordinatorProgramAssignmentController::class, 'getCoordinators'])->name('coordinator-assignments.coordinators');
        Route::get('/programs', [CoordinatorProgramAssignmentController::class, 'getAvailablePrograms'])->name('coordinator-assignments.programs');
        Route::post('/', [CoordinatorProgramAssignmentController::class, 'store'])->name('coordinator-assignments.store');
        Route::delete('/{id}', [CoordinatorProgramAssignmentController::class, 'destroy'])->name('coordinator-assignments.destroy');
    });

    // Logout
    Route::post('/logout', function (Illuminate\Http\Request $r) {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    /* Notifications */
    Route::get('/notification', [NotificationController::class, 'index'])->name('notification.index');
    Route::get('/honorarium-ledger', [HonorariumLedgerController::class, 'index'])->name('honorarium-ledger.index');
    Route::get('/certificates', [DefenseCertificateController::class, 'index'])->name('certificates.index');
    Route::post('/certificates/request/{defenseRequest}', [DefenseCertificateController::class, 'requestCertificate'])->name('certificates.request');
    Route::post('/certificates/{certificate}/release', [DefenseCertificateController::class, 'release'])->name('certificates.release');
    Route::post('/certificates/{certificate}/email', [DefenseCertificateController::class, 'email'])->middleware('throttle:10,1')->name('certificates.email');
    Route::get('/certificates/{certificate}/download', [DefenseCertificateController::class, 'download'])->name('certificates.download');
    Route::get('/academic-progress', [AdviserStudentProgressController::class, 'index'])->name('academic-progress.index');
    Route::get('/academic-progress/{student}', [AdviserStudentProgressController::class, 'show'])->name('academic-progress.show');
    Route::put('/academic-progress/{student}', [AdviserStudentProgressController::class, 'update'])->name('academic-progress.update');
    Route::put('/defense-outcomes/{defenseRequest}', [DefenseAcademicOutcomeController::class, 'update'])->name('defense-outcomes.update');
    Route::get('/defense-register/csv', [DefenseRegisterController::class, 'csv'])->name('defense-register.csv');
    Route::get('/defense-register', [DefenseRegisterController::class, 'index'])->name('defense-register.index');
    Route::get('/defense-register/export', [DefenseRegisterController::class, 'export'])->name('defense-register.export');
    Route::get('/defense-register/print', [DefenseRegisterController::class, 'print'])->name('defense-register.print');
    Route::patch('/defense-register/{defenseRequest}/panel-types', [DefenseRegisterController::class, 'classify'])->name('defense-register.classify');
    Route::post('/defense-register/{defenseRequest}/confirm-service', [DefenseRegisterController::class, 'confirm'])->name('defense-register.confirm');
    Route::post('/honorarium-ledger/{defenseRequest}/prepare', [HonorariumLedgerController::class, 'prepare'])->name('honorarium-ledger.prepare');
    Route::patch('/honorarium-ledger/{disbursement}', [HonorariumLedgerController::class, 'update'])->name('honorarium-ledger.update');
    // Honorarium module additions, preserving the defense and comprehensive exam routes.
    Route::get('/honorarium-workspace', [HonorariumWorkspaceController::class, 'index'])->name('honorarium-workspace.index');
    Route::post('/honorarium-workspace/{defenseRequest}/submit-to-finance', [HonorariumWorkspaceController::class, 'submitToFinance'])->name('honorarium-workspace.submit-to-finance');
    Route::post('/honorarium-workspace/finance/{submission}/status', [HonorariumWorkspaceController::class, 'updateFinanceStatus'])->name('honorarium-workspace.finance-status');
    Route::get('/honorarium-documents', [FinanceDocumentWorkflowController::class, 'index'])->name('honorarium-documents.index');
    Route::post('/honorarium-documents/{defenseRequest}/submit-one', [FinanceDocumentWorkflowController::class, 'submitFirst'])->name('honorarium-documents.submit-one');
    Route::post('/honorarium-documents/{defenseRequest}/submit-two', [FinanceDocumentWorkflowController::class, 'submitSecond'])->name('honorarium-documents.submit-two');
    Route::post('/honorarium-documents/finance/{submission}/review', [FinanceDocumentWorkflowController::class, 'review'])->name('honorarium-documents.review');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.read-all');

    /* Payments */
    Route::redirect('/payments', '/payment')->name('payment.legacy');
    Route::get('/payment', [PaymentSubmissionController::class, 'index'])->name('payment.index');
    Route::post('/payment', [PaymentSubmissionController::class, 'store'])->name('payment.store');
    Route::get('/payment-submissions/{payment}/receipt', [PaymentSubmissionController::class, 'receipt'])
        ->name('payment-submissions.receipt');

    /* Student schedule page */
    Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::get('/schedules', fn () => redirect()->route('schedule.index'));

    // Events API
    Route::get('/api/calendar/events', [ScheduleEventController::class, 'list'])->name('api.calendar.events');
    Route::post('/api/calendar/events', [ScheduleEventController::class, 'store'])->name('api.calendar.events.store');
    Route::put('/api/calendar/events/{event}', [ScheduleEventController::class, 'update'])->name('api.calendar.events.update');
    Route::patch('/api/calendar/events/{event}/move', [ScheduleEventController::class, 'move'])->name('api.calendar.events.move');
    Route::delete('/api/calendar/events/{event}', [ScheduleEventController::class, 'destroy'])->name('api.calendar.events.delete');

    /* Defense Requirements (student submit) */
    Route::get('/defense-requirements', [DefenseRequirementController::class, 'index'])->name('defense-requirements.index');
    Route::redirect('/response', '/comprehensive-exam')->name('response.index');
    Route::redirect('/aa/responses', '/coordinator/compre-exam')->name('assistant.responses.index');
    Route::post('/aa/responses/{studentResponse}/evaluate', [StudentResponseController::class, 'evaluate'])
        ->name('assistant.responses.evaluate');
    Route::post('/defense-requests/{defenseRequest}/request-corrections', [DefenseCorrectionController::class, 'requestCorrections'])->name('defense-requests.request-corrections');
    Route::post('/defense-requirements/{defenseRequest}/resubmit', [DefenseCorrectionController::class, 'resubmit'])->name('defense-requirements.resubmit');
    Route::post('/defense-requirements', [DefenseRequirementController::class, 'store'])->name('defense-requirements.store');
    Route::post('/defense-requirements/{id}/unsubmit', [DefenseRequirementController::class, 'unsubmit'])
        ->name('defense-requirements.unsubmit');

    /* Adviser view of all student submissions */
    Route::get('/all-defense-requirements', [DefenseRequirementController::class, 'all'])
        ->name('defense-requirements.all');

    /* Defense Request (main workflow) */
    Route::get('/defense-request', [DefenseRequestController::class, 'index'])->name('defense-request.index');
    // Alias so frontend calls to /defense-requests (plural) also hit the same index action (JSON or Inertia)
    Route::get('/defense-requests', [DefenseRequestController::class, 'index'])->name('defense-requests.index');
    Route::post('/defense-request', [DefenseRequestController::class, 'store'])->name('defense-request.store');

    /* Workflow actions */
    Route::post(
        '/defense-requests/{defenseRequest}/adviser-decision',
        [DefenseRequestController::class, 'adviserDecision']
    )->name('defense-requests.adviser-decision');
    Route::post(
        '/defense-requests/{defenseRequest}/coordinator-decision',
        [DefenseRequestController::class, 'coordinatorDecision']
    )->name('defense-requests.coordinator-decision');

    /* Status / priority */
    Route::patch(
        '/defense-requests/{defenseRequest}/status',
        [DefenseRequestController::class, 'updateStatus']
    )->name('defense-requests.update-status');
    Route::patch(
        '/defense-requests/{defenseRequest}/priority',
        [DefenseRequestController::class, 'updatePriority']
    )->name('defense-requests.update-priority');

    /* Bulk */
    Route::patch(
        '/defense-requests/bulk-status',
        [DefenseRequestController::class, 'bulkUpdateStatus']
    )->name('defense-requests.bulk-update-status');
    Route::patch(
        '/defense-requests/bulk-priority',
        [DefenseRequestController::class, 'bulkUpdatePriority']
    )->name('defense-requests.bulk-update-priority');
    Route::delete(
        '/defense-requests/bulk-remove',
        [DefenseRequestController::class, 'bulkDelete']
    )->name('defense-requests.bulk-remove');
    Route::post('/defense-requests/bulk-approve', [DefenseRequestController::class, 'bulkApprove'])
        ->name('defense-requests.bulk-approve');
    Route::post('/defense-requests/bulk-reject', [DefenseRequestController::class, 'bulkReject'])
        ->name('defense-requests.bulk-reject');
    Route::post('/defense-requests/bulk-retrieve', [DefenseRequestController::class, 'bulkRetrieve'])
        ->name('defense-requests.bulk-retrieve');

    /* Adviser helpers */
    Route::get(
        '/defense-requests/adviser-suggestion',
        [DefenseRequestController::class, 'adviserSuggestion']
    )->name('defense-requests.adviser-suggestion');
    Route::get(
        '/defense-requests/adviser-candidates',
        [DefenseRequestController::class, 'adviserCandidates']
    )->name('defense-requests.adviser-candidates');

    /* Lightweight APIs */
    Route::get('/defense-requests/calendar', [DefenseRequestController::class, 'calendar'])->name('defense-requests.calendar');
    Route::get('/defense-requests/pending', [DefenseRequestController::class, 'pending'])->name('defense-requests.pending');
    Route::get('/api/defense-requests/count', [DefenseRequestController::class, 'count'])->name('api.defense-requests.count');
    Route::get('/api/defense-request/{defenseRequest}', [DefenseRequestController::class, 'apiShow'])->name('api.defense-request.show');

    Route::get('/storage/defense_requirements/{filename}', [DefenseRequestController::class, 'downloadAttachment'])
        ->defaults('directory', 'defense_requirements')->name('defense-requirements.attachment');

    foreach (['defense_documents', 'generated', 'defense-requirements'] as $directory) {
        Route::get('/storage/'.$directory.'/{filename}', [DefenseRequestController::class, 'downloadAttachment'])
            ->defaults('directory', $directory)->name($directory.'.attachment');
    }

    /* Attachments download */
    Route::get(
        '/storage/defense-attachments/{filename}',
        [DefenseRequestController::class, 'downloadAttachment']
    )->name('defense-attachments.download');

    /* Resource (keep after specific routes) */
    Route::resource('defense-requests', DefenseRequestController::class)
        ->only(['store', 'show']);

    /* Panelists CRUD */
    Route::get('/panelists', [PanelistController::class, 'view'])->name('panelists.view');
    Route::post('/panelists', [PanelistController::class, 'store'])->name('panelists.store');
    Route::put('/panelists/{panelist}', [PanelistController::class, 'update'])->name('panelists.update');
    Route::delete('/panelists/{panelist}', [PanelistController::class, 'destroy'])->name('panelists.destroy');
    Route::post('/panelists/bulk-delete', [PanelistController::class, 'bulkDelete'])->name('panelists.bulk-delete');
    Route::post('/panelists/bulk-status', [PanelistController::class, 'bulkUpdateStatus'])->name('panelists.bulk-status');

    /* Comprehensive Exam (student) */
    Route::get('/comprehensive-exam', [ComprehensiveExamController::class, 'index'])->name('comprehensive-exam.index');
    Route::post('/comprehensive-exam', [ComprehensiveExamController::class, 'store'])->name('comprehensive-exam.store');
    Route::delete('/comprehensive-exam/{application}/reset', [ComprehensiveExamController::class, 'reset'])
        ->whereNumber('application')
        ->name('comprehensive-exam.reset');
    Route::get('/dean/responses', [StudentResponseController::class, 'deanIndex'])
        ->name('dean.responses.index');

    Route::get('/dean/defense-requests', function () {
        abort_unless(in_array(Auth::user()->role, ['Dean', 'Super Admin'], true), 403);

        return redirect('/defense-requests');
    });
    /* Coordinator Comprehensive Exam */
    Route::get('/coordinator/compre-exam', [CoordinatorCompreExamController::class, 'index'])
        ->name('coordinator.compre-exam.index');
    Route::post('/coordinator/compre-exam/{application}/review', [CoordinatorCompreExamController::class, 'review'])->name('coordinator.compre-exam.review');
    Route::get('/coordinator/compre-payment', [CoordinatorComprePaymentController::class, 'index'])
        ->name('coordinator.compre-payment.index');
    Route::get('/coordinator/compre-schedule', [CoordinatorCompreScheduleController::class, 'index'])
        ->name('coordinator.compre-schedule.index');
    Route::post('/coordinator/compre-schedule', [CoordinatorCompreScheduleController::class, 'store'])
        ->name('coordinator.compre-schedule.store');
    Route::put('/coordinator/compre-schedule/{schedule}', [CoordinatorCompreScheduleController::class, 'update'])
        ->name('coordinator.compre-schedule.update');
    Route::post('/coordinator/compre-payment/{id}/approve', [CoordinatorComprePaymentController::class, 'approve'])
        ->name('coordinator.compre-payment.approve');
    Route::post('/coordinator/compre-payment/{id}/reject', [CoordinatorComprePaymentController::class, 'reject'])
        ->name('coordinator.compre-payment.reject');
    Route::post('/coordinator/compre-payment/{id}/exam-permit', [CoordinatorComprePaymentController::class, 'setExamPermit'])
        ->name('coordinator.compre-payment.exam-permit');

    /* Honorarium / Reports */
    Route::get('/generate-report', function () {
        abort_unless(in_array(Auth::user()->role, ['Administrative Assistant', 'Dean', 'Super Admin'], true), 403);

        return redirect()->route('honorarium-summary.index');
    })
        ->name('generate-report.index');
    // Honorarium data is supplied by HonorariumSummaryController above.

    /* Academic Records */
    Route::get('/academic-records', fn () => Inertia::render('student/academic-records/academic-records'))
        ->name('academic-records.index');

    /* System Status */
    Route::get('/system-status', fn () => Inertia::render('system-status'))->name('system-status');
    Route::get('/system-status/checks', SystemStatusController::class)->name('system-status.checks');

    /* Coordinator Defense Management */
    Route::prefix('coordinator')->name('coordinator.')->group(function () {
        Route::get('/defense-management', [CoordinatorDefenseController::class, 'index'])->name('defense.index');

        Route::get('/defense/{defenseRequest}', [CoordinatorDefenseController::class, 'show'])->name('defense.show');
        Route::post('/defense/{defenseRequest}/assign-panels', [CoordinatorDefenseController::class, 'assignPanels'])
            ->name('defense.assign-panels');
        Route::post('/defense/{defenseRequest}/schedule', [CoordinatorDefenseController::class, 'scheduleDefense'])
            ->name('defense.schedule');
        Route::post('/defense/{defenseRequest}/notify', [CoordinatorDefenseController::class, 'sendNotifications'])
            ->name('defense.notify');
        Route::put('/defense/{defenseRequest}', [CoordinatorDefenseController::class, 'updateDefense'])
            ->name('defense.update');

        Route::get('/defense-requests/approval', [CoordinatorDefenseController::class, 'getRequestsForApproval'])
            ->name('defense-requests.approval');

        Route::get('/schedule', [DefenseScheduleController::class, 'index'])
            ->name('schedule.index');
        Route::get('/schedule/calendar', [DefenseScheduleController::class, 'calendar'])
            ->name('schedule.calendar');
        Route::post('/schedule/check-conflicts', [DefenseScheduleController::class, 'checkConflicts'])
            ->name('schedule.check-conflicts');
        Route::get('/schedule/available-panelists', [DefenseScheduleController::class, 'availablePanelists'])
            ->name('schedule.available-panelists');

        Route::get('/defense-requests/all', [CoordinatorDefenseController::class, 'allDefenseRequests'])
            ->name('defense-requests.all');

        Route::post('/defense-requests/{defenseRequest}/panels', [CoordinatorDefenseController::class, 'assignPanelsJson'])
            ->name('defense.panels.json');
        Route::post('/defense-requests/{defenseRequest}/schedule-json', [CoordinatorDefenseController::class, 'scheduleDefenseJson'])
            ->name('defense.schedule.json');
        Route::get('/defense-requests/{defenseRequest}/panel-availability', [CoordinatorDefenseController::class, 'panelAvailability'])->name('defense.panel-availability');
        Route::get('/panel-members', [CoordinatorDefenseController::class, 'panelMembersAll'])
            ->name('defense.panel-members');

        Route::get('/defense-requests/{defenseRequest}/details', function (DefenseRequest $defenseRequest) {
            $user = Auth::user();
            $roles = ['Coordinator', 'Administrative Assistant', 'Dean'];
            if (! $user || ! in_array($user->role, $roles)) {
                abort(403);
            }

            if ($user->role === 'Coordinator') {
                abort_unless((int) $defenseRequest->coordinator_user_id === (int) $user->id, 403);
            }
            $adviser = $defenseRequest->adviserUser;
            $mapped = [
                'attachment_names' => $defenseRequest->attachment_names,
                'adviser_profile' => $adviser ? ['id' => $adviser->id, 'name' => $adviser->name, 'email' => $adviser->email] : null,
                'id' => $defenseRequest->id,
                'first_name' => $defenseRequest->first_name,
                'middle_name' => $defenseRequest->middle_name,
                'last_name' => $defenseRequest->last_name,
                'school_id' => $defenseRequest->school_id,
                'program' => $defenseRequest->program,
                'thesis_title' => $defenseRequest->thesis_title,
                'defense_type' => $defenseRequest->defense_type,
                'status' => $defenseRequest->status,
                'priority' => $defenseRequest->priority,
                'workflow_state' => $defenseRequest->workflow_state,
                'defense_adviser' => $defenseRequest->defense_adviser,
                'defense_chairperson' => $defenseRequest->defense_chairperson,
                'defense_panelist1' => $defenseRequest->defense_panelist1,
                'defense_panelist2' => $defenseRequest->defense_panelist2,
                'defense_panelist3' => $defenseRequest->defense_panelist3,
                'defense_panelist4' => $defenseRequest->defense_panelist4,
                'scheduled_date' => $defenseRequest->scheduled_date?->format('Y-m-d'),
                'scheduled_time' => $defenseRequest->scheduled_time,
                'scheduled_end_time' => $defenseRequest->scheduled_end_time,
                'defense_mode' => $defenseRequest->defense_mode,
                'defense_venue' => $defenseRequest->defense_venue,
                'scheduling_notes' => $defenseRequest->scheduling_notes,
                // --- ALL DOCUMENT FIELDS ---
                'advisers_endorsement' => $defenseRequest->advisers_endorsement,
                'rec_endorsement' => $defenseRequest->rec_endorsement,
                'proof_of_payment' => $defenseRequest->proof_of_payment,
                'reference_no' => $defenseRequest->reference_no,
                'manuscript_proposal' => $defenseRequest->manuscript_proposal,
                'similarity_index' => $defenseRequest->similarity_index,
                'avisee_adviser_attachment' => $defenseRequest->avisee_adviser_attachment,
                'ai_detection_certificate' => $defenseRequest->ai_detection_certificate,
                'endorsement_form' => $defenseRequest->endorsement_form,
                // --- END DOCUMENT FIELDS ---
                'last_status_updated_by' => $defenseRequest->last_status_updated_by,
                'last_status_updated_at' => $defenseRequest->last_status_updated_at,
                'workflow_history' => $defenseRequest->workflow_history ?? [],
                'correction_notes' => $defenseRequest->correction_notes,
                'correction_stage' => $defenseRequest->correction_stage,
                'correction_requested_by' => $defenseRequest->correction_requested_by,
                'correction_requested_at' => $defenseRequest->correction_requested_at,
                'correction_count' => $defenseRequest->correction_count,
                'adviser_status' => $defenseRequest->adviser_status ?? null,
                'coordinator_status' => $defenseRequest->coordinator_status ?? null,

                // ADD: exact level for rate matching
                'program_level' => ProgramLevel::getLevel($defenseRequest->program),
            ];

            return Inertia::render('coordinator/submissions/defense-request/details', [
                'defenseRequest' => $mapped,
                'userRole' => $user->role,
            ]);
        })->name('defense-requests.details');
    });

    // Profile and its legacy alias are registered in settings.php.

    // Add this route for the adviser students list page
    Route::get('/adviser/students-list', function () {
        return Inertia::render('adviser/students-list/Index');
    })->name('adviser.students-list');

    // Add this line if missing
    Route::get('/api/adviser/code', [AdviserStudentController::class, 'getAdviserCode']);

    Route::get('/coordinator/adviser-list', function () {
        return Inertia::render('coordinator/adviser-list/Index');
    })->name('coordinator.adviser-list');
});

/*
|--------------------------------------------------------------------------
| Auth (NOT necessarily verified) utility API
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])->group(function () {
    Route::get('/api/panel-members', [PanelistController::class, 'allCombined'])->name('api.panel-members');
    Route::get('/adviser/defense-requests', [DefenseRequestController::class, 'adviserQueue'])
        ->name('adviser.defense-requests');

    Route::get('/api/document-templates', [DocumentTemplateController::class, 'index']);
    Route::get('/api/document-templates/{template}', [DocumentTemplateController::class, 'show']);
    Route::post('/api/document-templates', [DocumentTemplateController::class, 'store']);
    Route::put('/api/document-templates/{template}/fields', [DocumentTemplateController::class, 'updateFields']);
    Route::delete('/api/document-templates/{template}', [DocumentTemplateController::class, 'destroy']);

    Route::get('/api/signatures', [UserSignatureController::class, 'index']);
    Route::get('/api/signatures/{signature}/image', [UserSignatureController::class, 'image'])->name('signatures.image');
    Route::post('/api/signatures', [UserSignatureController::class, 'store']);
    Route::patch('/api/signatures/{signature}/activate', [UserSignatureController::class, 'activate']);

    Route::get('/generated-documents/{doc}', [GeneratedDocumentController::class, 'show'])
        ->name('generated-documents.show');
});

/*
|--------------------------------------------------------------------------
| Public / Shared API
|--------------------------------------------------------------------------
*/
Route::get('/api/adviser-search', function (Illuminate\Http\Request $request) {
    $q = $request->input('q', '');

    return User::where(function ($query) use ($q) {
        $query->where('first_name', 'like', "%$q%")
            ->orWhere('last_name', 'like', "%$q%");
    })
        ->where(function ($query) {
            $query->where('role', 'Adviser')
                ->orWhereHas('roles', fn ($q) => $q->where('name', 'Adviser'));
        })
        ->limit(10)
        ->get(['id', 'first_name', 'middle_name', 'last_name']);
})->name('api.adviser-search');

// Keep existing bookmarks/clients working while new screens use adviser URLs.
Route::get('/api/faculty-search', fn (Illuminate\Http\Request $request) => redirect()->route('api.adviser-search', $request->query())
)->name('api.faculty-search');

Route::get('/api/coordinator/defense-requests', function () {
    $user = Auth::user();
    $roles = ['Coordinator', 'Administrative Assistant', 'Dean'];
    if (! $user || ! in_array($user->role, $roles)) {
        abort(403);
    }

    $records = DefenseRequest::query()
        ->when($user->role === 'Coordinator', fn ($query) => $query->where('coordinator_user_id', $user->id))
        ->whereIn('workflow_state', [
            'adviser-approved',
            'coordinator-review',
            'coordinator-approved',
            'coordinator-rejected',
            'panels-assigned',
            'scheduled',
        ])
        ->orderBy('adviser_reviewed_at', 'desc')
        ->get();

    return $records->map(fn ($r) => [
        'id' => $r->id,
        'thesis_title' => $r->thesis_title,
        'workflow_state' => $r->workflow_state,
        'status' => $r->status,
    ]);
})->name('api.coordinator.defense-requests');

/*
|--------------------------------------------------------------------------
| Legacy / Extra
|--------------------------------------------------------------------------
*/
Route::get('/legacy/academic-records', [AcademicRecordController::class, 'index'])
    ->name('legacy.academic-records.index');
Route::get('/legacy/academic-records/dashboard', fn () => Inertia::render('legacy/AcademicRecordsDashboard'))
    ->name('legacy.academic-records.dashboard');
Route::get('/legacy/link', [LegacyLinkController::class, 'form'])->name('legacy.link.form');
Route::post('/legacy/link', [LegacyLinkController::class, 'link'])->name('legacy.link.submit');
Route::delete('/legacy/link', [LegacyLinkController::class, 'unlink'])->name('legacy.link.unlink');
Route::get('/adviser/class-list', [InstructorClassListController::class, 'page'])
    ->name('adviser.class-list.page');
Route::get('/legacy/adviser/class-list', [InstructorClassListController::class, 'index'])
    ->name('legacy.adviser.class-list');
Route::redirect('/faculty/class-list', '/adviser/class-list')->name('faculty.class-list.page');
Route::get('/legacy/faculty/class-list', [InstructorClassListController::class, 'index'])
    ->name('legacy.faculty.class-list');

// Temporary test route for comprehensive exam eligibility
Route::get('/test-eligibility', [ComprehensiveExamEligibilityController::class, 'checkEligibility'])
    ->middleware('auth');

// Debug route to check academic records data
Route::get('/debug-academic-records', [DebugController::class, 'academicRecordsDebug'])
    ->middleware('auth');

/*
|--------------------------------------------------------------------------
| Include default auth scaffolding (login, password, etc.)
|--------------------------------------------------------------------------
*/
if (file_exists(__DIR__.'/auth.php')) {
    require __DIR__.'/auth.php';
}
if (file_exists(__DIR__.'/settings.php')) {
    require __DIR__.'/settings.php';
}

Route::middleware(['auth'])->group(function () {
    Route::get('/adviser/students/{student}/progress', [AdviserStudentProgressController::class, 'show'])->name('adviser.student-progress');
    Route::put('/adviser/students/{student}/progress', [AdviserStudentProgressController::class, 'update'])->name('adviser.student-progress.update');
    Route::get('/api/adviser/students', [AdviserStudentController::class, 'index']);
    Route::post('/api/adviser/students', [AdviserStudentController::class, 'store']);
    Route::post('/api/adviser/register-with-code', [AdviserStudentController::class, 'registerWithCode']);
    Route::get('/api/adviser/code', [AdviserStudentController::class, 'getAdviserCode']);
    Route::delete('/api/adviser/students/{student}', [AdviserStudentController::class, 'destroy']);

    Route::get('/api/advisers', [CoordinatorAdviserController::class, 'all'])->name('api.advisers');
    Route::middleware(['auth'])->get('/api/adviser/has-students', [AdviserStudentController::class, 'hasStudents']);

    Route::get('/api/panelists', [PanelistController::class, 'index'])->name('api.panelists');

    Route::get('/api/adviser/pending-students', [AdviserStudentController::class, 'pending']);
    Route::post('/api/adviser/pending-students/{student}/accept', [AdviserStudentController::class, 'acceptPending']);
    Route::post('/api/adviser/pending-students/{student}/reject', [AdviserStudentController::class, 'rejectPending']);

    // Student search for autocomplete
    Route::get('/api/students/search', [StudentSearchController::class, 'search']);

    // Comprehensive exam eligibility API
    Route::get('/api/comprehensive-exam/eligibility', [ComprehensiveExamEligibilityController::class, 'checkEligibility']);

    // Manual data scraping endpoint for testing
    Route::post('/api/comprehensive-exam/scrape-data', [ComprehensiveExamEligibilityController::class, 'manualDataScraping']);

});

// Adviser view: Defense Requirement Details (for /adviser/defense-requirements/{id}/details)
Route::get('/adviser/defense-requirements/{id}/details', function ($id) {
    $user = Auth::user();
    if (! $user || ! in_array($user->role, ['Adviser'])) {
        abort(403);
    }

    $defenseRequest = DefenseRequest::findOrFail($id);
    abort_unless((int) $defenseRequest->adviser_user_id === (int) $user->id, 403);

    // Fetch all coordinators linked to this adviser
    $coordinators = $user->coordinators()
        ->select('users.id', 'users.first_name', 'users.middle_name', 'users.last_name', 'users.email')
        ->get()
        ->map(function ($c) {
            return [
                'id' => $c->id,
                'name' => trim($c->first_name.' '.($c->middle_name ? strtoupper($c->middle_name[0]).'. ' : '').$c->last_name),
                'email' => $c->email,
            ];
        })
        ->values()
        ->all();

    return Inertia::render('adviser/defense-requirements/details-requirements', [
        'defenseRequest' => $defenseRequest,
        'userRole' => $user->role,
        'coordinators' => $coordinators, // <-- pass as prop
    ]);
})->name('adviser.defense-requirements.details');

/* Adviser: Upload documents for defense requirements */
Route::post('/adviser/defense-requirements/{defenseRequest}/documents', [DefenseRequestController::class, 'uploadDocuments'])
    ->name('adviser.defense-requirements.upload-documents');

Route::post('/adviser/defense-requirements/{defenseRequest}/endorsement-form', [DefenseRequestController::class, 'attachEndorsement']);

/* Document Generation API */
Route::post('/api/generate-document', [GeneratedDocumentController::class, 'generateDocument']);

/* Update Adviser Status for Defense Requirements */
Route::patch('/adviser/defense-requirements/{defenseRequest}/adviser-status', [DefenseRequestController::class, 'updateAdviserStatus'])
    ->name('adviser.defense-requirements.update-adviser-status');

Route::get('/assistant/all-defense-list', function () {
    // You can add authorization here if you want only AA/Dean to access
    if (! in_array(Auth::user()->role, ['Administrative Assistant', 'Dean'])) {
        abort(403);
    }

    return Inertia::render('assistant/all-defense-list/Index');
})->name('assistant.all-defense-list');

Route::get('/coordinator/defense-requests', function () {
    $user = Auth::user();
    if (! $user || $user->role !== 'Coordinator') {
        abort(403);
    }

    return Inertia::render('coordinator/submissions/defense-request/Index');
})->name('coordinator.defense-requests');

Route::get('/assistant/all-defense-list/{id}/details', function ($id) {
    $user = Auth::user();
    if (! $user || ! in_array($user->role, ['Administrative Assistant', 'Dean'])) {
        abort(403);
    }
    $defenseRequest = DefenseRequest::findOrFail($id);

    // Get program level using helper
    $programLevel = ProgramLevel::getLevel($defenseRequest->program);

    // Sum all rates for this program level and defense type
    $expectedTotal = PaymentRate::where('program_level', $programLevel)
        ->where('defense_type', $defenseRequest->defense_type)
        ->sum('amount');

    // Define vars to avoid "unassigned variable" errors
    $coordinator = null;
    $aa_verification_status = null;
    $aa_verification_id = null;

    // Compose panelists list (same as before)
    $panelistFields = [
        $defenseRequest->defense_chairperson,
        $defenseRequest->defense_panelist1,
        $defenseRequest->defense_panelist2,
        $defenseRequest->defense_panelist3,
        $defenseRequest->defense_panelist4,
    ];
    $panelists = collect($panelistFields)
        ->filter()
        ->map(function ($panelistIdOrName) {
            if (is_numeric($panelistIdOrName)) {
                $p = Panelist::find($panelistIdOrName);
                if ($p) {
                    return ['id' => $p->id, 'name' => $p->name];
                }
            }

            return ['id' => null, 'name' => $panelistIdOrName];
        })->values()->all();

    return Inertia::render('assistant/all-defense-list/details', [
        'defenseRequest' => [
            'id' => $defenseRequest->id,
            'first_name' => $defenseRequest->first_name,
            'middle_name' => $defenseRequest->middle_name,
            'last_name' => $defenseRequest->last_name,
            'school_id' => $defenseRequest->school_id,
            'program' => $defenseRequest->program,
            'thesis_title' => $defenseRequest->thesis_title,
            'defense_type' => $defenseRequest->defense_type,
            'priority' => $defenseRequest->priority,
            'workflow_state' => $defenseRequest->workflow_state,
            'status' => $defenseRequest->status,
            'scheduled_date' => $defenseRequest->scheduled_date?->format('Y-m-d'),
            'date_of_defense' => $defenseRequest->scheduled_date
                ? $defenseRequest->scheduled_date->format('Y-m-d')
                : ($defenseRequest->created_at ? $defenseRequest->created_at->format('Y-m-d') : null),
            'defense_mode' => $defenseRequest->defense_mode,
            'mode_defense' => $defenseRequest->defense_mode,
            'adviser' => $defenseRequest->defense_adviser ?? '—',
            'submitted_at' => $defenseRequest->submitted_at ? Carbon::parse($defenseRequest->submitted_at)->format('Y-m-d H:i:s') : null,
            'coordinator_status' => $defenseRequest->coordinator_status,
            'expected_rate' => $expectedTotal,
            'amount' => $defenseRequest->amount ?? null,
            'reference_no' => $defenseRequest->reference_no ?? null,
            'coordinator' => $coordinator,
            'aa_verification_status' => $aa_verification_status,
            'aa_verification_id' => $aa_verification_id,
        ],
    ]);
});

Route::middleware(['auth'])->get('/api/adviser/coordinators', function (Request $request) {
    $user = $request->user();
    $coordinators = $user->coordinators()
        ->select('first_name', 'middle_name', 'last_name', 'email')
        ->get()
        ->map(function ($c) {
            return [
                'name' => trim($c->first_name.' '.($c->middle_name ? strtoupper($c->middle_name[0]).'. ' : '').$c->last_name),
                'email' => $c->email,
            ];
        });

    return response()->json(['coordinators' => $coordinators]);
});

Route::middleware(['auth'])->get('/api/adviser/registered-coordinator', [CoordinatorAdviserController::class, 'getRegisteredCoordinator']);

/*
|--------------------------------------------------------------------------
| Student Documents
|--------------------------------------------------------------------------
*/
Route::get('/student/documents', [StudentDocumentController::class, 'index'])
    ->name('student.documents');

Route::put('/test-put', function () {
    return response()->json(['ok' => true]);
});

Route::get('/assistant/all-defense-list/data', function () {
    $user = Auth::user();
    if (! $user || ! in_array($user->role, ['Administrative Assistant', 'Dean'])) {
        abort(403);
    }

    $rows = DefenseRequest::query()
        ->where('coordinator_status', 'Approved')
        ->orderByDesc('created_at')
        ->limit(500)
        ->get([
            'id',
            'first_name',
            'middle_name',
            'last_name',
            'school_id',
            'program',
            'thesis_title',
            'defense_type',
            'status',
            'priority',
            'workflow_state',
            'scheduled_date',
            'defense_mode',
            'defense_venue',
            'panels_assigned_at',
            'defense_adviser',
            'submitted_at',
            'coordinator_status',
            'amount',
            'reference_no',
            'coordinator_user_id',
        ])
        ->map(function ($r) {
            $expectedTotal = 0;
            if ($r->program && $r->defense_type) {
                $programLevel = ProgramLevel::getLevel($r->program);
                $expectedTotal = PaymentRate::where('program_level', $programLevel)
                    ->where('defense_type', $r->defense_type)
                    ->sum('amount');
            }

            $coordinator = null;
            if ($r->coordinator_user_id) {
                $coordUser = User::find($r->coordinator_user_id);
                if ($coordUser) {
                    $coordinator = trim(
                        $coordUser->first_name.' '.
                        ($coordUser->middle_name ? strtoupper($coordUser->middle_name[0]).'. ' : '').
                        $coordUser->last_name
                    );
                }
            }

            $aaVerification = AaPaymentVerification::where('defense_request_id', $r->id)->first();
            $aa_verification_status = $aaVerification ? $aaVerification->status : null;
            $aa_verification_id = $aaVerification ? $aaVerification->id : null; // <-- ADD THIS

            return [
                'id' => $r->id,
                'first_name' => $r->first_name,
                'middle_name' => $r->middle_name,
                'last_name' => $r->last_name,
                'program' => $r->program,
                'thesis_title' => $r->thesis_title,
                'defense_type' => $r->defense_type,
                'priority' => $r->priority,
                'workflow_state' => $r->workflow_state,
                'status' => $r->status,
                'scheduled_date' => $r->scheduled_date?->format('Y-m-d'),
                'date_of_defense' => $r->scheduled_date
                    ? $r->scheduled_date->format('Y-m-d')
                    : ($r->created_at ? $r->created_at->format('Y-m-d') : null),
                'defense_mode' => $r->defense_mode,
                'mode_defense' => $r->defense_mode,
                'adviser' => $r->defense_adviser ?? '—',
                'submitted_at' => $r->submitted_at ? Carbon::parse($r->submitted_at)->format('Y-m-d H:i:s') : null,
                'coordinator_status' => $r->coordinator_status,
                'expected_rate' => $expectedTotal,
                'amount' => $r->amount ?? null,
                'reference_no' => $r->reference_no ?? null,
                'coordinator' => $coordinator,
                'aa_verification_status' => $aa_verification_status,
                'aa_verification_id' => $aa_verification_id, // <-- ADD THIS
            ];
        });

    return response()->json($rows->values());
});

// Test route to preview adviser invitation email
Route::get('/test-adviser-invitation', function () {
    return new AdviserInvitation(
        'Dr. Juan Dela Cruz',
        'Dr. Maria Santos (Coordinator)'
    );
})->middleware('auth');
