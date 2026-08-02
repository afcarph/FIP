'use client';

import { Bot, Info, Send, Sparkles, User } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useAskAssistant, useRefuelRecommendation } from '@/hooks/use-api';
import { useGeolocation } from '@/hooks/use-geolocation';
import { cn, formatCurrency } from '@/lib/utils';

interface Turn {
  role: 'user' | 'assistant';
  content: string;
  sources?: string[];
}

const STARTERS = [
  'Should I refuel today?',
  'Where is the cheapest diesel near me?',
  'Why did my fuel consumption increase?',
  'How much did I spend on fuel last month?',
];

export default function AssistantPage() {
  const { latitude, longitude } = useGeolocation();
  const ask = useAskAssistant();
  const { data: recommendation } = useRefuelRecommendation();

  const [turns, setTurns] = React.useState<Turn[]>([]);
  const [input, setInput] = React.useState('');
  const [sessionId, setSessionId] = React.useState<number | undefined>();
  const [suggestions, setSuggestions] = React.useState<string[]>(STARTERS);

  const endRef = React.useRef<HTMLDivElement>(null);

  React.useEffect(() => {
    endRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [turns, ask.isPending]);

  const submit = (question: string) => {
    const trimmed = question.trim();
    if (!trimmed || ask.isPending) return;

    setTurns((previous) => [...previous, { role: 'user', content: trimmed }]);
    setInput('');

    ask.mutate(
      { question: trimmed, session_id: sessionId, latitude, longitude },
      {
        onSuccess: (reply) => {
          setSessionId(reply.session_id);
          setSuggestions(reply.suggestions?.length ? reply.suggestions : STARTERS);
          setTurns((previous) => [
            ...previous,
            { role: 'assistant', content: reply.answer, sources: reply.sources },
          ]);
        },
        onError: (error) => {
          setTurns((previous) => [
            ...previous,
            {
              role: 'assistant',
              content:
                error instanceof Error
                  ? `I could not answer that: ${error.message}`
                  : 'Something went wrong. Please try again.',
            },
          ]);
        },
      },
    );
  };

  return (
    <div className="mx-auto flex h-[calc(100vh-8rem)] max-w-3xl flex-col">
      <header className="mb-4">
        <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
          <Sparkles className="size-5 text-primary" aria-hidden="true" />
          AI Fuel Advisor
        </h1>
        <p className="text-sm text-muted-foreground">
          Answers drawn from your vehicles, your spend and this week&rsquo;s forecast
        </p>
      </header>

      {/* Standing recommendation, shown before any question is asked. */}
      {recommendation && turns.length === 0 ? (
        <Card glass className="mb-4 border-primary/20">
          <CardContent className="flex items-start gap-3 p-4">
            <div className="rounded-lg bg-primary/10 p-2 text-primary">
              <Info className="size-4" aria-hidden="true" />
            </div>
            <div>
              <p className="text-sm font-medium">{recommendation.headline}</p>
              {recommendation.estimated_impact ? (
                <p className="mt-0.5 text-xs text-muted-foreground">
                  Roughly {formatCurrency(recommendation.estimated_impact)} on a full tank.
                </p>
              ) : null}
            </div>
          </CardContent>
        </Card>
      ) : null}

      <div className="scrollbar-thin flex-1 space-y-4 overflow-y-auto pr-1">
        {turns.length === 0 ? (
          <div className="flex h-full flex-col items-center justify-center text-center">
            <div className="mb-4 rounded-full bg-primary/10 p-4">
              <Bot className="size-7 text-primary" aria-hidden="true" />
            </div>
            <h2 className="mb-1 font-semibold">Ask me anything about fuel</h2>
            <p className="mb-6 max-w-sm text-sm text-muted-foreground">
              I work from your own data — your vehicles, your fill-ups and live prices around you.
            </p>
          </div>
        ) : (
          turns.map((turn, index) => (
            <div
              key={index}
              className={cn('flex gap-3', turn.role === 'user' && 'flex-row-reverse')}
            >
              <div
                className={cn(
                  'flex size-8 shrink-0 items-center justify-center rounded-full',
                  turn.role === 'user' ? 'bg-secondary' : 'bg-primary/10 text-primary',
                )}
                aria-hidden="true"
              >
                {turn.role === 'user' ? <User className="size-4" /> : <Bot className="size-4" />}
              </div>

              <div className={cn('max-w-[80%]', turn.role === 'user' && 'text-right')}>
                <div
                  className={cn(
                    'inline-block rounded-2xl px-4 py-2.5 text-sm leading-relaxed',
                    turn.role === 'user'
                      ? 'bg-primary text-primary-foreground'
                      : 'bg-muted text-foreground',
                  )}
                >
                  {turn.content}
                </div>

                {/* Naming the sources is what makes the answer checkable. */}
                {turn.sources?.length ? (
                  <p className="mt-1.5 text-xs text-muted-foreground">
                    Based on: {turn.sources.join(' · ')}
                  </p>
                ) : null}
              </div>
            </div>
          ))
        )}

        {ask.isPending ? (
          <div className="flex gap-3">
            <div className="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
              <Bot className="size-4" aria-hidden="true" />
            </div>
            <div className="flex items-center gap-1 rounded-2xl bg-muted px-4 py-3">
              {[0, 150, 300].map((delay) => (
                <span
                  key={delay}
                  className="size-1.5 animate-pulse rounded-full bg-muted-foreground"
                  style={{ animationDelay: `${delay}ms` }}
                />
              ))}
              <span className="sr-only">Thinking</span>
            </div>
          </div>
        ) : null}

        <div ref={endRef} />
      </div>

      <div className="mt-4 space-y-3">
        {suggestions.length > 0 && !ask.isPending ? (
          <div className="flex flex-wrap gap-2">
            {suggestions.slice(0, 4).map((suggestion) => (
              <button
                key={suggestion}
                type="button"
                onClick={() => submit(suggestion)}
                className="rounded-full border px-3 py-1.5 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
              >
                {suggestion}
              </button>
            ))}
          </div>
        ) : null}

        <form
          onSubmit={(event) => {
            event.preventDefault();
            submit(input);
          }}
          className="flex gap-2"
        >
          <Input
            value={input}
            onChange={(event) => setInput(event.target.value)}
            placeholder="Ask about prices, savings or your vehicles…"
            aria-label="Your question"
            disabled={ask.isPending}
          />
          <Button type="submit" size="icon" disabled={!input.trim() || ask.isPending} aria-label="Send">
            <Send aria-hidden="true" />
          </Button>
        </form>
      </div>
    </div>
  );
}
