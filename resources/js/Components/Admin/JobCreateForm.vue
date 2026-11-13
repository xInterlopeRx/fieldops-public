<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { Link, usePage } from "@inertiajs/vue3";
import { PlusIcon } from "@heroicons/vue/24/outline";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import type { GlobalSearchResult } from "@/Composables/useGlobalSearchAutocomplete";
import { useWorkOrderStatus } from "@/Composables/useWorkOrderStatus";

interface Contact {
    id: number;
    contact_type: string;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    phone: string | null;
}

interface Site {
    id: number;
    name: string;
    address: string | null;
    primary_contact: Contact | null;
}

interface Customer {
    id: number;
    client_id: string | null;
    name: string;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    phone: string | null;
    sites: Site[];
    contacts: Contact[];
    quickbooks_sync: {
        status: string;
        linked: boolean;
        write_pending: boolean;
        last_synced_at: string | null;
        error: string | null;
    };
}

type ContactRole = "client_contact" | "technical_contact" | "project_manager" | "contractor_check_in";

const props = withDefaults(defineProps<{
    customerId?: number | null;
    customerLocked?: boolean;
    siteId?: number | null;
    projectId?: number | null;
    allowedSiteIds?: number[];
    scheduledAt?: string | null;
    initialStatus?: string | null;
}>(), {
    customerId: null,
    customerLocked: false,
    siteId: null,
    projectId: null,
    allowedSiteIds: () => [],
    scheduledAt: null,
    initialStatus: null,
});

const emit = defineEmits<{
    cancel: [];
    created: [jobId: number];
    "dirty-change": [dirty: boolean];
}>();

const page = usePage();
const { definition: statusDefinition, label: statusLabel, badgeClass: statusBadgeClass } = useWorkOrderStatus();
const statuses = computed(() => page.props.work_order_statuses.filter((status) => status.calendar_selectable));
const selectedCustomer = ref<Customer | null>(null);
const customerQuery = ref("");
const customerMode = ref<"search" | "create">("search");
const siteQuery = ref("");
const siteMode = ref<"select" | "create">("select");
const saving = ref(false);
const loadingCustomer = ref(false);
const errors = ref<Record<string, string[]>>({});
const errorMessage = ref("");
const scheduleConflict = ref("");
let customerPollGeneration = 0;

const form = reactive({
    title: "",
    subtitle: "",
    scope: "",
    client_completion_summary: "",
    job_notes: "",
    field_notes: "",
    status: props.initialStatus ?? page.props.work_order_default_status,
    pending_reason: "",
    scheduled_at: props.scheduledAt ?? "",
    scheduling_override: false,
    customer_id: null as number | null,
    customer_name: "",
    customer_email: "",
    customer_phone: "",
    customer_address: "",
    site_id: null as number | null,
    site_name: "",
    site_address: "",
    client_contact_id: null as number | null,
    client_contact_name: "",
    client_contact_phone: "",
    client_contact_email: "",
    technical_contact_id: null as number | null,
    technical_contact_name: "",
    technical_contact_phone: "",
    technical_contact_email: "",
    project_manager_contact_id: null as number | null,
    project_manager_name: "",
    project_manager_phone: "",
    project_manager_email: "",
    contractor_check_in_contact_id: null as number | null,
    contractor_check_in_name: "",
    contractor_check_in_phone: "",
    contractor_check_in_email: "",
    files: [] as File[],
});
const initialFormSnapshot = ref<string | null>(null);

const formSnapshot = (): string => {
    const { files, ...fields } = form;

    return JSON.stringify({
        fields,
        files: files.map((file) => [file.name, file.size, file.lastModified]),
        customerQuery: customerQuery.value,
        siteQuery: siteQuery.value,
    });
};

const hasFormChanges = computed(() => initialFormSnapshot.value !== null
    && formSnapshot() !== initialFormSnapshot.value);
const hasUnsavedChanges = (): boolean => hasFormChanges.value;

watch(hasFormChanges, (dirty) => emit("dirty-change", dirty), { flush: "sync", immediate: true });

const error = (key: string): string | undefined => errors.value[key]?.[0];
const isRequired = (key: "job_notes_required" | "client_completion_summary_required" | "pending_reason_required"): boolean =>
    statusDefinition(form.status)?.[key] ?? false;
