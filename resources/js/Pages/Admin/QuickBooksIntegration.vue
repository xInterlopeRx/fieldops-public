<script setup lang="ts">
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import { computed, onMounted, onUnmounted } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";

interface QuickBooksConnection {
    environment: string;
    realmId: string | null;
    connectedAt: string | null;
    connected: boolean;
}

interface ItemCatalogSync {
    status: string;
    totalItems: number;
    processedItems: number;
    importedItems: number;
    activeItems: number;
    selectableItems: number;
    failureCount: number;
    failureCode: string | null;
    canResume: boolean;
    canSync: boolean;
    updatedAt: string | null;
}

const props = defineProps<{
    configured: boolean;
    apiModeEnabled: boolean;
    environment: string;
    redirectUri: string;
    oauthRequiresLocalhost: boolean;
    missingConfiguration: string[];
    connection: QuickBooksConnection | null;
    quickBooksSyncEnabled: boolean;
    quickBooksOptedIn: boolean;
    quickBooksSyncEnableBlocker: string | null;
    quickBooksSyncControlAvailable: boolean;
    quickBooksPausedJobs: { count: number; truncated: boolean };
    itemCatalog: ItemCatalogSync;
    status: string | null;
    error: string | null;
}>();

const itemSyncForm = useForm({ confirm_sync: false, password: "", item_import: "" });
const quickBooksSyncForm = useForm({
    enabled: props.quickBooksSyncEnabled,
    confirm_change: false,
    password: "",
});
const quickBooksSyncControlError = computed(
    () => (quickBooksSyncForm.errors as Record<string, string>).quickbooks_sync,
);

const syncItems = (): void => {
    itemSyncForm.transform((data) => ({ confirm_sync: data.confirm_sync, password: data.password })).post(route("admin.integrations.quickbooks.items.sync"), {
        preserveScroll: true,
        onFinish: () => itemSyncForm.reset("password", "confirm_sync"),
    });
};

const saveQuickBooksSyncControl = (): void => {
    if (
        !quickBooksSyncForm.confirm_change ||
        quickBooksSyncForm.enabled === props.quickBooksSyncEnabled
    ) {
        return;
    }

    quickBooksSyncForm.put(route("admin.integrations.quickbooks.sync-control.update"), {
        preserveScroll: true,
        onFinish: () => quickBooksSyncForm.reset("confirm_change", "password"),
    });
};

let itemProgressRefresh: number | undefined;

onMounted(() => {
    itemProgressRefresh = window.setInterval(() => {
        if (props.quickBooksSyncEnabled && props.itemCatalog.status === "in_progress") {
            router.reload({ only: ["itemCatalog"] });
        }
    }, 5000);
});

onUnmounted(() => {
    if (itemProgressRefresh !== undefined) window.clearInterval(itemProgressRefresh);
});
</script>

