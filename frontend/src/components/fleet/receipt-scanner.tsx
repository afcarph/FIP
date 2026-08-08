'use client';

import { Camera, Loader2, TriangleAlert } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useScanReceipt } from '@/hooks/use-api';
import { ApiError } from '@/lib/api-client';
import type { ReceiptScanResult } from '@/types/api';

interface ReceiptScannerProps {
  vehicleId?: number;
  onScanned: (result: ReceiptScanResult) => void;
}

/**
 * Photograph a receipt to pre-fill the fill-up form.
 *
 * The deliberate choice here is that scanning fills the form and stops. It does
 * not submit, and it never presents itself as having recorded anything — the
 * user still reads the figures and presses save, because OCR on faded thermal
 * paper is a suggestion and the expense ledger is financial.
 *
 * Confidence and warnings are surfaced rather than hidden. A scan the API has
 * flagged for review says so plainly; quietly filling the fields with a shaky
 * read is how a misplaced decimal point reaches the books.
 */
export function ReceiptScanner({ vehicleId, onScanned }: ReceiptScannerProps) {
  const inputRef = React.useRef<HTMLInputElement>(null);
  const scan = useScanReceipt();

  const result = scan.data;
  const error = scan.error instanceof ApiError ? scan.error : null;

  const handleFile = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0];
    if (!file) return;

    scan.mutate(
      { file, vehicleId },
      { onSuccess: onScanned },
    );

    // Reset so choosing the same file twice still fires a change event.
    event.target.value = '';
  };

  return (
    <div className="rounded-xl border border-dashed p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm font-medium">Scan a receipt</p>
          <p className="text-xs text-muted-foreground">
            Fills the form below. Nothing is saved until you press save.
          </p>
        </div>

        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => inputRef.current?.click()}
          disabled={scan.isPending}
        >
          {scan.isPending ? (
            <>
              <Loader2 className="animate-spin" aria-hidden="true" />
              Reading…
            </>
          ) : (
            <>
              <Camera aria-hidden="true" />
              Choose photo
            </>
          )}
        </Button>

        <input
          ref={inputRef}
          type="file"
          accept="image/*"
          // `capture` opens the camera directly on a phone, which is where a
          // receipt is actually photographed.
          capture="environment"
          className="sr-only"
          onChange={handleFile}
          aria-label="Receipt photo"
        />
      </div>

      <div role="status" aria-live="polite" className="empty:hidden">
        {error ? (
          <p className="mt-3 text-sm text-destructive">
            {error.status === 503
              ? 'The scanning service is unavailable right now — enter the figures manually.'
              : error.message}
          </p>
        ) : null}

        {result ? <ScanSummary result={result} /> : null}
      </div>
    </div>
  );
}

function ScanSummary({ result }: { result: ReceiptScanResult }) {
  const confidencePct = Math.round(result.confidence * 100);

  return (
    <div className="mt-3 space-y-2">
      <div className="flex flex-wrap items-center gap-2">
        <Badge variant={result.needs_review ? 'warning' : 'success'}>
          {result.needs_review ? 'Check the figures' : 'Read cleanly'}
        </Badge>
        <span className="tabular text-xs text-muted-foreground">{confidencePct}% confidence</span>
        {result.draft.station_hint ? (
          <Badge variant="outline">{result.draft.station_hint}</Badge>
        ) : null}
      </div>

      {result.warnings.length > 0 ? (
        <ul className="space-y-1">
          {result.warnings.map((warning) => (
            <li key={warning} className="flex items-start gap-1.5 text-xs text-muted-foreground">
              <TriangleAlert
                className="mt-0.5 size-3 shrink-0 text-amber-600 dark:text-amber-400"
                aria-hidden="true"
              />
              <span>{warning}</span>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
