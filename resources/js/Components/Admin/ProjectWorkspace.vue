<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, reactive, ref, watch } from "vue";
import { XMarkIcon } from "@heroicons/vue/24/outline";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import AttachmentFileTypeIcon from "@/Components/AttachmentFileTypeIcon.vue";
import AttachmentOnlineActions from "@/Components/AttachmentOnlineActions.vue";
import JobWorkspace from "@/Components/Admin/JobWorkspace.vue";
import StaffAssignmentDropdown from "@/Components/Admin/StaffAssignmentDropdown.vue";
import { useDialogFocusTrap } from "@/Composables/useDialogFocusTrap";
import { useDesktopInlineWorkspace } from "@/Composables/useDesktopInlineWorkspace";
import { usePressAndHold } from "@/Composables/usePressAndHold";
import { useWorkOrderStatus } from "@/Composables/useWorkOrderStatus";

interface ProjectWorkspaceData {
    project: { id: number; number: string | null; name: string; notes: string | null; status: string; customerName: string | null };
    sites: Array<{ id: number; name: string; address: string | null }>;
    availableSites: Array<{ id: number; name: string; address: string | null }>;
    attachments: Array<{ id: number; name: string; mimeType: string; size: number; uploadedBy: string | null; createdAt: string; url: string; previewUrl?: string | null; thumbnailUrl?: string | null; onlineUrl?: string | null; availabilityUrl?: string | null; officeApp?: "word" | "excel" | "powerpoint" | null }>;
    jobs: Array<{ id: number; number: string | null; title: string; status: string; site: string | null; visitsCount: number }>;
    assignedUserIds: number[];
    activity: Array<{ id: number; summary: string; actor: string; createdAt: string }>;
}

interface StaffOption {
    id: number;
    name: string;
    email: string;
    roles: string[];
}

const props = withDefaults(defineProps<{ projectId: number; canArchive: boolean; nested?: boolean; inlineOnDesktop?: boolean; editRequestId?: number }>(), { nested: false, inlineOnDesktop: false, editRequestId: 0 });
const emit = defineEmits<{ close: []; saved: []; archived: []; "editing-change": [editing: boolean] }>();
const isDesktopInline = useDesktopInlineWorkspace(props.inlineOnDesktop);
const { badgeClass: jobStatusBadgeClass, label: jobStatusLabel } = useWorkOrderStatus();

const tabs = [
    { id: "details", label: "Details" },
    { id: "jobs", label: "Jobs" },
    { id: "activity", label: "Activity" },
] as const;

const activeTab = ref<(typeof tabs)[number]["id"]>("details");
const loading = ref(true);
const isEditing = ref(false);
const saving = ref(false);
const uploading = ref(false);
const archiving = ref(false);
const search = ref("");
const selectedJobId = ref<number | null>(null);
const selectedJobEditRequestId = ref(0);
const selectedJobEditTargetId = ref<number | null>(null);
const lastEditRequestId = ref(0);
const sortBy = ref("job_number_asc");
const errorMessage = ref("");
const statusMessage = ref("");
const baseline = ref("");
const panelElement = ref<HTMLElement | null>(null);
const data = ref<ProjectWorkspaceData | null>(null);
const staff = ref<StaffOption[]>([]);
const handleDialogKeydown = useDialogFocusTrap(
    panelElement,
    () => !isDesktopInline.value,
);
const draft = reactive({ name: "", notes: "", status: "created", siteIds: [] as number[], assignedUserIds: [] as number[] });

watch(isEditing, (editing) => emit("editing-change", editing), { flush: "sync", immediate: true });

