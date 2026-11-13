<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

type SetupStep = {
    key: string;
    label: string;
    status: string;
    lastAttemptedAt: string | null;
    completedAt: string | null;
    failureCode: string | null;
    attemptCount: number;
    progress: { current: number | null; total: number } | null;
    message: string | null;
};

const props = defineProps<{
    readiness: {
        required: boolean;
        legacy: boolean;
        ready: boolean;
        invitationsUnlocked: boolean;
        canComplete: boolean;
        quickBooksOptedIn: boolean;
        canOptInQuickBooks: boolean;
        canOptOutQuickBooks: boolean;
        canDeferBwsRuntimeReader: boolean;
        canResumeBwsRuntimeReader: boolean;
        canResumeInitialImport: boolean;
        canStartCustomerSiteImport: boolean;
        canPreviewItemCatalogImport: boolean;
        canStartItemCatalogImport: boolean;
        canResumeItemCatalogImport: boolean;
        canResumeCustomerSiteImport: boolean;
        customerSiteImport: {
            status: string;
            source: string;
            customersImported: number;
            sitesImported: number;
            customersProcessed: number;
            customersTotal: number;
            failureCount: number;
            failures: { recordId: string; row: number | null; code: string }[];
            failureCode: string | null;
            canResume: boolean;
        };
        itemCatalog: {
            status: string;
            totalItems: number;
            processedItems: number;
            importedItems: number;
            activeItems: number;
            selectableItems: number;
            failureCount: number;
            failures: { itemId: string | null; position: number; code: string }[];
            failureCode: string | null;
            canResume: boolean;
            updatedAt: string | null;
        };
        completedAt: string | null;
        completedBy: string | null;
        blockers: string[];
        steps: SetupStep[];
    };
    status?: string | null;
    error?: string | null;
    qboImportPreview?: { customers: number; qboRecords: number; potentialSites: number } | null;
    qboItemImportPreview?: { items: number } | null;
    fieldpulseImportPreview?: {
        source: string;
        customers: number;
        locations: number;
        projects: number;
        jobs: number;
        jobs_with_project_reference: number;
        jobs_with_unmatched_project_reference: number;
        jobs_with_project_customer_mismatch: number;
        notes: number;
        contacts: number;
        existingRun: FieldPulseRunSummary | null;
        conflictingSource: boolean;
    } | null;
    fieldpulseImport?: FieldPulseRunSummary | null;
    quickBooksFilePreview?: {
        rows: number;
        customers: number;
        billingAddresses: number;
        shippingAddresses: number;
        duplicateRows: number;
        source: string;
        conflictCount: number;
        existingRun: { status: string } | null;
        conflictingSource: boolean;
        canStart: boolean;
    } | null;
    quickBooksFileImport?: {
        status: string;
        customers_imported: number;
        customers_total: number;
        failure_count: number;
        failure_code: string | null;
    } | null;
}>();

type FieldPulseRunSummary = {
    status: string;
    stage: string;
    customers_imported: number;
    customers_total: number;
    sites_imported: number;
    projects_imported: number;
    contacts_imported: number;
    jobs_imported: number;
    notes_imported: number;
    comments_imported: number;
    failure_count: number;
    failure_code: string | null;
    document_sha256?: string | null;
    document_retained_at?: string | null;
    failures?: Array<{ resource_type: string; source_record_id: string; failure_code: string }>;
};

const quickBooksOptInForm = useForm({ opted_in: true, confirm_change: false, password: '' });
const importPreviewForm = useForm({ password: '', quickbooks_import: '' });
const importStartForm = useForm({ confirm_import: false, password: '', quickbooks_import: '' });
const importResumeForm = useForm({ confirm_import: false, password: '', quickbooks_import: '' });
const itemPreviewForm = useForm({ password: '', item_import: '' });
const itemStartForm = useForm({ confirm_import: false, password: '', item_import: '' });
const itemResumeForm = useForm({ confirm_import: false, password: '', item_import: '' });
const fieldpulsePreviewForm = useForm({ document: null as File | null, password: '', fieldpulse: '' });
const fieldpulseStartForm = useForm({ document: null as File | null, confirm_import: false, password: '', fieldpulse: '' });
const fieldpulseResumeForm = useForm({ password: '', fieldpulse: '' });

const fieldpulseRefreshing = ref(false);
let fieldpulsePollTimer: number | undefined;

const fieldpulseStatusView = computed(() => {
    const summary = props.fieldpulseImport;
    if (! summary) {
        return null;
    }
    if (summary.status === 'in_progress') {
        return { label: 'Import running', tone: 'blue', detail: `Stage: ${summary.stage}` };
    }
    if (summary.status === 'customer_site_complete' && summary.failure_count === 0) {
        return { label: 'Import complete', tone: 'green', detail: 'All FieldPulse records imported without failures.' };
    }
    if (summary.status === 'customer_site_complete') {
        return { label: 'Completed with records needing attention', tone: 'amber', detail: `${summary.failure_count} record(s) recorded as failures.` };
    }
    if (summary.status === 'failed') {
        return { label: 'Import failed', tone: 'red', detail: summary.failure_code ?? '' };
    }

    return { label: summary.status, tone: 'slate', detail: `Stage: ${summary.stage}` };
});

const fieldpulsePillClass = computed(() => {
    const tones: Record<string, string> = {
        blue: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        green: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        amber: 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        red: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        slate: 'bg-slate-100 text-slate-800 dark:bg-slate-700 dark:text-slate-200',
    };

    return tones[fieldpulseStatusView.value?.tone ?? 'slate'] ?? tones.slate;
});

const fieldpulseStatBlocks = computed(() => {
    const summary = props.fieldpulseImport;
    if (! summary) {
        return [];
    }

    return [
        { label: 'Customers', value: summary.customers_total > 0 ? `${summary.customers_imported} / ${summary.customers_total}` : String(summary.customers_imported) },
        { label: 'Contacts', value: String(summary.contacts_imported) },
        { label: 'Sites', value: String(summary.sites_imported) },
        { label: 'Projects', value: String(summary.projects_imported) },
        { label: 'Jobs', value: String(summary.jobs_imported) },
        { label: 'Notes', value: String(summary.notes_imported) },
    ];
});

const fieldpulseStageProgress = computed(() => {
    const summary = props.fieldpulseImport;
    if (! summary || summary.status !== 'in_progress') {
        return null;
    }

    const stages = ['customers', 'locations', 'jobs', 'notes'];
    const labels = ['Customers', 'Locations', 'Jobs', 'Notes'];
    const stageIndex = stages.indexOf(summary.stage);
    const done = summary.stage === 'complete' ? stages.length : Math.max(0, stageIndex);

    return {
        percent: Math.round((done / stages.length) * 100),
        stages: labels.map((label, i) => ({ label, done: i < done })),
    };
});

