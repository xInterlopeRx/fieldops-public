<script setup lang="ts">
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';

const props = defineProps<{
    verified: boolean;
    verifyUrl: string;
    expiresAt: string;
    verifiedAt: string | null;
}>();

const form = useForm({ token: '' });
const tokenAvailable = ref(false);

onMounted(() => {
    const fragment = new URLSearchParams(window.location.hash.slice(1));
    const token = fragment.get('token');

    if (token !== null) {
        form.token = token;
        tokenAvailable.value = true;
        window.history.replaceState({}, document.title, window.location.pathname + window.location.search);
    }
});

function confirmDelivery() {
    form.post(props.verifyUrl, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Confirm integration setup email" />
    <main class="min-h-screen bg-gray-50 px-4 py-12 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
        <section class="mx-auto grid max-w-xl gap-5 rounded-lg border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-900 sm:p-8">
            <div class="grid gap-2">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-800 dark:text-blue-300">FieldOps · Integration setup</p>
                <h1 class="text-2xl font-semibold">Confirm setup email delivery</h1>
            </div>

            <div v-if="verified" class="grid gap-3 rounded-md border border-green-300 bg-green-50 p-4 text-sm text-green-950 dark:border-green-800 dark:bg-green-950 dark:text-green-100" role="status">
                <p class="font-semibold">Email link confirmed.</p>
                <p>FieldOps recorded that this signed setup link was opened. This confirms the email delivery path only; Graph target permissions, canaries, cleanup, and activation remain separate checks.</p>
                <p v-if="verifiedAt">Confirmed at: {{ verifiedAt }}</p>
            </div>

            <template v-else>
                <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                    If you requested the setup email, confirm below. Merely opening this page does not verify delivery; email security scanners cannot complete this action automatically. The link expires at {{ expiresAt }}.
                </p>
                <p class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                    This confirms only the email linkback. It does not authorize or activate Graph operations and does not unlock staff invitations.
                </p>
                <InputError :message="form.errors.token" />
                <PrimaryButton class="w-fit" :disabled="form.processing || !tokenAvailable" @click="confirmDelivery">
                    {{ form.processing ? 'Confirming…' : 'Confirm I received this email' }}
                </PrimaryButton>
            </template>
        </section>
    </main>
</template>
