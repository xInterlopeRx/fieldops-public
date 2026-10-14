<script setup lang="ts">
import axios from "axios";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted, reactive, ref } from "vue";
import { XMarkIcon } from "@heroicons/vue/24/outline";
import Modal from "@/Components/Modal.vue";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PaginationChevronNav from "@/Components/Admin/PaginationChevronNav.vue";

interface TimeEntryRow {
    id: number;
    startedAt: string;
    durationMinutes: number | null;
    isRunning: boolean;
    technician: string;
    customer: string;
    project: string | null;
    job: string | null;
    activityType: string;
    activityLabel: string;
    billable: boolean;
    status: string;
    statusLabel: string;
    canEdit: boolean;
    canApprove: boolean;
    canUnapprove: boolean;
    canDelete: boolean;
}

interface PaginatedEntries {
    data: TimeEntryRow[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface Filters {
    date_from: string;
    date_to: string;
    technician: string;
    customer: string;
    project: string;
    activity_type: string;
    billable: string;
    status: string;
}

const props = defineProps<{
    entries: PaginatedEntries;
    filters: Filters;
    activityTypes: Array<{ value: string; label: string }>;
    approvalStatuses: Array<{ value: string; label: string }>;
    status: string | null;
}>();

const filterForm = reactive<Filters>({ ...props.filters });
const selectedIds = ref<number[]>([]);
const busyEntryId = ref<number | null>(null);
const bulkApproving = ref(false);
const actionError = ref("");
const now = ref(Date.now());
let clockInterval: number | null = null;

const eligibleOnPage = computed(() => props.entries.data.filter((entry) => entry.canApprove));
const allEligibleSelected = computed(() =>
    eligibleOnPage.value.length > 0 && eligibleOnPage.value.every((entry) => selectedIds.value.includes(entry.id)),
);
const hasFilters = computed(() => Object.values(props.filters).some((value) => value !== ""));

const filterParams = (): Record<string, string | undefined> => ({
    date_from: filterForm.date_from || undefined,
    date_to: filterForm.date_to || undefined,
    technician: filterForm.technician.trim() || undefined,
    customer: filterForm.customer.trim() || undefined,
    project: filterForm.project.trim() || undefined,
    activity_type: filterForm.activity_type || undefined,
    billable: filterForm.billable || undefined,
    status: filterForm.status || undefined,
});

const applyFilters = (): void => {
    selectedIds.value = [];
    actionError.value = "";
    router.get(route("admin.time-tracking.review"), filterParams(), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onError: showActionError,
    });
};

const clearFilters = (): void => {
    Object.assign(filterForm, {
        date_from: "",
        date_to: "",
        technician: "",
        customer: "",
        project: "",
        activity_type: "",
        billable: "",
        status: "",
    });
    selectedIds.value = [];
    applyFilters();
};

const toggleEntry = (entry: TimeEntryRow): void => {
    if (!entry.canApprove) return;

    if (!selectedIds.value.includes(entry.id) && selectedIds.value.length >= 100) {
        actionError.value = "Bulk approval is limited to 100 entries at a time.";

        return;
    }

    selectedIds.value = selectedIds.value.includes(entry.id)
        ? selectedIds.value.filter((id) => id !== entry.id)
        : [...selectedIds.value, entry.id];
};

const togglePageSelection = (): void => {
    const pageIds = eligibleOnPage.value.map((entry) => entry.id);

    if (!allEligibleSelected.value && new Set([...selectedIds.value, ...pageIds]).size > 100) {
        actionError.value = "Bulk approval is limited to 100 entries at a time.";

        return;
    }

    selectedIds.value = allEligibleSelected.value
        ? selectedIds.value.filter((id) => !pageIds.includes(id))
        : [...new Set([...selectedIds.value, ...pageIds])];
};

const showActionError = (errors: Record<string, string>): void => {
    actionError.value = Object.values(errors).flat().join(" ") || "The action could not be completed.";
};

const approveEntry = (entry: TimeEntryRow): void => {
    busyEntryId.value = entry.id;
    actionError.value = "";
    router.post(route("admin.time-tracking.entries.approve", entry.id), {}, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = selectedIds.value.filter((id) => id !== entry.id); },
        onError: showActionError,
        onFinish: () => { busyEntryId.value = null; },
    });
};

