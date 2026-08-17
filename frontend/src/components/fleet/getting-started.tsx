'use client';

import { ArrowRight, Check, Circle } from 'lucide-react';
import Link from 'next/link';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useOnboarding } from '@/hooks/use-api';
import { cn } from '@/lib/utils';
import type { OnboardingStep } from '@/types/api';

/**
 * What to do first.
 *
 * A company that has just registered lands on a dashboard where every figure
 * is zero — accurate, and no help at all. It says what is true without saying
 * what to do, and everything the product is for sits behind a few steps nobody
 * has mentioned.
 *
 * Three things this deliberately does.
 *
 * It shows one step at a time as the next thing, rather than five buttons of
 * equal weight. The order is what the product actually requires — a driver
 * cannot be assigned to a vehicle that does not exist — so the next step is
 * always one that can be done now.
 *
 * It disappears on its own. Progress is derived from real records, so finishing
 * the work is what removes this, not a button somebody has to find. Nothing
 * congratulates a company for a vehicle it has since deleted either: delete it
 * and the step comes back, because the step was never a stored tick.
 *
 * And it can be put away. A fleet with no smartphones will never complete the
 * device step, and a checklist that cannot be dismissed becomes furniture.
 * That preference lives in this browser rather than on the company: it is a
 * display choice by one person, not a fact about the tenant, and storing it
 * server-side would hide the list from colleagues who have not seen it.
 */

const DISMISSED_KEY = 'fip.onboarding_dismissed';

function StepRow({ step, isNext }: { step: OnboardingStep; isNext: boolean }) {
  return (
    <li className="flex items-start gap-3">
      <span
        className={cn(
          'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border',
          step.done
            ? 'border-emerald-600/40 bg-emerald-600 text-white'
            : 'border-border text-muted-foreground',
        )}
        aria-hidden
      >
        {step.done ? <Check className="size-3" /> : <Circle className="size-2 fill-current" />}
      </span>

      <div className="min-w-0 flex-1">
        <p className={cn('text-sm font-medium', step.done && 'text-muted-foreground line-through')}>
          {step.title}
        </p>

        {/* Only the step being asked for explains itself. The rest are a list
            of what is left, and five paragraphs is not a list. */}
        {isNext ? (
          <p className="mt-0.5 text-sm text-muted-foreground">{step.description}</p>
        ) : null}
      </div>

      {isNext ? (
        <Button asChild size="sm" className="shrink-0">
          <Link href={step.href}>
            {step.action}
            <ArrowRight aria-hidden />
          </Link>
        </Button>
      ) : null}
    </li>
  );
}

export function GettingStarted() {
  const { data } = useOnboarding();
  const [dismissed, setDismissed] = React.useState(true);

  // Read after mount: localStorage does not exist while this renders on the
  // server, and assuming "not dismissed" would flash the card at somebody who
  // put it away.
  React.useEffect(() => {
    setDismissed(window.localStorage.getItem(DISMISSED_KEY) === 'true');
  }, []);

  if (dismissed) return null;
  if (!data?.applies || !data.steps || data.is_complete) return null;

  const next = data.steps.find((step) => !step.done);

  return (
    <Card className="border-primary/30 bg-primary/[0.03]">
      <CardHeader>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <CardTitle className="text-base">Getting started</CardTitle>
            <CardDescription>
              {data.completed} of {data.total} done — this disappears once your fleet is set up.
            </CardDescription>
          </div>

          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              window.localStorage.setItem(DISMISSED_KEY, 'true');
              setDismissed(true);
            }}
          >
            Hide
          </Button>
        </div>
      </CardHeader>

      <CardContent>
        <ul className="space-y-3">
          {data.steps.map((step) => (
            <StepRow key={step.key} step={step} isNext={step.key === next?.key} />
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
