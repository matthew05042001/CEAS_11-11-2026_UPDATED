<?php

namespace App\Http\Controllers;

use App\Models\Adviser;
use App\Models\ComprehensiveExamSchedule;
use App\Models\CoordinatorProgramAssignment;
use App\Models\DefenseRequest;
use App\Models\DefenseRequirement;
use App\Models\ExamApplication;
use App\Models\HonorariumDisbursement;
use App\Models\Panelist;
use App\Models\PaymentSubmission;
use App\Models\StudentResponse;
use App\Models\User;
use App\Services\CoordinatorProgramService;
use App\Services\StudentProfileEnricher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        if (! $user) {
            abort(401);
        }

        if (in_array(strtolower(trim((string) $user->role)), ['finance', 'finance office', 'finance officer'], true)) {
            return redirect()->route('honorarium-workspace.index');
        }

        if ($user->role === 'Proctor') {
            $assignedSchedules = ComprehensiveExamSchedule::query()
                ->whereHas('proctorUsers', fn ($query) => $query->where('users.id', $user->id))
                ->with('proctorUsers:id,first_name,last_name')
                ->latest('exam_date')
                ->latest('exam_time')
                ->paginate(25)
                ->through(fn (ComprehensiveExamSchedule $schedule) => [
                    'id' => $schedule->id,
                    'program' => $schedule->program,
                    'exam_date' => $schedule->exam_date?->format('Y-m-d'),
                    'exam_time' => $schedule->exam_time,
                    'venue' => $schedule->venue,
                    'teacher_assigned' => $schedule->teacher_assigned,
                    'other_proctors' => $schedule->proctorUsers
                        ->reject(fn (User $proctor) => $proctor->id === $user->id)
                        ->map(fn (User $proctor) => $proctor->display_name)
                        ->values(),
                ]);

            return Inertia::render('dashboard/Index', [
                'auth' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->display_name,
                        'role' => 'Proctor',
                        'effective_role' => 'Proctor',
                    ],
                ],
                'assignedSchedules' => $assignedSchedules,
            ]);
        }

        if ($user->role === 'Registrar') {
            return Inertia::render('dashboard/Index', [
                'auth' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->display_name,
                        'role' => 'Registrar',
                        'effective_role' => 'Registrar',
                    ],
                ],
                'registrarApplications' => ExamApplication::query()
                    ->with('subjects')
                    ->where('registrar_approval_status', 'pending')
                    ->latest('application_id')
                    ->paginate(25, ['*'], 'registrar_page')
                    ->withQueryString()
                    ->through(function (ExamApplication $application) {
                        $studentId = (string) $application->student_id;
                        $student = User::query()
                            ->where('school_id', $studentId)
                            ->when(ctype_digit($studentId), fn ($query) => $query->orWhereKey((int) $studentId))
                            ->first();
                        $studentResponse = $student
                            ? StudentResponse::query()->where('user_id', $student->id)->latest('id')->first()
                            : null;

                        return [
                            'application_id' => $application->application_id,
                            'student_name' => $student?->display_name ?? 'Student account not found',
                            'student_email' => $student?->email,
                            'student_id' => $student?->school_id ?? $application->student_id,
                            'program' => $application->program,
                            'school_year' => $application->school_year,
                            'created_at' => $application->created_at,
                            'aa_evaluated_at' => $studentResponse?->evaluated_at,
                            'subjects' => $application->subjects->map(fn ($subject) => [
                                'name' => $subject->subject_name,
                                'date' => $subject->exam_date ? substr((string) $subject->exam_date, 0, 10) : null,
                                'start_time' => $subject->start_time,
                                'end_time' => $subject->end_time,
                            ])->values(),
                        ];
                    }),
                'evaluatedResponses' => StudentResponse::query()
                    ->whereNotNull('evaluated_at')
                    ->latest('evaluated_at')
                    ->paginate(25, ['*'], 'response_page')
                    ->withQueryString(),
            ]);
        }

        // DATA SCRAPING REMOVED FROM LOGIN FLOW
        // Data will be scraped on-demand when user visits comprehensive exam page
        // This prevents 2-minute login delays
        if (session('trigger_data_scraping')) {
            session()->forget('trigger_data_scraping'); // Clear flag

            Log::info('Dashboard: Data scraping will happen on-demand (not on login)', [
                'user_id' => $user->id,
            ]);
        }

        // TRIGGER BACKGROUND PROFILE ENRICHMENT IF FLAGGED
        $enrichmentData = Cache::get('pending_enrichment_'.$user->id);
        if ($enrichmentData) {
            Cache::forget('pending_enrichment_'.$user->id); // Clear flag

            try {
                Log::info('Dashboard: Triggering background profile enrichment', [
                    'user_id' => $user->id,
                    'is_staff' => $enrichmentData['is_staff'] ?? false,
                ]);

                $legacySession = Cache::get('legacy_session_'.$user->id);

                if ($legacySession) {
                    // Dispatch enrichment to background
                    dispatch(function () use ($user, $legacySession, $enrichmentData) {
                        try {
                            $enricher = app(StudentProfileEnricher::class);

                            Log::info('Background Job: Starting profile enrichment', [
                                'user_id' => $user->id,
                            ]);

                            $enricher->enrich($user, $legacySession, $enrichmentData['is_staff'] ?? false);

                            // After enrichment, update email to UIC format if it's a student
                            $user->refresh();
                            if ($user->role === 'Student' && $user->first_name && $user->last_name && $user->school_id) {
                                $uicEmail = $user->generateUicEmail();

                                if ($uicEmail !== $user->email) {
                                    $existingUser = User::where('email', $uicEmail)
                                        ->where('id', '!=', $user->id)
                                        ->first();

                                    if (! $existingUser) {
                                        $user->update(['email' => $uicEmail]);

                                        Log::info('Background Job: Updated email to UIC format', [
                                            'user_id' => $user->id,
                                            'uic_email' => $uicEmail,
                                        ]);
                                    }
                                }
                            }

                            Log::info('Background Job: Profile enrichment completed', [
                                'user_id' => $user->id,
                                'name' => $user->fresh()->display_name,
                            ]);
                        } catch (\Throwable $e) {
                            Log::error('Background Job: Enrichment failed', [
                                'user_id' => $user->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    })->afterResponse();
                }
            } catch (\Throwable $e) {
                Log::error('Dashboard: Failed to trigger enrichment', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $roleName = is_object($user->role) ? $user->role->name : $user->role;
        if (in_array($roleName, ['Adviser']) && method_exists($user, 'generateAdviserCode') && ! $user->adviser_code) {
            $user->generateAdviserCode();
        }
        if (in_array($roleName, ['Coordinator']) && ! $user->coordinator_code) {
            $user->generateCoordinatorCode();
        }

        // --- Add this block ---
        $studentsCount = 0;
        if (in_array($roleName, ['Adviser']) && method_exists($user, 'advisedStudents')) {
            $studentsCount = $user->advisedStudents()->count();
        }
        // --- End block ---

        // --- compute effective role (from _dev_develop) ---
        $effective = $user->role;
        if (! $effective) {
            // if using a roles package / pivot, try to derive a priority-based effective role
            if (method_exists($user, 'allRoleNames')) {
                $all = $user->allRoleNames();
                $priority = ['Coordinator', 'Dean', 'Chair', 'Adviser', 'Student'];
                foreach ($priority as $p) {
                    if (in_array($p, $all, true)) {
                        $effective = $p;
                        break;
                    }
                }
                if (! $effective && ! empty($all)) {
                    $effective = $all[0];
                }
            }
        }

        // --- student-specific defense requirement / request lookup (from develop) ---
        $latestRequirement = null;
        $defenseRequests = collect();
        $defenseRequest = null;

        try {
            // latest defense requirement for the student (if your model uses user_id)
            if (class_exists(DefenseRequirement::class)) {
                $latestRequirement = DefenseRequirement::where('user_id', $user->id)
                    ->orderByDesc('created_at')
                    ->first();
            }

            // get all defense requests that match the student's identity (school_id + name)
            if (class_exists(DefenseRequest::class)) {
                $defenseRequests = DefenseRequest::where('school_id', $user->school_id)
                    ->where('first_name', $user->first_name)
                    ->where('last_name', $user->last_name)
                    ->orderByDesc('date_of_defense')
                    ->get();
            }

            // find a related defense request using thesis_title from the latest requirement (if present)
            if ($latestRequirement && class_exists(DefenseRequest::class)) {
                $defenseRequest = DefenseRequest::where('thesis_title', $latestRequirement->thesis_title)
                    ->where('school_id', $user->school_id)
                    ->where('first_name', $user->first_name)
                    ->where('last_name', $user->last_name)
                    ->latest()
                    ->first();
            }

            if (in_array($roleName, ['Coordinator', 'Administrative Assistant', 'Dean'])) {
                // Send ALL defense requests for coordinator
                $defenseRequests = DefenseRequest::orderByDesc('created_at')->get();
            }
        } catch (\Throwable $e) {
            // don't crash the dashboard for missing models/columns — log if you want
            Log::debug('Dashboard student-defense lookup failed: '.$e->getMessage());
            // keep defaults (null / empty collection)
        }

        // --- prepare the Inertia payload (combines both branches) ---
        $props = [
            'auth' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->display_name, // Use the new display_name attribute
                    'first_name' => $user->first_name,
                    'middle_name' => $user->middle_name,
                    'last_name' => $user->last_name,
                    'email' => $user->email,
                    'role' => $effective,
                    'school_id' => $user->school_id,
                    'avatar' => $user->employee_photo_url ?? null,
                    'pendingAdvisers' => $user->advisers()->wherePivot('status', 'pending')->get(['users.id', 'users.first_name', 'users.last_name', 'users.email']),
                    // Show only accepted advisers
                    'advisers' => method_exists($user, 'advisers')
                        ? $user->advisers()
                            ->wherePivot('status', 'accepted')
                            ->select('users.id', 'users.first_name', 'users.middle_name', 'users.last_name', 'users.email', 'users.adviser_code')
                            ->get()
                        : collect(),
                ],
            ],
            'defenseRequirement' => $latestRequirement,
            'defenseRequest' => $defenseRequest,
            'defenseRequests' => $defenseRequests,
            'studentsCount' => $studentsCount,
        ];

        if (in_array($effective, ['Coordinator', 'Administrative Assistant', 'Dean'], true)) {
            $honorariums = HonorariumDisbursement::whereIn('status', ['prepared', 'approved']);
            if ($effective === 'Coordinator') {
                $honorariums->whereHas('defenseRequest', fn ($q) => $q->where('coordinator_user_id', $user->id));
            }
            $props['stats'] = ['pending_honorariums' => $honorariums->count()];
            if ($effective === 'Administrative Assistant') {
                $props['stats'] += [
                    'todays_defenses' => DefenseRequest::whereDate('scheduled_date', today())
                        ->whereIn('workflow_state', ['scheduled', 'completed'])->count(),
                    'pending_applications' => ExamApplication::where('final_approval_status', 'pending')->count(),
                    'pending_payments' => PaymentSubmission::where('status', 'pending')->count(),
                    'pending_defense_requests' => DefenseRequest::where('coordinator_status', 'Approved')
                        ->whereNotIn('workflow_state', ['completed', 'cancelled'])->count(),
                    'upcoming_deadlines' => 0,
                ];
            }
        }
        if ($effective === 'Student') {
            $examPayment = PaymentSubmission::where('student_id', $user->id)->latest('payment_id')->first();
            $defensePayment = DefenseRequest::where('submitted_by', $user->id)
                ->whereNotNull('proof_of_payment')->latest()->first();
            $examIsLatest = $examPayment && (! $defensePayment || $examPayment->created_at >= $defensePayment->created_at);
            $props['recentPayment'] = ['amount' => (float) ($examIsLatest ? $examPayment->amount_paid : ($defensePayment?->amount ?? 0))];
        }

        if ($effective === 'Dean') {
            $props['graduateStaff'] = $this->getGraduateStaff();
        }

        // --- Coordinator specific data ---
        if ($effective === 'Coordinator') {
            $props['coordinatorStudents'] = $this->getCoordinatorStudents($user->email);
            $props['coordinatorPrograms'] = CoordinatorProgramService::getProgramsByEmail($user->email);

            // Add this to include all panelists
            $props['panelists'] = Panelist::all(['id', 'name', 'email']);
        }

        // --- Super Admin specific data ---
        if ($effective === 'Super Admin') {
            $props['allUsers'] = $this->getAllUsers();
            $props['programs'] = $this->getPrograms();
            $props['coordinators'] = $this->getCoordinators();
            $props['stats'] = $this->getSuperAdminStats();
            $props['coordinatorAssignments'] = $this->getCoordinatorAssignments();
        }

        return Inertia::render('dashboard/Index', $props);
    }

    private function getGraduateStaff()
    {
        $staff = User::whereIn('role', ['Coordinator', 'Administrative Assistant'])
            ->with('coordinatorPrograms')->orderBy('last_name')->orderBy('first_name')->get();
        $ids = $staff->pluck('id');
        $assignments = CoordinatorProgramAssignment::whereIn('coordinator_user_id', $ids)
            ->where('is_active', true)->get()->groupBy('coordinator_user_id');
        $advisers = Adviser::whereIn('coordinator_id', $ids)->get()->groupBy('coordinator_id');
        $students = DB::table('adviser_student')->whereIn('requested_by', $ids)->where('status', 'accepted')
            ->select('requested_by', DB::raw('count(distinct student_id) as total'))->groupBy('requested_by')->pluck('total', 'requested_by');

        return $staff->map(function ($person) use ($assignments, $advisers, $students) {
            $programs = collect($assignments->get($person->id, collect())->pluck('program_name'))
                ->merge($person->coordinatorPrograms->pluck('program'))->filter()->unique()->values();
            if ($programs->isEmpty() && $person->role === 'Coordinator') {
                $programs = collect(CoordinatorProgramService::getProgramsByEmail($person->email));
            }

            return [
                'id' => $person->id, 'name' => $person->display_name, 'email' => $person->email,
                'role' => $person->role, 'programs' => $programs->all(),
                'students' => $person->role === 'Coordinator' ? (int) $students->get($person->id, 0) : null,
                'advisers' => $person->role === 'Coordinator' ? $advisers->get($person->id, collect())->pluck('email')->map(fn ($email) => strtolower($email))->unique()->count() : null,
            ];
        })->values();
    }

    /**
     * Get all users for Super Admin management
     */
    private function getAllUsers()
    {
        try {
            return User::select('id', 'first_name', 'last_name', 'email', 'role', 'school_id', 'program', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(100) // Limit to prevent slow queries
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => trim($user->first_name.' '.$user->last_name),
                        'email' => $user->email,
                        'role' => $user->role,
                        'school_id' => $user->school_id,
                        'program' => $user->program,
                        'created_at' => $user->created_at ? $user->created_at->format('M d, Y') : 'Unknown',
                    ];
                });
        } catch (\Exception $e) {
            return collect();
        }
    }

    /**
     * Get programs with detailed coordinator assignments using CoordinatorProgramService
     */
    private function getPrograms()
    {
        try {
            // Cache student counts to avoid N queries
            $studentCounts = Cache::remember('dashboard_student_counts', 300, function () {
                return User::where('role', 'Student')
                    ->whereNotNull('program')
                    ->select('program', DB::raw('count(*) as total'))
                    ->groupBy('program')
                    ->pluck('total', 'program')
                    ->toArray();
            });

            $programs = collect([]);

            // Get coordinators from database
            $coordinators = User::where('role', 'Coordinator')
                ->select('id', 'first_name', 'last_name', 'email', 'created_at')
                ->get();

            foreach ($coordinators as $coordinator) {
                // Get programs from the service
                $assignedPrograms = CoordinatorProgramService::getProgramsByEmail($coordinator->email);

                foreach ($assignedPrograms as $index => $programName) {
                    // Get count from cached data
                    $studentCount = $studentCounts[$programName] ?? 0;

                    $programs->push([
                        'id' => 'prog_'.$coordinator->id.'_'.$index,
                        'name' => $programName,
                        'code' => CoordinatorProgramService::generateProgramCode($programName),
                        'coordinator_id' => $coordinator->id,
                        'coordinator_name' => trim($coordinator->first_name.' '.$coordinator->last_name),
                        'coordinator_email' => $coordinator->email,
                        'status' => 'active',
                        'students_count' => $studentCount, // Use actual count only
                        'created_at' => $coordinator->created_at ? $coordinator->created_at->format('M d, Y') : 'Unknown',
                    ]);
                }
            }

            return $programs->sortBy('name')->values();
        } catch (\Exception $e) {
            return collect();
        }
    }

    /**
     * Generate a program code from program name
     */
    private function generateProgramCode($programName)
    {
        // Extract acronyms or create codes from program names
        $name = strtoupper(trim($programName));

        // Handle specific program patterns with their standard codes
        $codes = [
            'DOCTOR IN BUSINESS MANAGEMENT' => 'DBM',
            'MASTER IN BUSINESS ADMINISTRATION' => 'MBA',
            'DOCTOR OF PHILOSOPHY IN COUNSELING' => 'PhD-COUN',
            'DOCTOR OF PHILOSOPHY IN PHYSICAL EDUCATION' => 'PhD-PE',
            'DOCTOR OF PHILOSOPHY IN MATHEMATICS' => 'PhD-MATH',
            'MASTER OF ARTS IN EDUCATION MAJOR IN PHYSICAL EDUCATION' => 'MAED-PE',
            'MASTER IN COUNSELING' => 'MIC',
            'MASTER OF ARTS IN COUNSELING' => 'MAC',
            'DOCTOR OF PHILOSOPHY IN PHARMACY' => 'PhD-PHARM',
            'MASTER OF SCIENCE IN PHARMACY' => 'MS-PHARM',
            'MASTER OF SCIENCE IN MEDICAL TECHNOLOGY' => 'MS-MEDTECH',
            'DOCTOR OF PHILOSOPHY IN EDUCATION MAJOR IN APPLIED LINGUISTICS' => 'PhDAL',
            'DOCTOR OF PHILOSOPHY IN EDUCATION MAJOR IN EDUCATIONAL LEADERSHIP' => 'PhDEL',
            'DOCTOR OF PHILOSOPHY IN EDUCATION MAJOR IN FILIPINO' => 'PhD-FIL',
            'MASTER OF ARTS IN EDUCATION MAJOR IN ENGLISH' => 'MAED-ENG',
            'MASTER OF ARTS IN EDUCATION MAJOR IN FILIPINO' => 'MAED-FIL',
            'MASTER OF ARTS IN EDUCATION MAJOR IN MUSIC EDUCATION' => 'MAED-MUS',
            'MASTER OF ARTS IN TEACHING COLLEGE CHEMISTRY' => 'MATC',
            'MASTER OF ARTS IN TEACHING COLLEGE PHYSICS' => 'MATP',
            'MASTER OF ARTS IN ENGINEERING EDUCATION MAJOR IN CIVIL ENGINEERING' => 'MAEE-CE',
            'MASTER OF ARTS IN ENGINEERING EDUCATION MAJOR IN ELECTRONICS AND COMMUNICATIONS ENGINEERING' => 'MAEE-ECE',
            'DOCTOR IN BUSINESS MANAGEMENT MAJOR IN INFORMATION SYSTEMS' => 'DBM-IS',
            'DOCTOR OF PHILOSOPHY IN INFORMATION TECHNOLOGY INTEGRATION' => 'PhDITI',
            'MASTER OF ARTS IN EDUCATION MAJOR IN INFORMATION TECHNOLOGY INTEGRATION' => 'MAED-ITI',
            'MASTER IN INFORMATION SYSTEMS' => 'MIS',
            'MASTER IN INFORMATION TECHNOLOGY' => 'MIT',
            'MASTER OF ARTS IN EDUCATION MAJOR IN MATHEMATICS' => 'MAED-MATH',
            'MASTER OF ARTS IN EDUCATION MAJOR IN SOCIOLOGY' => 'MAED-SOC',
            'MASTER OF ARTS IN EDUCATION MAJOR IN RELIGIOUS EDUCATION' => 'MAED-REL',
            'MASTER OF ARTS IN EDUCATION MAJOR IN VALUES EDUCATION' => 'MAED-VAL',
            'MASTER OF ARTS IN EDUCATIONAL MANAGEMENT' => 'MA-EDMAN',
            'MASTER OF ARTS IN ELEMENTARY EDUCATION' => 'MA-ELEM',
        ];

        // Check for exact matches first
        foreach ($codes as $pattern => $code) {
            if (str_contains($name, $pattern)) {
                return $code;
            }
        }

        // Check for partial matches with common keywords
        if (str_contains($name, 'DBM')) {
            return 'DBM';
        }
        if (str_contains($name, 'MBA')) {
            return 'MBA';
        }
        if (str_contains($name, 'PHD')) {
            return 'PhD';
        }
        if (str_contains($name, 'MAED')) {
            return 'MAED';
        }
        if (str_contains($name, 'MATC')) {
            return 'MATC';
        }
        if (str_contains($name, 'MATP')) {
            return 'MATP';
        }
        if (str_contains($name, 'MIT')) {
            return 'MIT';
        }
        if (str_contains($name, 'MIS')) {
            return 'MIS';
        }

        // Fallback: create acronym from first letters of major words
        $words = explode(' ', $name);
        $importantWords = array_filter($words, function ($word) {
            return ! in_array($word, ['IN', 'OF', 'THE', 'AND', 'FOR', 'WITH', 'MAJOR']);
        });

        return strtoupper(substr(implode('', array_map(fn ($w) => substr($w, 0, 1), array_slice($importantWords, 0, 4))), 0, 6));
    }

    /**
     * Get coordinators for program assignment
     */
    private function getCoordinators()
    {
        try {
            return User::where('role', 'Coordinator')
                ->select('id', 'first_name', 'last_name', 'email')
                ->get()
                ->map(function ($coordinator) {
                    return [
                        'id' => $coordinator->id,
                        'name' => trim($coordinator->first_name.' '.$coordinator->last_name),
                        'email' => $coordinator->email,
                    ];
                });
        } catch (\Exception $e) {
            return collect();
        }
    }

    /**
     * Get Super Admin statistics
     */
    private function getSuperAdminStats()
    {
        try {
            $totalUsers = User::count();
            $totalPrograms = User::where('role', 'Coordinator')->count();
            $pendingRequests = 0;

            // Try to get pending defense requests if the model exists
            if (class_exists(DefenseRequest::class)) {
                $pendingRequests = DefenseRequest::whereIn('workflow_state', ['submitted', 'pending', 'adviser-pending'])->count();
            }

            return [
                'total_users' => $totalUsers,
                'total_programs' => $totalPrograms,
                'pending_requests' => $pendingRequests,
            ];
        } catch (\Exception $e) {
            return [
                'total_users' => 0,
                'active_sessions' => 0,
                'total_programs' => 0,
                'pending_requests' => 0,
            ];
        }
    }

    /**
     * Get students assigned to a specific coordinator's programs
     */
    private function getCoordinatorStudents(string $coordinatorEmail)
    {
        try {
            // Get programs assigned to this coordinator
            $assignedPrograms = CoordinatorProgramService::getProgramsByEmail($coordinatorEmail);

            if (empty($assignedPrograms)) {
                return collect();
            }

            // Get students enrolled in any of these programs
            $students = User::where('role', 'Student')
                ->whereIn('program', $assignedPrograms)
                ->select('id', 'first_name', 'last_name', 'email', 'program', 'school_id', 'created_at')
                ->get()
                ->map(function ($student) {
                    return [
                        'id' => $student->id,
                        'name' => trim($student->first_name.' '.$student->last_name),
                        'email' => $student->email,
                        'program' => $student->program,
                        'school_id' => $student->school_id,
                        'enrolled_date' => $student->created_at ? $student->created_at->format('M d, Y') : 'Unknown',
                    ];
                });

            return $students;
        } catch (\Exception $e) {
            return collect();
        }
    }

    /**
     * Get coordinator assignment data for Super Admin management
     */
    private function getCoordinatorAssignments()
    {
        try {
            // Get all coordinator-program mappings
            $mappings = CoordinatorProgramService::getAllMappings();

            // Get defense requests with coordinator assignments
            $requests = DefenseRequest::with(['coordinatorUser', 'user'])
                ->whereNotNull('coordinator_user_id')
                ->select([
                    'id', 'coordinator_user_id', 'program', 'thesis_title',
                    'submitted_by', 'workflow_state', 'coordinator_assigned_at',
                    'coordinator_manually_assigned', 'coordinator_assignment_notes',
                ])
                ->orderBy('coordinator_assigned_at', 'desc')
                ->limit(100)
                ->get()
                ->map(function ($request) {
                    return [
                        'id' => $request->id,
                        'student_name' => $request->user ? trim($request->user->first_name.' '.$request->user->last_name) : 'Unknown',
                        'program' => $request->program,
                        'thesis_title' => $request->thesis_title,
                        'coordinator_name' => $request->coordinatorUser ? trim($request->coordinatorUser->first_name.' '.$request->coordinatorUser->last_name) : 'Unassigned',
                        'coordinator_email' => $request->coordinatorUser?->email,
                        'workflow_state' => $request->workflow_state,
                        'assigned_at' => $request->coordinator_assigned_at ? $request->coordinator_assigned_at->format('M d, Y H:i') : null,
                        'is_manual' => $request->coordinator_manually_assigned,
                        'notes' => $request->coordinator_assignment_notes,
                    ];
                });

            return [
                'programMappings' => $mappings,
                'recentAssignments' => $requests,
                'stats' => [
                    'total_coordinators' => count($mappings),
                    'total_assignments' => $requests->count(),
                    'manual_assignments' => $requests->where('is_manual', true)->count(),
                    'auto_assignments' => $requests->where('is_manual', false)->count(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Error fetching coordinator assignments', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'programMappings' => [],
                'recentAssignments' => collect(),
                'stats' => [
                    'total_coordinators' => 0,
                    'total_assignments' => 0,
                    'manual_assignments' => 0,
                    'auto_assignments' => 0,
                ],
            ];
        }
    }
}
