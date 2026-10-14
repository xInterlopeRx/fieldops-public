<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminCustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $term = trim($validated['q'] ?? '');

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
            ->withCount(['sites', 'projects', 'workOrders'])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Customer $customer): array => [
                'id' => $customer->id,
                'clientId' => $customer->client_id,
                'name' => $customer->name,
                'companyName' => $customer->company_name,
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
            'filters' => ['q' => $term],
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
