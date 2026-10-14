<script setup lang="ts">
import { onMounted, ref } from 'vue';

const model = defineModel<string>({ required: true });

const input = ref<HTMLInputElement | null>(null);

onMounted(() => {
    if (input.value?.hasAttribute('autofocus')) {
        input.value?.focus();
    }
});

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <input
        class="fieldops-form-input min-h-11 rounded-lg border-fieldops-border-strong shadow-sm focus:border-fieldops-blue-dark focus:ring-fieldops-blue-dark dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:border-fieldops-blue dark:focus:ring-fieldops-blue"
        v-model="model"
        ref="input"
    />
</template>
