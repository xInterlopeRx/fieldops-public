<script setup lang="ts">
import axios from "axios";
import { ref, watch } from "vue";

interface VisitItemUsage {
    id: number;
    itemId: number;
    name: string;
    description: string | null;
    type: string | null;
    quantity: string;
}

interface QuickBooksItemOption {
    id: number;
    name: string;
    description: string | null;
    type: string;
}

const props = defineProps<{
    visitId: number;
    items: VisitItemUsage[];
    editable: boolean;
}>();

const visitItems = ref<VisitItemUsage[]>([...props.items]);
const itemSearch = ref("");
const catalogItems = ref<QuickBooksItemOption[]>([]);
const searching = ref(false);
const saving = ref(false);
const error = ref("");
const selectedItemId = ref<number | null>(null);
const newQuantity = ref(1);
const quantityDrafts = ref<Record<number, number>>(
    Object.fromEntries(props.items.map((item) => [item.id, Number(item.quantity)])),
);

watch(() => props.items, (items) => {
    visitItems.value = [...items];
    quantityDrafts.value = Object.fromEntries(items.map((item) => [item.id, Number(item.quantity)]));
}, { deep: true });

const searchItems = async (): Promise<void> => {
    searching.value = true;
    error.value = "";

    try {
        const response = await axios.get(route("visits.items.search", props.visitId), {
            params: { search: itemSearch.value },
        });
        catalogItems.value = response.data.data.catalog as QuickBooksItemOption[];
        visitItems.value = response.data.data.items as VisitItemUsage[];
        quantityDrafts.value = Object.fromEntries(visitItems.value.map((item) => [item.id, Number(item.quantity)]));
    } catch (exception) {
        error.value = axios.isAxiosError(exception)
            ? exception.response?.data?.message ?? "QuickBooks Items could not be searched."
            : "QuickBooks Items could not be searched.";
    } finally {
        searching.value = false;
    }
};

const addItem = async (): Promise<void> => {
    if (selectedItemId.value === null) return;

    saving.value = true;
    error.value = "";

    try {
        const response = await axios.post(route("visits.items.store", props.visitId), {
            item_id: selectedItemId.value,
            quantity: newQuantity.value,
        });
        const item = response.data.data as VisitItemUsage;
        const existingIndex = visitItems.value.findIndex((existing) => existing.id === item.id);

        if (existingIndex >= 0) {
            visitItems.value.splice(existingIndex, 1, item);
        } else {
            visitItems.value.push(item);
        }

        visitItems.value.sort((left, right) => left.name.localeCompare(right.name));
        quantityDrafts.value[item.id] = Number(item.quantity);
        selectedItemId.value = null;
        newQuantity.value = 1;
    } catch (exception) {
        error.value = axios.isAxiosError(exception)
            ? exception.response?.data?.message ?? Object.values(exception.response?.data?.errors ?? {}).flat()[0] ?? "Item usage could not be saved."
            : "Item usage could not be saved.";
    } finally {
        saving.value = false;
    }
};

const updateQuantity = async (item: VisitItemUsage): Promise<void> => {
    saving.value = true;
    error.value = "";

    try {
        const response = await axios.put(route("visits.items.update", { visit: props.visitId, jobItem: item.id }), {
            quantity: quantityDrafts.value[item.id],
        });
        const updated = response.data.data as VisitItemUsage;
        const index = visitItems.value.findIndex((current) => current.id === updated.id);
        if (index >= 0) visitItems.value.splice(index, 1, updated);
        quantityDrafts.value[updated.id] = Number(updated.quantity);
    } catch (exception) {
        error.value = axios.isAxiosError(exception)
            ? exception.response?.data?.message ?? Object.values(exception.response?.data?.errors ?? {}).flat()[0] ?? "Item quantity could not be saved."
            : "Item quantity could not be saved.";
    } finally {
        saving.value = false;
    }
};

