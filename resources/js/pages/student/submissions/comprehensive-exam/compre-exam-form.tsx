import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Stepper } from '@/components/ui/Stepper';
import type { SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import React, { useEffect, useState } from 'react';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onFinish?: () => void;
};

export default function CompreExamForm({ open, onOpenChange, onFinish }: Props) {
    const { props } = usePage<SharedData>();
    const user = props?.auth?.user || {};

    const { data, setData, post, processing, reset, errors, clearErrors } = useForm<{
        schoolYear: string;
        program: string;
        family_name: string;
        first_name: string;
        email_address: string;
        id_number: string;
        officeAddress: string;
        mobileNo: string;
        telephoneNo: string;
    }>({
        schoolYear: '',
        program: user.program || '',
        family_name: user.last_name || '',
        first_name: user.first_name || '',
        email_address: user.email || '',
        id_number: String(user.school_id || user.student_number || user.id || ''),
        officeAddress: '',
        mobileNo: String(user.mobile_no || user.contact_number || ''),
        telephoneNo: '',
    });

    const [currentStep, setCurrentStep] = useState(0);
    const [showSuccessPanel, setShowSuccessPanel] = useState(false);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>({});

    function fieldError(field: string): string | undefined {
        return clientErrors[field] ?? Object.entries(errors).find(([key]) => key === field)?.[1];
    }

    function clearFieldError(field: string) {
        setClientErrors((current) => {
            const next = { ...current };
            delete next[field];
            return next;
        });
        clearErrors();
    }

    useEffect(() => {
        if (!showSuccessPanel) return;

        const timer = window.setTimeout(() => {
            onOpenChange(false);
            setShowSuccessPanel(false);
            setCurrentStep(0);
            onFinish?.();
        }, 1800);

        return () => window.clearTimeout(timer);
    }, [onFinish, onOpenChange, showSuccessPanel]);

    // Build School Year options 2020-2021 ... 2039-2040
    const schoolYearOptions = React.useMemo(
        () =>
            Array.from({ length: 20 }, (_, i) => {
                const start = 2020 + i;
                return `${start}-${start + 1}`;
            }),
        [],
    );

    function handleDialogChange(isOpen: boolean) {
        onOpenChange(isOpen);
        if (!isOpen) {
            setCurrentStep(0);
            reset();
            setShowSuccessPanel(false);
            setClientErrors({});
            clearErrors();
        }
    }

    function handleSubmit() {
        post(route('comprehensive-exam.store'), {
            preserveScroll: true,
            preserveState: true, // keep modal state so we can show success panel
            onSuccess: () => {
                setShowSuccessPanel(true); // show the success UI like Defense
            },
            onError: (validationErrors) => {
                const fields = Object.keys(validationErrors);
                if (fields.some((field) => ['mobileNo', 'telephoneNo', 'officeAddress', 'email_address'].includes(field))) {
                    setCurrentStep(1);
                } else if (fields.some((field) => ['schoolYear', 'program', 'family_name', 'first_name', 'id_number'].includes(field))) {
                    setCurrentStep(0);
                }
            },
        });
    }

    function handleNext() {
        if (!validateStep(currentStep)) return;
        clearErrors();

        if (currentStep < steps.length - 1) {
            setCurrentStep((s) => s + 1);
        } else {
            handleSubmit();
        }
    }

    function validateStep(step: number): boolean {
        const nextErrors: Record<string, string> = {};

        if (step === 0) {
            if (!data.first_name.trim()) nextErrors.first_name = 'Enter your first name.';
            if (!data.family_name.trim()) nextErrors.family_name = 'Enter your family name.';
            if (!data.id_number.trim()) nextErrors.id_number = 'Enter your ID number.';
            if (!data.schoolYear) nextErrors.schoolYear = 'Select a school year.';
            if (!data.program.trim()) nextErrors.program = 'Enter your program.';
            if (!data.email_address.trim()) {
                nextErrors.email_address = 'Enter your email address.';
            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email_address)) {
                nextErrors.email_address = 'Enter a valid email address.';
            }
        } else if (step === 1) {
            if (!data.mobileNo.trim()) nextErrors.mobileNo = 'Enter your mobile number.';
        }

        setClientErrors(nextErrors);
        return Object.keys(nextErrors).length === 0;
    }

    function handlePrev() {
        if (currentStep > 0) setCurrentStep((s) => s - 1);
    }

    const steps = [
        {
            title: 'Personal Information',
            content: (
                <>
                    <HeadingSmall title="Step 1: Student Information" />
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div>
                                    <Label className="text-s" htmlFor="first-name">First Name</Label>
                            <Input
                                        id="first-name"
                                        className="text-s text-foreground disabled:bg-muted disabled:text-foreground h-10 disabled:opacity-100"
                                        value={data.first_name}
                                        onChange={(e) => {
                                            setData('first_name', e.target.value);
                                            clearFieldError('first_name');
                                        }}
                                        aria-invalid={Boolean(fieldError('first_name'))}
                                    />
                                    {fieldError('first_name') && <p className="text-xs text-red-600">{fieldError('first_name')}</p>}
                                </div>
                                <div>
                                    <Label className="text-s" htmlFor="middle-initial">Middle Initial</Label>
                                    <Input
                                        id="middle-initial"
                                        className="text-s text-foreground disabled:bg-muted disabled:text-foreground h-10 disabled:opacity-100"
                                        maxLength={3}
                                        value={user.middle_name?.[0] ?? ''} // changed
                                readOnly
                                disabled
                            />
                        </div>
                        <div>
                            <Label className="text-s" htmlFor="family-name">Family Name</Label>
                            <Input
                                id="family-name"
                                className="text-s text-foreground disabled:bg-muted disabled:text-foreground h-10 disabled:opacity-100"
                                value={data.family_name}
                                onChange={(e) => {
                                    setData('family_name', e.target.value);
                                    clearFieldError('family_name');
                                }}
                                aria-invalid={Boolean(fieldError('family_name'))}
                            />
                            {fieldError('family_name') && <p className="text-xs text-red-600">{fieldError('family_name')}</p>}
                        </div>
                        <div>
                            <Label className="text-s" htmlFor="id-number">ID Number</Label>
                            <Input
                                id="id-number"
                                className="text-s h-10"
                                value={data.id_number}
                                onChange={(e) => {
                                    setData('id_number', e.target.value);
                                    clearFieldError('id_number');
                                }}
                                aria-invalid={Boolean(fieldError('id_number'))}
                            />
                            {fieldError('id_number') && <p className="text-xs text-red-600">{fieldError('id_number')}</p>}
                        </div>
                        <div>
                            <Label className="text-s">School Year</Label>
                            <select
                                className="text-s border-border bg-background text-foreground h-10 w-full rounded-md border px-3 focus:ring-2 focus:ring-rose-300 focus:outline-none"
                                value={data.schoolYear}
                                onChange={(e) => {
                                    setData('schoolYear', e.target.value);
                                    clearFieldError('schoolYear');
                                }}
                                aria-invalid={Boolean(fieldError('schoolYear'))}
                            >
                                <option value="" disabled>
                                    Select school year
                                </option>
                                {schoolYearOptions.map((sy) => (
                                    <option key={sy} value={sy}>
                                        {sy}
                                    </option>
                                ))}
                            </select>
                            {fieldError('schoolYear') && <p className="text-xs text-red-600">{fieldError('schoolYear')}</p>}
                        </div>
                        <div>
                            <Label className="text-s" htmlFor="program">Program</Label>
                            <Input
                                id="program"
                                className="text-s h-10 w-105"
                                value={data.program}
                                onChange={(e) => {
                                    setData('program', e.target.value);
                                    clearFieldError('program');
                                }}
                                aria-invalid={Boolean(fieldError('program'))}
                            />
                            {fieldError('program') && <p className="text-xs text-red-600">{fieldError('program')}</p>}
                        </div>
                    </div>
                </>
            ),
        },
        {
            title: 'Contact Information',
            content: (
                <>
                    <HeadingSmall title="Step 2: Contact Information" />
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div className="md:col-span-2">
                            <Label className="text-s">Office Address</Label>
                            <Input className="text-s h-10" value={data.officeAddress} onChange={(e) => setData('officeAddress', e.target.value)} />
                        </div>
                        <div>
                            <Label className="text-s">Mobile No.</Label>
                            <Input
                                type="text"
                                inputMode="numeric"
                                pattern="[0-9]*"
                                className="text-s h-10"
                                value={data.mobileNo}
                                placeholder="e.g. 09171234567"
                                onChange={(e) => {
                                    setData('mobileNo', e.target.value.replace(/\D/g, ''));
                                    clearFieldError('mobileNo');
                                }}
                                aria-invalid={Boolean(fieldError('mobileNo'))}
                            />
                            {fieldError('mobileNo') && <p className="text-xs text-red-600">{fieldError('mobileNo')}</p>}
                        </div>
                        <div>
                            <Label className="text-s">Telephone No.</Label>
                            <Input
                                type="tel"
                                className="text-s h-10"
                                value={data.telephoneNo}
                                placeholder="e.g. (02) 123-4567"
                                onChange={(e) => {
                                    // Allow digits, spaces, dashes, parentheses
                                    const cleaned = e.target.value.replace(/[^0-9\s()-]/g, '');
                                    setData('telephoneNo', cleaned);
                                }}
                            />
                        </div>
                        <div className="md:col-span-2">
                            <Label className="text-s" htmlFor="email-address">Email</Label>
                            <Input
                                id="email-address"
                                className="text-s h-10"
                                type="email"
                                value={data.email_address}
                                onChange={(e) => {
                                    setData('email_address', e.target.value);
                                    clearFieldError('email_address');
                                }}
                                aria-invalid={Boolean(fieldError('email_address'))}
                            />
                            {fieldError('email_address') && <p className="text-xs text-red-600">{fieldError('email_address')}</p>}
                        </div>
                    </div>
                </>
            ),
        },
        {
            title: 'Review Submission',
            content: (
                <>
                    <div className="w-full pb-5">
                        <HeadingSmall title="Step 3: Review Submission" />
                        <div className="space-y-6 rounded-xl border-2 border-rose-400 bg-rose-50 p-8 shadow-lg">
                            <div className="flex flex-col gap-6 md:flex-row">
                                <div className="flex-1 space-y-2">
                                    <div className="text-lg font-bold text-rose-700">Personal Information</div>
                                    <div className="text-gray-700">
                                        <span className="font-semibold">Name:</span>{' '}
                                        {`${data.family_name || ''}, ${data.first_name || ''} ${user.middle_name?.[0] ?? ''}`}
                                    </div>
                                    <div className="text-gray-700">
                                        <span className="font-semibold">ID Number:</span> {data.id_number || '--'}
                                    </div>
                                    <div className="text-gray-700">
                                        <span className="font-semibold">School Year:</span> {data.schoolYear || '--'}
                                    </div>
                                    <div className="text-gray-700">
                                        <span className="font-semibold">Program:</span> {data.program || '--'}
                                    </div>
                                </div>
                                <div className="flex-1 space-y-2">
                                    <div className="text-lg font-bold text-rose-700">Contact Information</div>
                                    <div className="text-gray-700">
                                        <span className="font-semibold">Office Address:</span> {data.officeAddress || '--'}
                                    </div>
                                    <div className="text-gray-700">
                                        <span className="font-semibold">Mobile No.:</span> {data.mobileNo || '--'}
                                    </div>
                                    <div className="text-gray-700">
                                        <span className="font-semibold">Telephone No.:</span> {data.telephoneNo || '--'}
                                    </div>
                                    <div className="text-gray-700">
                                        <span className="font-semibold">Email:</span> {data.email_address || '--'}
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </>
            ),
        },
    ];

    return (
        <Dialog open={open} onOpenChange={handleDialogChange}>
            <DialogContent className="flex h-[85vh] w-full max-w-3xl flex-col">
                <DialogHeader>
                    <DialogTitle>Comprehensive Exam Application</DialogTitle>
                    <DialogDescription>Review your application details before submitting.</DialogDescription>
                </DialogHeader>

                {showSuccessPanel ? (
                    <div role="status" className="flex flex-1 flex-col items-center justify-center space-y-6 px-4">
                        <Check size={48} className="text-rose-500" />
                        <h2 className="text-2xl font-semibold">Application Submitted!</h2>
                        <p className="text-center text-gray-600">Your comprehensive exam application has been saved successfully.</p>
                    </div>
                ) : (
                    <div className="flex-1 overflow-auto px-2">
                        <Stepper
                            steps={steps}
                            currentStep={currentStep}
                            onNext={handleNext}
                            onPrev={handlePrev}
                            loading={processing}
                            className="h-full"
                        />
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
