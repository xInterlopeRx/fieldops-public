<script setup lang="ts">
import axios from "axios";
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { XMarkIcon } from "@heroicons/vue/24/outline";
import { usePage } from "@inertiajs/vue3";
import AttachmentFileTypeIcon from "@/Components/AttachmentFileTypeIcon.vue";
import AttachmentOnlineActions from "@/Components/AttachmentOnlineActions.vue";
import StaffAssignmentDropdown from "@/Components/Admin/StaffAssignmentDropdown.vue";
import VisitWorkspace from "@/Components/Admin/VisitWorkspace.vue";
import { useDialogFocusTrap } from "@/Composables/useDialogFocusTrap";
import { useDesktopInlineWorkspace } from "@/Composables/useDesktopInlineWorkspace";
import { usePressAndHold } from "@/Composables/usePressAndHold";
import { useJobAttachmentUploadQueue } from "@/Composables/useJobAttachmentUploadQueue";
import { useWorkOrderStatus } from "@/Composables/useWorkOrderStatus";

interface ContactOption {
    id: number;
    siteId: number | null;
    siteName: string | null;
    type: string;
    name: string;
    email: string | null;
    phone: string | null;
}

interface JobWorkspaceData {
    job: {
        id: number;
        number: string | null;
        title: string;
        subtitle: string | null;
        description: string | null;
        status: string;
        scope: string;
        jobNotes: string | null;
        fieldNotes: string | null;
        clientCompletionSummary: string | null;
        siteId: number;
        customerId: number;
        customerName: string | null;
        projectName: string | null;
        projectNumber: string | null;
        contactIds: { client: number | null; technical: number | null; projectManager: number | null; contractor: number | null };
    };
    sites: Array<{ id: number; name: string; address: string | null }>;
    contacts: ContactOption[];
    additionalContactIds: number[];
    assignedUserIds: number[];
    staff: Array<{ id: number; name: string; email: string; roles: string[] }>;
    visits: Array<{ id: number; type: string; scheduledAt: string | null; scope: string | null; notes: string | null; durationMinutes: number | null; items: Array<{ id: number; name: string; description: string | null; type: string | null; quantity: string }> }>;
    attachments: Array<{ id: number; name: string; mimeType: string; size: number; createdAt: string | null; url: string; previewUrl?: string | null; thumbnailUrl?: string | null; onlineUrl?: string | null; availabilityUrl?: string | null; officeApp?: "word" | "excel" | "powerpoint" | null }>;
    activity: Array<{ id: number; summary: string; actor: string; createdAt: string }>;
    statusHistory: Array<{ id: number; fromStatus: string | null; toStatus: string; reason: string | null; actor: string; createdAt: string | null }>;
}

const props = withDefaults(defineProps<{ jobId: number; canArchive: boolean; nested?: boolean; inlineOnDesktop?: boolean; editRequestId?: number }>(), { nested: false, inlineOnDesktop: false, editRequestId: 0 });
const emit = defineEmits<{ close: []; saved: []; archived: []; "editing-change": [editing: boolean] }>();
const isDesktopInline = useDesktopInlineWorkspace(props.inlineOnDesktop);
const page = usePage();
const {
    definition: statusDefinition,
    badgeClass: statusBadgeClass,
    label: workOrderStatusLabel,
} = useWorkOrderStatus();
const requiresClientSummary = (status: string): boolean =>
    statusDefinition(status)?.client_completion_summary_required ?? false;
const requiresJobNotes = (status: string): boolean =>
    statusDefinition(status)?.job_notes_required ?? false;
const requiresPendingReason = (status: string): boolean =>
    statusDefinition(status)?.pending_reason_required ?? false;

const contactSlots = [
    { key: "clientContactId", label: "Client contact" },
    { key: "technicalContactId", label: "Technical contact" },
    { key: "projectManagerContactId", label: "Project manager" },
    { key: "contractorContactId", label: "Contractor check-in contact" },
] as const;

