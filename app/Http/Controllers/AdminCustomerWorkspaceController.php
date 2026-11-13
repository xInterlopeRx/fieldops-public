<?php

namespace App\Http\Controllers;

use App\Enums\CustomerGroup;
use App\Enums\CustomerStatus;
use App\Enums\UsState;
use App\Http\Requests\UpdateAdminCustomerWorkspaceRequest;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\ContactMethod;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerAttachment;
use App\Models\CustomerEmailAddress;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Services\ActivityLogService;
use App\Services\ArchiveService;
use App\Services\AssignmentService;
use App\Services\AttachmentLifecycleService;
use App\Services\AttachmentListService;
use App\Services\ContactMethodService;
use App\Services\CustomerAddressBookService;
use App\Services\ProjectNumberGenerator;
use App\Services\QuickBooksApiClient;
use App\Services\QuickBooksCustomerSyncService;
use App\Services\QuickBooksCustomerWriteService;
use App\Services\QuickBooksSyncControl;
use App\Services\SharePointAttachmentUploadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminCustomerWorkspaceController extends Controller
{
    public function show(
        Customer $customer,
        AttachmentListService $attachmentLists,
        CustomerAddressBookService $addressBook,
        QuickBooksSyncControl $quickBooksSyncControl,
    ): JsonResponse {
        $sites = $customer->sites()
            ->with([
                'customerAddresses' => fn ($query) => $query->orderBy('id'),
                'primaryContact',
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'customer_id', 'name', 'address', 'primary_contact_id', 'quickbooks_write_request_id']);
        $contacts = $customer->contacts()
            ->with([
                'site:id,name',
                'methods' => fn ($query) => $query->orderBy('method_type')->orderBy('id'),
            ])
            ->orderBy('contact_type')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->limit(100)
            ->get();
        $emailAddresses = $customer->emailAddresses()
            ->orderBy('email')
            ->orderBy('id')
            ->limit(100)
            ->get();
        $attachments = $attachmentLists->forCustomerWorkspace($customer);
        $projects = $customer->projects()
            ->withCount('workOrders')
            ->orderBy('project_number')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'customer_id', 'project_number', 'name', 'status']);
        $jobs = WorkOrder::query()
            ->whereHas('site', fn (Builder $query) => $query->where('customer_id', $customer->getKey()))
            ->with(['site:id,name', 'project:id,project_number,name'])
            ->withCount('visits')
            ->orderBy('job_number')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'site_id', 'project_id', 'job_number', 'title', 'status', 'scope', 'job_notes', 'field_notes']);
        $activity = AuditEvent::query()
            ->where(function (Builder $query) use ($customer): void {
                $query->where(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new Customer)->getMorphClass())
                        ->where('subject_id', $customer->getKey());
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new Site)->getMorphClass())
                        ->whereIn('subject_id', Site::withArchived()->where('customer_id', $customer->getKey())->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new Project)->getMorphClass())
                        ->whereIn('subject_id', Project::withArchived()->where('customer_id', $customer->getKey())->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new WorkOrder)->getMorphClass())
                        ->whereIn('subject_id', WorkOrder::withArchived()
                            ->whereIn('site_id', Site::withArchived()->where('customer_id', $customer->getKey())->select('id'))
                            ->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new Visit)->getMorphClass())
                        ->whereIn('subject_id', Visit::withArchived()
                            ->whereIn('work_order_id', WorkOrder::withArchived()
                                ->whereIn('site_id', Site::withArchived()->where('customer_id', $customer->getKey())->select('id'))
                                ->select('id'))
                            ->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new Contact)->getMorphClass())
                        ->whereIn('subject_id', Contact::withArchived()->where('customer_id', $customer->getKey())->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new CustomerEmailAddress)->getMorphClass())
                        ->whereIn('subject_id', CustomerEmailAddress::withArchived()->where('customer_id', $customer->getKey())->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new CustomerAttachment)->getMorphClass())
                        ->whereIn('subject_id', CustomerAttachment::withArchived()->where('customer_id', $customer->getKey())->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new CustomerAddress)->getMorphClass())
                        ->whereIn('subject_id', CustomerAddress::withArchived()->where('customer_id', $customer->getKey())->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new ProjectAttachment)->getMorphClass())
                        ->whereIn('subject_id', ProjectAttachment::withArchived()
                            ->whereIn('project_id', Project::withArchived()->where('customer_id', $customer->getKey())->select('id'))
                            ->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($customer): void {
                    $subjectQuery->where('subject_type', (new WorkOrderAttachment)->getMorphClass())
                        ->whereIn('subject_id', WorkOrderAttachment::withArchived()
                            ->whereIn('work_order_id', WorkOrder::withArchived()
                                ->whereIn('site_id', Site::withArchived()->where('customer_id', $customer->getKey())->select('id'))
                                ->select('id'))
                            ->select('id'));
                });
            })
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'actor_user_id', 'subject_type', 'subject_id', 'event', 'summary', 'created_at']);
        $upcomingJobStatuses = collect(config('work_orders.statuses', []))
            ->filter(fn (array $status): bool => $status['active_for_upcoming_jobs'] ?? false)
            ->keys()
            ->all();
        $upcomingJobs = Visit::query()
            ->where('scheduled_at', '>=', now())
            ->whereHas('workOrder', fn (Builder $query) => $query->whereIn('status', $upcomingJobStatuses))
            ->whereHas('workOrder.site', fn (Builder $query) => $query->where('customer_id', $customer->getKey()))
            ->with([
                'workOrder:id,site_id,project_id,job_number,title,status',
                'workOrder.site:id,name',
                'workOrder.project:id,project_number,name',
            ])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit(60)
            ->get(['id', 'work_order_id', 'visit_type', 'scheduled_at'])
            ->unique('work_order_id')
            ->take(3)
            ->map(fn (Visit $visit): array => [
                'id' => $visit->workOrder->id,
                'number' => $visit->workOrder->job_number,
                'title' => $visit->workOrder->title,
                'site' => $visit->workOrder->site?->name,
                'project' => $visit->workOrder->project?->name,
                'scheduledAt' => $visit->scheduled_at?->toIso8601String(),
            ])
            ->values();
        $customer->load(['defaultBillingAddress', 'assignedUsers.roles']);
        $address = $customer->defaultBillingAddress;
        $shippingAddress = $addressBook->defaultsForForm($customer, 'shipping');

        return response()->json([
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'clientId' => $customer->client_id,
                    'name' => $customer->name,
                    'companyName' => $customer->company_name,
                    'customerGroup' => $customer->customer_group,
                    'customerStatus' => $customer->customer_status,
                    'customerGroupOptions' => CustomerGroup::options(),
                    'customerStatusOptions' => CustomerStatus::options(),
                    'notes' => $customer->notes,
                    'quickBooksSyncStatus' => $customer->quickbooks_sync_status,
                    'quickBooksSyncError' => $customer->quickbooks_sync_error,
                    'quickBooksLinked' => $customer->quickbooks_id !== null,
                    'quickBooksSyncEnabled' => $quickBooksSyncControl->isEnabled(),
                    'quickBooksWritePending' => $customer->quickbooks_write_request_id !== null,
                    'quickBooksLastSyncedAt' => $customer->quickbooks_last_synced_at?->toIso8601String(),
                ],
                'billingAddress' => $address?->only([
                    'line1', 'line2', 'line3', 'line4', 'line5', 'city',
                    'country_subdivision_code', 'postal_code', 'country',
                ]) ?? ['line1' => $customer->address],
                'shippingAddress' => $shippingAddress,
                'sites' => $sites->map(function (Site $site) use ($addressBook, $contacts): array {
                    $primaryContact = $site->primaryContact ?? $contacts->first(
                        fn (Contact $contact): bool => (int) $contact->site_id === (int) $site->getKey()
                            && $contact->contact_type === 'primary',
                    );

                    return [
                        'id' => $site->id,
                        'name' => $site->name,
                        'address' => $addressBook->siteAddressFields($site),
                        'addressSummary' => $addressBook->siteAddressSummary($site),
                        'primaryContactId' => $primaryContact?->getKey(),
                        'primaryContactName' => $primaryContact === null
                            ? null
                            : trim(implode(' ', array_filter([
                                $primaryContact->first_name,
                                $primaryContact->middle_name,
                                $primaryContact->last_name,
                            ]))),
                        'writePending' => $site->quickbooks_write_request_id !== null,
                    ];
                })->values(),
                'stateOptions' => UsState::options(),
                'contacts' => $contacts->map(fn (Contact $contact): array => [
                    'id' => $contact->id,
                    'siteId' => $contact->site_id,
                    'siteName' => $contact->site?->name,
                    'type' => $contact->contact_type,
                    'source' => $contact->source,
                    'firstName' => $contact->first_name,
                    'lastName' => $contact->last_name,
                    'middleName' => $contact->middle_name,
                    'title' => $contact->title,
                    'suffix' => $contact->suffix,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'alternatePhone' => $contact->alternate_phone,
                    'mobilePhone' => $contact->mobile_phone,
                    'fax' => $contact->fax,
                    'website' => $contact->website,
                    'methods' => $contact->methods->map(fn (ContactMethod $method): array => [
                        'id' => $method->getKey(),
                        'methodType' => $method->method_type->value,
                        'label' => $method->label->value,
                        'value' => $method->value,
                        'isPrimary' => $method->is_primary,
                    ])->values(),
                ])->values(),
                'emailAddresses' => $emailAddresses->map(fn (CustomerEmailAddress $email): array => [
                    'id' => $email->id,
                    'email' => $email->email,
                    'label' => $email->label,
                ])->values(),
                'attachments' => $attachments,
                'projects' => $projects->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'number' => $project->project_number,
                    'name' => $project->name,
                    'status' => $project->status,
                    'jobsCount' => $project->work_orders_count,
                ])->values(),
                'jobs' => $jobs->map(fn (WorkOrder $job): array => [
                    'id' => $job->id,
                    'number' => $job->job_number,
                    'title' => $job->title,
                    'status' => $job->status,
                    'site' => $job->site?->name,
                    'project' => $job->project?->name,
                    'visitsCount' => $job->visits_count,
                ])->values(),
                'upcomingJobs' => $upcomingJobs,
                'assignedUserIds' => $customer->assignedUsers->modelKeys(),
                'activity' => $activity->map(fn (AuditEvent $event): array => [
                    'id' => $event->id,
                    'summary' => $event->summary,
                    'actor' => $event->actor?->name ?? 'Former user',
                    'createdAt' => $event->created_at?->toIso8601String(),
                ])->values(),
            ],
            'staff' => User::query()
                ->with('roles:id,name')
                ->where('status', 'active')
                ->orderBy('name')
                ->limit(250)
                ->get(['id', 'name', 'email'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values()->all(),
                ])
                ->values(),
        ]);
    }

    public function storeProject(
        Request $request,
        Customer $customer,
        ActivityLogService $activityLog,
        ProjectNumberGenerator $projectNumbers,
    ): JsonResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'project_number' => ['nullable', 'string', 'max:255', Rule::unique('projects', 'project_number')],
            'site_ids' => ['sometimes', 'array', 'max:100'],
            'site_ids.*' => ['required', 'integer', 'distinct', 'exists:sites,id'],
            'new_site' => ['sometimes', 'array'],
            'new_site.name' => ['required_with:new_site', 'string', 'max:255'],
            'new_site.address' => ['nullable', 'string', 'max:1000'],
        ]);
        $requestedSiteIds = $data['site_ids'] ?? [];
        $siteIds = Site::query()
            ->where('customer_id', $customer->getKey())
            ->whereKey($requestedSiteIds)
            ->pluck('id')
            ->all();

        if (count($siteIds) !== count($requestedSiteIds)) {
            throw ValidationException::withMessages([
                'site_ids' => ['Choose only Sites belonging to this Client.'],
            ]);
        }

        [$project, $associatedSiteIds] = DB::transaction(function () use ($customer, $data, $siteIds, $activityLog, $projectNumbers, $request): array {
            $associatedSiteIds = $siteIds;

            if (isset($data['new_site'])) {
                $site = $customer->sites()->create([
                    'name' => $data['new_site']['name'],
                    'address' => $data['new_site']['address'] ?? null,
                    'metadata' => ['created_from' => 'project_form'],
                ]);
                $associatedSiteIds[] = $site->getKey();
                $activityLog->record($site, 'created', [], $request->user());
            }

            $project = Project::query()->create([
                'customer_id' => $customer->getKey(),
                'name' => $data['name'],
                'project_number' => $data['project_number'] ?? null,
                'status' => 'created',
            ]);
            $projectNumbers->assign($project);
            $project->sites()->sync($associatedSiteIds);
            $activityLog->record($project, 'created', [], $request->user());

            return [$project, $associatedSiteIds];
        });

        return response()->json([
            'data' => [
                'id' => $project->getKey(),
                'number' => $project->project_number,
                'name' => $project->name,
                'status' => $project->status,
                'jobsCount' => 0,
                'siteIds' => $associatedSiteIds,
            ],
        ], 201);
    }

    public function update(
        UpdateAdminCustomerWorkspaceRequest $request,
        Customer $customer,
        CustomerAddressBookService $addressBook,
        QuickBooksCustomerWriteService $quickBooksWrites,
        AssignmentService $assignments,
        ArchiveService $archives,
        ContactMethodService $contactMethods,
    ): JsonResponse {
        if (
            $customer->quickbooks_write_request_id !== null ||
            $customer->quickbooks_sync_status === 'pending' ||
            ($customer->quickbooks_id !== null && $customer->quickbooks_sync_status === 'failed')
        ) {
            return response()->json([
                'message' => 'QuickBooks synchronization is in progress or needs administrator resolution. Wait for it to finish before editing this customer.',
            ], 409);
        }

        $billingAddressChanged = $request->billingAddressChanged();
        $shippingAddressChanged = $request->shippingAddressChanged();
        $data = $request->validated();
        $actor = $request->user();
        $siteIdsToSync = [];
        $quickBooksFields = [];

        DB::transaction(function () use ($customer, $data, $actor, $addressBook, $assignments, $archives, $contactMethods, $billingAddressChanged, $shippingAddressChanged, &$siteIdsToSync, &$quickBooksFields): void {
            $sitesByRequestIndex = [];
            $createdContactsByClientKey = [];

            $hadCustomerPrimaryContact = Contact::query()
                ->where('customer_id', $customer->getKey())
                ->whereNull('site_id')
                ->where('contact_type', 'primary')
                ->lockForUpdate()
                ->first() !== null;
            $customerFields = collect($data)->only([
                'name', 'company_name', 'customer_group', 'customer_status', 'notes',
            ])->all();
            $customer->fill($customerFields);
            $quickBooksFields = array_values(array_intersect(
                array_keys($customer->getDirty()),
                ['name', 'company_name', 'notes'],
            ));
            $customer->save();

            if ($billingAddressChanged && $addressBook->saveDefault($customer, 'billing', $data['billing_address'])) {
                $quickBooksFields[] = 'BillAddr';
            }

            if ($shippingAddressChanged && $addressBook->saveDefault($customer, 'shipping', $data['shipping_address'])) {
                $quickBooksFields[] = 'ShipAddr';
            }

            foreach ($data['sites'] as $siteIndex => $siteData) {
                $site = isset($siteData['id'])
                    ? $customer->sites()->whereKey($siteData['id'])->firstOrFail()
                    : new Site(['customer_id' => $customer->getKey()]);

                if ($site->quickbooks_write_request_id !== null) {
                    abort(409, 'A QuickBooks site write is pending. Wait for it to finish before editing that site.');
                }

                $siteAddress = $siteData['address'];
                $site->fill([
                    'name' => $siteData['name'],
                    'address' => $addressBook->formatAddressFields($siteAddress),
                ]);
                $siteProfileChanged = ! $site->exists || $site->isDirty();

                if ($siteProfileChanged) {
                    $site->save();
                }

                $siteAddressChanged = $addressBook->saveSiteAddress($site, $siteAddress);

                if ($siteProfileChanged || $siteAddressChanged) {
                    $siteIdsToSync[] = (int) $site->getKey();
                }

                $sitesByRequestIndex[$siteIndex] = $site;
            }

            $siteIds = $customer->sites()->pluck('id')->all();
            foreach ($data['contacts'] as $contactData) {
                if (isset($contactData['site_id']) && ! in_array((int) $contactData['site_id'], $siteIds, true)) {
                    throw ValidationException::withMessages([
                        'contacts' => 'Contacts must be associated with a site belonging to this customer.',
                    ]);
                }

                $contact = isset($contactData['id'])
                    ? $customer->contacts()->whereKey($contactData['id'])->firstOrFail()
                    : new Contact(['customer_id' => $customer->getKey(), 'source' => 'manual']);
                $remove = (bool) ($contactData['remove'] ?? false);
                $methodData = $contactData['methods'] ?? null;
                $clientKey = $contactData['client_key'] ?? null;
                $originalContactType = $contact->contact_type;
                $originalSiteId = $contact->site_id;
                unset($contactData['id'], $contactData['client_key'], $contactData['remove'], $contactData['methods']);

                if ($contact->exists && $contact->source === 'quickbooks') {
                    $contact->fill($contactData);

                    if ($contact->contact_type !== $originalContactType || $contact->site_id !== $originalSiteId) {
                        abort(409, 'QuickBooks-sourced contacts cannot be retyped or moved to another Site.');
                    }

                    if ($remove) {
                        $contact->fill([
                            'first_name' => null,
                            'middle_name' => null,
                            'last_name' => null,
                            'title' => null,
                            'suffix' => null,
                            'email' => null,
                            'phone' => null,
                            'alternate_phone' => null,
                            'mobile_phone' => null,
                            'fax' => null,
                            'website' => null,
                        ]);
                    }

                    if ($contact->isDirty()) {
                        $contact->save();

                        if ($contact->site_id !== null && $contact->contact_type === 'primary') {
                            $siteIdsToSync[] = (int) $contact->site_id;
                        }
                    }

                    if (is_array($methodData)) {
                        $contactMethods->sync($contact, $methodData);
                    }

                    continue;
                }

                if ($remove) {
                    if ($contact->exists) {
                        $referencingSites = Site::query()
                            ->where('primary_contact_id', $contact->getKey())
                            ->lockForUpdate()
                            ->get(['id', 'primary_contact_id']);

                        foreach ($referencingSites as $referencingSite) {
                            $referencingSite->forceFill(['primary_contact_id' => null])->save();
                            $siteIdsToSync[] = (int) $referencingSite->getKey();
                        }

                        $archives->archive('contacts', $contact->getKey(), $actor, 'Removed from customer profile.');
                    }

                    continue;
                }

                $this->makeContactRoleAvailable(
                    $customer,
                    $contact,
                    $contactData['contact_type'],
                    isset($contactData['site_id']) ? (int) $contactData['site_id'] : null,
                    $siteIdsToSync,
                );

                $contact->fill($contactData);
                if ($contact->isDirty()) {
                    $contact->save();

                    if ($originalSiteId !== null && $originalContactType === 'primary') {
                        $siteIdsToSync[] = (int) $originalSiteId;
                    }

                    if ($contact->site_id !== null && $contact->contact_type === 'primary') {
                        $siteIdsToSync[] = (int) $contact->site_id;
                    }
                }

                if (is_array($methodData)) {
                    $contactMethods->sync($contact, $methodData);
                }

                if (is_string($clientKey) && $clientKey !== '') {
                    $createdContactsByClientKey[$clientKey] = $contact;
                }
            }

            foreach ($sitesByRequestIndex as $siteIndex => $site) {
                $siteData = $data['sites'][$siteIndex];
                $clientKey = $siteData['primary_contact_key'] ?? null;
                $selectedContact = null;

                if (is_string($clientKey) && $clientKey !== '') {
                    $selectedContact = $createdContactsByClientKey[$clientKey] ?? null;
                } elseif (isset($siteData['primary_contact_id'])) {
                    $selectedContact = $customer->contacts()->whereKey($siteData['primary_contact_id'])->first();
                }

                if (($siteData['primary_contact_id'] ?? null) !== null && $selectedContact === null) {
                    throw ValidationException::withMessages([
                        "sites.{$siteIndex}.primary_contact_id" => 'The selected contact does not belong to this customer or is no longer available.',
                    ]);
                }

                if ($selectedContact !== null && (int) $selectedContact->customer_id !== (int) $customer->getKey()) {
                    throw ValidationException::withMessages([
                        "sites.{$siteIndex}.primary_contact_id" => 'Choose a contact belonging to this Customer.',
                    ]);
                }

                $primaryContactId = $selectedContact?->getKey();
                $currentPrimaryContactId = $site->primary_contact_id === null
                    ? null
                    : (int) $site->primary_contact_id;
                $nextPrimaryContactId = $primaryContactId === null ? null : (int) $primaryContactId;

                if ($currentPrimaryContactId !== $nextPrimaryContactId) {
                    $site->forceFill(['primary_contact_id' => $primaryContactId])->save();
                    $siteIdsToSync[] = (int) $site->getKey();
                }
            }

            $customerPrimaryContact = Contact::query()
                ->where('customer_id', $customer->getKey())
                ->whereNull('site_id')
                ->where('contact_type', 'primary')
                ->first();

            if ($customerPrimaryContact !== null || $hadCustomerPrimaryContact) {
                $customer->fill([
                    'first_name' => $customerPrimaryContact?->first_name,
                    'middle_name' => $customerPrimaryContact?->middle_name,
                    'last_name' => $customerPrimaryContact?->last_name,
                    'title' => $customerPrimaryContact?->title,
                    'suffix' => $customerPrimaryContact?->suffix,
                    'email' => $customerPrimaryContact?->email,
                    'phone' => $customerPrimaryContact?->phone,
                    'alternate_phone' => $customerPrimaryContact?->alternate_phone,
                    'mobile_phone' => $customerPrimaryContact?->mobile_phone,
                    'fax' => $customerPrimaryContact?->fax,
                    'website' => $customerPrimaryContact?->website,
                ]);
                $primaryContactFields = array_keys($customer->getDirty());

                if ($primaryContactFields !== []) {
                    $customer->save();
                    $quickBooksFields = array_values(array_unique([...$quickBooksFields, ...$primaryContactFields]));
                }
            }

            foreach ($data['email_addresses'] as $emailData) {
                $emailAddress = isset($emailData['id'])
                    ? $customer->emailAddresses()->whereKey($emailData['id'])->firstOrFail()
                    : new CustomerEmailAddress(['customer_id' => $customer->getKey()]);
                $remove = (bool) ($emailData['remove'] ?? false);
                unset($emailData['id'], $emailData['remove']);

                if ($remove) {
                    if ($emailAddress->exists) {
                        $archives->archive('customer-email-addresses', $emailAddress->getKey(), $actor, 'Removed from customer profile.');
                    }

                    continue;
                }

                $emailAddress->fill($emailData);
                $emailAddress->save();
            }

            $assignments->sync($customer, array_map('intval', $data['assigned_user_ids']), $actor);
        });

        $customer->refresh();
        $customerSyncStatus = $quickBooksFields === []
            ? null
            : $quickBooksWrites->queueCustomer($customer, array_values(array_unique($quickBooksFields)), createIfUnlinked: true);
        $siteSyncStatuses = collect(array_unique($siteIdsToSync))
            ->mapWithKeys(fn (int $siteId): array => [$siteId => $quickBooksWrites->queueSite(Site::query()->findOrFail($siteId))])
            ->all();
        $quickBooksWarnings = collect([$customerSyncStatus, ...array_values($siteSyncStatuses)])
            ->filter(fn (?string $status): bool => in_array($status, ['not_connected', 'unlinked', 'failed', 'paused'], true))
            ->unique()
            ->map(fn (string $status): string => match ($status) {
                'not_connected' => 'QuickBooks is not connected for this environment. Changes were saved in FieldOps but not queued for QuickBooks.',
                'unlinked' => 'A related Site is not linked to a QuickBooks Customer. That Site remains local to FieldOps.',
                'failed' => 'The QuickBooks write could not be queued. Check the sync status and connection.',
                'paused' => 'QuickBooks sync is paused. This change is queued and held until sync resumes.',
            })
            ->values();

        return response()->json([
            'message' => 'Customer profile saved.',
            'quickBooksCustomerStatus' => $customerSyncStatus,
            'quickBooksSiteStatuses' => $siteSyncStatuses,
            'quickBooksWarnings' => $quickBooksWarnings,
        ]);
    }

    /** @param list<int> $siteIdsToSync */
    private function makeContactRoleAvailable(
        Customer $customer,
        Contact $contact,
        string $contactType,
        ?int $siteId,
        array &$siteIdsToSync,
    ): void {
        if ($contactType === 'other') {
            return;
        }

        $conflictQuery = Contact::query()
            ->where('customer_id', $customer->getKey())
            ->where('contact_type', $contactType);

        if ($siteId === null) {
            $conflictQuery->whereNull('site_id');
        } else {
            $conflictQuery->where('site_id', $siteId);
        }

        if ($contact->exists) {
            $conflictQuery->whereKeyNot($contact->getKey());
        }

        $existingContact = $conflictQuery->lockForUpdate()->first();

        if ($existingContact === null) {
            return;
        }

        if ($existingContact->source === 'quickbooks') {
            throw ValidationException::withMessages([
                'contacts' => 'A QuickBooks-sourced contact role cannot be reassigned in FieldOps.',
            ]);
        }

        $existingContact->forceFill(['contact_type' => 'other'])->save();

        if ($existingContact->site_id !== null) {
            $siteIdsToSync[] = (int) $existingContact->site_id;
        }
    }

    public function resolveQuickBooksConflict(
        Request $request,
        Customer $customer,
        QuickBooksApiClient $quickBooks,
        QuickBooksCustomerSyncService $quickBooksSync,
        QuickBooksSyncControl $quickBooksSyncControl,
        ActivityLogService $activityLog,
    ): JsonResponse {
        if (! $quickBooksSyncControl->isEnabled()) {
            return response()->json([
                'message' => 'QuickBooks synchronization is paused by an Admin. Resume it before resolving from QuickBooks.',
            ], 409);
        }

        if ($customer->quickbooks_id === null) {
            return response()->json(['message' => 'This customer is not linked to QuickBooks.'], 422);
        }

        if (! $quickBooks->isConnectedForCurrentEnvironment()) {
            return response()->json(['message' => 'QuickBooks is not connected for the configured environment.'], 409);
        }

        $cleared = DB::transaction(function () use ($customer, $request, $activityLog): bool {
            $lockedCustomer = Customer::query()->lockForUpdate()->findOrFail($customer->getKey());

            if ($lockedCustomer->quickbooks_sync_status !== 'failed') {
                return false;
            }

            $discardedFields = $lockedCustomer->quickbooks_write_fields ?? [];
            $pendingSiteIds = Site::query()
                ->where('customer_id', $lockedCustomer->getKey())
                ->whereNotNull('quickbooks_write_request_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            $lockedCustomer->forceFill([
                'quickbooks_sync_status' => 'idle',
                'quickbooks_sync_error' => null,
                'quickbooks_write_request_id' => null,
                'quickbooks_write_fields' => null,
                'quickbooks_write_payload' => null,
            ])->save();

            if ($pendingSiteIds !== []) {
                Site::query()
                    ->whereKey($pendingSiteIds)
                    ->update(['quickbooks_write_request_id' => null]);
            }

            $activityLog->record($lockedCustomer, 'quickbooks_refreshed', [
                'discarded_fields' => $discardedFields,
                'site_ids' => $pendingSiteIds,
            ], $request->user());

            return true;
        });

        if (! $cleared) {
            return response()->json([
                'message' => 'QuickBooks sync is not in a failed state. Wait for the current operation to finish.',
            ], 409);
        }

        $status = $quickBooksSync->requestRefresh($customer->fresh(), force: true);

        if ($status !== 'pending') {
            return response()->json([
                'status' => $status,
                'message' => $status === 'failed'
                    ? 'The QuickBooks refresh could not be queued. Try again or contact an administrator.'
                    : 'QuickBooks could not be refreshed. Check the connection and try again.',
            ], 503);
        }

        return response()->json([
            'status' => 'pending',
            'message' => 'Refresh queued. QuickBooks values will replace conflicting synchronized fields when the refresh completes.',
        ], 202);
    }

    public function uploadAttachment(
        Request $request,
        Customer $customer,
        SharePointAttachmentUploadService $attachments,
        AttachmentListService $attachmentLists,
        AttachmentLifecycleService $attachmentEvents,
    ): JsonResponse {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,mp4,mov,webm,pdf,txt,csv,doc,docx,xls,xlsx,ppt,pptx'],
        ]);
        try {
            $attachment = $attachments->uploadForCustomer($validated['file'], $customer, $request->user());
        } catch (\Throwable $exception) {
            $attachmentEvents->failed($customer, $request->user());

            throw $exception;
        }

        return response()->json([
            'data' => $attachmentLists->customerUploadItem($attachment->load('uploader:id,name')),
        ], 201);
    }

    public function attachment(Request $request, CustomerAttachment $attachment): mixed
    {
        $headers = ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff'];

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            $headers,
        );
    }
}
