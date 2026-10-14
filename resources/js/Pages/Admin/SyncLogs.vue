<script setup lang="ts">
import axios from "axios";
import { computed, ref } from "vue";
import { Head, Link, router } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import PaginationChevronNav from "@/Components/Admin/PaginationChevronNav.vue";

interface SyncHealth {
    status: "healthy" | "warning" | "error";
    label: string;
    summary: string;
    errors: number;
    pending: number;
    configuration: number;
    quickBooks: { linkedCustomers: number; failed: number; pending: number; connected: boolean; environment: string; syncEnabled: boolean };
    graph: { calendarEnabled: boolean; mailEnabled: boolean; contactsEnabled: boolean; failed: number; pending: number; configuration: number; outlookContacts: { failed: number; pending: number } };
}

interface QueueWorkerStatus {
    state: "recently_seen" | "stale" | "not_detected" | "unknown";
    lastSeenAt: string | null;
    ageSeconds: number | null;
}

interface QueueMetrics {
    queue: string;
    monitoringSupported: boolean;
    totalJobs: number | null;
    readyJobs: number | null;
    delayedOrReservedJobs: number | null;
    oldestReadyAt: string | null;
    oldestReadyAgeSeconds: number | null;
    workerAttentionRequired: boolean;
    worker: QueueWorkerStatus;
}

interface QueueReviewItem {
    id: number | string;
    queue: string;
    type: string;
    attempts: number;
    state?: string;
    createdAt: string | null;
    availableAt?: string | null;
    reservedAt?: string | null;
    failedAt?: string | null;
    canForget?: boolean;
}

interface QueueBacklog extends QueueMetrics {
    driver: string;
    additionalQueues: QueueMetrics[];
    failedJobsCount: number | null;
    failedJobsTruncated: boolean;
    reviewItems: QueueReviewItem[];
    failedJobReviewItems: QueueReviewItem[];
    quickBooksSyncEnabled: boolean;
    quickBooksPausedJobs: { count: number; truncated: boolean };
}

interface QuickBooksIssue {
    id: number;
    clientId: string | null;
    label: string;
    linked: boolean;
    status: string;
    error: string | null;
    requestedAt: string | null;
    lastSyncedAt: string | null;
    writePending: boolean;
    canResolve: boolean;
    canRetryCreate: boolean;
    sites: Array<{ id: number; name: string; writePending: boolean }>;
}

interface VisitIssue {
    id: number;
    type: string;
    jobId: number | null;
    jobNumber: string | null;
    jobTitle: string | null;
    customer: string | null;
    site: string | null;
    scheduledAt: string | null;
    status: string;
    error: string | null;
    syncedAt: string | null;
    canRetry: boolean;
}

interface NotificationIssue {
    id: string;
    type: string;
    jobId: number | null;
    jobNumber: string | null;
    jobTitle: string | null;
    visitId: number | null;
    recipientRole: string;
    status: string;
    attempts: number;
    lastError: string | null;
    updatedAt: string | null;
    canRetry: boolean;
}

interface OutlookContactIssue {
    id: number;
    contact: string;
    customer: string | null;
    customerId: number;
    site: string | null;
    outlookContactId: string | null;
    status: string;
    lastSyncedAt: string | null;
    requestedAt: string | null;
    error: string | null;
    archived: boolean;
    canResync: boolean;
    canRepair: boolean;
    repairMode: "replace_linked" | "manual_reconciliation" | null;
    blockedReason: string | null;
}

