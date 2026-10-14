<?php

namespace App\Http\Controllers;

use App\Models\ArchiveBatch;
use App\Models\User;
use App\Services\ArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminArchiveController extends Controller
{
    public function index(Request $request, ArchiveService $archives): JsonResponse
    {
        $batches = $this->batches($request, $archives);

        $batches->setCollection($archives->presentBatches($batches->getCollection()));

        return response()->json($batches);
    }

    public function page(Request $request, ArchiveService $archives): Response
    {
        $batches = $this->batches($request, $archives);
        $batches->setCollection($archives->presentBatches($batches->getCollection()));

        return Inertia::render('Admin/Archive', [
            'batches' => $batches,
            'filters' => ['type' => $request->query('type') ?? ''],
            'types' => $archives->types(),
            'status' => session('status'),
        ]);
    }

    public function restoreFromPage(string $batch, Request $request, ArchiveService $archives): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $archives->restore($batch, $actor);

        return to_route('admin.archive')->with('status', 'Archive restored.');
    }

    public function archive(Request $request, string $type, string $record, ArchiveService $archives): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $batch = $archives->archive($type, $record, $actor, $validated['reason'] ?? null);

        return response()->json([
            'data' => $archives->presentBatches(collect([$batch->load('archivedBy:id,name')]))->first(),
        ], 201);
    }

    public function restore(string $batch, Request $request, ArchiveService $archives): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $restoredBatch = $archives->restore($batch, $actor);

        return response()->json([
            'data' => $archives->presentBatches(collect([$restoredBatch->load('archivedBy:id,name')]))->first(),
            'restored' => true,
        ]);
    }

    private function batches(Request $request, ArchiveService $archives): LengthAwarePaginator
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', Rule::in($archives->types())],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return ArchiveBatch::query()
            ->with('archivedBy:id,name')
            ->whereNull('restored_at')
            ->when(isset($validated['type']), fn ($query) => $query->where('root_type', $validated['type']))
            ->orderByDesc('archived_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();
    }
}