const unapproveEntry = (entry: TimeEntryRow): void => {
    busyEntryId.value = entry.id;
    actionError.value = "";
    router.post(route("admin.time-tracking.entries.unapprove", entry.id), {}, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = selectedIds.value.filter((id) => id !== entry.id); },
        onError: showActionError,
        onFinish: () => { busyEntryId.value = null; },
    });
};

const deleteEntry = (entry: TimeEntryRow): void => {
    if (!window.confirm(`Permanently delete this time entry for ${entry.technician}? This cannot be undone.`)) return;

    busyEntryId.value = entry.id;
    actionError.value = "";
    router.delete(route("admin.time-tracking.entries.destroy", entry.id), {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = selectedIds.value.filter((id) => id !== entry.id);
            if (editingEntry.value?.id === entry.id) closeEditor();
        },
        onError: showActionError,
        onFinish: () => { busyEntryId.value = null; },
    });
};

const bulkApprove = (): void => {
    if (selectedIds.value.length === 0) return;

    bulkApproving.value = true;
    actionError.value = "";
    router.post(route("admin.time-tracking.bulk-approve"), { ids: selectedIds.value }, {
        preserveScroll: true,
        onSuccess: () => { selectedIds.value = []; },
        onError: showActionError,
        onFinish: () => { bulkApproving.value = false; },
    });
};

const editorOpen = ref(false);
const editorLoading = ref(false);
const editorReady = ref(false);
const editingEntry = ref<TimeEntryRow | null>(null);
const editorError = ref("");
const editForm = useForm({
    started_at: "",
    ended_at: "",
    duration_minutes: 0,
    activity_type: "",
    billable: false,
    notes: "",
});

const toLocalDateTime = (value: string | null): string => {
    if (!value) return "";
    const date = new Date(value);

    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
};

const openEditor = async (entry: TimeEntryRow): Promise<void> => {
    editorOpen.value = true;
    editorLoading.value = true;
    editorReady.value = false;
    editorError.value = "";
    editingEntry.value = entry;

    try {
        const response = await axios.get(route("admin.time-tracking.entries.edit", entry.id));
        const data = response.data.data;
        editForm.started_at = toLocalDateTime(data.startedAt);
        editForm.ended_at = toLocalDateTime(data.endedAt);
        editForm.duration_minutes = data.durationMinutes;
        editForm.activity_type = data.activityType;
        editForm.billable = data.billable;
        editForm.notes = data.notes ?? "";
        editForm.clearErrors();
        editorReady.value = true;
    } catch {
        editorError.value = "This entry can no longer be edited. Refresh the review list and try again.";
    } finally {
        editorLoading.value = false;
    }
};

const closeEditor = (): void => {
    if (editForm.processing) return;
    editorOpen.value = false;
    editorReady.value = false;
    editingEntry.value = null;
    editForm.clearErrors();
};

const saveEdit = (): void => {
    if (!editingEntry.value || !editorReady.value) return;

    editorError.value = "";
    editForm.patch(route("admin.time-tracking.entries.update", editingEntry.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = selectedIds.value.filter((id) => id !== editingEntry.value?.id);
            editorOpen.value = false;
            editorReady.value = false;
            editingEntry.value = null;
            editForm.reset();
        },
        onError: (errors) => {
            editorError.value = Object.values(errors).flat().join(" ") || "Review the highlighted fields.";
        },
    });
};

