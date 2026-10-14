<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from "vue";
import { MagnifyingGlassIcon, XMarkIcon } from "@heroicons/vue/24/outline";
import {
    useGlobalSearchAutocomplete,
    type GlobalSearchResult,
} from "@/Composables/useGlobalSearchAutocomplete";

export type { GlobalSearchResult } from "@/Composables/useGlobalSearchAutocomplete";

const emit = defineEmits<{
    select: [result: GlobalSearchResult];
}>();

const root = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const query = ref("");
const isExpanded = ref(false);
const {
    results,
    isOpen,
    isLoading,
    activeIndex,
    resultId,
    activeDescendant,
    closeSuggestions,
    openForCurrentQuery,
    handleKeydown: handleAutocompleteKeydown,
    closeIfOutside: closeAutocompleteIfOutside,
} = useGlobalSearchAutocomplete(query, "fieldops-global-search-option");

const expandSearch = async (): Promise<void> => {
    isExpanded.value = true;
    await nextTick();
    input.value?.focus();
};

const clearSearch = async (): Promise<void> => {
    query.value = "";
    closeSuggestions();
    await nextTick();
    input.value?.focus();
};

const closeIfOutside = (event: PointerEvent): void => {
    const isOutside = root.value !== null && !root.value.contains(event.target as Node);
    closeAutocompleteIfOutside(event, root.value);

    if (isOutside && query.value.trim() === "") isExpanded.value = false;
};

onMounted(() => {
    document.addEventListener("pointerdown", closeIfOutside);
});

onBeforeUnmount(() => {
    document.removeEventListener("pointerdown", closeIfOutside);
});

const selectResult = (result: GlobalSearchResult): void => {
    emit("select", result);
    query.value = "";
    results.value = [];
    closeSuggestions();
    isExpanded.value = false;
};

const selectTopResult = (): void => {
    const result = results.value[activeIndex.value] ?? results.value[0];

    if (isOpen.value && result) {
        selectResult(result);
    }
};

const handleKeydown = (event: KeyboardEvent): void => {
    if (event.key === "Escape" && !isOpen.value && isExpanded.value) {
        event.preventDefault();
        isExpanded.value = false;
        input.value?.blur();

        return;
    }

    handleAutocompleteKeydown(event, selectResult);
};

const resultTypeLabel = (type: GlobalSearchResult["type"]): string => {
    switch (type) {
        case "client":
            return "Customer";
        case "job":
            return "Job";
        case "visit":
            return "Visit";
        default:
            return "Project";
    }
};

const isExactMatch = (result: GlobalSearchResult): boolean => {
    const term = query.value.trim().toLocaleLowerCase();

    return [result.name, result.reference]
        .filter((value): value is string => value !== null)
        .some((value) => value.trim().toLocaleLowerCase() === term);
};
</script>

<template>
    <div
        ref="root"
        class="fieldops-global-search"
        :class="{ 'fieldops-global-search--expanded': isExpanded }"
    >
        <button
            v-if="!isExpanded"
            type="button"
            class="fieldops-global-search__toggle"
            aria-label="Open global search"
            title="Search everything"
            aria-controls="fieldops-global-search-input"
            :aria-expanded="isExpanded"
            @click="expandSearch"
        >
            <MagnifyingGlassIcon class="fieldops-global-search__toggle-icon" aria-hidden="true" />
        </button>
        <form v-else role="search" aria-label="Search all FieldOps records" @submit.prevent="selectTopResult">
            <label for="fieldops-global-search-input" class="sr-only">
                Search customers, projects, jobs, and visits
            </label>
            <div class="fieldops-global-search__field">
                <MagnifyingGlassIcon
                    class="fieldops-global-search__icon"
                    aria-hidden="true"
                />
                <input
                    ref="input"
                    id="fieldops-global-search-input"
                    v-model="query"
                    type="search"
                    maxlength="100"
                    autocomplete="off"
                    spellcheck="false"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="fieldops-global-search-suggestions"
                    :aria-expanded="isOpen"
                    :aria-activedescendant="activeDescendant()"
                    placeholder="Search everything..."
                    class="fieldops-client-input fieldops-global-search__input"
                    @focus="openForCurrentQuery"
                    @keydown="handleKeydown"
                />
                <button
                    v-if="query.length > 0"
                    type="button"
                    class="fieldops-global-search__clear"
                    aria-label="Clear global search"
                    title="Clear search"
                    @click="clearSearch"
                >
                    <XMarkIcon class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </form>

        <div
            v-if="isOpen"
            id="fieldops-global-search-suggestions"
            class="fieldops-global-search__suggestions"
            role="listbox"
            aria-label="Search suggestions"
            aria-live="polite"
        >
            <p v-if="isLoading" class="fieldops-global-search__message">
                Searching…
            </p>
            <p
                v-else-if="results.length === 0"
                class="fieldops-global-search__message"
            >
                No matches found.
            </p>
            <button
                v-for="(result, index) in results"
                :id="resultId(index)"
                :key="`${result.type}-${result.id}`"
                type="button"
                role="option"
                :aria-selected="activeIndex === index"
                class="fieldops-global-search__option"
                :class="{
                    'fieldops-global-search__option--active': activeIndex === index,
                }"
                @mousedown.prevent
                @mouseenter="activeIndex = index"
                @click.stop="selectResult(result)"
            >
                <span class="fieldops-global-search__option-copy">
                    <span class="fieldops-global-search__option-heading">
                        <strong>{{ result.name }}</strong>
                        <span class="fieldops-global-search__type">
                            {{ resultTypeLabel(result.type) }}
                        </span>
                    </span>
                    <span class="fieldops-global-search__detail">
                        <span v-if="result.reference">{{ result.reference }}</span>
                        <span v-if="result.reference && result.detail" aria-hidden="true"> · </span>
                        <span v-if="result.detail">{{ result.detail }}</span>
                    </span>
                </span>
                <span
                    v-if="isExactMatch(result)"
                    class="fieldops-global-search__exact"
                >
                    Exact
                </span>
            </button>
        </div>
    </div>
</template>
