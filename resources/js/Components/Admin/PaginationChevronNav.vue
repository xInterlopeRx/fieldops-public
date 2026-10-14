<script setup lang="ts">
import { Link } from "@inertiajs/vue3";
import { ChevronLeftIcon, ChevronRightIcon } from "@heroicons/vue/24/outline";

const props = withDefaults(defineProps<{
    label: string;
    currentPage: number;
    lastPage: number;
    previousUrl: string | null;
    nextUrl: string | null;
    summary?: string;
    preserveScroll?: boolean;
}>(), {
    summary: "",
    preserveScroll: false,
});

const pageSummary = (): string =>
    props.summary || `Page ${props.currentPage} of ${props.lastPage}`;
</script>

<template>
    <nav class="fieldops-pagination" :aria-label="label">
        <Link
            v-if="previousUrl"
            :href="previousUrl"
            :preserve-scroll="preserveScroll"
            class="fieldops-pagination-chevron"
            aria-label="Previous page"
            title="Previous page"
        >
            <ChevronLeftIcon class="h-6 w-6" aria-hidden="true" />
        </Link>
        <button
            v-else
            type="button"
            class="fieldops-pagination-chevron fieldops-pagination-chevron--disabled"
            aria-label="No previous page"
            disabled
        >
            <ChevronLeftIcon class="h-6 w-6" aria-hidden="true" />
        </button>

        <span class="fieldops-pagination-summary" aria-live="polite">
            {{ pageSummary() }}
        </span>

        <Link
            v-if="nextUrl"
            :href="nextUrl"
            :preserve-scroll="preserveScroll"
            class="fieldops-pagination-chevron"
            aria-label="Next page"
            title="Next page"
        >
            <ChevronRightIcon class="h-6 w-6" aria-hidden="true" />
        </Link>
        <button
            v-else
            type="button"
            class="fieldops-pagination-chevron fieldops-pagination-chevron--disabled"
            aria-label="No next page"
            disabled
        >
            <ChevronRightIcon class="h-6 w-6" aria-hidden="true" />
        </button>
    </nav>
</template>