const loading = ref(true);
const isEditing = ref(false);
const saving = ref(false);
const archiving = ref(false);
const activityOpen = ref(false);
const selectedVisitId = ref<number | null>(null);
const selectedVisitEditRequestId = ref(0);
const selectedVisitEditTargetId = ref<number | null>(null);
const lastEditRequestId = ref(0);
const formElement = ref<HTMLFormElement | null>(null);
const panelElement = ref<HTMLElement | null>(null);
const scopeSectionElement = ref<HTMLDetailsElement | null>(null);
const handleDialogKeydown = useDialogFocusTrap(
    panelElement,
    () => !isDesktopInline.value,
);
const errorMessage = ref("");
const uploadStatusMessage = ref("");
const recentlyUploadedIds = ref(new Set<number>());
const uploadButtonFlash = ref(false);
let uploadButtonFlashTimer: number | undefined;
let uploadButtonFlashGeneration = 0;
const baseline = ref("");
const data = ref<JobWorkspaceData | null>(null);
const {
    uploads: queuedUploads,
    isOnline: attachmentUploadsOnline,
    loading: attachmentQueueLoading,
    error: attachmentQueueError,
    enqueue: enqueueAttachments,
    retry: retryQueuedAttachment,
    discard: discardQueuedAttachment,
    resume: resumeAttachmentQueue,
} = useJobAttachmentUploadQueue({
    userId: page.props.auth.user.id,
    workOrderId: props.jobId,
    onUploaded: (uploaded): void => {
        if (!data.value) return;

        const attachment = uploaded as JobWorkspaceData["attachments"][number];
        data.value.attachments = [
            attachment,
            ...data.value.attachments.filter((item) => item.id !== attachment.id),
        ].sort((left, right) => (right.createdAt ?? "").localeCompare(left.createdAt ?? ""));

        recentlyUploadedIds.value = new Set([...recentlyUploadedIds.value, attachment.id]);
        window.setTimeout(() => {
            const next = new Set(recentlyUploadedIds.value);
            next.delete(attachment.id);
            recentlyUploadedIds.value = next;
        }, 1400);

        uploadButtonFlash.value = false;
        const flashGeneration = ++uploadButtonFlashGeneration;
        window.requestAnimationFrame(() => {
            if (flashGeneration === uploadButtonFlashGeneration) uploadButtonFlash.value = true;
        });
        if (uploadButtonFlashTimer !== undefined) window.clearTimeout(uploadButtonFlashTimer);
        uploadButtonFlashTimer = window.setTimeout(() => {
            if (flashGeneration === uploadButtonFlashGeneration) uploadButtonFlash.value = false;
        }, 1400);

        uploadStatusMessage.value = `${attachment.name} uploaded successfully.`;
        emit("saved");
    },
});
const previewStatus = ref(page.props.work_order_default_status);
const previewStatusSaving = ref(false);
const draft = ref({
    siteId: 0,
    title: "",
    subtitle: "",
    description: "",
    status: page.props.work_order_default_status,
    scope: "",
    jobNotes: "",
    fieldNotes: "",
    pendingReason: "",
    scheduledAt: "",
    clientCompletionSummary: "",
    clientContactId: null as number | null,
    technicalContactId: null as number | null,
    projectManagerContactId: null as number | null,
    contractorContactId: null as number | null,
    additionalContactIds: [] as number[],
    assignedUserIds: [] as number[],
});

const snapshot = (): string => JSON.stringify({
    ...draft.value,
    additionalContactIds: [...draft.value.additionalContactIds].sort((left, right) => left - right),
    assignedUserIds: [...draft.value.assignedUserIds].sort((left, right) => left - right),
});
const hasChanges = computed(() => baseline.value !== "" && snapshot() !== baseline.value);
const selectedSite = computed(() => data.value?.sites.find((site) => site.id === draft.value.siteId) ?? null);
const sortedVisits = computed(() => [...(data.value?.visits ?? [])].sort((left, right) =>
    (left.scheduledAt ?? "9999").localeCompare(right.scheduledAt ?? "9999"),
));

watch(isEditing, (editing) => emit("editing-change", editing), { flush: "sync", immediate: true });

const setData = (value: JobWorkspaceData): void => {
    data.value = value;
    previewStatus.value = value.job.status;
    draft.value = {
        siteId: value.job.siteId,
        title: value.job.title,
        subtitle: value.job.subtitle ?? "",
        description: value.job.description ?? "",
        status: value.job.status,
        scope: value.job.scope,
        jobNotes: value.job.jobNotes ?? "",
        fieldNotes: value.job.fieldNotes ?? "",
        pendingReason: "",
        scheduledAt: value.visits[0]?.scheduledAt
            ? new Date(value.visits[0].scheduledAt).toISOString().slice(0, 16)
            : "",
        clientCompletionSummary: value.job.clientCompletionSummary ?? "",
        clientContactId: value.job.contactIds.client,
        technicalContactId: value.job.contactIds.technical,
        projectManagerContactId: value.job.contactIds.projectManager,
        contractorContactId: value.job.contactIds.contractor,
        additionalContactIds: [...value.additionalContactIds],
        assignedUserIds: [...value.assignedUserIds],
    };
    baseline.value = snapshot();
    isEditing.value = false;
};

