<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Staff sign in | FieldOps" />

        <div v-if="status" class="fieldops-login-status" role="status">
            {{ status }}
        </div>

        <div class="fieldops-login-heading">
            <p class="fieldops-login-eyebrow">Staff access</p>
            <h2>Sign in to FieldOps</h2>
            <p>Use your FieldOps staff account to continue.</p>
        </div>

        <form class="fieldops-login-form" @submit.prevent="submit">
            <div class="fieldops-login-field">
                <InputLabel for="email" value="Email" />

                <TextInput
                    id="email"
                    type="email"
                    class="fieldops-login-input mt-1 block w-full"
                    v-model="form.email"
                    required
                    autofocus
                    autocomplete="username"
                />

                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div class="fieldops-login-field">
                <InputLabel for="password" value="Password" />

                <TextInput
                    id="password"
                    type="password"
                    class="fieldops-login-input mt-1 block w-full"
                    v-model="form.password"
                    required
                    autocomplete="current-password"
                />

                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div class="fieldops-login-actions">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="fieldops-login-link"
                >
                    Forgot your password?
                </Link>

                <PrimaryButton
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Signing in…' : 'Sign in' }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>

<style scoped>
.fieldops-login-status {
    margin-bottom: 1.25rem;
    border: 1px solid #008033;
    border-radius: 8px;
    background: #e8f8ed;
    padding: 0.75rem 0.9rem;
    color: #004d1a;
    font-size: 0.875rem;
    font-weight: 600;
}

.fieldops-login-heading {
    margin-bottom: 1.75rem;
}

.fieldops-login-eyebrow {
    margin: 0 0 0.45rem;
    color: var(--fieldops-blue-dark);
    font-size: 0.68rem;
    font-weight: 800;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}

.fieldops-login-heading h2 {
    margin: 0;
    color: var(--fieldops-navy);
    font-family: var(--font-brand);
    font-size: clamp(1.7rem, 4vw, 2.15rem);
    font-weight: 800;
    letter-spacing: -0.045em;
}

.fieldops-login-heading > p:last-child {
    margin: 0.5rem 0 0;
    color: #53617a;
    font-size: 0.9rem;
    line-height: 1.6;
}

.fieldops-login-form {
    display: grid;
    gap: 1.15rem;
}

.fieldops-login-field {
    display: grid;
    gap: 0.4rem;
}

.fieldops-login-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.fieldops-login-link {
    border-radius: 4px;
    color: var(--fieldops-blue-dark);
    font-size: 0.8rem;
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 3px;
}

.fieldops-login-link:hover {
    color: var(--fieldops-blue-dark);
}

.fieldops-login-link:focus-visible {
    outline: 3px solid var(--fieldops-blue-strong);
    outline-offset: 3px;
}

@media (max-width: 390px) {
    .fieldops-login-actions {
        align-items: stretch;
        flex-direction: column-reverse;
    }

    .fieldops-login-actions :deep(button) {
        justify-content: center;
    }
}
</style>
