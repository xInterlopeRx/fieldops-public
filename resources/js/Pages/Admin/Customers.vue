<script setup lang="ts">
import { Head, router } from "@inertiajs/vue3";
import { onBeforeUnmount, onMounted, ref } from "vue";
import { MagnifyingGlassIcon } from "@heroicons/vue/24/outline";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import CustomerWorkspace from "@/Components/Admin/CustomerWorkspace.vue";
import PaginationChevronNav from "@/Components/Admin/PaginationChevronNav.vue";
import { usePressAndHold } from "@/Composables/usePressAndHold";

interface AdminCustomer {
    id: number;
    clientId: string | null;
    name: string;
    companyName: string | null;
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

const props = defineProps<{
    customers: PaginatedCustomers;
    filters: { q: string };
}>();

const search = ref(props.filters.q);
const selectedCustomerId = ref<number | null>(null);
const selectedCustomerIsEditing = ref(false);
const selectedCustomerEditRequestId = ref(0);
const selectedCustomerEditTargetId = ref<number | null>(null);

const submitSearch = (): void => {
    router.get(
        route("admin.customers"),
        { q: search.value.trim() || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const refreshDirectory = (): void => {
    router.reload({ only: ["customers"] });
};

const openCustomer = (customerId: number): void => {
    if (selectedCustomerIsEditing.value && selectedCustomerId.value !== customerId) return;

    selectedCustomerEditTargetId.value = null;
    selectedCustomerId.value = customerId;
};

const openCustomerForEditing = (customerId: number): void => {
    if (selectedCustomerIsEditing.value && selectedCustomerId.value !== customerId) return;

    selectedCustomerId.value = customerId;
    selectedCustomerEditTargetId.value = customerId;
    selectedCustomerEditRequestId.value += 1;
};

const customerPress = usePressAndHold(openCustomer, openCustomerForEditing);

const handleCustomerSearchResult = (event: Event): void => {
    const customerId = Number((event as CustomEvent<number>).detail);

    if (Number.isSafeInteger(customerId) && customerId > 0) openCustomer(customerId);
};

onMounted(() => {
    window.addEventListener("fieldops:customer-search-result-selected", handleCustomerSearchResult);

    const requestedCustomer = Number(new URLSearchParams(window.location.search).get("open_customer"));

    if (Number.isSafeInteger(requestedCustomer) && requestedCustomer > 0) openCustomer(requestedCustomer);
});

onBeforeUnmount(() => window.removeEventListener("fieldops:customer-search-result-selected", handleCustomerSearchResult));

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

const closeCustomerWorkspace = (): void => {
    selectedCustomerId.value = null;
    selectedCustomerIsEditing.value = false;
    selectedCustomerEditRequestId.value = 0;
    selectedCustomerEditTargetId.value = null;
};
</script>

<template>
    <Head title="Customers" />

    <AdminLayout title="Customers">
        <div class="fieldops-admin-directory-page mx-auto max-w-7xl space-y-4 px-4 py-5 sm:px-6 lg:px-8">
            <div class="fieldops-admin-directory-grid grid items-stretch gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(19rem,0.85fr)]">
                <CustomerWorkspace
                    v-if="selectedCustomerId !== null"
                    :key="selectedCustomerId"
                    :customer-id="selectedCustomerId"
                    :edit-request-id="selectedCustomerEditTargetId === selectedCustomerId ? selectedCustomerEditRequestId : 0"
                    :can-archive="$page.props.auth.is_admin"
                    :inline-on-desktop="true"
                    @close="closeCustomerWorkspace"
                    @editing-change="selectedCustomerIsEditing = $event"
                    @saved="refreshDirectory"
                    @archived="refreshDirectory"
                />

                <section v-else class="fieldops-directory-empty-details hidden lg:flex" aria-label="Customer details">
                    <header class="fieldops-workspace-fixed-header">
                        <h2 class="fieldops-workspace-title">Customer details</h2>
                    </header>
                    <div class="fieldops-directory-empty-details__body">
                        <p class="max-w-md text-sm leading-6 text-slate-700">
                            Select a customer to review its contacts, Sites, Jobs, and activity history.
                        </p>
                    </div>
                </section>

            <div class="fieldops-admin-directory-list-column min-w-0 lg:col-start-2" :inert="selectedCustomerIsEditing">
                <section class="fieldops-panel bg-white p-2">
                    <form
                        role="search"
                        aria-label="Search customers"
                        class="flex items-center gap-2"
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
                            @clear="submitSearch"
                        />
                        <button
                            type="submit"
                            class="fieldops-submit-action fieldops-directory-search-button fieldops-directory-search-button--icon"
                            aria-label="Search customers"
                            title="Search customers"
                        >
                            <MagnifyingGlassIcon class="h-4 w-4" aria-hidden="true" />
                        </button>
                    </form>
                </section>

            <section
                class="fieldops-panel fieldops-admin-directory-results overflow-hidden bg-gray-50"
                aria-label="Customer results"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 border-b border-gray-200 px-4 py-2"
                >
                    <h2 class="font-semibold text-gray-900">
                        <span class="text-base">Customer directory</span>
                        <span class="ml-1 text-sm font-normal text-gray-600">
                            ({{ customers.total }})
                        </span>
                    </h2>
                    <p
                        v-if="customers.total > 0"
                        class="text-sm text-gray-600"
                    >
                        Showing {{ customers.from }}–{{ customers.to }}
                    </p>
                    <p v-if="selectedCustomerIsEditing" class="w-full text-xs text-gray-600">
                        Cancel or submit editing before switching customers.
                    </p>
                </div>

                <div v-if="customers.data.length" class="fieldops-admin-directory-cards space-y-1.5">
                    <button
                        v-for="customer in customers.data"
                        :key="customer.id"
                        type="button"
                        class="grid min-h-[3.5rem] w-full content-center gap-0.5 rounded-lg border border-l-4 border-gray-200 border-l-transparent bg-white px-3 py-1.5 text-right shadow-sm transition-colors hover:border-blue-200 hover:bg-blue-50 hover:shadow-md focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-blue-700 disabled:cursor-not-allowed disabled:opacity-60 sm:px-4"
                        :class="{ 'border-blue-300 border-l-blue-700 bg-blue-50 shadow-md': selectedCustomerId === customer.id }"
                        :disabled="selectedCustomerIsEditing && selectedCustomerId !== customer.id"
                        :aria-pressed="selectedCustomerId === customer.id"
                        :aria-label="`Open ${customer.name} details. ${customerSecondarySummary(customer)}. Press and hold to edit.`"
                        :title="`${customerSecondarySummary(customer)} · Press and hold to edit`"
                        v-on="customerPress(customer.id)"
                    >
                        <span class="flex min-w-0 items-center justify-end gap-2">
                            <span class="truncate text-sm font-semibold text-gray-900">{{ customer.name }}</span>
                            <span v-if="customer.clientId" class="shrink-0 rounded bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                                {{ customer.clientId }}
                            </span>
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
                    {{ filters.q ? "No customers match that search." : "No customers are available yet." }}
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
    </AdminLayout>
</template>
