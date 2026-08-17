'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { api } from '@/lib/api-client';

/**
 * Platform settings held in the database rather than in a `.env`.
 *
 * Only privacy and retention live here today. The shape is deliberately not
 * flattened into a bag of key/value pairs — a retention period carries a status
 * and bounds that a generic settings form would throw away.
 */

export type RetentionStatus = 'approved' | 'provisional' | 'requires_review';

export interface LocationRetention {
  days: number;
  status: RetentionStatus;
  is_configured: boolean;
  minimum_days: number;
  maximum_days: number;
  fallback_days: number;
  requires_approval: boolean;
  stored_rows: number;
  rows_beyond_retention: number;
}

export interface PrivacySettings {
  location_retention: LocationRetention;
}

export const settingsKeys = {
  privacy: ['admin', 'settings', 'privacy'] as const,
};

export function usePrivacySettings() {
  return useQuery({
    queryKey: settingsKeys.privacy,
    queryFn: async () => (await api.get<PrivacySettings>('/admin/settings/privacy')).data,
  });
}

export function useUpdateLocationRetention() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (days: number) =>
      (await api.put<LocationRetention>('/admin/settings/privacy/location-retention', { days })).data,
    onSuccess: () => {
      // The row counts and the status both change with the period, so the whole
      // block is refetched rather than patched in place.
      queryClient.invalidateQueries({ queryKey: settingsKeys.privacy });
    },
  });
}
