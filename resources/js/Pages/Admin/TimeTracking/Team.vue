<script setup lang="ts">
import { Head } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PaginationChevronNav from "@/Components/Admin/PaginationChevronNav.vue";

interface TimeEntryRow {
    id: number;
    startedAt: string;
    durationMinutes: number | null;
    isRunning: boolean;
    isOwn: boolean;
    technician: string;
    customer: string;
    project: string | null;
    job: string | null;
    activityLabel: string;
    statusLabel: string;
}

interface PaginatedEntries {
    data: TimeEntryRow[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

defineProps<{ entries: PaginatedEntries }>();

const startedAtLabel = (value: string): string =>
    new Date(value).toLocaleString(undefined, {
        weekday: "short",
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
    });

const durationLabel = (minutes: number | null, running: boolean): string => {
    if (running) return "In progress";
    if (minutes === null) return "—";

    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;

    return hours > 0
        ? `${hours}h ${remainingMinutes}m`
        : `${remainingMinutes}m`;
};
</script>

<template>
    <Head title="Team Timesheets" />

    <AdminLayout title="Team Timesheets">
        <div class="mx-auto max-w-screen-xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <section class="fieldops-panel space-y-2 bg-white p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Operations / Time Tracking</p>
                <h2 class="text-lg font-semibold text-gray-900">Team timesheets</h2>
                <p class="text-sm text-gray-600">
                    Read-only entries for you and teammates who share assigned work. Financial and compensation details are not shown.
                    Contact HR to request a correction.
                </p>
            </section>

            <section class="fieldops-panel overflow-hidden bg-white" aria-label="Team timesheet entries">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-fieldops-blue/10 px-4 py-3 sm:px-5">
                    <h2 class="font-semibold text-gray-900">Time entries</h2>
                    <p class="text-sm text-gray-600">{{ entries.total }} entries</p>
                </div>

                <div v-if="entries.data.length" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">Team member</th>
                                <th scope="col" class="px-4 py-3">Started</th>
                                <th scope="col" class="px-4 py-3">Activity</th>
                                <th scope="col" class="px-4 py-3">Work</th>
                                <th scope="col" class="px-4 py-3">Duration</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="entry in entries.data" :key="entry.id" class="align-top">
                                <td class="px-4 py-3 font-medium text-gray-900">
                                    {{ entry.technician }}
                                    <span v-if="entry.isOwn" class="ml-1 text-xs font-normal text-gray-500">(you)</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ startedAtLabel(entry.startedAt) }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ entry.activityLabel }}</td>
                                <td class="min-w-48 px-4 py-3 text-gray-700">
                                    <span class="block">{{ entry.job || entry.project || entry.customer }}</span>
                                    <span v-if="entry.job && entry.project" class="mt-0.5 block text-xs text-gray-500">{{ entry.project }}</span>
                                    <span v-else-if="entry.job || entry.project" class="mt-0.5 block text-xs text-gray-500">{{ entry.customer }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 tabular-nums text-gray-700">{{ durationLabel(entry.durationMinutes, entry.isRunning) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-gray-700">{{ entry.statusLabel }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="px-5 py-12 text-center text-sm text-gray-600">
                    No time entries are available for your assigned team yet.
                </p>

                <PaginationChevronNav
                    v-if="entries.last_page > 1"
                    label="Team timesheet pages"
                    :current-page="entries.current_page"
                    :last-page="entries.last_page"
                    :previous-url="entries.prev_page_url"
                    :next-url="entries.next_page_url"
                    preserve-scroll
                />
            </section>
        </div>
    </AdminLayout>
</template>