const beginEditing = (): void => {
    isEditing.value = true;
    errorMessage.value = "";
};

const applyEditRequest = (): void => {
    if (!data.value || props.editRequestId <= lastEditRequestId.value) return;

    lastEditRequestId.value = props.editRequestId;
    beginEditing();
};

watch(() => props.editRequestId, applyEditRequest);

const openVisit = (visitId: number): void => {
    selectedVisitEditTargetId.value = null;
    selectedVisitId.value = visitId;
};

const editVisit = (visitId: number): void => {
    selectedVisitId.value = visitId;
    selectedVisitEditTargetId.value = visitId;
    selectedVisitEditRequestId.value += 1;
};

const closeVisit = (): void => {
    selectedVisitId.value = null;
    selectedVisitEditRequestId.value = 0;
    selectedVisitEditTargetId.value = null;
};

const visitPress = usePressAndHold(openVisit, editVisit);

const cancelEditing = (): void => {
    if (!data.value || saving.value) return;

    setData(data.value);
    errorMessage.value = "";
};

const requestClose = (): void => {
    if (isEditing.value || hasChanges.value || saving.value || archiving.value) return;

    emit("close");
};

const load = async (): Promise<void> => {
    loading.value = true;
    try {
        const response = await axios.get(route("admin.jobs.workspace.show", props.jobId));
        setData(response.data.data as JobWorkspaceData);
        applyEditRequest();
    } catch {
        errorMessage.value = "Job details could not be loaded.";
    } finally {
        loading.value = false;
    }
};

const refreshWorkspaceData = async (): Promise<void> => {
    try {
        const response = await axios.get(route("admin.jobs.workspace.show", props.jobId));
        data.value = response.data.data as JobWorkspaceData;
        emit("saved");
    } catch {
        errorMessage.value = "Job details could not be refreshed.";
    }
};

const uploadAttachment = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const files = input.files ? Array.from(input.files) : [];
    if (files.length === 0 || !data.value) return;

    errorMessage.value = "";
    uploadStatusMessage.value = "";
    await enqueueAttachments(files);
    input.value = "";
};

const removeMissingAttachment = (attachmentId: number): void => {
    if (data.value) {
        data.value.attachments = data.value.attachments.filter((attachment) => attachment.id !== attachmentId);
    }

    uploadStatusMessage.value = "This file was missing from SharePoint; its stale attachment link was removed.";
    emit("saved");
};

watch(attachmentUploadsOnline, (online) => {
    if (! online) return;

    if (data.value) {
        void resumeAttachmentQueue();

        return;
    }

    if (! loading.value) {
        void load().then(() => {
            if (data.value) void resumeAttachmentQueue();
        });
    }
});

const submit = async (): Promise<void> => {
    if (!hasChanges.value || !data.value) return;

    const form = formElement.value;
    const firstInvalid = form?.querySelector<HTMLElement>(":invalid");
    if (form && firstInvalid) {
        const collapsedSection = firstInvalid.closest("details");
        if (collapsedSection instanceof HTMLDetailsElement) collapsedSection.open = true;
        await nextTick();
        form.reportValidity();

        return;
    }

    saving.value = true;
    errorMessage.value = "";
    try {
        await axios.put(route("admin.jobs.workspace.update", props.jobId), {
            site_id: draft.value.siteId,
            title: draft.value.title,
            subtitle: draft.value.subtitle || null,
            description: draft.value.description || null,
            scope: draft.value.scope,
            job_notes: draft.value.jobNotes || null,
            field_notes: draft.value.fieldNotes || null,
            pending_reason: draft.value.pendingReason || null,
            scheduled_at: draft.value.scheduledAt || null,
            status: draft.value.status,
            timer_visit_id: selectedVisitId.value ?? (data.value.visits.length === 1 ? data.value.visits[0]?.id ?? null : null),
            client_completion_summary: draft.value.clientCompletionSummary || null,
            client_contact_id: draft.value.clientContactId,
            technical_contact_id: draft.value.technicalContactId,
            project_manager_contact_id: draft.value.projectManagerContactId,
            contractor_check_in_contact_id: draft.value.contractorContactId,
            additional_contact_ids: draft.value.additionalContactIds,
            assigned_user_ids: draft.value.assignedUserIds,
        });
        await load();
        emit("saved");
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? Object.values(error.response?.data?.errors ?? {}).flat()[0] ?? "Job changes could not be saved."
            : "Job changes could not be saved.";
    } finally {
        saving.value = false;
    }
};

