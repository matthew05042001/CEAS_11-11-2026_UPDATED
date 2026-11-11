import { Badge } from '@/components/ui/badge';
import { format } from 'date-fns';
import { CreditCard, GraduationCap, Mail, Receipt, User2 } from 'lucide-react';
import type { ComprePaymentSummary } from './Index';

export default function Details({ payment }: { payment: ComprePaymentSummary }) {
    const statusCls =
        payment.status === 'approved'
            ? 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-900'
            : payment.status === 'rejected'
              ? 'bg-rose-100 text-rose-700 border-rose-200 dark:bg-rose-950 dark:text-rose-300 dark:border-rose-900'
              : 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-950 dark:text-amber-300 dark:border-amber-900';

    const money = (amt?: number | null) => (amt == null ? '—' : new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(amt));

    return (
        <div className="p-3 text-sm">
            {/* Header */}
            <div className="mb-3 flex items-center gap-2">
                <div className="bg-primary/10 text-primary inline-flex h-8 w-8 items-center justify-center rounded-md">
                    <CreditCard className="h-5 w-5" />
                </div>
                <h2 className="text-lg font-semibold tracking-tight">Details</h2>
            </div>

            <div className="dark:bg-background rounded-lg border bg-white/60 p-3">
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <div className="flex items-center gap-2 text-[15px] font-medium">
                            <User2 className="text-muted-foreground h-4 w-4" />
                            <span className="text-base font-semibold md:text-[17px]">
                                {payment.last_name}, {payment.first_name} {payment.middle_name ? `${payment.middle_name[0]}.` : ''}
                            </span>
                        </div>
                        <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-3 text-[13px]">
                            <span className="inline-flex items-center gap-1">
                                <Mail className="h-3.5 w-3.5" />
                                {payment.email || '—'}
                            </span>
                            <span className="inline-flex items-center gap-1">
                                ID: <span className="text-foreground font-medium">{payment.school_id || '—'}</span>
                            </span>
                        </div>
                    </div>

                    <div>
                        <div className="flex items-center gap-2 text-[15px]">
                            <GraduationCap className="text-muted-foreground h-4 w-4" />
                            <span className="text-foreground">{payment.program || '—'}</span>
                        </div>
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            <Badge variant="outline" className={`rounded-full ${statusCls}`}>
                                {payment.status[0].toUpperCase() + payment.status.slice(1)}
                            </Badge>
                            <Badge variant="outline" className="rounded-full">
                                {payment.submitted_at ? format(new Date(payment.submitted_at), 'MMM dd, yyyy') : '—'}
                            </Badge>
                        </div>
                    </div>
                </div>

                <div className="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div>
                        <div className="text-muted-foreground text-xs">Reference</div>
                        <div className="inline-flex items-center gap-2 font-medium">
                            <Receipt className="text-muted-foreground h-4 w-4" />
                            {payment.reference || '—'}
                        </div>
                    </div>
                    <div>
                        <div className="text-muted-foreground text-xs">Amount</div>
                        <div className="font-medium">{money(payment.amount)}</div>
                    </div>
                    {payment.remarks ? (
                        <div className="md:col-span-2">
                            <div className="text-muted-foreground text-xs">Remarks</div>
                            <div className="text-foreground font-normal">{payment.remarks}</div>
                        </div>
                    ) : null}
                    {payment.proof_url ? (
                        <div className="md:col-span-2">
                            <div className="text-muted-foreground mb-1 text-xs">Proof</div>
                            <a href={payment.proof_url} target="_blank" rel="noreferrer" className="text-primary break-all underline">
                                View uploaded receipt
                            </a>
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
