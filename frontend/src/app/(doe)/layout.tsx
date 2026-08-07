'use client';

import { Activity, BarChart3, History, Search } from 'lucide-react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Shell for the DOE price monitoring section.
 *
 * Deliberately outside the authenticated `(app)` group. Every `/fuel/*`
 * endpoint is public — these are the department's own published figures — so
 * gating the UI behind a login would add friction that the API itself does not
 * impose, and would make UAT need an account before anyone can look at
 * anything.
 */

const NAV = [
  { href: '/doe', label: 'Dashboard', icon: BarChart3 },
  { href: '/doe/search', label: 'Search', icon: Search },
  { href: '/doe/history', label: 'History', icon: History },
  { href: '/doe/status', label: 'API Status', icon: Activity },
];

export default function DoeLayout({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();

  return (
    <div className="min-h-screen bg-slate-50 dark:bg-slate-950">
      <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
        <div className="mx-auto flex max-w-7xl flex-col gap-3 px-4 py-3 sm:px-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="flex items-center gap-3">
            <div className="flex size-9 items-center justify-center rounded-lg bg-emerald-600 text-sm font-bold text-white">
              FIP
            </div>
            <div>
              <p className="text-sm font-semibold text-slate-900 dark:text-slate-50">
                DOE Fuel Price Monitoring
              </p>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                Department of Energy weekly publications
              </p>
            </div>
          </div>

          {/* Horizontal on desktop, scrollable on a phone — the four
              destinations fit either way without a burger menu. */}
          <nav className="-mx-1 flex gap-1 overflow-x-auto pb-1 lg:mx-0 lg:pb-0">
            {NAV.map((item) => {
              const active = pathname === item.href;

              return (
                <Link
                  key={item.href}
                  href={item.href}
                  aria-current={active ? 'page' : undefined}
                  className={cn(
                    'flex shrink-0 items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors',
                    active
                      ? 'bg-emerald-600 text-white'
                      : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                  )}
                >
                  <item.icon className="size-4" aria-hidden />
                  {item.label}
                </Link>
              );
            })}
          </nav>
        </div>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-6 sm:px-6">{children}</main>
    </div>
  );
}
