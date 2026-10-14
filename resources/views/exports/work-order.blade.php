<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Work Order {{ $workOrder->id }}</title>
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
        .status { background: #aaffcc; border: 1px solid #00b84a; border-radius: 12px; display: inline-block; font-size: 10px; font-weight: bold; padding: 3px 8px; text-transform: uppercase; }
        .visit { border-bottom: 1px solid #b8d9c0; padding: 8px 0; }
        .visit:last-child { border-bottom: 0; }
        .visit-notes { margin: 3px 0 6px; white-space: pre-wrap; }
        .footer { border-top: 1px solid #b8d9c0; color: #4b6351; font-size: 10px; margin-top: 28px; padding-top: 8px; }
    </style>
</head>
<body>
    @php($documentHeading = ($completionReport ?? false) ? 'Completion Report' : 'Work Order')
    @php($jobLabel = $workOrder->job_number ?? ('#'.$workOrder->id))
    <header class="header">
        <h1>{{ $documentHeading }} {{ $jobLabel }}</h1>
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
        <h2>Work Details</h2>
        <div class="grid">
            <div class="column">
                <div class="label">Status</div>
                <div class="value"><span class="status">{{ $workOrder->status }}</span></div>
            </div>
            <div class="column">
                <div class="label">Created</div>
                <div class="value">{{ $workOrder->created_at?->format('F j, Y') }}</div>
            </div>
        </div>
        <div class="label">Description</div>
        <div class="value">{!! nl2br(e($workOrder->description ?: 'No description provided.')) !!}</div>
        <div class="label">Job scope</div>
        <div class="value">{!! nl2br(e($workOrder->scope ?: 'No scope recorded.')) !!}</div>
        @if ($workOrder->client_completion_summary)
            <div class="label">Client completion summary</div>
            <div class="value">{!! nl2br(e($workOrder->client_completion_summary)) !!}</div>
        @endif
        @if (($completionReport ?? false) ? $workOrder->field_notes : ($workOrder->job_notes || $workOrder->field_notes))
            <h2>Technical Notes</h2>
            @if (! ($completionReport ?? false) && $workOrder->job_notes)
                <div class="label">Job notes</div>
                <div class="value">{!! nl2br(e($workOrder->job_notes)) !!}</div>
            @endif
            @if ($workOrder->field_notes)
                <div class="label">Field notes</div>
                <div class="value">{!! nl2br(e($workOrder->field_notes)) !!}</div>
            @endif
        @endif
    </section>

    <section>
        <h2>Visits</h2>
        @php($orderedVisits = $workOrder->visits->sortBy(fn ($visit) => sprintf('%020d-%020d', $visit->scheduled_at?->timestamp ?? PHP_INT_MAX, $visit->id)) )
        @forelse ($orderedVisits as $visit)
            <div class="visit">
                @if ($completionReport ?? false)
                    <h3>{{ $visit->scheduled_at?->format('F j, Y') ?? 'Unscheduled' }}</h3>
                    <div class="visit-notes">{{ $visit->notes ?: 'No visit notes recorded.' }}</div>
                @else
                <strong>{{ $visit->scheduled_at?->format('F j, Y g:i A') ?? 'Unscheduled' }}</strong>
                @if ($visit->duration_minutes)
                    <span class="muted"> ({{ $visit->duration_minutes }} minutes)</span>
                @endif
                @endif
                @if ($visit->scope)
                    <div><strong>Visit scope:</strong> {!! nl2br(e($visit->scope)) !!}</div>
                @endif
                @if (! ($completionReport ?? false) && $visit->notes)
                    <div><strong>Visit notes:</strong> {!! nl2br(e($visit->notes)) !!}</div>
                @endif
                @if (! ($completionReport ?? false) && $visit->assignedUsers->isNotEmpty())
                    <div class="muted"><strong>Assigned technicians:</strong> {{ $visit->assignedUsers->pluck('name')->join(', ') }}</div>
                @endif
            </div>
        @empty
            <p class="muted">No visits recorded.</p>
        @endforelse
    </section>

    <section>
        <h2>Supporting Files</h2>
        @php($supportingAttachments = $workOrder->attachments->reject(fn ($attachment) => $attachment->disk === 'local' && str_starts_with($attachment->path, 'work-orders/reports/')))
        @forelse ($supportingAttachments as $attachment)
            <div class="value">{{ $attachment->original_name }} <span class="muted">({{ $attachment->mime_type }})</span></div>
        @empty
            <p class="muted">No supporting files were attached.</p>
        @endforelse
    </section>

    <p class="footer">FieldOps local work-order export</p>
</body>
</html>
