import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Link } from '@inertiajs/react';
import { addDays, endOfMonth, endOfWeek, format, parseISO, startOfMonth, startOfWeek } from 'date-fns';
import { CalendarDays, ChevronLeft, ChevronRight, Clock } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

type Schedule = {
    id: string;
    title: string;
    start: string;
    end?: string | null;
    allDay?: boolean;
    origin?: string;
    description?: string;
    updated?: boolean;
    change_reason?: string | null;
    data?: { status?: string; workflow_state?: string };
};

export function scheduleOnDay(item: Schedule, day: Date): boolean {
    const key = format(day, 'yyyy-MM-dd');
    const start = item.start.slice(0, 10);
    let end = item.end?.slice(0, 10) ?? start;
    if (end > start && item.end && /[ T]00:00(?::00)?(?:Z|[+-]\d{2}:\d{2})?$/.test(item.end)) end = format(addDays(parseISO(end), -1), 'yyyy-MM-dd');
    const state = (item.data?.status ?? '').toLowerCase();
    return !['cancelled', 'canceled', 'rejected'].includes(state) && start <= key && end >= key;
}

export default function StudentSchedulesWidget({ onTodayCount }: { onTodayCount?: (count: number) => void }) {
    const [selected, setSelected] = useState(() => new Date());
    const [month, setMonth] = useState(() => startOfMonth(new Date()));
    const [events, setEvents] = useState<Schedule[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const monthKey = format(month, 'yyyy-MM');

    useEffect(() => {
        const controller = new AbortController();
        const first = startOfWeek(parseISO(`${monthKey}-01`));
        const last = endOfWeek(endOfMonth(parseISO(`${monthKey}-01`)));
        async function load() {
            setLoading(true);
            setError('');
            try {
                const params = new URLSearchParams({ from: format(first, 'yyyy-MM-dd'), to: format(last, 'yyyy-MM-dd') });
                const response = await fetch(`/api/calendar/events?${params}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error('Schedules unavailable');
                const data: unknown = await response.json();
                if (
                    !Array.isArray(data) ||
                    !data.every((item) => item && typeof item.id === 'string' && typeof item.start === 'string' && typeof item.title === 'string')
                )
                    throw new Error('Invalid schedule response');
                if (controller.signal.aborted) return;
                const rows = data as Schedule[];
                setEvents(rows);
                const today = format(new Date(), 'yyyy-MM-dd');
                if (today >= format(first, 'yyyy-MM-dd') && today <= format(last, 'yyyy-MM-dd'))
                    onTodayCount?.(rows.filter((item) => scheduleOnDay(item, new Date())).length);
            } catch {
                if (!controller.signal.aborted) {
                    setEvents([]);
                    setError('Unable to load schedules. Please try again.');
                }
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }
        void load();
        return () => controller.abort();
    }, [monthKey, onTodayCount, retry]);

    useEffect(() => {
        const refresh = () => {
            if (document.visibilityState === 'visible') setRetry((value) => value + 1);
        };
        const timer = window.setInterval(refresh, 30000);
        window.addEventListener('focus', refresh);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            window.clearInterval(timer);
            window.removeEventListener('focus', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, []);

    const dayEvents = useMemo(
        () => events.filter((item) => scheduleOnDay(item, selected)).sort((a, b) => a.start.localeCompare(b.start) || a.title.localeCompare(b.title)),
        [events, selected],
    );
    function choose(date: Date) {
        setSelected(date);
        setMonth(startOfMonth(date));
    }
    function time(item: Schedule) {
        if (item.allDay) return 'All day';
        const start = parseISO(item.start);
        const end = item.end ? parseISO(item.end) : null;
        return `${format(start, 'h:mm a')}${end ? ` - ${format(end, 'h:mm a')}` : ''}`;
    }

    return (
        <section
            aria-label="Schedules"
            className="bg-card text-card-foreground grid w-full min-w-0 overflow-hidden rounded-xl lg:grid-cols-[minmax(0,1fr)_380px] dark:bg-zinc-900"
        >
            <div className="min-w-0 p-5 md:p-6">
                <div className="mb-3 flex items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold">Schedules</h2>
                    <Link
                        href="/schedules"
                        aria-label="View all schedules"
                        className="text-muted-foreground hover:text-primary flex items-center gap-2"
                    >
                        <span className="rounded-full bg-rose-500 p-2 text-white">
                            <CalendarDays className="h-5 w-5" />
                        </span>
                        <ChevronRight className="h-5 w-5" />
                    </Link>
                </div>
                <div className="border-border flex flex-wrap items-center justify-between gap-3 border-b pb-4">
                    <p className="text-muted-foreground text-sm">{format(selected, 'MMMM d, yyyy')}</p>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={() => choose(new Date())}>
                            Today
                        </Button>
                        <Button variant="outline" size="icon" aria-label="Previous day" onClick={() => choose(addDays(selected, -1))}>
                            <ChevronLeft className="h-4 w-4" />
                        </Button>
                        <Button variant="outline" size="icon" aria-label="Next day" onClick={() => choose(addDays(selected, 1))}>
                            <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
                <div aria-live="polite" className="min-h-64 py-5 lg:min-h-80">
                    {loading ? (
                        <p className="text-muted-foreground py-12 text-center text-sm">Loading schedules...</p>
                    ) : error ? (
                        <div className="space-y-3 py-12 text-center">
                            <p role="alert" className="text-destructive text-sm">
                                {error}
                            </p>
                            <Button variant="outline" size="sm" onClick={() => setRetry((value) => value + 1)}>
                                Try again
                            </Button>
                        </div>
                    ) : dayEvents.length === 0 ? (
                        <p className="text-muted-foreground py-12 text-center text-sm">No schedules for this day</p>
                    ) : (
                        <ul className="space-y-3">
                            {dayEvents.map((item) => (
                                <li key={item.id} className="border-border rounded-lg border p-4">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-medium break-words">{item.title}</p>
                                        {item.origin === 'comprehensive-exam' && item.updated && (
                                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                                Updated
                                            </span>
                                        )}
                                    </div>
                                    <p className="text-muted-foreground mt-2 flex items-center gap-2 text-xs">
                                        <Clock className="h-3.5 w-3.5" />
                                        {time(item)}
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        {item.origin === 'defense' ? 'Defense' : item.origin === 'comprehensive-exam' ? 'Comprehensive exam' : 'School event'}
                                    </p>
                                    {item.description && <p className="text-muted-foreground mt-2 text-sm break-words">{item.description}</p>}
                                    {item.origin === 'comprehensive-exam' && item.change_reason && (
                                        <p className="mt-2 text-sm font-medium text-amber-800 dark:text-amber-200">
                                            Reason for update: {item.change_reason}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
            <div className="border-border border-t p-5 lg:border-t-0 lg:border-l">
                <Calendar
                    mode="single"
                    required
                    selected={selected}
                    onSelect={choose}
                    month={month}
                    onMonthChange={setMonth}
                    className="w-full rounded-xl [--cell-size:2.5rem]"
                    classNames={{ root: 'w-full' }}
                    modifiers={{ scheduled: (date) => events.some((item) => scheduleOnDay(item, date)) }}
                    modifiersClassNames={{
                        scheduled:
                            'after:absolute after:bottom-1 after:left-1/2 after:h-1 after:w-1 after:-translate-x-1/2 after:rounded-full after:bg-rose-500',
                    }}
                />
                <p className="text-muted-foreground mt-3 text-center text-xs">A pink dot marks a scheduled day.</p>
            </div>
        </section>
    );
}
