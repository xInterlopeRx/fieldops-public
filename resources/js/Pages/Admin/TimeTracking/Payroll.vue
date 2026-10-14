<script setup lang="ts">
import { Head, Link, router } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";

interface PayrollEntry {
    id: number;
    date: string;
    startAt: string;
    endAt: string;
    customer: string;
    project: string | null;
    job: string | null;
    activityType: string;
    activityLabel: string;
    billable: boolean;
    durationHours: number;
    regularHours: number;
    overtimeHours: number;
    totalHours: number;
}

interface PayrollDay {
    date: string;
    regularHours: number;
    overtimeHours: number;
    totalHours: number;
    entries: PayrollEntry[];
}

interface TechnicianPayroll {
    id: number;
    name: string;
    regularHours: number;
    overtimeHours: number;
    totalHours: number;
    days: PayrollDay[];
}

interface PayrollReportData {
    dateFrom: string;
    dateTo: string;
    timezone: string;
    includedApprovalStatus: string;
    excludedEntriesCount: number;
    overtimeThresholdHours: number;
    workweekStartsOn: string;
    workweekEndsOn: string;
    technicians: TechnicianPayroll[];
    totals: { regularHours: number; overtimeHours: number; totalHours: number };
}

const props = defineProps<{
    report: PayrollReportData;
    filters: { date_from: string; date_to: string };
}>();

const dateFrom = ref(props.filters.date_from);
const dateTo = ref(props.filters.date_to);
const actionError = ref("");
const expandedTechnicians = ref<number[]>([]);
const hasTechnicians = computed(() => props.report.technicians.length > 0);

const applyDates = (): void => {
    actionError.value = "";
    router.get(route("admin.time-tracking.payroll"), {
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onError: (errors) => {
            actionError.value = Object.values(errors).flat().join(" ") || "Choose a valid payroll date range.";
        },
    });
};

const exportUrl = (format: "csv" | "excel"): string => route(
    `admin.time-tracking.payroll.export.${format}`,
    { date_from: props.filters.date_from, date_to: props.filters.date_to },
);

const toggleTechnician = (technicianId: number): void => {
    expandedTechnicians.value = expandedTechnicians.value.includes(technicianId)
        ? expandedTechnicians.value.filter((id) => id !== technicianId)
        : [...expandedTechnicians.value, technicianId];
};

const isExpanded = (technicianId: number): boolean => expandedTechnicians.value.includes(technicianId);
const formatHours = (hours: number): string => hours.toFixed(4);
const formatThreshold = (hours: number): string => Number(hours).toString();
const formatDate = (value: string): string => new Date(`${value}T00:00:00`).toLocaleDateString();
const formatTime = (value: string): string => new Date(value).toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
</script>

