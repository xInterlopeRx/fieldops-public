<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\CustomerAddressBookService;
use App\Services\QuickBooksCustomerSyncService;
use App\Services\QuickBooksCustomerWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function globalSearch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:40'],
        ]);
        $term = trim($validated['q'] ?? '');

        if ($term === '') {
            return response()->json(['data' => []]);
        }

        $normalizedTerm = mb_strtolower($term);
        $like = '%'.$normalizedTerm.'%';
        $prefix = $normalizedTerm.'%';
        $status = trim($validated['status'] ?? '');

        $customers = Customer::query()
            ->where(function ($query) use ($like) {
                $query->where(function ($customer) use ($like) {
                    $customer->whereRaw('LOWER(client_id) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(company_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(first_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(middle_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(alternate_phone) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(mobile_phone) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(fax) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(website) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(address) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(notes) LIKE ?', [$like]);
                })
                    ->orWhereHas('contacts', function ($contact) use ($like) {
                        $contact->where(function ($details) use ($like) {
                            $details->whereRaw('LOWER(first_name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(middle_name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(phone) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(alternate_phone) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(mobile_phone) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(fax) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(website) LIKE ?', [$like]);
                        })->orWhereHas('methods', fn ($method) => $method->whereRaw('LOWER(value) LIKE ?', [$like]));
                    })
                    ->orWhereHas('sites', fn ($site) => $site
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(company_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(address) LIKE ?', [$like]))
                    ->orWhereHas('emailAddresses', fn ($email) => $email
                        ->whereRaw('LOWER(email) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(label) LIKE ?', [$like]))
                    ->orWhereHas('addresses', function ($address) use ($like) {
                        $address->where(function ($details) use ($like) {
                            $details->whereRaw('LOWER(label) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(line1) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(line2) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(line3) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(line4) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(line5) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(city) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(country_subdivision_code) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(postal_code) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(country) LIKE ?', [$like]);
                        });
                    });
            })
            ->orderByRaw(
                'CASE WHEN LOWER(name) = ? OR LOWER(client_id) = ? THEN 0 WHEN LOWER(name) LIKE ? OR LOWER(client_id) LIKE ? THEN 1 ELSE 2 END',
                [$normalizedTerm, $normalizedTerm, $prefix, $prefix],
            )
            ->orderBy('name')
            ->orderBy('id')
            ->limit(8)
            ->get(['id', 'client_id', 'name', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'address', 'notes'])
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'type' => 'client',
                'name' => $customer->name,
                'reference' => $customer->client_id,
                'detail' => $customer->company_name ?? $customer->email ?? $customer->phone ?? $customer->address,
            ]);

        $workOrders = WorkOrder::query()
            ->where(function ($query) use ($request) {
                $query->whereHas('assignedUsers', fn ($assigned) => $assigned->whereKey($request->user()->id))
                    ->orWhereHas('visits.assignedUsers', fn ($assigned) => $assigned->whereKey($request->user()->id));
            })
            ->where(function ($query) use ($like) {
                $query->whereRaw('LOWER(job_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(status) LIKE ?', [$like])
                    ->orWhereHas('site', function ($site) use ($like) {
                        $site->whereRaw('LOWER(name) LIKE ?', [$like])
                            ->orWhereHas('customer', fn ($customer) => $customer->whereRaw('LOWER(name) LIKE ?', [$like]));
                    });
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->with(['site:id,customer_id,name', 'site.customer:id,name'])
            ->orderByRaw(
                'CASE WHEN LOWER(title) = ? OR LOWER(job_number) = ? THEN 0 WHEN LOWER(title) LIKE ? OR LOWER(job_number) LIKE ? THEN 1 ELSE 2 END',
                [$normalizedTerm, $normalizedTerm, $prefix, $prefix],
            )
            ->orderBy('title')
            ->orderBy('id')
            ->limit(8)
            ->get(['id', 'site_id', 'job_number', 'title', 'status']);

        $jobs = $workOrders->map(fn (WorkOrder $workOrder): array => [
            'id' => $workOrder->id,
            'type' => 'job',
            'name' => $workOrder->title,
            'reference' => $workOrder->job_number,
            'detail' => ($workOrder->site?->customer?->name ?? $workOrder->site?->name).' · '.str_replace('_', ' ', $workOrder->status),
            'status' => $workOrder->status,
        ]);

        $visits = Visit::query()
            ->where(function ($query) use ($request) {
                $query->whereHas('assignedUsers', fn ($assigned) => $assigned->whereKey($request->user()->id))
                    ->orWhereHas('workOrder.assignedUsers', fn ($assigned) => $assigned->whereKey($request->user()->id));
            })
            ->where(function ($query) use ($like) {
                $query->whereRaw('LOWER(notes) LIKE ?', [$like])
                    ->orWhereHas('workOrder', function ($workOrder) use ($like) {
                        $workOrder->whereRaw('LOWER(job_number) LIKE ?', [$like])
                            ->orWhereRaw('LOWER(title) LIKE ?', [$like]);
                    });
            })
            ->with('workOrder:id,title')
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['id', 'work_order_id', 'notes', 'visit_type', 'scheduled_at'])
            ->map(fn (Visit $visit): array => [
                'id' => $visit->id,
                'parent_id' => $visit->work_order_id,
                'type' => 'visit',
                'name' => $visit->workOrder?->title ?? 'Visit',
                'reference' => 'V'.$visit->id,
                'detail' => ucfirst($visit->visit_type).' · '.$visit->scheduled_at?->format('M j, Y g:i A'),
            ]);

        $projects = Project::query()
            ->where(function ($query) use ($like) {
                $query->whereRaw('LOWER(project_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereHas('customer', fn ($customer) => $customer->whereRaw('LOWER(name) LIKE ?', [$like]));
            })
            ->with('customer:id,name')
            ->orderByRaw(
                'CASE WHEN LOWER(name) = ? OR LOWER(project_number) = ? THEN 0 WHEN LOWER(name) LIKE ? OR LOWER(project_number) LIKE ? THEN 1 ELSE 2 END',
                [$normalizedTerm, $normalizedTerm, $prefix, $prefix],
            )
            ->orderBy('name')
            ->orderBy('id')
            ->limit(8)
            ->get(['id', 'customer_id', 'project_number', 'name'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'type' => 'project',
                'name' => $project->name,
                'reference' => $project->project_number,
                'detail' => $project->customer?->name,
            ]);

        $rank = function (array $result) use ($normalizedTerm): array {
            $name = mb_strtolower($result['name']);
            $reference = mb_strtolower((string) ($result['reference'] ?? ''));
            $matchRank = $name === $normalizedTerm || $reference === $normalizedTerm
                ? 0
                : (str_starts_with($name, $normalizedTerm) || str_starts_with($reference, $normalizedTerm) ? 1 : 2);
            $typeRank = match ($result['type']) {
                'client' => 0,
                'project' => 1,
                'job' => 2,
                'visit' => 3,
                default => 4,
            };

            return [$matchRank, $typeRank, $name, (int) $result['id']];
        };

        $rankedSuggestions = collect()
            ->concat($customers)
            ->concat($projects)
            ->concat($jobs)
            ->concat($visits)
            ->sort(fn (array $left, array $right): int => $rank($left) <=> $rank($right))
            ->values();

        $sectionSuggestions = $rankedSuggestions
            ->unique('type')
            ->take(4)
            ->values();
        $selectedKeys = $sectionSuggestions
            ->map(fn (array $result): string => $result['type'].':'.$result['id'])
            ->all();
        $suggestions = $sectionSuggestions
            ->concat($rankedSuggestions->reject(
                fn (array $result): bool => in_array($result['type'].':'.$result['id'], $selectedKeys, true),
            ))
            ->take(4)
            ->values();

        return response()->json([
            'data' => $suggestions,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());

        $customers = Customer::query()
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($query) use ($term) {
                    $query->where('client_id', 'like', "%{$term}%")
                        ->orWhere('name', 'ilike', "%{$term}%")
                        ->orWhere('email', 'ilike', "%{$term}%");
                });
            })
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'client_id', 'name', 'first_name', 'last_name', 'email', 'phone', 'address', 'notes']);

        return response()->json(['data' => $customers]);
    }

    public function show(
        Customer $customer,
        CustomerAddressBookService $addressBook,
        QuickBooksCustomerSyncService $quickBooksSync,
    ): JsonResponse {
        $refreshStatus = $quickBooksSync->requestRefresh($customer);
        $customer->refresh()->load(['contacts', 'sites']);

        return response()->json($this->customerResponse($customer, $refreshStatus, $addressBook));
    }

    public function checkQuickBooksUpdates(Customer $customer, QuickBooksCustomerSyncService $quickBooksSync): JsonResponse
    {
        $status = $quickBooksSync->requestRefresh($customer, force: true);

        if ($status === 'unlinked') {
            return response()->json([
                'status' => $status,
                'message' => 'This customer is not linked to a QuickBooks customer.',
            ], 422);
        }

        if ($status === 'not_connected') {
            return response()->json([
                'status' => $status,
                'message' => 'QuickBooks is not connected for the configured environment.',
            ], 409);
        }

        if ($status === 'sync_paused') {
            return response()->json([
                'status' => $status,
                'message' => 'QuickBooks synchronization is paused by an Admin.',
            ], 409);
        }

        return response()->json(['status' => $status], 202);
    }

    public function store(
        StoreCustomerRequest $request,
        CustomerAddressBookService $addressBook,
        QuickBooksCustomerWriteService $quickBooksWrites,
    ): RedirectResponse {
        $validated = $request->validated();
        $billingAddress = $validated['billing_address'] ?? [];
        $shippingAddress = $validated['shipping_address'] ?? [];

        if (($billingAddress['line1'] ?? null) === null && ! empty($validated['address'])) {
            $billingAddress['line1'] = $validated['address'];
        }

        unset($validated['billing_address'], $validated['shipping_address']);

        $customer = DB::transaction(function () use ($validated, $billingAddress, $shippingAddress, $addressBook): Customer {
            $lastClientId = Customer::query()
                ->whereNotNull('client_id')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('client_id');
            $nextNumber = $lastClientId === null ? 1001 : ((int) substr($lastClientId, 1)) + 1;

            $customer = Customer::create([
                ...$validated,
                'client_id' => 'C'.str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT),
            ]);
            $addressBook->saveDefault($customer, 'billing', $billingAddress);
            $addressBook->saveDefault($customer, 'shipping', $shippingAddress);

            return $customer;
        });

        $writeStatus = $quickBooksWrites->queueCustomer($customer, createIfUnlinked: true);
        $message = "Client {$customer->client_id} created.";

        if ($writeStatus === 'pending') {
            $message .= ' QuickBooks create queued.';
        } elseif ($writeStatus === 'paused') {
            $message .= ' QuickBooks sync is paused; the create is queued and held until sync resumes.';
        } elseif ($writeStatus === 'not_connected') {
            $message .= ' QuickBooks is not connected; this customer remains local.';
        } elseif ($writeStatus === 'failed') {
            $message .= ' The QuickBooks create could not be queued; contact an administrator.';
        }

        return redirect()->route('calendar')->with('success', $message);
    }

    public function update(
        UpdateCustomerRequest $request,
        Customer $customer,
        CustomerAddressBookService $addressBook,
        QuickBooksCustomerWriteService $quickBooksWrites,
    ): RedirectResponse {
        if ($customer->quickbooks_sync_status === 'sync_paused'
            || $customer->quickbooks_write_request_id !== null
            || $customer->quickbooks_sync_status === 'pending'
            || ($customer->quickbooks_id !== null && $customer->quickbooks_sync_status === 'failed')) {
            return back()->withErrors([
                'quickbooks' => 'QuickBooks synchronization is in progress or needs administrator resolution. Wait for it to finish before editing this customer again.',
            ]);
        }

        $validated = $request->validated();
        $billingAddress = $validated['billing_address'] ?? [];
        $shippingAddress = $validated['shipping_address'] ?? [];
        $billingAddressSubmitted = array_key_exists('billing_address', $validated);
        $shippingAddressSubmitted = array_key_exists('shipping_address', $validated);

        if (! $billingAddressSubmitted && array_key_exists('address', $validated)) {
            $billingAddress['line1'] = $validated['address'];
            $billingAddressSubmitted = true;
        }

        unset($validated['address'], $validated['billing_address'], $validated['shipping_address']);

        $writeFields = DB::transaction(function () use ($customer, $validated, $billingAddress, $shippingAddress, $billingAddressSubmitted, $shippingAddressSubmitted, $addressBook): array {
            $customer->fill($validated);
            $changedFields = array_keys($customer->getDirty());
            $customer->save();

            if ($billingAddressSubmitted && $addressBook->saveDefault($customer, 'billing', $billingAddress)) {
                $changedFields[] = 'BillAddr';
            }

            if ($shippingAddressSubmitted && $addressBook->saveDefault($customer, 'shipping', $shippingAddress)) {
                $changedFields[] = 'ShipAddr';
            }

            return array_values(array_unique($changedFields));
        });

        $customer->refresh();
        $writeStatus = $quickBooksWrites->queueCustomer($customer, $writeFields, createIfUnlinked: true);
        $message = "Client {$customer->client_id} updated.";

        if ($writeStatus === 'pending') {
            $message .= ' QuickBooks update queued.';
        } elseif ($writeStatus === 'paused') {
            $message .= ' QuickBooks sync is paused; the update is queued and held until sync resumes.';
        } elseif ($writeStatus === 'not_connected' && $customer->quickbooks_id !== null) {
            $message .= ' QuickBooks write-back requires the sandbox connection.';
        } elseif ($writeStatus === 'failed') {
            $message .= ' The QuickBooks write could not be queued; contact an administrator.';
        }

        return back()->with('success', $message);
    }

    /** @return array<string, mixed> */
    private function customerResponse(Customer $customer, string $refreshStatus, CustomerAddressBookService $addressBook): array
    {
        $syncStatus = in_array($refreshStatus, ['not_connected', 'sync_paused'], true)
            ? $refreshStatus
            : ($customer->quickbooks_sync_status ?? 'idle');

        return [
            'data' => $customer,
            'address_defaults' => [
                'billing' => $addressBook->defaultsForForm($customer, 'billing'),
                'shipping' => $addressBook->defaultsForForm($customer, 'shipping'),
            ],
            'quickbooks_sync' => [
                'linked' => $customer->quickbooks_id !== null,
                'write_pending' => $customer->quickbooks_write_request_id !== null,
                'status' => $syncStatus,
                'last_synced_at' => $customer->quickbooks_last_synced_at?->toIso8601String(),
                'error' => $syncStatus === 'sync_paused'
                    ? 'QuickBooks synchronization is paused by an Admin; local data is unchanged.'
                    : ($syncStatus === 'failed'
                    ? ($customer->quickbooks_write_request_id !== null
                        ? 'The QuickBooks write did not complete. An administrator can safely retry it.'
                        : 'The QuickBooks refresh did not complete. Try checking again or contact an administrator.')
                    : null),
            ],
        ];
    }
}
