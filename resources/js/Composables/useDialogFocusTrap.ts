import { onBeforeUnmount, type Ref } from "vue";

const FOCUSABLE_SELECTOR = [
    "a[href]",
    "button:not([disabled])",
    "input:not([disabled]):not([type='hidden'])",
    "select:not([disabled])",
    "textarea:not([disabled])",
    "[tabindex]:not([tabindex='-1'])",
].join(",");

export const useDialogFocusTrap = (
    panel: Ref<HTMLElement | null>,
    enabled: () => boolean = () => true,
) => {
    const returnFocusTarget = typeof document !== "undefined" && document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;

    const handleKeydown = (event: KeyboardEvent): void => {
        if (event.key !== "Tab" || !enabled()) return;
        event.stopPropagation();

        const container = panel.value;
        if (!container) return;

        const focusableElements = Array.from(
            container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR),
        ).filter((element) =>
            element.getClientRects().length > 0 &&
            getComputedStyle(element).visibility !== "hidden",
        );

        if (focusableElements.length === 0) {
            event.preventDefault();
            container.focus();

            return;
        }

        const first = focusableElements[0];
        const last = focusableElements[focusableElements.length - 1];
        const active = document.activeElement;

        if (event.shiftKey && (active === first || active === container || !container.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || active === container || !container.contains(active))) {
            event.preventDefault();
            first.focus();
        }
    };

    onBeforeUnmount(() => {
        if (returnFocusTarget?.isConnected) {
            returnFocusTarget.focus({ preventScroll: true });
        }
    });

    return handleKeydown;
};
