<script setup lang="ts">
import { Head, Link, useForm, usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import InputError from "@/Components/InputError.vue";
import InputLabel from "@/Components/InputLabel.vue";
import TextInput from "@/Components/TextInput.vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import type { PageProps } from "@/types";

interface Candidate {
    id: number;
    name: string;
    email: string;
}

const props = defineProps<{ candidates: Candidate[] }>();
const selectedUserId = ref("");
const page = usePage<PageProps<{ flash?: { admin_transfer_url?: string } }>>();
const form = useForm({ password: "", totp_code: "" });
const selectedCandidate = () => props.candidates.find((candidate) => String(candidate.id) === selectedUserId.value);

const offerTransfer = (): void => {
    const candidate = selectedCandidate();
    if (!candidate) return;
    if (!window.confirm(`Offer the single Admin account role to ${candidate.name}? Your Admin role remains active until they accept.`)) return;

    form.post(route("admin.users.admin-transfer.store", candidate.id), {
        preserveScroll: true,
        onFinish: () => form.reset("password", "totp_code"),
    });
};
</script>

<template>
    <Head title="Transfer Admin account role" />

    <AdminLayout title="Transfer Admin account role">
        <div class="mx-auto max-w-3xl space-y-4 px-4 py-6 sm:px-6 lg:px-8">
            <section class="fieldops-panel space-y-4 bg-white p-5 sm:p-6" aria-labelledby="transfer-admin-heading">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Operations / Staff authority</p>
                    <h1 id="transfer-admin-heading" class="mt-1 text-xl font-semibold text-gray-900">Transfer Admin account role</h1>
                </div>
                <p class="text-sm text-gray-700">
                    There is one active Admin account role. The selected user must accept the offer from their own active, verified account before the role moves. Your access remains unchanged until acceptance.
                </p>
                <p class="text-sm text-gray-700">
                    Finance and HR security-group memberships are independent and will not be transferred. Assign groups separately from the Staff directory.
                </p>

                <form v-if="candidates.length" class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end" @submit.prevent="offerTransfer">
                    <label class="fieldops-workspace-field">
                        Transfer to
                        <select v-model="selectedUserId" class="fieldops-client-input">
                            <option value="">Select an active verified user</option>
                            <option v-for="candidate in candidates" :key="candidate.id" :value="String(candidate.id)">
                                {{ candidate.name }} · {{ candidate.email }}
                            </option>
                        </select>
                    </label>
                    <div class="grid gap-3 sm:col-span-2 sm:grid-cols-2">
                        <label class="fieldops-workspace-field">
                            <InputLabel for="admin-transfer-password" value="Current password" />
                            <TextInput id="admin-transfer-password" v-model="form.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.password" />
                        </label>
                        <label class="fieldops-workspace-field">
                            <InputLabel for="admin-transfer-totp" value="Fresh authenticator code" />
                            <TextInput id="admin-transfer-totp" v-model="form.totp_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.totp_code" />
                        </label>
                    </div>
                    <button class="fieldops-submit-action min-h-10" type="submit" :disabled="!selectedUserId || !form.password || !form.totp_code || form.processing">
                        {{ form.processing ? "Verifying…" : "Offer transfer" }}
                    </button>
                </form>
                <p v-else class="text-sm text-gray-600">There are no other active, verified users eligible to receive the Admin role.</p>

                <section v-if="page.props.flash?.admin_transfer_url" class="grid gap-2 rounded-lg border border-amber-300 bg-amber-50 p-4" role="status">
                    <h2 class="font-semibold text-amber-950">Offer created</h2>
                    <p class="text-sm text-amber-900">Share this acceptance link with the selected user. It expires in 24 hours.</p>
                    <a class="break-all text-sm font-semibold text-amber-950 underline" :href="page.props.flash.admin_transfer_url">{{ page.props.flash.admin_transfer_url }}</a>
                </section>

                <div class="flex justify-end border-t border-fieldops-blue/10 pt-4">
                    <Link :href="route('admin.home')" class="fieldops-secondary-action min-h-10">Back to Operations</Link>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