<template>
    <Head title="QuickBooks integration" />

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

            <section class="fieldops-panel space-y-4 bg-white p-6" aria-labelledby="quickbooks-sync-control-title">
                <div>
                    <h2 id="quickbooks-sync-control-title" class="text-lg font-semibold">QuickBooks synchronization</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Pause or resume QuickBooks Customer/Site reads and writes, Item sync, and API imports. The OAuth connection and local records are retained. Queued QuickBooks work is held while paused and resumes when enabled; a request already in progress may finish. CSV-only import mode remains local regardless of this switch.
                    </p>
                </div>
                <p v-if="!quickBooksSyncEnabled && quickBooksSyncEnableBlocker" class="rounded bg-amber-50 p-3 text-sm text-amber-950" role="status">
                    {{ quickBooksSyncEnableBlocker }}
                    <Link :href="route('admin.onboarding')" class="ml-1 font-semibold underline">Open First-run setup</Link>
                </p>
                <p :class="quickBooksSyncEnabled ? 'text-sm font-semibold text-green-800' : 'rounded bg-amber-50 p-3 text-sm font-semibold text-amber-950'" role="status">
                    QuickBooks sync {{ quickBooksSyncEnabled ? 'enabled' : 'paused' }}<span v-if="!quickBooksSyncEnabled"> · {{ quickBooksPausedJobs.truncated ? '100 or more' : quickBooksPausedJobs.count }} queued job(s) held</span>
                </p>
                <form v-if="quickBooksSyncControlAvailable" class="grid gap-3 sm:max-w-xl" @submit.prevent="saveQuickBooksSyncControl">
                    <label class="flex min-h-11 items-center gap-3 text-sm text-gray-800">
                        <input v-model="quickBooksSyncForm.enabled" type="checkbox" role="switch" class="rounded border-gray-300 text-blue-700 focus:ring-blue-700" :disabled="!quickBooksSyncEnabled && Boolean(quickBooksSyncEnableBlocker)" />
                        <span>Enable QuickBooks synchronization</span>
                    </label>
                    <label class="flex items-start gap-2 text-sm text-gray-800">
                        <input v-model="quickBooksSyncForm.confirm_change" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                        <span>{{ quickBooksSyncForm.enabled ? 'I authorize held QuickBooks jobs to resume.' : 'I understand queued QuickBooks work will be held; an in-flight request may finish.' }}</span>
                    </label>
                    <div>
                        <label for="quickbooks-sync-password" class="text-sm font-medium text-gray-700">Current password</label>
                        <input id="quickbooks-sync-password" v-model="quickBooksSyncForm.password" type="password" autocomplete="current-password" required class="fieldops-client-input mt-1 block w-full" />
                        <p v-if="quickBooksSyncForm.errors.password" class="mt-2 text-sm text-red-700" role="alert">{{ quickBooksSyncForm.errors.password }}</p>
                    </div>
                    <p v-if="quickBooksSyncControlError" class="text-sm text-red-700" role="alert">{{ quickBooksSyncControlError }}</p>
                    <button type="submit" class="fieldops-submit-action w-fit" :disabled="quickBooksSyncForm.processing || !quickBooksSyncForm.confirm_change || quickBooksSyncForm.enabled === quickBooksSyncEnabled || (quickBooksSyncForm.enabled && Boolean(quickBooksSyncEnableBlocker))">
                        {{ quickBooksSyncForm.processing ? 'Saving…' : quickBooksSyncForm.enabled ? 'Enable QuickBooks sync' : 'Pause QuickBooks sync' }}
                    </button>
                </form>
                <p v-else class="rounded bg-amber-50 p-3 text-sm text-amber-950" role="status">
                    This control requires the shared database queue. Ask an administrator to configure the managed database worker before changing QuickBooks sync state.
                </p>
            </section>

            <section class="fieldops-panel space-y-4 bg-white p-6">
                <div>
                    <h1 class="text-lg font-semibold">Connection status</h1>
                    <p class="mt-1 text-sm text-gray-600">
                        Create and edit FieldOps customer profiles, default
                        billing/shipping addresses, and new Sites through
                        sandbox write-back. Opening a linked
                        customer also checks QuickBooks when the local copy is
                        stale. Item catalog sync is read-only; FieldOps never
                        creates Items or stores QuickBooks prices, costs,
                        accounting references, or stock quantities.
                    </p>
                </div>

                <div v-if="connection" class="space-y-2 text-sm">
                    <p v-if="connection.connected" class="font-semibold text-green-800">Connected</p>
                    <p v-else-if="!apiModeEnabled" role="status" class="rounded bg-amber-50 p-3 text-amber-900">
                        CSV import mode is local-only. QuickBooks API reads, synchronization, OAuth reconnect, and write-back are disabled.
                    </p>
                    <p v-else role="status" class="rounded bg-amber-50 p-3 text-amber-900">
                        This saved QuickBooks authorization is not usable in the current environment. Reconnect to QuickBooks to establish a fresh company authorization; the existing connection is retained until the new authorization succeeds.
                    </p>
                    <p>
                        Environment:
                        <span class="font-medium">{{ connection.environment }}</span>
                    </p>
                    <p v-if="connection.realmId">
                        QuickBooks company ID:
                        <code>{{ connection.realmId }}</code>
                    </p>
                    <p v-if="connection.connectedAt">
                        Connected:
                        <time :datetime="connection.connectedAt">
                            {{ new Date(connection.connectedAt).toLocaleString() }}
                        </time>
                    </p>
                    <p
                        v-if="connection.environment !== environment"
                        class="rounded bg-amber-50 p-3 text-amber-900"
                    >
                        The saved connection is for {{ connection.environment }},
                        but the current configuration is {{ environment }}.
                        Reconnect before syncing.
                    </p>
                </div>
                <p v-else class="text-sm text-gray-600">Not connected.</p>
            </section>

            <section v-if="apiModeEnabled && quickBooksOptedIn" class="fieldops-panel space-y-4 bg-white p-6" aria-labelledby="item-catalog-title">
                <div>
                    <h2 id="item-catalog-title" class="text-lg font-semibold">QuickBooks Item catalog</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Sync existing Items for Visit usage. The catalog retains only Item ID, name, description, type, and active status. The QuickBooks API response is filtered in memory; financial fields are not persisted, logged, or displayed.
                    </p>
                </div>
                <dl class="grid gap-2 text-sm sm:grid-cols-[12rem_1fr]">
                    <dt class="font-medium text-gray-700">Sync status</dt>
                    <dd>{{ itemCatalog.status }}</dd>
                    <dt class="font-medium text-gray-700">Active Items</dt>
                    <dd>{{ itemCatalog.activeItems }}</dd>
                    <dt class="font-medium text-gray-700">Selectable Items</dt>
                    <dd>{{ itemCatalog.selectableItems }}</dd>
                    <template v-if="itemCatalog.status === 'in_progress'">
                        <dt class="font-medium text-gray-700">Import progress</dt>
                        <dd>{{ itemCatalog.importedItems }} / {{ itemCatalog.totalItems }}</dd>
                    </template>
                    <template v-if="itemCatalog.failureCode">
                        <dt class="font-medium text-gray-700">Last failure</dt>
                        <dd class="font-mono text-red-700">{{ itemCatalog.failureCode }}</dd>
                    </template>
                </dl>
                <form v-if="connection?.connected && itemCatalog.canSync" class="grid gap-3 sm:max-w-xl" @submit.prevent="syncItems">
                    <label class="flex items-start gap-2 text-sm text-gray-800">
                        <input v-model="itemSyncForm.confirm_sync" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                        <span>Confirm a read-only sync of existing QuickBooks Items. No QuickBooks Item create/update operation will be issued.</span>
                    </label>
                    <div>
                        <label for="qbo-item-sync-password" class="text-sm font-medium text-gray-700">Current password</label>
                        <input id="qbo-item-sync-password" v-model="itemSyncForm.password" type="password" autocomplete="current-password" required class="fieldops-client-input mt-1 block w-full" />
                        <p v-if="itemSyncForm.errors.password || itemSyncForm.errors.item_import" class="mt-2 text-sm text-red-700" role="alert">
                            {{ itemSyncForm.errors.password || itemSyncForm.errors.item_import }}
                        </p>
                    </div>
                    <button type="submit" class="fieldops-submit-action w-fit" :disabled="itemSyncForm.processing || !itemSyncForm.confirm_sync">
                        {{ itemSyncForm.processing ? 'Starting…' : itemCatalog.status === 'complete' ? 'Refresh Item catalog' : 'Sync Item catalog' }}
                    </button>
                </form>
                <p v-else-if="connection?.connected && itemCatalog.status === 'in_progress'" class="text-sm text-blue-800" role="status">
                    Item catalog sync is running. This page refreshes progress automatically.
                </p>
                <p v-else-if="connection?.connected && !itemCatalog.canSync" class="text-sm text-amber-900" role="status">
                    Complete the QuickBooks Customer/Site first-run phase before syncing the Item catalog during onboarding.
                </p>
                <p v-else-if="apiModeEnabled" class="text-sm text-amber-900" role="status">
                    Connect the intended QuickBooks company before syncing its Item catalog.
                </p>
            </section>

            <section v-else class="fieldops-panel space-y-3 bg-white p-6" aria-labelledby="item-catalog-title">
                <div>
                    <h2 id="item-catalog-title" class="text-lg font-semibold">QuickBooks Item catalog</h2>
                    <p v-if="!apiModeEnabled" class="mt-1 text-sm text-gray-600" role="status">
                        The selected CSV source is local-only, so this page cannot read QuickBooks Items. Item-based features remain unavailable, but Items are not required for invitations while QuickBooks sync is off.
                    </p>
                    <p v-else class="mt-1 text-sm text-gray-600" role="status">
                        QuickBooks synchronization is opted out, so the Item catalog is not loaded or required for invitations. Opt in from First-run setup before using QuickBooks Item features.
                    </p>
                </div>
                <Link :href="route('admin.onboarding')" class="w-fit font-semibold text-blue-800 underline underline-offset-2 dark:text-blue-300">
                    Open First-run setup
                </Link>
            </section>

            <section class="fieldops-panel space-y-4 bg-white p-6">
                <div>
                    <h2 class="text-lg font-semibold">Connect to Intuit</h2>
                    <p class="mt-1 text-sm text-gray-600">
                        Sign in to Intuit and authorize the QuickBooks Online
                        Accounting scope. Use a sandbox company while
                        <span class="font-medium">sandbox</span> is selected.
                        The Intuit app credentials are resolved from the verified
                        BWS project using <code>INTUIT_CLIENT_ID</code> and
                        <code>INTUIT_CLIENT_SECRET</code>; keep both names present
                        in that project before connecting.
                    </p>
                </div>

                <p v-if="!apiModeEnabled" role="status" class="rounded border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
                    The selected first-run source is CSV. No Intuit app credentials are required in this mode; this page cannot connect or synchronize a QuickBooks company.
                    <Link :href="route('admin.onboarding')" class="ml-1 font-semibold underline">Review the source choice</Link>
                </p>
                <p v-else-if="!quickBooksOptedIn" role="status" class="rounded border border-blue-200 bg-blue-50 p-4 text-sm text-blue-950">
                    QuickBooks synchronization is not opted in. No Intuit app credentials or company authorization are required until you opt in from First-run setup.
                    <Link :href="route('admin.onboarding')" class="ml-1 font-semibold underline">Open First-run setup</Link>
                </p>

                <dl class="grid gap-2 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt class="font-medium text-gray-700">Environment</dt>
                    <dd>{{ environment }}</dd>
                    <dt class="font-medium text-gray-700">Redirect URI</dt>
                    <dd class="break-all">
                        <code>{{ redirectUri || "Not configured" }}</code>
                    </dd>
                </dl>

                <p
                    v-if="oauthRequiresLocalhost"
                    role="status"
                    class="rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
                >
                    This QuickBooks callback is localhost-only. To connect or reconnect, open FieldOps at localhost on the host computer; localhost on another device refers to that device, not this server.
                </p>

                <div
                    v-if="missingConfiguration.length > 0"
                    class="rounded border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"
                >
                    <p class="font-semibold">
                        Complete the local QuickBooks configuration before
                        connecting:
                    </p>
                    <ul class="mt-2 list-inside list-disc">
                        <li v-for="key in missingConfiguration" :key="key">
                            <code>{{ key }}</code>
                        </li>
                    </ul>
                </div>

                <a
                    v-if="quickBooksOptedIn && apiModeEnabled && configured && !oauthRequiresLocalhost"
                    :href="route('admin.integrations.quickbooks.redirect')"
                    class="fieldops-submit-action inline-flex items-center justify-center"
                >
                    {{ connection ? "Reconnect to QuickBooks" : "Connect to QuickBooks" }}
                </a>
                <button
                    v-else-if="quickBooksOptedIn && apiModeEnabled && configured"
                    type="button"
                    disabled
                    class="fieldops-submit-action cursor-not-allowed opacity-60"
                >
                    Connect from localhost on the host
                </button>
                <button
                    v-else-if="quickBooksOptedIn && apiModeEnabled"
                    type="button"
                    disabled
                    class="fieldops-submit-action cursor-not-allowed opacity-60"
                >
                    Configure credentials to connect
                </button>
            </section>
        </div>
    </AdminLayout>
</template>
