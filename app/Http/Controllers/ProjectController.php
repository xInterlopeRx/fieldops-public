<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function show(Request $request, Project $project): JsonResponse
    {
        return response()->json([
            'data' => $project->load(['customer', 'sites', 'workOrders', 'attachments']),
        ]);
    }
}
