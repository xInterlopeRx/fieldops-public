<?php

namespace App\Services\FieldPulse;

use App\Models\Customer;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FieldPulseProjectImportService
{
    /**
     * @param  array<string, string>  $row
     * @return array{customer: Customer, project: Project|null, attributes: array<string, mixed>}
     */
    public function inspect(array $row, bool $lockForUpdate = false): array
    {
        $sourceId = trim($row['fieldpulse_id'] ?? '');

        if ($sourceId === '') {
            throw new RuntimeException('fieldpulse_project_id_missing');
        }

        $customerQuery = Customer::withArchived()->where('fieldpulse_id', $row['customer_fieldpulse_id'] ?? '');
        $projectQuery = Project::withArchived()->where('fieldpulse_id', $sourceId);

        if ($lockForUpdate) {
            $customerQuery->lockForUpdate();
            $projectQuery->lockForUpdate();
        }

        $customer = $customerQuery->first();

        if ($customer === null) {
            throw new RuntimeException('fieldpulse_project_owner_missing');
        }

        if ($customer->is_archived) {
            throw new RuntimeException('fieldpulse_project_owner_archived');
        }

        $projectNumber = $this->nullIfBlank($row['project_number'] ?? '');
        $project = $projectQuery->first();

        if ($project !== null && $project->is_archived) {
            throw new RuntimeException('fieldpulse_project_archived');
        }

        if ($project !== null && (int) $project->customer_id !== (int) $customer->getKey()) {
            throw new RuntimeException('fieldpulse_project_customer_conflict');
        }

        if ($projectNumber !== null && Project::withArchived()
            ->where('project_number', $projectNumber)
            ->where(function ($query) use ($sourceId): void {
                $query->whereNull('fieldpulse_id')->orWhere('fieldpulse_id', '!=', $sourceId);
            })
            ->exists()) {
            throw new RuntimeException('fieldpulse_project_number_conflict');
        }

        $metadata = $this->extraMetadata($row['metadata_json'] ?? '');

        return [
            'customer' => $customer,
            'project' => $project,
            'attributes' => [
                'customer_id' => $customer->getKey(),
                'fieldpulse_id' => $sourceId,
                'project_number' => $projectNumber,
                'name' => ($row['name'] ?? '') !== '' ? $row['name'] : 'Project '.$sourceId,
                'notes' => $this->nullIfBlank($row['notes'] ?? ''),
                'status' => $this->status($row['status'] ?? ''),
                'metadata' => [
                    ...($project?->metadata ?? []),
                    'fieldpulse' => array_filter([
                        ...$metadata,
                        'source_status' => $this->nullIfBlank($row['status'] ?? ''),
                    ], static fn (mixed $value): bool => $value !== null),
                ],
            ],
        ];
    }

    /** @param array<string, string> $row */
    public function upsert(array $row): Project
    {
        return DB::transaction(function () use ($row): Project {
            $resolved = $this->inspect($row, lockForUpdate: true);
            $project = $resolved['project'] ?? new Project;
            $project->fill($resolved['attributes'])->save();

            return $project;
        });
    }

    private function status(string $status): string
    {
        $normalized = str_replace([' ', '-'], '_', mb_strtolower(trim($status)));

        return match ($normalized) {
            'new', 'created' => 'created',
            'scheduled' => 'scheduled',
            'accepted', 'opportunity', 'planning' => 'planning',
            'active', 'in_progress' => 'active',
            'on_hold', 'hold', 'paused' => 'on_hold',
            'completed', 'complete' => 'completed',
            'canceled', 'cancelled' => 'cancelled',
            default => 'created',
        };
    }

    private function nullIfBlank(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function extraMetadata(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
