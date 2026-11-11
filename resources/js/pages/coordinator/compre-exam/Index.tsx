import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { usePage } from '@inertiajs/react';
import { PaperclipIcon, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import TableCompreExam from './table-compre-exam';

export type CompreExamApplicationSummary = {
    id: number;
    application_id?: number | null;
    review_remarks?: string | null;
    reviewed_at?: string | null;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    email: string | null;
    school_id: string | null;
    program: string | null;
    eligible: boolean;
    lacking: string[];
    applied: boolean;
    submitted_at: string | null;
    application_status: 'approved' | 'pending' | 'rejected' | 'not_yet_applied';
    registrar_approval_status: 'approved' | 'pending' | null;
    can_review: boolean;
    can_schedule: boolean;
    schedule: {
        id: number;
        exam_date: string;
        exam_time: string;
        venue: string;
        proctors: string[];
        proctor_ids: number[];
        teacher_assigned: string | null;
        change_reason: string | null;
    } | null;
    permit_status: 'approved' | 'pending' | 'rejected' | null;
    subjects: {
        name: string | null;
        code: string | null;
        date: string | null;
        start_time: string | null;
        end_time: string | null;
    }[];
    response: {
        id: number;
        family_name: string;
        first_name: string;
        program: string;
        contact_number: string;
        email_address: string;
        id_number: string;
        created_at: string;
        evaluated_at: string | null;
    } | null;
};

type PageProps = {
    auth: { user: { role: string } };
    programs: string[];
    eligible: CompreExamApplicationSummary[];
    notEligible: CompreExamApplicationSummary[];
    counts?: { eligible: number; notEligible: number };
    proctorOptions: { id: number; name: string; email: string }[];
    canEvaluateResponses: boolean;
};

export default function CoordinatorCompreExamIndex() {
    const { props } = usePage<PageProps>();
    const { eligible = [], notEligible = [] } = props;
    const isCoordinator = props.auth.user.role === 'Coordinator';

    const [tab, setTab] = useState<'applications' | 'students'>('applications');
    const [q, setQ] = useState('');

    const allStudents = [...eligible, ...notEligible];
    const data = allStudents.filter((student) => (tab === 'applications' ? student.applied : !student.applied));

    const paged = useMemo(() => {
        const query = q.trim().toLowerCase();
        if (!query) return data;
        return data.filter((r) =>
            [r.first_name, r.middle_name || '', r.last_name, r.email || '', r.school_id || '', r.program || '']
                .join(' ')
                .toLowerCase()
                .includes(query),
        );
    }, [data, q]);

    const columns = { student: true, program: true, eligibility: true, applied: true, appStatus: true, actions: true };

    return (
        <AppLayout>
            <div className="px-7 pt-5 pb-6">
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <div className="flex items-center gap-2">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full border border-rose-500 bg-rose-500/10">
                            <PaperclipIcon className="h-5 w-5 text-rose-400" />
                        </div>
                        <div>
                            <span className="text-base font-semibold">Comprehensive Exam Applications</span>
                            <span className="text-muted-foreground block text-xs">
                                This section shows all comprehensive exam applications. View student application details.
                            </span>
                        </div>
                    </div>
                    <div className="relative ml-auto w-64">
                        <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search..." className="h-8 pl-8" />
                        <Search className="absolute top-1.5 left-2 h-5 w-5 text-zinc-400" />
                    </div>
                </div>

                <div className="mt-4 flex gap-2" role="group" aria-label="Exam application views">
                    <button
                        className={`rounded-md border px-3 py-2 text-sm ${tab === 'applications' ? 'bg-muted font-semibold' : ''}`}
                        onClick={() => setTab('applications')}
                    >
                        Applications
                    </button>
                    <button
                        className={`rounded-md border px-3 py-2 text-sm ${tab === 'students' ? 'bg-muted font-semibold' : ''}`}
                        onClick={() => setTab('students')}
                    >
                        Students not yet applied
                    </button>
                </div>
                <div className="mt-3">
                    <TableCompreExam
                        paged={paged}
                        columns={columns}
                        proctorOptions={props.proctorOptions ?? []}
                        canManageSchedules={isCoordinator}
                        canEvaluateResponses={props.canEvaluateResponses}
                    />
                </div>
            </div>
        </AppLayout>
    );
}