const snapshot = (): string => JSON.stringify({
    name: draft.name,
    notes: draft.notes,
    status: draft.status,
    siteIds: [...draft.siteIds].sort((left, right) => left - right),
    assignedUserIds: [...draft.assignedUserIds].sort((left, right) => left - right),
});
const hasChanges = computed(() => baseline.value !== "" && snapshot() !== baseline.value);
const visibleJobs = computed(() => {
    const query = search.value.trim().toLocaleLowerCase();
    const jobs = [...(data.value?.jobs ?? [])]
        .filter((job) => !query || `${job.number ?? ""} ${job.title} ${job.site ?? ""} ${job.status}`.toLocaleLowerCase().includes(query));
    const compareText = (left: string | null, right: string | null): number =>
        (left ?? "").localeCompare(right ?? "", undefined, { numeric: true, sensitivity: "base" });

    return jobs.sort((left, right) => {
        if (sortBy.value === "job_number_desc") return compareText(right.number, left.number);
        if (sortBy.value === "title") return compareText(left.title, right.title);
        if (sortBy.value === "status") return compareText(left.status, right.status);

        return compareText(left.number, right.number);
    });
});

const setData = (value: ProjectWorkspaceData): void => {
    data.value = value;
    Object.assign(draft, {
        name: value.project.name,
        notes: value.project.notes ?? "",
        status: value.project.status,
        siteIds: value.sites.map((site) => site.id),
        assignedUserIds: [...value.assignedUserIds],
    });
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

const openJob = (jobId: number): void => {
    selectedJobEditTargetId.value = null;
    selectedJobId.value = jobId;
};

const editJob = (jobId: number): void => {
    selectedJobId.value = jobId;
    selectedJobEditTargetId.value = jobId;
    selectedJobEditRequestId.value += 1;
};

const closeJob = (): void => {
    selectedJobId.value = null;
    selectedJobEditRequestId.value = 0;
    selectedJobEditTargetId.value = null;
};

const jobPress = usePressAndHold(openJob, editJob);

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
        const response = await axios.get(route("admin.projects.workspace.show", props.projectId));
        setData(response.data.data as ProjectWorkspaceData);
        staff.value = response.data.staff as StaffOption[];
        applyEditRequest();
    } catch {
        errorMessage.value = "Project details could not be loaded.";
    } finally {
        loading.value = false;
    }
};

const refreshWorkspace = async (): Promise<void> => {
    try {
        const response = await axios.get(route("admin.projects.workspace.show", props.projectId));
        data.value = response.data.data as ProjectWorkspaceData;
        staff.value = response.data.staff as StaffOption[];
        emit("saved");
    } catch {
        errorMessage.value = "Project details could not be refreshed.";
    }
};

const removeMissingAttachment = (attachmentId: number): void => {
    if (data.value) {
        data.value.attachments = data.value.attachments.filter((attachment) => attachment.id !== attachmentId);
    }

    statusMessage.value = "This file was missing from SharePoint; its stale FieldOps attachment link was removed.";
};

const submit = async (): Promise<void> => {
    if (!hasChanges.value) return;
    saving.value = true;
    errorMessage.value = "";
    try {
        await axios.put(route("admin.projects.workspace.update", props.projectId), {
            name: draft.name,
            notes: draft.notes || null,
            status: draft.status,
            site_ids: draft.siteIds,
            assigned_user_ids: draft.assignedUserIds,
        });
        await load();
        emit("saved");
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? Object.values(error.response?.data?.errors ?? {}).flat()[0] ?? "Project changes could not be saved."
            : "Project changes could not be saved.";
    } finally {
        saving.value = false;
    }
};

const archive = async (): Promise<void> => {
    if (!props.canArchive || archiving.value || !data.value) return;
    if (!window.confirm(`Archive ${data.value.project.name}? Related jobs and records will remain recoverable.`)) return;
    archiving.value = true;
    try {
        await axios.post(route("admin.archives.archive", { type: "projects", record: props.projectId }), { reason: "Archived from project profile." });
        emit("archived");
        emit("close");
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error) ? error.response?.data?.message ?? "Project could not be archived." : "Project could not be archived.";
    } finally {
        archiving.value = false;
    }
};