const availableSites = computed(() => {
    const sites = selectedCustomer.value?.sites ?? [];

    return props.allowedSiteIds.length === 0
        ? sites
        : sites.filter((site) => props.allowedSiteIds.includes(site.id));
});
const selectedSite = computed(() => availableSites.value.find((site) => site.id === form.site_id) ?? null);
const contactRoles: Array<{ key: ContactRole; label: string; idField: keyof typeof form; prefix: string }> = [
    { key: "client_contact", label: "Customer contact", idField: "client_contact_id", prefix: "client_contact" },
    { key: "technical_contact", label: "Technical contact", idField: "technical_contact_id", prefix: "technical_contact" },
    { key: "project_manager", label: "Project manager", idField: "project_manager_contact_id", prefix: "project_manager" },
    { key: "contractor_check_in", label: "Contractor check-in contact", idField: "contractor_check_in_contact_id", prefix: "contractor_check_in" },
];

const snapshotContact = (role: ContactRole, contact: Contact | null): void => {
    const idFields: Record<ContactRole, keyof typeof form> = {
        client_contact: "client_contact_id",
        technical_contact: "technical_contact_id",
        project_manager: "project_manager_contact_id",
        contractor_check_in: "contractor_check_in_contact_id",
    };
    const prefix = role;
    const idField = idFields[role];
    (form as unknown as Record<string, string | number | null>)[idField] = contact?.id ?? null;
    (form as unknown as Record<string, string | number | null>)[`${prefix}_name`] = contact
        ? [contact.first_name, contact.last_name].filter(Boolean).join(" ")
        : "";
    (form as unknown as Record<string, string | number | null>)[`${prefix}_phone`] = contact?.phone ?? "";
    (form as unknown as Record<string, string | number | null>)[`${prefix}_email`] = contact?.email ?? "";
};

const fillClientContactFallback = (): void => {
    const contact = selectedSite.value?.primary_contact
        ?? selectedCustomer.value?.contacts.find((item) => item.contact_type === "primary")
        ?? null;

    if (contact) {
        snapshotContact("client_contact", contact);
        return;
    }

    snapshotContact("client_contact", null);
    form.client_contact_name = [selectedCustomer.value?.first_name, selectedCustomer.value?.last_name]
        .filter(Boolean)
        .join(" ");
    form.client_contact_phone = selectedCustomer.value?.phone ?? "";
    form.client_contact_email = selectedCustomer.value?.email ?? "";
};

const clearContactSelections = (): void => {
    contactRoles.forEach((role) => snapshotContact(role.key, null));
};

const loadCustomer = async (customerId: number, preferredSiteId: number | null = null): Promise<void> => {
    loadingCustomer.value = true;
    errorMessage.value = "";

    try {
        const response = await axios.get(route("customers.show", customerId), {
            headers: { Accept: "application/json" },
        });
        const payload = response.data as {
            data: Omit<Customer, "quickbooks_sync">;
            quickbooks_sync: Customer["quickbooks_sync"];
        };
        selectedCustomer.value = { ...payload.data, quickbooks_sync: payload.quickbooks_sync };
        customerMode.value = "search";
        form.customer_id = payload.data.id;
        form.customer_name = "";
        customerQuery.value = payload.data.name;
        clearContactSelections();
        siteMode.value = "select";
        siteQuery.value = "";
        const availableCustomerSites = props.allowedSiteIds.length === 0
            ? payload.data.sites
            : payload.data.sites.filter((site) => props.allowedSiteIds.includes(site.id));
        form.site_id = availableCustomerSites.find((site) => site.id === preferredSiteId)?.id
            ?? availableCustomerSites[0]?.id
            ?? null;
        fillClientContactFallback();
        if (form.site_id === null) siteMode.value = "create";
        if (payload.quickbooks_sync.status === "pending") void pollCustomerStatus(customerId);
    } catch {
        errorMessage.value = "The Customer could not be loaded. Search again and retry.";
    } finally {
        loadingCustomer.value = false;
    }
};

