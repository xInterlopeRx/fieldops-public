<?php

namespace App\Http\Controllers;

use App\Enums\CustomerGroup;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AdminCustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $groupOptions = [
            ['value' => 'unassigned', 'label' => 'Unassigned'],
            ...CustomerGroup::options(),
        ];
        $statusOptions = [
            ['value' => 'unassigned', 'label' => 'Unassigned'],
            ...CustomerStatus::options(),
            ['value' => 'archived', 'label' => 'Archived'],
        ];
        $groupValues = array_column($groupOptions, 'value');
        $statusValues = array_column($statusOptions, 'value');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'exclude_groups' => ['sometimes', 'array', 'max:'.count($groupValues)],
            'exclude_groups.*' => ['required', 'string', 'distinct', Rule::in($groupValues)],
            'exclude_statuses' => ['sometimes', 'array', 'max:'.count($statusValues)],
            'exclude_statuses.*' => ['required', 'string', 'distinct', Rule::in($statusValues)],
            'sort' => ['nullable', Rule::in([
                'name_asc', 'name_desc', 'client_id_asc', 'client_id_desc',
                'primary_first_asc', 'primary_first_desc', 'primary_last_asc', 'primary_last_desc',
                'group', 'status', 'updated_desc',
            ])],
        ]);
        $term = trim($validated['q'] ?? '');
        $excludedGroups = $validated['exclude_groups'] ?? [];
        $excludedStatuses = $validated['exclude_statuses'] ?? [];
        $selectedGroups = array_values(array_diff($groupValues, $excludedGroups));
        $selectedStatuses = array_values(array_diff($statusValues, $excludedStatuses));
        $showArchivedShortcut = $selectedStatuses === ['archived'];
        $sort = $validated['sort'] ?? 'name_asc';

        $customers = Customer::query()
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.mb_strtolower($term).'%';

                $query->where(function ($customerQuery) use ($like): void {
                    foreach (['name', 'company_name', 'first_name', 'last_name', 'email', 'phone', 'client_id'] as $column) {
                        $customerQuery->orWhereRaw("LOWER({$column}) LIKE ?", [$like]);
                    }

                    $customerQuery->orWhereHas('sites', fn ($siteQuery) => $siteQuery
                        ->whereRaw('LOWER(name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(address) LIKE ?', [$like]));
                });
            })
            ->with('defaultBillingAddress')
            ->withCount(['sites', 'projects', 'workOrders']);

        if (count($selectedGroups) < count($groupValues)) {
            $selectedGroupValues = array_values(array_diff($selectedGroups, ['unassigned']));
            $includesUnassignedGroup = in_array('unassigned', $selectedGroups, true);

            if ($selectedGroups === []) {
                $customers->whereRaw('1 = 0');
            } else {
                $customers->where(function ($query) use ($selectedGroupValues, $includesUnassignedGroup): void {
                    if ($selectedGroupValues !== []) {
                        $query->whereIn('customer_group', $selectedGroupValues);
                    }

                    if ($includesUnassignedGroup) {
                        $selectedGroupValues === []
                            ? $query->whereNull('customer_group')
                            : $query->orWhereNull('customer_group');
                    }
                });
            }
        }

        if (count($selectedStatuses) < count($statusValues)) {
            $selectedStatusValues = array_values(array_diff($selectedStatuses, ['unassigned', 'archived']));
            $includesUnassignedStatus = in_array('unassigned', $selectedStatuses, true);

            if ($selectedStatusValues === [] && ! $includesUnassignedStatus) {
                $customers->whereRaw('1 = 0');
            } else {
                $customers->where(function ($query) use ($selectedStatusValues, $includesUnassignedStatus): void {
                    if ($selectedStatusValues !== []) {
                        $query->whereIn('customer_status', $selectedStatusValues);
                    }

                    if ($includesUnassignedStatus) {
                        $selectedStatusValues === []
                            ? $query->whereNull('customer_status')
                            : $query->orWhereNull('customer_status');
                    }
                });
            }
        }

        match ($sort) {
            'name_desc' => $customers->orderByDesc('name')->orderByDesc('id'),
            'client_id_asc' => $customers
                ->orderByRaw('CASE WHEN client_id IS NULL THEN 1 ELSE 0 END')
                ->orderBy('client_id')->orderBy('name')->orderBy('id'),
            'client_id_desc' => $customers
                ->orderByRaw('CASE WHEN client_id IS NULL THEN 1 ELSE 0 END')
                ->orderByDesc('client_id')->orderBy('name')->orderBy('id'),
            'primary_first_asc' => $customers
                ->orderByRaw("CASE WHEN NULLIF(TRIM(first_name), '') IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw('LOWER(first_name) ASC')->orderBy('name')->orderBy('id'),
            'primary_first_desc' => $customers
                ->orderByRaw("CASE WHEN NULLIF(TRIM(first_name), '') IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw('LOWER(first_name) DESC')->orderBy('name')->orderBy('id'),
            'primary_last_asc' => $customers
                ->orderByRaw("CASE WHEN NULLIF(TRIM(last_name), '') IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw('LOWER(last_name) ASC')->orderBy('name')->orderBy('id'),
            'primary_last_desc' => $customers
                ->orderByRaw("CASE WHEN NULLIF(TRIM(last_name), '') IS NULL THEN 1 ELSE 0 END")
                ->orderByRaw('LOWER(last_name) DESC')->orderBy('name')->orderBy('id'),
            'group' => $customers
                ->orderByRaw('CASE WHEN customer_group IS NULL THEN 1 ELSE 0 END')
                ->orderBy('customer_group')->orderBy('name')->orderBy('id'),
            'status' => $customers
                ->orderByRaw('CASE WHEN customer_status IS NULL THEN 1 ELSE 0 END')
                ->orderBy('customer_status')->orderBy('name')->orderBy('id'),
            'updated_desc' => $customers->orderByDesc('updated_at')->orderByDesc('id'),
            default => $customers->orderBy('name')->orderBy('id'),
        };

        $customers = $customers
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Customer $customer): array => [
                'id' => $customer->id,
                'clientId' => $customer->client_id,
                'name' => $customer->name,
                'companyName' => $customer->company_name,
                'customerGroup' => $customer->customer_group,
                'customerStatus' => $customer->customer_status,
                'firstName' => $customer->first_name,
                'lastName' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'primaryAddress' => $this->primaryAddress($customer),
                'quickBooksActive' => $customer->quickbooks_active,
                'syncStatus' => $customer->quickbooks_sync_status,
                'lastSyncedAt' => $customer->quickbooks_last_synced_at?->toIso8601String(),
                'sitesCount' => $customer->sites_count,
                'projectsCount' => $customer->projects_count,
                'jobsCount' => $customer->work_orders_count,
            ]);

        return Inertia::render('Admin/Customers', [
            'customers' => $customers,
            'filters' => [
                'q' => $term,
                'groups' => $selectedGroups,
                'statuses' => $selectedStatuses,
                'excludedGroups' => $excludedGroups,
                'excludedStatuses' => $excludedStatuses,
                'showArchivedShortcut' => $showArchivedShortcut,
                'sort' => $sort,
            ],
            'groupOptions' => $groupOptions,
            'statusOptions' => $statusOptions,
        ]);
    }

    private function primaryAddress(Customer $customer): ?string
    {
        $address = $customer->defaultBillingAddress;

        if ($address === null) {
            return $customer->address;
        }

        $street = collect([
            $address->line1,
            $address->line2,
            $address->line3,
            $address->line4,
            $address->line5,
        ])->filter()->implode(', ');
        $locality = collect([
            $address->city,
            $address->country_subdivision_code,
            $address->postal_code,
        ])->filter()->implode(' ');

        return collect([$street, $locality, $address->country])->filter()->implode(', ') ?: null;
    }
}
