'use client';

import {
  Bell,
  Bot,
  Building2,
  Car,
  ChevronLeft,
  ClipboardList,
  FileText,
  Fuel,
  IdCard,
  LayoutDashboard,
  LogOut,
  Map,
  Menu,
  Moon,
  Receipt,
  Route,
  Settings,
  Shield,
  ShieldCheck,
  Smartphone,
  Sun,
  TriangleAlert,
  TrendingUp,
  Users,
  Wrench,
  Truck,
  X,
} from 'lucide-react';
import { useTheme } from 'next-themes';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { useAuth } from '@/hooks/use-auth';
import { cn, initials } from '@/lib/utils';
import type { Role } from '@/types/api';

interface NavItem {
  href: string;
  label: string;
  icon: typeof LayoutDashboard;
  /** Omit to show for everyone signed in. */
  roles?: Role[];
}

/**
 * The dashboard, whichever one the signed-in user's role opens onto. Fleet
 * roles land on /fleet; a private motorist keeps the personal summary.
 */
const PRIMARY_NAV: NavItem[] = [
  { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
];

/**
 * Fleet Management: the operational work the product exists for, grouped so it
 * reads as one module rather than scattered top-level links.
 *
 * Vehicles and Expenses moved here from the flat primary list. They were always
 * fleet work; sitting beside Stations and Forecasts made the product read as a
 * fuel-price browser with fleet features bolted on.
 */
const FLEET_NAV: NavItem[] = [
  { href: '/fleet', label: 'Fleet overview', icon: Truck, roles: ['fleet_manager', 'company_manager', 'super_admin', 'system_admin'] },
  { href: '/vehicles', label: 'Vehicles', icon: Car },
  { href: '/fleet/drivers', label: 'Drivers', icon: IdCard, roles: ['fleet_manager', 'company_manager', 'super_admin', 'system_admin'] },
  { href: '/fleet/devices', label: 'Device health', icon: Smartphone, roles: ['fleet_manager', 'company_manager', 'super_admin', 'system_admin'] },
  // Deliberately not offered to viewers. Everything else on this list describes
  // the fleet; this one describes where identifiable people currently are, and
  // the API guards it with its own permission rather than with a role.
  { href: '/fleet/map', label: 'Fleet map', icon: Map, roles: ['fleet_manager', 'company_manager', 'super_admin', 'system_admin'] },
  { href: '/fleet/maintenance', label: 'Maintenance', icon: Wrench, roles: ['fleet_manager', 'company_manager', 'super_admin', 'system_admin'] },
  { href: '/expenses', label: 'Fuel & expenses', icon: Receipt },
  { href: '/fleet/alerts', label: 'Fuel alerts', icon: TriangleAlert, roles: ['fleet_manager', 'company_manager', 'super_admin', 'system_admin'] },
  { href: '/fleet/assignments', label: 'Assignments', icon: ClipboardList, roles: ['fleet_manager', 'company_manager', 'super_admin', 'system_admin'] },
  // Viewer included: read-only oversight extends to what the fleet is
  // committed to, the same way it already covers vehicles and alerts.
  { href: '/fleet/trips', label: 'Trips & dispatch', icon: Route, roles: ['fleet_manager', 'company_manager', 'viewer', 'super_admin', 'system_admin'] },
  { href: '/fleet/reports', label: 'Reports', icon: FileText, roles: ['fleet_manager', 'company_manager', 'super_admin', 'system_admin'] },
];

/**
 * Fuel intelligence: the market-facing side. Kept, because it is working
 * functionality people use, but no longer competing with fleet operations for
 * the top of the sidebar.
 */
const INSIGHT_NAV: NavItem[] = [
  { href: '/map', label: 'Station map', icon: Map },
  { href: '/stations', label: 'Stations', icon: Fuel },
  { href: '/forecasts', label: 'Price forecasts', icon: TrendingUp },
  { href: '/assistant', label: 'AI Advisor', icon: Bot },
];

const ADMIN_NAV: NavItem[] = [
  { href: '/admin', label: 'Admin console', icon: Shield, roles: ['super_admin', 'system_admin'] },
  {
    href: '/admin/companies',
    label: 'Companies',
    icon: Building2,
    roles: ['super_admin', 'system_admin'],
  },
  {
    href: '/admin/users',
    label: 'Users',
    icon: Users,
    // Wider than the rest of this group: a company manager administers their
    // own people, and the listing is tenant-scoped by the API.
    roles: ['company_manager', 'super_admin', 'system_admin'],
  },
  {
    href: '/admin/settings',
    label: 'Privacy & retention',
    icon: ShieldCheck,
    roles: ['super_admin', 'system_admin'],
  },
];

/**
 * Application chrome: a persistent sidebar on desktop, an off-canvas drawer on
 * mobile. Navigation is filtered by role so a driver never sees a fleet link
 * they cannot open — a menu item that 403s is worse than no menu item.
 */
export function AppShell({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { user, hasRole, logout, isLoading } = useAuth();
  const [mobileOpen, setMobileOpen] = React.useState(false);
  const [collapsed, setCollapsed] = React.useState(false);

  // Any navigation closes the drawer; leaving it open over the new page is a
  // classic mobile annoyance.
  React.useEffect(() => setMobileOpen(false), [pathname]);

  const visible = React.useCallback(
    (items: NavItem[]) => items.filter((item) => !item.roles || hasRole(...item.roles)),
    [hasRole],
  );

  const sections = [
    { items: visible(PRIMARY_NAV), label: null },
    { items: visible(FLEET_NAV), label: 'Fleet Management' },
    { items: visible(INSIGHT_NAV), label: 'Fuel Intelligence' },
    { items: visible(ADMIN_NAV), label: 'Administration' },
  ].filter((section) => section.items.length > 0);

  return (
    <div className="flex min-h-screen bg-background">
      {/* Mobile scrim */}
      {mobileOpen ? (
        <div
          className="fixed inset-0 z-40 bg-background/80 backdrop-blur-sm lg:hidden"
          onClick={() => setMobileOpen(false)}
          aria-hidden="true"
        />
      ) : null}

      <aside
        className={cn(
          'fixed inset-y-0 left-0 z-50 flex flex-col border-r bg-card transition-all duration-200 lg:static lg:translate-x-0',
          collapsed ? 'w-[68px]' : 'w-64',
          mobileOpen ? 'translate-x-0' : '-translate-x-full',
        )}
        aria-label="Main navigation"
      >
        <div className="flex h-16 items-center justify-between border-b px-4">
          <Link href="/dashboard" className="flex items-center gap-2 overflow-hidden">
            <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
              <Fuel className="size-4" aria-hidden="true" />
            </div>
            {!collapsed ? <span className="truncate font-semibold">FIP</span> : null}
          </Link>

          <Button
            variant="ghost"
            size="icon-sm"
            className="lg:hidden"
            onClick={() => setMobileOpen(false)}
            aria-label="Close navigation"
          >
            <X aria-hidden="true" />
          </Button>
        </div>

        <nav className="scrollbar-thin flex-1 space-y-5 overflow-y-auto p-3">
          {sections.map((section, index) => (
            <div key={index} className="space-y-1">
              {section.label && !collapsed ? (
                <p className="px-3 py-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                  {section.label}
                </p>
              ) : null}

              {section.items.map((item) => {
                const active = pathname === item.href || pathname.startsWith(`${item.href}/`);

                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    aria-current={active ? 'page' : undefined}
                    title={collapsed ? item.label : undefined}
                    className={cn(
                      'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                      active
                        ? 'bg-primary/10 text-primary'
                        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                    )}
                  >
                    <item.icon className="size-4 shrink-0" aria-hidden="true" />
                    {!collapsed ? <span className="truncate">{item.label}</span> : null}
                  </Link>
                );
              })}
            </div>
          ))}
        </nav>

        <div className="border-t p-3">
          <Link
            href="/settings"
            className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
          >
            <Settings className="size-4 shrink-0" aria-hidden="true" />
            {!collapsed ? 'Settings' : null}
          </Link>

          <button
            type="button"
            onClick={logout}
            className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive"
          >
            <LogOut className="size-4 shrink-0" aria-hidden="true" />
            {!collapsed ? 'Sign out' : null}
          </button>
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b bg-background/80 px-4 backdrop-blur-md">
          <Button
            variant="ghost"
            size="icon-sm"
            className="lg:hidden"
            onClick={() => setMobileOpen(true)}
            aria-label="Open navigation"
          >
            <Menu aria-hidden="true" />
          </Button>

          <Button
            variant="ghost"
            size="icon-sm"
            className="hidden lg:inline-flex"
            onClick={() => setCollapsed((value) => !value)}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            <ChevronLeft className={cn('transition-transform', collapsed && 'rotate-180')} aria-hidden="true" />
          </Button>

          <div className="flex-1" />

          <ThemeToggle />

          <Button asChild variant="ghost" size="icon-sm" aria-label="Notifications">
            <Link href="/notifications" className="relative">
              <Bell aria-hidden="true" />
            </Link>
          </Button>

          <Link
            href="/settings"
            className="flex items-center gap-2 rounded-full py-1 pl-1 pr-3 transition-colors hover:bg-accent"
          >
            <div className="flex size-8 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
              {isLoading ? '…' : initials(user?.full_name)}
            </div>
            <span className="hidden text-sm font-medium sm:inline">{user?.first_name ?? 'Account'}</span>
          </Link>
        </header>

        <main id="main" className="flex-1 p-4 md:p-6">
          {children}
        </main>
      </div>
    </div>
  );
}

function ThemeToggle() {
  const { theme, setTheme } = useTheme();
  const [mounted, setMounted] = React.useState(false);

  // The server does not know the user's theme, so rendering the icon before
  // mount would produce a hydration mismatch.
  React.useEffect(() => setMounted(true), []);

  if (!mounted) {
    return <div className="size-8" aria-hidden="true" />;
  }

  return (
    <Button
      variant="ghost"
      size="icon-sm"
      onClick={() => setTheme(theme === 'dark' ? 'light' : 'dark')}
      aria-label={`Switch to ${theme === 'dark' ? 'light' : 'dark'} theme`}
    >
      {theme === 'dark' ? <Sun aria-hidden="true" /> : <Moon aria-hidden="true" />}
    </Button>
  );
}