function refreshFieldPulseImport(): void {
    if (fieldpulseRefreshing.value) {
        return;
    }

    fieldpulseRefreshing.value = true;
    router.reload({
        only: ['fieldpulseImport'],
        onFinish: () => {
            fieldpulseRefreshing.value = false;
        },
    });
}

onMounted(() => {
    fieldpulsePollTimer = window.setInterval(() => {
        if (props.fieldpulseImport?.status === 'in_progress') {
            refreshFieldPulseImport();
        }
    }, 4000);
});

onUnmounted(() => {
    if (fieldpulsePollTimer !== undefined) {
        window.clearInterval(fieldpulsePollTimer);
    }
});
const qbFilePreviewForm = useForm({ quickbooks_file: null as File | null, password: '', quickbooks_file_field: '' });
const qbFileStartForm = useForm({ quickbooks_file: null as File | null, confirm_import: false, password: '', quickbooks_file_field: '' });
const legacyResumeForm = useForm({ password: '', onboarding: '' });
const deferBwsForm = useForm({ confirm_defer: false, password: '', onboarding: '' });
const resumeBwsForm = useForm({ password: '', onboarding: '' });
const completeForm = useForm({ confirm_complete: false, password: '', readiness: '' });
const reconcilingFailures = ref(false);
const blockedSteps = computed(() => props.readiness.steps.filter((step) => props.readiness.blockers.includes(step.label)));

function updateQuickBooksOptIn() {
    quickBooksOptInForm.put(route('admin.onboarding.quickbooks-opt-in.update'), {
        preserveScroll: true,
        onFinish: () => quickBooksOptInForm.reset('confirm_change', 'password'),
    });
}

function deferQuickBooksSetup() {
    quickBooksOptInForm.opted_in = false;
    updateQuickBooksOptIn();
}

function previewCustomerSiteImport() {
    importPreviewForm.transform((data) => ({ password: data.password })).post(route('admin.onboarding.initial-import.customer-sites.preview'), {
        preserveScroll: true,
        onFinish: () => importPreviewForm.reset('password'),
    });
}

function startCustomerSiteImport() {
    importStartForm.transform((data) => ({
        confirm_import: data.confirm_import,
        password: data.password,
    })).post(route('admin.onboarding.initial-import.customer-sites.start'), {
        preserveScroll: true,
        onFinish: () => importStartForm.reset('password'),
    });
}

function resumeCustomerSiteImport() {
    importResumeForm.transform((data) => ({
        confirm_import: data.confirm_import,
        password: data.password,
    })).post(route('admin.onboarding.initial-import.customer-sites.resume'), {
        preserveScroll: true,
        onFinish: () => importResumeForm.reset('password'),
    });
}

function previewItemCatalogImport() {
    itemPreviewForm.transform((data) => ({ password: data.password })).post(route('admin.onboarding.initial-import.items.preview'), {
        preserveScroll: true,
        onFinish: () => itemPreviewForm.reset('password'),
    });
}

function startItemCatalogImport() {
    itemStartForm.transform((data) => ({
        confirm_import: data.confirm_import,
        password: data.password,
    })).post(route('admin.onboarding.initial-import.items.start'), {
        preserveScroll: true,
        onFinish: () => itemStartForm.reset('password'),
    });
}

function resumeItemCatalogImport() {
    itemResumeForm.transform((data) => ({
        confirm_import: data.confirm_import,
        password: data.password,
    })).post(route('admin.onboarding.initial-import.items.resume'), {
        preserveScroll: true,
        onFinish: () => itemResumeForm.reset('password'),
    });
}

function selectQuickBooksFile(event: Event) {
    const input = event.target as HTMLInputElement;
    qbFilePreviewForm.quickbooks_file = input.files?.[0] ?? null;
}

function selectQuickBooksStartFile(event: Event) {
    const input = event.target as HTMLInputElement;
    qbFileStartForm.quickbooks_file = input.files?.[0] ?? null;
}

function previewQuickBooksFileImport() {
    qbFilePreviewForm.transform((data) => ({ quickbooks_file: data.quickbooks_file, password: data.password })).post(route('admin.onboarding.initial-import.quickbooks-file.preview'), {
        preserveScroll: true,
        onFinish: () => { qbFilePreviewForm.reset('password'); },
    });
}

function startQuickBooksFileImport() {
    qbFileStartForm.transform((data) => ({
        quickbooks_file: data.quickbooks_file,
        confirm_import: data.confirm_import,
        password: data.password,
    })).post(route('admin.onboarding.initial-import.quickbooks-file.start'), {
        preserveScroll: true,
        onFinish: () => { qbFileStartForm.reset('password'); },
    });
}

function selectFieldPulseDocument(event: Event) {
    const input = event.target as HTMLInputElement;
    fieldpulsePreviewForm.document = input.files?.[0] ?? null;
}

function selectFieldPulseStartDocument(event: Event) {
    const input = event.target as HTMLInputElement;
    fieldpulseStartForm.document = input.files?.[0] ?? null;
}

function previewFieldPulseImport() {
    fieldpulsePreviewForm.transform((data) => ({ document: data.document, password: data.password })).post(route('admin.onboarding.initial-import.fieldpulse.preview'), {
        preserveScroll: true,
        onFinish: () => { fieldpulsePreviewForm.reset('password'); },
    });
}

function startFieldPulseImport() {
    fieldpulseStartForm.transform((data) => ({
        document: data.document,
        confirm_import: data.confirm_import,
        password: data.password,
    })).post(route('admin.onboarding.initial-import.fieldpulse.start'), {
        preserveScroll: true,
        onFinish: () => { fieldpulseStartForm.reset('password'); },
    });
}

function resumeFieldPulseImport() {
    fieldpulseResumeForm.transform((data) => ({ password: data.password })).post(route('admin.onboarding.initial-import.fieldpulse.resume'), {
        preserveScroll: true,
        onFinish: () => { fieldpulseResumeForm.reset('password'); },
    });
}

function clearLegacyImportDeferral() {
    legacyResumeForm.transform((data) => ({ password: data.password })).post(route('admin.onboarding.initial-import.resume'), {
        preserveScroll: true,
        onFinish: () => legacyResumeForm.reset('password'),
    });
}

function deferBwsSetup() {
    deferBwsForm.transform((data) => ({
        confirm_defer: data.confirm_defer,
        password: data.password,
    })).post(route('admin.onboarding.bws.defer'), {
        preserveScroll: true,
        onFinish: () => deferBwsForm.reset('password'),
    });
}

function resumeBwsSetup() {
    resumeBwsForm.transform((data) => ({ password: data.password })).post(route('admin.onboarding.bws.resume'), {
        preserveScroll: true,
        onFinish: () => resumeBwsForm.reset('password'),
    });
}

function reconcile() {
    if (reconcilingFailures.value) return;

    reconcilingFailures.value = true;
    router.post(route('admin.onboarding.reconcile'), {}, {
        preserveScroll: true,
        onFinish: () => { reconcilingFailures.value = false; },
    });
}

