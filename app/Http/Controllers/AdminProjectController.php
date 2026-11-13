<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $statusOptions = ProjectStatus::options();
        $statusValues = array_column($statusOptions, 'value');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'company' => ['nullable', 'string', 'max:100'],
            'project_name' => ['nullable', 'string', 'max:100'],
            'exclude_statuses' => ['sometimes', 'array', 'max:'.count($statusValues)],
            'exclude_statuses.*' => ['required', 'string', 'distinct', Rule::in($statusValues)],
            'sort' => ['nullable', Rule::in([
                'name_asc', 'name_desc', 'number_asc', 'number_desc',
                'company_asc', 'company_desc', 'status', 'updated_desc',
            ])],
        ]);
        $term = trim($validated['q'] ?? '');
        $company = trim($validated['company'] ?? '');
        $projectName = trim($validated['project_name'] ?? '');
        $excludedStatuses = $validated['exclude_statuses'] ?? [];
        $selectedStatuses = array_values(array_diff($statusValues, $excludedStatuses));
        $sort = $validated['sort'] ?? 'name_asc';

        $projects = Project::query()
            ->when($term !== '', function (Builder $query) use ($term): void {
                $like = '%'.mb_strtolower($term).'%';

                $query->where(function (Builder $projectQuery) use ($like): void {
                    $projectQuery
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(project_number) LIKE ?', [$like])
                        ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(company_name) LIKE ?', [$like]));
                });
            })
            ->when($company !== '', function (Builder $query) use ($company): void {
                $like = '%'.mb_strtolower($company).'%';

                $query->whereHas('customer', fn (Builder $customerQuery) => $customerQuery
                    ->whereRaw("LOWER(COALESCE(NULLIF(company_name, ''), name)) LIKE ?", [$like]));
            })
            ->when($projectName !== '', fn (Builder $query) => $query
                ->whereRaw('LOWER(projects.name) LIKE ?', ['%'.mb_strtolower($projectName).'%']))
            ->when($excludedStatuses !== [], function (Builder $query) use ($selectedStatuses): void {
                $selectedStatuses === []
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('projects.status', $selectedStatuses);
            })
            ->with('customer:id,name,company_name')
            ->withCount(['sites', 'workOrders']);

        match ($sort) {
            'name_desc' => $projects->orderByRaw('LOWER(projects.name) DESC')->orderByDesc('projects.id'),
            'number_asc' => $projects
                ->orderByRaw("CASE WHEN project_number IS NULL OR TRIM(project_number) = '' THEN 1 ELSE 0 END")
                ->orderByRaw('LOWER(project_number) ASC')->orderByRaw('LOWER(projects.name)')->orderBy('projects.id'),
            'number_desc' => $projects
                ->orderByRaw("CASE WHEN project_number IS NULL OR TRIM(project_number) = '' THEN 1 ELSE 0 END")
                ->orderByRaw('LOWER(project_number) DESC')->orderByRaw('LOWER(projects.name)')->orderBy('projects.id'),
            'company_asc', 'company_desc' => $projects
                ->orderBy(
                    Customer::query()
                        ->selectRaw("LOWER(COALESCE(NULLIF(customers.company_name, ''), customers.name))")
                        ->whereColumn('customers.id', 'projects.customer_id'),
                    $sort === 'company_asc' ? 'asc' : 'desc',
                )
                ->orderByRaw('LOWER(projects.name)')->orderBy('projects.id'),
            'status' => $projects->orderBy('projects.status')->orderByRaw('LOWER(projects.name)')->orderBy('projects.id'),
            'updated_desc' => $projects->orderByDesc('projects.updated_at')->orderByDesc('projects.id'),
            default => $projects->orderByRaw('LOWER(projects.name)')->orderBy('projects.id'),
        };

        $projects = $projects
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Project $project): array => [
                'id' => $project->id,
                'number' => $project->project_number,
                'name' => $project->name,
                'status' => $project->status,
                'customerName' => $project->customer?->company_name ?: $project->customer?->name,
                'sitesCount' => $project->sites_count,
                'jobsCount' => $project->work_orders_count,
            ]);

        return Inertia::render('Admin/Projects', [
            'projects' => $projects,
            'filters' => [
                'q' => $term,
                'company' => $company,
                'projectName' => $projectName,
                'statuses' => $selectedStatuses,
                'excludedStatuses' => $excludedStatuses,
                'sort' => $sort,
            ],
            'statusOptions' => $statusOptions,
        ]);
    }
}
