import { Badge } from '@/components/ui/badge';
import type { TripStatus } from '@/types/api';

/**
 * One place that decides how a trip status looks and reads.
 *
 * `in_progress` is the one worth naming carefully: it is the only state in
 * which a vehicle is actually out, and the fleet dashboard counts it as
 * unavailable. Everything else is either planning or history.
 */
const TONE: Record<TripStatus, { label: string; variant: 'default' | 'secondary' | 'warning' | 'destructive' }> = {
  draft: { label: 'Draft', variant: 'secondary' },
  dispatched: { label: 'Dispatched', variant: 'warning' },
  in_progress: { label: 'In progress', variant: 'default' },
  completed: { label: 'Completed', variant: 'secondary' },
  cancelled: { label: 'Cancelled', variant: 'destructive' },
};

export function TripStatusBadge({ status }: { status: TripStatus }) {
  const tone = TONE[status] ?? { label: status, variant: 'secondary' as const };

  return <Badge variant={tone.variant}>{tone.label}</Badge>;
}
