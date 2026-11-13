<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { MagnifyingGlassIcon, PlusIcon, WrenchScrewdriverIcon, XMarkIcon } from "@heroicons/vue/24/outline";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import type { GlobalSearchResult } from "@/Composables/useGlobalSearchAutocomplete";
import Modal from "@/Components/Modal.vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import JobCreateForm from "@/Components/Admin/JobCreateForm.vue";
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

interface FilterOption {
    value: string;
    label: string;
    color: string;
}

interface ProjectSiteOption {
    id: number;
    name: string;
    address: string | null;
}

interface ProjectCustomerOption {
    id: number;
    client_id: string | null;
    name: string;
    sites: ProjectSiteOption[];
}

interface ProjectJobCreateContext {
    projectId: number;
    projectName: string;
    projectNumber: string | null;
    customerId: number;
    customerName: string;
    siteIds: number[];
    scheduledAt: string | null;
}

interface ProjectCreateFormHandle {
    hasUnsavedChanges: () => boolean;
    submit: () => Promise<boolean>;
}

interface PendingProjectNavigation {
    projectId: number;
    edit: boolean;
}

type PendingProjectForm = "project" | "job";

const props = defineProps<{
    projects: PaginatedProjects;
    filters: {
        q: string;
        company: string;
        projectName: string;
        statuses: string[];
        excludedStatuses: string[];
        sort: string;
    };
    statusOptions: FilterOption[];
}>();

const search = ref(props.filters.q);
const companyFilter = ref(props.filters.company);
const projectNameFilter = ref(props.filters.projectName);
const selectedStatuses = ref([...props.filters.statuses]);
const sortBy = ref(props.filters.sort);
const statusFilterMenu = ref<HTMLDetailsElement | null>(null);
const selectedProjectId = ref<number | null>(null);
const selectedProjectIsEditing = ref(false);
const selectedProjectEditRequestId = ref(0);
const selectedProjectEditTargetId = ref<number | null>(null);
const creatingProject = ref(false);
const creatingJobForProject = ref(false);
const projectJobCreateFormRef = ref<ProjectCreateFormHandle | null>(null);
const projectCreateBusy = ref(false);
const projectCreateError = ref("");
const projectCustomer = ref<ProjectCustomerOption | null>(null);
const projectCustomerLoading = ref(false);
const projectCustomerQuery = ref("");
const projectSiteQuery = ref("");
const selectedProjectSiteIds = ref<number[]>([]);
const addingProjectSite = ref(false);
const projectSiteNameDraft = ref("");
const projectSiteAddressDraft = ref("");
const projectNameDraft = ref("");
const projectCreateReturnId = ref<number | null>(null);
const projectCreateScheduledAt = ref<string | null>(null);
const initialProjectFormSnapshot = ref<string | null>(null);
const pendingProjectNavigation = ref<PendingProjectNavigation | null>(null);
const pendingProjectForm = ref<PendingProjectForm | null>(null);
const savingBeforeProjectNavigation = ref(false);
const projectJobContext = ref<ProjectJobCreateContext | null>(null);
const projectWorkspaceInitialTab = ref<"details" | "jobs" | "activity">("details");
let projectCustomerLoadGeneration = 0;

