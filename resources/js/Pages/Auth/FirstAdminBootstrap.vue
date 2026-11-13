<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { PageProps } from '@/types';

type SmtpBackup = {
    host: string;
    port: number;
    security: string;
    from_address: string;
    from_name: string;
    tested_recipient: string | null;
    tested_at: string | null;
} | null;

const page = usePage<PageProps<{ smtp_backup: SmtpBackup }>>();
const allowPlainSmtp = page.props.smtp_plain_transport_allowed;
const smtpBackup = computed(() => page.props.smtp_backup);

const form = useForm({
    name: '',
    email: smtpBackup.value?.tested_recipient ?? '',
    password: '',
    password_confirmation: '',
    smtp_host: smtpBackup.value?.host ?? '',
    smtp_port: smtpBackup.value ? String(smtpBackup.value.port) : '587',
    smtp_security: smtpBackup.value?.security ?? 'starttls',
    smtp_username: '',
    smtp_password: '',
    smtp_from_address: smtpBackup.value?.from_address ?? '',
    smtp_from_name: smtpBackup.value?.from_name ?? 'FieldOps',
});

const smtpTesting = ref(false);

const savedSettingsMatch = computed(() => {
    const backup = smtpBackup.value;

    return backup !== null
        && form.smtp_host.trim() === backup.host
        && Number(form.smtp_port) === backup.port
        && form.smtp_security === backup.security
        && form.smtp_from_address.trim().toLowerCase() === backup.from_address
        && form.smtp_from_name.trim() === backup.from_name;
});

const smtpTestVerified = computed(() => smtpBackup.value?.tested_at != null);

const smtpTestMatchesForm = computed(() => savedSettingsMatch.value
    && smtpTestVerified.value
    && form.email.trim().toLowerCase() === smtpBackup.value?.tested_recipient
    && form.smtp_username.trim() === ''
    && form.smtp_password === '');

const useSavedConfiguration = computed(() => savedSettingsMatch.value
    && form.smtp_username.trim() === ''
    && form.smtp_password === '');

const testSmtp = () => {
    form.clearErrors();
    smtpTesting.value = true;

    const payload = useSavedConfiguration.value
        ? {
            email: form.email,
            use_saved_configuration: true,
        }
        : {
            email: form.email,
            smtp_host: form.smtp_host,
            smtp_port: Number(form.smtp_port),
            smtp_security: form.smtp_security,
            smtp_username: form.smtp_username,
            smtp_password: form.smtp_password,
            smtp_from_address: form.smtp_from_address,
            smtp_from_name: form.smtp_from_name,
        };

    router.post(route('installation.bootstrap.smtp-test'), payload, {
        preserveScroll: true,
        onError: (errors) => form.setError(errors),
        onFinish: () => {
            smtpTesting.value = false;
            form.reset('smtp_username', 'smtp_password');
        },
    });
};

