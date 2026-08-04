'use client';

import { Bell, CheckCheck } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotifications,
} from '@/hooks/use-api';
import { formatRelative } from '@/lib/utils';
import type { AppNotification } from '@/types/api';

const PRIORITY_VARIANT = {
  urgent: 'destructive',
  high: 'warning',
  normal: 'secondary',
  low: 'outline',
} as const;

function NotificationRow({ notification }: { notification: AppNotification }) {
  const markRead = useMarkNotificationRead();
  const unread = notification.read_at === null;

  const body = (
    <div className="flex items-start gap-3">
      <span
        aria-hidden="true"
        className={`mt-1.5 size-2 shrink-0 rounded-full ${unread ? 'bg-primary' : 'bg-transparent'}`}
      />
      <div className="min-w-0 flex-1 space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className={`text-sm ${unread ? 'font-semibold' : 'font-medium'}`}>
            {notification.title}
          </p>
          {notification.priority !== 'normal' ? (
            <Badge variant={PRIORITY_VARIANT[notification.priority]}>{notification.priority}</Badge>
          ) : null}
        </div>
        <p className="text-sm text-muted-foreground">{notification.body}</p>
        <p className="text-xs text-muted-foreground">{formatRelative(notification.created_at)}</p>
      </div>
      {unread ? (
        <Button
          size="sm"
          variant="ghost"
          disabled={markRead.isPending}
          onClick={(event) => {
            // The row may be a link; marking read should not navigate.
            event.preventDefault();
            markRead.mutate(notification.id);
          }}
        >
          Mark read
        </Button>
      ) : null}
    </div>
  );

  return (
    <Card className={unread ? 'border-primary/30' : undefined}>
      <CardContent className="pt-6">
        {notification.action_url ? (
          <Link href={notification.action_url} className="block">
            {body}
          </Link>
        ) : (
          body
        )}
      </CardContent>
    </Card>
  );
}

export default function NotificationsPage() {
  const [unreadOnly, setUnreadOnly] = React.useState(false);
  const { data: notifications, isLoading } = useNotifications(unreadOnly);
  const markAll = useMarkAllNotificationsRead();

  const unreadCount = notifications?.filter((item) => item.read_at === null).length ?? 0;

  return (
    <div className="space-y-6">
      <header className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Notifications</h1>
          <p className="text-sm text-muted-foreground">
            Price movements, maintenance and anything else worth your attention
          </p>
        </div>

        <div className="flex gap-2">
          <Button
            size="sm"
            variant={unreadOnly ? 'default' : 'outline'}
            onClick={() => setUnreadOnly((value) => !value)}
          >
            {unreadOnly ? 'Showing unread' : 'Show unread only'}
          </Button>
          <Button
            size="sm"
            variant="outline"
            disabled={markAll.isPending || unreadCount === 0}
            onClick={() => markAll.mutate()}
          >
            <CheckCheck aria-hidden="true" />
            Mark all read
          </Button>
        </div>
      </header>

      {isLoading ? (
        <div className="space-y-3">
          {[0, 1, 2].map((key) => (
            <Skeleton key={key} className="h-24 w-full" />
          ))}
        </div>
      ) : !notifications?.length ? (
        <EmptyState
          icon={Bell}
          title={unreadOnly ? 'Nothing unread' : 'No notifications yet'}
          description={
            unreadOnly
              ? 'Everything here has been read.'
              : 'Price alerts and maintenance reminders will appear here.'
          }
        />
      ) : (
        <div className="space-y-3">
          {notifications.map((notification) => (
            <NotificationRow key={notification.id} notification={notification} />
          ))}
        </div>
      )}
    </div>
  );
}