interface PaginatedOutlookContacts {
    data: OutlookContactIssue[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{
    health: SyncHealth;
    queueBacklog: QueueBacklog;
    quickBooksConnection: { environment: string; connected: boolean; sandboxWritesEnabled: boolean; syncEnabled: boolean };
    graphWrites: { calendarEnabled: boolean; mailEnabled: boolean; contactsEnabled: boolean };
    quickBooks: QuickBooksIssue[];
    visits: VisitIssue[];
    notifications: NotificationIssue[];
    outlookContacts: PaginatedOutlookContacts;
    outlookContactFilters: { q: string; status: string; statuses: string[] };
    activities: Array<{ id: number; type: string; record: string; createdAt: string }>;
    sharePointPointers: SharePointPointer[];
}>();

interface SharePointPointer {
    id: number;
    filename: string;
    customer: string | null;
    site: string | null;
    project: string | null;
    job: string | null;
    createdAt: string | null;
}

const notice = ref("");
const errorMessage = ref("");
const busyKey = ref<string | number | null>(null);
const outlookContactSearch = ref(props.outlookContactFilters.q);
const outlookContactStatus = ref(props.outlookContactFilters.status);
const selectedPointerIds = ref<number[]>(props.sharePointPointers.map((pointer) => pointer.id));
const retiredPointerIds = ref<Set<number>>(new Set());
const pointerRetirementFeedback = ref<{ type: "success" | "error"; message: string } | null>(null);
const pointerRetirement = ref({
    sharepointAdminUpn: "",
    verificationReference: "",
    password: "",
    remoteFilesPreserved: false,
});
const pointerRetirementRequirements = computed(() => {
    const requirements: string[] = [];

    if (selectedPointerIds.value.length === 0) requirements.push("select at least one pointer");
    if (!pointerRetirement.value.sharepointAdminUpn.trim()) requirements.push("enter the SharePoint administrator UPN");
    if (!pointerRetirement.value.verificationReference.trim()) requirements.push("enter the external change reference");
    if (!pointerRetirement.value.password) requirements.push("enter your current password");
    if (!pointerRetirement.value.remoteFilesPreserved) requirements.push("confirm the remote files were preserved or reconciled");

    return requirements;
});
const failedJobForget = ref<{
    id: number | string | null;
    confirmed: boolean;
    password: string;
    error: string;
}>({ id: null, confirmed: false, password: "", error: "" });
const forgottenFailedJobIds = ref<Set<string>>(new Set());
const visibleFailedJobReviewItems = computed(() =>
    props.queueBacklog.failedJobReviewItems.filter((item) => !forgottenFailedJobIds.value.has(String(item.id))),
);
const visibleSharePointPointers = computed(() =>
    props.sharePointPointers.filter((pointer) => !retiredPointerIds.value.has(pointer.id)),
);

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : "Not recorded";

const formatQueueAge = (seconds: number | null): string => {
    if (seconds === null) return "Not available";
    if (seconds < 60) return "less than a minute";

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} minute${minutes === 1 ? "" : "s"}`;

    const hours = Math.floor(minutes / 60);
    return `${hours} hour${hours === 1 ? "" : "s"}`;
};

const queueWorkerStatusText = (worker: QueueWorkerStatus): string => {
    if (worker.state === "recently_seen") {
        return `Worker checked in ${formatQueueAge(worker.ageSeconds)} ago`;
    }

    if (worker.state === "stale") {
        return `Worker heartbeat is stale; last seen ${formatQueueAge(worker.ageSeconds)} ago`;
    }

    if (worker.state === "not_detected") return "No worker heartbeat detected";

    return "Worker status unavailable";
};

const quickBooksErrorText = (code: string | null): string => {
    switch (code) {
        case "quickbooks_conflict":
            return "QuickBooks changed since the FieldOps copy was synchronized.";
        case "reauthorization_required":
            return "The QuickBooks connection needs to be reauthorized.";
        case "quickbooks_record_not_found":
            return "The linked record could not be found in QuickBooks.";
        case "queue_dispatch_failed":
            return "The QuickBooks write could not be placed on the queue.";
        case "sync_failed":
            return "The QuickBooks background job failed without a provider-specific reason. Check the queue worker/server logs, then resolve the linked Customer using QuickBooks if a refresh is still needed.";
        case null:
        case "":
            return "QuickBooks synchronization failed.";
        default:
            return `QuickBooks synchronization failed (${code}).`;
    }
};

const refreshLogs = (): void => {
    router.reload();
};

const retireSharePointPointers = async (): Promise<void> => {
    if (selectedPointerIds.value.length === 0 || busyKey.value !== null) return;
    const attachmentIds = [...selectedPointerIds.value];
    const count = attachmentIds.length;
    if (!window.confirm(`Retire ${count} selected local SharePoint metadata pointer(s)? This will not call SharePoint and will not delete any remote file.`)) return;

    busyKey.value = "sharepoint-pointer-retirement";
    errorMessage.value = "";
    notice.value = "";
    pointerRetirementFeedback.value = null;
    try {
        const response = await axios.post(route("admin.sync-logs.sharepoint-pointers.retire"), {
            attachment_ids: attachmentIds,
            remote_files_preserved: pointerRetirement.value.remoteFilesPreserved,
            sharepoint_admin_upn: pointerRetirement.value.sharepointAdminUpn,
            verification_reference: pointerRetirement.value.verificationReference,
            password: pointerRetirement.value.password,
        });
        if (response.data?.status !== "retired" || response.data?.count !== count) {
            throw new Error("The server did not confirm retirement of all selected pointers. Refresh the page and review the remaining list.");
        }
        retiredPointerIds.value = new Set([...retiredPointerIds.value, ...attachmentIds]);
        selectedPointerIds.value = selectedPointerIds.value.filter((id) => !attachmentIds.includes(id));
        pointerRetirementFeedback.value = {
            type: "success",
            message: response.data.message ?? "Local SharePoint pointers retired; remote files were not changed.",
        };
        pointerRetirement.value = {
            sharepointAdminUpn: "",
            verificationReference: "",
            password: "",
            remoteFilesPreserved: false,
        };
        refreshLogs();
    } catch (error) {
        const responseData = axios.isAxiosError(error) ? error.response?.data as {
            message?: unknown;
            errors?: Record<string, unknown>;
        } | undefined : undefined;
        const validationMessage = Object.values(responseData?.errors ?? {})
            .flatMap((messages) => Array.isArray(messages) ? messages : [])
            .find((message): message is string => typeof message === "string");
        const serverMessage = typeof responseData?.message === "string" ? responseData.message : null;
        pointerRetirementFeedback.value = {
            type: "error",
            message: validationMessage
                ?? serverMessage
                ?? (error instanceof Error ? error.message : "SharePoint metadata pointers could not be retired."),
        };
    } finally {
        pointerRetirement.value.password = "";
        busyKey.value = null;
    }
};

const beginForgettingFailedJob = (item: QueueReviewItem): void => {
    if (!item.canForget || busyKey.value !== null) return;

    failedJobForget.value = {
        id: item.id,
        confirmed: false,
        password: "",
        error: "",
    };
};

const cancelForgettingFailedJob = (): void => {
    failedJobForget.value = { id: null, confirmed: false, password: "", error: "" };
};

const forgetFailedQueueRecord = async (item: QueueReviewItem): Promise<void> => {
    if (!item.canForget
        || String(failedJobForget.value.id) !== String(item.id)
        || !failedJobForget.value.confirmed
        || !failedJobForget.value.password
        || busyKey.value !== null) return;

    busyKey.value = `forget-failed-${item.id}`;
    failedJobForget.value.error = "";
    errorMessage.value = "";
    notice.value = "";

    try {
        const response = await axios.post(route("admin.sync-logs.failed-jobs.forget", item.id), {
            confirm_forget: failedJobForget.value.confirmed,
            password: failedJobForget.value.password,
        });

        if (response.data?.status !== "forgotten" || String(response.data?.failed_job_id) !== String(item.id)) {
            throw new Error("The server did not confirm forgetting this failed record. Refresh the page and review it again.");
        }

        forgottenFailedJobIds.value = new Set([...forgottenFailedJobIds.value, String(item.id)]);
        notice.value = response.data.message ?? "The failed QuickBooks customer sync record was forgotten without retrying it.";
        cancelForgettingFailedJob();
        refreshLogs();
    } catch (error) {
        const responseData = axios.isAxiosError(error) ? error.response?.data as {
            message?: unknown;
            errors?: Record<string, unknown>;
        } | undefined : undefined;
        const validationMessage = Object.values(responseData?.errors ?? {})
            .flatMap((messages) => Array.isArray(messages) ? messages : [])
            .find((message): message is string => typeof message === "string");
        failedJobForget.value.error = validationMessage
            ?? (typeof responseData?.message === "string"
                ? responseData.message
                : error instanceof Error ? error.message : "The failed record could not be forgotten.");
    } finally {
        failedJobForget.value.password = "";
        busyKey.value = null;
    }
};

const submitOutlookContactFilters = (): void => {
    router.get(
        route("admin.sync-logs"),
        {
            contact_q: outlookContactSearch.value.trim() || undefined,
            contact_status: outlookContactStatus.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const outlookStatusLabel = (status: string): string => {
    const labels: Record<string, string> = {
        reconciled: "Reconciled (paused)",
        not_synced: "Not synced",
        pending: "Pending",
        syncing: "Syncing",
        synced: "Synced",
        failed: "Failed",
        unknown: "Unknown outcome",
        paused: "Paused",
    };

    return labels[status] ?? status.replaceAll("_", " ");
};

const outlookErrorText = (code: string): string => {
    const messages: Record<string, string> = {
        outlook_contact_target_reconciled: "The old Outlook Contact was manually reconciled with the Microsoft 365 administrator. FieldOps cleared its local Outlook ID; no Graph request or remote deletion was performed. This is a completed reconciliation, not a sync failure.",
        outlook_contact_email_invalid: "A FieldOps email value is invalid. Correct it before syncing.",
        outlook_contact_email_limit_exceeded: "Outlook supports at most three email values. Reconcile the extra values before syncing.",
        outlook_contact_business_phone_limit_exceeded: "Outlook supports at most two business phone values. Reconcile the extra values before syncing.",
        outlook_contact_multiple_mobile_phones: "Outlook supports one mobile phone value. Reconcile the different mobile numbers before syncing.",
        outlook_contact_mailbox_mismatch: "This stored Outlook ID belongs to a different mailbox. Resync and repair are blocked.",
        outlook_contact_mailbox_untracked: "This Outlook ID has no verified mailbox owner. Manual reconciliation is required.",
        outlook_contact_sync_disabled: "Synchronization paused because the feature is disabled.",
        outlook_contact_create_outcome_unknown: "Outlook may have created this Contact, but FieldOps did not receive its ID. Check Outlook manually before attempting another create.",
        outlook_contact_create_reconciliation_required: "Check Outlook manually before attempting another create.",
        outlook_contact_id_persistence_failed: "The Outlook ID could not be recorded after creation. Check Outlook manually before attempting another create.",
        outlook_contact_id_missing: "The Graph create response did not contain a usable Contact ID. Manual Outlook reconciliation is required.",
        outlook_contact_sync_failed: "The Outlook background job failed without a provider-specific reason. Check that a queue worker is running and inspect server logs before retrying.",
    };

    return messages[code] ?? `Outlook synchronization failed (${code}).`;
};

const resyncOutlookContact = async (contact: OutlookContactIssue): Promise<void> => {
    if (!contact.canResync || busyKey.value !== null) return;
    busyKey.value = `outlook-resync-${contact.id}`;
    errorMessage.value = "";
    notice.value = "";
    try {
        const response = await axios.post(route("admin.sync-logs.outlook-contacts.resync", contact.id));
        notice.value = response.data.message ?? "Outlook Contact sync queued.";
        refreshLogs();
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "Outlook Contact resync could not be queued."
            : "Outlook Contact resync could not be queued.";
    } finally {
        busyKey.value = null;
    }
};

const repairOutlookContact = async (contact: OutlookContactIssue): Promise<void> => {
    if (!contact.canRepair || busyKey.value !== null) return;
    const manualReconciliation = contact.repairMode === "manual_reconciliation";
    const confirmation = manualReconciliation
        ? `The previous create for ${contact.contact} had an unknown outcome. Manually inspect the configured Outlook Contacts folder and remove/reconcile any duplicate first. Have you completed that check and confirmed it is safe to create a new Outlook Contact?`
        : `Repair ${contact.contact} by deleting the currently linked Outlook Contact and creating a fresh copy from FieldOps?`;
    if (!window.confirm(confirmation)) return;

    busyKey.value = `outlook-repair-${contact.id}`;
    errorMessage.value = "";
    notice.value = "";
    try {
        const response = await axios.post(
            route("admin.sync-logs.outlook-contacts.repair", contact.id),
            manualReconciliation ? { manual_reconciliation_confirmed: true } : {},
        );
        notice.value = response.data.message ?? "Outlook Contact replacement queued.";
        refreshLogs();
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "Outlook Contact repair could not be queued."
            : "Outlook Contact repair could not be queued.";
    } finally {
        busyKey.value = null;
    }
};

const resolveQuickBooks = async (issue: QuickBooksIssue): Promise<void> => {
    if (!issue.canResolve || busyKey.value !== null) return;
    if (!window.confirm(`QuickBooks is the source of truth. Refresh ${issue.label} and linked Sites from QuickBooks? Conflicting local contract-field edits will be replaced; FieldOps-only data will remain.`)) return;

    busyKey.value = issue.id;
    errorMessage.value = "";
    notice.value = "";
    try {
        const response = await axios.post(route("admin.customers.quickbooks.resolve", issue.id));
        notice.value = response.data.message ?? "QuickBooks refresh queued.";
        refreshLogs();
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "QuickBooks conflict could not be resolved."
            : "QuickBooks conflict could not be resolved.";
    } finally {
        busyKey.value = null;
    }
};

const retryVisit = async (visit: VisitIssue): Promise<void> => {
    if (!visit.canRetry || busyKey.value !== null) return;
    busyKey.value = visit.id;
    errorMessage.value = "";
    notice.value = "";
    try {
        const response = await axios.post(route("admin.sync-logs.visits.retry", visit.id));
        notice.value = response.data.message ?? "Visit sync retry queued.";
        refreshLogs();
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "Visit sync retry could not be queued."
            : "Visit sync retry could not be queued.";
    } finally {
        busyKey.value = null;
    }
};

const retryCustomerCreate = async (issue: QuickBooksIssue): Promise<void> => {
    if (!issue.canRetryCreate || busyKey.value !== null) return;
    busyKey.value = issue.id;
    errorMessage.value = "";
    notice.value = "";
    try {
        const response = await axios.post(route("admin.sync-logs.customers.retry-create", issue.id));
        notice.value = response.data.message ?? "QuickBooks Customer create queued.";
        refreshLogs();
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "QuickBooks Customer create could not be retried."
            : "QuickBooks Customer create could not be retried.";
    } finally {
        busyKey.value = null;
    }
};

const retryNotification = async (item: NotificationIssue): Promise<void> => {
    if (!item.canRetry || busyKey.value !== null) return;
    busyKey.value = item.id;
    errorMessage.value = "";
    notice.value = "";
    try {
        const response = await axios.post(route("admin.sync-logs.notifications.retry", item.id));
        notice.value = response.data.message ?? "Notification retry queued.";
        refreshLogs();
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "Notification retry could not be queued."
            : "Notification retry could not be queued.";
    } finally {
        busyKey.value = null;
    }
};
</script>

<template>
    <Head title="Sync status and activity" />

    <AdminLayout title="Sync status and activity">
        <div class="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <p v-if="notice" role="status" class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">{{ notice }}</p>
            <p v-if="errorMessage" role="alert" class="rounded-lg border border-orange-300 bg-orange-50 px-4 py-3 text-sm text-orange-950 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-100">{{ errorMessage }}</p>

            <section
                class="fieldops-panel grid gap-4 border-2 p-4 sm:grid-cols-[auto_1fr] sm:items-center sm:p-5"
                :class="health.status === 'healthy'
                    ? 'border-green-300 bg-green-50 dark:border-green-800 dark:bg-green-950'
                    : 'border-orange-300 bg-orange-50 dark:border-orange-800 dark:bg-orange-950'"
            >
                <span class="fieldops-sync-health-indicator" :class="`fieldops-sync-health-indicator--${health.status}`" aria-hidden="true">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7v5h-5M4 17v-5h5"/><path stroke-linecap="round" stroke-linejoin="round" d="M5.5 9a7 7 0 0 1 11.8-2L20 12M4 12l2.7 5a7 7 0 0 0 11.8-2"/></svg>
                </span>
                <div>
                    <h1 class="text-lg font-semibold" :class="health.status === 'healthy' ? 'text-green-900 dark:text-green-100' : 'text-orange-950 dark:text-orange-100'">{{ health.label }}</h1>
                    <p class="mt-1 text-sm" :class="health.status === 'healthy' ? 'text-green-900 dark:text-green-200' : 'text-orange-950 dark:text-orange-200'">{{ health.summary }}</p>
                    <p class="mt-1 text-xs" :class="health.status === 'healthy' ? 'text-green-800 dark:text-green-300' : 'text-orange-900 dark:text-orange-300'">Current synchronization state only; successful historical runs are not retained as a separate log.</p>
                </div>
            </section>

            <section class="fieldops-panel grid gap-2 bg-white p-4" aria-labelledby="queue-backlog-title">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 id="queue-backlog-title" class="font-semibold text-gray-900">Background job queue</h2>
                    <button type="button" class="fieldops-secondary-action min-h-9" @click="refreshLogs">Refresh status</button>
                </div>
                <template v-if="queueBacklog.monitoringSupported">
                    <p class="text-sm text-gray-700">Queue: {{ queueBacklog.queue }} · {{ queueBacklog.readyJobs }} ready · {{ queueBacklog.delayedOrReservedJobs }} delayed or reserved · {{ queueBacklog.totalJobs }} total</p>
                    <p v-if="queueBacklog.oldestReadyAt" class="text-sm text-gray-600">Oldest ready job: {{ formatDate(queueBacklog.oldestReadyAt) }} ({{ formatQueueAge(queueBacklog.oldestReadyAgeSeconds) }})</p>
                    <p class="text-sm text-gray-700" role="status">{{ queueWorkerStatusText(queueBacklog.worker) }}<span v-if="queueBacklog.worker.lastSeenAt"> · {{ formatDate(queueBacklog.worker.lastSeenAt) }}</span></p>
                    <p v-if="!queueBacklog.quickBooksSyncEnabled" class="text-sm text-amber-950" role="status">
                        QuickBooks sync is paused; {{ queueBacklog.quickBooksPausedJobs.truncated ? "100 or more" : queueBacklog.quickBooksPausedJobs.count }} QuickBooks job(s) are held.
                        <Link :href="route('admin.integrations.quickbooks.show')" class="font-semibold underline">Manage QuickBooks sync</Link>
                    </p>
                    <p v-for="queue in queueBacklog.additionalQueues" :key="queue.queue" class="text-sm text-gray-700">
                        {{ queue.queue }}: {{ queue.readyJobs }} ready · {{ queue.delayedOrReservedJobs }} delayed or reserved · {{ queue.totalJobs }} total · {{ queueWorkerStatusText(queue.worker) }}
                    </p>
                    <p v-if="queueBacklog.failedJobsCount" class="text-sm text-orange-800" role="alert">
                        Laravel has {{ queueBacklog.failedJobsTruncated ? "100 or more" : queueBacklog.failedJobsCount }} failed background job record(s). Raw serialized jobs are not replayed here; review the per-record issues below and use a specific recovery action.
                    </p>
                    <p v-if="queueBacklog.readyJobs" class="text-sm text-gray-700">
                        Use the per-item Retry, Resync, or Resolve controls below. Each queues only the selected operation; the managed background worker processes it automatically. Do not repeat an action while that item is Pending.
                    </p>
                    <div v-if="queueBacklog.reviewItems.length" class="space-y-2 border-t border-gray-200 pt-3">
                        <h3 class="text-sm font-semibold text-gray-900">Read-only queued work review (up to 50)</h3>
                        <p class="text-xs text-gray-600">Only an allow-listed job category, queue, attempts, and timestamps are shown. Payloads and serialized job data are never displayed. These rows have no retry or discard controls.</p>
                        <ul class="divide-y divide-gray-200">
                            <li v-for="item in queueBacklog.reviewItems" :key="`${item.queue}-${item.id}`" class="py-2 text-sm text-gray-700">
                                <span class="font-medium">{{ item.type }}</span> · {{ item.queue }} · job #{{ item.id }} · {{ item.attempts }} attempt(s)
                                <span v-if="item.state"> · {{ item.state }}</span>
                                <span v-if="item.reservedAt"> since {{ formatDate(item.reservedAt) }}</span>
                                <span v-else-if="item.availableAt"> at {{ formatDate(item.availableAt) }}</span>
                                <span v-if="item.createdAt"> · queued {{ formatDate(item.createdAt) }}</span>
                            </li>
                        </ul>
                    </div>
                    <div v-if="queueBacklog.failedJobReviewItems.length" class="space-y-2 border-t border-gray-200 pt-3">
                        <h3 class="text-sm font-semibold text-gray-900">Failed queue records (up to 25)</h3>
                        <p class="text-xs text-gray-600">Failure exceptions and serialized payloads are intentionally hidden. This list never replays work. A failed QuickBooks customer-sync record can be explicitly forgotten after review; other failures must be resolved by their owning feature.</p>
                        <ul class="divide-y divide-gray-200">
                            <li v-for="item in visibleFailedJobReviewItems" :key="`failed-${item.id}`" class="space-y-3 py-3 text-sm text-gray-700">
                                <div>
                                    <span class="font-medium">{{ item.type }}</span> · {{ item.queue ?? "Unknown queue" }} · failed record #{{ item.id }}
                                    <span v-if="item.failedAt"> · failed {{ formatDate(item.failedAt) }}</span>
                                </div>
                                <div v-if="item.canForget" class="space-y-3 rounded-md border border-amber-300 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950">
                                    <button
                                        v-if="String(failedJobForget.id) !== String(item.id)"
                                        type="button"
                                        class="fieldops-secondary-action min-h-9 border-amber-500 px-3 py-1 text-amber-950"
                                        :disabled="busyKey !== null"
                                        @click="beginForgettingFailedJob(item)"
                                    >
                                        Forget failed record…
                                    </button>
                                    <form v-else class="grid gap-3" @submit.prevent="forgetFailedQueueRecord(item)">
                                        <p :id="`failed-job-forget-warning-${item.id}`" class="text-sm text-amber-950 dark:text-amber-100">
                                            This permanently removes only this failed queue record, including its retry payload and exception. It will not retry the job or contact QuickBooks. This cannot be undone.
                                        </p>
                                        <label class="flex items-start gap-2 text-sm text-amber-950 dark:text-amber-100">
                                            <input v-model="failedJobForget.confirmed" type="checkbox" class="mt-1 rounded border-amber-500">
                                            <span>I reviewed this failure and confirm that it should be permanently forgotten without retrying it.</span>
                                        </label>
                                        <label :for="`failed-job-forget-password-${item.id}`" class="text-sm font-medium text-amber-950 dark:text-amber-100">
                                            Your current password
                                            <input
                                                :id="`failed-job-forget-password-${item.id}`"
                                                v-model="failedJobForget.password"
                                                type="password"
                                                autocomplete="current-password"
                                                class="mt-1 block w-full rounded-md border-gray-300 text-sm text-gray-900"
                                                required
                                            >
                                        </label>
                                        <p v-if="failedJobForget.error" role="alert" class="text-sm text-orange-900 dark:text-orange-100">{{ failedJobForget.error }}</p>
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                type="submit"
                                                class="fieldops-secondary-action min-h-10 border-orange-500 text-orange-950"
                                                :disabled="busyKey !== null || !failedJobForget.confirmed || !failedJobForget.password"
                                                :aria-describedby="`failed-job-forget-warning-${item.id}`"
                                            >
                                                {{ busyKey === `forget-failed-${item.id}` ? "Forgetting…" : "Permanently forget record" }}
                                            </button>
                                            <button type="button" class="fieldops-secondary-action min-h-10" :disabled="busyKey !== null" @click="cancelForgettingFailedJob">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </li>
                        </ul>
                        <p v-if="!visibleFailedJobReviewItems.length" role="status" class="text-sm text-green-800">No failed queue records remain in this review list.</p>
                    </div>
                    <p v-if="queueBacklog.workerAttentionRequired" role="alert" class="rounded-md border border-amber-400 bg-amber-50 p-3 text-sm text-amber-950">
                        <template v-if="queueBacklog.worker.state === 'recently_seen'">
                            A worker has checked in, but ready jobs have waited more than two minutes. Check its processing/log status before requeuing anything.
                        </template>
                        <template v-else>
                            Ready jobs have waited more than two minutes and no recent worker heartbeat is visible. Ask the administrator to check the managed queue-worker service; queued work is still in the database and has not been discarded.
                        </template>
                    </p>
                </template>
                <p v-else class="text-sm text-gray-600">Queue backlog details are unavailable for the configured {{ queueBacklog.driver }} queue driver. Ask the administrator to verify its worker.</p>
            </section>

            <section class="fieldops-panel space-y-4 bg-white p-4" aria-labelledby="sharepoint-pointer-title">
                <div>
                    <h2 id="sharepoint-pointer-title" class="font-semibold text-gray-900">Local SharePoint metadata pointers</h2>
                    <p class="mt-1 text-sm text-gray-600">Retirement removes only selected FieldOps metadata rows. It makes no SharePoint request and does not delete or modify remote files. An external SharePoint-admin reconciliation reference and fresh password confirmation are required.</p>
                </div>
                <p
                    v-if="pointerRetirementFeedback"
                    :role="pointerRetirementFeedback.type === 'success' ? 'status' : 'alert'"
                    class="rounded-md border p-3 text-sm"
                    :class="pointerRetirementFeedback.type === 'success'
                        ? 'border-green-300 bg-green-50 text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-100'
                        : 'border-orange-300 bg-orange-50 text-orange-950 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-100'"
                >
                    {{ pointerRetirementFeedback.message }}
                </p>
                <p
                    v-if="pointerRetirementRequirements.length"
                    id="sharepoint-pointer-requirements"
                    role="status"
                    class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
                >
                    Not submitted. Complete these requirements to enable retirement: {{ pointerRetirementRequirements.join("; ") }}.
                </p>
                <p
                    v-if="busyKey === 'sharepoint-pointer-retirement'"
                    role="status"
                    aria-live="polite"
                    class="rounded-md border border-blue-300 bg-blue-50 p-3 text-sm text-blue-950 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-100"
                >
                    Submitting retirement request. The pointers will remain listed until the server confirms the result.
                </p>
                <p v-if="!visibleSharePointPointers.length" class="text-sm text-gray-600">No local SharePoint pointers are currently listed.</p>
                <template v-else>
                    <div class="space-y-2">
                        <label v-for="pointer in visibleSharePointPointers" :key="pointer.id" class="flex items-start gap-3 rounded-md border border-gray-200 p-3 text-sm">
                            <input v-model="selectedPointerIds" type="checkbox" :value="pointer.id" class="mt-1 rounded border-gray-400">
                            <span class="min-w-0">
                                <span class="block font-medium text-gray-900">{{ pointer.filename }} <span class="font-normal text-gray-600">(local pointer #{{ pointer.id }})</span></span>
                                <span class="block text-gray-600">{{ [pointer.customer, pointer.site, pointer.project, pointer.job].filter(Boolean).join(" · ") || "No owner label available" }}</span>
                                <span class="block text-xs text-gray-500">Recorded {{ formatDate(pointer.createdAt) }}</span>
                            </span>
                        </label>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm font-medium text-gray-700">SharePoint administrator UPN
                            <input v-model="pointerRetirement.sharepointAdminUpn" type="email" autocomplete="off" class="mt-1 block w-full rounded-md border-gray-300 text-sm" required>
                        </label>
                        <label class="text-sm font-medium text-gray-700">External verification / change reference
                            <input v-model="pointerRetirement.verificationReference" type="text" autocomplete="off" maxlength="80" placeholder="CHG-2026-1042" class="mt-1 block w-full rounded-md border-gray-300 text-sm" required>
                        </label>
                        <label class="text-sm font-medium text-gray-700">Your password
                            <input v-model="pointerRetirement.password" type="password" autocomplete="current-password" class="mt-1 block w-full rounded-md border-gray-300 text-sm" required>
                        </label>
                        <label class="flex items-start gap-2 self-end pb-2 text-sm text-gray-800">
                            <input v-model="pointerRetirement.remoteFilesPreserved" type="checkbox" class="mt-1 rounded border-gray-400">
                            <span>I confirmed with the SharePoint administrator that remote files are preserved/reconciled and only these local pointers should be retired.</span>
                        </label>
                    </div>
                    <button
                        type="button"
                        class="fieldops-secondary-action min-h-10 border-orange-500 text-orange-950"
                        :disabled="busyKey !== null || pointerRetirementRequirements.length > 0"
                        :aria-describedby="pointerRetirementRequirements.length ? 'sharepoint-pointer-requirements' : undefined"
                        @click="retireSharePointPointers"
                    >
                        {{ busyKey === "sharepoint-pointer-retirement" ? "Retiring local pointers…" : `Retire ${selectedPointerIds.length} selected local pointer(s)` }}
                    </button>
                </template>
            </section>

            <section class="fieldops-panel overflow-hidden bg-white" aria-labelledby="quickbooks-issues-title">
                <header class="border-b border-gray-200 px-4 py-3 sm:px-5">
                    <h2 id="quickbooks-issues-title" class="font-semibold text-gray-900">QuickBooks sync issues</h2>
                    <p class="mt-1 text-xs text-gray-600">Conflicted contract fields resolve from QuickBooks. Provider IDs and tokens are intentionally omitted.</p>
                    <p v-if="!quickBooksConnection.syncEnabled" class="mt-2 text-sm text-amber-900" role="status">
                        QuickBooks sync is paused. Resume it in <Link :href="route('admin.integrations.quickbooks.show')" class="font-semibold underline">QuickBooks settings</Link> to release held jobs.
                    </p>
                </header>
                <div v-if="quickBooks.length" class="divide-y divide-gray-200">
                    <article v-for="issue in quickBooks" :key="issue.id" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium text-gray-900">{{ issue.clientId ? `${issue.clientId} · ` : "" }}{{ issue.label }}</p>
                            <p class="text-sm text-gray-600">Status: {{ issue.status }}<span v-if="issue.lastSyncedAt"> · Last synced {{ formatDate(issue.lastSyncedAt) }}</span></p>
                            <p v-if="issue.status === 'pending' && !quickBooksConnection.syncEnabled" role="status" class="text-sm text-amber-900">QuickBooks sync is paused; this operation is held until sync resumes.</p>
                            <p v-else-if="issue.status === 'pending'" role="status" class="text-sm text-amber-900">QuickBooks refresh queued at {{ formatDate(issue.requestedAt) }}; waiting for the background worker. This is not complete yet.</p>
                            <p v-if="issue.status === 'failed'" role="alert" class="text-sm text-orange-800">{{ quickBooksErrorText(issue.error) }}</p>
                            <p v-if="issue.sites.length" class="text-xs text-amber-900">Pending Sites: {{ issue.sites.map((site) => site.name).join(", ") }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <button v-if="issue.canResolve" type="button" class="fieldops-secondary-action min-h-10 border-amber-500 text-amber-950" :disabled="busyKey !== null" @click="resolveQuickBooks(issue)">
                                {{ busyKey === issue.id ? "Queuing…" : "Resolve using QuickBooks" }}
                            </button>
                            <button v-if="issue.canRetryCreate" type="button" class="fieldops-secondary-action min-h-10" :disabled="busyKey !== null" @click="retryCustomerCreate(issue)">
                                {{ busyKey === issue.id ? "Queuing…" : "Retry sandbox create" }}
                            </button>
                            <Link :href="route('admin.customers', { q: issue.clientId || issue.label })" class="fieldops-secondary-action inline-flex min-h-10 items-center justify-center">Open customer</Link>
                        </div>
                    </article>
                </div>
                <p v-else class="px-4 py-8 text-center text-sm text-gray-600 sm:px-5">No Customer/Site sync issues are currently recorded.</p>
            </section>

            <section class="fieldops-panel overflow-hidden bg-white" aria-labelledby="visit-sync-title">
                <header class="border-b border-gray-200 px-4 py-3 sm:px-5">
                    <h2 id="visit-sync-title" class="font-semibold text-gray-900">Visit calendar synchronization</h2>
                </header>
                <div v-if="visits.length" class="divide-y divide-gray-200">
                    <article v-for="visit in visits" :key="visit.id" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium text-gray-900">{{ visit.jobNumber }} · {{ visit.jobTitle }}</p>
                            <p class="text-sm text-gray-600">{{ visit.customer }}<span v-if="visit.site"> · {{ visit.site }}</span><span v-if="visit.scheduledAt"> · {{ formatDate(visit.scheduledAt) }}</span></p>
                            <p class="text-sm text-gray-700">{{ visit.type }} Visit · {{ visit.status }}<span v-if="visit.syncedAt"> · Synced {{ formatDate(visit.syncedAt) }}</span></p>
                            <p v-if="['pending', 'syncing'].includes(visit.status)" role="status" class="text-sm text-amber-900">Visit synchronization is {{ visit.status === 'pending' ? 'queued and waiting for the background worker' : 'being processed' }}.</p>
                            <p v-if="visit.status === 'pending' && queueBacklog.worker.state !== 'recently_seen'" role="alert" class="text-sm text-orange-800">
                                The Visit is already queued, but no recent worker heartbeat is available. Do not queue it again; <a href="#queue-backlog-title" class="font-semibold underline">check worker status</a> above.
                            </p>
                            <p v-if="visit.status === 'pending_configuration' || (visit.status === 'failed' && !graphWrites.calendarEnabled)" class="text-sm text-amber-900">
                                Microsoft Graph calendar writes are disabled or need configuration.
                                <Link :href="route('admin.integrations.microsoft-graph.onboarding-targets.show')" class="font-semibold underline">Open Microsoft Graph settings</Link>
                            </p>
                            <p v-if="visit.error" role="alert" class="text-sm text-orange-800">{{ visit.error }}</p>
                        </div>
                        <button v-if="visit.status === 'failed'" type="button" class="fieldops-secondary-action min-h-10" :disabled="!visit.canRetry || busyKey !== null" :title="visit.canRetry ? 'Retry this failed Visit sync' : 'Graph calendar writes are disabled'" @click="retryVisit(visit)">
                            {{ busyKey === visit.id ? "Queuing…" : "Retry sync" }}
                        </button>
                        <Link v-if="visit.jobNumber" :href="route('admin.jobs', { q: visit.jobNumber })" class="fieldops-secondary-action inline-flex min-h-10 items-center justify-center">Open job</Link>
                    </article>
                </div>
                <p v-else class="px-4 py-8 text-center text-sm text-gray-600 sm:px-5">No Visit sync issues are currently recorded.</p>
            </section>

            <section class="fieldops-panel overflow-hidden bg-white" aria-labelledby="notification-sync-title">
                <header class="border-b border-gray-200 px-4 py-3 sm:px-5">
                    <h2 id="notification-sync-title" class="font-semibold text-gray-900">Graph notifications</h2>
                    <p class="mt-1 text-xs text-gray-600">Unknown outcomes are never blindly retried. Oversized or unavailable attachment sets require remediation first.</p>
                </header>
                <div v-if="notifications.length" class="divide-y divide-gray-200">
                    <article v-for="item in notifications" :key="item.id" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <div class="min-w-0 space-y-1">
                            <p class="font-medium text-gray-900">{{ item.jobNumber }} · {{ item.jobTitle }}</p>
                            <p class="text-sm text-gray-600">{{ item.type.replaceAll("_", " ") }} · {{ item.recipientRole }} · {{ item.status }}</p>
                            <p class="text-xs text-gray-500">Attempts: {{ item.attempts }}<span v-if="item.updatedAt"> · Updated {{ formatDate(item.updatedAt) }}</span></p>
                            <p v-if="['pending', 'sending'].includes(item.status)" role="status" class="text-sm text-amber-900">Notification is {{ item.status === 'pending' ? 'queued and waiting for the background worker' : 'being processed' }}.</p>
                            <p v-if="item.lastError" role="alert" class="text-sm text-orange-800">{{ item.lastError }}</p>
                        </div>
                        <button v-if="item.status === 'failed'" type="button" class="fieldops-secondary-action min-h-10" :disabled="!item.canRetry || busyKey !== null" :title="item.canRetry ? 'Retry this known failed notification' : 'Graph notification writes are disabled'" @click="retryNotification(item)">
                            {{ busyKey === item.id ? "Queuing…" : "Retry notification" }}
                        </button>
                        <Link v-if="item.jobNumber" :href="route('admin.jobs', { q: item.jobNumber })" class="fieldops-secondary-action inline-flex min-h-10 items-center justify-center">Open job</Link>
                    </article>
                </div>
                <p v-else class="px-4 py-8 text-center text-sm text-gray-600 sm:px-5">No Graph notification issues are currently recorded.</p>
            </section>

            <section class="fieldops-panel overflow-hidden bg-white" aria-labelledby="outlook-contact-sync-title">
                <header class="border-b border-gray-200 px-4 py-3 sm:px-5">
                    <h2 id="outlook-contact-sync-title" class="font-semibold text-gray-900">Outlook Contact synchronization</h2>
                    <p class="mt-1 text-xs text-gray-600">FieldOps remains authoritative. Outlook IDs are mailbox-scoped. Repair deletes and recreates the linked Outlook Contact from FieldOps; unknown create outcomes require manual reconciliation.</p>
                    <p v-if="!graphWrites.contactsEnabled" class="mt-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950" role="status">
                        Outlook Contact synchronization is disabled pending Microsoft 365 permission and mailbox-scope verification. Diagnostics remain available; write actions are disabled.
                    </p>
                </header>

                <form class="grid gap-3 border-b border-gray-200 px-4 py-4 sm:grid-cols-[minmax(12rem,1fr)_12rem_auto] sm:items-end sm:px-5" @submit.prevent="submitOutlookContactFilters">
                    <div class="space-y-1">
                        <label for="outlook-contact-search" class="text-sm font-medium text-gray-800">Find a sync item</label>
                        <ClearableSearchInput id="outlook-contact-search" v-model="outlookContactSearch" maxlength="100" autocomplete="off" class="fieldops-input min-h-10 w-full" placeholder="Contact, Customer, or Site" clear-label="Clear Contact search" />
                    </div>
                    <div class="space-y-1">
                        <label for="outlook-contact-status" class="text-sm font-medium text-gray-800">Contact status</label>
                        <select id="outlook-contact-status" v-model="outlookContactStatus" class="fieldops-input min-h-10 w-full">
                            <option value="action_needed">Action needed</option>
                            <option value="all">All statuses</option>
                            <option v-for="status in outlookContactFilters.statuses" :key="status" :value="status">{{ outlookStatusLabel(status) }}</option>
                        </select>
                    </div>
                    <button type="submit" class="fieldops-primary-action min-h-10">Filter queue</button>
                </form>

                <div v-if="outlookContacts.data.length" class="h-96 divide-y divide-gray-200 overflow-y-auto overscroll-contain">
                    <article v-for="contact in outlookContacts.data" :key="contact.id" class="flex flex-col gap-3 px-4 py-4 sm:px-5 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-gray-900">{{ contact.contact }}</p>
                                <span v-if="contact.archived" class="rounded border border-gray-300 px-2 py-0.5 text-xs text-gray-700">Archived</span>
                                <span class="rounded border border-gray-300 px-2 py-0.5 text-xs font-medium text-gray-800">{{ outlookStatusLabel(contact.status) }}</span>
                            </div>
                            <p class="text-sm text-gray-600">
                                <Link :href="route('admin.customers', { open_customer: contact.customerId })" class="font-medium text-blue-800 underline decoration-blue-300 underline-offset-2 hover:text-blue-950 dark:text-blue-300 dark:hover:text-blue-100">
                                    {{ contact.customer || `Customer #${contact.customerId}` }}
                                </Link>
                                <span v-if="contact.site"> · {{ contact.site }}</span>
                            </p>
                            <p class="break-all text-sm text-gray-700"><span class="font-medium">Outlook Contact ID:</span> {{ contact.outlookContactId || "Not linked" }}</p>
                            <p class="text-sm text-gray-600"><span class="font-medium">Last Sync:</span> {{ formatDate(contact.lastSyncedAt) }}<span v-if="contact.requestedAt"> · Requested {{ formatDate(contact.requestedAt) }}</span></p>
                            <p v-if="contact.status === 'pending'" role="status" class="text-sm text-amber-900">This Contact sync is queued and waiting for the background worker. Do not request another resync while it is Pending.</p>
                            <p v-else-if="contact.status === 'syncing'" role="status" class="text-sm text-blue-800">A queue worker is processing this Contact sync.</p>
                            <p
                                v-if="contact.error"
                                class="text-sm"
                                :class="contact.status === 'reconciled' ? 'text-green-800 dark:text-green-300' : contact.status === 'paused' ? 'text-amber-900' : 'text-orange-800'"
                                :role="['paused', 'reconciled'].includes(contact.status) ? 'status' : 'alert'"
                            >
                                {{ outlookErrorText(contact.error) }}
                            </p>
                            <p v-if="contact.blockedReason" class="text-sm text-amber-900">{{ contact.blockedReason }}</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap gap-2">
                            <button v-if="contact.canResync" type="button" class="fieldops-secondary-action min-h-10" :disabled="busyKey !== null" @click="resyncOutlookContact(contact)">
                                {{ busyKey === `outlook-resync-${contact.id}` ? "Queuing…" : contact.archived ? "Retry archive cleanup" : "Resync" }}
                            </button>
                            <button v-if="contact.canRepair" type="button" class="fieldops-secondary-action min-h-10 border-amber-500 text-amber-950" :disabled="busyKey !== null" @click="repairOutlookContact(contact)">
                                {{ busyKey === `outlook-repair-${contact.id}` ? "Queuing…" : contact.repairMode === "manual_reconciliation" ? "Repair after Outlook check" : "Repair / replace" }}
                            </button>
                        </div>
                    </article>
                </div>
                <p v-else class="px-4 py-8 text-center text-sm text-gray-600 sm:px-5">No Contacts match the current filters.</p>

                <PaginationChevronNav
                    v-if="outlookContacts.last_page > 1"
                    label="Outlook Contact pages"
                    :current-page="outlookContacts.current_page"
                    :last-page="outlookContacts.last_page"
                    :previous-url="outlookContacts.prev_page_url"
                    :next-url="outlookContacts.next_page_url"
                    :summary="`Page ${outlookContacts.current_page} of ${outlookContacts.last_page} · ${outlookContacts.total} Contacts`"
                />
            </section>

            <section class="fieldops-panel overflow-hidden bg-white" aria-labelledby="activity-log-title">
                <header class="border-b border-gray-200 px-4 py-3 sm:px-5">
                    <h2 id="activity-log-title" class="font-semibold text-gray-900">Project and Job activity</h2>
                    <p class="mt-1 text-xs text-gray-600">Recent Project, Job, Visit, and attachment activity (latest 100); entries cannot be edited or deleted.</p>
                </header>
                <div v-if="activities.length" class="divide-y divide-gray-200">
                    <article v-for="event in activities" :key="event.id" class="px-4 py-3 sm:px-5">
                        <p class="text-sm text-gray-900">{{ formatDate(event.createdAt) }} · {{ event.record }}</p>
                        <p class="mt-1 text-xs text-gray-500">{{ event.type }} activity</p>
                    </article>
                </div>
                <p v-else class="px-4 py-8 text-center text-sm text-gray-600 sm:px-5">No Project/Job activity has been recorded yet.</p>
            </section>

            <div class="grid gap-4 md:grid-cols-2">
                <section class="fieldops-panel space-y-2 bg-white p-4">
                    <h2 class="font-semibold text-gray-900">QuickBooks status</h2>
                    <p class="text-sm text-gray-700">Environment: {{ quickBooksConnection.environment }}</p>
                    <p class="text-sm" :class="quickBooksConnection.connected ? 'text-green-800' : 'text-amber-900'">
                        {{ quickBooksConnection.connected ? "Connected" : "Not connected for the configured environment" }}
                    </p>
                    <p class="text-xs text-gray-600">{{ health.quickBooks.linkedCustomers }} linked Customers · {{ health.quickBooks.failed }} failed · {{ health.quickBooks.pending }} pending</p>
                </section>
                <section class="fieldops-panel space-y-2 bg-white p-4">
                    <h2 class="font-semibold text-gray-900">Microsoft Graph status</h2>
                    <p class="text-sm text-gray-700">Calendar writes: {{ graphWrites.calendarEnabled ? "Enabled" : "Disabled" }} · Notification writes: {{ graphWrites.mailEnabled ? "Enabled" : "Disabled" }} · Outlook Contact sync: {{ graphWrites.contactsEnabled ? "Enabled" : "Disabled" }}</p>
                    <p class="text-xs text-gray-600">{{ health.graph.failed }} failed/blocked · {{ health.graph.pending }} pending · {{ health.graph.configuration }} awaiting configuration</p>
                    <p class="text-xs text-gray-600">Outlook Contacts: {{ health.graph.outlookContacts.failed }} failed/unknown · {{ health.graph.outlookContacts.pending }} pending</p>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>