const updatePreviewStatus = async (newStatus: string): Promise<void> => {
    if (!data.value || previewStatusSaving.value) return;

    const oldStatus = data.value.job.status;

    if (newStatus === oldStatus) return;

    if (newStatus === "scheduled" && data.value.visits.length === 0) {
        draft.value.status = newStatus;
        draft.value.scheduledAt = "";
        beginEditing();
        if (scopeSectionElement.value) {
            scopeSectionElement.value.open = true;
            void nextTick(() => {
                const section = scopeSectionElement.value;
                const scrollContainer = section?.closest('.fieldops-workspace-scroll') as HTMLElement | null;

                if (!section || !scrollContainer) return;

                const offset = section.getBoundingClientRect().top - scrollContainer.getBoundingClientRect().top;
                scrollContainer.scrollTo({
                    top: scrollContainer.scrollTop + offset,
                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                });
            });
        }
        errorMessage.value = "Choose the scheduled date and time, then save the Job.";

        return;
    }

    errorMessage.value = "";
    let clientCompletionSummary = data.value.job.clientCompletionSummary ?? "";
    let pendingReason = "";

    if (requiresClientSummary(newStatus) && clientCompletionSummary.trim() === "") {
        const enteredSummary = window.prompt("Enter the client completion summary before completing this Job.");

        if (enteredSummary === null) {
            previewStatus.value = oldStatus;

            return;
        }

        clientCompletionSummary = enteredSummary.trim();

        if (clientCompletionSummary === "") {
            errorMessage.value = "A client completion summary is required before completing this Job.";
            previewStatus.value = oldStatus;

            return;
        }
    }

    if (newStatus === "pending") {
        const enteredReason = window.prompt("Provide the reason this Job is moving to Pending.");
        if (enteredReason === null || enteredReason.trim() === "") {
            previewStatus.value = oldStatus;
            errorMessage.value = "A reason is required before moving this Job to Pending.";

            return;
        }
        pendingReason = enteredReason.trim();
    }

    previewStatusSaving.value = true;

    try {
        await axios.patch(route("work-orders.status.update", props.jobId), {
            status: newStatus,
            client_completion_summary: clientCompletionSummary || null,
            pending_reason: pendingReason || null,
            timer_visit_id: selectedVisitId.value ?? (data.value.visits.length === 1 ? data.value.visits[0]?.id ?? null : null),
        });
        await load();
        window.dispatchEvent(new Event("fieldops:time-entry-timer-refresh"));
        emit("saved");
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? Object.values(error.response?.data?.errors ?? {}).flat()[0] ?? "Job status could not be updated."
            : "Job status could not be updated.";
        previewStatus.value = oldStatus;
    } finally {
        previewStatusSaving.value = false;
    }
};

const archive = async (): Promise<void> => {
    if (!props.canArchive || archiving.value || !data.value) return;
    if (!window.confirm(`Archive job ${data.value.job.number ?? data.value.job.title}? Related visits and files will remain recoverable.`)) return;
    archiving.value = true;
    try {
        await axios.post(route("admin.archives.archive", { type: "work-orders", record: props.jobId }), { reason: "Archived from job profile." });
        emit("archived");
        emit("close");
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error) ? error.response?.data?.message ?? "Job could not be archived." : "Job could not be archived.";
    } finally {
        archiving.value = false;
    }
};

const formatDate = (value: string | null): string => value ? new Date(value).toLocaleString() : "Unscheduled";
const formatBytes = (value: number): string => value < 1024 ? `${value} B` : `${(value / 1024).toFixed(0)} KB`;

onMounted(async () => {
    panelElement.value?.focus();
    await load();

    if (data.value) {
        await resumeAttachmentQueue();
    }
});

onBeforeUnmount(() => {
    if (uploadButtonFlashTimer !== undefined) window.clearTimeout(uploadButtonFlashTimer);
});
</script>