const uploadAttachment = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file || !data.value) return;

    uploading.value = true;
    errorMessage.value = "";
    const formData = new FormData();
    formData.append("file", file);

    try {
        const response = await axios.post(route("admin.projects.attachments.store", props.projectId), formData);
        data.value.attachments.push(response.data.data);
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error) ? error.response?.data?.message ?? "Attachment could not be uploaded." : "Attachment could not be uploaded.";
    } finally {
        uploading.value = false;
        input.value = "";
    }
};

const formatBytes = (value: number): string => value < 1024 ? `${value} B` : `${(value / 1024).toFixed(0)} KB`;

const formatDate = (value: string): string => new Date(value).toLocaleString();

onMounted(() => {
    panelElement.value?.focus();
    void load();
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
        <section ref="panelElement" tabindex="-1" class="fieldops-workspace-panel" :class="{ 'fieldops-workspace-panel--inline-desktop': isDesktopInline }" :role="isDesktopInline ? 'region' : 'dialog'" :aria-modal="isDesktopInline ? undefined : 'true'" :aria-label="data ? `${data.project.name} project details` : 'Project details'" @keydown="handleDialogKeydown">
            <header class="flex items-start justify-between gap-4 border-b border-gray-200 px-4 py-4 sm:px-6">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Project</p>
                    <h2 class="truncate text-lg font-semibold text-gray-900">{{ data?.project.name ?? "Loading project" }}</h2>
                    <p class="text-sm text-gray-600">{{ data?.project.number || "No project number" }}<span v-if="data?.project.customerName"> · {{ data.project.customerName }}</span></p>
                </div>
                <button v-if="!isEditing" type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Close project details" title="Close details" @click="requestClose">
                    <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                </button>
            </header>

            <nav class="fieldops-workspace-tabs" aria-label="Project sections">
                <button v-for="tab in tabs" :key="tab.id" type="button" class="fieldops-workspace-tab" :class="{ 'fieldops-workspace-tab--active': activeTab === tab.id }" @click="activeTab = tab.id; search = ''">{{ tab.label }}</button>
            </nav>

            <p v-if="errorMessage" role="alert" class="mx-4 mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 sm:mx-6">{{ errorMessage }}</p>
            <p v-if="statusMessage" role="status" class="mx-4 mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800 sm:mx-6">{{ statusMessage }}</p>
            <div v-if="loading" class="flex-1 px-6 py-12 text-center text-sm text-gray-600">Loading project details…</div>

            <form v-else-if="data" class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
                <div class="fieldops-workspace-scroll">
                    <section v-show="activeTab === 'details'" class="space-y-5">
                        <label class="fieldops-workspace-field">Project name<input v-model="draft.name" required maxlength="255" :readonly="!isEditing" /></label>
                        <label class="fieldops-workspace-field">Status
                            <select v-model="draft.status" :disabled="!isEditing">
                                <option value="created">Created</option><option value="scheduled">Scheduled</option><option value="planning">Planning</option><option value="active">Active</option><option value="on_hold">On hold</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option>
                            </select>
                        </label>
                        <label class="fieldops-workspace-field">Project notes<textarea v-model="draft.notes" rows="5" maxlength="20000" :readonly="!isEditing" /></label>
                        <fieldset class="space-y-2" :disabled="!isEditing">
                            <legend class="text-sm font-semibold text-gray-900">Sites for this project</legend>
                            <label v-for="site in data.availableSites" :key="site.id" class="flex min-h-11 items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                <input v-model="draft.siteIds" type="checkbox" :value="site.id" />
                                <span class="min-w-0 flex-1"><span class="block truncate font-medium">{{ site.name }}</span><small class="text-xs text-gray-500">{{ site.address || "No address recorded" }}</small></span>
                            </label>
                            <p v-if="data.availableSites.length === 0" class="text-sm text-gray-600">This customer has no active sites.</p>
                        </fieldset>
                        <section class="space-y-2">
                            <h3 class="text-sm font-semibold text-gray-900">Assigned staff</h3>
                            <StaffAssignmentDropdown v-model="draft.assignedUserIds" :staff="staff" :disabled="!isEditing" />
                        </section>
                        <section class="space-y-2" aria-label="Project attachments">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-gray-900">Attachments</h3>
                                <label v-if="isEditing" class="fieldops-secondary-action min-h-9 cursor-pointer px-3 py-1">
                                    {{ uploading ? "Uploading…" : "Upload" }}
                                    <input class="sr-only" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.webm,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx" :disabled="uploading" @change="uploadAttachment" />
                                </label>
                            </div>
                            <div v-for="attachment in data.attachments" :key="attachment.url" class="flex flex-col gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between">
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
                            <p v-if="data.attachments.length === 0" class="text-sm text-gray-600">No attachments.</p>
                        </section>
                    </section>

                    <section v-show="activeTab === 'jobs'" class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm text-gray-600">Select a job to open its details.</p>
                            <label class="text-xs text-gray-600">Sort
                                <select v-model="sortBy" class="ml-1 rounded border-gray-300 py-1 text-xs">
                                    <option value="job_number_asc">Job number ↑</option><option value="job_number_desc">Job number ↓</option><option value="title">Title</option><option value="status">Status</option>
                                </select>
                            </label>
                        </div>
                        <button v-for="job in visibleJobs" :key="job.id" type="button" class="fieldops-workspace-list-row" :aria-label="`Open job ${job.number ?? job.title} details. Press and hold to edit.`" title="Click to preview; press and hold to edit" v-on="jobPress(job.id)">
                            <span class="min-w-0 flex-1"><strong class="block truncate">{{ job.number }} · {{ job.title }}</strong><small>{{ job.site || "No site" }} · {{ job.visitsCount }} visits</small></span>
                            <span class="fieldops-status-badge shrink-0" :class="jobStatusBadgeClass(job.status)">{{ jobStatusLabel(job.status) }}</span>
                            <span aria-hidden="true">›</span>
                        </button>
                        <p v-if="visibleJobs.length === 0" class="py-8 text-center text-sm text-gray-600">No jobs match this search.</p>
                    </section>

                    <section v-show="activeTab === 'activity'" class="space-y-2">
                        <article v-for="event in data.activity" :key="event.id" class="rounded-lg border border-gray-200 px-3 py-3">
                            <p class="text-sm text-gray-900">{{ formatDate(event.createdAt) }} · {{ event.summary }}</p>
                        </article>
                        <p v-if="data.activity.length === 0" class="py-8 text-center text-sm text-gray-600">No activity has been recorded yet.</p>
                    </section>
                </div>

                <div v-if="activeTab === 'jobs'" class="border-t border-gray-200 p-3">
                    <label class="sr-only" for="project-job-search">Search project jobs</label>
                    <ClearableSearchInput id="project-job-search" v-model="search" class="fieldops-client-input fieldops-directory-search-input" placeholder="Search jobs in this project" clear-label="Clear project job search" />
                </div>

                <footer class="fieldops-workspace-footer">
                    <button v-if="isEditing" type="button" class="fieldops-delete-action" :disabled="!canArchive || archiving" @click="archive">{{ archiving ? "Archiving…" : "Delete" }}</button>
                    <div class="ml-auto flex items-center gap-2">
                        <button v-if="isEditing" type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Cancel project edits" title="Cancel editing" :disabled="saving" @click="cancelEditing">
                            <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                        </button>
                        <button v-else type="button" class="fieldops-secondary-action fieldops-edit-action min-h-10" @click="beginEditing">Edit</button>
                        <button v-if="isEditing" type="submit" class="fieldops-submit-action min-h-10" :disabled="saving || !hasChanges">{{ saving ? "Saving…" : "Submit" }}</button>
                    </div>
                </footer>
            </form>
        </section>
    </div>
    <JobWorkspace
        v-if="selectedJobId !== null"
        :job-id="selectedJobId"
        :can-archive="canArchive"
        :nested="true"
        :edit-request-id="selectedJobEditTargetId === selectedJobId ? selectedJobEditRequestId : 0"
        @close="closeJob"
        @saved="refreshWorkspace"
        @archived="refreshWorkspace"
    />
</template>
