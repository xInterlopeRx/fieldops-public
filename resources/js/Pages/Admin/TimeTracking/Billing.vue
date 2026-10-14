<script setup lang="ts">
import { Head, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";

interface BillingEntry {
    id: number;
    date: string;
    startedAt: string;
    technician: string;
    activityType: string;
    activityLabel: string;
    hours: number;
    billable: boolean;
    approvalStatus: string;
    approvalStatusLabel: string;
    billableStatus: string;
    canMarkInvoiced: boolean;
}

interface BillingJobGroup {
    id: number | null;
    name: string;
    totalBillableHours: number;
    totalUnbilledHours: number;
    entries: BillingEntry[];
}

interface BillingProjectGroup {
    id: number | null;
    name: string;
    totalBillableHours: number;
    totalUnbilledHours: number;
    jobs: BillingJobGroup[];
}

interface BillingCustomerGroup {
    id: number;
    name: string;
    totalBillableHours: number;
    totalUnbilledHours: number;
    projects: BillingProjectGroup[];
}

interface BillingReport {
    dateFrom: string;
    dateTo: string;
    timezone: string;
    customers: BillingCustomerGroup[];
    totalBillableHours: number;
    totalUnbilledHours: number;
}

const props = defineProps<{
    report: BillingReport;
    filters: { date_from: string; date_to: string };
    status: string | null;
}>();

const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const actionError = ref("");
const selectedIds = ref<number[]>([]);
const busyEntryId = ref<number | null>(null);
const bulkMarking = ref(false);

const entries = computed(() => props.report.customers.flatMap((customer) =>
    customer.projects.flatMap((project) => project.jobs.flatMap((job) => job.entries)),
));
const eligibleEntries = computed(() => entries.value.filter((entry) => entry.canMarkInvoiced));
const allEligibleSelected = computed(() =>
    eligibleEntries.value.length > 0 && eligibleEntries.value.every((entry) => selectedIds.value.includes(entry.id)),
);

const applyDates = (): void => {
    actionError.value = "";
    selectedIds.value = [];
    router.get(route("admin.time-tracking.billing"), {
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onError: (errors) => {
            actionError.value = Object.values(errors).flat().join(" ") || "Choose a valid billing date range.";
        },
    });
};

const toggleEntry = (entry: BillingEntry): void => {
    if (!entry.canMarkInvoiced) return;

    if (!selectedIds.value.includes(entry.id) && selectedIds.value.length >= 100) {
        actionError.value = "Bulk Mark Invoiced is limited to 100 entries at a time.";

        return;
    }

    selectedIds.value = selectedIds.value.includes(entry.id)
        ? selectedIds.value.filter((id) => id !== entry.id)
        : [...selectedIds.value, entry.id];
};

const toggleAllEligible = (): void => {
    if (!allEligibleSelected.value && eligibleEntries.value.length > 100) {
        actionError.value = "Select up to 100 entries at a time for bulk processing.";

        return;
    }

    selectedIds.value = allEligibleSelected.value
        ? []
        : eligibleEntries.value.map((entry) => entry.id);
};

const showActionError = (errors: Record<string, string>): void => {
    actionError.value = Object.values(errors).flat().join(" ") || "The billing action could not be completed.";
};

const markInvoiced = (entry: BillingEntry): void => {
    busyEntryId.value = entry.id;
    actionError.value = "";
    router.post(route("admin.time-tracking.billing.entries.mark-invoiced", entry.id), {}, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = selectedIds.value.filter((id) => id !== entry.id); },
        onError: showActionError,
        onFinish: () => { busyEntryId.value = null; },
    });
};

const bulkMarkInvoiced = (): void => {
    if (selectedIds.value.length === 0) return;

    bulkMarking.value = true;
    actionError.value = "";
    router.post(route("admin.time-tracking.billing.bulk-mark-invoiced"), { ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; },
        onError: showActionError,
        onFinish: () => { bulkMarking.value = false; },
    });
};

const formatHours = (hours: number): string => hours.toFixed(4);
const formatDate = (value: string): string => new Date(`${value}T00:00:00`).toLocaleDateString();
</script>

