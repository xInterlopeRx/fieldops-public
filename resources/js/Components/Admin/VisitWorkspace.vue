<script setup lang="ts">
import axios from "axios";
import { computed, onMounted, ref, watch } from "vue";
import { XMarkIcon } from "@heroicons/vue/24/outline";
import AttachmentFileTypeIcon from "@/Components/AttachmentFileTypeIcon.vue";
import { useDialogFocusTrap } from "@/Composables/useDialogFocusTrap";
import VisitItemUsageEditor from "@/Components/VisitItemUsageEditor.vue";

interface VisitWorkspaceData {
    visit: {
        id: number;
        type: string;
        scheduledAt: string | null;
        durationMinutes: number | null;
        scope: string;
        notes: string | null;
        jobId: number;
        jobNumber: string | null;
        jobTitle: string;
        siteName: string | null;
        siteAddress: string | null;
        customerName: string | null;
        itemsEditable: boolean;
    };
    attachments: Array<{ id: number; name: string; mimeType: string; size: number; url: string }>;
    items: Array<{ id: number; itemId: number; name: string; description: string | null; type: string | null; quantity: string }>;
    activity: Array<{ id: number; summary: string; actor: string; createdAt: string }>;
}

const props = withDefaults(defineProps<{ visitId: number; canArchive: boolean; nested?: boolean; editRequestId?: number }>(), { nested: false, editRequestId: 0 });
const emit = defineEmits<{ close: []; saved: []; archived: [] }>();

const loading = ref(true);
const isEditing = ref(false);
const saving = ref(false);
const archiving = ref(false);
const activityOpen = ref(false);
const errorMessage = ref("");
const data = ref<VisitWorkspaceData | null>(null);
const baseline = ref("");
const lastEditRequestId = ref(0);
const panelElement = ref<HTMLElement | null>(null);
const handleDialogKeydown = useDialogFocusTrap(panelElement);
const draft = ref({ scope: "", notes: "" });
const hasChanges = computed(() => baseline.value !== "" && JSON.stringify(draft.value) !== baseline.value);

const applyEditRequest = (): void => {
    if (!data.value || props.editRequestId <= lastEditRequestId.value) return;

    lastEditRequestId.value = props.editRequestId;
    isEditing.value = true;
};

watch(() => props.editRequestId, applyEditRequest);

const cancelEditing = (): void => {
    if (!data.value) return;

    draft.value = {
        scope: data.value.visit.scope,
        notes: data.value.visit.notes ?? "",
    };
    isEditing.value = false;
};

const setData = (value: VisitWorkspaceData): void => {
    data.value = value;
    draft.value = { scope: value.visit.scope, notes: value.visit.notes ?? "" };
    baseline.value = JSON.stringify(draft.value);
    isEditing.value = false;
};

const load = async (): Promise<void> => {
    loading.value = true;
    try {
        const response = await axios.get(route("admin.visits.workspace.show", props.visitId));
        setData(response.data.data as VisitWorkspaceData);
        applyEditRequest();
    } catch {
        errorMessage.value = "Visit details could not be loaded.";
    } finally {
        loading.value = false;
    }
};

const submit = async (): Promise<void> => {
    if (!hasChanges.value) return;
    saving.value = true;
    errorMessage.value = "";
    try {
        await axios.put(route("admin.visits.workspace.update", props.visitId), draft.value);
        await load();
        emit("saved");
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? Object.values(error.response?.data?.errors ?? {}).flat()[0] ?? "Visit changes could not be saved."
            : "Visit changes could not be saved.";
    } finally {
        saving.value = false;
    }
};

const archive = async (): Promise<void> => {
    if (!props.canArchive || archiving.value || !data.value) return;
    if (!window.confirm(`Archive this ${data.value.visit.type} for ${data.value.visit.jobNumber ?? "the job"}? It can be restored from Archive.`)) return;
    archiving.value = true;
    try {
        await axios.post(route("admin.archives.archive", { type: "visits", record: props.visitId }), { reason: "Archived from visit details." });
        emit("archived");
        emit("close");
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error) ? error.response?.data?.message ?? "Visit could not be archived." : "Visit could not be archived.";
    } finally {
        archiving.value = false;
    }
};

const formatDate = (value: string | null): string => value ? new Date(value).toLocaleString() : "Unscheduled";
const formatBytes = (value: number): string => value < 1024 ? `${value} B` : `${(value / 1024).toFixed(0)} KB`;

onMounted(() => {
    panelElement.value?.focus();
    void load();
});
</script>