const submit = () => {
    if (!smtpTestMatchesForm.value) {
        form.setError('smtp_host', 'Test and save SMTP settings for this email address before creating the first Admin.');

        return;
    }

    form.transform((data) => ({
        name: data.name,
        email: data.email,
        password: data.password,
        password_confirmation: data.password_confirmation,
        smtp_host: data.smtp_host,
        smtp_port: Number(data.smtp_port),
        smtp_security: data.smtp_security,
        smtp_from_address: data.smtp_from_address,
        smtp_from_name: data.smtp_from_name,
    })).post(route('installation.bootstrap.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Create the first Admin" />

        <div class="grid gap-5">
            <header class="grid gap-2">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-800">Initial setup</p>
                <h2 class="text-2xl font-semibold text-slate-900">Create the first Admin</h2>
                <p class="text-sm leading-6 text-slate-600">
                    Test SMTP separately, then create the one-time Admin account. The saved SMTP settings are reused for the verification email.
                </p>
            </header>

            <form class="grid gap-4" @submit.prevent="submit">
                <div>
                    <InputLabel for="name" value="Name" />
                    <TextInput id="name" v-model="form.name" class="mt-1 block w-full" autocomplete="name" required autofocus />
                    <InputError class="mt-2" :message="form.errors.name" />
                </div>

                <div>
                    <InputLabel for="email" value="Work email" />
                    <TextInput id="email" v-model="form.email" class="mt-1 block w-full" type="email" autocomplete="email" required />
                    <InputError class="mt-2" :message="form.errors.email" />
                </div>

                <section class="grid gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4" aria-labelledby="smtp-setup-heading">
                    <header class="grid gap-1">
                        <h3 id="smtp-setup-heading" class="text-base font-semibold text-slate-900">SMTP email delivery</h3>
                        <p class="text-sm leading-5 text-slate-600">Send a test message to the work email above. Settings are saved only after the SMTP server accepts the test message, and the first-Admin submission reuses that successful test instead of sending a duplicate. Acceptance does not guarantee inbox delivery or a Sent Items copy.</p>

                        <div v-if="smtpTestMatchesForm" class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-900" role="status" aria-live="polite">
                            SMTP test succeeded for {{ smtpBackup?.tested_recipient }}. Settings are encrypted and saved for this setup; the password is never sent back to the browser.
                        </div>
                        <div v-else-if="savedSettingsMatch" class="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900" role="status" aria-live="polite">
                            Saved SMTP settings are available. Test them for the current work email before creating the Admin.
                        </div>
                    </header>

                    <div>
                        <InputLabel for="smtp_host" value="SMTP host" />
                        <TextInput id="smtp_host" v-model="form.smtp_host" class="mt-1 block w-full" autocomplete="off" required />
                        <InputError class="mt-2" :message="form.errors.smtp_host" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="smtp_port" value="SMTP port" />
                            <TextInput id="smtp_port" v-model="form.smtp_port" class="mt-1 block w-full" type="number" min="1" max="65535" required />
                            <InputError class="mt-2" :message="form.errors.smtp_port" />
                        </div>
                        <div>
                            <InputLabel for="smtp_security" value="Connection security" />
                            <select id="smtp_security" v-model="form.smtp_security" class="mt-1 block min-h-11 w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
                                <option value="starttls">STARTTLS</option>
                                <option value="ssl">Implicit TLS (SSL)</option>
                                <option v-if="allowPlainSmtp" value="none">No TLS (local development only)</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.smtp_security" />
                        </div>
                    </div>

                    <div>
                        <InputLabel for="smtp_username" value="SMTP username (if required)" />
                        <TextInput id="smtp_username" v-model="form.smtp_username" class="mt-1 block w-full" autocomplete="off" />
                        <InputError class="mt-2" :message="form.errors.smtp_username" />
                    </div>

                    <div>
                        <InputLabel for="smtp_password" value="SMTP password (if required)" />
                        <TextInput id="smtp_password" v-model="form.smtp_password" class="mt-1 block w-full" type="password" autocomplete="new-password" />
                        <InputError class="mt-2" :message="form.errors.smtp_password" />
                        <p class="mt-1 text-xs leading-5 text-slate-600">Leave username and password blank to reuse saved credentials when the other SMTP settings are unchanged. Enter both to test updated credentials.</p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="smtp_from_address" value="From email address" />
                            <TextInput id="smtp_from_address" v-model="form.smtp_from_address" class="mt-1 block w-full" type="email" autocomplete="off" required />
                            <InputError class="mt-2" :message="form.errors.smtp_from_address" />
                        </div>
                        <div>
                            <InputLabel for="smtp_from_name" value="From name" />
                            <TextInput id="smtp_from_name" v-model="form.smtp_from_name" class="mt-1 block w-full" autocomplete="organization" required />
                            <InputError class="mt-2" :message="form.errors.smtp_from_name" />
                        </div>
                    </div>

                    <p class="text-xs leading-5 text-slate-600">After a successful test, SMTP username and password are encrypted in the database using the deployment-managed Laravel APP_KEY. They are never flashed back into the session or returned to the browser.</p>

                    <button
                        type="button"
                        class="inline-flex min-h-11 items-center justify-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 shadow-sm hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-fieldops-blue-strong focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60"
                        :disabled="smtpTesting || form.processing"
                        @click="testSmtp"
                    >
                        {{ smtpTesting ? 'Testing SMTP…' : smtpTestMatchesForm ? 'Test saved SMTP settings again' : savedSettingsMatch ? 'Test saved SMTP settings' : 'Test SMTP and save settings' }}
                    </button>
                </section>

                <div>
                    <InputLabel for="password" value="Password" />
                    <TextInput id="password" v-model="form.password" class="mt-1 block w-full" type="password" autocomplete="new-password" required />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div>
                    <InputLabel for="password_confirmation" value="Confirm password" />
                    <TextInput id="password_confirmation" v-model="form.password_confirmation" class="mt-1 block w-full" type="password" autocomplete="new-password" required />
                    <InputError class="mt-2" :message="form.errors.password_confirmation" />
                </div>

                <PrimaryButton class="w-full justify-center" :disabled="form.processing || !smtpTestMatchesForm">
                    {{ form.processing ? 'Creating account…' : 'Create first Admin' }}
                </PrimaryButton>
            </form>
        </div>
    </GuestLayout>
</template>
