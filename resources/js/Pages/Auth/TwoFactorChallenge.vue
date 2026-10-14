<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const recoveryMode = ref(false);
const form = useForm({
    code: '',
    recovery_code: '',
    trust_device: false,
});

const submit = () => {
    form.transform((data) =>
        recoveryMode.value
            ? { recovery_code: data.recovery_code }
            : { code: data.code, trust_device: data.trust_device },
    ).post(route('two-factor.login.store'), {
        onFinish: () => form.reset('code', 'recovery_code', 'trust_device'),
    });
};

const toggleRecoveryMode = () => {
    recoveryMode.value = !recoveryMode.value;
    form.reset('code', 'recovery_code', 'trust_device');
    form.clearErrors();
};
</script>

<template>
    <GuestLayout>
        <Head title="Verify sign in | FieldOps" />

        <div class="mb-6">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
                Verify your sign in
            </h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                Enter the code from your authenticator app to continue.
            </p>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <div v-if="!recoveryMode">
                <InputLabel for="code" value="Authentication code" />
                <TextInput
                    id="code"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    required
                    autofocus
                    class="mt-1 block w-full"
                />
                <InputError :message="form.errors.code" class="mt-2" />

                <label for="trust-device" class="mt-4 flex cursor-pointer items-start gap-3">
                    <Checkbox id="trust-device" v-model:checked="form.trust_device" class="mt-0.5" />
                    <span class="grid gap-1">
                        <span class="text-sm font-medium text-gray-800 dark:text-gray-200">Trust this device for 14 days</span>
                        <span class="text-sm text-gray-600 dark:text-gray-400">Use only on a private device. You will not be asked for an authenticator code on this device during that period.</span>
                    </span>
                </label>
            </div>

            <div v-else>
                <InputLabel for="recovery_code" value="Recovery code" />
                <TextInput
                    id="recovery_code"
                    v-model="form.recovery_code"
                    type="text"
                    autocomplete="one-time-code"
                    required
                    autofocus
                    class="mt-1 block w-full"
                />
                <InputError :message="form.errors.recovery_code" class="mt-2" />
            </div>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <button
                    type="button"
                    class="text-sm text-gray-700 underline hover:text-gray-900 focus:outline-none focus:ring-2 focus:ring-fieldops-orange focus:ring-offset-2 dark:text-gray-300 dark:hover:text-white dark:focus:ring-offset-gray-800"
                    @click="toggleRecoveryMode"
                >
                    {{ recoveryMode ? 'Use an authenticator code' : 'Use a recovery code' }}
                </button>

                <PrimaryButton :disabled="form.processing">
                    {{ form.processing ? 'Verifying…' : 'Verify and continue' }}
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
