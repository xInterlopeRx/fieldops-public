import { onBeforeUnmount, ref, watch, type Ref } from "vue";

export interface GlobalSearchResult {
    id: number;
    parent_id?: number;
    type: "client" | "project" | "job" | "visit";
    name: string;
    reference: string | null;
    detail: string | null;
    status?: string;
}

export type GlobalSearchResultType = GlobalSearchResult["type"];

export const useGlobalSearchAutocomplete = (
    query: Ref<string>,
    optionIdPrefix: string,
    allowedTypes?: GlobalSearchResultType[],
) => {
    const results = ref<GlobalSearchResult[]>([]);
    const isOpen = ref(false);
    const isLoading = ref(false);
    const activeIndex = ref(-1);
    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    let requestController: AbortController | null = null;
    let requestGeneration = 0;

    const resultId = (index: number): string => `${optionIdPrefix}-${index}`;
    const activeDescendant = (): string | undefined =>
        activeIndex.value >= 0 ? resultId(activeIndex.value) : undefined;

    const closeSuggestions = (): void => {
        isOpen.value = false;
        activeIndex.value = -1;
    };

    const search = async (term: string, generation: number): Promise<void> => {
        const controller = new AbortController();
        requestController = controller;

        try {
            const response = await fetch(
                `${route("global.search")}?q=${encodeURIComponent(term)}`,
                {
                    headers: { Accept: "application/json" },
                    signal: controller.signal,
                },
            );

            if (!response.ok) throw new Error("Global search request failed.");

            const payload = (await response.json()) as {
                data: GlobalSearchResult[];
            };

            if (generation === requestGeneration) {
                results.value = payload.data
                    .filter((result) => !allowedTypes || allowedTypes.includes(result.type))
                    .slice(0, 4);
                activeIndex.value = -1;
            }
        } catch (error: unknown) {
            if (error instanceof DOMException && error.name === "AbortError") return;

            if (generation === requestGeneration) results.value = [];
        } finally {
            if (generation === requestGeneration) isLoading.value = false;
        }
    };

    watch(query, (value) => {
        requestGeneration += 1;
        const generation = requestGeneration;

        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }

        requestController?.abort();
        results.value = [];
        activeIndex.value = -1;

        const term = value.trim();
        isOpen.value = term.length > 0;

        if (term === "") {
            isLoading.value = false;

            return;
        }

        isLoading.value = true;
        debounceTimer = setTimeout(() => {
            debounceTimer = null;
            void search(term, generation);
        }, 160);
    });

    onBeforeUnmount(() => {
        if (debounceTimer !== null) clearTimeout(debounceTimer);
        requestController?.abort();
    });

    const openForCurrentQuery = (): void => {
        isOpen.value = query.value.trim().length > 0;
    };

    const moveActiveOption = (direction: 1 | -1): void => {
        if (results.value.length === 0) return;

        isOpen.value = true;
        activeIndex.value = activeIndex.value < 0
            ? (direction === 1 ? 0 : results.value.length - 1)
            : (activeIndex.value + direction + results.value.length) % results.value.length;
    };

    const handleKeydown = (
        event: KeyboardEvent,
        selectResult: (result: GlobalSearchResult) => void,
    ): void => {
        if (event.key === "ArrowDown") {
            event.preventDefault();
            moveActiveOption(1);
        } else if (event.key === "ArrowUp") {
            event.preventDefault();
            moveActiveOption(-1);
        } else if (event.key === "Enter" && isOpen.value && (isLoading.value || results.value.length > 0)) {
            event.preventDefault();

            const result = results.value[activeIndex.value] ?? results.value[0];

            if (result) selectResult(result);
        } else if (event.key === "Escape" && isOpen.value) {
            event.preventDefault();
            closeSuggestions();
        }
    };

    const closeIfOutside = (event: PointerEvent, root: HTMLElement | null): void => {
        if (root && !root.contains(event.target as Node)) closeSuggestions();
    };

    return {
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
    };
};
