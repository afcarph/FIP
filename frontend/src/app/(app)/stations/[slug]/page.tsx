'use client';

import { BadgeCheck, Clock, MapPin, Navigation, Phone, Zap } from 'lucide-react';
import { useParams } from 'next/navigation';
import * as React from 'react';

import { DoeStationReference } from '@/components/doe/doe-station-reference';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useStation } from '@/hooks/use-api';

/**
 * One station.
 *
 * Two sections, deliberately not blended: what we know about the forecourt,
 * and what the Department of Energy published for its area. The second is
 * never presented as the first — see DoeStationReference for why the
 * distinction is load-bearing rather than pedantic.
 */
export default function StationDetailPage() {
  const params = useParams<{ slug: string }>();
  const { data: station, isLoading, error } = useStation(params.slug);

  if (isLoading) {
    return <p className="text-sm text-muted-foreground">Loading station…</p>;
  }

  if (error || !station) {
    return <p className="text-sm text-muted-foreground">That station could not be found.</p>;
  }

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">{station.name}</h1>
        <p className="text-sm text-muted-foreground">
          {station.brand?.name}
          {station.distance_km !== undefined ? ` · ${station.distance_km} km` : null}
        </p>
      </header>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Station information</CardTitle>
          </CardHeader>

          <CardContent className="space-y-3 text-sm">
            <p className="flex items-start gap-2">
              <MapPin className="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden />
              <span>
                {station.address.line}
                {station.address.city ? `, ${station.address.city}` : null}
                {station.address.region ? `, ${station.address.region}` : null}
              </span>
            </p>

            {station.phone ? (
              <p className="flex items-center gap-2">
                <Phone className="size-4 text-muted-foreground" aria-hidden />
                {station.phone}
              </p>
            ) : null}

            <div className="flex flex-wrap gap-2 pt-1">
              {station.is_verified ? (
                <Badge variant="secondary">
                  <BadgeCheck className="mr-1 size-3" aria-hidden />
                  Verified
                </Badge>
              ) : (
                <Badge variant="outline">Unverified</Badge>
              )}
              {station.is_24_hours ? (
                <Badge variant="outline">
                  <Clock className="mr-1 size-3" aria-hidden />
                  24 hours
                </Badge>
              ) : null}
              {station.has_ev_charging ? (
                <Badge variant="outline">
                  <Zap className="mr-1 size-3" aria-hidden />
                  EV charging
                </Badge>
              ) : null}
              {station.amenities?.map((amenity) => (
                <Badge key={amenity.id} variant="outline">
                  {amenity.name}
                </Badge>
              ))}
            </div>

            {/* Coordinates come from gas_stations and nowhere else, so this
                works whether or not the DOE has anything to say about the
                area. */}
            <Button asChild variant="outline" size="sm" className="mt-2">
              <a
                href={`https://www.google.com/maps/search/?api=1&query=${station.latitude},${station.longitude}`}
                target="_blank"
                rel="noreferrer noopener"
              >
                <Navigation aria-hidden />
                Show location
              </a>
            </Button>
          </CardContent>
        </Card>

        {station.doe_reference ? (
          <DoeStationReference reference={station.doe_reference} />
        ) : null}
      </div>
    </div>
  );
}
