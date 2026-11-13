<?php

namespace App\Services\FieldPulse;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FieldPulseProjectBackfillService
{
    public function __construct(
        private readonly FieldPulseDocument $document,
        private readonly FieldPulseProjectImportService $projects,
    ) {}

    /** @return array<string, mixed> */
    public function preview(string $path): array
    {
        $data = $this->readDocument($path);

        return $this->report($this->plan($data), executed: false);
    }

    /** @return array<string, mixed> */
    public function execute(string $path, User $actor): array
    {
        $data = $this->readDocument($path);
        $documentHash = hash_file('sha256', $path);

        if (! is_string($documentHash)) {
            throw new RuntimeException('fieldpulse_document_hash_failed');
        }

        return DB::transaction(function () use ($data, $actor, $documentHash): array {
            $plan = $this->plan($data, lockForUpdate: true);

            if ($plan['conflicts'] !== []) {
                return $this->report($plan, executed: false);
            }

            $projectsById = [];
            $projectsCreated = 0;

            foreach ($plan['projects'] as $sourceId => $projectPlan) {
                $project = $projectPlan['resolved']['project'];

                if ($project === null) {
                    $project = new Project;
                    $project->fill($projectPlan['resolved']['attributes'])->save();
                    $projectsCreated++;

                    AuditEvent::query()->create([
                        'actor_user_id' => $actor->getKey(),
                        'subject_type' => $project->getMorphClass(),
                        'subject_id' => $project->getKey(),
                        'event' => 'fieldpulse_project_backfilled',
                        'summary' => $actor->name.' imported a FieldPulse Project during project-link backfill.',
                        'metadata' => [
                            'source' => 'fieldpulse_document_project_backfill',
                            'source_document_sha256' => $documentHash,
                            'fieldpulse_project_id' => $sourceId,
                            'customer_id' => $project->customer_id,
                        ],
                        'created_at' => now(),
                    ]);
                }

                $projectsById[$sourceId] = $project;
            }

            $jobsLinked = 0;

            foreach ($plan['jobs_to_link'] as $jobPlan) {
                $workOrder = $jobPlan['work_order'];
                $project = $projectsById[$jobPlan['project_fieldpulse_id']];
                $workOrder->project_id = $project->getKey();
                $workOrder->save();
                $jobsLinked++;

                AuditEvent::query()->create([
                    'actor_user_id' => $actor->getKey(),
                    'subject_type' => $workOrder->getMorphClass(),
                    'subject_id' => $workOrder->getKey(),
                    'event' => 'fieldpulse_job_project_link_backfilled',
                    'summary' => $actor->name.' linked an existing FieldPulse Job to its FieldPulse Project.',
                    'metadata' => [
                        'source' => 'fieldpulse_document_project_backfill',
                        'source_document_sha256' => $documentHash,
                        'fieldpulse_job_id' => $workOrder->fieldpulse_id,
                        'project_id' => $project->getKey(),
                        'fieldpulse_project_id' => $project->fieldpulse_id,
                        'customer_id' => $project->customer_id,
                    ],
                    'created_at' => now(),
                ]);
            }

            return $this->report($plan, executed: true, projectsCreated: $projectsCreated, jobsLinked: $jobsLinked);
        });
    }

