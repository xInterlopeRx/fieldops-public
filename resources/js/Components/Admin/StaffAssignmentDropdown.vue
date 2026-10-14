<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from "vue";

interface StaffOption {
    id: number;
    name: string;
    email: string;
    roles: string[];
}

const props = withDefaults(defineProps<{
    modelValue: number[];
    staff: StaffOption[];
    disabled?: boolean;
}>(), { disabled: false });

const emit = defineEmits<{ "update:modelValue": [value: number[]] }>();
const root = ref<HTMLElement | null>(null);
const open = ref(false);
const selectedNames = computed(() => props.staff.filter((person) => props.modelValue.includes(person.id)).map((person) => person.name));
const summary = computed(() => {
    if (selectedNames.value.length === 0) return "No staff assigned";
    if (selectedNames.value.length <= 2) return selectedNames.value.join(", ");

    return `${selectedNames.value.slice(0, 2).join(", ")} +${selectedNames.value.length - 2}`;
});

const toggle = (userId: number): void => {
    const next = props.modelValue.includes(userId)
        ? props.modelValue.filter((id) => id !== userId)
        : [...props.modelValue, userId];
    emit("update:modelValue", next.sort((left, right) => left - right));
};

const closeOnOutsideClick = (event: MouseEvent): void => {
    if (root.value && event.target instanceof Node && !root.value.contains(event.target)) {
        open.value = false;
    }
};

onMounted(() => document.addEventListener("click", closeOnOutsideClick));
onBeforeUnmount(() => document.removeEventListener("click", closeOnOutsideClick));
</script>

<template>
    <div ref="root" class="fieldops-assignment-dropdown" @keydown.esc="open = false">
        <button
            type="button"
            class="fieldops-assignment-trigger"
            :disabled="disabled"
            :aria-expanded="open"
            aria-haspopup="listbox"
            aria-label="Choose assigned staff"
            @click="open = !open"
        >
            <span class="min-w-0 flex-1 truncate">{{ summary }}</span>
            <span class="fieldops-assignment-count">{{ modelValue.length }}</span>
            <span aria-hidden="true" class="text-gray-500">▾</span>
        </button>
        <div v-if="open" class="fieldops-assignment-options" role="group" aria-label="Active staff">
            <label v-for="person in staff" :key="person.id" class="fieldops-assignment-option">
                <input type="checkbox" :checked="modelValue.includes(person.id)" @change="toggle(person.id)" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate font-medium">{{ person.name }}</span>
                    <small class="block truncate text-gray-500">{{ person.roles.join(", ") || person.email }}</small>
                </span>
            </label>
            <p v-if="staff.length === 0" class="px-3 py-4 text-sm text-gray-600">No active staff are available.</p>
        </div>
    </div>
</template>
