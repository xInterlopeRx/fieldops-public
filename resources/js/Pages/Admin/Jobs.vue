<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { CalendarDaysIcon, MagnifyingGlassIcon, XMarkIcon } from "@heroicons/vue/24/outline";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
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

const props = defineProps<{
    jobs: PaginatedJobs;
    filters: { q: string; project_id: number | null };
    projectFilter: { id: number; name: string; number: string | null } | null;
}>();

const search = ref(props.filters.q);
const selectedJobId = ref<number | null>(null);
const selectedJobIsEditing = ref(false);
const selectedJobEditRequestId = ref(0);
const selectedJobEditTargetId = ref<number | null>(null);
const { badgeClass: statusClass, label } = useWorkOrderStatus();

const submitSearch = (): void => {
    router.get(
        route("admin.jobs"),
        {
            q: search.value.trim() || undefined,
            project_id: props.filters.project_id || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearFilters = (): void => {
    search.value = "";
    router.get(route("admin.jobs"), {}, { preserveState: true, preserveScroll: true });
};

const refreshDirectory = (): void => {
    router.reload({ only: ["jobs"] });
};

const openJob = (jobId: number): void => {
    if (selectedJobIsEditing.value && selectedJobId.value !== jobId) return;

    selectedJobEditTargetId.value = null;
    selectedJobId.value = jobId;
};

const openJobForEditing = (jobId: number): void => {
    if (selectedJobIsEditing.value && selectedJobId.value !== jobId) return;

    selectedJobId.value = jobId;
    selectedJobEditTargetId.value = jobId;
    selectedJobEditRequestId.value += 1;
};

const jobPress = usePressAndHold(openJob, openJobForEditing);

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
                v-if="selectedJobId !== null"
                :key="selectedJobId"
                :job-id="selectedJobId"
                :edit-request-id="selectedJobEditTargetId === selectedJobId ? selectedJobEditRequestId : 0"
                :can-archive="$page.props.auth.is_admin"
                :inline-on-desktop="true"
                @close="closeJob"
                @editing-change="selectedJobIsEditing = $event"
                @saved="refreshDirectory"
                @archived="refreshDirectory"
            />

            <section v-else class="fieldops-directory-empty-details hidden lg:flex" aria-label="Job details">
                <header class="fieldops-workspace-fixed-header">
                    <h2 class="fieldops-workspace-title">Job Details</h2>
                </header>
                <div class="fieldops-directory-empty-details__body">
                    <p class="text-sm text-gray-600">Select a job to load its details and edit form.</p>
                </div>
            </section>

            <div class="fieldops-admin-directory-list-column min-w-0 lg:col-start-2" :inert="selectedJobIsEditing">
            <section class="fieldops-panel bg-white p-2">
                <p v-if="projectFilter" class="mb-2 text-sm font-medium text-gray-700">
                    Project filter: {{ projectFilter.number ? `${projectFilter.number} · ` : "" }}{{ projectFilter.name }}
                </p>

                <form
                    role="search"
                    aria-label="Search jobs"
                    class="flex items-center gap-2"
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
                    />
                    <button
                        type="submit"
                        class="fieldops-submit-action fieldops-directory-search-button fieldops-directory-search-button--icon"
                        aria-label="Search jobs"
                        title="Search jobs"
                    >
                        <MagnifyingGlassIcon class="h-4 w-4" aria-hidden="true" />
                    </button>
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
            </section>

            <section
                class="fieldops-panel fieldops-admin-directory-results overflow-hidden bg-gray-50"
                aria-label="Job results"
            >
                <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 border-b border-gray-200 px-4 py-2">
                    <h2 class="font-semibold text-gray-900">
                        Jobs <span class="ml-1 text-sm font-normal text-gray-600">({{ jobs.total }})</span>
                    </h2>
                    <p v-if="jobs.total > 0" class="text-sm text-gray-600">
                        Showing {{ jobs.from }}–{{ jobs.to }}
                    </p>
                    <p v-if="selectedJobIsEditing" class="w-full text-xs text-gray-600">Cancel or submit editing before switching jobs or changing the search.</p>
                </div>

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
                    {{ filters.q || filters.project_id ? "No jobs match these filters." : "No jobs are available yet." }}
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
    </AdminLayout>
</template>
