<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $dataset['title'] }}</title>
    <style>
        @page { margin: 18mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #0f172a; }
        .header { border-bottom: 2px solid #0f172a; padding-bottom: 8px; margin-bottom: 14px; }
        .header h1 { margin: 0 0 2px; font-size: 16px; }
        .header .meta { color: #64748b; font-size: 8px; }
        .summary { margin-bottom: 12px; }
        .summary table { border-collapse: collapse; }
        .summary td { padding: 3px 14px 3px 0; }
        .summary .label { color: #64748b; }
        .summary .value { font-weight: bold; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { background: #0f172a; color: #fff; text-align: left; padding: 5px 6px; font-size: 8px; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #e2e8f0; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; color: #94a3b8; font-size: 7px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $dataset['title'] }}</h1>
        <div class="meta">
            {{ config('app.name') }} &middot;
            Period {{ $run->period_start?->toFormattedDateString() }} – {{ $run->period_end?->toFormattedDateString() }} &middot;
            Generated {{ $generatedAt->toDayDateTimeString() }} &middot;
            Requested by {{ $run->requester?->full_name }}
        </div>
    </div>

    @if (! empty($dataset['summary']))
        <div class="summary">
            <table>
                <tr>
                    @foreach ($dataset['summary'] as $label => $value)
                        <td><span class="label">{{ $label }}:</span> <span class="value">{{ $value }}</span></td>
                    @endforeach
                </tr>
            </table>
        </div>
    @endif

    <table class="data">
        <thead>
            <tr>
                @foreach ($dataset['columns'] as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($dataset['rows'] as $row)
                <tr>
                    @foreach (array_keys($dataset['columns']) as $key)
                        <td>{{ $row[$key] ?? '—' }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($dataset['columns']) }}">No data for this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        {{ config('app.name') }} — confidential. Report ID {{ $run->id }}.
    </div>
</body>
</html>