<template>
    <div
        class="fieldops-workspace-backdrop"
        :class="{ 'fieldops-workspace-backdrop--nested': nested, 'fieldops-workspace-backdrop--inline-desktop': isDesktopInline }"
        tabindex="-1"
        @click.self="requestClose"
        @keydown.esc.stop="requestClose"
    >
        <section ref="panelElement" tabindex="-1" class="fieldops-workspace-panel fieldops-job-workspace" :class="{ 'fieldops-workspace-panel--inline-desktop': isDesktopInline }" :role="isDesktopInline ? 'region' : 'dialog'" :aria-modal="isDesktopInline ? undefined : 'true'" :aria-label="data ? `Job ${data.job.number} details` : 'Job details'" @keydown="handleDialogKeydown">
            <header class="relative flex shrink-0 items-start justify-between gap-4 border-b border-gray-200 px-4 py-4 sm:px-6">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ data?.job.customerName ?? "Job" }}<span v-if="data?.job.projectName"> · {{ data.job.projectName }}</span></p>
                    <h2 class="truncate text-lg font-semibold text-gray-900">{{ data?.job.number }} · {{ data?.job.title ?? "Loading job" }}</h2>
                    <span v-if="data" class="fieldops-status-badge mt-1" :class="statusBadgeClass(data.job.status)">{{ workOrderStatusLabel(data.job.status) }}</span>
                    <p v-if="data?.job.subtitle" class="truncate text-sm text-gray-600">{{ data.job.subtitle }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <template v-if="!isEditing">
                        <label class="sr-only" for="job-preview-status">Change Job status</label>
                        <select
                            id="job-preview-status"
                            v-model="previewStatus"
                            class="fieldops-client-input min-h-10 max-w-36 py-1 text-xs"
                            aria-label="Change Job status"
                            :disabled="previewStatusSaving || loading"
                            @change="updatePreviewStatus(previewStatus)"
                        >
                            <option v-for="status in page.props.work_order_statuses" :key="status.value" :value="status.value">{{ status.label }}</option>
                        </select>
                    </template>
                    <button type="button" class="fieldops-secondary-action min-h-10 px-3" :aria-expanded="activityOpen" aria-label="Show job activity" @click="activityOpen = !activityOpen">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                    </button>
                    <button v-if="!isEditing" type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Close job details" title="Close details" @click="requestClose">
                        <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
        <aside v-if="activityOpen && data" class="absolute right-4 top-14 z-10 max-h-[55vh] w-[min(24rem,calc(100vw-2rem))] space-y-2 overflow-y-auto rounded-xl border border-gray-200 bg-white p-3 shadow-xl" aria-label="Job activity">
                    <article v-for="event in data.statusHistory" :key="`status-${event.id}`" class="border-b border-gray-100 pb-2 last:border-0">
                        <p class="flex flex-wrap items-center gap-2 text-sm text-gray-900">{{ formatDate(event.createdAt) }} · <span class="fieldops-status-badge" :class="statusBadgeClass(event.fromStatus ?? 'created')">{{ workOrderStatusLabel(event.fromStatus ?? 'created') }}</span> → <span class="fieldops-status-badge" :class="statusBadgeClass(event.toStatus)">{{ workOrderStatusLabel(event.toStatus) }}</span> · {{ event.actor }}</p>
                        <p v-if="event.reason" class="whitespace-pre-wrap text-sm text-gray-700">Reason: {{ event.reason }}</p>
                    </article>
                    <article v-for="event in data.activity" :key="event.id" class="border-b border-gray-100 pb-2 last:border-0">
                        <p class="text-sm text-gray-900">{{ formatDate(event.createdAt) }} · {{ event.summary }}</p>
                    </article>
                    <p v-if="data.activity.length === 0" class="py-4 text-center text-sm text-gray-600">No activity has been recorded yet.</p>
                </aside>
            </header>

            <p v-if="errorMessage" role="alert" class="mx-4 mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 sm:mx-6">{{ errorMessage }}</p>
            <p v-if="attachmentQueueError" role="alert" class="mx-4 mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 sm:mx-6">{{ attachmentQueueError }}</p>
            <p v-if="uploadStatusMessage" role="status" class="mx-4 mt-3 rounded-lg border border-green-300 bg-green-50 px-3 py-2 text-sm text-green-900 sm:mx-6">{{ uploadStatusMessage }}</p>
            <div v-if="loading" class="flex-1 px-6 py-12 text-center text-sm text-gray-600">Loading job details…</div>

            <form v-else-if="data" ref="formElement" novalidate class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit()">
                <div class="fieldops-workspace-scroll" @click="activityOpen = false">
                    <details class="fieldops-workspace-accordion">
                        <summary class="fieldops-workspace-accordion__summary">Site details, visits &amp; attachments</summary>
                        <section class="grid gap-4 border-t border-gray-200 p-3">
                        <label class="fieldops-workspace-field">Select a site
                            <select v-model.number="draft.siteId" :disabled="!isEditing">
                                <option v-for="site in data.sites" :key="site.id" :value="site.id">{{ site.name }}</option>
                            </select>
                        </label>
                        <div class="rounded-lg border border-gray-200 p-3">
                            <p class="text-sm font-semibold text-gray-900">Site details</p>
                            <p class="mt-1 text-sm text-gray-700">{{ selectedSite?.address || "No site address recorded." }}</p>
                            <p class="mt-2 text-xs text-gray-500">{{ data.job.customerName }}<span v-if="data.job.projectName"> · {{ data.job.projectNumber ? `${data.job.projectNumber} · ` : "" }}{{ data.job.projectName }}</span></p>
                        </div>
                        <section class="space-y-2" aria-label="Job visits">
                            <h3 class="text-sm font-semibold text-gray-900">Visits</h3>
                            <article v-for="visit in sortedVisits" :key="visit.id" class="rounded-lg border border-gray-200 px-3 py-3">
                                <h4 class="text-sm font-semibold text-gray-900">{{ formatDate(visit.scheduledAt) }} · {{ visit.type }}</h4>
                                <p v-if="visit.scope" class="mt-1 whitespace-pre-wrap text-sm text-gray-700">{{ visit.scope }}</p>
                                <p v-if="visit.notes" class="mt-1 whitespace-pre-wrap text-sm text-gray-600">{{ visit.notes }}</p>
                                <button type="button" class="fieldops-secondary-action mt-2 min-h-9 px-3 py-1" aria-label="Open visit details. Press and hold to edit." title="Click to preview; press and hold to edit" v-on="visitPress(visit.id)">Open visit details</button>
                            </article>
                            <p v-if="sortedVisits.length === 0" class="text-sm text-gray-600">No visits recorded.</p>
                        </section>
                        <section class="space-y-2" aria-label="Job attachments">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-gray-900">Attachments</h3>
                                <label class="fieldops-secondary-action min-h-9 cursor-pointer px-3 py-1" :class="{ 'job-attachment-upload-confirm': uploadButtonFlash, 'pointer-events-none opacity-60': attachmentQueueLoading || queuedUploads.length >= 20 }">
                                    Upload files
                                    <input class="sr-only" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.webm,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx" :disabled="attachmentQueueLoading || queuedUploads.length >= 20" @change="uploadAttachment" />
                                </label>
                            </div>
                            <p class="text-xs text-gray-600">Select up to 20 supported files (20 MB each). Pending files stay in this browser profile on this device (not encrypted by FieldOps) until SharePoint confirms upload or you remove them.</p>
                            <p v-if="!attachmentUploadsOnline && queuedUploads.length" role="status" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950">
                                You’re offline. {{ queuedUploads.length }} pending file(s) will resume when this Job workspace is open and the connection returns.
                            </p>
                            <p v-else-if="queuedUploads.length" role="status" class="text-xs text-gray-600">
                                {{ queuedUploads.length }} file(s) pending on this device. They are not available to other staff until upload completes.
                            </p>
                            <p v-if="queuedUploads.length >= 20" role="status" class="text-xs text-amber-900">The device queue is full for this Job. Upload or remove pending files before adding more.</p>
                            <div v-for="attachment in data.attachments" :key="attachment.url" class="flex flex-col gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between" :class="{ 'job-attachment-uploaded': recentlyUploadedIds.has(attachment.id) }">
                                <a v-if="!attachment.onlineUrl" :href="attachment.previewUrl || attachment.url" :target="attachment.previewUrl ? '_blank' : undefined" :rel="attachment.previewUrl ? 'noreferrer' : undefined" class="flex min-w-0 items-center gap-3 text-blue-800 hover:text-blue-900">
                                    <AttachmentFileTypeIcon :file-name="attachment.name" :mime-type="attachment.mimeType" :thumbnail-url="attachment.thumbnailUrl" />
                                    <span class="min-w-0 flex-1 truncate">{{ attachment.name }}</span>
                                    <span class="shrink-0 text-xs text-gray-500">{{ formatBytes(attachment.size) }}</span>
                                </a>
                                <div v-else class="flex min-w-0 items-center gap-3">
                                    <AttachmentFileTypeIcon :file-name="attachment.name" :mime-type="attachment.mimeType" :thumbnail-url="attachment.thumbnailUrl" />
                                    <span class="min-w-0 flex-1 truncate text-gray-900">{{ attachment.name }}</span>
                                    <span class="shrink-0 text-xs text-gray-500">{{ formatBytes(attachment.size) }}</span>
                                </div>
                                <AttachmentOnlineActions
                                    v-if="attachment.onlineUrl"
                                    :attachment-id="attachment.id"
                                    :file-name="attachment.name"
                                    :online-url="attachment.onlineUrl"
                                    :open-url="attachment.previewUrl || attachment.url"
                                    :availability-url="attachment.availabilityUrl"
                                    @missing="removeMissingAttachment"
                                />
                            </div>
                            <div v-for="upload in queuedUploads" :key="upload.clientUploadId" class="flex flex-col gap-2 rounded-lg border border-dashed border-amber-300 bg-amber-50/60 px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between">
                                <div class="flex min-w-0 items-center gap-3">
                                    <AttachmentFileTypeIcon :file-name="upload.name" :mime-type="upload.mimeType" />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate font-medium text-gray-900">{{ upload.name }}</span>
                                        <span class="text-xs text-amber-900">{{ upload.status === 'uploading' ? 'Uploading…' : upload.status === 'failed' ? 'Upload needs attention' : attachmentUploadsOnline ? 'Queued on this device' : 'Waiting for connection' }} · {{ formatBytes(upload.size) }}</span>
                                        <span v-if="upload.error" class="block text-xs text-red-800">{{ upload.error }}</span>
                                    </span>
                                </div>
                                <span class="flex shrink-0 gap-2">
                                    <button v-if="upload.status === 'failed'" type="button" class="fieldops-secondary-action min-h-9 px-3 py-1 text-xs" @click="retryQueuedAttachment(upload.clientUploadId)">Retry</button>
                                    <button v-if="upload.status !== 'uploading'" type="button" class="fieldops-secondary-action min-h-9 px-3 py-1 text-xs" @click="discardQueuedAttachment(upload.clientUploadId)">Remove</button>
                                </span>
                            </div>
                            <p v-if="data.attachments.length === 0" class="text-sm text-gray-600">No attachments.</p>
                        </section>
                        </section>
                    </details>

                    <details class="fieldops-workspace-accordion">
                        <summary class="fieldops-workspace-accordion__summary">Contacts</summary>
                        <section class="grid gap-4 border-t border-gray-200 p-3">
                        <p class="text-sm text-gray-600">Job contacts are selected only from this customer’s contacts.</p>
                        <label v-for="slot in contactSlots" :key="slot.key" class="fieldops-workspace-field">
                            {{ slot.label }}
                            <select v-model="draft[slot.key]" :disabled="!isEditing">
                                <option :value="null">No contact selected</option>
                                <option v-for="contact in data.contacts" :key="contact.id" :value="contact.id">{{ `${contact.name}${contact.siteName ? ` · ${contact.siteName}` : ""}` }}</option>
                            </select>
                        </label>
                        <fieldset class="space-y-2" :disabled="!isEditing">
                            <legend class="text-sm font-semibold text-gray-900">Additional contacts</legend>
                            <label v-for="contact in data.contacts" :key="contact.id" class="flex min-h-11 items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                <input v-model="draft.additionalContactIds" type="checkbox" :value="contact.id" />
                                <span class="min-w-0 flex-1"><span class="block truncate">{{ contact.name }}</span><small class="text-xs text-gray-500">{{ contact.email || contact.phone || contact.type }}<span v-if="contact.siteName"> · {{ contact.siteName }}</span></small></span>
                            </label>
                            <p v-if="data.contacts.length === 0" class="text-sm text-gray-600">No customer contacts are available.</p>
                        </fieldset>
                        </section>
                    </details>

                    <details ref="scopeSectionElement" open class="fieldops-workspace-accordion">
                        <summary class="fieldops-workspace-accordion__summary">Scope, notes &amp; assigned staff</summary>
                        <section class="grid gap-4 border-t border-gray-200 p-3">
                        <label class="fieldops-workspace-field">Job title<input v-model="draft.title" required maxlength="255" :readonly="!isEditing" /></label>
                        <label class="fieldops-workspace-field">Subtitle<input v-model="draft.subtitle" maxlength="255" :readonly="!isEditing" /></label>
                        <label class="fieldops-workspace-field">Status<select v-model="draft.status" :disabled="!isEditing"><option v-for="status in page.props.work_order_statuses" :key="status.value" :value="status.value">{{ status.label }}</option></select></label>
                        <label v-if="draft.status === 'scheduled'" class="fieldops-workspace-field">Scheduled date and time<input v-model="draft.scheduledAt" type="datetime-local" step="900" :required="draft.status === 'scheduled' && data.visits.length === 0" :readonly="!isEditing" /></label>
                        <label v-if="requiresPendingReason(draft.status) && data.job.status !== draft.status" class="fieldops-workspace-field">Reason for Pending (required)<textarea v-model="draft.pendingReason" rows="2" maxlength="2000" required :readonly="!isEditing" /></label>
                        <label class="fieldops-workspace-field">Description<textarea v-model="draft.description" rows="3" maxlength="20000" :readonly="!isEditing" /></label>
                        <label class="fieldops-workspace-field">Required job scope<textarea v-model="draft.scope" rows="5" required maxlength="20000" :readonly="!isEditing" /></label>
                        <label class="fieldops-workspace-field">Internal notes<textarea v-model="draft.jobNotes" rows="4" maxlength="20000" :readonly="!isEditing" /></label>
                        <label class="fieldops-workspace-field">Field notes (included in client updates and reports)<textarea v-model="draft.fieldNotes" rows="4" maxlength="20000" :readonly="!isEditing" /></label>
                        <label v-if="requiresClientSummary(draft.status)" class="fieldops-workspace-field">Client completion summary<textarea v-model="draft.clientCompletionSummary" rows="3" maxlength="5000" required :readonly="!isEditing" /></label>
                        <section class="space-y-2">
                            <h3 class="text-sm font-semibold text-gray-900">Assigned staff</h3>
                            <StaffAssignmentDropdown v-model="draft.assignedUserIds" :staff="data.staff" :disabled="!isEditing" />
                        </section>
                        </section>
                    </details>

                    <details class="fieldops-workspace-accordion">
                        <summary class="fieldops-workspace-accordion__summary">Items used on this Job</summary>
                        <section class="grid gap-4 border-t border-gray-200 p-3">
                            <p class="text-sm text-gray-600">Recorded by Visit. Open a Visit to add or adjust Item quantities. Prices, costs, and stock are not shown or tracked.</p>
                        <article v-for="visit in sortedVisits" :key="visit.id" class="grid gap-2 rounded-xl border border-gray-200 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h4 class="text-sm font-semibold text-gray-900">{{ formatDate(visit.scheduledAt) }} · {{ visit.type }}</h4>
                                <button type="button" class="fieldops-secondary-action min-h-9 px-3 py-1" aria-label="Open visit details. Press and hold to edit." title="Click to preview; press and hold to edit" v-on="visitPress(visit.id)">Open Visit</button>
                            </div>
                            <ul v-if="visit.items.length > 0" class="grid gap-2">
                                <li v-for="item in visit.items" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <span>{{ item.name }}<span v-if="item.type" class="ml-2 text-xs text-gray-500">{{ item.type }}</span></span>
                                    <small v-if="item.description" class="basis-full text-xs text-gray-600">{{ item.description }}</small>
                                    <span class="font-medium tabular-nums">Qty {{ item.quantity }}</span>
                                </li>
                            </ul>
                            <p v-else class="text-sm text-gray-600">No Items recorded for this Visit.</p>
                        </article>
                        <p v-if="sortedVisits.length === 0" class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-600">Record a Visit before adding Item usage.</p>
                        </section>
                    </details>
                </div>

                <footer class="fieldops-workspace-footer">
                    <button v-if="isEditing" type="button" class="fieldops-delete-action" :disabled="!canArchive || archiving" @click="archive">{{ archiving ? "Archiving…" : "Delete" }}</button>
                    <div class="ml-auto flex items-center gap-2">
                        <button v-if="isEditing" type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Cancel job edits" title="Cancel editing" :disabled="saving" @click="cancelEditing">
                            <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                        </button>
                        <button v-else type="button" class="fieldops-secondary-action fieldops-edit-action min-h-10" @click="beginEditing">Edit</button>
                        <button v-if="isEditing" type="submit" class="fieldops-submit-action min-h-10" :disabled="saving || !hasChanges">{{ saving ? "Saving…" : "Submit" }}</button>
                    </div>
                </footer>
            </form>
        </section>
    </div>
    <VisitWorkspace
        v-if="selectedVisitId !== null"
        :visit-id="selectedVisitId"
        :can-archive="canArchive"
        :nested="true"
        :edit-request-id="selectedVisitEditTargetId === selectedVisitId ? selectedVisitEditRequestId : 0"
        @close="closeVisit"
        @saved="refreshWorkspaceData"
        @archived="refreshWorkspaceData"
    />
</template>

<style scoped>
.job-attachment-uploaded {
    animation: job-attachment-upload-confirm 600ms ease-in-out 2;
    border-color: rgb(34 197 94);
}

.job-attachment-upload-confirm {
    animation: job-attachment-upload-confirm 600ms ease-in-out 2;
}

@keyframes job-attachment-upload-confirm {
    0%, 100% { background-color: white; }
    50% { background-color: rgb(220 252 231); }
}

@media (prefers-reduced-motion: reduce) {
    .job-attachment-uploaded,
    .job-attachment-upload-confirm {
        animation: none;
        background-color: rgb(220 252 231);
    }
}
</style>
