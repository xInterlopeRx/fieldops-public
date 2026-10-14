<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Billing Job Report {{ $workOrder->job_number }}</title>
    <style>
        @page { margin: 32px; }
        body { color: #17301f; font-family: DejaVu Sans, sans-serif; font-size: 12px; line-height: 1.45; }
        h1, h2, p { margin: 0; }
        h1 { color: #004d1a; font-size: 24px; }
        h2 { border-bottom: 1px solid #00b84a; color: #004d1a; font-size: 14px; margin: 24px 0 8px; padding-bottom: 4px; }
        h3 { color: #004d1a; font-size: 13px; margin: 0 0 4px; }
        .header { border-bottom: 3px solid #008033; padding-bottom: 12px; }
        .muted { color: #4b6351; }
        .meta { margin-top: 4px; }
        .grid { display: table; width: 100%; }
        .column { display: table-cell; vertical-align: top; width: 50%; }
        .label { color: #4b6351; font-size: 10px; font-weight: bold; letter-spacing: .08em; text-transform: uppercase; }
        .value { margin-bottom: 8px; }
        .visit { border-bottom: 1px solid #b8d9c0; padding: 10px 0; }
        .visit:last-child { border-bottom: 0; }
        table { border-collapse: collapse; margin-top: 8px; width: 100%; }
        th, td { border-bottom: 1px solid #d7e4d9; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { color: #4b6351; font-size: 10px; text-transform: uppercase; }
        .quantity { text-align: right; white-space: nowrap; }
        .footer { border-top: 1px solid #b8d9c0; color: #4b6351; font-size: 10px; margin-top: 28px; padding-top: 8px; }
    </style>
</head>
<body>
    @php($jobLabel = $workOrder->job_number ?? ('#'.$workOrder->id))
    <header class="header">
        <h1>Billing Job Report {{ $jobLabel }}</h1>
        <p class="meta">{{ $workOrder->title }}</p>
        <p class="meta muted">Generated {{ now()->format('F j, Y g:i A') }}</p>
    </header>

    <section>
        <h2>Customer and Site</h2>
        <div class="grid">
            <div class="column">
                <div class="label">Customer</div>
                <div class="value">{{ $workOrder->site?->customer?->name ?? 'Not provided' }}</div>
                <div class="label">Client ID</div>
                <div class="value">{{ $workOrder->site?->customer?->client_id ?? 'Not provided' }}</div>
            </div>
            <div class="column">
                <div class="label">Site</div>
                <div class="value">{{ $workOrder->site?->name ?? 'Not provided' }}</div>
                <div class="label">Address</div>
                <div class="value">{{ $workOrder->site?->address ?? 'Not provided' }}</div>
            </div>
        </div>
    </section>

    <section>
        <h2>Work Summary</h2>
        <div class="label">Job scope</div>
        <div class="value">{!! nl2br(e($workOrder->scope ?: 'No scope recorded.')) !!}</div>
        @if ($workOrder->client_completion_summary)
            <div class="label">Completion summary</div>
            <div class="value">{!! nl2br(e($workOrder->client_completion_summary)) !!}</div>
        @endif
    </section>

    <section>
        <h2>Visit Item Usage</h2>
        @php($orderedVisits = $workOrder->visits->sortBy(fn ($visit) => sprintf('%020d-%020d', $visit->scheduled_at?->timestamp ?? PHP_INT_MAX, $visit->id)))
        @php($hasItems = $orderedVisits->contains(fn ($visit) => $visit->jobItems->isNotEmpty()))
        @forelse ($orderedVisits as $visit)
            <div class="visit">
                <h3>{{ $visit->scheduled_at?->format('F j, Y') ?? 'Unscheduled' }} · {{ $visit->visit_type }}</h3>
                @if ($visit->jobItems->isNotEmpty())
                    <table>
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Description</th>
                                <th class="quantity">Quantity</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($visit->jobItems as $jobItem)
                                <tr>
                                    <td>{{ $jobItem->item_name_snapshot }}<br><span class="muted">QuickBooks Item {{ $jobItem->quickbooks_item_id }}</span></td>
                                    <td>{{ $jobItem->item_description_snapshot ?: '—' }}</td>
                                    <td class="quantity">{{ $jobItem->quantity }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="muted">No Item usage recorded for this Visit.</p>
                @endif
            </div>
        @empty
            <p class="muted">No Visits are recorded for this Job.</p>
        @endforelse
        @unless ($hasItems)
            <p class="muted">No QuickBooks Item quantities were recorded. Review the Job in FieldOps before billing.</p>
        @endunless
    </section>

    <p class="footer">Item identities and quantities only. Prices, costs, account references, and inventory levels are maintained in QuickBooks Online.</p>
</body>
</html>
