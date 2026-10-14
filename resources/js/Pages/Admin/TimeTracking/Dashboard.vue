<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";

interface DashboardMetrics {
    unapprovedHours: number;
    approvedHours: number;
    billableHours: number;
    unbilledHours: number;
    activeTimers: number;
    exceptions: number;
    staleTimers: number;
    agedUnapprovedEntries: number;
    staleTimerHours: number;
    unapprovedEntryAgeDays: number;
}

const props = defineProps<{ metrics: DashboardMetrics }>();

const formatHours = (hours: number): string => hours.toFixed(4);
</script>

<template>
    <Head title="Time Tracking Dashboard" />

    <AdminLayout title="Time Tracking Dashboard">
        <div class="mx-auto max-w-screen-xl space-y-5 px-4 py-6 sm:px-6 lg:px-8">
            <section class="fieldops-panel space-y-2 bg-white p-4 sm:p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Admin / Time Tracking</p>
                <h2 class="text-lg font-semibold text-gray-900">Operational overview</h2>
                <p class="text-sm text-gray-600">
                    Hours cover completed entries. Approved Hours include entries later marked Invoiced or Paid;
                    Unapproved Hours exclude running timers. Billable Hours include completed Billable entries, and
                    Unbilled Hours are Billable entries still Approved.
                </p>
            </section>

            <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" aria-label="Time tracking summary metrics">
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Unapproved Hours</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ formatHours(metrics.unapprovedHours) }}</p>
                    <p class="mt-1 text-xs text-gray-500">Completed Draft entries</p>
                </article>
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Approved Hours</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ formatHours(metrics.approvedHours) }}</p>
                    <p class="mt-1 text-xs text-gray-500">Approved, Invoiced, and Paid entries</p>
                </article>
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Billable Hours</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ formatHours(metrics.billableHours) }}</p>
                    <p class="mt-1 text-xs text-gray-500">Completed Billable entries</p>
                </article>
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Unbilled Hours</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ formatHours(metrics.unbilledHours) }}</p>
                    <p class="mt-1 text-xs text-gray-500">Billable entries still Approved</p>
                </article>
                <article class="fieldops-panel bg-white p-4 sm:p-5">
                    <h2 class="text-sm font-medium text-gray-600">Active Timers</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ metrics.activeTimers }}</p>
                    <p class="mt-1 text-xs text-gray-500">Entries without an end time</p>
                </article>
                <Link
                    :href="route('admin.time-tracking.exceptions')"
                    class="fieldops-panel block bg-white p-4 transition hover:border-amber-300 hover:bg-amber-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700 sm:p-5"
                    :aria-label="`View ${metrics.exceptions} time tracking exceptions`"
                >
                    <h2 class="text-sm font-medium text-gray-600">Exceptions</h2>
                    <p class="mt-2 text-2xl font-bold tabular-nums text-gray-900">{{ metrics.exceptions }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ metrics.staleTimers }} stale timers · {{ metrics.agedUnapprovedEntries }} aged Draft entries</p>
                </Link>
            </section>

            <section class="fieldops-panel space-y-3 bg-white p-4 sm:p-5" aria-labelledby="time-tracking-links-title">
                <div>
                    <h2 id="time-tracking-links-title" class="font-semibold text-gray-900">Quick links</h2>
                    <p class="mt-1 text-sm text-gray-600">Open a time-entry work area.</p>
                </div>
                <nav aria-label="Time tracking quick links" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Link :href="route('admin.time-tracking.review')" class="fieldops-secondary-action flex min-h-12 items-center justify-between gap-3 px-4">
                        <span>Review</span><span aria-hidden="true">→</span>
                    </Link>
                    <Link :href="route('admin.time-tracking.payroll')" class="fieldops-secondary-action flex min-h-12 items-center justify-between gap-3 px-4">
                        <span>Payroll</span><span aria-hidden="true">→</span>
                    </Link>
                    <Link :href="route('admin.time-tracking.billing')" class="fieldops-secondary-action flex min-h-12 items-center justify-between gap-3 px-4">
                        <span>Billing</span><span aria-hidden="true">→</span>
                    </Link>
                    <Link :href="route('admin.time-tracking.exceptions')" class="fieldops-secondary-action flex min-h-12 items-center justify-between gap-3 px-4">
                        <span>Exceptions</span><span aria-hidden="true">→</span>
                    </Link>
                </nav>
            </section>
        </div>
    </AdminLayout>
</template>
