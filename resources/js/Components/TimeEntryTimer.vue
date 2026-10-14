<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

type ActivityType =
    | "travel"
    | "labor"
    | "remote_support"
    | "documentation"
    | "research"
    | "meeting"
    | "administrative"
    | "project_work";

interface TimerVisit {
    id: number;
    visitType: "initial" | "revisit";
    scheduledAt: string;
}

interface ActiveTimer {
    id: number;
    customerId: number;
    customerName: string | null;
    projectId: number | null;
    projectName: string | null;
    projectNumber: string | null;
    jobId: number | null;
    jobNumber: string | null;
    jobTitle: string | null;
    visitId: number | null;
    visitType: "initial" | "revisit" | null;
    visitScheduledAt: string | null;
    activityType: ActivityType;
    startedAt: string;
    endedAt: string | null;
    durationMinutes: number | null;
    billable: boolean;
    notes: string | null;
    approvalStatus: "draft" | "approved" | "invoiced" | "paid";
}

const props = withDefaults(defineProps<{
    customerId: number;
    customerName: string;
    projectId: number | null;
    projectName: string | null;
    projectNumber: string | null;
    jobId: number;
    jobNumber: string;
    jobTitle: string;
    visits: TimerVisit[];
    initialVisitId?: number | null;
}>(), {
    initialVisitId: null,
});
const emit = defineEmits<{
    visitSelected: [visitId: number | null];
}>();

const activityTypes: Array<{ value: ActivityType; label: string }> = [
    { value: "travel", label: "Travel" },
    { value: "labor", label: "Labor" },
    { value: "remote_support", label: "Remote Support" },
    { value: "documentation", label: "Documentation" },
    { value: "research", label: "Research" },
    { value: "meeting", label: "Meeting" },
    { value: "administrative", label: "Administrative" },
    { value: "project_work", label: "Project Work" },
];

const initialVisit = props.visits.find((visit) => visit.id === props.initialVisitId);
const selectedVisitId = ref<number | null>(
    initialVisit?.id ?? (props.visits.length === 1 ? props.visits[0]?.id ?? null : null),
);
const activityType = ref<ActivityType>("labor");
const activeTimer = ref<ActiveTimer | null>(null);
const isLoading = ref(true);
const isSaving = ref(false);
const errorMessage = ref("");
const successMessage = ref("");
const clockNow = ref(Date.now());

let clockInterval: number | null = null;
let statusPoll: number | null = null;

const runningDuration = computed(() => {
    if (!activeTimer.value) return "00:00:00";

    const startedAt = Date.parse(activeTimer.value.startedAt);
    const elapsedSeconds = Math.max(0, Math.floor((clockNow.value - startedAt) / 1000));
    const hours = Math.floor(elapsedSeconds / 3600);
    const minutes = Math.floor((elapsedSeconds % 3600) / 60);
    const seconds = elapsedSeconds % 60;

    return [hours, minutes, seconds]
        .map((value) => String(value).padStart(2, "0"))
        .join(":");
});

const statusLabel = computed(() => {
    if (isLoading.value) return "Checking timer";

    return activeTimer.value ? "Timer running" : "No active timer";
});

const activeActivityLabel = computed(() =>
    activityTypes.find((item) => item.value === activeTimer.value?.activityType)?.label
        ?? "Unknown activity",
);

const activeJobLabel = computed(() => {
    const timer = activeTimer.value;
    if (!timer) return "";

    return [timer.jobNumber, timer.jobTitle].filter(Boolean).join(" · ")
        || (timer.jobId === null ? "Project-only time" : `Job #${timer.jobId}`);
});

const activeProjectLabel = computed(() => {
    const timer = activeTimer.value;
    if (!timer) return "";

    return [timer.projectNumber, timer.projectName].filter(Boolean).join(" · ")
        || (timer.projectId === null ? "No Project" : `Project #${timer.projectId}`);
});

