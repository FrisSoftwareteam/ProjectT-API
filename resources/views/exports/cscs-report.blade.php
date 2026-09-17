<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 34px 38px; }
        body { color: #17233c; font-family: DejaVu Sans, sans-serif; font-size: 10px; line-height: 1.45; }
        h1 { color: #12356b; font-size: 21px; margin: 0 0 4px; }
        h2 { border-bottom: 1px solid #dbe3ef; color: #12356b; font-size: 13px; margin: 22px 0 8px; padding-bottom: 5px; }
        .subtitle { color: #637083; margin-bottom: 18px; }
        .meta { background: #f4f7fb; border: 1px solid #dce4ef; padding: 10px 12px; }
        .meta span { display: inline-block; margin-right: 24px; }
        table { border-collapse: collapse; margin-top: 7px; width: 100%; }
        th { background: #eaf0f8; color: #27466f; font-weight: bold; text-align: left; }
        th, td { border: 1px solid #dce4ef; padding: 6px 7px; vertical-align: top; }
        tr:nth-child(even) td { background: #fafbfd; }
        .pass { color: #087b4b; font-weight: bold; }
        .fail { color: #b42318; font-weight: bold; }
        .footer { color: #7a8697; font-size: 8px; margin-top: 20px; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="subtitle">Generated {{ now()->toDayDateTimeString() }}</div>

    <div class="meta">
        <span><strong>Batch:</strong> {{ $batch->business_reference ?: '#'.$batch->id }}</span>
        <span><strong>Status:</strong> {{ $batch->workflow_status }}</span>
        <span><strong>Register:</strong> {{ $batch->register?->name }}</span>
        <span><strong>Revision:</strong> {{ $batch->revision }}</span>
    </div>

    <h2>Batch summary</h2>
    <table>
        <thead><tr><th>Metric</th><th>Value</th></tr></thead>
        <tbody>
        @foreach($summary as $key => $value)
            @if(!is_array($value))
                <tr><td>{{ str($key)->replace('_', ' ')->title() }}</td><td>{{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</td></tr>
            @endif
        @endforeach
        </tbody>
    </table>

    @if($type === 'audit')
        <h2>Approval history</h2>
        <table>
            <thead><tr><th>Date</th><th>Decision</th><th>Step</th><th>Actor</th><th>Comment</th></tr></thead>
            <tbody>
            @forelse($approvals as $approval)
                <tr>
                    <td>{{ optional($approval->acted_at)->toDayDateTimeString() }}</td>
                    <td>{{ $approval->decision }}</td>
                    <td>{{ $approval->step_no ?: '—' }}</td>
                    <td>{{ $approval->actor?->name ?? $approval->actor?->full_name ?? $approval->actor?->email }}</td>
                    <td>{{ $approval->comment }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No approval actions recorded.</td></tr>
            @endforelse
            </tbody>
        </table>

        <h2>Activity timeline</h2>
        <table>
            <thead><tr><th>Date</th><th>Event</th><th>Status change</th><th>Actor</th><th>Comment</th></tr></thead>
            <tbody>
            @foreach($events as $event)
                <tr>
                    <td>{{ optional($event->created_at)->toDayDateTimeString() }}</td>
                    <td>{{ $event->event_type }}</td>
                    <td>{{ $event->from_status ?: '—' }} → {{ $event->to_status ?: '—' }}</td>
                    <td>{{ $event->actor?->name ?? $event->actor?->full_name ?? $event->actor?->email }}</td>
                    <td>{{ $event->comment }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @if($type === 'reconciliation' && !empty($verification))
        <h2>Post-posting verification</h2>
        <table>
            <thead><tr><th>Check</th><th>Result</th></tr></thead>
            <tbody>
            @foreach(($verification['checks'] ?? []) as $check => $passed)
                <tr>
                    <td>{{ str($check)->replace('_', ' ')->title() }}</td>
                    <td class="{{ $passed ? 'pass' : 'fail' }}">{{ $passed ? 'PASS' : 'FAIL' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">Project T · CSCS controlled reconciliation record · Batch #{{ $batch->id }}</div>
</body>
</html>
