<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const allowPlainSmtp = page.props.smtp_plain_transport_allowed;

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    smtp_host: '',
    smtp_port: '587',
    smtp_security: 'starttls',
    smtp_username: '',
    smtp_password: '',
    smtp_from_address: '',
    smtp_from_name: 'FieldOps',
});

const submit = () => {
    form.post(route('installation.bootstrap.store'), {
        onFinish: () => form.reset('password', 'password_confirmation', 'smtp_username', 'smtp_password'),
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
                    This one-time account claims this FieldOps installation. We’ll send an email verification link before setup can continue.
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
                        <p class="text-sm leading-5 text-slate-600">FieldOps tests SMTP by emailing your work address before creating the Admin account. After that, a separate email contains the verification link.</p>
                    </header>

                    <div>
                        <InputLabel for="smtp_host" value="SMTP host" />
                        <TextInput id="smtp_host" v-model="form.smtp_host" class="mt-1 block w-full" autocomplete="off" required />
                        <InputError class="mt-2" :message="form.errors.smtp_host" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="smtp_port" value="SMTP port" />
                            <TextInput id="smtp_port" v-model.number="form.smtp_port" class="mt-1 block w-full" type="number" min="1" max="65535" required />
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

                    <p class="text-xs leading-5 text-slate-600">SMTP username and password are encrypted in the database using the deployment-managed Laravel APP_KEY and are never flashed back into the session.</p>
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

                <PrimaryButton class="w-full justify-center" :disabled="form.processing">
                    {{ form.processing ? 'Creating account…' : 'Create first Admin' }}
                </PrimaryButton>
            </form>
        </div>
    </GuestLayout>
</template>
