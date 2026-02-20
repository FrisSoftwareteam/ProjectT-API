<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Dividend Summary</title>
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #111; font-size: 12px; }
        h1 { font-size: 18px; margin: 0 0 8px; }
        h2 { font-size: 14px; margin: 16px 0 6px; }
        .meta { margin-bottom: 12px; }
        .meta div { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f4f4f4; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Dividend Summary</h1>

    <div class="meta">
        <div><strong>Company:</strong> {{ $declaration->register->company->name ?? 'N/A' }}</div>
        <div><strong>Register:</strong> {{ $declaration->register->name ?? 'N/A' }}</div>
        <div><strong>Period:</strong> {{ $declaration->period_label }}</div>
        <div><strong>Rate Per Share:</strong> {{ number_format((float) $declaration->rate_per_share, 6) }}</div>
        <div><strong>Record Date:</strong> {{ $declaration->record_date?->format('Y-m-d') ?? '' }}</div>
        <div><strong>Payment Date:</strong> {{ $declaration->payment_date?->format('Y-m-d') ?? '' }}</div>
    </div>

    <h2>Totals</h2>
    <table>
        <tbody>
            <tr>
                <th>Total Gross Amount</th>
                <td class="right">{{ number_format((float) $declaration->total_gross_amount, 2) }}</td>
            </tr>
            <tr>
                <th>Total Tax Amount</th>
                <td class="right">{{ number_format((float) $declaration->total_tax_amount, 2) }}</td>
            </tr>
            <tr>
                <th>Total Net Amount</th>
                <td class="right">{{ number_format((float) $declaration->total_net_amount, 2) }}</td>
            </tr>
            <tr>
                <th>Rounding Residue</th>
                <td class="right">{{ number_format((float) $declaration->rounding_residue, 6) }}</td>
            </tr>
            <tr>
                <th>Eligible Shareholders</th>
                <td class="right">{{ $declaration->eligible_shareholders_count ?? 0 }}</td>
            </tr>
        </tbody>
    </table>

    <h2>By Share Class</h2>
    <table>
        <thead>
            <tr>
                <th>Share Class</th>
                <th class="right">Total Shares</th>
                <th class="right">Gross Amount</th>
                <th class="right">Tax Amount</th>
                <th class="right">Net Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($byShareClass as $row)
                <tr>
                    <td>{{ $row->shareClass?->class_code ?? $row->share_class_id }}</td>
                    <td class="right">{{ number_format((float) $row->total_shares, 6) }}</td>
                    <td class="right">{{ number_format((float) $row->gross_amount, 2) }}</td>
                    <td class="right">{{ number_format((float) $row->tax_amount, 2) }}</td>
                    <td class="right">{{ number_format((float) $row->net_amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
