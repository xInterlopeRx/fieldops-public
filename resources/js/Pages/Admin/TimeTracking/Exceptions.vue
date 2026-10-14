<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PaginationChevronNav from "@/Components/Admin/PaginationChevronNav.vue";

interface ExceptionEntry {
    id: number;
    type: "stale_timer" | "aged_unapproved";
    label: string;
    ageMinutes: number;
    startedAt: string;
    date: string;
    durationMinutes: number | null;
    technician: string;
    customer: string;
    project: string | null;
    job: string | null;
    activityLabel: string;
}

interface PaginatedExceptions {
    data: ExceptionEntry[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

interface DashboardMetrics {
    staleTimers: number;
    agedUnapprovedEntries: number;
    exceptions: number;
    staleTimerHours: number;
    unapprovedEntryAgeDays: number;
}

const props = defineProps<{ entries: PaginatedExceptions; metrics: DashboardMetrics }>();

const formatDate = (value: string): string => new Date(value).toLocaleDateString();
const formatTime = (value: string): string => new Date(value).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
const formatHours = (minutes: number | null): string => `${((minutes ?? 0) / 60).toFixed(4)}h`;
const ageLabel = (entry: ExceptionEntry): string => {
    if (entry.type === "stale_timer") {
        const hours = Math.floor(entry.ageMinutes / 60);
        const minutes = entry.ageMinutes % 60;

        return `${hours}h ${minutes}m`;
    }

    const days = Math.floor(entry.ageMinutes / 1440);
    const hours = Math.floor((entry.ageMinutes % 1440) / 60);

    return `${days}d ${hours}h`;
};

const reviewUrl = (entry: ExceptionEntry): string => route("admin.time-tracking.review", {
    date_from: entry.date,
    date_to: entry.date,
    technician: entry.technician,
    status: "draft",
});
</script>

<template>
    <Head title="Time Tracking · Exceptions" />

    <AdminLayout title="Time Tracking · Exceptions">
        <div class="mx-auto max-w-screen-2xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <section class="fieldops-panel space-y-2 bg-white p-4 sm:p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Admin / Time Tracking / Exceptions</p>
                        <h2 class="mt-1 text-lg font-semibold text-gray-900">Items needing attention</h2>
                    </div>
                    <Link :href="route('admin.time-tracking.dashboard')" class="fieldops-secondary-action inline-flex min-h-10 items-center justify-center">Dashboard</Link>
                </div>
                <p class="text-sm text-gray-600">
                    Exceptions are active timers older than {{ metrics.staleTimerHours }} hours and completed Draft entries awaiting review for more than {{ metrics.unapprovedEntryAgeDays }} days.
                </p>
                <div class="flex flex-wrap gap-4 text-sm text-gray-700">
                    <span>Stale timers: <strong>{{ metrics.staleTimers }}</strong></span>
                    <span>Aged Draft entries: <strong>{{ metrics.agedUnapprovedEntries }}</strong></span>
                    <span>Total exceptions: <strong>{{ metrics.exceptions }}</strong></span>
                </div>
            </section>

            <section class="fieldops-panel overflow-hidden bg-white" aria-labelledby="exception-list-title">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-4 sm:px-5">
                    <div>
                        <h2 id="exception-list-title" class="font-semibold text-gray-900">Exceptions</h2>
                        <p class="mt-1 text-sm text-gray-600">
                            <template v-if="entries.total">Showing {{ entries.from }}–{{ entries.to }} of {{ entries.total }}</template>
                            <template v-else>No exceptions</template>
                        </p>
                    </div>
                    <Link :href="route('admin.time-tracking.review')" class="fieldops-secondary-action inline-flex min-h-10 items-center justify-center">Review all entries</Link>
                </div>

                <div v-if="entries.data.length" class="overflow-x-auto">
                    <table class="w-full min-w-[1000px] text-left text-sm">
                        <caption class="sr-only">Stale active timers and aged unapproved time entries</caption>
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-600">
                            <tr>
                                <th scope="col" class="px-3 py-3">Exception</th>
                                <th scope="col" class="px-3 py-3">Technician</th>
                                <th scope="col" class="px-3 py-3">Customer</th>
                                <th scope="col" class="px-3 py-3">Project / Job</th>
                                <th scope="col" class="px-3 py-3">Activity</th>
                                <th scope="col" class="px-3 py-3">Started</th>
                                <th scope="col" class="px-3 py-3">Duration</th>
                                <th scope="col" class="px-3 py-3">Age</th>
                                <th scope="col" class="px-3 py-3"><span class="sr-only">Action</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-for="entry in entries.data" :key="entry.id" class="align-top hover:bg-gray-50">
                                <td class="px-3 py-3">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="entry.type === 'stale_timer' ? 'bg-amber-100 text-amber-900' : 'bg-red-100 text-red-900'">
                                        {{ entry.label }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 font-medium text-gray-900">{{ entry.technician }}</td>
                                <td class="px-3 py-3 text-gray-800">{{ entry.customer }}</td>
                                <td class="px-3 py-3 text-gray-700">{{ [entry.project, entry.job].filter(Boolean).join(" / ") || "—" }}</td>
                                <td class="px-3 py-3 text-gray-700">{{ entry.activityLabel }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-gray-700">{{ formatDate(entry.startedAt) }} · {{ formatTime(entry.startedAt) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 text-gray-700">{{ formatHours(entry.durationMinutes) }}</td>
                                <td class="whitespace-nowrap px-3 py-3 font-medium text-gray-900">{{ ageLabel(entry) }}</td>
                                <td class="px-3 py-3">
                                    <Link :href="reviewUrl(entry)" class="fieldops-secondary-action inline-flex min-h-9 items-center justify-center px-3">Review</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-else class="px-5 py-12 text-center text-sm text-gray-600">No configured time-entry exceptions need attention.</p>

                <PaginationChevronNav
                    v-if="entries.last_page > 1"
                    label="Exception pages"
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