    /** @param array<string, mixed> $data
     * @return array{projects: array<string, array{resolved: array{customer: Customer, project: Project|null, attributes: array<string, mixed>}}>, skipped_projects: array<string, string>, jobs_to_link: list<array{work_order: WorkOrder, project_fieldpulse_id: string}>, projects_existing: int, projects_skipped_missing_owner: int, jobs_with_project_reference: int, jobs_already_linked: int, jobs_missing: int, jobs_skipped_missing_project_owner: int, jobs_unmatched_project_reference: int, jobs_archived: int, conflicts: list<array{resource_type: string, source_record_id: string, failure_code: string}>, source_projects: int}
     */
    private function plan(array $data, bool $lockForUpdate = false): array
    {
        $plan = [
            'projects' => [],
            'skipped_projects' => [],
            'jobs_to_link' => [],
            'projects_existing' => 0,
            'projects_skipped_missing_owner' => 0,
            'jobs_with_project_reference' => 0,
            'jobs_already_linked' => 0,
            'jobs_missing' => 0,
            'jobs_skipped_missing_project_owner' => 0,
            'jobs_unmatched_project_reference' => 0,
            'jobs_archived' => 0,
            'conflicts' => [],
            'source_projects' => 0,
        ];
        $sourceProjects = $data['Projects'] ?? [];
        $plan['source_projects'] = count($sourceProjects);
        $seenProjectIds = [];

        foreach ($sourceProjects as $row) {
            $sourceId = trim($row['fieldpulse_id'] ?? '');

            if ($sourceId === '') {
                continue;
            }

            if (isset($seenProjectIds[$sourceId])) {
                $this->addConflict($plan, 'project', $sourceId, 'fieldpulse_project_id_duplicated');

                continue;
            }

            $seenProjectIds[$sourceId] = true;

            try {
                $resolved = $this->projects->inspect($row, $lockForUpdate);
                $plan['projects'][$sourceId] = ['resolved' => $resolved];

                if ($resolved['project'] !== null) {
                    $plan['projects_existing']++;
                }
            } catch (Throwable $exception) {
                $failureCode = $this->failureCode($exception);

                if ($failureCode === 'fieldpulse_project_owner_missing') {
                    $plan['skipped_projects'][$sourceId] = $failureCode;
                    $plan['projects_skipped_missing_owner']++;
                } else {
                    $this->addConflict($plan, 'project', $sourceId, $failureCode);
                }
            }
        }

        $seenJobIds = [];

        foreach ($data['Jobs'] as $row) {
            $metadata = json_decode($row['metadata_json'] ?? '', true);
            $projectNo = is_array($metadata) ? trim((string) ($metadata['project_no'] ?? '')) : '';

            if ($projectNo === '') {
                continue;
            }

            $plan['jobs_with_project_reference']++;
            $jobSourceId = trim($row['fieldpulse_id'] ?? '');

            if ($jobSourceId === '') {
                continue;
            }

            if (isset($seenJobIds[$jobSourceId])) {
                $this->addConflict($plan, 'job', $jobSourceId, 'fieldpulse_job_id_duplicated');

                continue;
            }

            $seenJobIds[$jobSourceId] = true;

            $projectPlan = $plan['projects'][$projectNo] ?? null;

            if ($projectPlan === null) {
                if (isset($plan['skipped_projects'][$projectNo])) {
                    $workOrderQuery = WorkOrder::withArchived()->where('fieldpulse_id', $jobSourceId);

                    if ($lockForUpdate) {
                        $workOrderQuery->lockForUpdate();
                    }

                    $workOrder = $workOrderQuery->first();

                    if ($workOrder === null) {
                        $plan['jobs_missing']++;
                        $plan['jobs_skipped_missing_project_owner']++;

                        continue;
                    }

                    if ($workOrder->is_archived) {
                        $plan['jobs_archived']++;

                        continue;
                    }

                    $this->addConflict($plan, 'job', $jobSourceId, 'fieldpulse_job_project_owner_missing');

                    continue;
                }

                if (! isset($seenProjectIds[$projectNo])) {
                    $plan['jobs_unmatched_project_reference']++;
                }

                continue;
            }

            if ($this->hasConflict($plan, 'project', $projectNo)) {
                continue;
            }

            $projectCustomer = trim($projectPlan['resolved']['customer']->fieldpulse_id ?? '');
            $jobCustomer = trim($row['customer_fieldpulse_id'] ?? '');

            if ($projectCustomer !== '' && $jobCustomer !== '' && $projectCustomer !== $jobCustomer) {
                $this->addConflict($plan, 'job', $jobSourceId, 'fieldpulse_job_project_customer_mismatch');

                continue;
            }

            $workOrderQuery = WorkOrder::withArchived()->where('fieldpulse_id', $jobSourceId);

            if ($lockForUpdate) {
                $workOrderQuery->lockForUpdate();
            }

            $workOrder = $workOrderQuery->first();

            if ($workOrder === null) {
                $plan['jobs_missing']++;

                continue;
            }

            if ($workOrder->is_archived) {
                $plan['jobs_archived']++;

                continue;
            }

            $siteQuery = Site::withArchived()->whereKey($workOrder->site_id);

            if ($lockForUpdate) {
                $siteQuery->lockForUpdate();
            }

            $site = $siteQuery->first();

            if ($site === null || $site->is_archived) {
                $this->addConflict($plan, 'job', $jobSourceId, 'fieldpulse_job_site_missing_or_archived');

                continue;
            }

            if ((int) $site->customer_id !== (int) $projectPlan['resolved']['customer']->getKey()) {
                $this->addConflict($plan, 'job', $jobSourceId, 'fieldpulse_job_project_customer_mismatch');

                continue;
            }

            $existingProjectId = $projectPlan['resolved']['project']?->getKey();

            if ($workOrder->project_id !== null) {
                if ($existingProjectId !== null && (int) $workOrder->project_id === (int) $existingProjectId) {
                    $plan['jobs_already_linked']++;
                } else {
                    $this->addConflict($plan, 'job', $jobSourceId, 'fieldpulse_job_project_assignment_conflict');
                }

                continue;
            }

            $plan['jobs_to_link'][] = [
                'work_order' => $workOrder,
                'project_fieldpulse_id' => $projectNo,
            ];
        }

        return $plan;
    }

