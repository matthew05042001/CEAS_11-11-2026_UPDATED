import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, usePage } from '@inertiajs/react';
import { Check, Receipt } from 'lucide-react';
import { useMemo, useState } from 'react';
import PaymentForm from './PaymentForm';
import DisplayPayment from './display-payment';

import { Dialog, DialogContent } from '@/components/ui/dialog';

type PageProps = {
    student: { name: string; program: string | null; email?: string | null };
    canSubmit: boolean;
    approvalStatus: 'not_submitted' | 'registrar_pending' | 'aa_pending' | 'approved' | 'rejected';
    payment: {
        payment_id: number;
        or_number: string;
        payment_date: string; // YYYY-MM-DD (or ISO)
        receipt_image: string | null;
        status: 'pending' | 'approved' | 'rejected';
        remarks?: string | null;
        created_at?: string | null;
    } | null;
};

// DEV ONLY: simulate approval/opening the form (remove when API is ready)
const DEV_SIMULATE_PAYMENT = false;
// Default simulation values (used when no URL params are provided)
const SIM_DEFAULTS = {
    canSubmit: true, // pretend the comprehensive application is approved
    debugOpen: false, // force-enable the button even if not allowed
};

export default function Index() {
    const { props } = usePage<PageProps>();
    const { student, canSubmit, approvalStatus, payment } = props;

    // DEV: read optional overrides from the query string (?pay_can=1&pay_debug=1)
    const devOverrides = useMemo(() => {
        if (!DEV_SIMULATE_PAYMENT || typeof window === 'undefined') {
            return { canSubmit: null as boolean | null, debugOpen: false };
        }
        const qs = new URLSearchParams(window.location.search);
        const can = qs.has('pay_can') ? qs.get('pay_can') === '1' : SIM_DEFAULTS.canSubmit; // fallback to SIM_DEFAULTS
        const dbg = qs.get('pay_debug') === '1' || SIM_DEFAULTS.debugOpen;
        return { canSubmit: can, debugOpen: dbg };
    }, []);

    // Effective ability to open the form
    const effectiveCanSubmit = DEV_SIMULATE_PAYMENT && devOverrides.canSubmit !== null ? devOverrides.canSubmit : canSubmit;

    const canOpenForm = effectiveCanSubmit && (!payment || payment.status === 'rejected');

    const [open, setOpen] = useState(false);
    const [showSuccessPanel, setShowSuccessPanel] = useState(false); // ensure this exists

    const statusBadge = useMemo(() => {
        const s = payment?.status;
        const base = 'inline-flex items-center rounded-full px-2 py-1 text-xs font-medium';
        if (s === 'approved') return <span className={`${base} bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300`}>Approved</span>;
        if (s === 'rejected') return <span className={`${base} bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300`}>Rejected</span>;
        if (s === 'pending') return <span className={`${base} bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300`}>Pending</span>;
        return <span className={`${base} bg-slate-100 text-slate-800 dark:bg-slate-800/50 dark:text-slate-300`}>No submission</span>;
    }, [payment?.status]);

    return (
        <AppLayout>
            <Head title="Comprehensive Exam • Payment" />

            <div className="flex h-full flex-1 flex-col gap-5 overflow-auto px-7 pt-5">
                <div className="bg-card text-card-foreground border-border w-full overflow-hidden rounded-lg border px-2">
                    {/* Header row (mirrors comprehensive exam header) */}
                    <div className="flex w-full flex-row items-center justify-between border-b p-3">
                        <div className="flex items-center gap-2">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full border border-rose-500 bg-rose-500/10">
                                <Receipt className="h-5 w-5 text-rose-400" />
                            </div>
                            <div>
                                <span className="text-base font-semibold">Comprehensive Exam Payment</span>
                                <p className="text-muted-foreground block text-xs">Submit your official receipt for verification.</p>
                                {!canSubmit && (
                                    <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                        {approvalStatus === 'not_submitted'
                                            ? 'Submit your comprehensive exam application first.'
                                            : approvalStatus === 'rejected'
                                              ? 'Your application was not approved. Contact the Graduate School for assistance.'
                                              : approvalStatus === 'registrar_pending'
                                                ? 'Payment will be available after the Registrar reviews your application.'
                                                : approvalStatus === 'aa_pending'
                                                  ? 'The Registrar approved your application. Payment will be available after the Administrative Assistant approves it.'
                                                  : 'Payment will be available after both the Registrar and Administrative Assistant approve your application.'}
                                    </p>
                                )}
                                <div className="mt-1 text-xs">{statusBadge}</div>
                            </div>
                        </div>

                        <Button
                            className="rounded-md bg-rose-500 px-5 text-sm"
                            onClick={() => setOpen(true)}
                            disabled={!canOpenForm && !devOverrides.debugOpen} // DEV: allow force-open
                            title={
                                canOpenForm || devOverrides.debugOpen
                                    ? 'Add receipt'
                                    : !effectiveCanSubmit
                                      ? 'You can submit payment only after Registrar and Administrative Assistant approval.'
                                      : 'Payment already submitted'
                            }
                        >
                            Add receipt
                        </Button>
                    </div>

                    {/* Display card (expandable) */}
                    <div className="p-1">
                        {payment ? (
                            <div id="payment-card">
                                <DisplayPayment
                                    payment={{
                                        id: payment.payment_id,
                                        status: payment.status,
                                        or_number: payment.or_number,
                                        payment_date: payment.payment_date,
                                        receipt_image: payment.receipt_image,
                                        remarks: payment.remarks ?? null,
                                        created_at: payment.created_at ?? null,
                                    }}
                                    student={student}
                                />
                            </div>
                        ) : (
                            <div className="text-muted-foreground p-6 text-center text-sm">No payment submitted yet.</div>
                        )}
                    </div>
                </div>
            </div>

            {/* Modal with success panel flow (like comprehensive exam form) */}
            <Dialog
                open={open}
                onOpenChange={(isOpen) => {
                    setOpen(isOpen);
                    if (!isOpen) setShowSuccessPanel(false);
                }}
            >
                <DialogContent className="flex h-[70vh] w-full max-w-3xl flex-col">
                    {' '}
                    {/* slightly shorter modal */}
                    {showSuccessPanel ? (
                        <div className="flex flex-1 flex-col items-center justify-center space-y-6 px-4">
                            <Check size={48} className="text-rose-500" />
                            <h2 className="text-2xl font-semibold">Payment Submitted!</h2>
                            <p className="text-muted-foreground text-center">Your payment has been saved successfully.</p>
                            <Button
                                onClick={() => {
                                    setOpen(false);
                                    setTimeout(() => {
                                        document.getElementById('payment-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                                    }, 100);
                                }}
                                className="bg-rose-500"
                            >
                                Done
                            </Button>
                        </div>
                    ) : (
                        <div className="flex-1 overflow-auto px-2">
                            <PaymentForm
                                canSubmit={canOpenForm || devOverrides.debugOpen}
                                payment={payment}
                                onSuccess={() => setShowSuccessPanel(true)} // show success card
                            />
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