<template>
    <Head title="Time Tracking · Payroll" />

    <AdminLayout title="Time Tracking · Payroll">
        <div class="mx-auto max-w-screen-2xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <section class="fieldops-panel space-y-4 bg-white p-4 sm:p-5" aria-labelledby="payroll-range-title">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Admin / Time Tracking / Payroll</p>
                        <h2 id="payroll-range-title" class="mt-1 text-lg font-semibold text-gray-900">Payroll date range</h2>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a :href="exportUrl('csv')" class="fieldops-secondary-action inline-flex min-h-10 items-center justify-center">Export CSV</a>
                        <a :href="exportUrl('excel')" class="fieldops-submit-action inline-flex min-h-10 items-center justify-center">Export Excel (.xls)</a>
                    </div>
                </div>

                <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end" @submit.prevent="applyDates">
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Date from
                        <input v-model="dateFrom" type="date" required class="fieldops-client-input" />
                    </label>
                    <label class="grid gap-1 text-sm font-medium text-gray-800">
                        Date to
                        <input v-model="dateTo" type="date" required :min="dateFrom || undefined" class="fieldops-client-input" />
                    </label>
                    <button type="submit" class="fieldops-submit-action min-h-10">Run report</button>
                </form>

                <p v-if="actionError" role="alert" class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ actionError }}</p>
                <p class="text-sm text-gray-600">
                    Approved entries only. Regular time is the first {{ formatThreshold(report.overtimeThresholdHours) }} hours per
                    {{ report.workweekStartsOn }}–{{ report.workweekEndsOn }} workweek;
                    overtime is additional time. Date ranges use complete workweeks for classification and include only selected dates in totals.
                    Workweek timezone: {{ report.timezone }}.
                </p>
                <p class="text-xs text-gray-500">
                    Report range: {{ formatDate(report.dateFrom) }}–{{ formatDate(report.dateTo) }}. Exports match the displayed report; run the report after changing dates.
                </p>
                <p v-if="report.excludedEntriesCount > 0" role="status" class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    {{ report.excludedEntriesCount }} {{ report.excludedEntriesCount === 1 ? "completed entry is" : "completed entries are" }} excluded because the status is not Approved.
                </p>
            </section>

            <section class="grid gap-3 sm:grid-cols-3" aria-label="Payroll totals">
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Regular Hours</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ formatHours(report.totals.regularHours) }}</p>
                </article>
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Overtime Hours</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ formatHours(report.totals.overtimeHours) }}</p>
                </article>
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Total Hours</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ formatHours(report.totals.totalHours) }}</p>
                </article>
            </section>

            <section class="fieldops-panel overflow-hidden bg-white" aria-labelledby="payroll-technicians-title">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-4 py-4 sm:px-5">
                    <div>
                        <h2 id="payroll-technicians-title" class="font-semibold text-gray-900">Hours by technician</h2>
                        <p class="mt-1 text-sm text-gray-600">Select a technician to expand daily totals and approved time-entry detail.</p>
                    </div>
                    <span class="text-sm text-gray-600">{{ report.technicians.length }} technician{{ report.technicians.length === 1 ? "" : "s" }}</span>
                </div>

                <div v-if="hasTechnicians" class="overflow-x-auto">
                    <table class="w-full min-w-[760px] border-collapse text-left text-sm">
                        <caption class="sr-only">Payroll totals grouped by technician</caption>
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">Technician</th>
                                <th scope="col" class="px-4 py-3 text-right">Regular Hours</th>
                                <th scope="col" class="px-4 py-3 text-right">Overtime Hours</th>
                                <th scope="col" class="px-4 py-3 text-right">Total Hours</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Details</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <template v-for="technician in report.technicians" :key="technician.id">
                                <tr class="hover:bg-gray-50">
                                    <th scope="row" class="px-4 py-3 font-semibold text-gray-900">{{ technician.name }}</th>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ formatHours(technician.regularHours) }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ formatHours(technician.overtimeHours) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatHours(technician.totalHours) }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <button
                                            type="button"
                                            class="fieldops-secondary-action min-h-9 px-3"
                                            :aria-expanded="isExpanded(technician.id)"
                                            :aria-controls="`payroll-details-${technician.id}`"
                                            @click="toggleTechnician(technician.id)"
                                        >
                                            {{ isExpanded(technician.id) ? "Hide details" : "Details" }}
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="isExpanded(technician.id)" :id="`payroll-details-${technician.id}`">
                                    <td colspan="5" class="bg-gray-50 px-3 py-4 sm:px-5">
                                        <div class="grid gap-5 xl:grid-cols-[minmax(15rem,0.7fr)_minmax(0,1.3fr)]">
                                            <section class="space-y-2" :aria-label="`${technician.name} daily totals`">
                                                <h3 class="font-semibold text-gray-900">Daily totals</h3>
                                                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                                    <table class="w-full min-w-[480px] text-left text-xs">
                                                        <thead class="bg-gray-100 text-gray-600">
                                                            <tr>
                                                                <th scope="col" class="px-3 py-2">Date</th>
                                                                <th scope="col" class="px-3 py-2 text-right">Regular</th>
                                                                <th scope="col" class="px-3 py-2 text-right">Overtime</th>
                                                                <th scope="col" class="px-3 py-2 text-right">Total</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-gray-100">
                                                            <tr v-for="day in technician.days" :key="day.date">
                                                                <td class="whitespace-nowrap px-3 py-2">{{ formatDate(day.date) }}</td>
                                                                <td class="px-3 py-2 text-right tabular-nums">{{ formatHours(day.regularHours) }}</td>
                                                                <td class="px-3 py-2 text-right tabular-nums">{{ formatHours(day.overtimeHours) }}</td>
                                                                <td class="px-3 py-2 text-right font-semibold tabular-nums">{{ formatHours(day.totalHours) }}</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </section>

                                            <section class="min-w-0 space-y-2" :aria-label="`${technician.name} time entry breakdown`">
                                                <h3 class="font-semibold text-gray-900">Time entry breakdown</h3>
                                                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                                                    <table class="w-full min-w-[1080px] text-left text-xs">
                                                        <thead class="bg-gray-100 text-gray-600">
                                                            <tr>
                                                                <th scope="col" class="px-3 py-2">Date / time</th>
                                                                <th scope="col" class="px-3 py-2">Customer</th>
                                                                <th scope="col" class="px-3 py-2">Project / Job</th>
                                                                <th scope="col" class="px-3 py-2">Activity</th>
                                                                <th scope="col" class="px-3 py-2">Billable</th>
                                                                <th scope="col" class="px-3 py-2 text-right">Entry</th>
                                                                <th scope="col" class="px-3 py-2 text-right">Regular</th>
                                                                <th scope="col" class="px-3 py-2 text-right">Overtime</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody class="divide-y divide-gray-100">
                                                            <tr v-for="entry in technician.days.flatMap((day) => day.entries)" :key="`${entry.id}-${entry.date}`">
                                                                <td class="whitespace-nowrap px-3 py-2">{{ formatDate(entry.date) }} · {{ formatTime(entry.startAt) }}–{{ formatTime(entry.endAt) }}</td>
                                                                <td class="max-w-40 truncate px-3 py-2">{{ entry.customer }}</td>
                                                                <td class="max-w-56 truncate px-3 py-2">{{ [entry.project, entry.job].filter(Boolean).join(" / ") || "—" }}</td>
                                                                <td class="whitespace-nowrap px-3 py-2">{{ entry.activityLabel }}</td>
                                                                <td class="px-3 py-2">{{ entry.billable ? "Yes" : "No" }}</td>
                                                                <td class="px-3 py-2 text-right tabular-nums">{{ formatHours(entry.durationHours) }}</td>
                                                                <td class="px-3 py-2 text-right tabular-nums">{{ formatHours(entry.regularHours) }}</td>
                                                                <td class="px-3 py-2 text-right tabular-nums">{{ formatHours(entry.overtimeHours) }}</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </section>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p v-else class="px-5 py-12 text-center text-sm text-gray-600">
                    No Approved time entries overlap this date range.
                </p>
            </section>
        </div>
    </AdminLayout>
</template>
