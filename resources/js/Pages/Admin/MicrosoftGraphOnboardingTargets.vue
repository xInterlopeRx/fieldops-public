<script setup lang="ts">
import AdminLayout from '@/Layouts/AdminLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type GraphTargets = {
    tenant_id: string;
    client_id: string;
    notification_mailbox_id: string;
    notification_mailbox_address: string;
    target_is_dedicated_shared_mailbox: boolean;
    notification_calendar_id: string | null;
    work_schedules_group_id: string;
    work_schedules_site_id: string;
    work_schedules_drive_id: string;
    business_attachments_site_id: string | null;
    business_attachments_drive_id: string | null;
    non_target_mailbox_email: string | null;
    non_target_is_user_mailbox: boolean;
    non_target_site_id: string | null;
    non_target_drive_id: string | null;
    runtimeActivatedAt: string | null;
    configuredAt: string | null;
    validationStatus: string;
    lastValidationAttemptedAt: string | null;
    readAccessVerifiedAt: string | null;
    readAccessCheckCount: number;
    lastErrorCode: string | null;
    boundaryValidationAttemptedAt: string | null;
    boundaryVerifiedAt: string | null;
    boundaryErrorCode: string | null;
    nonTargetMailboxCalendarStatus: string;
    nonTargetSiteStatus: string;
    nonTargetDriveStatus: string;
    targetContactsStatus: string;
    nonTargetContactsStatus: string;
    contactsBoundaryValidationAttemptedAt: string | null;
    contactsBoundaryVerifiedAt: string | null;
    contactsBoundaryErrorCode: string | null;
};

type LinkbackStatus = {
    status: string;
    sentAt: string | null;
    expiresAt: string;
    verifiedAt: string | null;
    failureCode: string | null;
} | null;

type GraphCanary = {
    id: string;
    status: string;
    resourceType: string;
    subject: string;
    tenantId: string;
    clientId: string;
    mailboxId: string;
    calendarId: string | null;
    remoteResourceId: string | null;
    attemptedAt: string | null;
    createdRemotelyAt: string | null;
    cleanedAt: string | null;
    reconciledAt: string | null;
    failureCode: string | null;
    canRetryCleanup: boolean;
    canManuallyReconcile: boolean;
};

type SharePointCanary = {
    id: string;
    status: string;
    folderName: string;
    siteId: string;
    driveId: string;
    remoteItemId: string | null;
    attemptedAt: string | null;
    createdRemotelyAt: string | null;
    cleanedAt: string | null;
    reconciledAt: string | null;
    failureCode: string | null;
    canRetryCleanup: boolean;
    canManuallyReconcile: boolean;
};

type RuntimeVisitReconciliation = {
    id: number;
    jobNumber: string | null;
    jobTitle: string | null;
    scheduledAt: string | null;
    archived: boolean;
    eventId: string | null;
    transactionId: string | null;
    unknownCreate: boolean;
    ownerStatus: 'unknown_create' | 'unverified' | 'different_target';
    sourceMailboxId: string | null;
    sourceCalendarId: string | null;
}[];

type RuntimeContactReconciliation = {
    id: number;
    name: string;
    customerName: string | null;
    archived: boolean;
    outlookContactId: string | null;
    unknownCreate: boolean;
    sourceMailboxId: string | null;
}[];

type RuntimeReconciliation = {
    canReconcile: boolean;
    canConfirmVisitOwnership: boolean;
    canReconcileVisits: boolean;
    legacyMailboxConfigured: boolean;
    legacyTenantId: string | null;
    targetConfirmed: boolean;
    controlConfirmed: boolean;
    legacyMailboxId: string | null;
    legacyCalendarId: string | null;
    targetMailboxId: string;
    targetTenantId: string;
    visits: RuntimeVisitReconciliation;
    visitCount: number;
    activeVisits: RuntimeVisitReconciliation;
    activeVisitCount: number;
    canRetireActiveVisits: boolean;
    contacts: RuntimeContactReconciliation;
    contactCount: number;
    inFlightVisitCount: number;
    inFlightContactCount: number;
    unresolvedMailCount: number;
    displayLimit: number;
};

type PermissionVerification = {
    id: string;
    m365AdminUpn: string;
    verificationReference: string;
    nonTargetMailboxEmail: string;
    targetIsDedicatedSharedMailbox: boolean;
    nonTargetIsUserMailbox: boolean;
    verifiedAt: string | null;
} | null;

type ContactsCanaryFeedback = {
    type: 'success' | 'error';
    message: string;
} | null;

const props = defineProps<{
    environment: string;
    onboardingWritesEnabled: boolean;
    target: GraphTargets | null;
    canSendLinkback: boolean;
    canValidateBoundary: boolean;
    canCreateCalendarCanary: boolean;
    canCreateContactCanary: boolean;
    canRecordPermissionVerification: boolean;
    canCreateSharePointCanary: boolean;
    canFinalizeGraphReadiness: boolean;
    canActivateGraphRuntime: boolean;
    runtimeReconciliation: RuntimeReconciliation;
    graphReadinessStepStatus: string | null;
    permissionVerification?: PermissionVerification;
    linkback: LinkbackStatus;
    contactsCanaryFeedback?: ContactsCanaryFeedback;
    lastCalendarCanary: GraphCanary | null;
    outstandingCalendarCanaries: GraphCanary[];
    lastContactCanary: GraphCanary | null;
    outstandingContactsCanaries: GraphCanary[];
    lastSharePointCanary: SharePointCanary | null;
    outstandingSharePointCanaries: SharePointCanary[];
    status?: string | null;
    error?: string | null;
}>();

function clearPasswordConfirmation(form: { password: string }): void {
    form.password = '';
}

const form = useForm({
    tenant_id: props.target?.tenant_id ?? '',
    client_id: props.target?.client_id ?? '',
    notification_mailbox_id: props.target?.notification_mailbox_id ?? '',
    notification_mailbox_address: props.target?.notification_mailbox_address ?? '',
    target_is_dedicated_shared_mailbox: props.target?.target_is_dedicated_shared_mailbox ?? false,
    notification_calendar_id: props.target?.notification_calendar_id ?? '',
    work_schedules_group_id: props.target?.work_schedules_group_id ?? '',
    work_schedules_site_id: props.target?.work_schedules_site_id ?? '',
    work_schedules_drive_id: props.target?.work_schedules_drive_id ?? '',
    business_attachments_site_id: props.target?.business_attachments_site_id ?? '',
    business_attachments_drive_id: props.target?.business_attachments_drive_id ?? '',
    non_target_mailbox_email: props.target?.non_target_mailbox_email ?? '',
    non_target_is_user_mailbox: props.target?.non_target_is_user_mailbox ?? false,
    non_target_site_id: props.target?.non_target_site_id ?? '',
    non_target_drive_id: props.target?.non_target_drive_id ?? '',
});
const validationForm = useForm({
    confirm_read_only_check: false,
    password: '',
});
const linkbackForm = useForm({
    confirm_send: false,
    password: '',
});
const boundaryForm = useForm({
    confirm_boundary_check: false,
    password: '',
});
const calendarCanaryForm = useForm({
    confirm_canary_action: false,
    password: '',
});
const cleanupCanaryForm = useForm({
    confirm_canary_action: false,
    password: '',
});
const reconcileCanaryForm = useForm({
    confirm_canary_action: false,
    password: '',
});
const contactCanaryForm = useForm({
    confirm_canary_action: false,
    password: '',
});
const cleanupContactCanaryForm = useForm({
    confirm_canary_action: false,
    password: '',
});
const reconcileContactCanaryForm = useForm({
    confirm_canary_action: false,
    password: '',
});
const permissionVerificationForm = useForm({
    confirm_scope_verification: false,
    m365_admin_upn: '',
    verification_reference: '',
    mail_send_target_verified: false,
    mail_send_non_target_denied: false,
    calendar_write_target_verified: false,
    calendar_write_non_target_denied: false,
    contacts_boundary_verified: false,
    sharepoint_boundary_verified: false,
    sharepoint_retention_reviewed: false,
    unscoped_app_roles_reviewed: false,
    password: '',
});
const sharePointCanaryForm = useForm({
    confirm_canary_action: false,
    password: '',
});
const cleanupSharePointCanaryForm = useForm({
    confirm_canary_action: false,
    password: '',
});
const reconcileSharePointCanaryForm = useForm({
    confirm_canary_action: false,
    password: '',
});
const finalizeGraphReadinessForm = useForm({
    confirm_finalize_graph: false,
    password: '',
});
const activateRuntimeTargetForm = useForm({
    confirm_activate_runtime: false,
    password: '',
});
const selectedVisitReconciliationId = ref<number | null>(null);
const selectedActiveVisitId = ref<number | null>(null);
const selectedContactReconciliationId = ref<number | null>(null);
const selectedVisitReconciliation = computed(() => props.runtimeReconciliation.visits
    .find((visit) => visit.id === selectedVisitReconciliationId.value) ?? null);
const selectedActiveVisit = computed(() => props.runtimeReconciliation.activeVisits
    .find((visit) => visit.id === selectedActiveVisitId.value) ?? null);
const selectedContactReconciliation = computed(() => props.runtimeReconciliation.contacts
    .find((contact) => contact.id === selectedContactReconciliationId.value) ?? null);

type SectionState = 'idle' | 'attention' | 'passed';

const sectionPanels: Record<SectionState, string> = {
    idle: 'border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-950',
    attention: 'border-orange-300 bg-orange-50 dark:border-orange-800 dark:bg-orange-950',
    passed: 'border-green-300 bg-green-50 dark:border-green-800 dark:bg-green-950',
};

const preflightState = computed<SectionState>(() => {
    const target = props.target;
    if (!target) return 'idle';
    if (target.validationStatus === 'read_verified') return 'passed';
    if (target.validationStatus === 'read_verified_partial') return 'attention';
    if (target.lastValidationAttemptedAt !== null || target.lastErrorCode !== null) return 'attention';
    return 'idle';
});

const boundaryState = computed<SectionState>(() => {
    const target = props.target;
    if (!target) return 'idle';
    if (target.boundaryVerifiedAt !== null && target.contactsBoundaryVerifiedAt !== null) return 'passed';
    if (target.boundaryValidationAttemptedAt !== null
        || target.contactsBoundaryValidationAttemptedAt !== null
        || target.boundaryErrorCode !== null
        || target.contactsBoundaryErrorCode !== null) return 'attention';
    return 'idle';
});

const linkbackState = computed<SectionState>(() => {
    const linkback = props.linkback;
    if (!linkback) return 'idle';
    if (linkback.verifiedAt !== null) return 'passed';
    if (linkback.failureCode !== null || linkback.status !== 'pending') return 'attention';
    return 'idle';
});

const calendarCanaryState = computed<SectionState>(() => {
    if (props.outstandingCalendarCanaries.length > 0) return 'attention';
    const last = props.lastCalendarCanary;
    if (last === null) return 'idle';
    if (last.status === 'cleaned' || last.status === 'manually_reconciled') return 'passed';
    return 'attention';
});

const contactCanaryState = computed<SectionState>(() => {
    if (props.outstandingContactsCanaries.length > 0) return 'attention';
    const last = props.lastContactCanary;
    if (last === null) return 'idle';
    if (last.status === 'cleaned' || last.status === 'manually_reconciled') return 'passed';
    return 'attention';
});