function completeSetup() {
    completeForm.transform((data) => ({
        confirm_complete: data.confirm_complete,
        password: data.password,
    })).post(route('admin.onboarding.complete'), {
        preserveScroll: true,
        onFinish: () => completeForm.reset('password'),
    });
}

function statusClasses(status: string): string {
    if (status === 'passed' || status === 'not_required') {
        return 'border-green-300 bg-green-50 text-green-800 dark:border-green-800 dark:bg-green-950 dark:text-green-200';
    }

    if (status === 'deferred') {
        return 'border-blue-300 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200';
    }

    if (status === 'failed') {
        return 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200';
    }

    return 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200';
}

function statusLabel(status: string): string {
    return {
        pending: 'Not complete',
        in_progress: 'In progress',
        passed: 'Complete',
        deferred: 'Deferred by Admin',
        failed: 'Needs attention',
        not_required: 'Not required',
    }[status] ?? 'Not complete';
}

function setupStepHref(step: SetupStep): string | null {
    switch (step.key) {
        case 'first_admin':
            return route('profile.edit');
        case 'bws_runtime_reader':
            return route('admin.integrations.bitwarden.show');
        case 'quickbooks_oauth':
            return route('admin.integrations.quickbooks.show');
        case 'graph_validation':
            return route('admin.integrations.microsoft-graph.onboarding-targets.show');
        default:
            return null;
    }
}

function setupStepSectionId(step: SetupStep): string | null {
    switch (step.key) {
        case 'initial_qbo_import':
            if (!props.readiness.required) {
                return null;
            }

            // Without a QuickBooks opt-in the Initial Customer/Site import is
            // driven by the FieldPulse document upload panel, which is the
            // first supported source offered on the page. Never send the admin
            // to the QuickBooks opt-in block or the top of the page.
            return props.readiness.quickBooksOptedIn ? 'initial-import' : 'fieldpulse-upload';
        case 'failure_reconciliation':
            return 'readiness-receipt';
        default:
            return null;
    }
}

function openSetupStep(step: SetupStep) {
    const targetId = setupStepSectionId(step);
    const target = targetId ? document.getElementById(targetId) : null;

    if (!target) {
        return;
    }

    target.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        block: 'center',
    });
    target.focus({ preventScroll: true });
}

let progressRefresh: number | undefined;

onMounted(() => {
    progressRefresh = window.setInterval(() => {
        if (props.readiness.customerSiteImport.status === 'in_progress' || props.readiness.itemCatalog.status === 'in_progress') {
            router.reload({ only: ['readiness'] });
        }
    }, 5000);
});

onUnmounted(() => {
    if (progressRefresh !== undefined) {
        window.clearInterval(progressRefresh);
    }
});
</script>

