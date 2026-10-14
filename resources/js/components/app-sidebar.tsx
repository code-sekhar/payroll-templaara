import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BadgeCheck, BookOpen, BriefcaseBusiness, Building2, CalendarDays, Clock3, Folder, GitBranch, LayoutGrid, Network, ShieldCheck, Users, ClipboardCheck, Fingerprint, FileClock, CalendarCheck, ClipboardList, Bell } from 'lucide-react';
import AppLogo from './app-logo';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        url: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        url: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth, activeCompany, companyAccess } = usePage<import('@/types').SharedData>().props;
    const permissions = auth.user?.permissions ?? [];
    const mainNavItems: NavItem[] = [
        { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
        ...(companyAccess ? [{ title: 'Companies', url: '/companies', icon: Building2 }] : []),
        ...(activeCompany && permissions.includes('user.view') ? [{ title: 'Members', url: `/companies/${activeCompany.id}/members`, icon: Users }] : []),
        ...(activeCompany && permissions.includes('role.view') ? [{ title: 'Roles', url: `/companies/${activeCompany.id}/roles`, icon: ShieldCheck }] : []),
        ...(activeCompany && (permissions.includes('employee.view') || permissions.includes('employee.view_team')) ? [{ title: 'Employees', url: '/employees', icon: BriefcaseBusiness }] : []),
        ...(activeCompany && permissions.includes('branch.view') ? [{ title: 'Branches', url: '/branches', icon: GitBranch }] : []),
        ...(activeCompany && permissions.includes('department.view') ? [{ title: 'Departments', url: '/departments', icon: Network }] : []),
        ...(activeCompany && permissions.includes('designation.view') ? [{ title: 'Designations', url: '/designations', icon: BadgeCheck }] : []),
        ...(activeCompany && permissions.includes('shift.view') ? [{ title: 'Shifts', url: `/companies/${activeCompany.id}/shifts`, icon: Clock3 }] : []),
        ...(activeCompany && permissions.includes('shift_assignment.view') ? [{ title: 'Shift assignments', url: `/companies/${activeCompany.id}/shift-assignments`, icon: Clock3 }] : []),
        ...(activeCompany && permissions.includes('work_schedule.view') ? [{ title: 'Work schedules', url: `/companies/${activeCompany.id}/work-schedules`, icon: CalendarDays }] : []),
        ...(activeCompany && permissions.includes('work_schedule_assignment.view') ? [{ title: 'Employee schedule assignments', url: `/companies/${activeCompany.id}/work-schedule-assignments`, icon: CalendarDays }] : []),
        ...(activeCompany && permissions.includes('holiday_calendar.view') ? [{ title: 'Holiday calendars', url: `/companies/${activeCompany.id}/holiday-calendars`, icon: CalendarDays }] : []),
        ...(activeCompany && permissions.includes('holiday_calendar_assignment.view') ? [{ title: 'Employee holiday calendar assignments', url: `/companies/${activeCompany.id}/employee-holiday-calendar-assignments`, icon: CalendarDays }] : []),
        ...(activeCompany && permissions.includes('attendance_policy.view') ? [{ title: 'Attendance policies', url: `/companies/${activeCompany.id}/attendance-policies`, icon: ClipboardCheck }] : []),
        ...(activeCompany && permissions.includes('attendance_worksite.view') ? [{ title: 'Attendance worksites', url: `/companies/${activeCompany.id}/attendance-worksites`, icon: ClipboardCheck }] : []),
        ...(activeCompany && permissions.includes('attendance_policy_assignment.view') ? [{ title: 'Employee attendance policy assignments', url: `/companies/${activeCompany.id}/attendance-policy-assignments`, icon: ClipboardCheck }] : []),
        ...(activeCompany && permissions.includes('attendance_punch.view') ? [{ title: 'Attendance punches', url: `/companies/${activeCompany.id}/attendance-punches`, icon: Fingerprint }] : []),
        ...(activeCompany && (permissions.includes('attendance.view') || permissions.includes('attendance.view_team')) ? [{ title: 'Attendance', url: `/companies/${activeCompany.id}/attendance`, icon: CalendarCheck }] : []),
        ...(activeCompany && permissions.includes('attendance.self_view') ? [{ title: 'My Attendance', url: `/companies/${activeCompany.id}/my-attendance`, icon: CalendarCheck }] : []),
        ...(activeCompany && (permissions.includes('attendance_regularization.view') || permissions.includes('attendance_regularization.self_view')) ? [{ title: 'Attendance regularization', url: `/companies/${activeCompany.id}/attendance-regularizations`, icon: FileClock }] : []),
        ...(activeCompany && (permissions.includes('attendance_regularization.view') || permissions.includes('attendance_regularization.self_view'))
            ? [{ title: 'Notifications', url: '/notifications', icon: Bell }]
            : []),
        ...(activeCompany && permissions.includes('leave_type.view') ? [{ title: 'Leave Types', url: `/companies/${activeCompany.id}/leave-types`, icon: CalendarDays }] : []),
        ...(activeCompany && permissions.includes('leave_policy.view') ? [{ title: 'Leave Policies', url: `/companies/${activeCompany.id}/leave-policies`, icon: ClipboardList }] : []),
        ...(activeCompany && permissions.includes('leave_policy_assignment.view') ? [{ title: 'Employee Leave Policy Assignments', url: `/companies/${activeCompany.id}/leave-policy-assignments`, icon: ClipboardList }] : []),
        ...(activeCompany && permissions.includes('leave_balance.view') ? [{ title: 'Leave Balances', url: `/companies/${activeCompany.id}/leave-balances`, icon: ClipboardList }] : []),
        ...(activeCompany && permissions.includes('leave_request.view') ? [{ title: 'Leave Requests', url: `/companies/${activeCompany.id}/leave-requests`, icon: CalendarDays }] : []),
        ...(activeCompany && permissions.includes('leave_request.view') ? [{ title: 'Leave Approval Inbox', url: '/leave/approvals', icon: ClipboardCheck }] : []),
        ...(activeCompany && permissions.includes('leave_request.view') ? [{ title: 'Team Leave Calendar', url: '/leave/calendar', icon: CalendarDays }] : []),
        ...(activeCompany && permissions.includes('leave_report.view') ? [{ title: 'Leave Reports', url: `/companies/${activeCompany.id}/leave-reports`, icon: CalendarDays }] : []),
        ...(activeCompany && permissions.includes('leave_request.self_view') ? [{ title: 'My Leave', url: `/companies/${activeCompany.id}/my-leave`, icon: CalendarDays }] : []),
    ];
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
