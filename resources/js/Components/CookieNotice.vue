<script setup lang="ts">
import { onMounted, ref } from "vue";

const storageKey = "fieldops-cookie-notice-dismissed-v1";
const isVisible = ref(false);

const dismiss = (): void => {
    isVisible.value = false;

    try {
        window.localStorage.setItem(storageKey, "true");
    } catch {
        // The notice remains dismissed for this page view if storage is unavailable.
    }
};

onMounted(() => {
    try {
        isVisible.value = window.localStorage.getItem(storageKey) !== "true";
    } catch {
        isVisible.value = true;
    }
});
</script>

<template>
    <aside
        v-if="isVisible"
        class="cookie-notice"
        role="region"
        aria-label="Cookie notice"
    >
        <div class="cookie-notice__copy">
            <h2>Cookies and sign-in</h2>
            <p>
                FieldOps uses essential cookies for sign-in and account security. If you choose “Trust this device,” a secure cookie remembers that choice for up to 14 days. Your sign-in session normally ends when you close the browser or after 24 hours of inactivity. We do not use advertising or analytics cookies.
            </p>
        </div>

        <button
            type="button"
            class="cookie-notice__dismiss"
            @click="dismiss"
        >
            Got it
        </button>
    </aside>
</template>

<style scoped>
.cookie-notice {
    position: fixed;
    z-index: 100;
    right: 0.75rem;
    bottom: 0.75rem;
    left: 0.75rem;
    display: grid;
    width: min(48rem, calc(100vw - 1.5rem));
    gap: 1rem;
    margin-inline: auto;
    border: 1px solid #cbd5e1;
    border-radius: 0.75rem;
    padding: 1rem;
    background: #fff;
    color: #111827;
    box-shadow: 0 16px 40px rgb(15 23 42 / 24%);
}

.cookie-notice__copy {
    display: grid;
    gap: 0.25rem;
}

.cookie-notice__copy h2 {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 700;
}

.cookie-notice__copy p {
    margin: 0;
    color: #374151;
    font-size: 0.875rem;
    line-height: 1.5;
}

.cookie-notice__dismiss {
    min-height: 2.75rem;
    border: 0;
    border-radius: 0.375rem;
    padding: 0.625rem 1rem;
    background: #0f172a;
    color: #fff;
    font-size: 0.875rem;
    font-weight: 700;
    cursor: pointer;
}

.cookie-notice__dismiss:hover {
    background: #334155;
}

.cookie-notice__dismiss:focus-visible {
    outline: 2px solid #1d4ed8;
    outline-offset: 2px;
}

@media (min-width: 640px) {
    .cookie-notice {
        right: 1rem;
        bottom: 1rem;
        left: auto;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        padding: 1.25rem;
    }
}
</style>
