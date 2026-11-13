<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { onBeforeUnmount, onMounted, ref, watch } from "vue";
import { ArrowsUpDownIcon, MagnifyingGlassIcon, PlusIcon, XMarkIcon } from "@heroicons/vue/24/outline";
import type { GlobalSearchResultType } from "@/Composables/useGlobalSearchAutocomplete";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import Modal from "@/Components/Modal.vue";
import CustomerCreateForm from "@/Components/Admin/CustomerCreateForm.vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import CustomerWorkspace from "@/Components/Admin/CustomerWorkspace.vue";
import PaginationChevronNav from "@/Components/Admin/PaginationChevronNav.vue";
import { usePressAndHold } from "@/Composables/usePressAndHold";

interface AdminCustomer {
    id: number;
    clientId: string | null;
    name: string;
    companyName: string | null;
    customerGroup: string | null;
    customerStatus: string | null;
    firstName: string | null;
    lastName: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    primaryAddress: string | null;
    quickBooksActive: boolean | null;
    syncStatus: string | null;
    lastSyncedAt: string | null;
    sitesCount: number;
    projectsCount: number;
    jobsCount: number;
}

interface FilterOption {
    value: string;
    label: string;
}

interface PaginatedCustomers {
    data: AdminCustomer[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface CustomerWorkspaceHandle {
    submit: () => Promise<boolean>;
    cancelEditing: () => void;
}

interface CustomerCreateFormHandle {
    hasUnsavedChanges: () => boolean;
    submit: () => Promise<boolean>;
}

interface PendingCustomerNavigation {
    customerId: number | null;
    edit: boolean;
    href?: string;
    createClient?: boolean;
}

const props = defineProps<{
    customers: PaginatedCustomers;
    filters: {
        q: string;
        groups: string[];
        statuses: string[];
        showArchivedShortcut: boolean;
        sort: string;
    };
    groupOptions: FilterOption[];
    statusOptions: FilterOption[];
}>();

const search = ref(props.filters.q);
const selectedGroups = ref([...props.filters.groups]);
const selectedStatuses = ref([...props.filters.statuses]);
const sortBy = ref(props.filters.sort);
const customerSuggestionTypes: GlobalSearchResultType[] = ["client"];
const groupFilterMenu = ref<HTMLDetailsElement | null>(null);
const statusFilterMenu = ref<HTMLDetailsElement | null>(null);
const selectedCustomerId = ref<number | null>(null);
const selectedCustomerIsEditing = ref(false);
const selectedCustomerHasChanges = ref(false);
const selectedCustomerJobHasChanges = ref(false);
const selectedCustomerEditRequestId = ref(0);
const selectedCustomerEditTargetId = ref<number | null>(null);
const customerWorkspaceContainer = ref<HTMLElement | null>(null);
const customerWorkspaceRef = ref<CustomerWorkspaceHandle | null>(null);
const customerCreateFormRef = ref<CustomerCreateFormHandle | null>(null);
const pendingCustomerNavigation = ref<PendingCustomerNavigation | null>(null);
const savingBeforeNavigation = ref(false);
const pendingCustomerCreateNavigation = ref(false);
const pendingCustomerJobNavigation = ref(false);
const creatingClient = ref(false);
const clientCreateReturnCustomerId = ref<number | null>(null);

const submitSearch = (): void => {
    const excludedGroups = props.groupOptions
        .map((option) => option.value)
        .filter((value) => ! selectedGroups.value.includes(value));
    const excludedStatuses = props.statusOptions
        .map((option) => option.value)
        .filter((value) => ! selectedStatuses.value.includes(value));

    router.get(
        route("admin.customers"),
        {
            q: search.value.trim() || undefined,
            ...(excludedGroups.length > 0 ? { exclude_groups: excludedGroups } : {}),
            ...(excludedStatuses.length > 0 ? { exclude_statuses: excludedStatuses } : {}),
            sort: sortBy.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const toggleAllGroups = (event: Event): void => {
    selectedGroups.value = (event.target as HTMLInputElement).checked
        ? props.groupOptions.map((option) => option.value)
        : [];
    submitSearch();
};

const toggleAllStatuses = (event: Event): void => {
    selectedStatuses.value = (event.target as HTMLInputElement).checked
        ? props.statusOptions.map((option) => option.value)
        : [];
    submitSearch();
};

const closeFilterMenus = (): void => {
    if (groupFilterMenu.value) groupFilterMenu.value.open = false;
    if (statusFilterMenu.value) statusFilterMenu.value.open = false;
};

const handleFilterMenuToggle = (menu: "group" | "status", event: Event): void => {
    const currentMenu = event.currentTarget as HTMLDetailsElement;
    if (!currentMenu.open) return;

    if (menu !== "group" && groupFilterMenu.value) groupFilterMenu.value.open = false;
    if (menu !== "status" && statusFilterMenu.value) statusFilterMenu.value.open = false;
};

const handleDocumentPointerDown = (event: PointerEvent): void => {
    const target = event.target;
    if (!(target instanceof Node)) return;
    if (groupFilterMenu.value?.contains(target) || statusFilterMenu.value?.contains(target)) return;

    closeFilterMenus();
};

const handleDocumentKeydown = (event: KeyboardEvent): void => {
    if (event.key === "Escape") closeFilterMenus();
};

watch(() => props.filters, (filters) => {
    search.value = filters.q;
    selectedGroups.value = [...filters.groups];
    selectedStatuses.value = [...filters.statuses];
    sortBy.value = filters.sort;
});

const refreshDirectory = (): void => {
    router.reload({ only: ["customers"] });
};

const handleCustomerSaved = (): void => {
    if (pendingCustomerNavigation.value === null) refreshDirectory();
};

const continueCustomerNavigation = (navigation: PendingCustomerNavigation): void => {
    pendingCustomerNavigation.value = null;
    savingBeforeNavigation.value = false;
    pendingCustomerCreateNavigation.value = false;
    pendingCustomerJobNavigation.value = false;
    selectedCustomerIsEditing.value = false;
    selectedCustomerHasChanges.value = false;
    selectedCustomerJobHasChanges.value = false;

    if (navigation.createClient) {
        clientCreateReturnCustomerId.value = selectedCustomerId.value;
        selectedCustomerId.value = null;
        selectedCustomerEditTargetId.value = null;
        selectedCustomerEditRequestId.value = 0;
        creatingClient.value = true;

        return;
    }

    creatingClient.value = false;
    clientCreateReturnCustomerId.value = null;

    if (navigation.customerId === null) {
        selectedCustomerId.value = null;
        selectedCustomerEditTargetId.value = null;
        selectedCustomerEditRequestId.value = 0;
    } else {
        selectedCustomerId.value = navigation.customerId;
        selectedCustomerEditTargetId.value = navigation.edit ? navigation.customerId : null;
        selectedCustomerEditRequestId.value = navigation.edit
            ? selectedCustomerEditRequestId.value + 1
            : 0;
    }

    if (navigation.href) {
        const destination = new URL(navigation.href, window.location.href);

        if (destination.origin === window.location.origin) {
            router.visit(destination.href);
        } else {
            window.location.assign(destination.href);
        }
    }
};

const requestCustomerNavigation = (customerId: number | null, edit = false, href?: string, createClient = false): void => {
    if (!createClient && customerId === selectedCustomerId.value && href === undefined) {
        if (edit && ! selectedCustomerIsEditing.value) {
            selectedCustomerEditTargetId.value = customerId;
            selectedCustomerEditRequestId.value += 1;
        }

        return;
    }

    const navigation = { customerId, edit, href, createClient };

    if (creatingClient.value) {
        if (customerCreateFormRef.value?.hasUnsavedChanges()) {
            pendingCustomerNavigation.value = navigation;
            pendingCustomerCreateNavigation.value = true;

            return;
        }

        continueCustomerNavigation(navigation);

        return;
    }

    if (selectedCustomerJobHasChanges.value) {
        pendingCustomerNavigation.value = navigation;
        pendingCustomerCreateNavigation.value = false;
        pendingCustomerJobNavigation.value = true;

        return;
    }

    if (selectedCustomerIsEditing.value && selectedCustomerHasChanges.value) {
        pendingCustomerNavigation.value = navigation;
        pendingCustomerCreateNavigation.value = false;
        pendingCustomerJobNavigation.value = false;

        return;
    }

    continueCustomerNavigation(navigation);
};

const openCustomer = (customerId: number): void => requestCustomerNavigation(customerId);

const openCustomerForEditing = (customerId: number): void => requestCustomerNavigation(customerId, true);

const addClient = (): void => requestCustomerNavigation(null, false, undefined, true);

const cancelClientCreate = (): void => {
    creatingClient.value = false;
    pendingCustomerNavigation.value = null;
    pendingCustomerCreateNavigation.value = false;
    pendingCustomerJobNavigation.value = false;
    selectedCustomerJobHasChanges.value = false;
    selectedCustomerId.value = clientCreateReturnCustomerId.value;
    clientCreateReturnCustomerId.value = null;
};

const handleClientCreated = (customerId: number): void => {
    const navigation = pendingCustomerNavigation.value;

    if (navigation) {
        continueCustomerNavigation(navigation);
        refreshDirectory();

        return;
    }

    creatingClient.value = false;
    clientCreateReturnCustomerId.value = null;
    selectedCustomerId.value = customerId;
    selectedCustomerEditTargetId.value = null;
    selectedCustomerEditRequestId.value = 0;
    router.reload({ only: ["customers"] });
};

const keepEditing = (): void => {
    pendingCustomerNavigation.value = null;
    pendingCustomerCreateNavigation.value = false;
    pendingCustomerJobNavigation.value = false;
};

const discardAndNavigate = (): void => {
    const navigation = pendingCustomerNavigation.value;
    if (!navigation) return;

    customerWorkspaceRef.value?.cancelEditing();
    continueCustomerNavigation(navigation);
};

const saveAndNavigate = async (): Promise<void> => {
    if (!pendingCustomerNavigation.value || savingBeforeNavigation.value) return;

    savingBeforeNavigation.value = true;

    if (pendingCustomerCreateNavigation.value) {
        const saved = await customerCreateFormRef.value?.submit();

        if (!saved && pendingCustomerNavigation.value !== null) {
            pendingCustomerNavigation.value = null;
            pendingCustomerCreateNavigation.value = false;
            savingBeforeNavigation.value = false;
        }

        return;
    }

    if (pendingCustomerJobNavigation.value) {
        const saved = await customerWorkspaceRef.value?.submit();

        if (!saved) {
            pendingCustomerNavigation.value = null;
            pendingCustomerJobNavigation.value = false;
            savingBeforeNavigation.value = false;

            return;
        }

        const navigation = pendingCustomerNavigation.value;
        if (navigation) {
            continueCustomerNavigation(navigation);
            if (navigation.href === undefined && !navigation.createClient) refreshDirectory();
        }

        return;
    }

    const saved = await customerWorkspaceRef.value?.submit();

    if (!saved) {
        savingBeforeNavigation.value = false;
        pendingCustomerNavigation.value = null;

        return;
    }

    const navigation = pendingCustomerNavigation.value;
    if (navigation) {
        continueCustomerNavigation(navigation);
        if (navigation.href === undefined && !navigation.createClient) refreshDirectory();
    }
};

const customerPress = usePressAndHold(openCustomer, openCustomerForEditing);

const handleCustomerSearchResult = (event: Event): void => {
    const customerId = Number((event as CustomEvent<number>).detail);

    if (Number.isSafeInteger(customerId) && customerId > 0) openCustomer(customerId);
};

const handleWorkspaceOutsidePointerDown = (event: PointerEvent): void => {
    if (
        selectedCustomerId.value === null ||
        ! selectedCustomerIsEditing.value ||
        pendingCustomerNavigation.value !== null ||
        event.button !== 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey
    ) return;

    const target = event.target;
    if (!(target instanceof Element)) return;
    if (customerWorkspaceContainer.value?.contains(target)) return;
    if (target.closest("[data-customer-card]")) return;

    const link = target.closest<HTMLAnchorElement>("a[href]");
    if (link?.target === "_blank") return;

    if (selectedCustomerHasChanges.value) {
        event.preventDefault();
        event.stopPropagation();
        requestCustomerNavigation(null, false, link?.href);

        return;
    }

    if (!link) requestCustomerNavigation(null);
};

onMounted(() => {
    window.addEventListener("fieldops:customer-search-result-selected", handleCustomerSearchResult);
    document.addEventListener("pointerdown", handleDocumentPointerDown);
    document.addEventListener("keydown", handleDocumentKeydown);
    document.addEventListener("pointerdown", handleWorkspaceOutsidePointerDown, true);

    const query = new URLSearchParams(window.location.search);
    const requestedCustomer = Number(query.get("open_customer"));
    const createClientRequested = query.get("create_client") === "1";

    if (createClientRequested) {
        addClient();
        query.delete("create_client");
        const queryString = query.toString();
        router.visit(`${window.location.pathname}${queryString ? `?${queryString}` : ""}${window.location.hash}`, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    } else if (Number.isSafeInteger(requestedCustomer) && requestedCustomer > 0) {
        openCustomer(requestedCustomer);
    }
});

onBeforeUnmount(() => {
    window.removeEventListener("fieldops:customer-search-result-selected", handleCustomerSearchResult);
    document.removeEventListener("pointerdown", handleDocumentPointerDown);
    document.removeEventListener("keydown", handleDocumentKeydown);
    document.removeEventListener("pointerdown", handleWorkspaceOutsidePointerDown, true);
});

const truncatePreviewCompanyName = (value: string): string => {
    const characters = Array.from(value);

    return characters.length > 25 ? `${characters.slice(0, 25).join("")}…` : value;
};

const customerSecondaryLines = (customer: AdminCustomer): string[] => [
    [customer.companyName ? truncatePreviewCompanyName(customer.companyName) : null, customer.phone]
        .filter((value): value is string => Boolean(value))
        .join(" · "),
    [customer.email, customer.primaryAddress]
        .filter((value): value is string => Boolean(value))
        .join(" · "),
].filter(Boolean);

const customerSecondarySummary = (customer: AdminCustomer): string =>
    [customer.companyName, customer.phone, customer.email, customer.primaryAddress]
        .filter((value): value is string => Boolean(value))
        .join(" · ") || "No company or contact details";

const optionLabel = (options: FilterOption[], value: string | null): string =>
    options.find((option) => option.value === value)?.label ?? "Unassigned";

const selectionSummary = (options: FilterOption[], selected: string[]): string => {
    if (selected.length === options.length) return "All";
    if (selected.length === 0) return "None";

    return selected.length === 1
        ? options.find((option) => option.value === selected[0])?.label ?? "1 selected"
        : `${selected.length} selected`;
};

const closeCustomerWorkspace = (): void => requestCustomerNavigation(null);
</script>

<template>
    <Head title="Customers" />

    <AdminLayout title="Customers">
        <div class="fieldops-admin-directory-page mx-auto max-w-7xl space-y-4 px-4 py-5 sm:px-6 lg:px-8">
            <div class="fieldops-admin-directory-grid grid items-stretch gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(19rem,0.85fr)]">
                <section v-if="creatingClient" class="fieldops-directory-empty-details flex" aria-label="Add Customer">
                    <header class="fieldops-workspace-fixed-header">
                        <h2 class="fieldops-workspace-title">Add Customer</h2>
                        <button type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Cancel Customer creation" title="Cancel" @click="cancelClientCreate">
                            <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </header>
                    <div class="fieldops-directory-empty-details__body">
                        <CustomerCreateForm ref="customerCreateFormRef" @cancel="cancelClientCreate" @created="handleClientCreated" />
                    </div>
                </section>

                <div v-else-if="selectedCustomerId !== null" ref="customerWorkspaceContainer" class="min-w-0">
                    <CustomerWorkspace
                        ref="customerWorkspaceRef"
                        :key="selectedCustomerId"
                        :customer-id="selectedCustomerId"
                        :edit-request-id="selectedCustomerEditTargetId === selectedCustomerId ? selectedCustomerEditRequestId : 0"
                        :can-archive="$page.props.auth.is_admin"
                        :inline-on-desktop="true"
                        @close="closeCustomerWorkspace"
                        @add-client="addClient"
                        @editing-change="selectedCustomerIsEditing = $event"
                        @dirty-change="selectedCustomerHasChanges = $event"
                        @job-create-dirty-change="selectedCustomerJobHasChanges = $event"
                        @saved="handleCustomerSaved"
                        @archived="refreshDirectory"
                    />
                </div>

                <section v-else class="fieldops-directory-empty-details hidden lg:flex" aria-label="Customer details">
                    <header class="fieldops-workspace-fixed-header">
                        <h2 class="fieldops-workspace-title">Customer details</h2>
                        <button
                            type="button"
                            class="fieldops-secondary-action fieldops-add-action inline-flex min-h-10 items-center gap-2 px-3 py-1"
                            @click="addClient"
                        >
                            <PlusIcon class="h-4 w-4" aria-hidden="true" />
                            Add Customer
                        </button>
                    </header>
                    <div class="fieldops-directory-empty-details__body">
                        <p class="max-w-md text-sm leading-6 text-slate-700">
                            Select a customer to review its contacts, Sites, Jobs, and activity history.
                        </p>
                    </div>
                </section>

            <div class="fieldops-admin-directory-list-column min-w-0 lg:col-start-2" :inert="selectedCustomerIsEditing">
                <header class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 px-1">
                    <h2 class="font-semibold text-gray-900">
                        <span class="text-base">Customer directory</span>
                        <span class="ml-1 text-sm font-normal text-gray-600">({{ customers.total }})</span>
                    </h2>
                    <p v-if="customers.total > 0" class="text-sm text-gray-600">
                        Showing {{ customers.from }}–{{ customers.to }}
                    </p>
                    <p v-if="selectedCustomerIsEditing" class="w-full text-xs text-gray-600">
                        Switch customers to leave this form; unsaved changes will prompt you.
                    </p>
                </header>

                <section class="fieldops-panel grid grid-cols-1 gap-2 bg-white p-3 sm:grid-cols-3" aria-label="Filter and sort customers">
                    <fieldset class="min-w-0 space-y-1">
                        <legend class="text-sm font-medium text-gray-700">Type</legend>
                        <details ref="groupFilterMenu" class="relative" @toggle="handleFilterMenuToggle('group', $event)">
                            <summary class="fieldops-client-input flex min-h-10 cursor-pointer list-none items-center justify-between gap-2 px-3 py-2 text-sm">
                                <span class="truncate">{{ selectionSummary(groupOptions, selectedGroups) }}</span>
                                <span aria-hidden="true">⌄</span>
                            </summary>
                            <div class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-300 bg-white p-2 shadow-lg dark:border-gray-600 dark:bg-gray-800">
                                <label class="flex min-h-10 items-center gap-2 px-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                                    <input type="checkbox" :checked="selectedGroups.length === groupOptions.length" @change="toggleAllGroups" />
                                    All types
                                </label>
                                <label v-for="group in groupOptions" :key="group.value" class="flex min-h-10 items-center gap-2 px-2 text-sm text-gray-700 dark:text-gray-200">
                                    <input v-model="selectedGroups" type="checkbox" :value="group.value" @change="submitSearch" />
                                    {{ group.label }}
                                </label>
                            </div>
                        </details>
                    </fieldset>
                    <fieldset class="min-w-0 space-y-1">
                        <legend class="text-sm font-medium text-gray-700">Status</legend>
                        <details ref="statusFilterMenu" class="relative" @toggle="handleFilterMenuToggle('status', $event)">
                            <summary class="fieldops-client-input flex min-h-10 cursor-pointer list-none items-center justify-between gap-2 px-3 py-2 text-sm">
                                <span class="truncate">{{ selectionSummary(statusOptions, selectedStatuses) }}</span>
                                <span aria-hidden="true">⌄</span>
                            </summary>
                            <div class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-gray-300 bg-white p-2 shadow-lg dark:border-gray-600 dark:bg-gray-800">
                                <label class="flex min-h-10 items-center gap-2 px-2 text-sm font-medium text-gray-800 dark:text-gray-100">
                                    <input type="checkbox" :checked="selectedStatuses.length === statusOptions.length" @change="toggleAllStatuses" />
                                    All
                                </label>
                                <label v-for="status in statusOptions" :key="status.value" class="flex min-h-10 items-center gap-2 px-2 text-sm text-gray-700 dark:text-gray-200">
                                    <input v-model="selectedStatuses" type="checkbox" :value="status.value" @change="submitSearch" />
                                    {{ status.label }}
                                </label>
                            </div>
                        </details>
                    </fieldset>
                    <label class="fieldops-workspace-field min-w-0">
                        <span class="flex items-center gap-1">
                            <ArrowsUpDownIcon class="h-4 w-4" aria-hidden="true" />
                            Sort
                        </span>
                        <select v-model="sortBy" aria-label="Sort customers" class="fieldops-client-input" @focus="closeFilterMenus" @change="submitSearch">
                            <optgroup label="Customer">
                                <option value="name_asc">Name A–Z</option>
                                <option value="name_desc">Name Z–A</option>
                                <option value="client_id_asc">Number low–high</option>
                                <option value="client_id_desc">Number high–low</option>
                                <option value="group">Type</option>
                                <option value="status">Status</option>
                            </optgroup>
                            <optgroup label="Primary contact">
                                <option value="primary_first_asc">First name A–Z</option>
                                <option value="primary_first_desc">First name Z–A</option>
                                <option value="primary_last_asc">Last name A–Z</option>
                                <option value="primary_last_desc">Last name Z–A</option>
                            </optgroup>
                            <optgroup label="Other">
                                <option value="updated_desc">Recently updated</option>
                            </optgroup>
                        </select>
                    </label>
                </section>

                <form
                    role="search"
                    aria-label="Search customers"
                    class="flex min-w-0 items-center gap-2"
                    @submit.prevent="submitSearch"
                >
                    <label for="admin-customer-search" class="sr-only">
                        Search customers, contacts, or sites
                    </label>
                    <ClearableSearchInput
                        id="admin-customer-search"
                        v-model="search"
                        maxlength="100"
                        autocomplete="off"
                        placeholder="Search customers, contacts, or sites"
                        class="fieldops-client-input fieldops-directory-search-input min-w-0 flex-1"
                        clear-label="Clear customer search"
                        :suggestion-types="customerSuggestionTypes"
                        @clear="submitSearch"
                    >
                        <template #action="{ closeSuggestions }">
                            <button type="submit" class="fieldops-clearable-search__action" aria-label="Search directory" title="Search directory" @click="closeSuggestions">
                                <MagnifyingGlassIcon class="h-5 w-5" aria-hidden="true" />
                            </button>
                        </template>
                    </ClearableSearchInput>
                </form>

                <section class="fieldops-panel fieldops-admin-directory-results overflow-hidden bg-gray-50" aria-label="Customer results">
                <div v-if="customers.data.length" class="fieldops-admin-directory-cards space-y-1.5">
                    <button
                        v-for="customer in customers.data"
                        :key="customer.id"
                        type="button"
                        class="grid min-h-[3.5rem] w-full content-center gap-0.5 rounded-lg border border-l-4 border-gray-200 border-l-transparent bg-white px-3 py-1.5 text-right shadow-sm transition-colors hover:border-blue-200 hover:bg-blue-50 hover:shadow-md focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-60 sm:px-4"
                        :class="{ 'border-blue-300 border-l-blue-700 bg-blue-50 shadow-md': selectedCustomerId === customer.id }"
                        data-customer-card
                        :aria-pressed="selectedCustomerId === customer.id"
                        :aria-label="`Open ${customer.name} details. ${customerSecondarySummary(customer)}. Double-click or press and hold to edit.`"
                        :title="`${customerSecondarySummary(customer)} · Double-click or press and hold to edit`"
                        v-on="customerPress(customer.id)"
                        @dblclick.prevent.stop="openCustomerForEditing(customer.id)"
                    >
                        <span class="flex min-w-0 items-center justify-end gap-2">
                            <span class="truncate text-sm font-semibold text-gray-900">{{ customer.name }}</span>
                            <span v-if="customer.clientId" class="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                {{ customer.clientId }}
                            </span>
                        </span>
                        <span class="flex flex-wrap justify-end gap-1 text-[0.65rem] font-medium">
                            <span class="rounded bg-blue-50 px-2 py-0.5 text-blue-800">{{ optionLabel(groupOptions, customer.customerGroup) }}</span>
                            <span class="rounded bg-gray-100 px-2 py-0.5 text-gray-700">{{ optionLabel(statusOptions, customer.customerStatus) }}</span>
                        </span>
                        <span class="grid min-w-0 justify-items-end text-xs leading-4 text-gray-600">
                            <span v-for="detail in customerSecondaryLines(customer)" :key="detail" class="max-w-full truncate text-right">
                                {{ detail }}
                            </span>
                            <span v-if="customerSecondaryLines(customer).length === 0" class="text-gray-500">No company or contact details</span>
                        </span>
                    </button>
                </div>

                <p v-else class="grid flex-1 place-items-center px-5 py-8 text-center text-sm text-gray-600 sm:px-6">
                    <template v-if="filters.showArchivedShortcut">
                        Archived customers are not shown in the active directory.
                        <Link :href="route('admin.archive', { type: 'customers' })" class="ml-1 font-semibold text-blue-800 underline">Open Customer archive to restore one</Link>.
                    </template>
                    <template v-else>
                        {{ filters.q || filters.groups.length < groupOptions.length || filters.statuses.length < statusOptions.length ? "No customers match these filters." : "No customers are available yet." }}
                    </template>
                </p>

                <PaginationChevronNav
                    v-if="customers.last_page > 1"
                    label="Customer pages"
                    :current-page="customers.current_page"
                    :last-page="customers.last_page"
                    :previous-url="customers.prev_page_url"
                    :next-url="customers.next_page_url"
                    preserve-scroll
                />
            </section>
            </div>
            </div>
        </div>

        <Modal
            :show="pendingCustomerNavigation !== null"
            max-width="md"
            :closeable="!savingBeforeNavigation"
            @close="keepEditing"
        >
            <div class="space-y-4 p-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    {{ pendingCustomerCreateNavigation
                        ? "Unsaved Customer details"
                        : pendingCustomerJobNavigation ? "Unsaved Job details" : "Unsaved customer changes" }}
                </h2>
                <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
                    {{ pendingCustomerCreateNavigation
                        ? "Save the new Customer before switching, or discard its details and switch."
                        : pendingCustomerJobNavigation
                            ? "Save the new Job before switching customers, or discard its details and switch."
                            : "Save or discard your changes before leaving this customer." }}
                </p>
                <div class="flex flex-wrap justify-end gap-2">
                    <button type="button" class="fieldops-secondary-action min-h-10" :disabled="savingBeforeNavigation" @click="keepEditing">
                        Keep editing
                    </button>
                    <button type="button" class="fieldops-secondary-action min-h-10" :disabled="savingBeforeNavigation" @click="discardAndNavigate">
                        {{ pendingCustomerCreateNavigation ? "Discard Customer" : pendingCustomerJobNavigation ? "Discard Job" : "Discard changes" }}
                    </button>
                    <button type="button" class="fieldops-submit-action min-h-10" :disabled="savingBeforeNavigation" @click="saveAndNavigate">
                        {{ savingBeforeNavigation ? "Saving…" : pendingCustomerCreateNavigation ? "Save Customer and continue" : pendingCustomerJobNavigation ? "Save Job and continue" : "Save and continue" }}
                    </button>
                </div>
            </div>
        </Modal>
    </AdminLayout>
</template>
