import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { MainNavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { CalendarFold, CreditCard, DollarSign, FileText, GraduationCap, LayoutGrid, LibraryBig, Send, Users } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLogo from './app-logo';

type PageProps = {
    auth: {
        user: {
            id: number;
            name: string;
            role: string;
        };
        is_adviser?: boolean; // <-- Add this
    };
};

const studentNavItems: MainNavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Submissions',
        href: '/submission',
        icon: Send,
        subItems: [
            { title: 'Comprehensive Exam', href: '/comprehensive-exam' },
            { title: 'Defense Requests', href: '/defense-requirements' },
        ],
    },
    {
        title: 'My Documents', // <-- Add this block
        href: '/student/documents',
        icon: FileText,
    },
    {
        title: 'Academic Records',
        href: '/academic-records',
        icon: LibraryBig,
    },
    {
        title: 'Payments',
        href: '/payment',
        icon: CreditCard,
    },
    {
        title: 'Schedules',
        href: '/schedule',
        icon: CalendarFold,
    },
];

const coordinatorNavItems: MainNavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
    {
        title: 'Thesis & Dissertations',
        href: '/coordinator/defense-requests',
        icon: GraduationCap,
        subItems: [
            { title: 'Defense Requests', href: '/coordinator/defense-requests' },
            { title: 'Panel Assignment', href: '/panelists' },
            { title: 'Adviser List', href: '/coordinator/adviser-list' },
        ],
    },
    {
        title: 'Applications',
        href: '/defense',
        icon: FileText,
        subItems: [
            { title: 'Comprehensive Exams', href: '/coordinator/compre-exam' },
            { title: 'Payment Receipt', href: '/coordinator/compre-payment' },
        ],
    },
    { title: 'Student Records', href: '/student-records', icon: Users },
    { title: 'Payment Rates', href: '/dean/payment-rates', icon: DollarSign },
    { title: 'Schedules', href: '/schedules', icon: CalendarFold },
];

const assistantNavItems: MainNavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
    {
        title: 'Applications',
        href: '/coordinator/compre-exam',
        icon: FileText,
        subItems: [
            { title: 'Comprehensive Exams', href: '/coordinator/compre-exam' },
            { title: 'Exam Payment Receipts', href: '/coordinator/compre-payment' },
        ],
    },
];

const adviserNavItems: MainNavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
    { title: 'Schedules', href: '/schedules', icon: CalendarFold },
];

const deanNavItems: MainNavItem[] = [
    { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
    { title: 'Defense Requests', href: '/dean/defense-requests', icon: FileText },
    {
        title: 'Comprehensive Exams',
        href: '/dean/responses',
        icon: FileText,
        subItems: [{ title: 'Application', href: '/dean/responses' }],
    },
    { title: 'Schedules', href: '/schedules', icon: CalendarFold },
    { title: 'Payment Rates', href: '/dean/payment-rates', icon: DollarSign },
    { title: 'Document Templates', href: '/settings/documents', icon: FileText },
];

const registrarNavItems: MainNavItem[] = [{ title: 'Dashboard', href: '/dashboard', icon: LayoutGrid }];
const proctorNavItems: MainNavItem[] = [{ title: 'Dashboard', href: '/dashboard', icon: LayoutGrid }];

function getNavItems(role: string): MainNavItem[] {
    let roleNavItems: MainNavItem[];

    if (role === 'Super Admin' || role === 'SuperAdmin') {
        roleNavItems = [{ title: 'Dashboard', href: '/dashboard', icon: LayoutGrid }];
    } else if (role === 'Coordinator') {
        roleNavItems = coordinatorNavItems;
    } else if (role === 'Dean') {
        roleNavItems = deanNavItems;
    } else if (role === 'Registrar') {
        roleNavItems = registrarNavItems;
    } else if (role === 'Proctor') {
        roleNavItems = proctorNavItems;
    } else if (role === 'Administrative Assistant') {
        roleNavItems = assistantNavItems;
    } else if (role === 'Adviser') {
        roleNavItems = adviserNavItems;
    } else if (['Finance', 'Finance Office', 'Finance Officer'].includes(role)) {
        roleNavItems = [{ title: 'Dashboard', href: '/dashboard', icon: LayoutGrid }];
    } else {
        roleNavItems = studentNavItems;
    }

    const additionalItems: MainNavItem[] = [];

    if (['Dean', 'Super Admin', 'SuperAdmin', 'Finance', 'Finance Office', 'Finance Officer', 'Adviser', 'Coordinator'].includes(role)) {
        additionalItems.push(
            { title: 'Honorarium Tracking', href: '/honorarium-workspace', icon: DollarSign },
            { title: 'Honorarium Payments', href: '/honorarium-ledger', icon: DollarSign },
            { title: 'Defense Register', href: '/defense-register', icon: CalendarFold },
        );
        if (['Dean', 'Super Admin', 'SuperAdmin', 'Finance', 'Finance Office', 'Finance Officer'].includes(role)) {
            additionalItems.push({ title: 'Finance Documents', href: '/honorarium-documents', icon: FileText });
        }
    }

    if (['Coordinator', 'Adviser', 'Dean'].includes(role)) {
        additionalItems.push({ title: 'Academic Progress', href: '/academic-progress', icon: Users });
    }
    if (role === 'Student') {
        additionalItems.push({ title: 'Certificates', href: '/certificates', icon: FileText });
    }

    const items = [...roleNavItems, ...additionalItems];
    const seenHrefs = new Set<string>();

    return items.filter((item) => {
        if (seenHrefs.has(item.href)) return false;
        seenHrefs.add(item.href);
        return true;
    });
}

export function AppSidebar() {
    const {
        auth: { user, is_adviser },
    } = usePage<PageProps>().props;

    const navItems = getNavItems(user.role);

    // Expanded menus state
    const [expandedMenus, setExpandedMenus] = useState<string[]>(() => {
        try {
            const saved = localStorage.getItem('sidebar.expandedMenus');
            return saved ? JSON.parse(saved) : [];
        } catch {
            return [];
        }
    });

    useEffect(() => {
        localStorage.setItem('sidebar.expandedMenus', JSON.stringify(expandedMenus));
    }, [expandedMenus]);

    // Show "Others" section for Adviser, Coordinator, Dean, or actual Adviser
    const showOthers = user.role === 'Adviser' || Boolean(is_adviser); // <-- Only show if user is an actual adviser

    // Items for the Others section
    const othersNavItems: MainNavItem[] = [
        {
            title: 'My Students',
            href: '/adviser/students-list',
            icon: Users,
        },
        {
            title: 'Defense Requests',
            href: '/all-defense-requirements',
            icon: GraduationCap,
        },
    ];

    return (
        <Sidebar collapsible="offcanvas" className="px-3 pt-5" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="xl" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} expandedMenus={expandedMenus} setExpandedMenus={setExpandedMenus} />
                {showOthers && (
                    <div className="mb-2">
                        <NavMain items={othersNavItems} expandedMenus={expandedMenus} setExpandedMenus={setExpandedMenus} sectionTitle="Others" />
                    </div>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={[]} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