const activeVisitLabel = computed(() => {
    const timer = activeTimer.value;
    if (!timer?.visitId) return "No Visit";

    const visitType = timer.visitType === "initial" ? "Initial Visit" : "Revisit";
    const scheduled = timer.visitScheduledAt
        ? new Date(timer.visitScheduledAt).toLocaleString()
        : null;

    return `${visitType}${scheduled ? ` · ${scheduled}` : ""}`;
});

const visitLabel = (visit: TimerVisit): string => {
    const visitType = visit.visitType === "initial" ? "Initial Visit" : "Revisit";

    return `${visitType} · ${new Date(visit.scheduledAt).toLocaleString()}`;
};

const selectedVisitLabel = computed(() => {
    const visit = props.visits.find((item) => item.id === selectedVisitId.value);

    return visit ? visitLabel(visit) : "Job-level time";
});

const refreshActiveTimer = async (
    options: { force?: boolean; clearError?: boolean } = {},
): Promise<void> => {
    if (isSaving.value && !options.force) return;

    try {
        const response = await axios.get(route("time-entries.active"));
        activeTimer.value = response.data.data as ActiveTimer | null;
        if (options.clearError) errorMessage.value = "";
    } catch {
        errorMessage.value = "Timer status could not be loaded. Try again.";
    } finally {
        isLoading.value = false;
    }
};

const startTimer = async (): Promise<void> => {
    if (activeTimer.value || isLoading.value || isSaving.value) return;

    isSaving.value = true;
    errorMessage.value = "";
    successMessage.value = "";

    try {
        const response = await axios.post(route("time-entries.timer.start"), {
            customer_id: props.customerId,
            project_id: props.projectId ?? undefined,
            job_id: props.jobId,
            visit_id: selectedVisitId.value ?? undefined,
            activity_type: activityType.value,
        });
        activeTimer.value = response.data.data as ActiveTimer;
        clockNow.value = Date.now();
        errorMessage.value = "";
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "Timer could not be started."
            : "Timer could not be started.";
        await refreshActiveTimer({ force: true });
    } finally {
        isSaving.value = false;
    }
};

const stopTimer = async (): Promise<void> => {
    if (!activeTimer.value || isSaving.value) return;

    const timeEntryId = activeTimer.value.id;
    isSaving.value = true;
    errorMessage.value = "";
    successMessage.value = "";

    try {
        const response = await axios.post(route("time-entries.timer.stop", timeEntryId));
        const stoppedTimer = response.data.data as ActiveTimer;
        activeTimer.value = null;
        successMessage.value = `Time saved · ${stoppedTimer.durationMinutes ?? 0} min`;
        errorMessage.value = "";
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "Timer could not be stopped."
            : "Timer could not be stopped.";
        await refreshActiveTimer({ force: true });
    } finally {
        isSaving.value = false;
    }
};

const retryTimerStatus = (): void => {
    void refreshActiveTimer({ force: true, clearError: true });
};

const handleTimerRefreshEvent = (): void => {
    void refreshActiveTimer({ force: true, clearError: true });
};

onMounted(() => {
    clockInterval = window.setInterval(() => {
        clockNow.value = Date.now();
    }, 1000);
    statusPoll = window.setInterval(() => void refreshActiveTimer(), 15000);
    window.addEventListener("fieldops:time-entry-timer-refresh", handleTimerRefreshEvent);
    void refreshActiveTimer({ clearError: true });
});

onBeforeUnmount(() => {
    if (clockInterval !== null) window.clearInterval(clockInterval);
    if (statusPoll !== null) window.clearInterval(statusPoll);
    window.removeEventListener("fieldops:time-entry-timer-refresh", handleTimerRefreshEvent);
});
</script>

