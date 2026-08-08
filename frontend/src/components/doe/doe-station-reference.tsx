'use client';

import { ExternalLink, Info } from 'lucide-react';
import * as React from 'react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { DoeReference } from '@/types/api';

/**
 * The DOE's weekly monitoring, shown against a station.
 *
 * Kept visually and verbally separate from the station's own information,
 * because they are different claims with different evidence. A station price
 * is a reading somebody took at that forecourt. A DOE figure is the range the
 * department published for that brand across that whole municipality during a
 * week that has usually already ended.
 *
 * Two presentations, and the difference is not cosmetic:
 *
 *   matched   — the seven conditions in DoeStationReference held, so the
 *               figures are for this brand in this area. Headed "DOE Price
 *               Reference".
 *   unmatched — nothing can honestly be attached to this forecourt. Headed
 *               "DOE Regional Reference", with no prices and the reason said
 *               out loud.
 *
 * Neither is ever labelled a live or current station price.
 */

const REASONS: Record<string, string> = {
  station_not_verified:
    'This station has not been verified, so no monitoring data is associated with it.',
  region_cannot_be_uniquely_resolved:
    'This station’s region could not be resolved to a single DOE monitoring region.',
  no_doe_report_for_region:
    'The Department of Energy does not publish a monitoring report covering this station’s region.',
  report_has_no_valid_coverage_period:
    'The latest report for this region states no usable monitoring period.',
  area_not_monitored:
    'The Department of Energy did not monitor this municipality in its latest report for the region.',
  province_mismatch:
    'A monitored area shares this station’s name but sits in a different province, so it was not used.',
  brand_not_published_in_area:
    'The Department of Energy published no price for this brand in this municipality.',
  brand_has_no_doe_equivalent:
    'This brand does not appear in the Department of Energy’s monitoring.',
};

function peso(value: number | null): string {
  return value === null ? '—' : `₱${value.toFixed(2)}`;
}

function range(min: number | null, max: number | null): string {
  if (min === null && max === null) return '—';
  if (min !== null && max !== null && min !== max) return `${peso(min)} – ${peso(max)}`;

  return peso(min ?? max);
}

function Attribution({ reference }: { reference: DoeReference }) {
  const report = reference.report;

  return (
    <div className="mt-4 space-y-1 border-t pt-3 text-xs text-muted-foreground">
      <p>
        <span className="font-medium text-foreground">Source:</span>{' '}
        {reference.attribution.source}
      </p>
      {report ? (
        <p>
          <span className="font-medium text-foreground">Monitoring period:</span>{' '}
          {report.coverage_label}
        </p>
      ) : null}
      {report?.source_url ? (
        <p>
          <span className="font-medium text-foreground">Report:</span>{' '}
          <a
            href={report.source_url}
            target="_blank"
            rel="noreferrer noopener"
            className="inline-flex items-center gap-1 text-primary hover:underline"
          >
            Published PDF
            <ExternalLink className="size-3" aria-hidden />
          </a>
        </p>
      ) : null}
      <p>{reference.attribution.basis}</p>
    </div>
  );
}

export function DoeStationReference({ reference }: { reference: DoeReference }) {
  if (!reference.matched) {
    return (
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">DOE Regional Reference</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex items-start gap-2 text-sm text-muted-foreground">
            <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
            <p>
              {REASONS[reference.reason] ??
                'No Department of Energy monitoring can be associated with this station.'}
            </p>
          </div>
          <Attribution reference={reference} />
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">DOE Price Reference</CardTitle>
        <p className="text-sm text-muted-foreground">
          {reference.doe_region} · {reference.doe_area}
          {reference.brand ? ` · ${reference.brand}` : null}
        </p>
      </CardHeader>

      <CardContent>
        <dl className="divide-y text-sm">
          {reference.prices.map((price) => (
            <div key={price.product} className="flex items-baseline justify-between gap-4 py-2">
              <dt className="text-muted-foreground">{price.product}</dt>
              <dd className="font-medium tabular-nums">
                {range(price.min_price, price.max_price)}
              </dd>
            </div>
          ))}
        </dl>

        <Attribution reference={reference} />
      </CardContent>
    </Card>
  );
}
