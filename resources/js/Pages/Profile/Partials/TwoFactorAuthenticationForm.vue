<script setup lang="ts">
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import axios from 'axios';
import { computed, ref } from 'vue';
import { XMarkIcon } from '@heroicons/vue/24/outline';

type ProtectedAction =
    | 'enable'
    | 'resume'
    | 'disable'
    | 'recovery'
    | 'regenerate'
    | 'confirm-code'
    | 'forget-devices'
    | 'logout-all'
    | null;

const props = defineProps<{
    enabled: boolean;
    pending: boolean;
}>();

const enabled = ref(props.enabled);
const setupPending = ref(props.pending);
const pendingAction = ref<ProtectedAction>(null);
const password = ref('');
const code = ref('');
const qrCodeSvg = ref('');
const recoveryCodes = ref<string[]>([]);
const showRecoveryCodes = ref(false);
const processing = ref(false);
const errorMessage = ref('');
const statusMessage = ref('');

const qrCodeImage = computed(() =>
    qrCodeSvg.value
        ? `data:image/svg+xml;charset=utf-8,${encodeURIComponent(qrCodeSvg.value)}`
        : '',
);

function beginAction(action: Exclude<ProtectedAction, null>) {
    pendingAction.value = action;
    password.value = '';
    errorMessage.value = '';
    statusMessage.value = '';
}

function cancelAction() {
    pendingAction.value = null;
    password.value = '';
    errorMessage.value = '';
}

function messageFromError(error: unknown): string {
    if (axios.isAxiosError(error)) {
        const errors = error.response?.data?.errors as
            | Record<string, string[]>
            | undefined;

        if (errors) {
            return Object.values(errors).flat().join(' ');
        }

        return error.response?.data?.message ?? 'The request could not be completed.';
    }

    return 'The request could not be completed. Please try again.';
}

async function loadQrCode() {
    const response = await axios.get(route('two-factor.qr-code'));
    qrCodeSvg.value = response.data.svg;
}

async function loadRecoveryCodes() {
    const response = await axios.get(route('two-factor.recovery-codes'));
    recoveryCodes.value = response.data;
    showRecoveryCodes.value = true;
}

async function submitPasswordConfirmation() {
    if (!pendingAction.value) {
        return;
    }

    processing.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        await axios.post(route('password.confirm.store'), {
            password: password.value,
        });

        const action = pendingAction.value;
        const confirmedPassword = password.value;
        pendingAction.value = null;
        password.value = '';

        if (action === 'enable') {
            await axios.post(route('two-factor.enable'));
            setupPending.value = true;
            await loadQrCode();
            statusMessage.value = 'Scan this QR code with your authenticator app, then enter its six-digit code below.';
        } else if (action === 'resume') {
            setupPending.value = true;
            await loadQrCode();
            statusMessage.value = 'Finish setup by entering the six-digit code from your authenticator app.';
        } else if (action === 'disable') {
            await axios.delete(route('two-factor.disable'));
            enabled.value = false;
            setupPending.value = false;
            qrCodeSvg.value = '';
            recoveryCodes.value = [];
            showRecoveryCodes.value = false;
            statusMessage.value = 'Two-factor authentication has been turned off.';
        } else if (action === 'recovery') {
            await loadRecoveryCodes();
        } else if (action === 'regenerate') {
            await axios.post(route('two-factor.regenerate-recovery-codes'));
            await loadRecoveryCodes();
            statusMessage.value = 'New recovery codes have been generated. The previous codes no longer work.';
        } else if (action === 'confirm-code') {
            await completeSetup();
        } else if (action === 'forget-devices') {
            await axios.post(route('profile.trusted-devices.revoke'));
            statusMessage.value = 'All trusted devices have been forgotten. They will need an authenticator code at the next sign in.';
        } else if (action === 'logout-all') {
            await axios.post(route('logout.all'), { password: confirmedPassword });
            window.location.assign(route('login'));
        }
    } catch (error) {
        errorMessage.value = messageFromError(error);
    } finally {
        processing.value = false;
    }
}

async function completeSetup() {
    await axios.post(route('two-factor.confirm'), { code: code.value });
    enabled.value = true;
    setupPending.value = false;
    code.value = '';
    await loadRecoveryCodes();
    statusMessage.value = 'Two-factor authentication is enabled. Save these recovery codes somewhere secure.';
}

