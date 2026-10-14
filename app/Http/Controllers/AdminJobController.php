<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminJobController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'project_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $term = trim($validated['q'] ?? '');
        $projectId = isset($validated['project_id']) ? (int) $validated['project_id'] : null;

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
            ->with([
                'site:id,customer_id,name',
                'site.customer:id,name,company_name',
                'project:id,project_number,name',
            ])
            ->withCount('visits')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
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
            ],
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
