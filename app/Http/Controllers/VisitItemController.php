<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVisitJobItemRequest;
use App\Http\Requests\UpdateVisitJobItemRequest;
use App\Models\JobItem;
use App\Models\Visit;
use App\Services\VisitJobItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitItemController extends Controller
{
    public function search(Request $request, Visit $visit, VisitJobItemService $items): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $visit->load('jobItems.item:id,type');

        return response()->json([
            'data' => [
                'catalog' => $items->searchCatalog($visit, $request->user(), $validated['search'] ?? ''),
                'items' => $visit->jobItems->map(fn (JobItem $jobItem): array => [
                    'id' => (int) $jobItem->getKey(),
                    'itemId' => (int) $jobItem->item_id,
                    'name' => $jobItem->item_name_snapshot,
                    'description' => $jobItem->item_description_snapshot,
                    'type' => $jobItem->item?->type,
                    'quantity' => (string) $jobItem->quantity,
                ])->values(),
            ],
        ]);
    }

    public function store(
        StoreVisitJobItemRequest $request,
        Visit $visit,
        VisitJobItemService $items,
    ): JsonResponse {
        $data = $request->validated();
        $item = $items->add(
            $visit,
            $request->user(),
            (int) $data['item_id'],
            (string) $data['quantity'],
        );

        return response()->json(['data' => $item], 201);
    }

    public function update(
        UpdateVisitJobItemRequest $request,
        Visit $visit,
        JobItem $jobItem,
        VisitJobItemService $items,
    ): JsonResponse {
        $data = $request->validated();
        $item = $items->updateQuantity($visit, $jobItem, $request->user(), (string) $data['quantity']);

        return response()->json(['data' => $item]);
    }

    public function destroy(Request $request, Visit $visit, JobItem $jobItem, VisitJobItemService $items): JsonResponse
    {
        abort_unless($request->user()?->can('work_orders.manage'), 403);
        $items->remove($visit, $jobItem, $request->user());

        return response()->json(['message' => 'Visit Item removed.']);
    }
}
