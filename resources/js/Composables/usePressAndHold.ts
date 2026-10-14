import { onBeforeUnmount } from "vue";

type PressKey = number | string;

interface PressAndHoldBindings {
    pointerdown: (event: PointerEvent) => void;
    pointermove: (event: PointerEvent) => void;
    pointerup: (event: PointerEvent) => void;
    pointercancel: (event: PointerEvent) => void;
    pointerleave: (event: PointerEvent) => void;
    contextmenu: (event: MouseEvent) => void;
    click: (event: MouseEvent) => void;
}

export function usePressAndHold<T extends PressKey>(
    onTap: (key: T) => void,
    onHold: (key: T) => void,
    holdDuration = 550,
): (key: T) => PressAndHoldBindings {
    let timer: ReturnType<typeof setTimeout> | null = null;
    let activePointerId: number | null = null;
    let heldKey: T | undefined;
    let startX = 0;
    let startY = 0;

    const clearTimer = (): void => {
        if (timer !== null) clearTimeout(timer);
        timer = null;
    };

    const cancel = (): void => {
        clearTimer();
        activePointerId = null;
    };

    const start = (event: PointerEvent, key: T): void => {
        if (!event.isPrimary || event.button !== 0) return;

        cancel();
        heldKey = undefined;
        activePointerId = event.pointerId;
        startX = event.clientX;
        startY = event.clientY;
        timer = setTimeout(() => {
            timer = null;
            heldKey = key;
            onHold(key);
        }, holdDuration);
    };

    const move = (event: PointerEvent): void => {
        if (event.pointerId !== activePointerId) return;

        if (Math.hypot(event.clientX - startX, event.clientY - startY) > 12) cancel();
    };

    const end = (event: PointerEvent): void => {
        if (event.pointerId === activePointerId) cancel();
    };

    const contextMenu = (event: MouseEvent): void => {
        if (timer !== null || heldKey !== undefined) event.preventDefault();
    };

    const bindings = (key: T): PressAndHoldBindings => ({
        pointerdown: (event) => start(event, key),
        pointermove: move,
        pointerup: end,
        pointercancel: end,
        pointerleave: end,
        contextmenu: contextMenu,
        click: (event) => {
            if (event.detail > 0 && heldKey === key) {
                event.preventDefault();
                event.stopPropagation();
                heldKey = undefined;

                return;
            }

            onTap(key);
        },
    });

    onBeforeUnmount(() => {
        cancel();
        heldKey = undefined;
    });

    return bindings;
}