<template>
    <Head title="First-run setup" />
    <AdminLayout title="First-run setup">
        <div class="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6 lg:px-8">
            <header class="grid gap-2">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-800">Installation onboarding</p>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Setup progress</h1>
                <p class="max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">
                    Progress is saved per step. You can leave this page and return later; completed checks are kept when another step needs repair.
                </p>
            </header>

            <p v-if="status" class="rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-200" role="status">
                {{ status }}
            </p>
            <p v-if="error" class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200" role="alert">
                {{ error }}
            </p>

            <section v-if="readiness.legacy" class="grid gap-2 rounded-md border border-green-300 bg-green-50 p-5 text-sm text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-200">
                <h2 class="font-semibold">Existing installation</h2>
                <p>Invitation behavior is preserved for this installation. New first-run readiness checks apply to fresh installations.</p>
                <p v-if="readiness.completedAt">Readiness record: {{ readiness.completedAt }}{{ readiness.completedBy ? ` · ${readiness.completedBy}` : '' }}</p>
            </section>

            <section v-else class="grid gap-3 rounded-md border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <section id="quickbooks-onboarding" tabindex="-1" v-if="!readiness.quickBooksOptedIn" class="grid scroll-mt-6 gap-3 rounded-md border border-gray-200 bg-gray-50 p-4 focus:outline-none focus:ring-2 focus:ring-blue-700 dark:border-gray-700 dark:bg-gray-950">
                    <div class="grid gap-1">
                        <h2 class="font-semibold text-gray-900 dark:text-gray-100">QuickBooks synchronization is optional</h2>
                        <p class="text-sm text-gray-700 dark:text-gray-300">QuickBooks details and sync controls are on the QuickBooks integration page. Microsoft Graph readiness remains required.</p>
                        <Link :href="route('admin.integrations.quickbooks.show')" class="w-fit font-semibold text-blue-800 underline underline-offset-2 dark:text-blue-300">
                            Open QuickBooks integration
                        </Link>
                    </div>
                    <form v-if="readiness.canOptInQuickBooks" class="grid gap-3 sm:max-w-xl" @submit.prevent="updateQuickBooksOptIn">
                        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                            <input v-model="quickBooksOptInForm.confirm_change" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                            <span>I want to include QuickBooks synchronization. I understand the Intuit credentials and company authorization must be verified before enabling sync, and the required imports must complete before the readiness receipt.</span>
                        </label>
                        <div>
                            <InputLabel for="quickbooks-opt-in-password" value="Current password" />
                            <TextInput id="quickbooks-opt-in-password" v-model="quickBooksOptInForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="quickBooksOptInForm.errors.password || quickBooksOptInForm.errors.confirm_change || (quickBooksOptInForm.errors as Record<string, string>).quickbooks_opt_in" />
                        </div>
                        <PrimaryButton class="w-fit" :disabled="quickBooksOptInForm.processing || !quickBooksOptInForm.confirm_change">
                            {{ quickBooksOptInForm.processing ? 'Updating…' : 'Opt into QuickBooks setup' }}
                        </PrimaryButton>
                    </form>
                </section>
                <section v-else-if="readiness.quickBooksOptedIn" id="quickbooks-onboarding" tabindex="-1" class="grid scroll-mt-6 gap-3 rounded-md border border-blue-200 bg-blue-50 p-4 focus:outline-none focus:ring-2 focus:ring-blue-700 dark:border-blue-900 dark:bg-blue-950">
                    <div class="grid gap-1 text-sm text-blue-950 dark:text-blue-100">
                        <h2 class="font-semibold">QuickBooks is included in onboarding</h2>
                        <p>Manage the QuickBooks connection, synchronization, and Item catalog from the QuickBooks integration page.</p>
                        <Link :href="route('admin.integrations.quickbooks.show')" class="w-fit font-semibold underline underline-offset-2">
                            Open QuickBooks integration
                        </Link>
                    </div>
                    <form v-if="readiness.canOptOutQuickBooks" class="grid gap-3 sm:max-w-xl" @submit.prevent="deferQuickBooksSetup">
                        <label class="flex items-start gap-2 text-sm text-blue-950 dark:text-blue-100">
                            <input v-model="quickBooksOptInForm.confirm_change" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                            <span>Defer QuickBooks before connecting a company or starting an import.</span>
                        </label>
                        <div>
                            <InputLabel for="quickbooks-opt-out-password" value="Current password" />
                            <TextInput id="quickbooks-opt-out-password" v-model="quickBooksOptInForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="quickBooksOptInForm.errors.password || quickBooksOptInForm.errors.confirm_change || (quickBooksOptInForm.errors as Record<string, string>).quickbooks_opt_in" />
                        </div>
                        <PrimaryButton class="w-fit" :disabled="quickBooksOptInForm.processing || !quickBooksOptInForm.confirm_change">
                            {{ quickBooksOptInForm.processing ? 'Updating…' : 'Defer QuickBooks setup' }}
                        </PrimaryButton>
                    </form>
                    <p v-else class="text-sm text-blue-900 dark:text-blue-200">QuickBooks setup has started and cannot be deferred. You can pause synchronization without discarding its connection or setup progress.</p>
                </section>

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="grid gap-1">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">Readiness checklist</h2>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            Invitations remain locked until every required item is verified and a readiness receipt is recorded.
                        </p>
                    </div>
                    <span :class="['rounded-full border px-3 py-1 text-sm font-semibold', statusClasses(readiness.invitationsUnlocked ? 'passed' : 'pending')]">
                        {{ readiness.invitationsUnlocked ? 'Invitations unlocked' : 'Invitations locked' }}
                    </span>
                </div>

                <ul class="grid gap-3" aria-label="Onboarding steps">
                    <li v-for="step in readiness.steps" :key="step.key" class="grid gap-2 rounded-md border border-gray-200 p-4 dark:border-gray-700 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
                        <div class="grid gap-1">
                            <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ step.label }}</h3>
                            <p v-if="step.message" class="text-sm text-gray-600 dark:text-gray-400">{{ step.message }}</p>
                            <p v-if="step.failureCode" class="text-xs font-mono text-red-700 dark:text-red-300">{{ step.failureCode }}</p>
                            <p v-if="step.progress" class="text-xs text-gray-600 dark:text-gray-400">
                                Progress: {{ step.progress.current ?? 0 }} / {{ step.progress.total }}
                            </p>
                            <p v-if="step.lastAttemptedAt" class="text-xs text-gray-500 dark:text-gray-400">
                                Last attempt: {{ step.lastAttemptedAt }} · {{ step.attemptCount }} attempt(s)
                            </p>
                            <Link
                                v-if="step.key === 'graph_validation'"
                                :href="route('admin.integrations.microsoft-graph.onboarding-targets.show')"
                                class="w-fit text-sm font-semibold text-blue-800 underline dark:text-blue-300"
                            >
                                Enter or review Microsoft Graph targets
                            </Link>
                        </div>
                            <Link
                                v-if="setupStepHref(step)"
                                :href="setupStepHref(step)!"
                                :aria-label="`Open setup for ${step.label}: ${statusLabel(step.status)}`"
                                :class="['w-fit rounded-full border px-3 py-1 text-xs font-semibold underline decoration-dotted underline-offset-2 hover:brightness-95 focus:outline-none focus:ring-2 focus:ring-blue-700', statusClasses(step.status)]"
                            >
                                {{ statusLabel(step.status) }}
                            </Link>
                            <a
                                v-else-if="setupStepSectionId(step)"
                                :href="`#${setupStepSectionId(step)}`"
                                :aria-label="`Go to setup for ${step.label}: ${statusLabel(step.status)}`"
                                :class="['w-fit cursor-pointer rounded-full border px-3 py-1 text-xs font-semibold underline decoration-dotted underline-offset-2 hover:brightness-95 focus:outline-none focus:ring-2 focus:ring-blue-700', statusClasses(step.status)]"
                                @click.prevent="openSetupStep(step)"
                            >
                                {{ statusLabel(step.status) }}
                            </a>
                        <span v-else :class="['w-fit rounded-full border px-3 py-1 text-xs font-semibold', statusClasses(step.status)]">
                            {{ statusLabel(step.status) }}
                        </span>
                    </li>
                </ul>

                <section v-if="readiness.canDeferBwsRuntimeReader" class="grid gap-3 rounded-md border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                    <div class="grid gap-1">
                        <h3 class="font-semibold text-blue-950 dark:text-blue-100">Pause BWS setup</h3>
                        <p class="text-sm text-blue-900 dark:text-blue-200">
                            Pause and return after the selected import mode's app secrets have been provisioned in this environment's BWS project. This does not unlock invitations or Graph-dependent integrations.
                        </p>
                    </div>
                    <form class="grid gap-3 sm:max-w-xl" @submit.prevent="deferBwsSetup">
                        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                            <input v-model="deferBwsForm.confirm_defer" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                            <span>I understand BWS and invitation access will remain locked until I resume and verify the required secrets.</span>
                        </label>
                        <div>
                            <InputLabel for="defer-bws-password" value="Current password" />
                            <TextInput id="defer-bws-password" v-model="deferBwsForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="deferBwsForm.errors.password || deferBwsForm.errors.confirm_defer || deferBwsForm.errors.onboarding" />
                        </div>
                        <PrimaryButton class="w-fit" :disabled="deferBwsForm.processing || !deferBwsForm.confirm_defer">
                            {{ deferBwsForm.processing ? 'Pausing…' : 'Pause BWS setup' }}
                        </PrimaryButton>
                    </form>
                </section>

                <section v-if="readiness.canResumeBwsRuntimeReader" class="grid gap-3 rounded-md border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                    <div class="grid gap-1">
                        <h3 class="font-semibold text-blue-950 dark:text-blue-100">Resume BWS setup</h3>
                        <p class="text-sm text-blue-900 dark:text-blue-200">
                            Re-verify the Can Read token and every app-secret name required by the selected import mode. Invitations and Graph-dependent integrations remain locked until verification succeeds.
                        </p>
                    </div>
                    <form class="grid gap-3 sm:max-w-xl" @submit.prevent="resumeBwsSetup">
                        <div>
                            <InputLabel for="resume-bws-password" value="Current password" />
                            <TextInput id="resume-bws-password" v-model="resumeBwsForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="resumeBwsForm.errors.password || resumeBwsForm.errors.onboarding" />
                        </div>
                        <PrimaryButton class="w-fit" :disabled="resumeBwsForm.processing">
                            {{ resumeBwsForm.processing ? 'Resuming…' : 'Resume BWS setup' }}
                        </PrimaryButton>
                    </form>
                </section>

                <section v-if="readiness.required && readiness.quickBooksOptedIn" id="initial-import" tabindex="-1" class="grid scroll-mt-6 gap-3 rounded-md border border-gray-200 p-4 focus:outline-none focus:ring-2 focus:ring-blue-700 dark:border-gray-700">
                    <div class="grid gap-1">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">QuickBooks Customer/Site import</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            This importer is additive and resumable: it upserts by QuickBooks IDs and does not clear FieldOps records. Keep the QBO Customer/Site list stable until it finishes; a changed Customer count pauses the run. The read-only Item catalog sync follows this phase.
                        </p>
                    </div>
                    <div v-if="readiness.customerSiteImport.status !== 'pending'" class="grid gap-1 text-sm text-gray-700 dark:text-gray-300" role="status">
                        <p>Customer records imported: {{ readiness.customerSiteImport.customersImported }} / {{ readiness.customerSiteImport.customersTotal }}</p>
                        <p>Customer records processed: {{ readiness.customerSiteImport.customersProcessed }} · Sites imported: {{ readiness.customerSiteImport.sitesImported }}</p>
                        <p v-if="readiness.customerSiteImport.failureCount > 0" class="text-red-700 dark:text-red-300">
                            {{ readiness.customerSiteImport.failureCount }} record(s) need attention.
                            <span v-if="readiness.customerSiteImport.failureCode" class="font-mono">{{ readiness.customerSiteImport.failureCode }}</span>
                        </p>
                        <ul v-if="readiness.customerSiteImport.failures.length" class="grid gap-1 rounded bg-red-50 p-2 text-xs text-red-800 dark:bg-red-950 dark:text-red-200" aria-label="Customer import failures">
                            <li v-for="failure in readiness.customerSiteImport.failures" :key="`qbo-${failure.recordId}`" class="font-mono">
                                QuickBooks Customer {{ failure.recordId }} · {{ failure.code }}
                            </li>
                            <li v-if="readiness.customerSiteImport.failureCount > readiness.customerSiteImport.failures.length" class="font-sans">
                                Showing the first {{ readiness.customerSiteImport.failures.length }} of {{ readiness.customerSiteImport.failureCount }} unresolved records.
                            </li>
                        </ul>
                        <p v-if="readiness.customerSiteImport.status === 'customer_site_complete'" class="font-semibold text-amber-800 dark:text-amber-200">
                            Customer/Site phase complete. The QuickBooks Item catalog must sync before invitation readiness.
                        </p>
                        <p v-else-if="readiness.customerSiteImport.status === 'in_progress'" class="text-blue-800 dark:text-blue-200">
                            Import is processing in the background. This page refreshes progress automatically.
                        </p>
                    </div>
                </section>

                    <div v-if="readiness.required" id="fieldpulse-upload" tabindex="-1" class="grid scroll-mt-6 gap-3 rounded-md border border-slate-200 bg-slate-50 p-4 focus:outline-none focus:ring-2 focus:ring-blue-700 dark:border-slate-700 dark:bg-slate-900">
                        <div class="grid gap-1">
                            <h4 class="font-semibold text-slate-900 dark:text-slate-100">FieldPulse upload</h4>
                            <p class="text-sm text-slate-600 dark:text-slate-300">
                                Upload our FieldPulse document (an .xlsx workbook with Customers, Locations, Jobs, and Notes sheets). It imports every Location as a Site, plus Contacts, Jobs, and the historical customer/job notes as a timeline. Records are matched only by their FieldPulse IDs. FieldOps retains the uploaded workbook, and this is the migration's source of record — keep your own copy as well.
                            </p>
                        </div>

                        <div v-if="fieldpulseImport" class="grid gap-3 text-sm text-gray-700 dark:text-gray-300" role="status">
                            <div v-if="fieldpulseStatusView" class="flex flex-wrap items-center gap-2">
                                <span :class="fieldpulsePillClass" class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold">
                                    <svg v-if="fieldpulseImport.status === 'in_progress'" class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                                    </svg>
                                    {{ fieldpulseStatusView.label }}
                                </span>
                                <span v-if="fieldpulseStatusView.detail" class="text-xs text-slate-600 dark:text-slate-400">{{ fieldpulseStatusView.detail }}</span>
                                <button type="button" class="ml-auto text-xs font-semibold text-blue-700 underline hover:text-blue-900 dark:text-blue-300 dark:hover:text-blue-100" @click="refreshFieldPulseImport">
                                    {{ fieldpulseRefreshing ? 'Refreshing…' : 'Refresh' }}
                                </button>
                            </div>
                            <div v-if="fieldpulseStageProgress" class="grid gap-1">
                                <div class="h-2.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700" role="progressbar" :aria-valuenow="fieldpulseStageProgress.percent" aria-valuemin="0" aria-valuemax="100">
                                    <div class="h-full rounded-full bg-blue-600 transition-all duration-700 ease-out" :style="{ width: fieldpulseStageProgress.percent + '%' }"></div>
                                </div>
                                <div class="flex flex-wrap justify-between gap-x-3 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    <span v-for="(stage, i) in fieldpulseStageProgress.stages" :key="stage.label" :class="stage.done ? 'font-semibold text-blue-700 dark:text-blue-300' : ''">
                                        {{ stage.done ? '✓ ' : '' }}{{ stage.label }}
                                    </span>
                                    <span class="font-semibold text-slate-700 dark:text-slate-300">{{ fieldpulseStageProgress.percent }}%</span>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                                <div v-for="stat in fieldpulseStatBlocks" :key="stat.label" class="rounded border border-slate-200 bg-white p-2 text-center dark:border-slate-700 dark:bg-slate-800">
                                    <div class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ stat.value }}</div>
                                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ stat.label }}</div>
                                </div>
                            </div>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                Stage: {{ fieldpulseImport.stage }} · The uploaded workbook is retained as the migration's source of record.<span v-if="fieldpulseImport.document_sha256"> SHA-256 {{ fieldpulseImport.document_sha256.slice(0, 12) }}…</span>
                            </p>
                            <div v-if="fieldpulseImport.failure_count > 0" class="grid gap-1 rounded border border-amber-300 bg-amber-50 p-2 dark:border-amber-700 dark:bg-amber-950/40">
                                <p class="font-semibold text-amber-800 dark:text-amber-200">
                                    {{ fieldpulseImport.failure_count }} record(s) need attention<span v-if="fieldpulseImport.failure_code"> — <span class="font-mono">{{ fieldpulseImport.failure_code }}</span></span>
                                </p>
                                <ul class="list-inside list-disc font-mono text-xs text-amber-800 dark:text-amber-200">
                                    <li v-for="failure in fieldpulseImport.failures ?? []" :key="`${failure.resource_type}-${failure.source_record_id}`">
                                        {{ failure.resource_type }}: {{ failure.source_record_id }} — {{ failure.failure_code }}
                                    </li>
                                </ul>
                                <p v-if="fieldpulseImport.failure_count > (fieldpulseImport.failures?.length ?? 0)" class="text-xs text-amber-700 dark:text-amber-300">
                                    Showing the first {{ fieldpulseImport.failures?.length ?? 0 }} of {{ fieldpulseImport.failure_count }}.
                                </p>
                            </div>
                        </div>

                        <form v-if="!fieldpulseImportPreview" class="grid gap-3 sm:max-w-xl" enctype="multipart/form-data" @submit.prevent="previewFieldPulseImport">
                            <label class="grid gap-1 text-sm text-gray-800 dark:text-gray-200">
                                <span>FieldPulse document (.xlsx, maximum 20 MB)</span>
                                <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="block w-full text-sm file:mr-3 file:rounded file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-blue-800" @change="selectFieldPulseDocument" />
                            </label>
                            <div>
                                <InputLabel for="fieldpulse-preview-password" value="Current password" />
                                <TextInput id="fieldpulse-preview-password" v-model="fieldpulsePreviewForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="fieldpulsePreviewForm.errors.document || fieldpulsePreviewForm.errors.password || fieldpulsePreviewForm.errors.fieldpulse" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="fieldpulsePreviewForm.processing || !fieldpulsePreviewForm.document">
                                {{ fieldpulsePreviewForm.processing ? 'Checking document…' : 'Preview document' }}
                            </PrimaryButton>
                        </form>

                        <div v-if="fieldpulseImportPreview" class="grid gap-3">
                            <div class="grid gap-1 text-sm text-slate-700 dark:text-slate-200" role="status">
                                <p>Document contains {{ fieldpulseImportPreview.customers }} Customer(s), {{ fieldpulseImportPreview.locations }} Location(s), {{ fieldpulseImportPreview.projects }} Project(s), {{ fieldpulseImportPreview.jobs }} Job(s), {{ fieldpulseImportPreview.notes }} note(s), and {{ fieldpulseImportPreview.contacts }} Contact(s). Preview made no local changes.</p>
                                <p>{{ fieldpulseImportPreview.jobs_with_project_reference }} Job(s) reference a Project; {{ fieldpulseImportPreview.jobs_with_unmatched_project_reference }} reference a Project missing from this document; {{ fieldpulseImportPreview.jobs_with_project_customer_mismatch }} have a Project/Customer mismatch.</p>
                                <p v-if="fieldpulseImportPreview.conflictingSource" class="font-semibold text-red-700 dark:text-red-300">A first-run Customer/Site import already exists from another source. Reset it before uploading.</p>
                            </div>
                            <form class="grid gap-3 sm:max-w-xl" enctype="multipart/form-data" @submit.prevent="startFieldPulseImport">
                                <label class="grid gap-1 text-sm text-gray-800 dark:text-gray-200">
                                    <span>Select the same document again to confirm it</span>
                                    <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="block w-full text-sm file:mr-3 file:rounded file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-blue-800" @change="selectFieldPulseStartDocument" />
                                </label>
                                <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                                    <input v-model="fieldpulseStartForm.confirm_import" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                                    <span>I authorize importing this document: Customers, Locations (as Sites), Contacts, Jobs, and historical notes.</span>
                                </label>
                                <div>
                                    <InputLabel for="fieldpulse-start-password" value="Current password" />
                                    <TextInput id="fieldpulse-start-password" v-model="fieldpulseStartForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="fieldpulseStartForm.errors.document || fieldpulseStartForm.errors.password || fieldpulseStartForm.errors.confirm_import || fieldpulseStartForm.errors.fieldpulse" />
                                </div>
                                <PrimaryButton class="w-fit" :disabled="fieldpulseStartForm.processing || !fieldpulseStartForm.document || !fieldpulseStartForm.confirm_import">
                                    {{ fieldpulseStartForm.processing ? 'Starting…' : 'Start FieldPulse upload' }}
                                </PrimaryButton>
                            </form>
                        </div>

                        <form v-if="fieldpulseImport && fieldpulseImport.status === 'in_progress'" class="grid gap-3 sm:max-w-xl" @submit.prevent="resumeFieldPulseImport">
                            <div>
                                <InputLabel for="fieldpulse-resume-password" value="Current password" />
                                <TextInput id="fieldpulse-resume-password" v-model="fieldpulseResumeForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="fieldpulseResumeForm.errors.password || fieldpulseResumeForm.errors.fieldpulse" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="fieldpulseResumeForm.processing">
                                {{ fieldpulseResumeForm.processing ? 'Resuming…' : 'Resume FieldPulse upload' }}
                            </PrimaryButton>
                        </form>
                    </div>

                    <div v-if="readiness.canStartCustomerSiteImport" class="grid gap-3">
                        <form v-if="!qboImportPreview" class="grid gap-3 sm:max-w-xl" @submit.prevent="previewCustomerSiteImport">
                            <div>
                                <InputLabel for="qbo-import-preview-password" value="Current password" />
                                <TextInput id="qbo-import-preview-password" v-model="importPreviewForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="importPreviewForm.errors.password || importPreviewForm.errors.quickbooks_import" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="importPreviewForm.processing">
                                {{ importPreviewForm.processing ? 'Checking QuickBooks…' : 'Preview Customer/Site counts' }}
                            </PrimaryButton>
                        </form>

                        <div v-if="qboImportPreview" class="grid gap-3 rounded-md border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p class="text-sm text-blue-950 dark:text-blue-100">
                                QuickBooks returned {{ qboImportPreview.customers }} top-level Customer record(s) and {{ qboImportPreview.qboRecords }} total Customer record(s); {{ qboImportPreview.potentialSites }} are potential child Sites pending hierarchy validation. Preview made no local changes.
                            </p>
                            <form class="grid gap-3 sm:max-w-xl" @submit.prevent="startCustomerSiteImport">
                                <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                                    <input v-model="importStartForm.confirm_import" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                                    <span>I authorize the resumable import of this QuickBooks company's Customers and direct child Sites. Existing FieldOps records will not be cleared; the read-only Item catalog sync follows this phase.</span>
                                </label>
                                <div>
                                    <InputLabel for="qbo-import-start-password" value="Current password" />
                                    <TextInput id="qbo-import-start-password" v-model="importStartForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="importStartForm.errors.password || importStartForm.errors.confirm_import || importStartForm.errors.quickbooks_import" />
                                </div>
                                <PrimaryButton class="w-fit" :disabled="importStartForm.processing || !importStartForm.confirm_import">
                                    {{ importStartForm.processing ? 'Starting…' : 'Start Customer/Site import' }}
                                </PrimaryButton>
                            </form>
                        </div>
                    </div>

                    <form v-if="readiness.canResumeCustomerSiteImport" class="grid gap-3 sm:max-w-xl" @submit.prevent="resumeCustomerSiteImport">
                        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                            <input v-model="importResumeForm.confirm_import" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                            <span>Retry the Customer/Site dataset from the beginning. Previously successful records are matched by QuickBooks ID; only unresolved failures remain blockers.</span>
                        </label>
                        <div>
                            <InputLabel for="qbo-import-resume-password" value="Current password" />
                            <TextInput id="qbo-import-resume-password" v-model="importResumeForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="importResumeForm.errors.password || importResumeForm.errors.confirm_import || importResumeForm.errors.quickbooks_import" />
                        </div>
                        <PrimaryButton class="w-fit" :disabled="importResumeForm.processing || !importResumeForm.confirm_import">
                            {{ importResumeForm.processing ? 'Resuming…' : 'Resume Customer/Site import' }}
                        </PrimaryButton>
                    </form>

                    <section v-if="readiness.itemCatalog.status !== 'pending' || readiness.canPreviewItemCatalogImport || readiness.canStartItemCatalogImport || readiness.canResumeItemCatalogImport" class="grid gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700" aria-labelledby="qbo-items-title">
                        <div class="grid gap-1">
                            <h4 id="qbo-items-title" class="font-semibold text-gray-900 dark:text-gray-100">QuickBooks Item catalog</h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                FieldOps imports existing Items read-only. Only QuickBooks Item ID, name, description, type, and active status are retained. Prices, costs, account references, and on-hand quantities are discarded and never stored or shown.
                            </p>
                        </div>

                        <div v-if="readiness.itemCatalog.status !== 'pending'" class="grid gap-1 text-sm text-gray-700 dark:text-gray-300" role="status">
                            <p>Catalog status: {{ readiness.itemCatalog.status }} · Active Items: {{ readiness.itemCatalog.activeItems }} · Selectable Items: {{ readiness.itemCatalog.selectableItems }}</p>
                            <p v-if="readiness.itemCatalog.status === 'in_progress'">Imported {{ readiness.itemCatalog.importedItems }} / {{ readiness.itemCatalog.totalItems }} Item record(s). Progress refreshes automatically.</p>
                            <p v-if="readiness.itemCatalog.failureCode" class="font-mono text-red-700 dark:text-red-300">{{ readiness.itemCatalog.failureCode }}</p>
                        </div>

                        <form v-if="readiness.canPreviewItemCatalogImport && !qboItemImportPreview" class="grid gap-3 sm:max-w-xl" @submit.prevent="previewItemCatalogImport">
                            <div>
                                <InputLabel for="qbo-item-preview-password" value="Current password" />
                                <TextInput id="qbo-item-preview-password" v-model="itemPreviewForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="itemPreviewForm.errors.password || itemPreviewForm.errors.item_import" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="itemPreviewForm.processing">
                                {{ itemPreviewForm.processing ? 'Checking QuickBooks…' : 'Preview Item catalog count' }}
                            </PrimaryButton>
                        </form>

                        <div v-if="qboItemImportPreview && readiness.canStartItemCatalogImport" class="grid gap-3 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                            <p class="text-sm text-blue-950 dark:text-blue-100">
                                QuickBooks reports {{ qboItemImportPreview.items }} Item record(s), including inactive and category records. Active Inventory, NonInventory, Service, and Group Items are selectable on Visits; Category records are not. No Item financial attributes are stored.
                            </p>
                            <form class="grid gap-3 sm:max-w-xl" @submit.prevent="startItemCatalogImport">
                                <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                                    <input v-model="itemStartForm.confirm_import" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                                    <span>I authorize a resumable, read-only import of the QuickBooks Item catalog. FieldOps will retain only Item IDs, names, descriptions, types, and active status; no accounting, price, cost, tax, or inventory quantities will be stored or written to QuickBooks.</span>
                                </label>
                                <div>
                                    <InputLabel for="qbo-item-start-password" value="Current password" />
                                    <TextInput id="qbo-item-start-password" v-model="itemStartForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                    <InputError class="mt-2" :message="itemStartForm.errors.password || itemStartForm.errors.confirm_import || itemStartForm.errors.item_import" />
                                </div>
                                <PrimaryButton class="w-fit" :disabled="itemStartForm.processing || !itemStartForm.confirm_import">
                                    {{ itemStartForm.processing ? 'Starting…' : 'Start Item catalog sync' }}
                                </PrimaryButton>
                            </form>
                        </div>

                        <form v-if="readiness.canResumeItemCatalogImport" class="grid gap-3 sm:max-w-xl" @submit.prevent="resumeItemCatalogImport">
                            <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                                <input v-model="itemResumeForm.confirm_import" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                                <span>Retry the Item catalog sync from the beginning. Existing rows are upserted by QuickBooks company and Item ID.</span>
                            </label>
                            <div>
                                <InputLabel for="qbo-item-resume-password" value="Current password" />
                                <TextInput id="qbo-item-resume-password" v-model="itemResumeForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="itemResumeForm.errors.password || itemResumeForm.errors.confirm_import || itemResumeForm.errors.item_import" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="itemResumeForm.processing || !itemResumeForm.confirm_import">
                                {{ itemResumeForm.processing ? 'Resuming…' : 'Resume Item catalog sync' }}
                            </PrimaryButton>
                        </form>
                    </section>

                <section v-if="readiness.canResumeInitialImport" class="grid gap-3 rounded-md border border-blue-200 bg-blue-50 p-4 dark:border-blue-900 dark:bg-blue-950">
                    <div class="grid gap-1">
                        <h3 class="font-semibold text-blue-950 dark:text-blue-100">Resume the initial import decision</h3>
                        <p class="text-sm text-blue-900 dark:text-blue-200">This clears the deferral. The import itself will remain pending until its resumable workflow is available.</p>
                    </div>
                    <form class="grid gap-3 sm:max-w-xl" @submit.prevent="clearLegacyImportDeferral">
                        <div>
                            <InputLabel for="resume-import-password" value="Current password" />
                            <TextInput id="resume-import-password" v-model="legacyResumeForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="legacyResumeForm.errors.password || legacyResumeForm.errors.onboarding" />
                        </div>
                        <PrimaryButton class="w-fit" :disabled="legacyResumeForm.processing">
                            {{ legacyResumeForm.processing ? 'Saving…' : 'Clear deferral' }}
                        </PrimaryButton>
                    </form>
                </section>

                <section v-if="readiness.required" class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900">
                    <div class="grid gap-1">
                        <h4 class="font-semibold text-slate-900 dark:text-slate-100">QuickBooks Customer Contact List upload</h4>
                        <p class="text-sm text-slate-600 dark:text-slate-300">
                            Upload the QuickBooks "Customer Contact List" .xlsx export. Customers are matched by a synthetic source key (the report has no QuickBooks ID) and their billing/shipping addresses are imported. No QuickBooks API call or link is created. FieldOps retains the uploaded report.
                        </p>
                    </div>

                    <div v-if="quickBooksFileImport" class="grid gap-1 text-sm text-gray-700 dark:text-gray-300" role="status">
                        <p>Status: {{ quickBooksFileImport.status }} · Customers {{ quickBooksFileImport.customers_imported }} / {{ quickBooksFileImport.customers_total }}</p>
                        <p v-if="quickBooksFileImport.failure_count > 0" class="text-red-700 dark:text-red-300">{{ quickBooksFileImport.failure_count }} record(s) need attention. <span v-if="quickBooksFileImport.failure_code" class="font-mono">{{ quickBooksFileImport.failure_code }}</span></p>
                    </div>

                    <form v-if="!quickBooksFilePreview" class="grid gap-3 sm:max-w-xl" enctype="multipart/form-data" @submit.prevent="previewQuickBooksFileImport">
                        <label class="grid gap-1 text-sm text-gray-800 dark:text-gray-200">
                            <span>Customer Contact List (.xlsx, maximum 20 MB)</span>
                            <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="block w-full text-sm file:mr-3 file:rounded file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-blue-800" @change="selectQuickBooksFile" />
                        </label>
                        <div>
                            <InputLabel for="qb-file-preview-password" value="Current password" />
                            <TextInput id="qb-file-preview-password" v-model="qbFilePreviewForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="qbFilePreviewForm.errors.quickbooks_file || qbFilePreviewForm.errors.password" />
                        </div>
                        <PrimaryButton class="w-fit" :disabled="qbFilePreviewForm.processing || !qbFilePreviewForm.quickbooks_file">
                            {{ qbFilePreviewForm.processing ? 'Checking file…' : 'Preview file' }}
                        </PrimaryButton>
                    </form>

                    <div v-if="quickBooksFilePreview" class="grid gap-3">
                        <div class="grid gap-1 text-sm text-slate-700 dark:text-slate-200" role="status">
                            <p>{{ quickBooksFilePreview.customers }} Customer(s), {{ quickBooksFilePreview.billingAddresses }} billing and {{ quickBooksFilePreview.shippingAddresses }} shipping address(es) detected. Preview made no local changes.</p>
                            <p v-if="quickBooksFilePreview.conflictCount > 0" class="font-semibold text-red-700 dark:text-red-300">{{ quickBooksFilePreview.conflictCount }} source key conflict(s) exist. No name-based merge will be attempted.</p>
                            <p v-if="quickBooksFilePreview.conflictingSource" class="font-semibold text-red-700 dark:text-red-300">A first-run Customer/Site import already exists from another source. Reset it first.</p>
                        </div>
                        <form v-if="quickBooksFilePreview.canStart" class="grid gap-3 sm:max-w-xl" enctype="multipart/form-data" @submit.prevent="startQuickBooksFileImport">
                            <label class="grid gap-1 text-sm text-gray-800 dark:text-gray-200">
                                <span>Select the same file again to confirm it</span>
                                <input type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required class="block w-full text-sm file:mr-3 file:rounded file:border-0 file:bg-white file:px-3 file:py-2 file:font-semibold file:text-blue-800" @change="selectQuickBooksStartFile" />
                            </label>
                            <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                                <input v-model="qbFileStartForm.confirm_import" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                                <span>I authorize importing these Customers and their addresses. No QuickBooks IDs or write-back will be created.</span>
                            </label>
                            <div>
                                <InputLabel for="qb-file-start-password" value="Current password" />
                                <TextInput id="qb-file-start-password" v-model="qbFileStartForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="qbFileStartForm.errors.quickbooks_file || qbFileStartForm.errors.password || qbFileStartForm.errors.confirm_import" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="qbFileStartForm.processing || !qbFileStartForm.quickbooks_file || !qbFileStartForm.confirm_import">
                                {{ qbFileStartForm.processing ? 'Starting…' : 'Start QuickBooks file import' }}
                            </PrimaryButton>
                        </form>
                    </div>
                </section>

                <section id="readiness-receipt" class="grid scroll-mt-6 gap-3 rounded-md border border-gray-200 p-4 dark:border-gray-700" tabindex="-1">
                    <div class="grid gap-1">
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">Readiness receipt</h3>
                        <p v-if="readiness.invitationsUnlocked" class="text-sm text-green-800 dark:text-green-300">
                            Recorded {{ readiness.completedAt }}{{ readiness.completedBy ? ` by ${readiness.completedBy}` : '' }}.
                        </p>
                        <p v-else class="text-sm text-gray-600 dark:text-gray-400">
                            {{ readiness.blockers.length }} required step(s) still block invitation access.
                        </p>
                    </div>
                    <form v-if="readiness.canComplete" class="grid gap-3 sm:max-w-xl" @submit.prevent="completeSetup">
                        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                            <input v-model="completeForm.confirm_complete" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                            <span>I have reviewed the readiness checks and authorize staff invitations to unlock.</span>
                        </label>
                        <div>
                            <InputLabel for="complete-setup-password" value="Current password" />
                            <TextInput id="complete-setup-password" v-model="completeForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="completeForm.errors.password || completeForm.errors.confirm_complete || completeForm.errors.readiness" />
                        </div>
                        <PrimaryButton class="w-fit" :disabled="completeForm.processing || !completeForm.confirm_complete">
                            {{ completeForm.processing ? 'Recording…' : 'Complete setup and unlock invitations' }}
                        </PrimaryButton>
                    </form>
                    <ul v-else-if="blockedSteps.length" class="grid gap-2 text-sm text-gray-700 dark:text-gray-300" aria-label="Required setup actions">
                        <li v-for="step in blockedSteps" :key="step.key" class="flex flex-wrap items-center justify-between gap-3">
                            <span>• {{ step.label }}</span>
                            <button
                                v-if="step.key === 'failure_reconciliation'"
                                type="button"
                                class="fieldops-secondary-action min-h-9 px-3 py-1"
                                :disabled="reconcilingFailures"
                                aria-label="Run the read-only provider failure reconciliation check"
                                @click="reconcile"
                            >
                                {{ reconcilingFailures ? 'Checking…' : 'Check now' }}
                            </button>
                            <Link v-else-if="setupStepHref(step)" :href="setupStepHref(step)!" class="w-fit cursor-pointer font-semibold text-blue-800 underline underline-offset-2 focus:outline-none focus:ring-2 focus:ring-blue-700 dark:text-blue-300">
                                Open setup
                            </Link>
                            <button
                                v-else-if="setupStepSectionId(step)"
                                type="button"
                                class="w-fit cursor-pointer font-semibold text-blue-800 underline underline-offset-2 focus:outline-none focus:ring-2 focus:ring-blue-700 dark:text-blue-300"
                                @click="openSetupStep(step)"
                            >
                                Go to step
                            </button>
                        </li>
                    </ul>
                    <p v-if="!readiness.invitationsUnlocked && readiness.blockers.includes('Reconcile provider failure queues')" class="text-xs text-gray-600 dark:text-gray-400">
                        The check reads current sync state only. It does not retry or discard queued work.
                    </p>
                </section>
            </section>
        </div>
    </AdminLayout>
</template>