const removeItem = async (item: VisitItemUsage): Promise<void> => {
    if (!window.confirm(`Remove ${item.name} from this Visit?`)) return;

    saving.value = true;
    error.value = "";

    try {
        await axios.delete(route("visits.items.destroy", { visit: props.visitId, jobItem: item.id }));
        visitItems.value = visitItems.value.filter((current) => current.id !== item.id);
        delete quantityDrafts.value[item.id];
    } catch (exception) {
        error.value = axios.isAxiosError(exception)
            ? exception.response?.data?.message ?? "Item usage could not be removed."
            : "Item usage could not be removed.";
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <section class="grid gap-3" aria-labelledby="visit-items-title">
        <div>
            <h3 id="visit-items-title" class="text-sm font-semibold text-gray-900">Items used on this Visit</h3>
            <p class="mt-1 text-xs text-gray-600">Item identity and quantity are included in the Billing job report. FieldOps does not show price, cost, or stock.</p>
        </div>

        <ul v-if="visitItems.length > 0" class="grid gap-2" aria-label="Items recorded for this Visit">
            <li v-for="item in visitItems" :key="item.id" class="grid gap-2 rounded-lg border border-gray-200 p-3 sm:grid-cols-[minmax(0,1fr)_8rem_auto_auto] sm:items-center">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-900">{{ item.name }}</p>
                    <p v-if="item.description" class="text-xs text-gray-600">{{ item.description }}</p>
                    <p v-if="item.type" class="text-xs text-gray-500">{{ item.type }}</p>
                </div>
                <label class="grid gap-1 text-xs text-gray-600">
                    Quantity
                    <input v-model.number="quantityDrafts[item.id]" type="number" min="0.001" max="999999999.999" step="0.001" :readonly="!editable" class="fieldops-client-input min-h-10" />
                </label>
                <button v-if="editable && quantityDrafts[item.id] !== Number(item.quantity)" type="button" class="fieldops-secondary-action min-h-10" :disabled="saving" @click="updateQuantity(item)">Save quantity</button>
                <button v-if="editable" type="button" class="fieldops-delete-action min-h-10" :disabled="saving" @click="removeItem(item)">Remove</button>
            </li>
        </ul>
        <p v-else class="text-sm text-gray-600">No Items recorded for this Visit.</p>

        <div v-if="editable" class="grid gap-2 rounded-lg border border-dashed border-gray-300 p-3">
            <label class="grid gap-1 text-sm text-gray-700">
                Search existing QuickBooks Items
                <input v-model="itemSearch" type="search" maxlength="100" autocomplete="off" class="fieldops-client-input min-h-10" @keydown.enter.prevent="searchItems" />
            </label>
            <button type="button" class="fieldops-secondary-action min-h-10 w-fit" :disabled="searching" @click="searchItems">{{ searching ? "Searching…" : "Search Items" }}</button>
            <label v-if="catalogItems.length > 0" class="grid gap-1 text-sm text-gray-700">
                Select Item
                <select v-model.number="selectedItemId" class="fieldops-client-input min-h-10">
                    <option :value="null">Choose an Item</option>
                    <option v-for="item in catalogItems.filter((result) => !visitItems.some((existing) => existing.itemId === result.id))" :key="item.id" :value="item.id">
                        {{ item.name }} · {{ item.type }}<span v-if="item.description"> — {{ item.description }}</span>
                    </option>
                </select>
            </label>
            <p v-if="catalogItems.length > 0 && catalogItems.every((result) => visitItems.some((existing) => existing.itemId === result.id))" class="text-sm text-gray-600">All matching Items are already recorded for this Visit.</p>
            <p v-else-if="catalogItems.length === 0 && itemSearch !== '' && !searching" class="text-sm text-gray-600">No active selectable Items matched this search.</p>
            <div v-if="selectedItemId !== null" class="grid gap-2 sm:grid-cols-[10rem_auto] sm:items-end">
                <label class="grid gap-1 text-sm text-gray-700">
                    Quantity
                    <input v-model.number="newQuantity" type="number" min="0.001" max="999999999.999" step="0.001" class="fieldops-client-input min-h-10" />
                </label>
                <button type="button" class="fieldops-submit-action fieldops-add-action min-h-10 w-fit" :disabled="saving" @click="addItem">{{ saving ? "Saving…" : "Add Item to Visit" }}</button>
            </div>
        </div>
        <p v-else class="text-xs text-gray-600">Items are read-only after the Job is completed or when the QuickBooks catalog is unavailable.</p>
        <p v-if="error" role="alert" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ error }}</p>
    </section>
</template>