const pollCustomerStatus = async (customerId: number): Promise<void> => {
    const generation = ++customerPollGeneration;

    for (let attempt = 0; attempt < 60; attempt += 1) {
        await new Promise<void>((resolve) => window.setTimeout(resolve, 2000));
        if (generation !== customerPollGeneration || selectedCustomer.value?.id !== customerId) return;

        try {
            const response = await axios.get(route("customers.show", customerId), {
                headers: { Accept: "application/json" },
            });
            const payload = response.data as {
                data: Omit<Customer, "quickbooks_sync">;
                quickbooks_sync: Customer["quickbooks_sync"];
            };
            const selectedSiteId = form.site_id;
            selectedCustomer.value = { ...payload.data, quickbooks_sync: payload.quickbooks_sync };
            const availableCustomerSites = props.allowedSiteIds.length === 0
                ? payload.data.sites
                : payload.data.sites.filter((site) => props.allowedSiteIds.includes(site.id));
            if (!availableCustomerSites.some((site) => site.id === selectedSiteId)) {
                form.site_id = availableCustomerSites[0]?.id ?? null;
                if (form.site_id === null) siteMode.value = "create";
            }
            fillClientContactFallback();

            if (payload.quickbooks_sync.status !== "pending") return;
        } catch {
            return;
        }
    }
};

const selectCustomer = (result: GlobalSearchResult): void => {
    if (result.type === "client") void loadCustomer(result.id);
};

const selectSite = (result: GlobalSearchResult): void => {
    if (result.type !== "site" || result.parent_id === undefined) return;
    if (props.allowedSiteIds.length > 0 && !props.allowedSiteIds.includes(result.id)) {
        errorMessage.value = "Choose a Site linked to this Project.";
        return;
    }
    void loadCustomer(result.parent_id, result.id);
};

const clearCustomer = (): void => {
    customerPollGeneration += 1;
    selectedCustomer.value = null;
    form.customer_id = null;
    form.site_id = null;
    customerQuery.value = "";
    siteQuery.value = "";
    customerMode.value = "search";
    siteMode.value = "select";
    clearContactSelections();
};

const createCustomerInline = (): void => {
    customerPollGeneration += 1;
    selectedCustomer.value = null;
    form.customer_id = null;
    form.site_id = null;
    customerQuery.value = "";
    siteQuery.value = "";
    customerMode.value = "create";
    siteMode.value = "create";
    clearContactSelections();
};

const createSite = (): void => {
    form.site_id = null;
    siteMode.value = "create";
    fillClientContactFallback();
};

const chooseExistingSite = (): void => {
    siteMode.value = "select";
    form.site_id = selectedCustomer.value?.sites[0]?.id ?? null;
    fillClientContactFallback();
};

const onSiteChanged = (): void => fillClientContactFallback();

const changeContact = (role: ContactRole, event: Event): void => {
    const contactId = Number((event.target as HTMLSelectElement).value) || null;
    const contact = contactId === null
        ? null
        : selectedCustomer.value?.contacts.find((candidate) => candidate.id === contactId) ?? null;
    snapshotContact(role, contact);
};

const updateContact = (role: ContactRole, field: "name" | "phone" | "email", event: Event): void => {
    const prefix = role === "project_manager" ? "project_manager" : role;
    (form as unknown as Record<string, string | number | null>)[`${prefix}_${field}`] = (event.target as HTMLInputElement).value;
};

const contactValue = (role: ContactRole, field: "name" | "phone" | "email"): string => {
    const prefix = role === "project_manager" ? "project_manager" : role;
    const value = (form as unknown as Record<string, string | number | null>)[`${prefix}_${field}`];

    return typeof value === "string" ? value : "";
};

const handleFiles = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    form.files = input.files ? Array.from(input.files) : [];
};

const append = (body: FormData, key: string, value: string | number | boolean | null): void => {
    if (value === null) return;

    body.append(key, typeof value === "boolean" ? (value ? "1" : "0") : String(value));
};

const submit = async (): Promise<boolean> => {
    if (saving.value || loadingCustomer.value) return false;

    saving.value = true;
    errors.value = {};
    errorMessage.value = "";
    scheduleConflict.value = "";

    const body = new FormData();
    Object.entries(form).forEach(([key, value]) => {
        if (key !== "files") append(body, key, value as string | number | boolean | null);
    });
    append(body, "project_id", props.projectId ?? null);
    form.files.forEach((file) => body.append("files[]", file));

    try {
        const response = await axios.post(route("work-orders.store"), body, {
            headers: { Accept: "application/json" },
        });
        emit("created", Number(response.data.data.id));
        return true;
    } catch (requestError: unknown) {
        const response = (requestError as {
            response?: { data?: { errors?: Record<string, string[]>; message?: string } };
        }).response;
        errors.value = response?.data?.errors ?? {};
        scheduleConflict.value = errors.value.scheduled_at?.[0] ?? "";
        errorMessage.value = response?.data?.errors
            ? "Review the highlighted fields and try again."
            : response?.data?.message ?? "The Job could not be created. Try again.";

        return false;
    } finally {
        saving.value = false;
    }
};

