import { cn } from '@/lib/utils';

/** Loading placeholder. `aria-hidden` so screen readers skip the shimmer. */
export function Skeleton({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div aria-hidden="true" className={cn('skeleton rounded-md', className)} {...props} />;
}

export function SkeletonCard() {
  return (
    <div className="space-y-3 rounded-xl border bg-card p-5">
      <Skeleton className="h-4 w-24" />
      <Skeleton className="h-8 w-32" />
      <Skeleton className="h-3 w-40" />
    </div>
  );
}

export function SkeletonChart({ height = 300 }: { height?: number }) {
  return (
    <div className="rounded-xl border bg-card p-5">
      <Skeleton className="mb-4 h-4 w-40" />
      <Skeleton className="w-full" style={{ height }} />
    </div>
  );
}