const sharePointCanaryState = computed<SectionState>(() => {
    if (props.outstandingSharePointCanaries.length > 0) return 'attention';
    const last = props.lastSharePointCanary;
    if (last === null) return 'idle';
    if (last.status === 'cleaned' || last.status === 'manually_reconciled') return 'passed';
    return 'attention';
});

const permissionVerificationState = computed<SectionState>(() => {
    if (props.permissionVerification && props.permissionVerification.verifiedAt !== null) return 'passed';
    return 'idle';
});

const finalizeState = computed<SectionState>(() => {
    if (props.graphReadinessStepStatus === 'passed') return 'passed';
    if (props.canFinalizeGraphReadiness) return 'attention';
    return 'idle';
});

const activateState = computed<SectionState>(() => {
    if (props.target?.runtimeActivatedAt) return 'passed';
    if (props.canActivateGraphRuntime) return 'attention';
    return 'idle';
});
const visitReconciliationForm = useForm({
    confirm_remote_reconciliation: false,
    m365_admin_upn: '',
    verification_reference: '',
    password: '',
});
const visitOwnershipConfirmationForm = useForm({
    confirm_target_ownership: false,
    m365_admin_upn: '',
    verification_reference: '',
    password: '',
});
const activeVisitRetirementForm = useForm({
    confirm_remote_reconciliation: false,
    m365_admin_upn: '',
    verification_reference: '',
    password: '',
});
const contactReconciliationForm = useForm({
    confirm_remote_reconciliation: false,
    m365_admin_upn: '',
    verification_reference: '',
    password: '',
});

function saveTargets() {
    form.put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), {
        preserveScroll: true,
    });
}

function validateReadAccess() {
    validationForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-read-access'), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(validationForm);
            validationForm.confirm_read_only_check = false;
        },
    });
}

function sendLinkback() {
    linkbackForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.send-linkback'), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(linkbackForm);
            linkbackForm.confirm_send = false;
        },
    });
}

function validatePermissionBoundary() {
    boundaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-permission-boundary'), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(boundaryForm);
            boundaryForm.confirm_boundary_check = false;
        },
    });
}

function createCalendarCanary() {
    calendarCanaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(calendarCanaryForm);
            calendarCanaryForm.confirm_canary_action = false;
        },
    });
}

function retryCalendarCanaryCleanup(canaryId: string) {
    cleanupCanaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.cleanup', { canary: canaryId }), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(cleanupCanaryForm);
            cleanupCanaryForm.confirm_canary_action = false;
        },
    });
}

function reconcileCalendarCanary(canaryId: string) {
    reconcileCanaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.reconcile', { canary: canaryId }), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(reconcileCanaryForm);
            reconcileCanaryForm.confirm_canary_action = false;
        },
    });
}

function createContactsCanary() {
    contactCanaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.store'), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(contactCanaryForm);
            contactCanaryForm.confirm_canary_action = false;
        },
    });
}

function retryContactsCanaryCleanup(canaryId: string) {
    cleanupContactCanaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.cleanup', { canary: canaryId }), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(cleanupContactCanaryForm);
            cleanupContactCanaryForm.confirm_canary_action = false;
        },
    });
}

function reconcileContactsCanary(canaryId: string) {
    reconcileContactCanaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.reconcile', { canary: canaryId }), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(reconcileContactCanaryForm);
            reconcileContactCanaryForm.confirm_canary_action = false;
        },
    });
}

function recordPermissionVerification() {
    permissionVerificationForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.permission-verification.store'), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(permissionVerificationForm);
            permissionVerificationForm.confirm_scope_verification = false;
        },
    });
}

function createSharePointCanary() {
    sharePointCanaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.store'), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(sharePointCanaryForm);
            sharePointCanaryForm.confirm_canary_action = false;
        },
    });
}

function retrySharePointCanaryCleanup(canaryId: string) {
    cleanupSharePointCanaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.cleanup', { canary: canaryId }), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(cleanupSharePointCanaryForm);
            cleanupSharePointCanaryForm.confirm_canary_action = false;
        },
    });
}

function reconcileSharePointCanary(canaryId: string) {
    reconcileSharePointCanaryForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.reconcile', { canary: canaryId }), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(reconcileSharePointCanaryForm);
            reconcileSharePointCanaryForm.confirm_canary_action = false;
        },
    });
}

function finalizeGraphReadiness() {
    finalizeGraphReadinessForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.finalize-readiness'), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(finalizeGraphReadinessForm);
            finalizeGraphReadinessForm.confirm_finalize_graph = false;
        },
    });
}

function activateRuntimeTarget() {
    activateRuntimeTargetForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.activate-runtime-target'), {
        preserveScroll: true,
        onFinish: () => {
            clearPasswordConfirmation(activateRuntimeTargetForm);
            activateRuntimeTargetForm.confirm_activate_runtime = false;
        },
    });
}

function reconcileVisitEvent() {
    if (selectedVisitReconciliationId.value === null) {
        return;
    }

    visitReconciliationForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.visits.store', {
        visit: selectedVisitReconciliationId.value,
    }), {
        preserveScroll: true,
        onSuccess: () => {
            selectedVisitReconciliationId.value = null;
            visitReconciliationForm.reset('m365_admin_upn', 'verification_reference');
        },
        onFinish: () => {
            clearPasswordConfirmation(visitReconciliationForm);
            visitReconciliationForm.confirm_remote_reconciliation = false;
        },
    });
}

function confirmVisitEventOwnership() {
    if (selectedVisitReconciliationId.value === null) {
        return;
    }

    visitOwnershipConfirmationForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.visits.confirm-owner', {
        visit: selectedVisitReconciliationId.value,
    }), {
        preserveScroll: true,
        onSuccess: () => {
            selectedVisitReconciliationId.value = null;
            visitOwnershipConfirmationForm.reset('m365_admin_upn', 'verification_reference');
        },
        onFinish: () => {
            clearPasswordConfirmation(visitOwnershipConfirmationForm);
            visitOwnershipConfirmationForm.confirm_target_ownership = false;
        },
    });
}

function retireActiveVisitEvent() {
    if (selectedActiveVisitId.value === null) {
        return;
    }

    activeVisitRetirementForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.active-visits.reconcile', {
        visit: selectedActiveVisitId.value,
    }), {
        preserveScroll: true,
        onSuccess: () => {
            selectedActiveVisitId.value = null;
            activeVisitRetirementForm.reset('m365_admin_upn', 'verification_reference');
        },
        onFinish: () => {
            clearPasswordConfirmation(activeVisitRetirementForm);
            activeVisitRetirementForm.confirm_remote_reconciliation = false;
        },
    });
}

function reconcileOutlookContact() {
    if (selectedContactReconciliationId.value === null) {
        return;
    }

    contactReconciliationForm.post(route('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.contacts.store', {
        contact: selectedContactReconciliationId.value,
    }), {
        preserveScroll: true,
        onSuccess: () => {
            selectedContactReconciliationId.value = null;
            contactReconciliationForm.reset('m365_admin_upn', 'verification_reference');
        },
        onFinish: () => {
            clearPasswordConfirmation(contactReconciliationForm);
            contactReconciliationForm.confirm_remote_reconciliation = false;
        },
    });
}
</script>

