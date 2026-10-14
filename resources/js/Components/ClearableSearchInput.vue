<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from "vue";
import { XMarkIcon } from "@heroicons/vue/24/outline";
import {
    useGlobalSearchAutocomplete,
    type GlobalSearchResult,
    type GlobalSearchResultType,
} from "@/Composables/useGlobalSearchAutocomplete";

defineOptions({ inheritAttrs: false });

const props = withDefaults(defineProps<{
    id: string;
    modelValue: string;
    clearLabel?: string;
    suggestionTypes?: GlobalSearchResultType[];
    dispatchSelection?: boolean;
}>(), {
    clearLabel: "Clear search",
    dispatchSelection: true,
});

const emit = defineEmits<{
    "update:modelValue": [value: string];
    clear: [];
    select: [result: GlobalSearchResult];
}>();

const root = ref<HTMLElement | null>(null);
const input = ref<HTMLInputElement | null>(null);
const query = computed({
    get: () => props.modelValue,
    set: (value: string) => emit("update:modelValue", value),
});
const suggestionsId = `${props.id}-suggestions`;
const {
    results,
    isOpen,
    isLoading,
    activeIndex,
    resultId,
    activeDescendant,
    closeSuggestions,
    openForCurrentQuery,
    handleKeydown,
    closeIfOutside,
} = useGlobalSearchAutocomplete(query, `${props.id}-option`, props.suggestionTypes);

const onPointerDown = (event: PointerEvent): void => closeIfOutside(event, root.value);

onMounted(() => document.addEventListener("pointerdown", onPointerDown));

onBeforeUnmount(() => document.removeEventListener("pointerdown", onPointerDown));

const updateValue = (event: Event): void => {
    emit("update:modelValue", (event.target as HTMLInputElement).value);
};

const clear = (): void => {
    query.value = "";
    emit("clear");
    input.value?.focus();
};

const selectResult = (result: GlobalSearchResult): void => {
    emit("select", result);
    query.value = "";
    closeSuggestions();

    if (props.dispatchSelection) {
        window.dispatchEvent(new CustomEvent("fieldops:global-search-requested", { detail: result }));
    }
};

const handleInputKeydown = (event: KeyboardEvent): void => handleKeydown(event, selectResult);

const resultTypeLabel = (type: GlobalSearchResultType): string => {
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
    <span ref="root" class="fieldops-clearable-search">
        <input
            v-bind="$attrs"
            :id="props.id"
            type="search"
            maxlength="100"
            :value="props.modelValue"
            class="fieldops-clearable-search__input"
            role="combobox"
            aria-autocomplete="list"
            :aria-controls="suggestionsId"
            :aria-expanded="isOpen"
            :aria-activedescendant="activeDescendant()"
            @input="updateValue"
            @focus="openForCurrentQuery"
            @keydown="handleInputKeydown"
        />
        <button
            v-if="props.modelValue.length > 0"
            type="button"
            class="fieldops-clearable-search__clear"
            :aria-label="props.clearLabel"
            title="Clear search"
            @click="clear"
        >
            <XMarkIcon class="h-4 w-4" aria-hidden="true" />
        </button>
        <div
            v-if="isOpen"
            :id="suggestionsId"
            class="fieldops-global-search__suggestions fieldops-clearable-search__suggestions"
            role="listbox"
            aria-label="Search suggestions"
            aria-live="polite"
        >
            <p v-if="isLoading" class="fieldops-global-search__message">Searching…</p>
            <p v-else-if="results.length === 0" class="fieldops-global-search__message">No matches found.</p>
            <button
                v-for="(result, index) in results"
                :id="resultId(index)"
                :key="`${result.type}-${result.id}`"
                type="button"
                role="option"
                :aria-selected="activeIndex === index"
                class="fieldops-global-search__option"
                :class="{ 'fieldops-global-search__option--active': activeIndex === index }"
                @mousedown.prevent
                @mouseenter="activeIndex = index"
                @click.stop="selectResult(result)"
            >
                <span class="fieldops-global-search__option-copy">
                    <span class="fieldops-global-search__option-heading">
                        <strong>{{ result.name }}</strong>
                        <span class="fieldops-global-search__type">{{ resultTypeLabel(result.type) }}</span>
                    </span>
                    <span class="fieldops-global-search__detail">
                        <span v-if="result.reference">{{ result.reference }}</span>
                        <span v-if="result.reference && result.detail" aria-hidden="true"> · </span>
                        <span v-if="result.detail">{{ result.detail }}</span>
                    </span>
                </span>
                <span v-if="isExactMatch(result)" class="fieldops-global-search__exact">Exact</span>
            </button>
        </div>
    </span>
</template>
