<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminProjectController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $term = trim($validated['q'] ?? '');

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
            ->with('customer:id,name,company_name')
            ->withCount(['sites', 'workOrders'])
            ->orderBy('name')
            ->orderBy('id')
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
            'filters' => ['q' => $term],
        ]);
    }
}