async function confirmSetup() {
    processing.value = true;
    errorMessage.value = '';
    statusMessage.value = '';

    try {
        await completeSetup();
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 423) {
            beginAction('confirm-code');
            errorMessage.value = 'Your password confirmation expired. Confirm your password to finish setup.';
            return;
        }

        errorMessage.value = messageFromError(error);
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                Two-factor authentication
            </h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Two-factor authentication is required for FieldOps access. Set up an authenticator app and save your recovery codes securely.
            </p>
            <p class="mt-2 text-sm font-medium text-gray-800 dark:text-gray-200" role="status">
                Status: {{ enabled ? 'Enabled' : setupPending ? 'Setup incomplete' : 'Not enabled' }}
            </p>
        </header>

        <div v-if="pendingAction" class="mt-6 space-y-4">
            <p class="text-sm text-gray-700 dark:text-gray-300">
                {{ pendingAction === 'forget-devices'
                    ? 'Confirm your password before forgetting trusted devices.'
                    : pendingAction === 'logout-all'
                      ? 'Confirm your password to sign out all devices and revoke trusted-device access.'
                      : 'Confirm your password before changing two-factor settings.' }}
            </p>
            <div>
                <InputLabel for="two-factor-password" value="Current password" />
                <TextInput
                    id="two-factor-password"
                    v-model="password"
                    type="password"
                    autocomplete="current-password"
                    required
                    autofocus
                    class="mt-1 block w-full"
                    @keyup.enter="submitPasswordConfirmation"
                />
            </div>
            <div class="flex items-center gap-4">
                <PrimaryButton :disabled="processing || !password" @click="submitPasswordConfirmation">
                    {{ processing ? 'Checking…' : 'Continue' }}
                </PrimaryButton>
                <button
                    type="button"
                    class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0"
                    aria-label="Cancel two-factor action"
                    title="Cancel"
                    @click="cancelAction"
                >
                    <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
        </div>

        <div v-else-if="!enabled && !setupPending" class="mt-6">
            <PrimaryButton @click="beginAction('enable')">
                Set up two-factor authentication
            </PrimaryButton>
        </div>

        <div v-else-if="setupPending" class="mt-6 space-y-5">
            <div v-if="qrCodeImage" class="space-y-3">
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    Scan this code with your authenticator app. The setup secret is only shown here.
                </p>
                <img
                    :src="qrCodeImage"
                    alt="Two-factor authenticator setup QR code"
                    class="h-48 w-48 rounded border border-gray-200 bg-white p-2"
                />
            </div>

            <div v-if="!qrCodeImage">
                <PrimaryButton @click="beginAction('resume')">
                    Continue two-factor setup
                </PrimaryButton>
            </div>

            <form v-if="qrCodeImage" class="space-y-4" @submit.prevent="confirmSetup">
                <div>
                    <InputLabel for="two-factor-code" value="Six-digit authenticator code" />
                    <TextInput
                        id="two-factor-code"
                        v-model="code"
                        type="text"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        required
                        class="mt-1 block w-full"
                    />
                </div>
                <PrimaryButton :disabled="processing">
                    {{ processing ? 'Verifying…' : 'Confirm and enable' }}
                </PrimaryButton>
            </form>
        </div>

        <div v-else class="mt-6 flex flex-wrap gap-3">
            <PrimaryButton @click="beginAction('recovery')">
                Show recovery codes
            </PrimaryButton>
            <PrimaryButton @click="beginAction('regenerate')">
                Regenerate recovery codes
            </PrimaryButton>
            <button
                type="button"
                class="rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-950 dark:focus:ring-offset-gray-800"
                @click="beginAction('disable')"
            >
                Turn off
            </button>
        </div>

        <div class="mt-8 grid gap-3 border-t border-gray-200 pt-6 dark:border-gray-700">
            <div v-if="enabled" class="grid gap-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Trusted devices</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Forgetting devices removes every 14-day trust token. Those devices will need an authenticator code at their next sign in.
                </p>
                <button
                    type="button"
                    class="w-fit rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-800 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-fieldops-orange focus:ring-offset-2 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800 dark:focus:ring-offset-gray-900"
                    @click="beginAction('forget-devices')"
                >
                    Forget all trusted devices
                </button>
            </div>

            <div class="grid gap-2">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Sign out everywhere</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Revoke trusted devices and sign out other active sessions. They will be signed out on their next request.
                </p>
                <button
                    type="button"
                    class="w-fit rounded-md border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-600 focus:ring-offset-2 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-950 dark:focus:ring-offset-gray-900"
                    @click="beginAction('logout-all')"
                >
                    Sign out all devices
                </button>
            </div>
        </div>

        <div v-if="errorMessage" class="mt-4 text-sm font-medium text-red-700 dark:text-red-300" role="alert">
            {{ errorMessage }}
        </div>
        <div v-if="statusMessage" class="mt-4 text-sm font-medium text-green-800 dark:text-green-300" role="status">
            {{ statusMessage }}
        </div>

        <div v-if="showRecoveryCodes && recoveryCodes.length" class="mt-5 rounded-md border border-gray-200 p-4 dark:border-gray-700">
            <div class="flex items-start justify-between gap-4">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                    Recovery codes
                </h3>
                <button
                    type="button"
                    class="text-sm text-gray-700 underline dark:text-gray-300"
                    @click="showRecoveryCodes = false; recoveryCodes = []"
                >
                    Hide codes
                </button>
            </div>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                Each code can be used once if you cannot access your authenticator app. Keep them private.
            </p>
            <ul class="mt-3 grid gap-2 font-mono text-sm text-gray-900 sm:grid-cols-2 dark:text-gray-100">
                <li v-for="recoveryCode in recoveryCodes" :key="recoveryCode">
                    {{ recoveryCode }}
                </li>
            </ul>
        </div>
    </section>
</template>
