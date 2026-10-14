import { onBeforeUnmount, onMounted, ref } from "vue";

export const useDesktopInlineWorkspace = (enabled: boolean) => {
    const isDesktopInline = ref(
        enabled && typeof window !== "undefined" && window.matchMedia("(min-width: 1024px)").matches,
    );
    let breakpoint: MediaQueryList | null = null;

    const updateLayout = (event: MediaQueryListEvent): void => {
        isDesktopInline.value = event.matches;
    };

    onMounted(() => {
        if (!enabled) return;

        breakpoint = window.matchMedia("(min-width: 1024px)");
        isDesktopInline.value = breakpoint.matches;
        breakpoint.addEventListener("change", updateLayout);
    });

    onBeforeUnmount(() => {
        breakpoint?.removeEventListener("change", updateLayout);
    });

    return isDesktopInline;
};