const formatDate = (value: string): string => new Date(value).toLocaleDateString();
const formatMinutes = (value: number): string => {
    const minutes = Math.max(0, Math.floor(value));
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;

    return hours > 0 ? `${hours}h ${remainder}m` : `${remainder}m`;
};
const durationLabel = (entry: TimeEntryRow): string => entry.isRunning
    ? `${formatMinutes((now.value - new Date(entry.startedAt).getTime()) / 60_000)} · Running`
    : formatMinutes(entry.durationMinutes ?? 0);

const statusClass = (status: string): string => ({
    draft: "bg-gray-100 text-gray-800",
    approved: "bg-green-100 text-green-900",
    invoiced: "bg-blue-100 text-blue-900",
    paid: "bg-teal-100 text-teal-900",
}[status] ?? "bg-gray-100 text-gray-800");

onMounted(() => {
    clockInterval = window.setInterval(() => { now.value = Date.now(); }, 30_000);
});

onUnmounted(() => {
    if (clockInterval !== null) window.clearInterval(clockInterval);
});
</script>

<template>
    <Head title="Time Tracking · Review" />

    <AdminLayout title="Time Tracking · Review">
        <div class="mx-auto max-w-screen-2xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <section class="fieldops-panel space-y-4 bg-white p-4 sm:p-5" aria-labelledby="time-entry-filters-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Admin / Time Tracking / Review</p>
                        <h2 id="time-entry-filters-title" class="mt-1 text-lg font-semibold text-gray-900">Filter time entries</h2>
                    </div>
                    <p class="text-sm text-gray-600">Filter values match technician, customer, or project names.</p>
                </div>

                <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="applyFilters">
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Date from
                        <input v-model="filterForm.date_from" type="date" class="fieldops-client-input" />
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Date to
                        <input v-model="filterForm.date_to" type="date" :min="filterForm.date_from || undefined" class="fieldops-client-input" />
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Technician
                        <ClearableSearchInput id="time-entry-technician-search" v-model="filterForm.technician" maxlength="100" class="fieldops-client-input" placeholder="Name contains…" clear-label="Clear technician filter" />
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Customer
                        <ClearableSearchInput id="time-entry-customer-search" v-model="filterForm.customer" maxlength="100" class="fieldops-client-input" placeholder="Name contains…" clear-label="Clear customer filter" />
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Project
                        <ClearableSearchInput id="time-entry-project-search" v-model="filterForm.project" maxlength="100" class="fieldops-client-input" placeholder="Name or number contains…" clear-label="Clear project filter" />
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Activity type
                        <select v-model="filterForm.activity_type" class="fieldops-client-input">
                            <option value="">All activity types</option>
                            <option v-for="activity in activityTypes" :key="activity.value" :value="activity.value">{{ activity.label }}</option>
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Billable
                        <select v-model="filterForm.billable" class="fieldops-client-input">
                            <option value="">All</option>
                            <option value="1">Billable</option>
                            <option value="0">Non-billable</option>
                        </select>
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Status
                        <select v-model="filterForm.status" class="fieldops-client-input">
                            <option value="">All statuses</option>
                            <option v-for="statusOption in approvalStatuses" :key="statusOption.value" :value="statusOption.value">{{ statusOption.label }}</option>
                        </select>
                    </label>
                    <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
                        <button type="submit" class="fieldops-submit-action min-h-10">Apply filters</button>
                        <button v-if="hasFilters" type="button" class="fieldops-secondary-action min-h-10" @click="clearFilters">Clear filters</button>
                    </div>
                </form>
            </section>

            <p v-if="status" role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">{{ status }}</p>
            <p v-if="actionError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ actionError }}</p>

            <section class="fieldops-panel overflow-hidden bg-white" aria-labelledby="time-entry-results-title">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-4 sm:px-5">
                    <div>
                        <h2 id="time-entry-results-title" class="font-semibold text-gray-900">Time entries <span class="font-normal text-gray-600">({{ entries.total }})</span></h2>
                        <p class="mt-1 text-sm text-gray-600">
                            <template v-if="entries.total">Showing {{ entries.from }}–{{ entries.to }}</template>
                            <template v-else>No matching entries</template>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="fieldops-submit-action min-h-10"
                        :disabled="selectedIds.length === 0 || bulkApproving"
                        @click="bulkApprove"
                    >
                        {{ bulkApproving ? "Approving…" : `Bulk approve${selectedIds.length ? ` (${selectedIds.length})` : ""}` }}
                    </button>
                </div>

                <div v-if="entries.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[1180px] border-collapse text-left text-sm">
                        <caption class="sr-only">Filterable time entries and Admin review actions</caption>
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-600">
                            <tr>
                                <th scope="col" class="w-12 px-3 py-3">
                                    <input
                                        type="checkbox"
                                        aria-label="Select all approvable entries on this page"
                                        :checked="allEligibleSelected"
                                        :disabled="eligibleOnPage.length === 0 || bulkApproving"
                                        @change="togglePageSelection"
                                    />
                                </th>
                                <th scope="col" class="px-3 py-3">Date</th>
                                <th scope="col" class="px-3 py-3">Technician</th>
                                <th scope="col" class="px-3 py-3">Customer</th>
                                <th scope="col" class="px-3 py-3">Project</th>
                                <th scope="col" class="px-3 py-3">Job</th>
                                <th scope="col" class="px-3 py-3">Activity type</th>
                                <th scope="col" class="px-3 py-3">Duration</th>
                                <th scope="col" class="px-3 py-3">Billable</th>
                                <th scope="col" class="px-3 py-3">Status</th>
                                <th scope="col" class="px-3 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="entry in entries.data" :key="entry.id" class="align-top hover:bg-gray-50">
                                <td class="px-3 py-3">
                                    <input
                                        type="checkbox"
                                        :aria-label="`Select time entry ${entry.id} for bulk approval`"
                                        :checked="selectedIds.includes(entry.id)"
                                        :disabled="!entry.canApprove || bulkApproving"
                                        @change="toggleEntry(entry)"
                                    />
                                </td>
                                <td class="whitespace-nowrap px-3 py-3 text-gray-900">{{ formatDate(entry.startedAt) }}</td>
                                <td class="max-w-40 px-3 py-3 font-medium text-gray-900">{{ entry.technician }}</td>
                                <td class="max-w-48 px-3 py-3 text-gray-800">{{ entry.customer }}</td>
                                <td class="max-w-48 px-3 py-3 text-gray-700">{{ entry.project || "—" }}</td>
                                <td class="max-w-56 px-3 py-3 text-gray-700">{{ entry.job || "—" }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-gray-800">{{ entry.activityLabel }}</td>
                                <td class="whitespace-nowrap px-3 py-3 font-medium text-gray-900">{{ durationLabel(entry) }}</td>
                                <td class="px-3 py-3">{{ entry.billable ? "Yes" : "No" }}</td>
                                <td class="px-3 py-3">
                                    <span class="inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold" :class="statusClass(entry.status)">{{ entry.statusLabel }}</span>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <button v-if="entry.canApprove" type="button" class="fieldops-secondary-action min-h-9 px-3" :disabled="busyEntryId === entry.id" @click="approveEntry(entry)">Approve</button>
                                        <button v-if="entry.canUnapprove" type="button" class="fieldops-secondary-action min-h-9 px-3" :disabled="busyEntryId === entry.id" @click="unapproveEntry(entry)">Unapprove</button>
                                        <button v-if="entry.canEdit" type="button" class="fieldops-secondary-action fieldops-edit-action min-h-9 px-3" :disabled="busyEntryId === entry.id" @click="openEditor(entry)">Edit</button>
                                        <span v-if="!entry.canApprove && !entry.canUnapprove && !entry.canEdit" class="text-gray-500">No actions</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="px-5 py-12 text-center text-sm text-gray-600">
                    {{ hasFilters ? "No time entries match these filters." : "No time entries have been recorded yet." }}
                </p>

                <PaginationChevronNav
                    v-if="entries.last_page > 1"
                    label="Time entry pages"
                    :current-page="entries.current_page"
                    :last-page="entries.last_page"
                    :previous-url="entries.prev_page_url"
                    :next-url="entries.next_page_url"
                    preserve-scroll
                />
            </section>
        </div>

        <Modal :show="editorOpen" max-width="2xl" @close="closeEditor">
            <form class="space-y-5 p-5 sm:p-6" @submit.prevent="saveEdit">
                <header class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Edit time entry</h2>
                        <p v-if="editingEntry" class="mt-1 text-sm text-gray-600">{{ editingEntry.technician }} · {{ editingEntry.customer }}</p>
                    </div>
                    <button type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Close time entry editor" title="Close" :disabled="editForm.processing" @click="closeEditor">
                        <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                    </button>
                </header>

                <p v-if="editorLoading" class="text-sm text-gray-600">Loading entry…</p>
                <p v-if="editorError" role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900">{{ editorError }}</p>
                <p v-if="editForm.errors.started_at || editForm.errors.ended_at || editForm.errors.duration_minutes || editForm.errors.activity_type || editForm.errors.billable || editForm.errors.notes" role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-900">Review the highlighted fields and correct the entry.</p>
                <p v-if="editingEntry?.status === 'approved'" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Saving changes returns this entry to Draft so it can be reviewed again.
                </p>

                <fieldset :disabled="!editorReady || editorLoading || editForm.processing" class="grid gap-4 sm:grid-cols-2">
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Start
                        <input v-model="editForm.started_at" type="datetime-local" required class="fieldops-client-input" />
                        <span v-if="editForm.errors.started_at" class="fieldops-client-error">{{ editForm.errors.started_at }}</span>
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        End
                        <input v-model="editForm.ended_at" type="datetime-local" required class="fieldops-client-input" />
                        <span v-if="editForm.errors.ended_at" class="fieldops-client-error">{{ editForm.errors.ended_at }}</span>
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Duration (minutes)
                        <input v-model.number="editForm.duration_minutes" type="number" min="0" step="1" required class="fieldops-client-input" />
                        <span v-if="editForm.errors.duration_minutes" class="fieldops-client-error">{{ editForm.errors.duration_minutes }}</span>
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Activity type
                        <select v-model="editForm.activity_type" required class="fieldops-client-input">
                            <option v-for="activity in activityTypes" :key="activity.value" :value="activity.value">{{ activity.label }}</option>
                        </select>
                        <span v-if="editForm.errors.activity_type" class="fieldops-client-error">{{ editForm.errors.activity_type }}</span>
                    </label>
                    <label class="flex min-h-11 items-center gap-3 text-sm font-medium text-gray-800 sm:col-span-2">
                        <input v-model="editForm.billable" type="checkbox" />
                        Billable
                        <span v-if="editForm.errors.billable" class="fieldops-client-error">{{ editForm.errors.billable }}</span>
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800 sm:col-span-2">
                        Notes
                        <textarea v-model="editForm.notes" rows="4" maxlength="20000" class="fieldops-client-input" />
                        <span v-if="editForm.errors.notes" class="fieldops-client-error">{{ editForm.errors.notes }}</span>
                    </label>
                </fieldset>

                <footer class="flex flex-wrap justify-end gap-2 border-t border-gray-200 pt-4">
                    <button v-if="editingEntry?.canDelete" type="button" class="fieldops-delete-action min-h-10 mr-auto" :disabled="busyEntryId === editingEntry.id || editForm.processing" @click="deleteEntry(editingEntry)">Delete</button>
                    <button type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Cancel time entry edit" title="Cancel" :disabled="editForm.processing" @click="closeEditor">
                        <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                    </button>
                    <button type="submit" class="fieldops-submit-action min-h-10" :disabled="!editorReady || editorLoading || editForm.processing">
                        {{ editForm.processing ? "Saving…" : "Save changes" }}
                    </button>
                </footer>
            </form>
        </Modal>
    </AdminLayout>
</template>
