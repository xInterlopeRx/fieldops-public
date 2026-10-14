import { ref } from "vue";

type SwipeDirection = "left" | "right";

interface UseSwipeNavigationOptions {
    onSwipe: (direction: SwipeDirection) => void;
    threshold?: number;
}

export function useSwipeNavigation({
    onSwipe,
    threshold = 50,
}: UseSwipeNavigationOptions) {
    const touchStartX = ref<number | null>(null);
    const touchStartY = ref<number | null>(null);

    const handleTouchStart = (event: TouchEvent) => {
        const touch = event.touches[0];
        touchStartX.value = touch?.clientX ?? null;
        touchStartY.value = touch?.clientY ?? null;
    };

    const handleTouchEnd = (event: TouchEvent) => {
        if (touchStartX.value === null || touchStartY.value === null) return;

        const touch = event.changedTouches[0];
        const horizontalDistance =
            (touch?.clientX ?? touchStartX.value) - touchStartX.value;
        const verticalDistance =
            (touch?.clientY ?? touchStartY.value) - touchStartY.value;

        touchStartX.value = null;
        touchStartY.value = null;

        if (
            Math.abs(horizontalDistance) < threshold ||
            Math.abs(horizontalDistance) <= Math.abs(verticalDistance)
        ) {
            return;
        }

        onSwipe(horizontalDistance < 0 ? "left" : "right");
    };

    return {
        handleTouchStart,
        handleTouchEnd,
    };
}
