<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\WorkOrder;
use App\Services\FieldPulse\FieldPulseDocument;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Produce "our FieldPulse document" from the locally captured FieldPulse data.
 */
#[Signature('fieldpulse:export-document {path : Destination XLSX file path}')]
#[Description('Write the FieldPulse document (Customers/Locations/Jobs/Notes) from locally captured data.')]
class FieldPulseExportDocument extends Command
{
    public function handle(FieldPulseDocument $document): int
    {
        $path = (string) $this->argument('path');
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            $this->error('The destination directory is not writable: '.$directory);

            return self::FAILURE;
        }

        $data = [
            'Customers' => $this->customers(),
            'Locations' => $this->locations(),
            'Jobs' => $this->jobs(),
            'Notes' => $this->notes(),
        ];

        $document->write($path, $data);

        $this->info(sprintf(
            'Wrote document to %s (%d customers, %d locations, %d jobs, %d notes)',
            $path,
            count($data['Customers']),
            count($data['Locations']),
            count($data['Jobs']),
            count($data['Notes']),
        ));

        return self::SUCCESS;
    }

    /** @return list<array<string, mixed>> */
    private function customers(): array
    {
        $rows = [];

        $customers = Customer::withArchived()
            ->whereNotNull('fieldpulse_id')
            ->orderBy('id')
            ->get();

        foreach ($customers as $customer) {
            $fp = $customer->metadata['fieldpulse'] ?? [];
            $secondary = Contact::withArchived()
                ->where('customer_id', $customer->getKey())
                ->where('contact_type', 'secondary')
                ->first();

            $rows[] = [
                'fieldpulse_id' => $customer->fieldpulse_id,
                'display_name' => $customer->name,
                'company_name' => $customer->company_name,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'alt_phone' => $customer->alternate_phone,
                'mobile_phone' => $customer->mobile_phone,
                'fax' => $customer->fax,
                'website' => $customer->website,
                'address' => $customer->address,
                'notes' => $customer->notes,
                'account_type' => $fp['account_type'] ?? null,
                'status' => $fp['status'] ?? null,
                'lead_source' => $fp['lead_source'] ?? null,
                'assigned_to' => $fp['assigned_to'] ?? null,
                'created_at' => $fp['created_at'] ?? null,
                'secondary_first_name' => $secondary?->first_name,
                'secondary_last_name' => $secondary?->last_name,
                'secondary_email' => $secondary?->email,
                'secondary_phone' => $secondary?->phone,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function locations(): array
    {
        $rows = [];

        $sites = Site::withArchived()
            ->whereNotNull('fieldpulse_id')
            ->with('customer')
            ->orderBy('id')
            ->get();

        foreach ($sites as $site) {
            $fp = $site->metadata['fieldpulse'] ?? [];

            $rows[] = [
                'fieldpulse_id' => $site->fieldpulse_id,
                'customer_fieldpulse_id' => $site->customer?->fieldpulse_id,
                'title' => $site->name,
                'address' => $site->address,
                'is_main_location' => ($fp['is_main_location'] ?? false) ? 'yes' : '',
                'is_primary_location' => ($fp['is_primary_location'] ?? false) ? 'yes' : '',
                'latitude' => $fp['latitude'] ?? null,
                'longitude' => $fp['longitude'] ?? null,
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function jobs(): array
    {
        $rows = [];

        $workOrders = WorkOrder::withArchived()
            ->whereNotNull('fieldpulse_id')
            ->with('site.customer')
            ->orderBy('id')
            ->get();

        foreach ($workOrders as $workOrder) {
            $fp = $workOrder->metadata['fieldpulse'] ?? [];

            $rows[] = [
                'fieldpulse_id' => $workOrder->fieldpulse_id,
                'customer_fieldpulse_id' => $workOrder->site?->customer?->fieldpulse_id,
                'location_fieldpulse_id' => $workOrder->site?->fieldpulse_id,
                'title' => $workOrder->title,
                'subtitle' => $workOrder->subtitle,
                'status' => $workOrder->status,
                'job_notes' => $workOrder->job_notes,
                'field_notes' => $workOrder->field_notes,
                'metadata_json' => $fp === [] ? null : json_encode($fp, JSON_UNESCAPED_SLASHES),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    private function notes(): array
    {
        $rows = [];

        $events = TimelineEvent::query()
            ->where('source', TimelineEvent::SOURCE_FIELDPULSE)
            ->with(['customer', 'site'])
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            $rows[] = [
                'source_record_id' => $event->source_record_id,
                'customer_fieldpulse_id' => $event->customer?->fieldpulse_id,
                'site_fieldpulse_id' => $event->site?->fieldpulse_id,
                'kind' => $event->kind,
                'body' => $event->body,
                'occurred_at' => $event->occurred_at?->toDateTimeString(),
                'metadata_json' => $event->metadata === null ? null : json_encode($event->metadata, JSON_UNESCAPED_SLASHES),
            ];
        }

        return $rows;
    }
}
