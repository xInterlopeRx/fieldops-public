<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Project;
use App\Models\Site;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminJobController extends Controller
{
    public function index(Request $request): Response
    {
        $statusDefinitions = config('work_orders.statuses', []);
        $statusValues = array_keys($statusDefinitions);
        $statusOptions = collect($statusDefinitions)
            ->map(fn (array $status, string $value): array => [
                'value' => $value,
                'label' => $status['label'],
            ])
            ->values()
            ->all();
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer', 'min:1'],
            'company' => ['nullable', 'string', 'max:100'],
            'project_name' => ['nullable', 'string', 'max:100'],
            'exclude_statuses' => ['sometimes', 'array', 'max:'.count($statusValues)],
            'exclude_statuses.*' => ['required', 'string', 'distinct', Rule::in($statusValues)],
            'sort' => ['nullable', Rule::in([
                'updated_desc', 'title_asc', 'title_desc', 'job_number_asc', 'job_number_desc',
                'company_asc', 'company_desc', 'project_asc', 'project_desc', 'status',
            ])],
        ]);
        $term = trim($validated['q'] ?? '');
        $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;
        $company = trim($validated['company'] ?? '');
        $projectName = trim($validated['project_name'] ?? '');
        $excludedStatuses = $validated['exclude_statuses'] ?? [];
        $selectedStatuses = array_values(array_diff($statusValues, $excludedStatuses));
        $sort = $validated['sort'] ?? 'updated_desc';

        $jobs = WorkOrder::query()
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.mb_strtolower($term).'%';

                $query->where(function (Builder $jobQuery) use ($like): void {
                    $jobQuery
                        ->whereRaw('LOWER(title) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(job_number) LIKE ?', [$like])
                        ->orWhereHas('site', fn (Builder $siteQuery) => $siteQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery
                                ->whereRaw('LOWER(name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(company_name) LIKE ?', [$like])))
                        ->orWhereHas('project', fn (Builder $projectQuery) => $projectQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(project_number) LIKE ?', [$like]));
                });
            })
            ->when($projectId !== null, fn (Builder $query) => $query->where('project_id', $projectId))
            ->when($company !== '', function (Builder $query) use ($company): void {
                $like = '%'.mb_strtolower($company).'%';

                $query->whereHas('site.customer', fn (Builder $customerQuery) => $customerQuery
                    ->whereRaw("LOWER(COALESCE(NULLIF(company_name, ''), name)) LIKE ?", [$like]));
            })
            ->when($projectName !== '', fn (Builder $query) => $query
                ->whereHas('project', fn (Builder $projectQuery) => $projectQuery
                    ->whereRaw('LOWER(projects.name) LIKE ?', ['%'.mb_strtolower($projectName).'%']))
            )
            ->when($excludedStatuses !== [], function (Builder $query) use ($selectedStatuses): void {
                $selectedStatuses === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('work_orders.status', $selectedStatuses);
            })
            ->with([
                'site:id,customer_id,name',
                'site.customer:id,name,company_name',
                'project:id,project_number,name',
            ])
            ->withCount('visits');

        match ($sort) {
            'title_asc' => $jobs->orderByRaw('LOWER(work_orders.title)')->orderBy('work_orders.id'),
            'title_desc' => $jobs->orderByRaw('LOWER(work_orders.title) DESC')->orderByDesc('work_orders.id'),
            'job_number_asc' => $jobs
                ->orderByRaw("CASE WHEN job_number IS NULL OR TRIM(job_number) = '' THEN 1 ELSE 0 END")
                ->orderByRaw('LOWER(work_orders.job_number)')->orderByRaw('LOWER(work_orders.title)')->orderBy('work_orders.id'),
            'job_number_desc' => $jobs
                ->orderByRaw("CASE WHEN job_number IS NULL OR TRIM(job_number) = '' THEN 1 ELSE 0 END")
                ->orderByRaw('LOWER(work_orders.job_number) DESC')->orderByRaw('LOWER(work_orders.title)')->orderBy('work_orders.id'),
            'company_asc', 'company_desc' => $jobs
                ->orderBy(
                    Customer::query()
                        ->selectRaw("LOWER(COALESCE(NULLIF(customers.company_name, ''), customers.name))")
                        ->whereIn('customers.id', Site::query()
                            ->select('sites.customer_id')
                            ->whereColumn('sites.id', 'work_orders.site_id'))
                        ->limit(1),
                    $sort === 'company_asc' ? 'asc' : 'desc',
                )
                ->orderByRaw('LOWER(work_orders.title)')->orderBy('work_orders.id'),
            'project_asc', 'project_desc' => $jobs
                ->orderBy(
                    Project::query()
                        ->selectRaw('LOWER(projects.name)')
                        ->whereColumn('projects.id', 'work_orders.project_id'),
                    $sort === 'project_asc' ? 'asc' : 'desc',
                )
                ->orderByRaw('LOWER(work_orders.title)')->orderBy('work_orders.id'),
            'status' => $jobs->orderBy('work_orders.status')->orderByRaw('LOWER(work_orders.title)')->orderBy('work_orders.id'),
            default => $jobs->orderByDesc('work_orders.updated_at')->orderByDesc('work_orders.id'),
        };

        $jobs = $jobs
            ->paginate(25)
            ->withQueryString()
            ->through(fn (WorkOrder $job): array => [
                'id' => $job->id,
                'number' => $job->job_number,
                'title' => $job->title,
                'status' => $job->status,
                'siteName' => $job->site?->name,
                'customerName' => $job->site?->customer?->company_name ?: $job->site?->customer?->name,
                'projectName' => $job->project?->name,
                'projectNumber' => $job->project?->project_number,
                'visitsCount' => $job->visits_count,
            ]);

        $projectFilter = $projectId === null
            ? null
            : Project::query()->select('id', 'project_number', 'name')->find($projectId);

        return Inertia::render('Admin/Jobs', [
            'jobs' => $jobs,
            'filters' => [
                'q' => $term,
                'project_id' => $projectId,
                'company' => $company,
                'projectName' => $projectName,
                'statuses' => $selectedStatuses,
                'excludedStatuses' => $excludedStatuses,
                'sort' => $sort,
            ],
            'statusOptions' => $statusOptions,
            'projectFilter' => $projectFilter === null
                ? null
                : [
                    'id' => $projectFilter->id,
                    'name' => $projectFilter->name,
                    'number' => $projectFilter->project_number,
                ],
        ]);
    }
}