    /** @param array<string, mixed> $plan
     * @return array<string, mixed>
     */
    private function report(array $plan, bool $executed, int $projectsCreated = 0, int $jobsLinked = 0): array
    {
        return [
            'executed' => $executed,
            'source_projects' => $plan['source_projects'],
            'projects_to_create' => count($plan['projects']) - $plan['projects_existing'],
            'projects_existing' => $plan['projects_existing'],
            'projects_skipped_missing_owner' => $plan['projects_skipped_missing_owner'],
            'projects_created' => $projectsCreated,
            'jobs_with_project_reference' => $plan['jobs_with_project_reference'],
            'jobs_to_link' => count($plan['jobs_to_link']),
            'jobs_linked' => $jobsLinked,
            'jobs_already_linked' => $plan['jobs_already_linked'],
            'jobs_missing' => $plan['jobs_missing'],
            'jobs_skipped_missing_project_owner' => $plan['jobs_skipped_missing_project_owner'],
            'jobs_unmatched_project_reference' => $plan['jobs_unmatched_project_reference'],
            'jobs_archived' => $plan['jobs_archived'],
            'conflict_count' => count($plan['conflicts']),
            'conflicts' => array_slice($plan['conflicts'], 0, 25),
        ];
    }

    /** @param array<string, mixed> $plan */
    private function addConflict(array &$plan, string $resourceType, string $sourceRecordId, string $failureCode): void
    {
        $plan['conflicts'][] = [
            'resource_type' => $resourceType,
            'source_record_id' => $sourceRecordId,
            'failure_code' => $failureCode,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function hasConflict(array $plan, string $resourceType, string $sourceRecordId): bool
    {
        foreach ($plan['conflicts'] as $conflict) {
            if ($conflict['resource_type'] === $resourceType && $conflict['source_record_id'] === $sourceRecordId) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<array<string, string>>> */
    private function readDocument(string $path): array
    {
        return $this->document->read($path);
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException && preg_match('/\A[a-z0-9_]{1,80}\z/D', $exception->getMessage()) === 1) {
            return $exception->getMessage();
        }

        return 'fieldpulse_project_backfill_failed';
    }
}
