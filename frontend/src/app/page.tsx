import { ArrowRight, Brain, Fuel, MapPin, ScanLine, TrendingDown, Truck } from 'lucide-react';
import Link from 'next/link';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

const FEATURES = [
  {
    icon: Brain,
    title: 'Weekly price forecasts',
    body: 'Know whether Tuesday brings an increase or a rollback — with the confidence level and the reasoning behind the call.',
  },
  {
    icon: MapPin,
    title: 'Cheapest station nearby',
    body: 'Live prices from operators, the DOE and the community, ranked by what the trip will actually cost you.',
  },
  {
    icon: ScanLine,
    title: 'Scan a price board',
    body: 'Photograph any station board and the prices are read, validated and shared with everyone else nearby.',
  },
  {
    icon: TrendingDown,
    title: 'Track every peso',
    body: 'Cost per kilometre, litres per month and where your consumption changed — computed from your own fill-ups.',
  },
  {
    icon: Truck,
    title: 'Fleet intelligence',
    body: 'Utilisation, driver efficiency, predictive maintenance and fuel-theft detection across your whole fleet.',
  },
  {
    icon: Fuel,
    title: 'Ask the advisor',
    body: '"Should I refuel today?" answered from your vehicles, your spend and this week\'s forecast.',
  },
];

export default function LandingPage() {
  return (
    <main id="main" className="relative overflow-hidden">
      {/* Ambient gradient — purely decorative, hidden from assistive tech. */}
      <div
        className="pointer-events-none absolute inset-x-0 top-0 h-[600px] bg-gradient-to-b from-primary/10 via-primary/5 to-transparent"
        aria-hidden="true"
      />

      <section className="container relative flex flex-col items-center py-20 text-center md:py-32">
        <div className="mb-6 inline-flex items-center gap-2 rounded-full border bg-card/60 px-4 py-1.5 text-xs font-medium backdrop-blur">
          <span className="relative flex size-2">
            <span className="absolute inline-flex size-full animate-pulse-ring rounded-full bg-primary opacity-75" />
            <span className="relative inline-flex size-2 rounded-full bg-primary" />
          </span>
          Live DOE prices, updated weekly
        </div>

        <h1 className="max-w-3xl text-balance text-4xl font-bold tracking-tight md:text-6xl">
          Know the fuel price{' '}
          <span className="bg-gradient-to-r from-primary to-chart-5 bg-clip-text text-transparent">
            before you drive there
          </span>
        </h1>

        <p className="mt-6 max-w-2xl text-pretty text-lg text-muted-foreground">
          Live prices from every major brand, AI forecasts of the weekly adjustment, and expense
          tracking that shows exactly where your fuel budget goes.
        </p>

        <div className="mt-9 flex flex-col gap-3 sm:flex-row">
          <Button asChild size="lg">
            <Link href="/register">
              Create a free account
              <ArrowRight aria-hidden="true" />
            </Link>
          </Button>
          <Button asChild size="lg" variant="outline">
            <Link href="/map">Browse prices without signing up</Link>
          </Button>
        </div>

        <p className="mt-4 text-xs text-muted-foreground">
          No card required · Prices for Luzon, Visayas and Mindanao
        </p>
      </section>

      <section className="container relative pb-24">
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {FEATURES.map(({ icon: Icon, title, body }) => (
            <Card key={title} glass interactive className="p-6">
              <CardContent className="p-0">
                <div className="mb-4 inline-flex rounded-lg bg-primary/10 p-2.5 text-primary">
                  <Icon className="size-5" aria-hidden="true" />
                </div>
                <h2 className="mb-2 font-semibold">{title}</h2>
                <p className="text-sm leading-relaxed text-muted-foreground">{body}</p>
              </CardContent>
            </Card>
          ))}
        </div>
      </section>
    </main>
  );
}