<template>
    <Head title="Time Tracking · Billing" />

    <AdminLayout title="Time Tracking · Billing">
        <div class="mx-auto max-w-screen-2xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <section class="fieldops-panel space-y-4 bg-white p-4 sm:p-5" aria-labelledby="billing-range-title">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Admin / Time Tracking / Billing</p>
                        <h2 id="billing-range-title" class="mt-1 text-lg font-semibold text-gray-900">Prepare customer billing</h2>
                    </div>
                    <label class="flex min-h-10 items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" :checked="allEligibleSelected" :disabled="eligibleEntries.length === 0 || bulkMarking" @change="toggleAllEligible" />
                        Select all eligible
                    </label>
                </div>

                <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" @submit.prevent="applyDates">
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Date from
                        <input v-model="dateFrom" type="date" required class="fieldops-client-input" />
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Date to
                        <input v-model="dateTo" type="date" required :min="dateFrom || undefined" class="fieldops-client-input" />
                    </label>
                    <button type="submit" class="fieldops-submit-action min-h-10">Run report</button>
                </form>

                <p class="text-sm text-gray-600">
                    The report includes completed Approved, Invoiced, and Paid entries. Total Billable Hours includes all Billable entries shown;
                    Total Unbilled Hours includes Billable Approved entries only. Mark Invoiced is local to FieldOps and does not create a QuickBooks invoice.
                    The date range uses each entry's start date ({{ report.timezone }}).
                </p>
            </section>

            <p v-if="status" role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">{{ status }}</p>
            <p v-if="actionError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ actionError }}</p>

            <section class="grid gap-3 sm:grid-cols-2" aria-label="Billing summaries">
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Total Billable Hours</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ formatHours(report.totalBillableHours) }}</p>
                </article>
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Total Unbilled Hours</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ formatHours(report.totalUnbilledHours) }}</p>
                </article>
            </section>

            <section class="fieldops-panel overflow-hidden bg-white" aria-labelledby="billing-groups-title">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-4 sm:px-5">
                    <div>
                        <h2 id="billing-groups-title" class="font-semibold text-gray-900">Customer billing groups</h2>
                        <p class="mt-1 text-sm text-gray-600">Grouped Customer → Project → Job. Mark Invoiced is available for completed Billable Approved entries.</p>
                    </div>
                    <button
                        type="button"
                        class="fieldops-submit-action min-h-10"
                        :disabled="selectedIds.length === 0 || bulkMarking"
                        @click="bulkMarkInvoiced"
                    >
                        {{ bulkMarking ? "Processing…" : `Bulk Mark Invoiced${selectedIds.length ? ` (${selectedIds.length})` : ""}` }}
                    </button>
                </div>

                <div v-if="report.customers.length" class="divide-y divide-gray-200">
                    <section v-for="customer in report.customers" :key="customer.id" class="space-y-4 p-4 sm:p-5" :aria-label="`${customer.name} billing group`">
                        <header class="flex flex-wrap items-start justify-between gap-3">
                            <h3 class="text-base font-semibold text-gray-900">{{ customer.name }}</h3>
                            <div class="flex gap-4 text-sm tabular-nums">
                                <span class="text-gray-700">Billable <strong>{{ formatHours(customer.totalBillableHours) }}</strong></span>
                                <span class="text-gray-700">Unbilled <strong>{{ formatHours(customer.totalUnbilledHours) }}</strong></span>
                            </div>
                        </header>

                        <section v-for="project in customer.projects" :key="`${customer.id}-${project.id ?? 'none'}`" class="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-3 sm:p-4" :aria-label="`${project.name} project group`">
                            <header class="flex flex-wrap items-start justify-between gap-2">
                                <h4 class="font-semibold text-gray-800">Project: {{ project.name }}</h4>
                                <p class="text-xs tabular-nums text-gray-600">
                                    Billable {{ formatHours(project.totalBillableHours) }} · Unbilled {{ formatHours(project.totalUnbilledHours) }}
                                </p>
                            </header>

                            <section v-for="job in project.jobs" :key="`${customer.id}-${project.id ?? 'none'}-${job.id ?? 'none'}`" class="space-y-2 rounded-lg border border-gray-200 bg-white p-3" :aria-label="`${job.name} job group`">
                                <header class="flex flex-wrap items-start justify-between gap-2">
                                    <h5 class="text-sm font-semibold text-gray-800">Job: {{ job.name }}</h5>
                                    <p class="text-xs tabular-nums text-gray-600">
                                        Billable {{ formatHours(job.totalBillableHours) }} · Unbilled {{ formatHours(job.totalUnbilledHours) }}
                                    </p>
                                </header>

                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[780px] text-left text-sm">
                                        <caption class="sr-only">{{ customer.name }} · {{ project.name }} · {{ job.name }} time entries</caption>
                                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-600">
                                            <tr>
                                                <th scope="col" class="w-10 px-3 py-2"><span class="sr-only">Select</span></th>
                                                <th scope="col" class="px-3 py-2">Date</th>
                                                <th scope="col" class="px-3 py-2">Technician</th>
                                                <th scope="col" class="px-3 py-2">Activity Type</th>
                                                <th scope="col" class="px-3 py-2 text-right">Hours</th>
                                                <th scope="col" class="px-3 py-2">Billable Status</th>
                                                <th scope="col" class="px-3 py-2"><span class="sr-only">Actions</span></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100">
                                            <tr v-for="entry in job.entries" :key="entry.id" class="hover:bg-gray-50">
                                                <td class="px-3 py-2">
                                                    <input
                                                        type="checkbox"
                                                        :aria-label="`Select ${entry.technician} time entry for invoicing`"
                                                        :checked="selectedIds.includes(entry.id)"
                                                        :disabled="!entry.canMarkInvoiced || bulkMarking"
                                                        @change="toggleEntry(entry)"
                                                    />
                                                </td>
                                                <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ formatDate(entry.date) }}</td>
                                                <td class="px-3 py-2 font-medium text-gray-900">{{ entry.technician }}</td>
                                                <td class="whitespace-nowrap px-3 py-2 text-gray-700">{{ entry.activityLabel }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums text-gray-900">{{ formatHours(entry.hours) }}</td>
                                                <td class="px-3 py-2">
                                                    <span
                                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold"
                                                        :class="entry.canMarkInvoiced ? 'bg-amber-100 text-amber-900' : entry.billable ? 'bg-blue-100 text-blue-900' : 'bg-gray-100 text-gray-800'"
                                                    >
                                                        {{ entry.billableStatus }}
                                                    </span>
                                                </td>
                                                <td class="px-3 py-2 text-right">
                                                    <button v-if="entry.canMarkInvoiced" type="button" class="fieldops-secondary-action min-h-9 px-3" :disabled="busyEntryId === entry.id" @click="markInvoiced(entry)">
                                                        {{ busyEntryId === entry.id ? "Processing…" : "Mark Invoiced" }}
                                                    </button>
                                                    <span v-else class="text-xs text-gray-500">—</span>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </section>
                        </section>
                    </section>
                </div>

                <p v-else class="px-5 py-12 text-center text-sm text-gray-600">No completed Approved, Invoiced, or Paid entries are in this date range.</p>
            </section>
        </div>
    </AdminLayout>
</template>
