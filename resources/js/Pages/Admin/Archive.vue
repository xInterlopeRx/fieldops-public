<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { ref } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PaginationChevronNav from "@/Components/Admin/PaginationChevronNav.vue";

interface ArchiveBatch {
    id: string;
    type: string;
    record: { id: number; label: string };
    archived_at: string | null;
    archived_by: string | null;
    reason: string | null;
    record_count: number;
    record_counts: Record<string, number>;
}

interface PaginatedArchiveBatches {
    data: ArchiveBatch[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{
    batches: PaginatedArchiveBatches;
    filters: { type: string };
    types: string[];
    status: string | null;
}>();

const selectedType = ref(props.filters.type);

const typeLabels: Record<string, string> = {
    customers: "Customers",
    sites: "Sites",
    projects: "Projects",
    "work-orders": "Jobs",
    visits: "Visits",
    contacts: "Contacts",
    "customer-addresses": "Customer addresses",
    "project-attachments": "Project attachments",
    "work-order-attachments": "Job attachments",
};

const typeLabel = (type: string): string => typeLabels[type] ?? type;

const submitFilter = (): void => {
    router.get(
        route("admin.archive"),
        { type: selectedType.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const restoreBatch = (batch: ArchiveBatch): void => {
    if (!window.confirm(`Restore “${batch.record.label}” and its archived records?`)) return;

    router.post(route("admin.archive.restore", batch.id), {}, { preserveScroll: true });
};

const formatDate = (value: string | null): string => {
    if (!value) return "Date not available";

    return new Date(value).toLocaleString();
};
</script>

<template>
    <Head title="Archive" />

    <AdminLayout title="Archive">
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <section class="fieldops-panel space-y-4 bg-white p-5 sm:p-6">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Recoverable archive</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Archived records and their files are retained. Restoring a batch restores only the records archived together.
                    </p>
                </div>

                <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="submitFilter">
                    <label for="archive-type-filter" class="grid min-w-0 flex-1 gap-1 text-sm font-medium text-gray-700">
                        Filter by record type
                        <select id="archive-type-filter" v-model="selectedType" class="fieldops-client-input">
                            <option value="">All record types</option>
                            <option v-for="type in types" :key="type" :value="type">
                                {{ typeLabel(type) }}
                            </option>
                        </select>
                    </label>
                    <button type="submit" class="fieldops-submit-action min-h-11 justify-center">
                        Apply filter
                    </button>
                    <Link
                        v-if="filters.type"
                        :href="route('admin.archive')"
                        class="fieldops-secondary-action inline-flex min-h-11 items-center justify-center"
                    >
                        Clear
                    </Link>
                </form>
            </section>

            <p
                v-if="status"
                role="status"
                class="rounded-lg border border-green-500 bg-green-50 px-4 py-3 text-sm font-medium text-green-900"
            >
                {{ status }}
            </p>

            <section class="fieldops-panel overflow-hidden bg-white" aria-label="Archived record batches">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-5 py-4 sm:px-6">
                    <h2 class="font-semibold text-gray-900">
                        Archived batches <span class="ml-1 text-sm font-normal text-gray-600">({{ batches.total }})</span>
                    </h2>
                    <p v-if="batches.total > 0" class="text-sm text-gray-600">
                        Showing {{ batches.from }}–{{ batches.to }}
                    </p>
                </div>

                <div v-if="batches.data.length" class="divide-y divide-gray-200">
                    <article
                        v-for="batch in batches.data"
                        :key="batch.id"
                        class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-6"
                    >
                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <h3 class="break-words font-semibold text-gray-900">{{ batch.record.label }}</h3>
                                <span class="fieldops-status-badge fieldops-status-badge--archive">
                                    {{ typeLabel(batch.type) }}
                                </span>
                            </div>
                            <p class="text-sm text-gray-600">
                                Archived {{ formatDate(batch.archived_at) }}
                                <span v-if="batch.archived_by">by {{ batch.archived_by }}</span>
                            </p>
                            <p class="text-sm text-gray-600">
                                {{ batch.record_count }} {{ batch.record_count === 1 ? "record" : "records" }} in this batch
                            </p>
                            <p v-if="batch.reason" class="break-words text-sm text-gray-700">
                                Reason: {{ batch.reason }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="fieldops-secondary-action min-h-10 shrink-0"
                            @click="restoreBatch(batch)"
                        >
                            Restore batch
                        </button>
                    </article>
                </div>

                <p v-else class="px-5 py-12 text-center text-sm text-gray-600 sm:px-6">
                    No archived batches match this filter.
                </p>

                <PaginationChevronNav
                    v-if="batches.last_page > 1"
                    label="Archive pages"
                    :current-page="batches.current_page"
                    :last-page="batches.last_page"
                    :previous-url="batches.prev_page_url"
                    :next-url="batches.next_page_url"
                    preserve-scroll
                />
            </section>
        </div>
    </AdminLayout>
</template>
