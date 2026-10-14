<script setup lang="ts">
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

defineProps<{ title: string }>();
</script>

<template>
    <AuthenticatedLayout>
        <template #header>
            <h1 class="fieldops-page-title">{{ title }}</h1>
        </template>

        <slot />
    </AuthenticatedLayout>
</template>
