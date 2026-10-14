<script setup lang="ts">
import { Head, useForm } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";

interface BitwardenConnection {
    organizationId: string;
    projectId: string;
    apiUrl: string;
    identityUrl: string;
    verificationStatus: string;
    verificationProfile: string;
    configuredAt: string | null;
    lastVerifiedAt: string | null;
    lastErrorCode: string | null;
}

const props = defineProps<{
    environment: string;
    apiUrl: string;
    identityUrl: string;
    twoFactorEnabled: boolean;
    requiredSecretsVerified: boolean;
    verificationProfile: string;
    requiredSecretNames: string[];
    endpointConfigurationChanged: boolean;
    connection: BitwardenConnection | null;
    status: string | null;
    error: string | null;
}>();

const form = useForm({
    runtime_access_token: "",
    organization_id: props.connection?.organizationId ?? "",
    project_id: props.connection?.projectId ?? "",
    password: "",
});

const configure = (): void => {
    form.post(route("admin.integrations.bitwarden.store"), {
        preserveScroll: true,
        onFinish: () => {
            // Inertia keeps form values in the page after validation failures unless cleared.
            form.runtime_access_token = "";
            form.password = "";
        },
    });
};

const formattedDate = (value: string | null): string | null =>
    value ? new Date(value).toLocaleString() : null;
</script>

