<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { CalendarDaysIcon, MagnifyingGlassIcon, PlusIcon, XMarkIcon } from "@heroicons/vue/24/outline";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import Modal from "@/Components/Modal.vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import JobCreateForm from "@/Components/Admin/JobCreateForm.vue";
import JobWorkspace from "@/Components/Admin/JobWorkspace.vue";
import PaginationChevronNav from "@/Components/Admin/PaginationChevronNav.vue";
import { usePressAndHold } from "@/Composables/usePressAndHold";
import { useWorkOrderStatus } from "@/Composables/useWorkOrderStatus";

interface JobRow {
    id: number;
    number: string | null;
    title: string;
    status: string;
    siteName: string | null;
    customerName: string | null;
    projectName: string | null;
    projectNumber: string | null;
    visitsCount: number;
}

interface PaginatedJobs {
    data: JobRow[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface FilterOption {
    value: string;
    label: string;
}

interface JobCreateFormHandle {
    hasUnsavedChanges: () => boolean;
    submit: () => Promise<boolean>;
}

interface PendingJobNavigation {
    jobId: number;
    edit: boolean;
}

const props = defineProps<{
    jobs: PaginatedJobs;
    filters: {
        q: string;
        project_id: number | null;
        company: string;
        projectName: string;
        statuses: string[];
        excludedStatuses: string[];
        sort: string;
    };
    projectFilter: { id: number; name: string; number: string | null } | null;
    statusOptions: FilterOption[];
}>();

const search = ref(props.filters.q);
const companyFilter = ref(props.filters.company);
const projectNameFilter = ref(props.filters.projectName);
const selectedStatuses = ref([...props.filters.statuses]);
const sortBy = ref(props.filters.sort);
const statusFilterMenu = ref<HTMLDetailsElement | null>(null);
const selectedJobId = ref<number | null>(null);
const selectedJobIsEditing = ref(false);
const selectedJobEditRequestId = ref(0);
const selectedJobEditTargetId = ref<number | null>(null);
const creatingJob = ref(false);
const jobCreateFormRef = ref<JobCreateFormHandle | null>(null);
const pendingJobNavigation = ref<PendingJobNavigation | null>(null);
const savingBeforeJobNavigation = ref(false);
const createJobReturnId = ref<number | null>(null);
const createJobCustomerId = ref<number | null>(null);
const createJobSiteId = ref<number | null>(null);
const createJobScheduledAt = ref<string | null>(null);
const createJobStatus = ref<string | null>(null);
const { badgeClass: statusClass, label } = useWorkOrderStatus();

const submitDirectory = (): void => {
    const excludedStatuses = props.statusOptions
        .map((option) => option.value)
        .filter((status) => ! selectedStatuses.value.includes(status));

    router.get(
        route("admin.jobs"),
        {
            q: search.value.trim() || undefined,
            project_id: props.filters.project_id || undefined,
            company: companyFilter.value.trim() || undefined,
            project_name: projectNameFilter.value.trim() || undefined,
            ...(excludedStatuses.length > 0 ? { exclude_statuses: excludedStatuses } : {}),
            sort: sortBy.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const submitSearch = (): void => submitDirectory();

const clearFilters = (): void => {
    search.value = "";
    companyFilter.value = "";
    projectNameFilter.value = "";
    selectedStatuses.value = props.statusOptions.map((option) => option.value);
    sortBy.value = "updated_desc";
    router.get(route("admin.jobs"), {}, { preserveState: true, preserveScroll: true });
};

const toggleAllStatuses = (event: Event): void => {
    selectedStatuses.value = (event.target as HTMLInputElement).checked
        ? props.statusOptions.map((option) => option.value)
        : [];
    submitDirectory();
};

const closeStatusFilterMenu = (): void => {
    if (statusFilterMenu.value) statusFilterMenu.value.open = false;
};

const handleDocumentPointerDown = (event: PointerEvent): void => {
    const target = event.target;
    if (!(target instanceof Node) || statusFilterMenu.value?.contains(target)) return;

    closeStatusFilterMenu();
};

const handleDocumentKeydown = (event: KeyboardEvent): void => {
    if (event.key === "Escape") closeStatusFilterMenu();
};

const selectionSummary = (options: FilterOption[], selected: string[]): string => {
    if (selected.length === options.length) return "All";
    if (selected.length === 0) return "None";

    return selected.length === 1
        ? options.find((option) => option.value === selected[0])?.label ?? "1 selected"
        : `${selected.length} selected`;
};

const hasActiveFilters = computed(() => props.filters.q !== ""
    || props.filters.project_id !== null
    || props.filters.company !== ""
    || props.filters.projectName !== ""
    || props.filters.excludedStatuses.length > 0);

watch(() => props.filters, (filters) => {
    search.value = filters.q;
    companyFilter.value = filters.company;
    projectNameFilter.value = filters.projectName;
    selectedStatuses.value = [...filters.statuses];
    sortBy.value = filters.sort;
});

onMounted(() => {
    document.addEventListener("pointerdown", handleDocumentPointerDown);
    document.addEventListener("keydown", handleDocumentKeydown);

    const query = new URLSearchParams(window.location.search);
    if (query.get("create_job") === "1") {
        const customerId = Number(query.get("customer_id"));
        const siteId = Number(query.get("site_id"));
        openJobCreate(
            Number.isSafeInteger(customerId) && customerId > 0 ? customerId : null,
            Number.isSafeInteger(siteId) && siteId > 0 ? siteId : null,
            query.get("scheduled_at"),
            query.get("status"),
        );
        ["create_job", "customer_id", "site_id", "scheduled_at", "status"].forEach((key) => query.delete(key));
        const queryString = query.toString();
        router.visit(`${window.location.pathname}${queryString ? `?${queryString}` : ""}${window.location.hash}`, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }
});

onBeforeUnmount(() => {
    document.removeEventListener("pointerdown", handleDocumentPointerDown);
    document.removeEventListener("keydown", handleDocumentKeydown);
});

const refreshDirectory = (): void => {
    router.reload({ only: ["jobs"] });
};

const continueJobNavigation = (navigation: PendingJobNavigation): void => {
    pendingJobNavigation.value = null;
    savingBeforeJobNavigation.value = false;

    if (creatingJob.value) cancelJobCreate();

    selectedJobIsEditing.value = false;
    selectedJobId.value = navigation.jobId;
    selectedJobEditTargetId.value = navigation.edit ? navigation.jobId : null;
    selectedJobEditRequestId.value = navigation.edit
        ? selectedJobEditRequestId.value + 1
        : 0;
};

const requestJobNavigation = (jobId: number, edit: boolean): void => {
    if (selectedJobIsEditing.value && selectedJobId.value !== jobId) return;

    const navigation = { jobId, edit };

    if (creatingJob.value) {
        if (jobCreateFormRef.value?.hasUnsavedChanges()) {
            pendingJobNavigation.value = navigation;

            return;
        }

        continueJobNavigation(navigation);

        return;
    }

    selectedJobId.value = jobId;
    selectedJobEditTargetId.value = edit ? jobId : null;
    selectedJobEditRequestId.value = edit
        ? selectedJobEditRequestId.value + 1
        : 0;
};

const openJob = (jobId: number): void => requestJobNavigation(jobId, false);

const openJobForEditing = (jobId: number): void => requestJobNavigation(jobId, true);

const jobPress = usePressAndHold(openJob, openJobForEditing);

const openJobCreate = (
    customerId: number | null = null,
    siteId: number | null = null,
    scheduledAt: string | null = null,
    status: string | null = null,
): void => {
    createJobReturnId.value = selectedJobId.value;
    selectedJobId.value = null;
    selectedJobIsEditing.value = false;
    creatingJob.value = true;
    createJobCustomerId.value = customerId;
    createJobSiteId.value = siteId;
    createJobScheduledAt.value = scheduledAt;
    createJobStatus.value = status;
};

const cancelJobCreate = (): void => {
    creatingJob.value = false;
    pendingJobNavigation.value = null;
    savingBeforeJobNavigation.value = false;
    createJobCustomerId.value = null;
    createJobSiteId.value = null;
    createJobScheduledAt.value = null;
    createJobStatus.value = null;
    selectedJobId.value = createJobReturnId.value;
    createJobReturnId.value = null;
};

const handleJobCreated = (jobId: number): void => {
    const navigation = pendingJobNavigation.value;
    creatingJob.value = false;
    createJobReturnId.value = null;
    createJobCustomerId.value = null;
    createJobSiteId.value = null;
    createJobScheduledAt.value = null;
    createJobStatus.value = null;
    pendingJobNavigation.value = null;
    savingBeforeJobNavigation.value = false;
    selectedJobIsEditing.value = false;
    selectedJobId.value = navigation?.jobId ?? jobId;
    selectedJobEditTargetId.value = navigation?.edit ? navigation.jobId : null;
    selectedJobEditRequestId.value = navigation?.edit
        ? selectedJobEditRequestId.value + 1
        : 0;
    router.reload({ only: ["jobs"] });
};

const keepJobEditing = (): void => {
    pendingJobNavigation.value = null;
};

const discardJobAndContinue = (): void => {
    const navigation = pendingJobNavigation.value;
    if (!navigation) return;

    continueJobNavigation(navigation);
};

const saveJobAndContinue = async (): Promise<void> => {
    if (!pendingJobNavigation.value || savingBeforeJobNavigation.value) return;

    savingBeforeJobNavigation.value = true;
    const saved = await jobCreateFormRef.value?.submit();

    if (!saved && pendingJobNavigation.value !== null) {
        pendingJobNavigation.value = null;
        savingBeforeJobNavigation.value = false;
    }
};

const closeJob = (): void => {
    selectedJobId.value = null;
    selectedJobIsEditing.value = false;
    selectedJobEditRequestId.value = 0;
    selectedJobEditTargetId.value = null;
};
</script>

<template>
    <Head title="Jobs" />

    <AdminLayout title="Jobs">
        <div class="fieldops-admin-directory-page mx-auto max-w-7xl space-y-4 px-4 py-5 sm:px-6 lg:px-8">
            <div class="fieldops-admin-directory-grid grid items-stretch gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(19rem,0.85fr)]">
            <JobWorkspace
                v-if="selectedJobId !== null && !creatingJob"
                :key="selectedJobId"
                :job-id="selectedJobId"
                :edit-request-id="selectedJobEditTargetId === selectedJobId ? selectedJobEditRequestId : 0"
                :can-archive="$page.props.auth.is_admin"
                :inline-on-desktop="true"
                :show-add-action="true"
                @close="closeJob"
                @add-job="openJobCreate"
                @editing-change="selectedJobIsEditing = $event"
                @saved="refreshDirectory"
                @archived="refreshDirectory"
            />

            <section v-else-if="creatingJob" class="fieldops-directory-empty-details flex" aria-label="Add Job">
                <header class="fieldops-workspace-fixed-header">
                    <h2 class="fieldops-workspace-title">Add Job</h2>
                    <button type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Cancel Job creation" title="Cancel" @click="cancelJobCreate">
                        <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                    </button>
                </header>
                <div class="fieldops-directory-empty-details__body">
                    <JobCreateForm
                        ref="jobCreateFormRef"
                        :customer-id="createJobCustomerId"
                        :site-id="createJobSiteId"
                        :scheduled-at="createJobScheduledAt"
                        :initial-status="createJobStatus"
                        @cancel="cancelJobCreate"
                        @created="handleJobCreated"
                    />
                </div>
            </section>

            <section v-else class="fieldops-directory-empty-details hidden lg:flex" aria-label="Job details">
                <header class="fieldops-workspace-fixed-header">
                    <h2 class="fieldops-workspace-title">Job Details</h2>
                    <button type="button" class="fieldops-secondary-action fieldops-add-action inline-flex min-h-10 items-center gap-2 px-3 py-1" @click="openJobCreate()">
                        <PlusIcon class="h-4 w-4" aria-hidden="true" />
                        Add Job
                    </button>
                </header>
                <div class="fieldops-directory-empty-details__body">
                    <p class="text-sm text-gray-600">Select a job to load its details and edit form.</p>
                </div>
            </section>

            <div class="fieldops-admin-directory-list-column min-w-0 lg:col-start-2" :inert="selectedJobIsEditing">
            <header class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 px-1">
                <h2 class="font-semibold text-gray-900">
                    <span class="text-base">Job directory</span>
                    <span class="ml-1 text-sm font-normal text-gray-600">({{ jobs.total }})</span>
                </h2>
                <p v-if="jobs.total > 0" class="text-sm text-gray-600">
                    Showing {{ jobs.from }}–{{ jobs.to }}
                </p>
                <p v-if="selectedJobIsEditing" class="w-full text-xs text-gray-600">
                    Cancel or submit editing before switching jobs or changing the search.
                </p>
            </header>
            <p v-if="projectFilter" class="px-1 text-sm font-medium text-gray-700">
                    Project filter: {{ projectFilter.number ? `${projectFilter.number} · ` : "" }}{{ projectFilter.name }}
            </p>

            <section class="fieldops-panel grid grid-cols-1 gap-2 bg-white p-3 sm:grid-cols-2" aria-label="Filter and sort jobs">
                <fieldset class="min-w-0 space-y-1">
                    <legend class="text-sm font-medium text-gray-700">Status</legend>
                    <details ref="statusFilterMenu" class="relative">
                        <summary class="fieldops-client-input flex min-h-10 cursor-pointer list-none items-center justify-between gap-2 px-3 py-2 text-sm">
                            <span class="truncate">{{ selectionSummary(statusOptions, selectedStatuses) }}</span>
                            <span aria-hidden="true">⌄</span>
                        </summary>
                        <div class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-300 bg-white p-2 shadow-lg dark:border-gray-600 dark:bg-gray-800">
                            <label class="flex min-h-10 items-center gap-2 px-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                                <input
                                    type="checkbox"
                                    :checked="selectedStatuses.length === statusOptions.length"
                                    @change="toggleAllStatuses"
                                />
                                All statuses
                            </label>
                            <label
                                v-for="status in statusOptions"
                                :key="status.value"
                                class="flex min-h-10 items-center gap-2 px-2 text-sm text-gray-700 dark:text-gray-200"
                            >
                                <input
                                    v-model="selectedStatuses"
                                    type="checkbox"
                                    :value="status.value"
                                    @change="submitDirectory"
                                />
                                {{ status.label }}
                            </label>
                        </div>
                    </details>
                </fieldset>
                <label class="fieldops-workspace-field min-w-0">
                    <span>Company name</span>
                    <input
                        v-model="companyFilter"
                        type="search"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="Filter by company"
                        class="fieldops-client-input"
                        @focus="closeStatusFilterMenu"
                        @change="submitDirectory"
                        @keydown.enter.prevent="submitDirectory"
                    />
                </label>
                <label class="fieldops-workspace-field min-w-0">
                    <span>Project name</span>
                    <input
                        v-model="projectNameFilter"
                        type="search"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="Filter by project name"
                        class="fieldops-client-input"
                        @focus="closeStatusFilterMenu"
                        @change="submitDirectory"
                        @keydown.enter.prevent="submitDirectory"
                    />
                </label>
                <label class="fieldops-workspace-field min-w-0">
                    <span>Sort</span>
                    <select v-model="sortBy" aria-label="Sort jobs" class="fieldops-client-input" @focus="closeStatusFilterMenu" @change="submitDirectory">
                        <optgroup label="Job">
                            <option value="title_asc">Job title A–Z</option>
                            <option value="title_desc">Job title Z–A</option>
                            <option value="job_number_asc">Job number A–Z</option>
                            <option value="job_number_desc">Job number Z–A</option>
                        </optgroup>
                        <optgroup label="Other">
                            <option value="company_asc">Company name A–Z</option>
                            <option value="company_desc">Company name Z–A</option>
                            <option value="project_asc">Project name A–Z</option>
                            <option value="project_desc">Project name Z–A</option>
                            <option value="status">Status</option>
                            <option value="updated_desc">Recently updated</option>
                        </optgroup>
                    </select>
                </label>
            </section>

            <form
                role="search"
                aria-label="Search jobs"
                class="flex min-w-0 items-center gap-2"
                @submit.prevent="submitSearch"
            >
                <label for="admin-job-search" class="sr-only">
                    Search jobs, projects, sites, or customers
                </label>
                <ClearableSearchInput
                    id="admin-job-search"
                    v-model="search"
                    maxlength="100"
                    autocomplete="off"
                    placeholder="Search job number, title, project, site, or customer"
                    class="fieldops-client-input fieldops-directory-search-input min-w-0 flex-1"
                    clear-label="Clear job filters"
                    @clear="clearFilters"
                >
                    <template #action="{ closeSuggestions }">
                        <button
                            type="submit"
                            class="fieldops-clearable-search__action"
                            aria-label="Search jobs"
                            title="Search jobs"
                            @click="closeSuggestions"
                        >
                            <MagnifyingGlassIcon class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </template>
                </ClearableSearchInput>
                <button
                    v-if="filters.project_id && !filters.q"
                    type="button"
                    class="fieldops-secondary-action fieldops-directory-search-button fieldops-directory-search-button--icon"
                    aria-label="Clear job filters"
                    title="Clear filters"
                    @click="clearFilters"
                >
                    <XMarkIcon class="h-4 w-4" aria-hidden="true" />
                </button>
            </form>

            <section
                class="fieldops-panel fieldops-admin-directory-results overflow-hidden bg-gray-50"
                aria-label="Job results"
            >
                <div v-if="jobs.data.length" class="fieldops-admin-directory-cards space-y-1.5">
                    <article
                        v-for="job in jobs.data"
                        :key="job.id"
                        class="grid grid-cols-[minmax(0,1fr)_auto] items-stretch gap-2 rounded-lg border border-gray-200 bg-white p-2 shadow-sm transition-shadow hover:shadow-md"
                        :class="{ 'border-blue-300 bg-blue-50 shadow-md': selectedJobId === job.id }"
                    >
                        <button
                            type="button"
                            class="grid min-h-[4.25rem] min-w-0 content-center gap-1 rounded-md border-l-4 border-transparent px-3 py-2 text-right transition-colors hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            :class="{ 'border-l-blue-700 bg-blue-50': selectedJobId === job.id }"
                            :disabled="selectedJobIsEditing && selectedJobId !== job.id"
                            :aria-pressed="selectedJobId === job.id"
                            :aria-label="`Open job ${job.number ?? job.title} details. Press and hold to edit.`"
                            title="Click to preview; press and hold to edit"
                            v-on="jobPress(job.id)"
                        >
                            <span class="flex min-w-0 items-center justify-end gap-2">
                                <span class="min-w-0 truncate font-semibold text-gray-900">{{ job.title }}</span>
                                <span v-if="job.number" class="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                    {{ job.number }}
                                </span>
                                <span class="fieldops-status-badge shrink-0" :class="statusClass(job.status)">
                                    {{ label(job.status) }}
                                </span>
                            </span>
                            <span class="truncate text-xs text-gray-600">
                                {{ job.customerName || "Customer not available" }} · {{ job.siteName || "Site not available" }}<span v-if="job.projectName"> · {{ job.projectNumber ? `${job.projectNumber} · ` : "" }}{{ job.projectName }}</span> · {{ job.visitsCount }} {{ job.visitsCount === 1 ? "visit" : "visits" }}
                            </span>
                        </button>
                        <Link
                            :href="route('calendar', { open_job: job.id })"
                            class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center self-center p-0"
                            :aria-label="`Open ${job.number ?? job.title} in schedule`"
                            title="Open in schedule"
                        >
                            <CalendarDaysIcon class="h-5 w-5" aria-hidden="true" />
                        </Link>
                    </article>
                </div>

                <p v-else class="grid flex-1 place-items-center px-5 py-8 text-center text-sm text-gray-600 sm:px-6">
                    {{ hasActiveFilters ? "No jobs match these filters." : "No jobs are available yet." }}
                </p>

                <PaginationChevronNav
                    v-if="jobs.last_page > 1"
                    label="Job pages"
                    :current-page="jobs.current_page"
                    :last-page="jobs.last_page"
                    :previous-url="jobs.prev_page_url"
                    :next-url="jobs.next_page_url"
                    preserve-scroll
                />
            </section>
            </div>
            </div>
        </div>

        <Modal
            :show="pendingJobNavigation !== null"
            max-width="md"
            :closeable="!savingBeforeJobNavigation"
            @close="keepJobEditing"
        >
            <div class="space-y-4 p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Unsaved Job details</h2>
                <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                    Save the new Job before switching, or discard its details and switch.
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" class="fieldops-secondary-action min-h-10" :disabled="savingBeforeJobNavigation" @click="keepJobEditing">
                        Keep editing
                    </button>
                    <button type="button" class="fieldops-secondary-action min-h-10" :disabled="savingBeforeJobNavigation" @click="discardJobAndContinue">
                        Discard Job
                    </button>
                    <button type="button" class="fieldops-submit-action min-h-10" :disabled="savingBeforeJobNavigation" @click="saveJobAndContinue">
                        {{ savingBeforeJobNavigation ? "Saving…" : "Save Job and continue" }}
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
