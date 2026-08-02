import type { LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

interface EmptyStateProps {
  icon: LucideIcon;
  title: string;
  description?: string;
  action?: React.ReactNode;
  className?: string;
}

/**
 * Empty states carry weight in a data product: the first thing a new user sees
 * is usually nothing, so each one names the next useful action.
 */
export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
  return (
    <div className={cn('flex flex-col items-center justify-center px-6 py-14 text-center', className)}>
      <div className="mb-4 rounded-full bg-muted p-3.5">
        <Icon className="size-6 text-muted-foreground" aria-hidden="true" />
      </div>
      <h3 className="mb-1 text-base font-semibold">{title}</h3>
      {description ? (
        <p className="mb-5 max-w-sm text-sm text-muted-foreground">{description}</p>
      ) : null}
      {action}
    </div>
  );
}
