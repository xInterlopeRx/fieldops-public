<script setup lang="ts">
import { Head, Link, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import InputError from "@/Components/InputError.vue";
import InputLabel from "@/Components/InputLabel.vue";
import PrimaryButton from "@/Components/PrimaryButton.vue";
import TextInput from "@/Components/TextInput.vue";

const form = useForm({
    password: "",
    totp_code: "",
});

const unlock = (): void => {
    form.post(route("admin.onboarding.access.unlock"), {
        onFinish: () => form.reset("password", "totp_code"),
    });
};
</script>

<template>
    <Head title="Unlock onboarding and integrations" />
    <AdminLayout title="Unlock onboarding and integrations">
        <div class="mx-auto grid max-w-3xl gap-6 px-4 py-8 sm:px-6 lg:px-8">
            <header class="grid gap-2">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-orange-800 dark:text-orange-300">
                    Protected operations area
                </p>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">
                    Confirm your identity
                </h1>
                <p class="max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-400">
                    QuickBooks, Bitwarden, Microsoft Graph, and first-run setup are grouped here. Enter your current password and a fresh authenticator code to open them. Access closes after 10 minutes without activity.
                </p>
            </header>

            <form
                class="grid max-w-xl gap-4 rounded-lg border border-orange-300 bg-white p-5 shadow-sm dark:border-orange-800 dark:bg-gray-900"
                @submit.prevent="unlock"
            >
                <div>
                    <InputLabel for="onboarding-area-password" value="Current password" />
                    <TextInput
                        id="onboarding-area-password"
                        v-model="form.password"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="mt-1 block w-full"
                    />
                    <InputError class="mt-2" :message="form.errors.password" />
                </div>

                <div>
                    <InputLabel for="onboarding-area-totp" value="Authenticator code" />
                    <TextInput
                        id="onboarding-area-totp"
                        v-model="form.totp_code"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        required
                        class="mt-1 block w-full"
                    />
                    <InputError class="mt-2" :message="form.errors.totp_code" />
                </div>
                <div class="flex flex-wrap items-center gap-4">
                    <PrimaryButton :disabled="form.processing">
                        {{ form.processing ? "Checking…" : "Unlock onboarding area" }}
                    </PrimaryButton>
                    <Link
                        :href="route('calendar')"
                        class="text-sm font-semibold text-gray-700 underline hover:text-gray-950 dark:text-gray-300 dark:hover:text-white"
                    >
                        Return to Schedule
                    </Link>
                </div>
            </form>
        </div>
    </AdminLayout>
</template>