<template>
    <Head title="Microsoft Graph onboarding targets" />
    <AdminLayout title="Microsoft Graph onboarding targets">
        <div class="mx-auto grid max-w-5xl gap-6 px-4 py-8 sm:px-6 lg:px-8">
            <header class="grid gap-2">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-800">Integration setup · {{ environment }}</p>
                <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Microsoft Graph targets</h1>
                <p class="max-w-3xl text-sm leading-6 text-gray-600 dark:text-gray-400">
                    Enter identifiers supplied and approved by your Microsoft 365 administrator. The application does not enumerate mailboxes, calendars, groups, sites, or drives.
                </p>
            </header>

            <div class="grid gap-3 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                <p class="font-semibold">Saving targets does not verify permissions or activate Graph operations.</p>
                <p>
                    This page stores only the manually supplied identifiers. No Graph request, test email, canary write, permission change, or mailbox/site enumeration is performed. Validation remains pending and invitations stay locked until target-specific checks and cleanup are complete.
                </p>
                <p v-if="!onboardingWritesEnabled" class="font-semibold">
                    Onboarding Graph writes are disabled by deployment configuration. Read-only metadata checks remain available; linkback mail and canary creation or cleanup are blocked.
                </p>
                <p>
                    The Business Attachments site and drive must be provisioned and approved separately. Repository documentation continues to use its configured SharePoint target.
                </p>
            </div>

            <p v-if="runtimeReconciliation.visitCount > 0" class="rounded-md border border-orange-300 bg-orange-50 p-4 text-sm text-orange-950 dark:border-orange-800 dark:bg-orange-950 dark:text-orange-100" role="status">
                {{ runtimeReconciliation.visitCount }} Visit event(s) need mailbox-owner confirmation or reconciliation before Calendar writes. <a href="#visit-event-ownership-review" class="font-semibold underline underline-offset-2 focus:outline-none focus:ring-2 focus:ring-orange-700">Review Visit event ownership</a>.
            </p>

            <p v-if="status" class="rounded-md border border-green-300 bg-green-50 p-3 text-sm text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-200" role="status">
                {{ status }}
            </p>
            <p v-if="error" class="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200" role="alert">
                {{ error }}
            </p>

            <section class="grid gap-5 rounded-md border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-900">
                <form class="grid gap-5" @submit.prevent="saveTargets">
                    <fieldset class="grid gap-4">
                        <legend class="text-base font-semibold text-gray-900 dark:text-gray-100">Entra application and dedicated shared mailbox</legend>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="graph-tenant-id" value="Tenant ID" />
                                <TextInput id="graph-tenant-id" v-model="form.tenant_id" class="mt-1 block w-full" autocomplete="off" required />
                                <InputError class="mt-2" :message="form.errors.tenant_id" />
                            </div>
                            <div>
                                <InputLabel for="graph-client-id" value="Application (client) ID" />
                                <TextInput id="graph-client-id" v-model="form.client_id" class="mt-1 block w-full" autocomplete="off" required />
                                <InputError class="mt-2" :message="form.errors.client_id" />
                            </div>
                            <div>
                                <InputLabel for="graph-mailbox-id" value="Dedicated shared mailbox object ID" />
                                <TextInput id="graph-mailbox-id" v-model="form.notification_mailbox_id" class="mt-1 block w-full" autocomplete="off" required />
                                <InputError class="mt-2" :message="form.errors.notification_mailbox_id" />
                            </div>
                            <div>
                                <InputLabel for="graph-mailbox-address" value="Dedicated shared mailbox primary SMTP address" />
                                <TextInput id="graph-mailbox-address" v-model="form.notification_mailbox_address" type="email" class="mt-1 block w-full" autocomplete="off" required />
                                <InputError class="mt-2" :message="form.errors.notification_mailbox_address" />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                                    <input v-model="form.target_is_dedicated_shared_mailbox" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                                    <span>My Microsoft 365 administrator provided this exact object ID, confirmed it belongs to the designated shared mailbox, and preconfigured its Exchange permissions. The application does not grant permissions. This is not an individual staff mailbox.</span>
                                </label>
                                <InputError class="mt-2" :message="form.errors.target_is_dedicated_shared_mailbox" />
                            </div>
                            <div class="sm:col-span-2">
                                <InputLabel for="graph-calendar-id" value="Calendar ID (optional; leave blank for the default calendar)" />
                                <TextInput id="graph-calendar-id" v-model="form.notification_calendar_id" class="mt-1 block w-full" autocomplete="off" />
                                <InputError class="mt-2" :message="form.errors.notification_calendar_id" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="grid gap-4 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <legend class="text-base font-semibold text-gray-900 dark:text-gray-100">Repository documentation target</legend>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Used for approved repository Markdown sync, not business attachments.</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="work-schedules-group-id" value="Microsoft 365 Group ID" />
                                <TextInput id="work-schedules-group-id" v-model="form.work_schedules_group_id" class="mt-1 block w-full" autocomplete="off" required />
                                <InputError class="mt-2" :message="form.errors.work_schedules_group_id" />
                            </div>
                            <div>
                                <InputLabel for="work-schedules-site-id" value="Repository SharePoint Site ID" />
                                <TextInput id="work-schedules-site-id" v-model="form.work_schedules_site_id" class="mt-1 block w-full" autocomplete="off" required />
                                <InputError class="mt-2" :message="form.errors.work_schedules_site_id" />
                            </div>
                            <div class="sm:col-span-2">
                                <InputLabel for="work-schedules-drive-id" value="Repository documents Drive ID" />
                                <TextInput id="work-schedules-drive-id" v-model="form.work_schedules_drive_id" class="mt-1 block w-full" autocomplete="off" required />
                                <InputError class="mt-2" :message="form.errors.work_schedules_drive_id" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="grid gap-4 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <legend class="text-base font-semibold text-gray-900 dark:text-gray-100">M365-admin-approved non-target controls</legend>
                        <p class="text-sm leading-6 text-gray-600 dark:text-gray-400">
                            Use existing resources that the selected app must not access; do not create or license another account. Enter a real same-tenant user mailbox's sign-in email/UPN as the mailbox denial control and get the non-target SharePoint Site/Drive IDs from your Microsoft 365 administrator. The mailbox must be in this selected tenant; an SMTP alias that is not the sign-in UPN may not resolve. The application uses the email directly in exact Graph resource paths and does not search or enumerate users.
                        </p>
                        <details class="grid gap-3 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-700 dark:bg-gray-950">
                            <summary class="cursor-pointer font-semibold text-gray-900 dark:text-gray-100">Optional PowerShell setup lookup: find one non-target Site and its Drives</summary>
                            <p class="leading-6 text-gray-700 dark:text-gray-300">
                                This is an optional, one-time setup method for an M365 administrator. The application does not install Graph PowerShell or request <code>Sites.Read.All</code> in production. The commands query only the Site root URL you enter and list that Site's Drives (up to 100); they do not search or enumerate tenant Sites. An administrator may use another approved method, such as the M365 admin center, to provide these IDs instead.
                            </p>
                            <p class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm leading-6 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                                If the administrator chooses PowerShell, <code>Sites.Read.All</code> is a broad delegated permission, not a site-scoped grant. It can read Sites available to the signed-in administrator; the command's single-Site query does not narrow that permission. Use only an M365-admin-approved PowerShell session and preapproved consent. If new consent is requested unexpectedly, cancel. This scope is for that admin session, never the application.
                            </p>
                            <p class="flex flex-wrap gap-x-3 gap-y-1">
                                <a href="https://learn.microsoft.com/en-us/powershell/microsoftgraph/installation?view=graph-powershell-1.0" target="_blank" rel="noreferrer" class="font-semibold text-blue-800 underline dark:text-blue-300">Install Graph PowerShell</a>
                                <a href="https://learn.microsoft.com/en-us/powershell/microsoftgraph/authentication-commands?view=graph-powershell-1.0" target="_blank" rel="noreferrer" class="font-semibold text-blue-800 underline dark:text-blue-300">Connect-MgGraph</a>
                                <a href="https://learn.microsoft.com/en-us/powershell/module/microsoft.graph.authentication/invoke-mggraphrequest?view=graph-powershell-1.0" target="_blank" rel="noreferrer" class="font-semibold text-blue-800 underline dark:text-blue-300">Invoke-MgGraphRequest</a>
                                <a href="https://learn.microsoft.com/en-us/graph/api/site-getbypath?view=graph-rest-1.0" target="_blank" rel="noreferrer" class="font-semibold text-blue-800 underline dark:text-blue-300">Get a Site by path</a>
                                <a href="https://learn.microsoft.com/en-us/powershell/module/microsoft.graph.sites/get-mgsitedrive?view=graph-powershell-1.0" target="_blank" rel="noreferrer" class="font-semibold text-blue-800 underline dark:text-blue-300">Get-MgSiteDrive</a>
                            </p>
                            <pre class="overflow-x-auto rounded-md bg-gray-900 p-3 text-xs leading-5 text-gray-100"><code>Install-Module -Name @('Microsoft.Graph.Authentication','Microsoft.Graph.Sites') -Scope CurrentUser -Repository PSGallery
Import-Module Microsoft.Graph.Authentication
Import-Module Microsoft.Graph.Sites
Connect-MgGraph -Scopes 'Sites.Read.All' -NoWelcome

$siteUrl = [uri](Read-Host 'Known existing non-target Site root URL')
if ($siteUrl.Scheme -ne 'https' -or $siteUrl.Query -or $siteUrl.Fragment) {
    throw 'Enter the https Site root URL only, without a query or fragment.'
}
$sitePath = $siteUrl.AbsolutePath.TrimEnd('/')
if ([string]::IsNullOrWhiteSpace($sitePath)) { $sitePath = '/' }
$siteUri = 'https://graph.microsoft.com/v1.0/sites/{0}:{1}?$select=id,displayName,webUrl' -f $siteUrl.DnsSafeHost, $sitePath
$site = Invoke-MgGraphRequest -Method GET -Uri $siteUri
$site | Select-Object id, displayName, webUrl | Format-List