<template>
    <section
        class="space-y-3 rounded-xl border border-gray-200 bg-white/75 p-3 shadow-sm sm:p-4"
        aria-label="Time tracking"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2">
                <span
                    class="h-2.5 w-2.5 shrink-0 rounded-full"
                    :class="activeTimer ? 'bg-emerald-600' : 'bg-gray-400'"
                    aria-hidden="true"
                ></span>
                <div class="min-w-0">
                    <h3 class="text-sm font-bold text-gray-900">{{ statusLabel }}</h3>
                    <p v-if="activeTimer" class="truncate text-xs text-gray-600">
                        {{ activeActivityLabel }}
                    </p>
                </div>
            </div>
            <div class="shrink-0 text-right">
                <p class="font-mono text-xl font-semibold tabular-nums text-gray-900" role="timer" :aria-label="`Elapsed time ${runningDuration}`" aria-live="off">
                    {{ runningDuration }}
                </p>
                <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500">Elapsed</p>
            </div>
        </div>

        <dl class="grid min-w-0 grid-cols-1 gap-x-3 gap-y-2 rounded-lg bg-gray-50/80 p-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
            <div class="min-w-0">
                <dt class="font-semibold text-gray-500">Customer</dt>
                <dd class="truncate font-medium text-gray-900">
                    {{ activeTimer?.customerName || props.customerName || `Customer #${activeTimer?.customerId ?? props.customerId}` }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="font-semibold text-gray-500">Project</dt>
                <dd class="truncate font-medium text-gray-900">
                    {{ activeTimer ? activeProjectLabel : (props.projectName ? [props.projectNumber, props.projectName].filter(Boolean).join(" · ") : "No Project") }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="font-semibold text-gray-500">Job</dt>
                <dd class="truncate font-medium text-gray-900">
                    {{ activeTimer ? activeJobLabel : `${props.jobNumber} · ${props.jobTitle}` }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="font-semibold text-gray-500">Visit</dt>
                <dd class="truncate font-medium text-gray-900">
                    {{ activeTimer ? activeVisitLabel : selectedVisitLabel }}
                </dd>
            </div>
        </dl>

        <div v-if="!activeTimer" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
            <label class="grid min-w-0 gap-1 text-xs font-semibold text-gray-600">
                Activity type
                <select v-model="activityType" class="fieldops-client-input min-h-10 w-full text-sm" :disabled="isLoading || isSaving">
                    <option v-for="activity in activityTypes" :key="activity.value" :value="activity.value">
                        {{ activity.label }}
                    </option>
                </select>
            </label>
            <label v-if="visits.length > 0" class="grid min-w-0 gap-1 text-xs font-semibold text-gray-600">
                Visit
                <select v-model="selectedVisitId" class="fieldops-client-input min-h-10 w-full text-sm" :disabled="isLoading || isSaving" @change="emit('visitSelected', selectedVisitId)">
                    <option :value="null">Job-level time</option>
                    <option v-for="visit in visits" :key="visit.id" :value="visit.id">
                        {{ visitLabel(visit) }}
                    </option>
                </select>
            </label>
            <button
                type="button"
                class="fieldops-submit-action min-h-10 sm:min-w-32"
                :disabled="isLoading || isSaving"
                @click="startTimer"
            >
                {{ isSaving ? "Starting…" : "Start Timer" }}
            </button>
        </div>

        <div v-else class="flex flex-wrap items-center justify-between gap-2">
            <p class="min-w-0 flex-1 truncate text-xs text-gray-600">
                Started {{ new Date(activeTimer.startedAt).toLocaleTimeString() }}
                · {{ activeTimer.approvalStatus }}
            </p>
            <button
                type="button"
                class="fieldops-delete-action min-h-10 sm:min-w-32"
                :disabled="isSaving"
                @click="stopTimer"
            >
                {{ isSaving ? "Saving…" : "Stop Timer" }}
            </button>
        </div>

        <p v-if="errorMessage" role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">
            {{ errorMessage }}
        </p>
        <p v-if="successMessage" role="status" class="text-xs font-medium text-emerald-800">
            {{ successMessage }}
        </p>
        <button v-if="errorMessage && !activeTimer" type="button" class="text-xs font-semibold text-blue-800 underline" @click="retryTimerStatus">
            Retry timer status
        </button>
    </section>
</template>
