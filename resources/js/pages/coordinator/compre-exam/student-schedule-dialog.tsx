import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Dialog, DialogContent, DialogDescription, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { format, startOfDay } from 'date-fns';
import { CalendarDays, CalendarIcon } from 'lucide-react';
import { FormEvent, useState } from 'react';
import type { CompreExamApplicationSummary } from './Index';

type Props = {
    student: CompreExamApplicationSummary;
    proctorOptions: { id: number; name: string; email: string }[];
};

function requestHeaders(): Record<string, string> {
    const xsrfCookie = document.cookie
        .split(';')
        .map((cookie) => cookie.trim())
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='));
    const xsrfToken = xsrfCookie ? decodeURIComponent(xsrfCookie.slice('XSRF-TOKEN='.length)) : '';
    const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : { 'X-CSRF-TOKEN': csrfToken }),
    };
}

async function errorMessage(response: Response): Promise<string> {
    if (response.status === 419) {
        return 'Your session expired. Refresh the page and try again.';
    }

    const body = await response.json().catch(() => null);
    const validationMessage = body?.errors ? Object.values(body.errors as Record<string, string[]>).flat()[0] : null;
    return validationMessage || body?.message || 'The exam schedule could not be saved.';
}

export default function StudentScheduleDialog({ student, proctorOptions }: Props) {
    const [open, setOpen] = useState(false);
    const [proctors, setProctors] = useState<number[]>(student.schedule?.proctor_ids ?? []);
    const [datePickerOpen, setDatePickerOpen] = useState(false);
    const [examDate, setExamDate] = useState<Date | undefined>(() => {
        const existingDate = student.schedule?.exam_date;
        if (!existingDate) return undefined;
        const parsedDate = new Date(`${existingDate}T00:00:00`);
        return parsedDate >= startOfDay(new Date()) ? parsedDate : undefined;
    });
    const [examTime, setExamTime] = useState(student.schedule?.exam_time ?? '');
    const [roomCount, setRoomCount] = useState(student.schedule?.venue ?? '');
    const [teacher, setTeacher] = useState(student.schedule?.teacher_assigned ?? '');
    const [changeReason, setChangeReason] = useState(student.schedule?.change_reason ?? '');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    const schedule = student.schedule;
    const selectedProctors = proctors
        .map((id) => proctorOptions.find((option) => option.id === id)?.name)
        .filter((name): name is string => Boolean(name));
    const summaryDate = examDate
        ? format(examDate, 'MMMM d, yyyy')
        : 'Not set';
    const summaryTime = examTime
        ? new Date(`1970-01-01T${examTime}`).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
        : 'Not set';

    const onSubmit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setError(null);
        setSaving(true);

        const formData = new FormData(event.currentTarget);
        formData.set('student_id', String(student.id));
        formData.set('program', student.program || '');
        if (examDate) {
            formData.set('date', format(examDate, 'yyyy-MM-dd'));
        }
        proctors.forEach((id) => formData.append('proctor_ids[]', String(id)));
        if (schedule) {
            formData.append('_method', 'PUT');
        }

        try {
            const response = await fetch(schedule ? `/coordinator/compre-schedule/${schedule.id}` : '/coordinator/compre-schedule', {
                method: 'POST',
                headers: requestHeaders(),
                credentials: 'same-origin',
                body: formData,
            });

            if (!response.ok) {
                throw new Error(await errorMessage(response));
            }

            setOpen(false);
            router.reload({ only: ['eligible', 'notEligible', 'proctorOptions'] });
        } catch (submitError) {
            setError(submitError instanceof Error ? submitError.message : 'The exam schedule could not be saved.');
        } finally {
            setSaving(false);
        }
    };

    const updateProctor = (index: number, value: string) => {
        setProctors((current) => current.map((id, itemIndex) => (itemIndex === index ? Number(value) : id)));
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline" type="button" className="gap-1">
                    <CalendarDays className="h-4 w-4" />
                    {schedule ? 'Edit schedule' : 'Schedule exam'}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogTitle>{schedule ? 'Update exam schedule' : "Schedule this student's exam"}</DialogTitle>
                <DialogDescription>
                    Set the exam date, time, room, proctor, and assigned teacher for {student.first_name} {student.last_name}.
                </DialogDescription>
                <form onSubmit={onSubmit} className="grid gap-4 sm:grid-cols-2">
                    <div className="rounded-md border px-3 py-2 text-sm sm:col-span-2">
                        <span className="text-muted-foreground">Program: </span>
                        {student.program || '—'}
                    </div>
                    <label className="grid gap-2 text-sm">
                        Date
                        <div>
                            <Button
                                variant="outline"
                                type="button"
                                aria-expanded={datePickerOpen}
                                onClick={() => setDatePickerOpen((current) => !current)}
                                className={`w-full justify-start text-left font-normal ${!examDate ? 'text-muted-foreground' : ''}`}
                            >
                                <CalendarIcon className="mr-2 h-4 w-4" />
                                {examDate ? format(examDate, 'MM/dd/yyyy') : 'Choose exam date'}
                            </Button>
                            {datePickerOpen && (
                                <div className="mt-2 w-fit rounded-md border bg-background p-1 shadow-md">
                                <Calendar
                                    mode="single"
                                    selected={examDate}
                                    onSelect={(date) => {
                                        setExamDate(date);
                                        setDatePickerOpen(false);
                                    }}
                                    disabled={{ before: startOfDay(new Date()) }}
                                />
                                </div>
                            )}
                        </div>
                        <input type="hidden" name="date" value={examDate ? format(examDate, 'yyyy-MM-dd') : ''} />
                    </label>
                    <label className="grid gap-2 text-sm">
                        Time
                        <input
                            name="time"
                            type="time"
                            value={examTime}
                            onChange={(event) => setExamTime(event.target.value)}
                            required
                            className="rounded-md border bg-transparent px-3 py-2"
                        />
                    </label>
                    <label className="grid gap-2 text-sm sm:col-span-2">
                        Number of rooms
                        <input
                            name="venue"
                            type="text"
                            value={roomCount}
                            onChange={(event) => setRoomCount(event.target.value)}
                            placeholder="Enter number of rooms"
                            required
                            className="rounded-md border bg-transparent px-3 py-2"
                        />
                    </label>
                    <div className="grid gap-2 text-sm sm:col-span-2">
                        <span>Proctors</span>
                        {proctors.map((proctorId, index) => (
                            <div key={index} className="flex gap-2">
                                <select
                                    value={proctorId || ''}
                                    onChange={(event) => updateProctor(index, event.target.value)}
                                    required
                                    className="w-full rounded-md border bg-white px-3 py-2 text-black"
                                >
                                    <option value="">Select a proctor</option>
                                    {proctorOptions.map((option) => (
                                        <option
                                            key={option.id}
                                            value={option.id}
                                            disabled={proctors.some((selected, selectedIndex) => selectedIndex !== index && selected === option.id)}
                                        >
                                            {option.name}
                                        </option>
                                    ))}
                                </select>
                                {index > 0 && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setProctors((current) => current.filter((_, itemIndex) => itemIndex !== index))}
                                    >
                                        Remove
                                    </Button>
                                )}
                            </div>
                        ))}
                        {proctorOptions.length === 0 && (
                            <span className="text-muted-foreground text-xs">
                                No Proctor accounts are available. Run `php artisan demo:proctor-accounts` to create demo accounts.
                            </span>
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            className="w-fit"
                            disabled={proctors.length >= proctorOptions.length}
                            onClick={() => setProctors((current) => [...current, 0])}
                        >
                            Add proctor
                        </Button>
                    </div>
                    <label className="grid gap-2 text-sm sm:col-span-2">
                        Teacher assigned
                        <input
                            name="teacher_assigned"
                            value={teacher}
                            onChange={(event) => setTeacher(event.target.value)}
                            placeholder="Enter teacher name"
                            required
                            className="rounded-md border bg-transparent px-3 py-2"
                        />
                    </label>
                    {schedule && (
                        <label className="grid gap-2 text-sm sm:col-span-2">
                            Reason for change
                            <textarea
                                name="change_reason"
                                value={changeReason}
                                onChange={(event) => setChangeReason(event.target.value)}
                                placeholder="Explain why this exam schedule is changing"
                                minLength={3}
                                maxLength={2000}
                                required
                                rows={3}
                                className="rounded-md border bg-transparent px-3 py-2"
                            />
                        </label>
                    )}
                    <section aria-label="Schedule summary" className="rounded-md border bg-muted/30 p-4 text-sm sm:col-span-2">
                        <h3 className="font-semibold">Schedule summary</h3>
                        <dl className="mt-3 grid gap-3 sm:grid-cols-2">
                            <div>
                                <dt className="text-muted-foreground text-xs">Student</dt>
                                <dd>{student.first_name} {student.last_name}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Program</dt>
                                <dd>{student.program || '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Date and time</dt>
                                <dd>{summaryDate} at {summaryTime}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Number of rooms</dt>
                                <dd>{roomCount || 'Not set'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Proctors</dt>
                                <dd>{selectedProctors.length ? selectedProctors.join(', ') : 'Not selected'}</dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground text-xs">Teacher assigned</dt>
                                <dd>{teacher.trim() || 'Not assigned'}</dd>
                            </div>
                            {schedule && (
                                <div className="sm:col-span-2">
                                    <dt className="text-muted-foreground text-xs">Reason for change</dt>
                                    <dd className="whitespace-pre-wrap">{changeReason.trim() || 'Not provided'}</dd>
                                </div>
                            )}
                        </dl>
                    </section>
                    {error && <p role="alert" className="text-sm text-red-500 sm:col-span-2">{error}</p>}
                    <div className="flex justify-end sm:col-span-2">
                        <Button type="submit" disabled={saving || !examDate || proctors.length === 0 || proctors.some((id) => !id)}>
                            {saving ? 'Saving...' : schedule ? 'Update schedule' : 'Save schedule'}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