const submitDirectory = (): void => {
    const excludedStatuses = props.statusOptions
        .map((option) => option.value)
        .filter((status) => ! selectedStatuses.value.includes(status));

    router.get(
        route("admin.projects"),
        {
            q: search.value.trim() || undefined,
            company: companyFilter.value.trim() || undefined,
            project_name: projectNameFilter.value.trim() || undefined,
            ...(excludedStatuses.length > 0 ? { exclude_statuses: excludedStatuses } : {}),
            sort: sortBy.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const submitSearch = (): void => submitDirectory();

const clearSearch = (): void => {
    search.value = "";
    submitDirectory();
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

const statusClass = (status: string): string =>
    `fieldops-status-badge--${props.statusOptions.find((option) => option.value === status)?.color ?? "gray"}`;

const label = (value: string): string =>
    props.statusOptions.find((option) => option.value === value)?.label
    ?? value.replaceAll("_", " ").replace(/\b\w/g, (character) => character.toUpperCase());

const hasActiveFilters = computed(() => props.filters.q !== ""
    || props.filters.company !== ""
    || props.filters.projectName !== ""
    || props.filters.excludedStatuses.length > 0);

const projectJobScheduleSuggestion = computed(() => {
    if (!projectCreateScheduledAt.value) return null;

    const scheduledAt = new Date(projectCreateScheduledAt.value);

    return Number.isNaN(scheduledAt.getTime())
        ? null
        : scheduledAt.toLocaleString(undefined, {
            weekday: "short",
            month: "short",
            day: "numeric",
            year: "numeric",
            hour: "numeric",
            minute: "2-digit",
        });
});

const projectFormSnapshot = (): string => JSON.stringify({
    customerId: projectCustomer.value?.id ?? null,
    selectedSiteIds: [...selectedProjectSiteIds.value].sort((first, second) => first - second),
    customerQuery: projectCustomerQuery.value,
    siteQuery: projectSiteQuery.value,
    projectName: projectNameDraft.value,
    addingSite: addingProjectSite.value,
    siteName: projectSiteNameDraft.value,
    siteAddress: projectSiteAddressDraft.value,
});

const hasUnsavedProjectChanges = (): boolean => initialProjectFormSnapshot.value !== null
    && projectFormSnapshot() !== initialProjectFormSnapshot.value;

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
    if (query.get("create_project") === "1") {
        openProjectCreate(null, query.get("scheduled_at"));
        ["create_project", "scheduled_at"].forEach((key) => query.delete(key));
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
    router.reload({ only: ["projects"] });
};

const continueProjectNavigation = (
    navigation: PendingProjectNavigation,
    form: PendingProjectForm | null,
): void => {
    pendingProjectNavigation.value = null;
    pendingProjectForm.value = null;
    savingBeforeProjectNavigation.value = false;

    if (form === "project" && creatingProject.value) cancelProjectCreate();
    if (form === "job" && creatingJobForProject.value) cancelProjectJobCreate();

    selectedProjectIsEditing.value = false;
    projectWorkspaceInitialTab.value = "details";
    selectedProjectId.value = navigation.projectId;
    selectedProjectEditTargetId.value = navigation.edit ? navigation.projectId : null;
    selectedProjectEditRequestId.value = navigation.edit
        ? selectedProjectEditRequestId.value + 1
        : 0;
};

const requestProjectNavigation = (projectId: number, edit: boolean): void => {
    if (selectedProjectIsEditing.value && selectedProjectId.value !== projectId) return;

    const navigation = { projectId, edit };

    if (creatingProject.value) {
        if (hasUnsavedProjectChanges()) {
            pendingProjectNavigation.value = navigation;
            pendingProjectForm.value = "project";

            return;
        }

        continueProjectNavigation(navigation, "project");

        return;
    }

    if (creatingJobForProject.value) {
        if (projectJobCreateFormRef.value?.hasUnsavedChanges()) {
            pendingProjectNavigation.value = navigation;
            pendingProjectForm.value = "job";

            return;
        }

        continueProjectNavigation(navigation, "job");

        return;
    }

    selectedProjectIsEditing.value = false;
    projectWorkspaceInitialTab.value = "details";
    selectedProjectId.value = projectId;
    selectedProjectEditTargetId.value = edit ? projectId : null;
    selectedProjectEditRequestId.value = edit
        ? selectedProjectEditRequestId.value + 1
        : 0;
};

const openProject = (projectId: number): void => requestProjectNavigation(projectId, false);

const openProjectForEditing = (projectId: number): void => requestProjectNavigation(projectId, true);

const projectPress = usePressAndHold(openProject, openProjectForEditing);

const openProjectCreate = (
    customer: { id: number; name: string } | null = null,
    scheduledAt: string | null = null,
): void => {
    projectCreateReturnId.value = selectedProjectId.value;
    selectedProjectId.value = null;
    creatingProject.value = true;
    creatingJobForProject.value = false;
    projectJobContext.value = null;
    projectCreateBusy.value = false;
    projectCreateError.value = "";
    projectCreateScheduledAt.value = scheduledAt;
    projectCustomer.value = null;
    selectedProjectSiteIds.value = [];
    projectCustomerQuery.value = "";
    projectSiteQuery.value = "";
    addingProjectSite.value = false;
    projectSiteNameDraft.value = "";
    projectSiteAddressDraft.value = "";
    projectNameDraft.value = "";
    initialProjectFormSnapshot.value = null;

    if (customer) {
        void loadProjectCustomer(customer.id, customer.name).finally(() => {
            initialProjectFormSnapshot.value = projectFormSnapshot();
        });
    } else {
        initialProjectFormSnapshot.value = projectFormSnapshot();
    }
};

const cancelProjectCreate = (): void => {
    if (projectCreateBusy.value) return;

    creatingProject.value = false;
    projectCustomerLoadGeneration += 1;
    projectCustomerLoading.value = false;
    projectCustomer.value = null;
    selectedProjectSiteIds.value = [];
    projectCustomerQuery.value = "";
    projectSiteQuery.value = "";
    addingProjectSite.value = false;
    projectSiteNameDraft.value = "";
    projectSiteAddressDraft.value = "";
    projectNameDraft.value = "";
    projectCreateError.value = "";
    projectCreateScheduledAt.value = null;
    initialProjectFormSnapshot.value = null;
    pendingProjectNavigation.value = null;
    pendingProjectForm.value = null;
    savingBeforeProjectNavigation.value = false;
    selectedProjectId.value = projectCreateReturnId.value;
    projectCreateReturnId.value = null;
};

const loadProjectCustomer = async (
    customerId: number,
    name: string,
    selectedSiteId: number | null = null,
): Promise<void> => {
    const generation = ++projectCustomerLoadGeneration;
    projectCustomerLoading.value = true;
    projectCreateError.value = "";

    try {
        const response = await window.axios.get(route("customers.show", customerId));
        const customer = response.data.data as {
            id: number;
            client_id: string | null;
            name: string;
            company_name?: string | null;
            sites: ProjectSiteOption[];
        };

        if (generation !== projectCustomerLoadGeneration) return;

        projectCustomer.value = {
            id: customer.id,
            client_id: customer.client_id,
            name: name || customer.company_name || customer.name,
            sites: customer.sites,
        };
        selectedProjectSiteIds.value = selectedSiteId !== null
            && customer.sites.some((site) => site.id === selectedSiteId)
            ? [selectedSiteId]
            : [];
        projectCustomerQuery.value = "";
    } catch {
        if (generation !== projectCustomerLoadGeneration) return;
        projectCreateError.value = "The Customer's Sites could not be loaded. Try selecting the Customer again.";
    } finally {
        if (generation === projectCustomerLoadGeneration) projectCustomerLoading.value = false;
    }
};

const selectProjectCustomer = (result: GlobalSearchResult): void => {
    if (result.type === "client") void loadProjectCustomer(result.id, result.name);
};

const selectProjectSite = (result: GlobalSearchResult): void => {
    if (result.type !== "site" || result.parent_id === undefined) return;

    void loadProjectCustomer(result.parent_id, "", result.id);
};

const clearProjectCustomer = (): void => {
    projectCustomerLoadGeneration += 1;
    projectCustomerLoading.value = false;
    projectCustomer.value = null;
    selectedProjectSiteIds.value = [];
    projectCustomerQuery.value = "";
};

const startAddingProjectSite = (): void => {
    addingProjectSite.value = true;
    projectSiteNameDraft.value = "";
    projectSiteAddressDraft.value = "";
};

const cancelAddingProjectSite = (): void => {
    addingProjectSite.value = false;
    projectSiteNameDraft.value = "";
    projectSiteAddressDraft.value = "";
};

const submitNewProject = async (addJob = false): Promise<boolean> => {
    if (!projectCustomer.value || !projectNameDraft.value.trim() || projectCreateBusy.value || projectCustomerLoading.value) return false;
    if (addingProjectSite.value && !projectSiteNameDraft.value.trim()) return false;

    projectCreateBusy.value = true;
    projectCreateError.value = "";

    try {
        const response = await window.axios.post(
            route("admin.customers.projects.store", projectCustomer.value.id),
            {
                name: projectNameDraft.value.trim(),
                site_ids: selectedProjectSiteIds.value,
                ...(addingProjectSite.value ? {
                    new_site: {
                        name: projectSiteNameDraft.value.trim(),
                        address: projectSiteAddressDraft.value.trim() || null,
                    },
                } : {}),
            },
        );
        const createdProjectId = Number(response.data.data.id);
        const pendingNavigation = pendingProjectForm.value === "project"
            ? pendingProjectNavigation.value
            : null;
        creatingProject.value = false;
        projectCreateReturnId.value = null;

        if (pendingNavigation) {
            projectCreateScheduledAt.value = null;
            initialProjectFormSnapshot.value = null;
            pendingProjectNavigation.value = null;
            pendingProjectForm.value = null;
            savingBeforeProjectNavigation.value = false;
            selectedProjectIsEditing.value = false;
            projectWorkspaceInitialTab.value = "details";
            selectedProjectId.value = pendingNavigation.projectId;
            selectedProjectEditTargetId.value = pendingNavigation.edit
                ? pendingNavigation.projectId
                : null;
            selectedProjectEditRequestId.value = pendingNavigation.edit
                ? selectedProjectEditRequestId.value + 1
                : 0;
            router.reload({ only: ["projects"] });

            return true;
        }

        if (addJob) {
            projectJobContext.value = {
                projectId: createdProjectId,
                projectName: String(response.data.data.name),
                projectNumber: response.data.data.number ?? null,
                customerId: projectCustomer.value.id,
                customerName: projectCustomer.value.name,
                siteIds: response.data.data.siteIds ?? [...selectedProjectSiteIds.value],
                scheduledAt: projectCreateScheduledAt.value,
            };
            projectCreateScheduledAt.value = null;
            creatingJobForProject.value = true;

            return true;
        }

        projectCreateScheduledAt.value = null;
        initialProjectFormSnapshot.value = null;
        projectWorkspaceInitialTab.value = "details";
        selectedProjectId.value = createdProjectId;
        router.reload({ only: ["projects"] });

        return true;
    } catch (error: unknown) {
        const responseErrors = (error as {
            response?: { data?: { errors?: Record<string, string[]> } };
        }).response?.data?.errors;
        projectCreateError.value = responseErrors?.["new_site.name"]?.[0]
            ?? responseErrors?.["new_site.address"]?.[0]
            ?? responseErrors?.site_ids?.[0]
            ?? responseErrors?.name?.[0]
            ?? "The Project could not be created. Try again.";

        return false;
    } finally {
        projectCreateBusy.value = false;
    }
};

const cancelProjectJobCreate = (): void => {
    if (!projectJobContext.value) return;

    pendingProjectNavigation.value = null;
    pendingProjectForm.value = null;
    savingBeforeProjectNavigation.value = false;
    selectedProjectId.value = projectJobContext.value.projectId;
    projectWorkspaceInitialTab.value = "jobs";
    projectJobContext.value = null;
    creatingJobForProject.value = false;
};

const finishProjectJobCreate = (): void => {
    if (!projectJobContext.value) return;

    const navigation = pendingProjectForm.value === "job"
        ? pendingProjectNavigation.value
        : null;
    selectedProjectId.value = navigation?.projectId ?? projectJobContext.value.projectId;
    projectWorkspaceInitialTab.value = navigation ? "details" : "jobs";
    selectedProjectEditTargetId.value = navigation?.edit ? navigation.projectId : null;
    selectedProjectEditRequestId.value = navigation?.edit
        ? selectedProjectEditRequestId.value + 1
        : 0;
    selectedProjectIsEditing.value = false;
    projectJobContext.value = null;
    creatingJobForProject.value = false;
    pendingProjectNavigation.value = null;
    pendingProjectForm.value = null;
    savingBeforeProjectNavigation.value = false;
    router.reload({ only: ["projects"] });
};

const keepProjectEditing = (): void => {
    pendingProjectNavigation.value = null;
    pendingProjectForm.value = null;
    savingBeforeProjectNavigation.value = false;
};

const discardProjectAndContinue = (): void => {
    const navigation = pendingProjectNavigation.value;
    const form = pendingProjectForm.value;
    if (!navigation || !form) return;

    continueProjectNavigation(navigation, form);
};

const saveProjectAndContinue = async (): Promise<void> => {
    const form = pendingProjectForm.value;
    if (!pendingProjectNavigation.value || !form || savingBeforeProjectNavigation.value) return;

    savingBeforeProjectNavigation.value = true;
    const saved = form === "project"
        ? await submitNewProject()
        : await projectJobCreateFormRef.value?.submit();

    if (!saved && pendingProjectNavigation.value !== null) {
        pendingProjectNavigation.value = null;
        pendingProjectForm.value = null;
        savingBeforeProjectNavigation.value = false;
    }
};

const closeProject = (): void => {
    selectedProjectId.value = null;
    projectWorkspaceInitialTab.value = "details";
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
                v-if="selectedProjectId !== null && !creatingProject && !creatingJobForProject"
                :key="selectedProjectId"
                :project-id="selectedProjectId"
                :edit-request-id="selectedProjectEditTargetId === selectedProjectId ? selectedProjectEditRequestId : 0"
                :can-archive="$page.props.auth.is_admin"
                :inline-on-desktop="true"
                :show-add-action="true"
                :initial-tab="projectWorkspaceInitialTab"
                @close="closeProject"
                @add-project="openProjectCreate"
                @editing-change="selectedProjectIsEditing = $event"
                @saved="refreshDirectory"
                @archived="refreshDirectory"
            />

            <section v-else-if="creatingJobForProject && projectJobContext" class="fieldops-directory-empty-details flex" aria-label="Add Job to Project">
                <header class="fieldops-workspace-fixed-header">
                    <div class="min-w-0">
                        <h2 class="fieldops-workspace-title">Add Job to Project</h2>
                        <p class="truncate text-xs text-gray-600">
                            {{ projectJobContext.projectNumber ? `${projectJobContext.projectNumber} · ` : "" }}{{ projectJobContext.projectName }} · {{ projectJobContext.customerName }}
                        </p>
                    </div>
                    <button type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Cancel Job creation" title="Cancel" @click="cancelProjectJobCreate">
                        <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                    </button>
                </header>
                <div class="fieldops-directory-empty-details__body">
                    <JobCreateForm
                        ref="projectJobCreateFormRef"
                        :customer-id="projectJobContext.customerId"
                        :customer-locked="true"
                        :site-id="projectJobContext.siteIds[0] ?? null"
                        :allowed-site-ids="projectJobContext.siteIds"
                        :project-id="projectJobContext.projectId"
                        :scheduled-at="projectJobContext.scheduledAt"
                        :initial-status="projectJobContext.scheduledAt ? 'scheduled' : null"
                        @cancel="cancelProjectJobCreate"
                        @created="finishProjectJobCreate"
                    />
                </div>
            </section>

            <section v-else-if="creatingProject" class="fieldops-directory-empty-details flex" aria-label="Add Project">
                <header class="fieldops-workspace-fixed-header">
                    <h2 class="fieldops-workspace-title">Add Project</h2>
                    <button type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Cancel Project creation" title="Cancel" :disabled="projectCreateBusy" @click="cancelProjectCreate">
                        <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                    </button>
                </header>
                <form class="fieldops-directory-empty-details__body grid content-start gap-4" :inert="projectCustomerLoading" @submit.prevent="submitNewProject()">
                    <div class="grid gap-1">
                        <label for="directory-project-customer" class="fieldops-client-label">Customer</label>
                        <div v-if="projectCustomer" class="flex min-h-11 items-center justify-between gap-3 rounded-lg border border-fieldops-blue-dark bg-white px-3 py-2 text-sm">
                            <span class="min-w-0 truncate"><strong>{{ projectCustomer.name }}</strong><small v-if="projectCustomer.client_id" class="ml-2 text-gray-500">{{ projectCustomer.client_id }}</small></span>
                            <button type="button" class="shrink-0 text-xs font-bold uppercase text-fieldops-blue-dark underline" :disabled="projectCustomerLoading || projectCreateBusy" @click="clearProjectCustomer">Change</button>
                        </div>
                        <ClearableSearchInput
                            v-else
                            id="directory-project-customer"
                            v-model="projectCustomerQuery"
                            class="fieldops-client-input fieldops-entity-picker-input"
                            placeholder="Search customers"
                            clear-label="Clear Project customer search"
                            :suggestion-types="['client']"
                            :dispatch-selection="false"
                            show-search-icon
                            search-icon-label="Search customers"
                            @select="selectProjectCustomer"
                        />
                        <p v-if="projectCustomerLoading" role="status" class="text-xs text-gray-600">Loading Customer Sites…</p>
                    </div>
                    <div class="grid gap-2">
                        <label for="directory-project-site" class="fieldops-client-label">Site <span class="font-normal text-gray-600">(optional)</span></label>
                        <div class="flex items-stretch gap-2">
                            <ClearableSearchInput
                                id="directory-project-site"
                                v-model="projectSiteQuery"
                                class="fieldops-client-input fieldops-entity-picker-input min-w-0 flex-1"
                                placeholder="Search Sites"
                                clear-label="Clear Project Site search"
                                :suggestion-types="['site']"
                                :dispatch-selection="false"
                                show-search-icon
                                search-icon-label="Search Sites"
                                @select="selectProjectSite"
                            />
                            <button
                                type="button"
                                class="fieldops-entity-picker-add-button fieldops-add-action shrink-0"
                                aria-label="Add new Site"
                                title="Add new Site"
                                :disabled="projectCreateBusy || projectCustomerLoading"
                                @click="addingProjectSite ? cancelAddingProjectSite() : startAddingProjectSite()"
                            >
                                <PlusIcon v-if="!addingProjectSite" class="h-5 w-5" aria-hidden="true" />
                                <XMarkIcon v-else class="h-5 w-5" aria-hidden="true" />
                            </button>
                        </div>
                        <fieldset v-if="projectCustomer" class="grid gap-2" :disabled="projectCreateBusy || projectCustomerLoading">
                            <legend class="text-sm font-semibold text-gray-900">Sites for this Project <span class="font-normal text-gray-600">(optional)</span></legend>
                            <label v-for="site in projectCustomer.sites" :key="site.id" class="flex min-h-12 items-center gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm">
                                <input v-model="selectedProjectSiteIds" type="checkbox" :value="site.id" />
                                <span class="min-w-0">
                                    <span class="block truncate font-medium">{{ site.name }}</span>
                                    <small class="block truncate text-gray-500">{{ site.address || "No address recorded" }}</small>
                                </span>
                            </label>
                            <p v-if="projectCustomer.sites.length === 0" class="text-sm text-gray-600">This Customer has no active Sites. Add one here or save the Project without Sites.</p>
                        </fieldset>
                        <section v-if="addingProjectSite" class="grid gap-3 rounded-lg border border-gray-200 bg-white/50 p-3" aria-label="Add Site to Project">
                            <h3 class="text-sm font-semibold text-gray-900">New Site</h3>
                            <div v-if="projectCustomer" class="flex min-h-10 items-center justify-between gap-3 text-sm">
                                <span class="truncate">For <strong>{{ projectCustomer.name }}</strong></span>
                                <button type="button" class="text-xs font-bold uppercase text-fieldops-blue-dark underline" :disabled="projectCreateBusy" @click="clearProjectCustomer">Change Customer</button>
                            </div>
                            <div v-else class="grid gap-1">
                                <label for="new-project-site-customer" class="fieldops-client-label">Customer</label>
                                <ClearableSearchInput
                                    id="new-project-site-customer"
                                    v-model="projectCustomerQuery"
                                    class="fieldops-client-input fieldops-entity-picker-input"
                                    placeholder="Search customers"
                                    clear-label="Clear Site customer search"
                                    :suggestion-types="['client']"
                                    :dispatch-selection="false"
                                    show-search-icon
                                    search-icon-label="Search customers"
                                    @select="selectProjectCustomer"
                                />
                            </div>
                            <label class="fieldops-workspace-field">Site name<input v-model="projectSiteNameDraft" required maxlength="255" placeholder="Site name" /></label>
                            <label class="fieldops-workspace-field">Site address (optional)<input v-model="projectSiteAddressDraft" maxlength="1000" placeholder="Street, City, State ZIP" /></label>
                            <p class="text-xs text-gray-600">The new Site will be saved with this Project and its Customer.</p>
                        </section>
                    </div>
                    <label class="fieldops-workspace-field">
                        Project name
                        <input v-model="projectNameDraft" required maxlength="255" placeholder="Project name" />
                    </label>
                    <p v-if="projectCreateError" role="alert" class="text-sm text-red-700">{{ projectCreateError }}</p>
                    <p class="text-xs text-gray-600">Search or add a Site at any time. Selecting an existing Site fills its owning Customer. Add Job saves the Project first, then opens the full Job form here.</p>
                    <p v-if="projectJobScheduleSuggestion" class="text-sm font-medium text-fieldops-blue-dark">Job schedule suggestion: {{ projectJobScheduleSuggestion }}</p>
                    <div class="flex justify-end gap-2">
                        <button type="button" class="fieldops-secondary-action min-h-10" :disabled="projectCreateBusy" @click="cancelProjectCreate">Cancel</button>
                        <button type="button" class="fieldops-secondary-action fieldops-add-action min-h-10" :disabled="projectCreateBusy || projectCustomerLoading || !projectCustomer || !projectNameDraft.trim() || (addingProjectSite && !projectSiteNameDraft.trim())" @click="submitNewProject(true)">
                            {{ projectCreateBusy ? "Saving…" : "Add Job" }}
                        </button>
                        <button type="submit" class="fieldops-submit-action fieldops-add-action min-h-10" :disabled="projectCreateBusy || projectCustomerLoading || !projectCustomer || !projectNameDraft.trim() || (addingProjectSite && !projectSiteNameDraft.trim())">
                            {{ projectCreateBusy ? "Adding…" : "Add Project" }}
                        </button>
                    </div>
                </form>
            </section>

            <section v-else class="fieldops-directory-empty-details hidden lg:flex" aria-label="Project details">
                <header class="fieldops-workspace-fixed-header">
                    <h2 class="fieldops-workspace-title">Project Details</h2>
                    <button type="button" class="fieldops-secondary-action fieldops-add-action inline-flex min-h-10 items-center gap-2 px-3 py-1" @click="openProjectCreate()">
                        <PlusIcon class="h-4 w-4" aria-hidden="true" />
                        Add Project
                    </button>
                </header>
                <div class="fieldops-directory-empty-details__body">
                    <p class="text-sm text-gray-600">Select a project to load its details and edit form.</p>
                </div>
            </section>

            <div class="fieldops-admin-directory-list-column min-w-0 lg:col-start-2" :inert="selectedProjectIsEditing">
            <header class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 px-1">
                <h2 class="font-semibold text-gray-900">
                    <span class="text-base">Project directory</span>
                    <span class="ml-1 text-sm font-normal text-gray-600">({{ projects.total }})</span>
                </h2>
                <p v-if="projects.total > 0" class="text-sm text-gray-600">
                    Showing {{ projects.from }}–{{ projects.to }}
                </p>
                <p v-if="selectedProjectIsEditing" class="w-full text-xs text-gray-600">
                    Cancel or submit editing before switching projects or changing the search.
                </p>
            </header>
            <section class="fieldops-panel grid grid-cols-1 gap-2 bg-white p-3 sm:grid-cols-2" aria-label="Filter and sort projects">
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
                    <select v-model="sortBy" aria-label="Sort projects" class="fieldops-client-input" @focus="closeStatusFilterMenu" @change="submitDirectory">
                        <optgroup label="Project">
                            <option value="name_asc">Project name A–Z</option>
                            <option value="name_desc">Project name Z–A</option>
                            <option value="number_asc">Project number A–Z</option>
                            <option value="number_desc">Project number Z–A</option>
                        </optgroup>
                        <optgroup label="Other">
                            <option value="company_asc">Company name A–Z</option>
                            <option value="company_desc">Company name Z–A</option>
                            <option value="status">Status</option>
                            <option value="updated_desc">Recently updated</option>
                        </optgroup>
                    </select>
                </label>
            </section>
                <form
                    role="search"
                    aria-label="Search projects"
                    class="flex min-w-0 items-center gap-2"
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
                >
                    <template #action="{ closeSuggestions }">
                        <button
                            type="submit"
                            class="fieldops-clearable-search__action"
                            aria-label="Search projects"
                            title="Search projects"
                            @click="closeSuggestions"
                        >
                            <MagnifyingGlassIcon class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </template>
                </ClearableSearchInput>
            </form>

            <section
                class="fieldops-panel fieldops-admin-directory-results overflow-hidden bg-gray-50"
                aria-label="Project results"
            >
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
                    {{ hasActiveFilters ? "No projects match these filters." : "No projects are available yet." }}
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

        <Modal
            :show="pendingProjectNavigation !== null"
            max-width="md"
            :closeable="!savingBeforeProjectNavigation"
            @close="keepProjectEditing"
        >
            <div class="space-y-4 p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ pendingProjectForm === "job" ? "Unsaved Job details" : "Unsaved Project details" }}
                </h2>
                <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                    {{ pendingProjectForm === "job"
                        ? "Save the new Job before switching, or discard its details and switch."
                        : "Save the new Project before switching, or discard its details and switch." }}
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" class="fieldops-secondary-action min-h-10" :disabled="savingBeforeProjectNavigation" @click="keepProjectEditing">
                        Keep editing
                    </button>
                    <button type="button" class="fieldops-secondary-action min-h-10" :disabled="savingBeforeProjectNavigation" @click="discardProjectAndContinue">
                        {{ pendingProjectForm === "job" ? "Discard Job" : "Discard Project" }}
                    </button>
                    <button type="button" class="fieldops-submit-action min-h-10" :disabled="savingBeforeProjectNavigation" @click="saveProjectAndContinue">
                        {{ savingBeforeProjectNavigation ? "Saving…" : pendingProjectForm === "job" ? "Save Job and continue" : "Save Project and continue" }}
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
