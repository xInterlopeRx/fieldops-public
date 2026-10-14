<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { ref } from "vue";
import { MagnifyingGlassIcon, WrenchScrewdriverIcon } from "@heroicons/vue/24/outline";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import ProjectWorkspace from "@/Components/Admin/ProjectWorkspace.vue";
import PaginationChevronNav from "@/Components/Admin/PaginationChevronNav.vue";
import { usePressAndHold } from "@/Composables/usePressAndHold";

interface ProjectRow {
    id: number;
    number: string | null;
    name: string;
    status: string;
    customerName: string | null;
    sitesCount: number;
    jobsCount: number;
}

interface PaginatedProjects {
    data: ProjectRow[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{
    projects: PaginatedProjects;
    filters: { q: string };
}>();

const search = ref(props.filters.q);
const selectedProjectId = ref<number | null>(null);
const selectedProjectIsEditing = ref(false);
const selectedProjectEditRequestId = ref(0);
const selectedProjectEditTargetId = ref<number | null>(null);

const submitSearch = (): void => {
    router.get(
        route("admin.projects"),
        { q: search.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearSearch = (): void => {
    search.value = "";
    submitSearch();
};

const statusClass = (status: string): string => {
    if (status === "active") return "fieldops-status-badge--success";
    if (["paused", "on_hold"].includes(status)) return "fieldops-status-badge--warning";

    return "fieldops-status-badge--archive";
};

const label = (value: string): string =>
    value.replaceAll("_", " ").replace(/\b\w/g, (character) => character.toUpperCase());

const refreshDirectory = (): void => {
    router.reload({ only: ["projects"] });
};

const openProject = (projectId: number): void => {
    if (selectedProjectIsEditing.value && selectedProjectId.value !== projectId) return;

    selectedProjectEditTargetId.value = null;
    selectedProjectId.value = projectId;
};

const openProjectForEditing = (projectId: number): void => {
    if (selectedProjectIsEditing.value && selectedProjectId.value !== projectId) return;

    selectedProjectId.value = projectId;
    selectedProjectEditTargetId.value = projectId;
    selectedProjectEditRequestId.value += 1;
};

const projectPress = usePressAndHold(openProject, openProjectForEditing);

const closeProject = (): void => {
    selectedProjectId.value = null;
    selectedProjectIsEditing.value = false;
    selectedProjectEditRequestId.value = 0;
    selectedProjectEditTargetId.value = null;
};
</script>

<template>
    <Head title="Projects" />

    <AdminLayout title="Projects">
        <div class="fieldops-admin-directory-page mx-auto max-w-7xl space-y-4 px-4 py-5 sm:px-6 lg:px-8">
            <div class="fieldops-admin-directory-grid grid items-stretch gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(19rem,0.85fr)]">
            <ProjectWorkspace
                v-if="selectedProjectId !== null"
                :key="selectedProjectId"
                :project-id="selectedProjectId"
                :edit-request-id="selectedProjectEditTargetId === selectedProjectId ? selectedProjectEditRequestId : 0"
                :can-archive="$page.props.auth.is_admin"
                :inline-on-desktop="true"
                @close="closeProject"
                @editing-change="selectedProjectIsEditing = $event"
                @saved="refreshDirectory"
                @archived="refreshDirectory"
            />

            <section v-else class="fieldops-directory-empty-details hidden lg:flex" aria-label="Project details">
                <header class="fieldops-workspace-fixed-header">
                    <h2 class="fieldops-workspace-title">Project Details</h2>
                </header>
                <div class="fieldops-directory-empty-details__body">
                    <p class="text-sm text-gray-600">Select a project to load its details and edit form.</p>
                </div>
            </section>

            <div class="fieldops-admin-directory-list-column min-w-0 lg:col-start-2" :inert="selectedProjectIsEditing">
            <section class="fieldops-panel bg-white p-2">
                <form
                    role="search"
                    aria-label="Search projects"
                    class="flex items-center gap-2"
                    @submit.prevent="submitSearch"
                >
                    <label for="admin-project-search" class="sr-only">
                        Search projects or customers
                    </label>
                    <ClearableSearchInput
                        id="admin-project-search"
                        v-model="search"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="Search project number, name, or customer"
                        class="fieldops-client-input fieldops-directory-search-input min-w-0 flex-1"
                        clear-label="Clear project search"
                        @clear="clearSearch"
                    />
                    <button
                        type="submit"
                        class="fieldops-submit-action fieldops-directory-search-button fieldops-directory-search-button--icon"
                        aria-label="Search projects"
                        title="Search projects"
                    >
                        <MagnifyingGlassIcon class="h-4 w-4" aria-hidden="true" />
                    </button>
                </form>
            </section>

            <section
                class="fieldops-panel fieldops-admin-directory-results overflow-hidden bg-gray-50"
                aria-label="Project results"
            >
                <div class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 border-b border-gray-200 px-4 py-2">
                    <h2 class="font-semibold text-gray-900">
                        Projects <span class="ml-1 text-sm font-normal text-gray-600">({{ projects.total }})</span>
                    </h2>
                    <p v-if="projects.total > 0" class="text-sm text-gray-600">
                        Showing {{ projects.from }}–{{ projects.to }}
                    </p>
                    <p v-if="selectedProjectIsEditing" class="w-full text-xs text-gray-600">Cancel or submit editing before switching projects or changing the search.</p>
                </div>

                <div v-if="projects.data.length" class="fieldops-admin-directory-cards space-y-1.5">
                    <article
                        v-for="project in projects.data"
                        :key="project.id"
                        class="grid grid-cols-[minmax(0,1fr)_auto] items-stretch gap-2 rounded-lg border border-gray-200 bg-white p-2 shadow-sm transition-shadow hover:shadow-md"
                        :class="{ 'border-blue-300 bg-blue-50 shadow-md': selectedProjectId === project.id }"
                    >
                        <button
                            type="button"
                            class="grid min-h-[4.25rem] min-w-0 content-center gap-1 rounded-md border-l-4 border-transparent px-3 py-2 text-right transition-colors hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            :class="{ 'border-l-blue-700 bg-blue-50': selectedProjectId === project.id }"
                            :disabled="selectedProjectIsEditing && selectedProjectId !== project.id"
                            :aria-pressed="selectedProjectId === project.id"
                            :aria-label="`Open ${project.name} project details. Press and hold to edit.`"
                            title="Click to preview; press and hold to edit"
                            v-on="projectPress(project.id)"
                        >
                            <span class="flex min-w-0 items-center justify-end gap-2">
                                <span class="min-w-0 truncate font-semibold text-gray-900">{{ project.name }}</span>
                                <span v-if="project.number" class="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                    {{ project.number }}
                                </span>
                                <span class="fieldops-status-badge shrink-0" :class="statusClass(project.status)">
                                    {{ label(project.status) }}
                                </span>
                            </span>
                            <span class="truncate text-xs text-gray-600">
                                {{ project.customerName || "Customer not available" }} · {{ project.sitesCount }} {{ project.sitesCount === 1 ? "site" : "sites" }} · {{ project.jobsCount }} {{ project.jobsCount === 1 ? "job" : "jobs" }}
                            </span>
                        </button>
                        <Link
                            :href="route('admin.jobs', { project_id: project.id })"
                            class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center self-center p-0"
                            :aria-label="`View jobs in ${project.name}`"
                            title="View jobs"
                        >
                            <WrenchScrewdriverIcon class="h-5 w-5" aria-hidden="true" />
                        </Link>
                    </article>
                </div>

                <p v-else class="grid flex-1 place-items-center px-5 py-8 text-center text-sm text-gray-600 sm:px-6">
                    {{ filters.q ? "No projects match that search." : "No projects are available yet." }}
                </p>

                <PaginationChevronNav
                    v-if="projects.last_page > 1"
                    label="Project pages"
                    :current-page="projects.current_page"
                    :last-page="projects.last_page"
                    :previous-url="projects.prev_page_url"
                    :next-url="projects.next_page_url"
                    preserve-scroll
                />
            </section>
            </div>
            </div>
        </div>
    </AdminLayout>
</template>