$drives = Get-MgSiteDrive -SiteId $site.id -Property @('id','name','driveType','webUrl') -Top 100
$drives | Select-Object id, name, driveType, webUrl | Format-Table -AutoSize
Disconnect-MgGraph</code></pre>
                            <p class="text-xs leading-5 text-gray-600 dark:text-gray-400">
                                Copy the returned Site ID and the Drive ID for the intended library into the fields below. A 404 from the application later is inconclusive; confirm the Site and Drive are existing and M365-admin-approved before using them as controls.
                            </p>
                        </details>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="non-target-mailbox-email" value="Existing non-target user mailbox sign-in email (UPN)" />
                                <TextInput id="non-target-mailbox-email" v-model="form.non_target_mailbox_email" type="email" class="mt-1 block w-full" autocomplete="off" />
                                <InputError class="mt-2" :message="form.errors.non_target_mailbox_email" />
                                <label class="mt-3 flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                                    <input v-model="form.non_target_is_user_mailbox" type="checkbox" class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                                    <span>My Microsoft 365 administrator confirmed this existing non-target control is a user mailbox.</span>
                                </label>
                                <InputError class="mt-2" :message="form.errors.non_target_is_user_mailbox" />
                            </div>
                            <div>
                                <InputLabel for="non-target-site-id" value="Non-target SharePoint Site ID" />
                                <TextInput id="non-target-site-id" v-model="form.non_target_site_id" class="mt-1 block w-full" autocomplete="off" />
                                <InputError class="mt-2" :message="form.errors.non_target_site_id" />
                            </div>
                            <div class="sm:col-span-2">
                                <InputLabel for="non-target-drive-id" value="Non-target SharePoint Drive ID" />
                                <TextInput id="non-target-drive-id" v-model="form.non_target_drive_id" class="mt-1 block w-full" autocomplete="off" />
                                <InputError class="mt-2" :message="form.errors.non_target_drive_id" />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="grid gap-4 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <legend class="text-base font-semibold text-gray-900 dark:text-gray-100">Business Attachments target</legend>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Enter only after an administrator has provisioned and approved the separate site/library.</p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="business-site-id" value="Business Attachments Site ID (optional until approved)" />
                                <TextInput id="business-site-id" v-model="form.business_attachments_site_id" class="mt-1 block w-full" autocomplete="off" />
                                <InputError class="mt-2" :message="form.errors.business_attachments_site_id" />
                            </div>
                            <div>
                                <InputLabel for="business-drive-id" value="Business Attachments Drive ID (optional until approved)" />
                                <TextInput id="business-drive-id" v-model="form.business_attachments_drive_id" class="mt-1 block w-full" autocomplete="off" />
                                <InputError class="mt-2" :message="form.errors.business_attachments_drive_id" />
                            </div>
                        </div>
                    </fieldset>

                    <div class="flex flex-wrap items-center gap-3 border-t border-gray-200 pt-5 dark:border-gray-700">
                        <PrimaryButton :disabled="form.processing">
                            {{ form.processing ? 'Saving…' : 'Save target identifiers' }}
                        </PrimaryButton>
                        <Link :href="route('admin.onboarding')" class="text-sm font-semibold text-blue-800 underline dark:text-blue-300">Back to setup progress</Link>
                    </div>
                </form>
            </section>

            <section v-if="target" class="grid gap-2 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm dark:border-gray-700 dark:bg-gray-950">
                <h2 class="font-semibold text-gray-900 dark:text-gray-100">Saved target status</h2>
                <p>Status: <span class="font-semibold">{{ target.validationStatus }}</span></p>
                <p v-if="target.configuredAt">Saved: {{ target.configuredAt }}</p>
                <p v-if="target.lastValidationAttemptedAt">Last validation attempt: {{ target.lastValidationAttemptedAt }}</p>
                <p v-if="target.readAccessVerifiedAt">Read-only metadata verified: {{ target.readAccessVerifiedAt }} · {{ target.readAccessCheckCount }} target checks</p>
                <p v-if="target.lastErrorCode" class="font-mono text-red-700 dark:text-red-300">{{ target.lastErrorCode }}</p>
                <p class="text-gray-600 dark:text-gray-400">These onboarding values are stored separately; current Graph consumers still use deployment configuration until a separate explicit activation gate is implemented.</p>
            </section>

            <section v-if="target" class="grid gap-4 rounded-md border p-5" :class="sectionPanels[preflightState]">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Read-only authority preflight</h2>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        On submit, the application resolves app credentials from the saved secret-manager project, requests an app-only token from Microsoft Entra, and performs HTTPS GET requests against the saved mailbox’s default or selected Calendar, repository documentation Site and Drive, and Business Attachments Site and Drive. It does not make standalone <code>/users/{id}</code> or <code>/groups/{id}</code> directory lookups. It reads metadata only—not messages, contacts, or file contents. It does not send email, create canaries, change permissions, or enable Graph writes.
                    </p>
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        This partial check does not verify allowed/denied access against a non-target resource, the email linkback, canary cleanup, or readiness. Invitations remain locked until the remaining Graph checks pass.
                    </p>
                </div>

                <form class="grid gap-4 sm:max-w-xl" @submit.prevent="validateReadAccess">
                    <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input v-model="validationForm.confirm_read_only_check" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                        <span>I authorize this read-only metadata check against the saved target identifiers.</span>
                    </label>
                    <div>
                        <InputLabel for="graph-check-password" value="Current password" />
                        <TextInput id="graph-check-password" v-model="validationForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="validationForm.errors.password" />
                    </div>
                    <PrimaryButton class="w-fit" :disabled="validationForm.processing || !validationForm.confirm_read_only_check">
                        {{ validationForm.processing ? 'Checking metadata…' : 'Run read-only target check' }}
                    </PrimaryButton>
                </form>
            </section>

            <section v-if="target" class="grid gap-4 rounded-md border p-5" :class="sectionPanels[boundaryState]">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Read-only non-target boundary probes</h2>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        With explicit authorization, the application probes the non-target default Calendar, Site, and Drive, plus the selected and non-target Contacts-folder collection endpoints. Contacts-folder requests use <code>$top=1</code> and <code>$select=id</code>; no Contact items are read. The v1.0 collection can be empty when there are no child folders under the default Contacts folder, so any successful selected-mailbox response counts as endpoint access. For non-targets, only a 403 counts as denial. A 200 means the app can access that control resource; a 404 is inconclusive, not proof of denial. No message read, sendMail call, write, or permission change occurs.
                    </p>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        This does not verify Exchange Mail.Send scope: the existing M365-admin PowerShell check remains required. These read-only probes do not prove write permissions or activate Graph operations.
                    </p>
                </div>

                <div v-if="target.boundaryValidationAttemptedAt" class="grid gap-1 rounded-md border border-gray-200 bg-white/70 p-3 text-sm dark:border-gray-700 dark:bg-gray-900/60">
                    <p>Last attempted: {{ target.boundaryValidationAttemptedAt }}</p>
                    <p>Non-target Calendar: <span class="font-semibold">{{ target.nonTargetMailboxCalendarStatus }}</span></p>
                    <p>Non-target Site: <span class="font-semibold">{{ target.nonTargetSiteStatus }}</span></p>
                    <p>Non-target Drive: <span class="font-semibold">{{ target.nonTargetDriveStatus }}</span></p>
                    <p>Selected Contacts-folder collection access: <span class="font-semibold">{{ target.targetContactsStatus }}</span></p>
                    <p>Non-target Contacts-folder collection access: <span class="font-semibold">{{ target.nonTargetContactsStatus }}</span></p>
                    <p v-if="target.boundaryVerifiedAt">Non-target Calendar/Site/Drive read denials verified at: {{ target.boundaryVerifiedAt }}</p>
                    <p v-if="target.contactsBoundaryVerifiedAt">Selected Contacts-folder collection request succeeded and non-target access was denied at: {{ target.contactsBoundaryVerifiedAt }}</p>
                    <p v-if="target.boundaryErrorCode" class="font-mono text-red-800 dark:text-red-300">Result: {{ target.boundaryErrorCode }}</p>
                    <p v-if="target.contactsBoundaryErrorCode" class="font-mono text-red-800 dark:text-red-300">Contacts result: {{ target.contactsBoundaryErrorCode }}</p>
                </div>

                <form v-if="canValidateBoundary" class="grid gap-4 sm:max-w-xl" @submit.prevent="validatePermissionBoundary">
                    <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input v-model="boundaryForm.confirm_boundary_check" type="checkbox" required class="mt-1 rounded border-gray-300 text-orange-700 focus:ring-orange-700" />
                        <span>I authorize five read-only metadata probes against the saved target and M365-admin-approved non-target control IDs (each may retry once after a 401 token refresh).</span>
                    </label>
                    <div>
                        <InputLabel for="graph-boundary-password" value="Current password" />
                        <TextInput id="graph-boundary-password" v-model="boundaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="boundaryForm.errors.password" />
                    </div>
                    <InputError :message="boundaryForm.errors.confirm_boundary_check" />
                    <PrimaryButton class="w-fit" :disabled="boundaryForm.processing || !boundaryForm.confirm_boundary_check">
                        {{ boundaryForm.processing ? 'Checking explicit controls…' : 'Check non-target read access' }}
                    </PrimaryButton>
                </form>
                <p v-else class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Complete the target metadata preflight, then save all three distinct non-target control IDs to enable this check.
                </p>
            </section>

            <section v-if="canSendLinkback" class="grid gap-4 rounded-md border p-5" :class="sectionPanels[linkbackState]">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Email delivery linkback</h2>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        This sends one plain-text setup message from the saved notification mailbox to the first Admin’s verified sign-in address. It contains only a 30-minute signed link, no customer or Job information, and does not read the mailbox. Graph acceptance is not proof of delivery; the recipient must open the message and explicitly confirm the link.
                    </p>
                    <p class="text-sm text-gray-700 dark:text-gray-300">
                        This verifies the single message-delivery path only. It does not test target/non-target authorization, create canaries, activate Graph operations, or unlock invitations.
                    </p>
                </div>

                <div v-if="linkback" class="grid gap-1 rounded-md border border-gray-200 bg-white/70 p-3 text-sm dark:border-gray-700 dark:bg-gray-900/60" role="status">
                    <p>Latest challenge: <span class="font-semibold">{{ linkback.status }}</span></p>
                    <p v-if="linkback.sentAt">Graph accepted at: {{ linkback.sentAt }}</p>
                    <p v-if="linkback.expiresAt">Link expires at: {{ linkback.expiresAt }}</p>
                    <p v-if="linkback.verifiedAt">Recipient confirmed at: {{ linkback.verifiedAt }}</p>
                    <p v-if="linkback.failureCode" class="font-mono">Result: {{ linkback.failureCode }}</p>
                    <p v-if="['sending', 'sent', 'unknown'].includes(linkback.status)" class="font-medium">Check the recipient mailbox before requesting another message. A new challenge invalidates this link.</p>
                </div>

                <form class="grid gap-4 sm:max-w-xl" @submit.prevent="sendLinkback">
                    <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input v-model="linkbackForm.confirm_send" type="checkbox" required class="mt-1 rounded border-gray-300 text-violet-700 focus:ring-violet-700" />
                        <span>I authorize the application to send one setup email to my verified sign-in address.</span>
                    </label>
                    <div>
                        <InputLabel for="graph-linkback-password" value="Current password" />
                        <TextInput id="graph-linkback-password" v-model="linkbackForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="linkbackForm.errors.password" />
                    </div>
                    <InputError :message="linkbackForm.errors.confirm_send" />
                    <PrimaryButton class="w-fit" :disabled="linkbackForm.processing || !linkbackForm.confirm_send">
                        {{ linkbackForm.processing ? 'Sending setup email…' : 'Send one setup email' }}
                    </PrimaryButton>
                </form>
            </section>

            <section v-if="target" class="grid gap-4 rounded-md border p-5" :class="sectionPanels[calendarCanaryState]">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Temporary Calendar write canary</h2>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        This creates one short, free, private event in the selected notification Calendar, with no attendees and no Customer, Job, or staff data. The application records its remote ID and ETag, then conditionally deletes it using <code>If-Match</code>. A timeout is not retried automatically. Calendar creation and cleanup are real Microsoft writes only after explicit authorization below.
                    </p>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        If creation or cleanup is ambiguous, do not run another canary. Use the saved subject and status to inspect the selected Calendar. Conditional cleanup conflicts require manual review; the application will not delete an event whose ETag changed.
                    </p>
                </div>

                <div v-if="lastCalendarCanary" class="grid gap-1 rounded-md border border-gray-200 bg-white/70 p-3 text-sm dark:border-gray-700 dark:bg-gray-900/60">
                    <h3 class="font-semibold">Most recent canary: {{ lastCalendarCanary.status }}</h3>
                    <p>Subject: <span class="font-mono">{{ lastCalendarCanary.subject }}</span></p>
                    <p v-if="lastCalendarCanary.remoteResourceId">Remote event ID: <span class="font-mono">{{ lastCalendarCanary.remoteResourceId }}</span></p>
                    <p v-if="lastCalendarCanary.createdRemotelyAt">Created remotely: {{ lastCalendarCanary.createdRemotelyAt }}</p>
                    <p v-if="lastCalendarCanary.cleanedAt">Conditionally cleaned: {{ lastCalendarCanary.cleanedAt }}</p>
                    <p v-if="lastCalendarCanary.reconciledAt">Manually reconciled: {{ lastCalendarCanary.reconciledAt }}</p>
                    <p v-if="lastCalendarCanary.failureCode" class="font-mono text-red-800 dark:text-red-300">Result: {{ lastCalendarCanary.failureCode }}</p>
                </div>

                <div v-if="outstandingCalendarCanaries.length > 0" class="grid gap-3">
                    <h3 class="font-semibold text-red-950 dark:text-red-100">Calendar canaries requiring reconciliation</h3>
                    <article v-for="canary in outstandingCalendarCanaries" :key="canary.id" class="grid gap-3 rounded-md border border-red-300 bg-white p-4 text-sm dark:border-red-800 dark:bg-gray-900">
                        <div class="grid gap-1">
                            <p>Status: <span class="font-semibold">{{ canary.status }}</span></p>
                            <p>Subject: <span class="font-mono">{{ canary.subject }}</span></p>
                            <p>Saved Tenant/App: <span class="font-mono">{{ canary.tenantId }} / {{ canary.clientId }}</span></p>
                            <p>Saved mailbox/calendar: <span class="font-mono">{{ canary.mailboxId }} / {{ canary.calendarId ?? 'default calendar' }}</span></p>
                            <p v-if="canary.remoteResourceId">Remote event ID: <span class="font-mono">{{ canary.remoteResourceId }}</span></p>
                            <p v-if="canary.failureCode" class="font-mono text-red-800 dark:text-red-300">Result: {{ canary.failureCode }}</p>
                            <p v-if="canary.status === 'create_unknown'" class="font-medium">Creation may have succeeded without returning an event ID. Inspect the Calendar for the exact subject before marking this reconciled.</p>
                            <p v-if="canary.status === 'cleanup_conflict'" class="font-medium">The event changed after creation or its ETag was unavailable. The application will not delete it automatically; inspect and remove it manually if appropriate.</p>
                            <p v-if="['creating', 'cleanup_pending'].includes(canary.status) && !canary.canRetryCleanup && !canary.canManuallyReconcile" class="font-medium">The operation may still be in flight. Reconciliation controls become available after five minutes if the server did not record an outcome.</p>
                        </div>

                        <form v-if="canary.canRetryCleanup" class="grid gap-3 border-t border-gray-200 pt-3 dark:border-gray-700" @submit.prevent="retryCalendarCanaryCleanup(canary.id)">
                            <label class="flex items-start gap-2">
                                <input v-model="cleanupCanaryForm.confirm_canary_action" type="checkbox" required class="mt-1 rounded border-gray-300 text-red-700 focus:ring-red-700" />
                                <span>I authorize one conditional cleanup retry for this saved event ID and ETag.</span>
                            </label>
                            <InputError :message="cleanupCanaryForm.errors.confirm_canary_action" />
                            <div>
                                <InputLabel for="calendar-cleanup-password" value="Current password" />
                                <TextInput id="calendar-cleanup-password" v-model="cleanupCanaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="cleanupCanaryForm.errors.password" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="cleanupCanaryForm.processing || !cleanupCanaryForm.confirm_canary_action">
                                {{ cleanupCanaryForm.processing ? 'Retrying cleanup…' : 'Retry conditional cleanup' }}
                            </PrimaryButton>
                        </form>

                        <form v-if="canary.canManuallyReconcile" class="grid gap-3 border-t border-gray-200 pt-3 dark:border-gray-700" @submit.prevent="reconcileCalendarCanary(canary.id)">
                            <label class="flex items-start gap-2">
                                <input v-model="reconcileCanaryForm.confirm_canary_action" type="checkbox" required class="mt-1 rounded border-gray-300 text-amber-700 focus:ring-amber-700" />
                                <span>I inspected this exact event in the saved Calendar in consultation with our M365 administrator and removed it if present, or verified it is absent.</span>
                            </label>
                            <InputError :message="reconcileCanaryForm.errors.confirm_canary_action" />
                            <div>
                                <InputLabel for="calendar-reconcile-password" value="Current password" />
                                <TextInput id="calendar-reconcile-password" v-model="reconcileCanaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="reconcileCanaryForm.errors.password" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="reconcileCanaryForm.processing || !reconcileCanaryForm.confirm_canary_action">
                                {{ reconcileCanaryForm.processing ? 'Recording reconciliation…' : 'Record manual reconciliation' }}
                            </PrimaryButton>
                        </form>
                    </article>
                </div>

                <form v-if="canCreateCalendarCanary" class="grid gap-4 sm:max-w-xl border-t border-gray-200 pt-4 dark:border-gray-700" @submit.prevent="createCalendarCanary">
                    <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input v-model="calendarCanaryForm.confirm_canary_action" type="checkbox" required class="mt-1 rounded border-gray-300 text-rose-700 focus:ring-rose-700" />
                        <span>I explicitly authorize one temporary Calendar event creation followed by conditional cleanup on the saved target.</span>
                    </label>
                    <div>
                        <InputLabel for="calendar-canary-password" value="Current password" />
                        <TextInput id="calendar-canary-password" v-model="calendarCanaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="calendarCanaryForm.errors.password" />
                    </div>
                    <InputError :message="calendarCanaryForm.errors.confirm_canary_action" />
                    <PrimaryButton class="w-fit" :disabled="calendarCanaryForm.processing || !calendarCanaryForm.confirm_canary_action">
                        {{ calendarCanaryForm.processing ? 'Creating and cleaning canary…' : 'Run Calendar canary' }}
                    </PrimaryButton>
                </form>
                <p v-else-if="outstandingCalendarCanaries.length === 0" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Complete target metadata and non-target Calendar denial checks before creating a write canary.
                </p>
            </section>

            <section v-if="target" class="grid gap-4 rounded-md border p-5" :class="sectionPanels[contactCanaryState]">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Temporary Contacts write canary</h2>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        This creates one uniquely named Contact in the selected mailbox's default Contacts folder. It includes only generated setup text—no email address, phone number, Customer, Job, or staff data. The application records its remote ID and ETag, then conditionally deletes it with <code>If-Match</code>. An ambiguous create is not retried automatically.
                    </p>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        The prerequisite check requests at most one Contacts-folder collection row for the selected and non-target mailboxes; the collection may be empty when there are no child folders. It does not read Contact items or prove a particular folder is readable. This canary then tests one write to the selected mailbox's default Contacts folder. It does not prove non-target Contacts write denial or enable Contact projection. M365 administrators must separately review the supported Contacts permission boundary.
                    </p>
                </div>

                <div
                    v-if="contactsCanaryFeedback"
                    class="rounded-md border p-3 text-sm"
                    :class="contactsCanaryFeedback.type === 'success'
                        ? 'border-green-300 bg-green-50 text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-200'
                        : 'border-red-300 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-200'"
                    :role="contactsCanaryFeedback.type === 'success' ? 'status' : 'alert'"
                    aria-live="polite"
                >
                    {{ contactsCanaryFeedback.message }}
                </div>

                <div v-if="lastContactCanary" class="grid gap-1 rounded-md border border-gray-200 bg-white/70 p-3 text-sm dark:border-gray-700 dark:bg-gray-900/60">
                    <h3 class="font-semibold">Most recent canary: {{ lastContactCanary.status }}</h3>
                    <p>Contact marker: <span class="font-mono">{{ lastContactCanary.subject }}</span></p>
                    <p v-if="lastContactCanary.remoteResourceId">Remote Contact ID: <span class="font-mono">{{ lastContactCanary.remoteResourceId }}</span></p>
                    <p v-if="lastContactCanary.createdRemotelyAt">Created remotely: {{ lastContactCanary.createdRemotelyAt }}</p>
                    <p v-if="lastContactCanary.cleanedAt">Conditionally cleaned: {{ lastContactCanary.cleanedAt }}</p>
                    <p v-if="lastContactCanary.reconciledAt">Manually reconciled: {{ lastContactCanary.reconciledAt }}</p>
                    <p v-if="lastContactCanary.failureCode" class="font-mono text-red-800 dark:text-red-300">Result: {{ lastContactCanary.failureCode }}</p>
                </div>

                <div v-if="outstandingContactsCanaries.length > 0" class="grid gap-3">
                    <h3 class="font-semibold text-red-950 dark:text-red-100">Contacts canaries requiring reconciliation</h3>
                    <article v-for="canary in outstandingContactsCanaries" :key="canary.id" class="grid gap-3 rounded-md border border-red-300 bg-white p-4 text-sm dark:border-red-800 dark:bg-gray-900">
                        <div class="grid gap-1">
                            <p>Status: <span class="font-semibold">{{ canary.status }}</span></p>
                            <p>Contact marker: <span class="font-mono">{{ canary.subject }}</span></p>
                            <p>Saved mailbox ID: <span class="font-mono">{{ canary.mailboxId }}</span></p>
                            <p v-if="canary.remoteResourceId">Remote Contact ID: <span class="font-mono">{{ canary.remoteResourceId }}</span></p>
                            <p v-if="canary.failureCode" class="font-mono text-red-800 dark:text-red-300">Result: {{ canary.failureCode }}</p>
                            <p v-if="canary.status === 'create_unknown'" class="font-medium">Creation may have succeeded without returning a Contact ID. Inspect the default Contacts folder for this exact marker before manually reconciling.</p>
                            <p v-if="canary.status === 'cleanup_conflict'" class="font-medium">The Contact changed after creation or its ETag was unavailable. The application will not delete it automatically; inspect and remove it manually if appropriate.</p>
                            <p v-if="['creating', 'cleanup_pending'].includes(canary.status) && !canary.canRetryCleanup && !canary.canManuallyReconcile" class="font-medium">The operation may still be in flight. Reconciliation controls become available after five minutes if the server did not record an outcome.</p>
                        </div>

                        <form v-if="canary.canRetryCleanup" class="grid gap-3 border-t border-gray-200 pt-3 dark:border-gray-700" @submit.prevent="retryContactsCanaryCleanup(canary.id)">
                            <label class="flex items-start gap-2">
                                <input v-model="cleanupContactCanaryForm.confirm_canary_action" type="checkbox" required class="mt-1 rounded border-gray-300 text-red-700 focus:ring-red-700" />
                                <span>I authorize one conditional cleanup retry for this saved Contact ID and ETag.</span>
                            </label>
                            <InputError :message="cleanupContactCanaryForm.errors.confirm_canary_action" />
                            <div>
                                <InputLabel for="contacts-cleanup-password" value="Current password" />
                                <TextInput id="contacts-cleanup-password" v-model="cleanupContactCanaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="cleanupContactCanaryForm.errors.password" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="cleanupContactCanaryForm.processing || !cleanupContactCanaryForm.confirm_canary_action">
                                {{ cleanupContactCanaryForm.processing ? 'Retrying cleanup…' : 'Retry conditional cleanup' }}
                            </PrimaryButton>
                        </form>

                        <form v-if="canary.canManuallyReconcile" class="grid gap-3 border-t border-gray-200 pt-3 dark:border-gray-700" @submit.prevent="reconcileContactsCanary(canary.id)">
                            <label class="flex items-start gap-2">
                                <input v-model="reconcileContactCanaryForm.confirm_canary_action" type="checkbox" required class="mt-1 rounded border-gray-300 text-amber-700 focus:ring-amber-700" />
                                <span>I inspected this exact marker in the saved Contacts folder in consultation with our M365 administrator and removed it if present, or verified it is absent.</span>
                            </label>
                            <InputError :message="reconcileContactCanaryForm.errors.confirm_canary_action" />
                            <div>
                                <InputLabel for="contacts-reconcile-password" value="Current password" />
                                <TextInput id="contacts-reconcile-password" v-model="reconcileContactCanaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="reconcileContactCanaryForm.errors.password" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="reconcileContactCanaryForm.processing || !reconcileContactCanaryForm.confirm_canary_action">
                                {{ reconcileContactCanaryForm.processing ? 'Recording reconciliation…' : 'Record manual reconciliation' }}
                            </PrimaryButton>
                        </form>
                    </article>
                </div>

                <form v-if="canCreateContactCanary" class="grid gap-4 sm:max-w-xl border-t border-gray-200 pt-4 dark:border-gray-700" @submit.prevent="createContactsCanary">
                    <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input v-model="contactCanaryForm.confirm_canary_action" type="checkbox" required class="mt-1 rounded border-gray-300 text-cyan-700 focus:ring-cyan-700" />
                        <span>I explicitly authorize one temporary Contact creation followed by conditional cleanup on the saved mailbox.</span>
                    </label>
                    <div>
                        <InputLabel for="contacts-canary-password" value="Current password" />
                        <TextInput id="contacts-canary-password" v-model="contactCanaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="contactCanaryForm.errors.password" />
                    </div>
                    <InputError :message="contactCanaryForm.errors.confirm_canary_action" />
                    <PrimaryButton class="w-fit" :disabled="contactCanaryForm.processing || !contactCanaryForm.confirm_canary_action">
                        {{ contactCanaryForm.processing ? 'Creating and cleaning Contact…' : 'Run Contacts canary' }}
                    </PrimaryButton>
                </form>
                <p v-else-if="outstandingContactsCanaries.length === 0" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Complete the selected/non-target Contacts-folder collection access check before creating a Contacts write canary.
                </p>
            </section>

            <section v-if="target" class="grid gap-4 rounded-md border p-5" :class="sectionPanels[permissionVerificationState]">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">M365 administrator permission verification</h2>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        After an M365 administrator runs the documented Exchange scope checks for this exact dedicated shared mailbox and an approved existing user-mailbox control, review the Contacts permission boundary, verify the Business Attachments Site/Drive scope and retention settings, and inspect for equivalent unscoped Entra grants. Enter only the M365 administrator's UPN and a short ticket/run reference—never paste command output, credentials, tokens, or raw provider responses.
                    </p>
                    <p class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm leading-6 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                        This is an administrator attestation that the M365 checks were completed. The saved target must be a dedicated shared mailbox and the mailbox denial control must be a user mailbox. The application does not independently query Exchange RBAC or Entra grants. The record is append-only and bound to the current tenant, app, mailbox, mailbox types, Business Attachments Site, and Drive. Target changes make old evidence inapplicable.
                    </p>
                </div>

                <div v-if="permissionVerification" class="grid gap-1 rounded-md border border-gray-200 bg-white/70 p-3 text-sm dark:border-gray-700 dark:bg-gray-900/60">
                    <h3 class="font-semibold">Latest attestation for these saved targets</h3>
                    <p>M365 administrator UPN: <span class="font-mono">{{ permissionVerification.m365AdminUpn }}</span></p>
                    <p>Verification reference: <span class="font-mono">{{ permissionVerification.verificationReference }}</span></p>
                    <p>Mailbox scope: dedicated shared mailbox target · user-mailbox denial control <span class="font-mono">{{ permissionVerification.nonTargetMailboxEmail }}</span></p>
                    <p>Target type: {{ permissionVerification.targetIsDedicatedSharedMailbox ? 'Dedicated shared mailbox confirmed' : 'Not confirmed' }} · Non-target type: {{ permissionVerification.nonTargetIsUserMailbox ? 'User mailbox confirmed' : 'Not confirmed' }}</p>
                    <p v-if="permissionVerification.verifiedAt">Recorded: {{ permissionVerification.verifiedAt }}</p>
                </div>

                <form v-if="canRecordPermissionVerification" class="grid gap-4 border-t border-gray-200 pt-4 dark:border-gray-700" @submit.prevent="recordPermissionVerification">
                    <div class="grid gap-3 rounded-md bg-white/70 p-4 text-sm dark:bg-gray-900/60">
                        <label class="flex items-start gap-2">
                            <input v-model="permissionVerificationForm.mail_send_target_verified" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                            <span>M365 administrator verified `Application Mail.Send` is allowed for the selected mailbox.</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input v-model="permissionVerificationForm.mail_send_non_target_denied" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                            <span>M365 administrator verified `Application Mail.Send` is denied for the approved non-target mailbox.</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input v-model="permissionVerificationForm.calendar_write_target_verified" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                            <span>M365 administrator verified `Application Calendars.ReadWrite` is allowed for the selected mailbox.</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input v-model="permissionVerificationForm.calendar_write_non_target_denied" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                            <span>M365 administrator verified `Application Calendars.ReadWrite` is denied for the approved non-target mailbox.</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input v-model="permissionVerificationForm.contacts_boundary_verified" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                            <span>M365 administrator reviewed Contacts write authorization for the selected and non-target mailboxes; selected access is allowed and non-target access is denied.</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input v-model="permissionVerificationForm.sharepoint_boundary_verified" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                            <span>M365 administrator verified the selected Business Attachments Site/Drive is accessible and an approved non-target Site/Drive is denied.</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input v-model="permissionVerificationForm.sharepoint_retention_reviewed" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                            <span>M365 administrator reviewed the approved Business Attachments target's retention settings.</span>
                        </label>
                        <label class="flex items-start gap-2">
                            <input v-model="permissionVerificationForm.unscoped_app_roles_reviewed" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                            <span>M365 administrator reviewed Entra app-role grants and confirmed no equivalent unscoped Graph grants bypass the selected-target boundary.</span>
                        </label>
                    </div>
                    <InputError :message="permissionVerificationForm.errors.mail_send_target_verified || permissionVerificationForm.errors.mail_send_non_target_denied || permissionVerificationForm.errors.calendar_write_target_verified || permissionVerificationForm.errors.calendar_write_non_target_denied || permissionVerificationForm.errors.contacts_boundary_verified || permissionVerificationForm.errors.sharepoint_boundary_verified || permissionVerificationForm.errors.sharepoint_retention_reviewed || permissionVerificationForm.errors.unscoped_app_roles_reviewed" />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="m365-admin-upn" value="M365 administrator UPN" />
                            <TextInput id="m365-admin-upn" v-model="permissionVerificationForm.m365_admin_upn" type="email" autocomplete="off" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="permissionVerificationForm.errors.m365_admin_upn" />
                        </div>
                        <div>
                            <InputLabel for="m365-verification-reference" value="Short ticket/run reference (not command output)" />
                            <TextInput id="m365-verification-reference" v-model="permissionVerificationForm.verification_reference" autocomplete="off" required class="mt-1 block w-full" />
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Start with a letter, use no spaces, and separate the prefix and reference with <code>-</code>, <code>:</code>, or <code>#</code>. Example: <code>CHG-2026-1042</code>.</p>
                            <InputError class="mt-2" :message="permissionVerificationForm.errors.verification_reference" />
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="permission-verification-password" value="Current password" />
                            <TextInput id="permission-verification-password" v-model="permissionVerificationForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="permissionVerificationForm.errors.password" />
                        </div>
                    </div>
                    <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input v-model="permissionVerificationForm.confirm_scope_verification" type="checkbox" required class="mt-1 rounded border-gray-300 text-indigo-700 focus:ring-indigo-700" />
                        <span>I confirm an M365 administrator completed every checked review against the exact saved targets and the evidence reference contains no secret or raw provider output.</span>
                    </label>
                    <InputError :message="permissionVerificationForm.errors.confirm_scope_verification" />
                    <PrimaryButton class="w-fit" :disabled="permissionVerificationForm.processing || !permissionVerificationForm.confirm_scope_verification">
                        {{ permissionVerificationForm.processing ? 'Recording verification…' : 'Record M365-admin verification' }}
                    </PrimaryButton>
                </form>
                <p v-else-if="!permissionVerification" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Complete full selected-target metadata checks, the Calendar/Site/Drive and Contacts read-boundary probes, and first-Admin email linkback before recording the external M365-admin review.
                </p>
            </section>

            <section v-if="target" class="grid gap-4 rounded-md border p-5" :class="sectionPanels[sharePointCanaryState]">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Temporary Business Attachments SharePoint canary</h2>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        This creates one uniquely marked empty folder at the root of the separately approved Business Attachments Drive. It contains no business files or customer data. The application records its DriveItem ID and eTag, then attempts conditional deletion with <code>If-Match</code>. Ambiguous creation is never retried automatically.
                    </p>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        The canary requires current-target metadata verification and the recorded M365-admin boundary/retention review. It proves a write to this selected Drive only; it does not independently prove non-target denial. No live canary has been run during development.
                    </p>
                </div>

                <div v-if="lastSharePointCanary" class="grid gap-1 rounded-md border border-gray-200 bg-white/70 p-3 text-sm dark:border-gray-700 dark:bg-gray-900/60">
                    <h3 class="font-semibold">Most recent canary: {{ lastSharePointCanary.status }}</h3>
                    <p>Folder marker: <span class="font-mono">{{ lastSharePointCanary.folderName }}</span></p>
                    <p v-if="lastSharePointCanary.remoteItemId">Remote DriveItem ID: <span class="font-mono">{{ lastSharePointCanary.remoteItemId }}</span></p>
                    <p v-if="lastSharePointCanary.createdRemotelyAt">Created remotely: {{ lastSharePointCanary.createdRemotelyAt }}</p>
                    <p v-if="lastSharePointCanary.cleanedAt">Conditionally cleaned: {{ lastSharePointCanary.cleanedAt }}</p>
                    <p v-if="lastSharePointCanary.reconciledAt">Manually reconciled: {{ lastSharePointCanary.reconciledAt }}</p>
                    <p v-if="lastSharePointCanary.failureCode" class="font-mono text-red-800 dark:text-red-300">Result: {{ lastSharePointCanary.failureCode }}</p>
                </div>

                <div v-if="outstandingSharePointCanaries.length > 0" class="grid gap-3">
                    <h3 class="font-semibold text-red-950 dark:text-red-100">SharePoint canaries requiring reconciliation</h3>
                    <article v-for="canary in outstandingSharePointCanaries" :key="canary.id" class="grid gap-3 rounded-md border border-red-300 bg-white p-4 text-sm dark:border-red-800 dark:bg-gray-900">
                        <div class="grid gap-1">
                            <p>Status: <span class="font-semibold">{{ canary.status }}</span></p>
                            <p>Folder marker: <span class="font-mono">{{ canary.folderName }}</span></p>
                            <p>Saved Site/Drive: <span class="font-mono">{{ canary.siteId }} / {{ canary.driveId }}</span></p>
                            <p v-if="canary.remoteItemId">Remote DriveItem ID: <span class="font-mono">{{ canary.remoteItemId }}</span></p>
                            <p v-if="canary.failureCode" class="font-mono text-red-800 dark:text-red-300">Result: {{ canary.failureCode }}</p>
                            <p v-if="canary.status === 'create_unknown'" class="font-medium">Creation may have succeeded without returning a DriveItem ID. Inspect the saved Drive for this exact folder marker before manually reconciling.</p>
                            <p v-if="canary.status === 'cleanup_conflict'" class="font-medium">The folder changed or its eTag was unavailable. The application will not delete it automatically; inspect and remove it manually if appropriate.</p>
                            <p v-if="['creating', 'cleanup_pending'].includes(canary.status) && !canary.canRetryCleanup && !canary.canManuallyReconcile" class="font-medium">The operation may still be in flight. Reconciliation controls become available after five minutes if no outcome was recorded.</p>
                        </div>
                        <form v-if="canary.canRetryCleanup" class="grid gap-3 border-t border-gray-200 pt-3 dark:border-gray-700" @submit.prevent="retrySharePointCanaryCleanup(canary.id)">
                            <label class="flex items-start gap-2">
                                <input v-model="cleanupSharePointCanaryForm.confirm_canary_action" type="checkbox" required class="mt-1 rounded border-gray-300 text-red-700 focus:ring-red-700" />
                                <span>I authorize one conditional cleanup retry for this saved DriveItem ID and eTag.</span>
                            </label>
                            <div>
                                <InputLabel for="sharepoint-cleanup-password" value="Current password" />
                                <TextInput id="sharepoint-cleanup-password" v-model="cleanupSharePointCanaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="cleanupSharePointCanaryForm.errors.password" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="cleanupSharePointCanaryForm.processing || !cleanupSharePointCanaryForm.confirm_canary_action">
                                {{ cleanupSharePointCanaryForm.processing ? 'Retrying cleanup…' : 'Retry conditional cleanup' }}
                            </PrimaryButton>
                        </form>
                        <form v-if="canary.canManuallyReconcile" class="grid gap-3 border-t border-gray-200 pt-3 dark:border-gray-700" @submit.prevent="reconcileSharePointCanary(canary.id)">
                            <label class="flex items-start gap-2">
                                <input v-model="reconcileSharePointCanaryForm.confirm_canary_action" type="checkbox" required class="mt-1 rounded border-gray-300 text-amber-700 focus:ring-amber-700" />
                                <span>I inspected this exact folder marker in the saved Business Attachments Drive in consultation with our M365 administrator and removed it if present, or verified it is absent.</span>
                            </label>
                            <div>
                                <InputLabel for="sharepoint-reconcile-password" value="Current password" />
                                <TextInput id="sharepoint-reconcile-password" v-model="reconcileSharePointCanaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="reconcileSharePointCanaryForm.errors.password" />
                            </div>
                            <PrimaryButton class="w-fit" :disabled="reconcileSharePointCanaryForm.processing || !reconcileSharePointCanaryForm.confirm_canary_action">
                                {{ reconcileSharePointCanaryForm.processing ? 'Recording reconciliation…' : 'Record manual reconciliation' }}
                            </PrimaryButton>
                        </form>
                    </article>
                </div>

                <form v-if="canCreateSharePointCanary" class="grid gap-4 border-t border-gray-200 pt-4 dark:border-gray-700 sm:max-w-xl" @submit.prevent="createSharePointCanary">
                    <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input v-model="sharePointCanaryForm.confirm_canary_action" type="checkbox" required class="mt-1 rounded border-gray-300 text-emerald-700 focus:ring-emerald-700" />
                        <span>I explicitly authorize creation of one empty marker folder in the saved Business Attachments Drive followed by conditional cleanup.</span>
                    </label>
                    <div>
                        <InputLabel for="sharepoint-canary-password" value="Current password" />
                        <TextInput id="sharepoint-canary-password" v-model="sharePointCanaryForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="sharePointCanaryForm.errors.password" />
                    </div>
                    <InputError :message="sharePointCanaryForm.errors.confirm_canary_action" />
                    <PrimaryButton class="w-fit" :disabled="sharePointCanaryForm.processing || !sharePointCanaryForm.confirm_canary_action">
                        {{ sharePointCanaryForm.processing ? 'Creating and cleaning folder…' : 'Run SharePoint canary' }}
                    </PrimaryButton>
                </form>
                <p v-else-if="outstandingSharePointCanaries.length === 0" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Approve and configure the Business Attachments Site/Drive, complete its read metadata check, and record M365-admin permission evidence before creating a canary.
                </p>
            </section>

            <section v-if="target" class="grid gap-4 rounded-md border p-5" :class="sectionPanels[finalizeState]">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Finalize Graph readiness</h2>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        This does not call Microsoft Graph. It rechecks the saved target-bound read tests, linkback, M365-admin attestation, all three successfully created and cleaned canaries, and absence of unresolved cleanup before recording the Graph setup step as passed.
                    </p>
                    <p v-if="graphReadinessStepStatus === 'passed'" class="text-sm font-semibold text-green-900 dark:text-green-200" role="status">Graph readiness was finalized. Any later target changes invalidate the evidence for a new readiness evaluation.</p>
                </div>
                <form v-if="canFinalizeGraphReadiness" class="grid gap-4 sm:max-w-xl" @submit.prevent="finalizeGraphReadiness">
                    <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input v-model="finalizeGraphReadinessForm.confirm_finalize_graph" type="checkbox" required class="mt-1 rounded border-gray-300 text-blue-700 focus:ring-blue-700" />
                        <span>I reviewed the saved M365 evidence and authorize the application to finalize the Graph readiness step for these exact targets.</span>
                    </label>
                    <div>
                        <InputLabel for="finalize-graph-password" value="Current password" />
                        <TextInput id="finalize-graph-password" v-model="finalizeGraphReadinessForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="finalizeGraphReadinessForm.errors.password" />
                    </div>
                    <InputError :message="finalizeGraphReadinessForm.errors.confirm_finalize_graph" />
                    <PrimaryButton class="w-fit" :disabled="finalizeGraphReadinessForm.processing || !finalizeGraphReadinessForm.confirm_finalize_graph">
                        {{ finalizeGraphReadinessForm.processing ? 'Rechecking and finalizing…' : 'Finalize Graph readiness' }}
                    </PrimaryButton>
                </form>
                <p v-else-if="graphReadinessStepStatus !== 'passed'" class="text-sm text-gray-700 dark:text-gray-300">
                    Finish every remaining Graph prerequisite shown on the First-run setup checklist before finalization is available.
                    <Link :href="route('admin.onboarding')" class="font-semibold underline">View readiness blockers</Link>
                </p>
            </section>

            <section v-if="target" id="visit-event-ownership-review" class="grid scroll-mt-6 gap-4 rounded-md border border-orange-300 bg-orange-50 p-5 dark:border-orange-800 dark:bg-orange-950">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-orange-950 dark:text-orange-100">Review Visit event mailbox ownership and reconcile legacy IDs</h2>
                    <p class="text-sm leading-6 text-orange-950 dark:text-orange-100">
                        Visit rows now record the tenant, mailbox, and Calendar that owns each event ID. Older rows may have no owner snapshot, even if deployment configuration has since changed. Ask your M365 administrator to check the exact event ID in the intended and previous mailbox Calendars. If it is in the active Calendar, record that confirmation below and retain the ID; if an old event was removed or reconciled, clear only its local pointer. Neither action calls Graph or deletes remote data. The Contact list contains only Customer Contacts managed by the application, not a Microsoft users/mailbox directory.
                    </p>
                    <p class="text-sm text-orange-950 dark:text-orange-100">
                        Pending items: {{ runtimeReconciliation.visitCount }} Visit event(s), {{ runtimeReconciliation.contactCount }} Outlook Contact(s). This view lists at most {{ runtimeReconciliation.displayLimit }} per type; refresh after each batch to see remaining items.
                    </p>
                    <p v-if="runtimeReconciliation.inFlightVisitCount || runtimeReconciliation.inFlightContactCount || runtimeReconciliation.unresolvedMailCount" class="text-sm font-semibold text-orange-950 dark:text-orange-100">
                        Runtime activation also waits for {{ runtimeReconciliation.inFlightVisitCount }} pending/in-flight Visit sync(s), {{ runtimeReconciliation.inFlightContactCount }} pending/in-flight/unknown Contact sync(s), and {{ runtimeReconciliation.unresolvedMailCount }} unresolved mail outbox item(s) to be resolved.
                    </p>
                    <p class="break-all text-xs text-orange-950 dark:text-orange-100">
                        Deployment-configured tenant/mailbox/calendar (not proof of the source for older Visit IDs): <code>{{ runtimeReconciliation.legacyTenantId ?? 'not configured' }} / {{ runtimeReconciliation.legacyMailboxId ?? 'not configured' }} / {{ runtimeReconciliation.legacyCalendarId ?? 'default calendar' }}</code>
                    </p>
                    <p class="break-all text-xs text-orange-950 dark:text-orange-100">
                        Pending destination tenant/shared mailbox: <code>{{ runtimeReconciliation.targetTenantId }} / {{ runtimeReconciliation.targetMailboxId }}</code>
                    </p>
                    <p v-if="!runtimeReconciliation.targetConfirmed || !runtimeReconciliation.controlConfirmed" class="font-semibold text-orange-950 dark:text-orange-100">
                        Confirm the exact target is the dedicated shared mailbox and the non-target control is a same-tenant user mailbox before reconciling IDs.
                    </p>
                    <p v-if="target.runtimeActivatedAt" class="font-semibold text-orange-950 dark:text-orange-100">
                        The runtime target is active. M365-admin Visit ownership confirmation and local pointer reconciliation remain available; neither performs Graph operations.
                    </p>
                </div>

                <div v-if="runtimeReconciliation.visits.length > 0" class="grid gap-3">
                    <h3 class="font-semibold text-orange-950 dark:text-orange-100">Visit calendar events</h3>
                    <label v-for="visit in runtimeReconciliation.visits" :key="`visit-reconcile-${visit.id}`" class="grid gap-1 rounded-md border border-orange-200 bg-white/70 p-3 text-sm dark:border-orange-800 dark:bg-gray-900/60">
                        <span class="flex items-start gap-2">
                            <input v-model="selectedVisitReconciliationId" type="radio" :value="visit.id" class="mt-1 border-gray-300 text-orange-700 focus:ring-orange-700" :disabled="!runtimeReconciliation.canReconcileVisits && !runtimeReconciliation.canConfirmVisitOwnership" />
                            <span><strong>Visit #{{ visit.id }}</strong> · {{ visit.jobNumber ?? 'Job' }} {{ visit.jobTitle ?? '' }} · {{ visit.scheduledAt ?? 'unscheduled' }}<span v-if="visit.archived"> · archived</span></span>
                        </span>
                        <span class="pl-6 text-xs text-gray-700 dark:text-gray-300">
                            <template v-if="visit.unknownCreate">Visit event create outcome is unknown; transaction ID: <code>{{ visit.transactionId }}</code></template>
                            <template v-else>Event ID: <code>{{ visit.eventId }}</code> · <span v-if="visit.ownerStatus === 'unverified'">mailbox owner not recorded</span><span v-else>recorded owner differs from the active target</span><span v-if="visit.sourceMailboxId"> · saved mailbox/Calendar: <code>{{ visit.sourceMailboxId }} / {{ visit.sourceCalendarId ?? 'default Calendar' }}</code></span></template>
                        </span>
                    </label>

                    <form v-if="selectedVisitReconciliation?.ownerStatus === 'unverified' && !selectedVisitReconciliation?.unknownCreate && runtimeReconciliation.canConfirmVisitOwnership" class="grid gap-3 border-t border-orange-200 pt-3 dark:border-orange-800" @submit.prevent="confirmVisitEventOwnership">
                        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                            <input v-model="visitOwnershipConfirmationForm.confirm_target_ownership" type="checkbox" required class="mt-1 rounded border-gray-300 text-green-700 focus:ring-green-700" />
                            <span>My M365 administrator checked this exact event ID in the saved shared-mailbox Calendar and confirmed it belongs to this target. Record the owner snapshot and keep the local event ID; do not create, update, or delete a remote event.</span>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel for="visit-owner-admin-upn" value="M365 administrator UPN" />
                                <TextInput id="visit-owner-admin-upn" v-model="visitOwnershipConfirmationForm.m365_admin_upn" type="email" autocomplete="off" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="visitOwnershipConfirmationForm.errors.m365_admin_upn" />
                            </div>
                            <div>
                                <InputLabel for="visit-owner-reference" value="Short ticket/run reference" />
                                <TextInput id="visit-owner-reference" v-model="visitOwnershipConfirmationForm.verification_reference" autocomplete="off" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="visitOwnershipConfirmationForm.errors.verification_reference" />
                            </div>
                            <div>
                                <InputLabel for="visit-owner-password" value="Current password" />
                                <TextInput id="visit-owner-password" v-model="visitOwnershipConfirmationForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="visitOwnershipConfirmationForm.errors.password" />
                            </div>
                        </div>
                        <InputError :message="visitOwnershipConfirmationForm.errors.confirm_target_ownership" />
                        <PrimaryButton class="w-fit" :disabled="visitOwnershipConfirmationForm.processing || !visitOwnershipConfirmationForm.confirm_target_ownership">
                            {{ visitOwnershipConfirmationForm.processing ? 'Recording ownership…' : 'Confirm active mailbox ownership' }}
                        </PrimaryButton>
                    </form>

                    <form v-if="selectedVisitReconciliationId !== null && selectedVisitReconciliation && runtimeReconciliation.canReconcileVisits" class="grid gap-3 border-t border-orange-200 pt-3 dark:border-orange-800" @submit.prevent="reconcileVisitEvent">
                        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                            <input v-model="visitReconciliationForm.confirm_remote_reconciliation" type="checkbox" required class="mt-1 rounded border-gray-300 text-orange-700 focus:ring-orange-700" />
                            <span v-if="selectedVisitReconciliation?.unknownCreate">I inspected the relevant mailbox Calendars with the M365 administrator and confirmed that any event from this unknown create outcome has been reconciled. The application may clear the local transaction marker; it cannot identify or delete the remote event.</span>
                            <span v-else>I confirmed with the M365 administrator that this exact Visit event is deleted from its previous mailbox/calendar or has otherwise been reconciled. This clears only the local event ID; it does not claim that the application deleted the remote event. Do not use this option for an event confirmed in the active Calendar.</span>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel for="visit-reconciliation-admin-upn" value="M365 administrator UPN" />
                                <TextInput id="visit-reconciliation-admin-upn" v-model="visitReconciliationForm.m365_admin_upn" type="email" autocomplete="off" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="visitReconciliationForm.errors.m365_admin_upn" />
                            </div>
                            <div>
                                <InputLabel for="visit-reconciliation-reference" value="Short ticket/run reference" />
                                <TextInput id="visit-reconciliation-reference" v-model="visitReconciliationForm.verification_reference" autocomplete="off" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="visitReconciliationForm.errors.verification_reference" />
                            </div>
                            <div>
                                <InputLabel for="visit-reconciliation-password" value="Current password" />
                                <TextInput id="visit-reconciliation-password" v-model="visitReconciliationForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="visitReconciliationForm.errors.password" />
                            </div>
                        </div>
                        <InputError :message="visitReconciliationForm.errors.confirm_remote_reconciliation" />
                        <PrimaryButton class="w-fit" :disabled="visitReconciliationForm.processing || !visitReconciliationForm.confirm_remote_reconciliation">
                            {{ visitReconciliationForm.processing ? 'Recording reconciliation…' : 'Clear this Visit event pointer' }}
                        </PrimaryButton>
                    </form>
                </div>
                <p v-else class="text-sm text-orange-950 dark:text-orange-100">No Visit event IDs from a different or unknown mailbox/calendar target were found.</p>

                <details v-if="runtimeReconciliation.activeVisitCount > 0" class="grid gap-3 rounded-md border border-gray-200 bg-white/70 p-4 text-sm dark:border-gray-700 dark:bg-gray-900/60">
                    <summary class="cursor-pointer font-semibold text-gray-900 dark:text-gray-100">
                        Active-target Visit event links ({{ runtimeReconciliation.activeVisitCount }})
                    </summary>
                    <p class="leading-6 text-gray-700 dark:text-gray-300">
                        Use this maintenance list only after an M365 administrator confirms the exact event was deleted or reconciled in the active Calendar. Recording that result clears the local Visit event link; it does not call Graph or delete the remote event.
                    </p>
                    <p class="text-xs text-gray-600 dark:text-gray-400">
                        Showing at most {{ runtimeReconciliation.displayLimit }}. Refresh after each batch to see remaining links.
                    </p>
                    <label v-for="visit in runtimeReconciliation.activeVisits" :key="`active-visit-retire-${visit.id}`" class="grid gap-1 rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-950">
                        <span class="flex items-start gap-2">
                            <input v-model="selectedActiveVisitId" type="radio" :value="visit.id" class="mt-1 border-gray-300 text-gray-700 focus:ring-blue-700" :disabled="!runtimeReconciliation.canRetireActiveVisits" />
                            <span><strong>Visit #{{ visit.id }}</strong> · {{ visit.jobNumber ?? 'Job' }} {{ visit.jobTitle ?? '' }} · {{ visit.scheduledAt ?? 'unscheduled' }}<span v-if="visit.archived"> · archived</span></span>
                        </span>
                        <span class="pl-6 text-xs text-gray-700 dark:text-gray-300">Active Calendar event ID: <code>{{ visit.eventId }}</code></span>
                    </label>
                    <form v-if="selectedActiveVisitId !== null && runtimeReconciliation.canRetireActiveVisits" class="grid gap-3 border-t border-gray-200 pt-3 dark:border-gray-700" @submit.prevent="retireActiveVisitEvent">
                        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                            <input v-model="activeVisitRetirementForm.confirm_remote_reconciliation" type="checkbox" required class="mt-1 rounded border-gray-300 text-orange-700 focus:ring-orange-700" />
                            <span>I confirmed with the M365 administrator that this exact event was deleted or otherwise reconciled in the active Calendar. The application may clear only its local event pointer; it will not call Graph or delete remote data.</span>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel for="active-visit-retirement-admin-upn" value="M365 administrator UPN" />
                                <TextInput id="active-visit-retirement-admin-upn" v-model="activeVisitRetirementForm.m365_admin_upn" type="email" autocomplete="off" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="activeVisitRetirementForm.errors.m365_admin_upn" />
                            </div>
                            <div>
                                <InputLabel for="active-visit-retirement-reference" value="Short ticket/run reference" />
                                <TextInput id="active-visit-retirement-reference" v-model="activeVisitRetirementForm.verification_reference" autocomplete="off" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="activeVisitRetirementForm.errors.verification_reference" />
                            </div>
                            <div>
                                <InputLabel for="active-visit-retirement-password" value="Current password" />
                                <TextInput id="active-visit-retirement-password" v-model="activeVisitRetirementForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="activeVisitRetirementForm.errors.password" />
                            </div>
                        </div>
                        <InputError :message="activeVisitRetirementForm.errors.confirm_remote_reconciliation" />
                        <PrimaryButton class="w-fit" :disabled="activeVisitRetirementForm.processing || !activeVisitRetirementForm.confirm_remote_reconciliation">
                            {{ activeVisitRetirementForm.processing ? 'Recording reconciliation…' : 'Clear reconciled active Visit link' }}
                        </PrimaryButton>
                    </form>
                </details>

                <div v-if="runtimeReconciliation.contacts.length > 0" class="grid gap-3 border-t border-orange-200 pt-4 dark:border-orange-800">
                    <h3 class="font-semibold text-orange-950 dark:text-orange-100">Outlook Contact projections</h3>
                    <label v-for="contact in runtimeReconciliation.contacts" :key="`contact-reconcile-${contact.id}`" class="grid gap-1 rounded-md border border-orange-200 bg-white/70 p-3 text-sm dark:border-orange-800 dark:bg-gray-900/60">
                        <span class="flex items-start gap-2">
                            <input v-model="selectedContactReconciliationId" type="radio" :value="contact.id" class="mt-1 border-gray-300 text-orange-700 focus:ring-orange-700" :disabled="!runtimeReconciliation.canReconcile || !!target.runtimeActivatedAt" />
                            <span><strong>{{ contact.name }}</strong> · {{ contact.customerName ?? 'Customer unavailable' }}<span v-if="contact.archived"> · archived</span></span>
                        </span>
                        <span class="pl-6 text-xs text-gray-700 dark:text-gray-300">Old mailbox: <code>{{ contact.sourceMailboxId ?? 'untracked' }}</code> · <template v-if="contact.unknownCreate">Previous create outcome unknown; no Outlook ID was stored.</template><template v-else>Outlook Contact ID: <code>{{ contact.outlookContactId }}</code></template></span>
                    </label>

                    <form v-if="selectedContactReconciliationId !== null && runtimeReconciliation.canReconcile && !target.runtimeActivatedAt" class="grid gap-3 border-t border-orange-200 pt-3 dark:border-orange-800" @submit.prevent="reconcileOutlookContact">
                        <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                            <input v-model="contactReconciliationForm.confirm_remote_reconciliation" type="checkbox" required class="mt-1 rounded border-gray-300 text-orange-700 focus:ring-orange-700" />
                            <span v-if="selectedContactReconciliation?.unknownCreate">I inspected the old mailbox's default Contacts folder with the M365 administrator and confirmed any possible duplicate from the unknown create has been reconciled. The application may clear its local unknown state; it cannot identify or delete the remote item.</span>
                            <span v-else>I confirmed with the M365 administrator that this exact Outlook Contact is deleted from the old mailbox or has been otherwise reconciled. The application may clear its local provider ID; this does not claim that the application deleted the remote Contact.</span>
                        </label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel for="contact-reconciliation-admin-upn" value="M365 administrator UPN" />
                                <TextInput id="contact-reconciliation-admin-upn" v-model="contactReconciliationForm.m365_admin_upn" type="email" autocomplete="off" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="contactReconciliationForm.errors.m365_admin_upn" />
                            </div>
                            <div>
                                <InputLabel for="contact-reconciliation-reference" value="Short ticket/run reference" />
                                <TextInput id="contact-reconciliation-reference" v-model="contactReconciliationForm.verification_reference" autocomplete="off" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="contactReconciliationForm.errors.verification_reference" />
                            </div>
                            <div>
                                <InputLabel for="contact-reconciliation-password" value="Current password" />
                                <TextInput id="contact-reconciliation-password" v-model="contactReconciliationForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="contactReconciliationForm.errors.password" />
                            </div>
                        </div>
                        <InputError :message="contactReconciliationForm.errors.confirm_remote_reconciliation" />
                        <PrimaryButton class="w-fit" :disabled="contactReconciliationForm.processing || !contactReconciliationForm.confirm_remote_reconciliation">
                            {{ contactReconciliationForm.processing ? 'Recording reconciliation…' : 'Clear this Outlook Contact pointer' }}
                        </PrimaryButton>
                    </form>
                </div>
                <p v-else class="text-sm text-orange-950 dark:text-orange-100">No Outlook Contact IDs owned by another or unknown mailbox were found.</p>
            </section>

            <section v-if="target" class="grid gap-4 rounded-md border p-5" :class="sectionPanels[activateState]">
                <div class="grid gap-2">
                    <h2 class="font-semibold text-gray-900 dark:text-gray-100">Activate the shared-mailbox runtime target</h2>
                    <p class="text-sm leading-6 text-gray-700 dark:text-gray-300">
                        Activation changes which mailbox runtime mail, Visit-calendar, and Outlook Contact consumers resolve. It does not enable those features or send requests; the separate deployment write flags remain authoritative. The selected target and user-mailbox control must have current readiness and M365-admin evidence.
                    </p>
                    <p v-if="target.runtimeActivatedAt" class="text-sm font-semibold text-green-900 dark:text-green-200" role="status">
                        This shared mailbox was activated for runtime resolution at {{ target.runtimeActivatedAt }}. Graph operations remain off until their feature flags are enabled.
                    </p>
                </div>
                <form v-if="canActivateGraphRuntime" class="grid gap-4 sm:max-w-xl" @submit.prevent="activateRuntimeTarget">
                    <label class="flex items-start gap-2 text-sm text-gray-800 dark:text-gray-200">
                        <input v-model="activateRuntimeTargetForm.confirm_activate_runtime" type="checkbox" required class="mt-1 rounded border-gray-300 text-violet-700 focus:ring-violet-700" />
                        <span>I reviewed the exact shared-mailbox target and user-mailbox denial control. Activate this mailbox for runtime resolution; do not enable any Graph write flags.</span>
                    </label>
                    <div>
                        <InputLabel for="activate-runtime-target-password" value="Current password" />
                        <TextInput id="activate-runtime-target-password" v-model="activateRuntimeTargetForm.password" type="password" autocomplete="current-password" required class="mt-1 block w-full" />
                        <InputError class="mt-2" :message="activateRuntimeTargetForm.errors.password" />
                    </div>
                    <InputError :message="activateRuntimeTargetForm.errors.confirm_activate_runtime" />
                    <PrimaryButton class="w-fit" :disabled="activateRuntimeTargetForm.processing || !activateRuntimeTargetForm.confirm_activate_runtime">
                        {{ activateRuntimeTargetForm.processing ? 'Activating target…' : 'Activate shared-mailbox target' }}
                    </PrimaryButton>
                </form>
                <p v-else-if="!target.runtimeActivatedAt" class="text-sm text-gray-700 dark:text-gray-300">
                    Activation becomes available after the complete onboarding receipt, Graph readiness finalization, and current M365-admin permission evidence are all present.
                </p>
            </section>
        </div>
    </AdminLayout>
</template>