<template>
    <Head title="Bitwarden Secrets Manager" />

    <AdminLayout title="Integrations">
        <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <p
                v-if="status"
                role="status"
                class="rounded border border-green-200 bg-green-50 p-4 text-sm text-green-900"
            >
                {{ status }}
            </p>
            <p
                v-if="error"
                role="alert"
                class="rounded border border-red-200 bg-red-50 p-4 text-sm text-red-900"
            >
                {{ error }}
            </p>

            <section class="fieldops-panel space-y-4 bg-white p-6" aria-labelledby="bws-status-title">
                <div>
                    <h1 id="bws-status-title" class="text-lg font-semibold">Runtime connection</h1>
                    <p class="mt-1 text-sm text-gray-600">
                        Each deployment uses its own BWS project and runtime machine account.
                        Provision the runtime account as Can Read before entering its token here.
                    </p>
                </div>

                <div v-if="connection" class="space-y-2 text-sm">
                    <p v-if="requiredSecretsVerified" class="font-semibold text-green-800">
                        BWS Can Read access and selected app-secret profile verified
                    </p>
                    <p v-else class="font-semibold text-amber-900">
                        Connection requires verification
                    </p>
                    <p>
                        Environment:
                        <span class="font-medium">{{ environment }}</span>
                    </p>
                    <p>
                        Organization ID:
                        <code>{{ connection.organizationId }}</code>
                    </p>
                    <p>
                        Project ID:
                        <code>{{ connection.projectId }}</code>
                    </p>
                    <p>
                        BWS service:
                        <code class="break-all">{{ connection.apiUrl }}</code>
                    </p>
                    <p v-if="connection.lastVerifiedAt">
                        Last verified:
                        <time :datetime="connection.lastVerifiedAt">
                            {{ formattedDate(connection.lastVerifiedAt) }}
                        </time>
                    </p>
                    <p v-if="connection.lastErrorCode" role="status" class="text-amber-900">
                        Last sanitized verification result: <code>{{ connection.lastErrorCode }}</code>
                    </p>
                    <p v-if="!requiredSecretsVerified && connection.verificationStatus === 'read_verified'" role="status" class="rounded bg-amber-50 p-3 text-amber-950">
                        This connection predates the selected-secret-profile validation. Re-enter the Can Read token and verify the listed app secrets before runtime access resumes.
                    </p>
                    <p
                        v-if="endpointConfigurationChanged"
                        role="status"
                        class="rounded bg-amber-50 p-3 text-amber-950"
                    >
                        The deployment's BWS service endpoints changed since this connection was
                        verified. Re-enter the Can Read token and verify the current project before
                        runtime access resumes.
                    </p>
                    <p class="rounded bg-amber-50 p-3 text-amber-950">
                        FieldOps only reads BWS; it never creates or updates secrets. The Can Read check confirms the selected profile's values are present and non-empty without showing or storing them.
                    </p>
                </div>
                <p v-else class="text-sm text-gray-600">
                    No runtime BWS connection is configured for {{ environment }}.
                </p>
            </section>

            <section class="fieldops-panel space-y-4 bg-white p-6" aria-labelledby="bws-config-title">
                <div>
                    <h2 id="bws-config-title" class="text-lg font-semibold">
                        {{ connection ? "Replace runtime reader token or project" : "Configure runtime reader" }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Enter the identifiers for the environment's dedicated BWS project and a
                        Can Read machine-account token. FieldOps verifies project access and reads
                        the selected import mode's required credential names before saving the encrypted token.
                        Secret values and the token are never returned to the page.
                    </p>
                </div>

                <p
                    v-if="!twoFactorEnabled"
                    role="alert"
                    class="rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
                >
                    Confirm TOTP two-factor authentication in your profile before configuring an integration.
                    <a :href="route('profile.edit')" class="font-semibold underline">Open profile security settings</a>.
                </p>

                <form class="grid gap-4" @submit.prevent="configure">
                    <label class="grid gap-1 text-sm">
                        <span>BWS runtime Can Read token</span>
                        <input
                            v-model="form.runtime_access_token"
                            class="fieldops-client-input"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            required
                            :aria-invalid="Boolean(form.errors.runtime_access_token)"
                            aria-describedby="bws-token-help bws-token-error"
                        />
                        <span id="bws-token-help" class="text-xs text-gray-600">
                            Use the Can Read machine-account token for this dedicated environment project. Never paste it into a terminal or support message.
                        </span>
                        <span v-if="form.errors.runtime_access_token" id="bws-token-error" class="text-sm text-red-700" role="alert">
                            {{ form.errors.runtime_access_token }}
                        </span>
                    </label>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm">
                            <span>Organization ID</span>
                            <input
                                v-model="form.organization_id"
                                class="fieldops-client-input"
                                autocomplete="off"
                                required
                                :aria-invalid="Boolean(form.errors.organization_id)"
                            />
                            <span v-if="form.errors.organization_id" class="text-sm text-red-700" role="alert">
                                {{ form.errors.organization_id }}
                            </span>
                        </label>
                        <label class="grid gap-1 text-sm">
                            <span>Environment project ID</span>
                            <input
                                v-model="form.project_id"
                                class="fieldops-client-input"
                                autocomplete="off"
                                required
                                :aria-invalid="Boolean(form.errors.project_id)"
                            />
                            <span v-if="form.errors.project_id" class="text-sm text-red-700" role="alert">
                                {{ form.errors.project_id }}
                            </span>
                        </label>
                    </div>

                    <div class="grid gap-2 text-sm text-gray-700">
                        <p class="font-medium">Provision these exact, non-empty secret names in the BWS project first:</p>
                        <ul class="list-inside list-disc font-mono text-xs">
                            <li v-for="secretName in requiredSecretNames" :key="secretName">{{ secretName }}</li>
                        </ul>
                        <p v-if="verificationProfile === 'graph_only'">QuickBooks synchronization is not opted in, so only Microsoft Graph app credentials are required. Opt into QuickBooks in First-run setup to require the Intuit credentials here.</p>
                        <p v-else>QuickBooks synchronization is opted in, so both Intuit and Microsoft Graph app credentials must be present and verified. Run this Can Read check after opting in, even if the project was verified earlier.</p>
                        <p>The verification reads these values only to check presence and size. It never returns or saves the application credential values.</p>
                    </div>

                    <dl class="grid gap-2 break-all text-sm sm:grid-cols-[10rem_1fr]">
                        <dt class="font-medium text-gray-700">BWS API URL</dt>
                        <dd><code>{{ apiUrl }}</code></dd>
                        <dt class="font-medium text-gray-700">BWS identity URL</dt>
                        <dd><code>{{ identityUrl }}</code></dd>
                    </dl>
                    <p class="text-xs text-gray-600">
                        Service endpoints are deployment-controlled and cannot be changed in this request.
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="grid gap-1 text-sm">
                            <span>Current password</span>
                            <input
                                v-model="form.password"
                                class="fieldops-client-input"
                                type="password"
                                autocomplete="current-password"
                                required
                                :aria-invalid="Boolean(form.errors.password)"
                            />
                            <span v-if="form.errors.password" class="text-sm text-red-700" role="alert">
                                {{ form.errors.password }}
                            </span>
                        </label>
                    </div>

                    <p class="text-xs text-gray-600">
                        Password and token are cleared from the page when the request finishes.
                    </p>

                    <button
                        class="fieldops-submit-action"
                        type="submit"
                        :disabled="form.processing || !twoFactorEnabled"
                    >
                        {{ form.processing ? "Verifying project and app secrets…" : "Verify secrets and save runtime reader" }}
                    </button>
                </form>
            </section>

        </div>
    </AdminLayout>
</template>
