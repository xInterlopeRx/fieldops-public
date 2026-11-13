<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";

const props = defineProps<{
    transfer: {
        id: number;
        fromName: string;
        expiresAt: string;
    };
}>();

const form = useForm({ confirmation: false });

const expiresAtLabel = new Date(props.transfer.expiresAt).toLocaleString(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
});

const accept = (): void => {
    if (!form.confirmation || form.processing) return;

    form.post(route("admin.admin-role-transfers.accept", props.transfer.id));
};
</script>

<template>
    <Head title="Accept Admin role transfer" />

    <AdminLayout title="Accept Admin role transfer">
        <div class="mx-auto max-w-2xl space-y-4 px-4 py-6 sm:px-6 lg:px-8">
            <section class="fieldops-panel space-y-4 bg-white p-5 sm:p-6" aria-labelledby="admin-transfer-title">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Account security</p>
                    <h1 id="admin-transfer-title" class="mt-1 text-xl font-semibold text-gray-900">Accept Admin role transfer</h1>
                </div>

                <p class="text-sm text-gray-700">
                    {{ transfer.fromName }} has offered to transfer the single Admin account role to you.
                    The current Admin keeps access unless you accept. The offer expires {{ expiresAtLabel }}.
                </p>
                <p class="text-sm text-gray-700">
                    Accepting transfers only the Admin account role. Your Finance/HR security groups and notification roles are not changed.
                </p>

                <form class="grid gap-4 border-t border-fieldops-blue/10 pt-4" @submit.prevent="accept">
                    <label class="flex items-start gap-3 text-sm text-gray-700">
                        <input v-model="form.confirmation" type="checkbox" class="mt-1" />
                        <span>I am signed in to my own verified account and accept responsibility for Admin access.</span>
                    </label>
                    <p v-if="form.errors.confirmation" role="alert" class="text-sm text-red-700">{{ form.errors.confirmation }}</p>
                    <div class="flex flex-wrap justify-end gap-2">
                        <Link :href="route('calendar')" class="fieldops-secondary-action min-h-10">Not now</Link>
                        <button class="fieldops-submit-action min-h-10" type="submit" :disabled="form.processing || !form.confirmation">
                            {{ form.processing ? "Transferring…" : "Accept Admin transfer" }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </AdminLayout>
</template>