<template>
    <div class="fieldops-workspace-backdrop" :class="{ 'fieldops-workspace-backdrop--nested': nested }" tabindex="-1" @click.self="emit('close')" @keydown.esc="emit('close')">
        <section ref="panelElement" tabindex="-1" class="fieldops-workspace-panel" role="dialog" aria-modal="true" :aria-label="data ? `${data.visit.type} visit details` : 'Visit details'" @keydown="handleDialogKeydown">
            <header class="relative flex items-start justify-between gap-4 border-b border-gray-200 px-4 py-4 sm:px-6">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ data?.visit.customerName ?? "Visit" }} · {{ data?.visit.jobNumber }}</p>
                    <h2 class="truncate text-lg font-semibold text-gray-900">{{ data?.visit.type }} · {{ data?.visit.jobTitle ?? "Loading visit" }}</h2>
                    <p class="text-sm text-gray-600">{{ formatDate(data?.visit.scheduledAt ?? null) }}<span v-if="data?.visit.durationMinutes"> · {{ data.visit.durationMinutes }} minutes</span></p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <button type="button" class="fieldops-secondary-action min-h-10 px-3" :aria-expanded="activityOpen" aria-label="Show visit activity" @click="activityOpen = !activityOpen">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                    </button>
                    <button type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Close visit details" title="Close details" @click="emit('close')">
                        <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
                <aside v-if="activityOpen && data" class="absolute right-4 top-14 z-10 max-h-[55vh] w-[min(24rem,calc(100vw-2rem))] space-y-2 overflow-y-auto rounded-xl border border-gray-200 bg-white p-3 shadow-xl" aria-label="Visit activity">
                    <article v-for="event in data.activity" :key="event.id" class="border-b border-gray-100 pb-2 last:border-0">
                        <p class="text-sm text-gray-900">{{ formatDate(event.createdAt) }} · {{ event.summary }}</p>
                    </article>
                    <p v-if="data.activity.length === 0" class="py-4 text-center text-sm text-gray-600">No activity has been recorded yet.</p>
                </aside>
            </header>

            <p v-if="errorMessage" role="alert" class="mx-4 mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 sm:mx-6">{{ errorMessage }}</p>
            <div v-if="loading" class="flex-1 px-6 py-12 text-center text-sm text-gray-600">Loading visit details…</div>
            <form v-else-if="data" class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
                <div class="fieldops-workspace-scroll space-y-4" @click="activityOpen = false">
                    <div class="rounded-lg border border-gray-200 p-3">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Site</p>
                        <p class="mt-1 font-medium text-gray-900">{{ data.visit.siteName || "No site recorded" }}</p>
                        <p class="mt-1 text-sm text-gray-600">{{ data.visit.siteAddress || "No address recorded" }}</p>
                    </div>
                    <label class="fieldops-workspace-field">Visit scope snapshot<textarea v-model="draft.scope" rows="5" maxlength="20000" required :readonly="!isEditing" /></label>
                    <label class="fieldops-workspace-field">Visit notes (included in client completion reports)<textarea v-model="draft.notes" rows="6" maxlength="20000" :readonly="!isEditing" /></label>
                    <VisitItemUsageEditor :visit-id="visitId" :items="data.items" :editable="data.visit.itemsEditable" />
                    <section class="space-y-2" aria-label="Visit attachments">
                        <h3 class="text-sm font-semibold text-gray-900">Attachments</h3>
                        <a v-for="attachment in data.attachments" :key="attachment.id" :href="attachment.url" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 text-sm text-blue-800 hover:bg-gray-50">
                            <span class="flex min-w-0 items-center gap-3">
                                <AttachmentFileTypeIcon :file-name="attachment.name" :mime-type="attachment.mimeType" />
                                <span class="truncate">{{ attachment.name }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-gray-500">{{ formatBytes(attachment.size) }}</span>
                        </a>
                        <p v-if="data.attachments.length === 0" class="text-sm text-gray-600">No attachments.</p>
                    </section>
                </div>
                <footer class="fieldops-workspace-footer">
                    <button v-if="isEditing" type="button" class="fieldops-delete-action" :disabled="!canArchive || archiving" @click="archive">{{ archiving ? "Archiving…" : "Delete" }}</button>
                    <div class="ml-auto flex items-center gap-2">
                        <button v-if="!isEditing" type="button" class="fieldops-secondary-action fieldops-edit-action min-h-10" @click="isEditing = true">Edit</button>
                        <button
                            v-if="isEditing"
                            type="button"
                            class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0"
                            aria-label="Cancel visit edits"
                            title="Cancel editing"
                            :disabled="saving"
                            @click="cancelEditing"
                        >
                            <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                        </button>
                        <button v-if="isEditing" type="submit" class="fieldops-submit-action min-h-10" :disabled="saving || !hasChanges">{{ saving ? "Saving…" : "Submit" }}</button>
                    </div>
                </footer>
            </form>
        </section>
    </div>
</template>
