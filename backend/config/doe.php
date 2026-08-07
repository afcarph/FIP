<?php

declare(strict_types=1);

/**
 * Where the DOE ingest keeps things, from the API's point of view.
 *
 * The ingest is a separate Python service with its own settings; these are the
 * few values the API needs so its health checks describe the same installation
 * the ingest is writing to. Deployment sets both from the same environment.
 */
return [
    /*
     * The PDF archive the ingest writes originals to. Reported by the health
     * endpoint so an operator can see the archive growing — and see it stop.
     */
    'pdf_archive_path' => env('DOE_PDF_ARCHIVE_PATH', storage_path('app/doe-pdfs')),

    /*
     * The CMS endpoint discovery reads. Shown on the system dashboard so the
     * source is visible without reading the ingest's configuration.
     */
    'graphql_endpoint' => env('DOE_GRAPHQL_ENDPOINT', 'https://prod-cms.doe.gov.ph/o/graphql'),

    /*
     * How long the ingest may go without a run before the scheduler is
     * considered dead. It runs daily, so a day and a bit: long enough to
     * survive a slow morning, short enough to notice a missed one.
     */
    'scheduler_stale_after_hours' => (int) env('DOE_SCHEDULER_STALE_AFTER_HOURS', 26),
];