const proceedWithScheduleConflict = (): void => {
    form.scheduling_override = true;
    void submit();
};

onMounted(() => {
    initialFormSnapshot.value = formSnapshot();

    if (props.customerId !== null) {
        void loadCustomer(props.customerId, props.siteId).finally(() => {
            initialFormSnapshot.value = formSnapshot();
        });
    }
});

onBeforeUnmount(() => {
    customerPollGeneration += 1;
});

defineExpose({ hasUnsavedChanges, submit });
</script>

<template>
    <form class="grid min-w-0 grid-cols-1 gap-4" :inert="loadingCustomer" @submit.prevent="submit">
        <label class="fieldops-workspace-field">
            Job title
            <input v-model="form.title" required maxlength="255" autofocus />
            <span v-if="error('title')" class="fieldops-client-error">{{ error('title') }}</span>
        </label>
        <label class="fieldops-workspace-field">Subtitle<input v-model="form.subtitle" maxlength="255" /></label>

        <section class="grid gap-2">
            <label class="fieldops-client-label">Customer</label>
            <div v-if="selectedCustomer" class="flex min-h-11 items-center justify-between gap-3 rounded-lg border border-fieldops-blue-dark bg-white px-3 py-2 text-sm">
                <span class="min-w-0 truncate"><strong>{{ selectedCustomer.name }}</strong><small v-if="selectedCustomer.client_id" class="ml-2 text-gray-500">{{ selectedCustomer.client_id }}</small></span>
                <button v-if="!customerLocked" type="button" class="shrink-0 text-xs font-bold uppercase text-fieldops-blue-dark underline" @click="clearCustomer">Change</button>
            </div>
            <div v-else-if="customerMode === 'search'" class="flex items-stretch gap-2">
                <ClearableSearchInput id="job-create-client" v-model="customerQuery" class="fieldops-client-input fieldops-entity-picker-input min-w-0 flex-1" placeholder="Search customers" clear-label="Clear Customer search" :suggestion-types="['client']" :dispatch-selection="false" show-search-icon search-icon-label="Search customers" @select="selectCustomer" />
                <button type="button" class="fieldops-entity-picker-add-button fieldops-add-action shrink-0" aria-label="Enter new Customer details" title="Enter new Customer details" @click="createCustomerInline">
                    <PlusIcon class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            <div v-else class="grid gap-2 sm:grid-cols-2">
                <input v-model="form.customer_name" class="fieldops-client-input" placeholder="New Customer name" required maxlength="255" />
                <input v-model="form.customer_email" class="fieldops-client-input" type="email" placeholder="Customer email" maxlength="255" />
                <input v-model="form.customer_phone" class="fieldops-client-input" type="tel" placeholder="Customer phone" maxlength="50" />
                <input v-model="form.customer_address" class="fieldops-client-input" placeholder="Customer address" maxlength="1000" />
                <button type="button" class="fieldops-secondary-action justify-self-start" @click="customerMode = 'search'; siteMode = 'select'">Choose existing Customer</button>
            </div>
            <span v-if="error('customer_id') || error('customer_name')" class="fieldops-client-error">{{ error('customer_id') || error('customer_name') }}</span>
            <p v-if="selectedCustomer?.quickbooks_sync.status === 'pending'" role="status" class="text-xs text-gray-600">Checking QuickBooks for current Customer and Site details.</p>
            <p v-else-if="selectedCustomer?.quickbooks_sync.status === 'sync_paused'" role="status" class="text-xs text-amber-900">QuickBooks sync is paused by an Admin.</p>
        </section>

        <section class="grid gap-2">
            <label id="job-create-site-label" class="fieldops-client-label">Site</label>
            <div v-if="!selectedCustomer && customerMode === 'search'" class="flex items-stretch gap-2">
                <ClearableSearchInput id="job-create-site-search" v-model="siteQuery" class="fieldops-client-input fieldops-entity-picker-input min-w-0 flex-1" placeholder="Search Sites" clear-label="Clear Site search" :suggestion-types="['site']" :dispatch-selection="false" show-search-icon search-icon-label="Search Sites" @select="selectSite" />
                <button type="button" class="fieldops-entity-picker-add-button fieldops-add-action shrink-0" aria-label="Add new Site" title="Select a Customer before adding a Site" disabled>
                    <PlusIcon class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            <div v-else-if="siteMode === 'select' && selectedCustomer" class="flex items-stretch gap-2">
                <select id="job-create-site" v-model="form.site_id" aria-labelledby="job-create-site-label" class="fieldops-client-input fieldops-entity-picker-input min-w-0 flex-1" @change="onSiteChanged">
                    <option :value="null">Select a Site</option>
                    <option v-for="site in availableSites" :key="site.id" :value="site.id">{{ site.name }}</option>
                </select>
                <button type="button" class="fieldops-entity-picker-add-button fieldops-add-action shrink-0" aria-label="Add new Site" title="Add new Site" @click="createSite">
                    <PlusIcon class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            <div v-else class="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                <div class="grid gap-2 sm:grid-cols-2">
                    <input v-model="form.site_name" class="fieldops-client-input" placeholder="New Site name" required maxlength="255" />
                    <input v-model="form.site_address" class="fieldops-client-input" placeholder="Site address" maxlength="1000" />
                </div>
                <button v-if="selectedCustomer" type="button" class="fieldops-secondary-action justify-self-start" @click="chooseExistingSite">Choose existing Site</button>
            </div>
            <span v-if="error('site_id') || error('site_name')" class="fieldops-client-error">{{ error('site_id') || error('site_name') }}</span>
        </section>

            <p v-if="selectedCustomer?.quickbooks_sync.status === 'pending'" role="status" class="text-xs text-gray-600">Checking QuickBooks. Site and contact details will update when the check finishes.</p>
            <p v-else-if="selectedCustomer?.quickbooks_sync.status === 'sync_paused'" role="status" class="text-xs text-amber-900">
                QuickBooks sync is paused by an Admin.
                <Link :href="route('admin.integrations.quickbooks.show')" class="font-semibold underline">Manage QuickBooks sync</Link>
            </p>

        <section class="grid gap-3" aria-labelledby="job-create-contacts-heading">
            <h3 id="job-create-contacts-heading" class="fieldops-client-label mb-0">Contacts</h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <div v-for="role in contactRoles" :key="role.key" class="grid gap-2 rounded-xl bg-white/35 p-3">
                    <label :for="`job-create-${role.key}-picker`" class="fieldops-client-label mb-0">{{ role.label }}</label>
                    <select :id="`job-create-${role.key}-picker`" :value="form[role.idField] ?? ''" class="fieldops-client-input" :disabled="!selectedCustomer?.contacts.length" @change="changeContact(role.key, $event)">
                        <option value="">No contact selected</option>
                        <option v-for="contact in selectedCustomer?.contacts ?? []" :key="contact.id" :value="contact.id">{{ [contact.first_name, contact.last_name].filter(Boolean).join(' ') || contact.email || contact.phone || `Contact ${contact.id}` }} · {{ contact.contact_type }}</option>
                    </select>
                    <input :aria-label="`${role.label} name`" :value="contactValue(role.key, 'name')" class="fieldops-client-input" :readonly="form[role.idField] !== null" placeholder="Name" @input="updateContact(role.key, 'name', $event)" />
                    <input :aria-label="`${role.label} phone`" :value="contactValue(role.key, 'phone')" class="fieldops-client-input" type="tel" :readonly="form[role.idField] !== null" placeholder="Phone" @input="updateContact(role.key, 'phone', $event)" />
                    <input :aria-label="`${role.label} email`" :value="contactValue(role.key, 'email')" class="fieldops-client-input" type="email" :readonly="form[role.idField] !== null" placeholder="Email" @input="updateContact(role.key, 'email', $event)" />
                    <span v-if="error(`${role.prefix}_email`)" class="fieldops-client-error">{{ error(`${role.prefix}_email`) }}</span>
                </div>
            </div>
        </section>

        <section v-if="props.projectId !== null" class="grid min-w-0 gap-3" aria-labelledby="job-create-scope-notes-heading">
            <h3 id="job-create-scope-notes-heading" class="fieldops-client-label mb-0">Scope and notes</h3>
            <label class="fieldops-workspace-field min-w-0">
                Job Scope (required)
                <textarea v-model="form.scope" rows="4" required maxlength="20000" />
                <span v-if="error('scope')" class="fieldops-client-error">{{ error('scope') }}</span>
            </label>
            <label class="fieldops-workspace-field min-w-0">
                Internal Job Notes
                <span class="text-xs font-normal text-gray-600">For staff; excluded from client-facing updates and reports.</span>
                <textarea v-model="form.job_notes" rows="3" :required="isRequired('job_notes_required')" maxlength="20000" />
                <span v-if="error('job_notes')" class="fieldops-client-error">{{ error('job_notes') }}</span>
            </label>
            <label class="fieldops-workspace-field min-w-0">
                Client-facing notes (Field Notes)
                <span class="text-xs font-normal text-gray-600">Included in client updates and reports.</span>
                <textarea v-model="form.field_notes" rows="3" maxlength="20000" />
                <span v-if="error('field_notes')" class="fieldops-client-error">{{ error('field_notes') }}</span>
            </label>
        </section>
        <template v-else>
            <label class="fieldops-workspace-field">Job Scope (required)<textarea v-model="form.scope" rows="4" required maxlength="20000" /><span v-if="error('scope')" class="fieldops-client-error">{{ error('scope') }}</span></label>
            <label class="fieldops-workspace-field">Job notes{{ isRequired('job_notes_required') ? ' (required)' : '' }}<textarea v-model="form.job_notes" rows="3" :required="isRequired('job_notes_required')" maxlength="20000" /><span v-if="error('job_notes')" class="fieldops-client-error">{{ error('job_notes') }}</span></label>
            <label class="fieldops-workspace-field">Field notes<textarea v-model="form.field_notes" rows="3" maxlength="20000" /><span v-if="error('field_notes')" class="fieldops-client-error">{{ error('field_notes') }}</span></label>
        </template>
        <label v-if="isRequired('client_completion_summary_required')" class="fieldops-workspace-field">Customer-facing completion summary (required)<textarea v-model="form.client_completion_summary" rows="3" required maxlength="5000" /><span v-if="error('client_completion_summary')" class="fieldops-client-error">{{ error('client_completion_summary') }}</span></label>
        <label class="fieldops-workspace-field">Status
            <select v-model="form.status" class="fieldops-status-badge" :class="statusBadgeClass(form.status)">
                <option v-for="status in statuses" :key="status.value" :value="status.value">{{ statusLabel(status.value) }}</option>
            </select>
        </label>
        <label v-if="isRequired('pending_reason_required')" class="fieldops-workspace-field">Reason for Pending (required)<textarea v-model="form.pending_reason" rows="2" required maxlength="2000" /><span v-if="error('pending_reason')" class="fieldops-client-error">{{ error('pending_reason') }}</span></label>
        <label v-if="form.status === 'scheduled' || form.scheduled_at" class="fieldops-workspace-field">Scheduled date and time
            <input v-model="form.scheduled_at" type="datetime-local" step="900" class="fieldops-client-input fieldops-datetime-input" :required="form.status === 'scheduled'" @input="form.scheduling_override = false" />
            <span v-if="error('scheduled_at')" class="fieldops-client-error">{{ error('scheduled_at') }}</span>
        </label>
        <div v-if="scheduleConflict" class="grid gap-2 rounded-lg border border-amber-500 bg-amber-50 p-3 text-sm text-amber-950">
            <p>{{ scheduleConflict }}</p>
            <button type="button" class="fieldops-secondary-action justify-self-start" :disabled="saving" @click="proceedWithScheduleConflict">Proceed anyway</button>
        </div>
        <label class="fieldops-workspace-field">Photos and documents
            <input type="file" multiple accept="image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx" class="fieldops-client-input" @change="handleFiles" />
        </label>
        <ul v-if="form.files.length" class="grid gap-1 text-sm text-gray-700">
            <li v-for="file in form.files" :key="`${file.name}-${file.lastModified}`">{{ file.name }}</li>
        </ul>
        <p v-if="errorMessage" role="alert" class="text-sm text-red-700">{{ errorMessage }}</p>
        <div class="flex justify-end gap-2 border-t border-gray-200 pt-4">
            <button type="button" class="fieldops-secondary-action min-h-10" :disabled="saving" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="fieldops-submit-action fieldops-add-action min-h-10" :disabled="saving || loadingCustomer">
                {{ saving ? 'Saving…' : 'Add Job' }}
            </button>
        </div>
    </form>
</template>
