import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarX, GraduationCap, Info, Plus, RefreshCcw, ShieldAlert, ShieldCheck, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import CompreExamForm from './compre-exam-form';
import DisplayApplication from './display-applications';

import { Badge } from '@/components/ui/badge';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Comprehensive Exam', href: '/comprehensive-exam' },
];

type Subject = { subject: string; date: string; startTime: string; endTime: string };

type ApplicationVM = {
    application_id: number;
    school_year: string;
    permit_status: string;
    final_approval_status: string;
    registrar_approval_status: string;
    contact_number?: string | null;
    telephone_number?: string | null;
    office_address?: string | null;
    program: string;
    created_at?: string;
    subjects: Subject[];
    first_name: string;
    middle_name?: string | null;
    last_name: string;
    email: string;
    student_id: string | number;
};

type PageProps = {
    application?: ApplicationVM | null;
};

type Eligibility = {
    examOpen: boolean | null;
    gradesComplete: boolean | null;
    documentsComplete: boolean | null;
    noOutstandingBalance: boolean | null;
    loading: boolean;
    error?: string | null;
};

export default function ComprehensiveExamIndex() {
    const { props } = usePage<PageProps>();
    const { application } = props;

    const [open, setOpen] = useState(false);
    const [resetDialogOpen, setResetDialogOpen] = useState(false);
    const [resettingApplication, setResettingApplication] = useState(false);

    // Eligibility state (student side only)
    const [elig, setElig] = useState<Eligibility>({
        examOpen: null,
        gradesComplete: null,
        documentsComplete: null,
        noOutstandingBalance: null,
        loading: true,
        error: null,
    });

    const [showEligDialog, setShowEligDialog] = useState(false);
    const retryTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const mounted = useRef(true);

    const fetchEligibility = useCallback(async function checkEligibility(attempt = 0) {
        if (!mounted.current) return;
        if (retryTimer.current) clearTimeout(retryTimer.current);
        setElig((e) => ({ ...e, loading: true, error: null }));

        try {
            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

            // Call the comprehensive exam eligibility API
            const response = await fetch('/api/comprehensive-exam/eligibility', {
                credentials: 'include',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
            });

            if (!response.ok) {
                throw new Error(`API error: ${response.status}`);
            }

            const data = await response.json();

            // Extract eligibility from the requirements array
            const requirements: { name: string; completed: boolean | null }[] = data.requirements || [];
            const documentsReq = requirements.find((r) => r.name === 'Complete documents submitted');
            const gradesReq = requirements.find((r) => r.name === 'Complete grades (registrar verified)');
            const balanceReq = requirements.find((r) => r.name === 'No outstanding tuition balance');

            const newElig = {
                examOpen: true, // This would need to be determined from semester settings
                gradesComplete: gradesReq?.completed ?? null,
                documentsComplete: documentsReq?.completed ?? null,
                noOutstandingBalance: balanceReq?.completed ?? null,
                loading: false,
                error: null,
            };

            setElig(newElig);

            // If data is still loading (all null) and we haven't retried too many times, retry after 3 seconds
            if (newElig.gradesComplete === null && newElig.documentsComplete === null && newElig.noOutstandingBalance === null) {
                if (attempt < 5) {
                    console.log(`Data still loading, will retry in 3 seconds (attempt ${attempt + 1}/5)`);
                    retryTimer.current = setTimeout(() => {
                        checkEligibility(attempt + 1);
                    }, 3000);
                }
            } else {
                // Reset retry count on success
            }
        } catch (error) {
            console.error('=== API ERROR ===', error);

            // Set all to null (Unknown) when API fails
            setElig({
                examOpen: null,
                gradesComplete: null,
                documentsComplete: null,
                noOutstandingBalance: null,
                loading: false,
                error: error instanceof Error ? error.message : 'API call failed',
            });
        }
    }, []);

    useEffect(() => {
        mounted.current = true;
        fetchEligibility();
        return () => {
            mounted.current = false;
            if (retryTimer.current) clearTimeout(retryTimer.current);
        };
    }, [fetchEligibility]);

    const canApply = useMemo(() => {
        return !!(elig.examOpen && elig.gradesComplete && elig.documentsComplete && elig.noOutstandingBalance);
    }, [elig]);

    const examClosed = elig.examOpen === false; // NEW
    const hasPending = application ? application.final_approval_status?.toLowerCase() === 'pending' : false;
    const canResetApplication =
        application?.registrar_approval_status?.toLowerCase() === 'approved' &&
        application.final_approval_status?.toLowerCase() === 'approved';

    function resetApplication() {
        if (!application || resettingApplication) return;
        setResettingApplication(true);
        router.delete(`/comprehensive-exam/${application.application_id}/reset`, {
            preserveScroll: true,
            onFinish: () => setResettingApplication(false),
            onSuccess: () => setResetDialogOpen(false),
        });
    }

    // Reasons to show when not eligible
    const blockers = useMemo(() => {
        const arr: { label: string; ok: boolean | null }[] = [
            { label: 'Exam window is open', ok: elig.examOpen },
            { label: 'Complete grades (registrar verified)', ok: elig.gradesComplete },
            { label: 'Complete documents submitted', ok: elig.documentsComplete },
            { label: 'No outstanding tuition balance', ok: elig.noOutstandingBalance },
        ];
        return arr;
    }, [elig]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Comprehensive Exam" />
            <div className="flex w-full flex-col px-7 pt-5 pb-5">
                <div className="bg-card text-card-foreground border-border w-full overflow-hidden rounded-lg border">
                    {/* --- Comprehensive Exam Content --- */}
                    <div className="border-border flex w-full flex-col items-start justify-between gap-4 border-b p-5 sm:flex-row sm:items-center">
                        <div className="flex items-center gap-2">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full border border-rose-500 bg-rose-500/10">
                                <GraduationCap className="h-5 w-5 text-rose-400" />
                            </div>
                            <div>
                                <span className="text-base font-semibold">Comprehensive Exam</span>
                                <p className="text-muted-foreground block text-xs">Submit and track your comprehensive exam application.</p>
                                <div className="mt-1 flex flex-wrap items-center gap-2">
                                    <Badge
                                        variant={canApply ? 'secondary' : 'outline'}
                                        className={
                                            examClosed
                                                ? 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300'
                                                : canApply
                                                  ? 'border-green-300 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300'
                                                  : ''
                                        }
                                    >
                                        {examClosed ? (
                                            <span className="flex items-center gap-1">
                                                <CalendarX className="h-3.5 w-3.5" />
                                                Closed
                                            </span>
                                        ) : canApply ? (
                                            <span className="flex items-center gap-1">
                                                <ShieldCheck className="h-3.5 w-3.5" />
                                                Eligible
                                            </span>
                                        ) : (
                                            <span className="flex items-center gap-1">
                                                <ShieldAlert className="h-3.5 w-3.5" />
                                                Not eligible
                                            </span>
                                        )}
                                    </Badge>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-7 px-2 text-xs"
                                        onClick={() => fetchEligibility()}
                                        disabled={elig.loading}
                                    >
                                        <RefreshCcw className={`mr-1 h-3.5 w-3.5 ${elig.loading ? 'animate-spin' : ''}`} />
                                        Refresh
                                    </Button>
                                    <Button size="sm" variant="ghost" className="h-7 px-2 text-xs" onClick={() => setShowEligDialog(true)}>
                                        <Info className="mr-1 h-3.5 w-3.5" />
                                        Details
                                    </Button>
                                </div>
                            </div>
                        </div>
                        <div className="flex w-full shrink-0 flex-col gap-2 sm:w-auto sm:flex-row">
                            {canResetApplication && (
                                <Button
                                    variant="outline"
                                    className="w-full border-rose-300 text-rose-700 hover:bg-rose-50 sm:w-auto"
                                    onClick={() => setResetDialogOpen(true)}
                                >
                                    <Trash2 className="mr-1 h-4 w-4" />
                                    Delete and start over
                                </Button>
                            )}
                            <Button
                                className="w-full rounded-md bg-rose-500 px-5 text-sm text-white hover:bg-rose-600 sm:w-auto"
                                onClick={() => setOpen(true)}
                                disabled={!canApply || (!!application && hasPending)}
                                title={
                                    examClosed
                                        ? 'Exam is closed'
                                        : !canApply
                                          ? 'You are not eligible yet'
                                          : hasPending
                                            ? 'Application pending'
                                            : 'Submit application'
                                }
                            >
                                <Plus className="mr-1 h-4 w-4" />
                                Submit application
                            </Button>
                        </div>
                    </div>
                    <div className="flex flex-col gap-4">
                        <CompreExamForm open={open} onOpenChange={setOpen} />
                        <div>
                            {application ? (
                                <DisplayApplication
                                    application={{
                                        id: application.application_id,
                                        first_name: application.first_name,
                                        middle_initial: application.middle_name ? application.middle_name[0] : null,
                                        last_name: application.last_name,
                                        program: application.program,
                                        school_year: application.school_year,
                                        office_address: application.office_address ?? null,
                                        mobile_no: application.contact_number ?? null,
                                        telephone_no: application.telephone_number ?? null,
                                        email: application.email,
                                        status:
                                            application.final_approval_status?.toLowerCase() === 'rejected'
                                                ? 'rejected'
                                                : application.registrar_approval_status?.toLowerCase() !== 'approved'
                                                  ? 'registrar_pending'
                                                  : application.final_approval_status?.toLowerCase() === 'approved'
                                                    ? 'approved'
                                                    : 'aa_pending',
                                        subjects: application.subjects,
                                        created_at: application.created_at ?? null,
                                    }}
                                />
                            ) : (
                                <div className="text-muted-foreground p-6 text-center text-sm">No application submitted yet.</div>
                            )}
                        </div>
                    </div>
                    <Dialog open={resetDialogOpen} onOpenChange={setResetDialogOpen}>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Delete this application and start over?</DialogTitle>
                                <DialogDescription>
                                    This permanently deletes the approved application, its subjects, exam payment and receipt, exam schedule, and related comprehensive exam notifications. You can then submit a new application. This cannot be undone.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter>
                                <Button variant="outline" disabled={resettingApplication} onClick={() => setResetDialogOpen(false)}>
                                    Keep application
                                </Button>
                                <Button variant="destructive" disabled={resettingApplication} onClick={resetApplication}>
                                    {resettingApplication ? 'Deleting...' : 'Delete and start over'}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                    {/* Eligibility details dialog */}
                    <Dialog open={showEligDialog} onOpenChange={setShowEligDialog}>
                        <DialogContent className="sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle>Comprehensive Exam Eligibility</DialogTitle>
                                <DialogDescription>You must meet all the requirements below before you can submit an application.</DialogDescription>
                            </DialogHeader>

                            <div className="space-y-2">
                                {blockers.map((b, i) => (
                                    <div key={i} className="flex items-center justify-between rounded border px-3 py-2">
                                        <span className="text-sm">{b.label}</span>
                                        <Badge
                                            variant="outline"
                                            className={
                                                b.ok === true
                                                    ? 'border-green-300 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300'
                                                    : b.ok === false
                                                      ? 'border-rose-300 bg-rose-50 text-rose-700 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300'
                                                      : 'text-muted-foreground'
                                            }
                                        >
                                            {b.ok === true ? 'OK' : b.ok === false ? 'Missing' : 'Unknown'}
                                        </Badge>
                                    </div>
                                ))}
                            </div>

                            <DialogFooter className="mt-2">
                                <Button variant="outline" onClick={() => setShowEligDialog(false)}>
                                    Close
                                </Button>
                                <Button
                                    onClick={() => {
                                        setShowEligDialog(false);
                                        if (canApply && !hasPending) setOpen(true);
                                    }}
                                    disabled={!canApply || hasPending}
                                >
                                    Apply Now
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                </div>
            </div>
        </AppLayout>
    );
}
