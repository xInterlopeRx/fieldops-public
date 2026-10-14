<script setup lang="ts">
import axios from "axios";
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { Link, router } from "@inertiajs/vue3";
import { XMarkIcon } from "@heroicons/vue/24/outline";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import AttachmentFileTypeIcon from "@/Components/AttachmentFileTypeIcon.vue";
import AttachmentOnlineActions from "@/Components/AttachmentOnlineActions.vue";
import JobWorkspace from "@/Components/Admin/JobWorkspace.vue";
import ProjectWorkspace from "@/Components/Admin/ProjectWorkspace.vue";
import StaffAssignmentDropdown from "@/Components/Admin/StaffAssignmentDropdown.vue";
import { useDialogFocusTrap } from "@/Composables/useDialogFocusTrap";
import { useDesktopInlineWorkspace } from "@/Composables/useDesktopInlineWorkspace";
import { usePressAndHold } from "@/Composables/usePressAndHold";
import { useWorkOrderStatus } from "@/Composables/useWorkOrderStatus";

interface SiteDraft {
    id: number | null;
    name: string;
    address: string;
    writePending?: boolean;
}

type ContactMethodTypeValue = "email" | "phone" | "teams";
type ContactMethodLabelValue = "office" | "mobile" | "dispatch" | "after_hours" | "operations" | "personal";

interface ContactMethodData {
    id: number;
    methodType: ContactMethodTypeValue;
    label: ContactMethodLabelValue;
    value: string;
    isPrimary: boolean;
}

interface ContactMethodDraft extends Omit<ContactMethodData, "id"> {
    clientKey: string;
    id: number | null;
}

interface ContactDraft {
    id: number | null;
    siteId: number | null;
    siteName?: string | null;
    type: string;
    source: string;
    firstName: string;
    middleName: string;
    lastName: string;
    title: string;
    suffix: string;
    email: string;
    phone: string;
    alternatePhone: string;
    mobilePhone: string;
    fax: string;
    website: string;
    methods: ContactMethodDraft[];
    remove?: boolean;
}

interface ContactWorkspaceData extends Omit<ContactDraft, "methods"> {
    methods: ContactMethodData[];
}

interface EmailDraft {
    id: number | null;
    email: string;
    label: string;
    remove?: boolean;
}

interface CustomerData {
    id: number;
    clientId: string | null;
    name: string;
    companyName: string | null;
    firstName: string | null;
    middleName: string | null;
    lastName: string | null;
    title: string | null;
    suffix: string | null;
    email: string | null;
    phone: string | null;
    alternatePhone: string | null;
    mobilePhone: string | null;
    fax: string | null;
    website: string | null;
    notes: string | null;
    quickBooksSyncStatus: string | null;
    quickBooksSyncError: string | null;
    quickBooksLinked: boolean;
    quickBooksSyncEnabled: boolean;
    quickBooksWritePending: boolean;
    quickBooksLastSyncedAt: string | null;
}

interface WorkspaceData {
    customer: CustomerData;
    billingAddress: Record<string, string | null>;
    sites: SiteDraft[];
    contacts: ContactWorkspaceData[];
    emailAddresses: EmailDraft[];
    attachments: Array<{
        id: number;
        name: string;
        mimeType: string;
        size: number;
        uploadedBy: string | null;
        createdAt: string;
        url: string;
        previewUrl?: string | null;
        thumbnailUrl?: string | null;
        onlineUrl?: string | null;
        availabilityUrl?: string | null;
        officeApp?: "word" | "excel" | "powerpoint" | null;
    }>;
    projects: Array<{ id: number; number: string | null; name: string; status: string; jobsCount: number }>;
    jobs: Array<{ id: number; number: string | null; title: string; status: string; site: string | null; project: string | null; visitsCount: number }>;
    upcomingJobs: Array<{ id: number; number: string | null; title: string; site: string | null; project: string | null; scheduledAt: string | null }>;
    assignedUserIds: number[];
    activity: Array<{ id: number; summary: string; actor: string; createdAt: string }>;
}

interface StaffOption {
    id: number;
    name: string;
    email: string;
    roles: string[];
}

const props = withDefaults(defineProps<{
    customerId: number;
    canArchive: boolean;
    inlineOnDesktop?: boolean;
    editRequestId?: number;
}>(), { inlineOnDesktop: false, editRequestId: 0 });

const emit = defineEmits<{
    close: [];
    saved: [];
    archived: [];
    "editing-change": [editing: boolean];
}>();
const { badgeClass: jobStatusBadgeClass, label: jobStatusLabel } = useWorkOrderStatus();

const tabs = [
    { id: "contact", label: "Contact info" },
    { id: "projects", label: "Projects" },
    { id: "jobs", label: "Jobs" },
    { id: "activity", label: "Activity" },
] as const;

const MAX_CONTACT_METHODS = 100;
const isDesktopInline = useDesktopInlineWorkspace(props.inlineOnDesktop);
const contactMethodTypeLabels: Record<ContactMethodTypeValue, string> = {
    email: "Email",
    phone: "Phone",
    teams: "Teams",
};
const contactMethodLabelOptions: Array<{ value: ContactMethodLabelValue; label: string }> = [
    { value: "office", label: "Office" },
    { value: "mobile", label: "Mobile" },
    { value: "dispatch", label: "Dispatch" },
    { value: "after_hours", label: "After Hours" },
    { value: "operations", label: "Operations" },
    { value: "personal", label: "Personal" },
];
const contactRoleOptions = [
    { value: "primary", label: "Primary contact" },
    { value: "other", label: "Additional contact" },
    { value: "gate_contact", label: "Gate Contact" },
    { value: "supervisor", label: "Supervisor" },
    { value: "operations_contact", label: "Operations Contact" },
    { value: "safety_contact", label: "Safety Contact" },
];

const activeTab = ref<(typeof tabs)[number]["id"]>("contact");
const tabSearch = ref("");
const sortBy = ref("job_number_asc");
const selectedSiteIndex = ref<number | null>(null);
const loading = ref(true);
const isEditing = ref(false);
const saving = ref(false);
const uploading = ref(false);
const archiving = ref(false);
const resolvingQuickBooks = ref(false);
const refreshingQuickBooksStatus = ref(false);
const awaitingQuickBooksResolution = ref(false);
const errorMessage = ref("");
const successMessage = ref("");
const warningMessage = ref("");
const staff = ref<StaffOption[]>([]);
const panelElement = ref<HTMLElement | null>(null);
const handleDialogKeydown = useDialogFocusTrap(
    panelElement,
    () => !isDesktopInline.value,
);
const workspace = ref<WorkspaceData | null>(null);
const baseline = ref("");
const selectedProjectId = ref<number | null>(null);
const selectedJobId = ref<number | null>(null);
const selectedProjectEditRequestId = ref(0);
const selectedJobEditRequestId = ref(0);
const selectedProjectEditTargetId = ref<number | null>(null);
const selectedJobEditTargetId = ref<number | null>(null);
const lastEditRequestId = ref(0);
let quickBooksStatusPoll: ReturnType<typeof setInterval> | null = null;
let nextContactMethodKey = 0;

const draft = reactive({
    name: "",
    companyName: "",
    firstName: "",
    middleName: "",
    lastName: "",
    title: "",
    suffix: "",
    email: "",
    phone: "",
    alternatePhone: "",
    mobilePhone: "",
    fax: "",
    website: "",
    notes: "",
    billingAddress: {
        line1: "",
        line2: "",
        line3: "",
        line4: "",
        line5: "",
        city: "",
        country_subdivision_code: "",
        postal_code: "",
        country: "",
    },
    sites: [] as SiteDraft[],
    contacts: [] as ContactDraft[],
    emailAddresses: [] as EmailDraft[],
    assignedUserIds: [] as number[],
});

const editableSnapshot = (): string =>
    JSON.stringify({
        name: draft.name,
        companyName: draft.companyName,
        firstName: draft.firstName,
        middleName: draft.middleName,
        lastName: draft.lastName,
        title: draft.title,
        suffix: draft.suffix,
        email: draft.email,
        phone: draft.phone,
        alternatePhone: draft.alternatePhone,
        mobilePhone: draft.mobilePhone,
        fax: draft.fax,
        website: draft.website,
        notes: draft.notes,
        billingAddress: draft.billingAddress,
        sites: draft.sites,
        contacts: draft.contacts,
        emailAddresses: draft.emailAddresses,
        assignedUserIds: [...draft.assignedUserIds].sort((left, right) => left - right),
    });

const hasChanges = computed(() => baseline.value !== "" && editableSnapshot() !== baseline.value);
const isQuickBooksBusy = computed(() => {
    const customer = workspace.value?.customer;

    return customer?.quickBooksWritePending === true || (
        customer?.quickBooksLinked === true && ["pending", "failed"].includes(customer.quickBooksSyncStatus ?? "")
    );
});
const visibleProjects = computed(() => {
    const query = tabSearch.value.trim().toLocaleLowerCase();

    return (workspace.value?.projects ?? []).filter((project) =>
        !query || `${project.number ?? ""} ${project.name} ${project.status}`.toLocaleLowerCase().includes(query),
    );
});
const visibleJobs = computed(() => {
    const query = tabSearch.value.trim().toLocaleLowerCase();
    const jobs = [...(workspace.value?.jobs ?? [])].filter((job) =>
        !query || `${job.number ?? ""} ${job.title} ${job.site ?? ""} ${job.project ?? ""} ${job.status}`.toLocaleLowerCase().includes(query),
    );

    const compareText = (left: string | null, right: string | null): number =>
        (left ?? "").localeCompare(right ?? "", undefined, { numeric: true, sensitivity: "base" });

    return jobs.sort((left, right) => {
        if (sortBy.value === "job_number_desc") return compareText(right.number, left.number);
        if (sortBy.value === "title") return compareText(left.title, right.title);
        if (sortBy.value === "status") return compareText(left.status, right.status);

        return compareText(left.number, right.number);
    });
});
const visibleContacts = computed(() =>
    draft.contacts.map((contact, index) => ({ contact, index })).filter(({ contact }) => !contact.remove),
);
const visibleEmailAddresses = computed(() =>
    draft.emailAddresses.map((email, index) => ({ email, index })).filter(({ email }) => !email.remove),
);
const selectedSite = computed(() =>
    selectedSiteIndex.value === null ? null : draft.sites[selectedSiteIndex.value] ?? null,
);
const upcomingSlots = computed(() =>
    Array.from({ length: 3 }, (_, index) => workspace.value?.upcomingJobs[index] ?? null),
);

const nullable = (value: string | null | undefined): string => value ?? "";

watch(isEditing, (editing) => emit("editing-change", editing), { flush: "sync", immediate: true });

const setDraft = (data: WorkspaceData): void => {
    workspace.value = data;
    isEditing.value = false;
    Object.assign(draft, {
        name: data.customer.name,
        companyName: nullable(data.customer.companyName),
        firstName: nullable(data.customer.firstName),
        middleName: nullable(data.customer.middleName),
        lastName: nullable(data.customer.lastName),
        title: nullable(data.customer.title),
        suffix: nullable(data.customer.suffix),
        email: nullable(data.customer.email),
        phone: nullable(data.customer.phone),
        alternatePhone: nullable(data.customer.alternatePhone),
        mobilePhone: nullable(data.customer.mobilePhone),
        fax: nullable(data.customer.fax),
        website: nullable(data.customer.website),
        notes: nullable(data.customer.notes),
        billingAddress: {
            line1: nullable(data.billingAddress.line1),
            line2: nullable(data.billingAddress.line2),
            line3: nullable(data.billingAddress.line3),
            line4: nullable(data.billingAddress.line4),
            line5: nullable(data.billingAddress.line5),
            city: nullable(data.billingAddress.city),
            country_subdivision_code: nullable(data.billingAddress.country_subdivision_code),
            postal_code: nullable(data.billingAddress.postal_code),
            country: nullable(data.billingAddress.country),
        },
        sites: data.sites.map((site) => ({ ...site })),
        contacts: data.contacts.map((contact) => ({
            ...contact,
            firstName: nullable(contact.firstName),
            middleName: nullable(contact.middleName),
            lastName: nullable(contact.lastName),
            title: nullable(contact.title),
            suffix: nullable(contact.suffix),
            email: nullable(contact.email),
            phone: nullable(contact.phone),
            alternatePhone: nullable(contact.alternatePhone),
            mobilePhone: nullable(contact.mobilePhone),
            fax: nullable(contact.fax),
            website: nullable(contact.website),
            methods: contact.methods.map((method) => ({
                ...method,
                clientKey: `saved-contact-method-${method.id}`,
            })),
        })),
        emailAddresses: data.emailAddresses.map((email) => ({
            ...email,
            label: nullable(email.label),
        })),
        assignedUserIds: [...data.assignedUserIds],
    });
    selectedSiteIndex.value = data.sites.length > 0 ? 0 : null;
    baseline.value = editableSnapshot();
};

const beginEditing = (): void => {
    isEditing.value = true;
    errorMessage.value = "";
    successMessage.value = "";
};

const applyEditRequest = (): void => {
    if (!workspace.value || props.editRequestId <= lastEditRequestId.value) return;

    lastEditRequestId.value = props.editRequestId;

    if (!isQuickBooksBusy.value) beginEditing();
};

watch(() => props.editRequestId, applyEditRequest);

const openProject = (projectId: number): void => {
    selectedProjectEditTargetId.value = null;
    selectedProjectId.value = projectId;
};

const closeProject = (): void => {
    selectedProjectId.value = null;
    selectedProjectEditRequestId.value = 0;
    selectedProjectEditTargetId.value = null;
};

const editProject = (projectId: number): void => {
    selectedProjectId.value = projectId;
    selectedProjectEditTargetId.value = projectId;
    selectedProjectEditRequestId.value += 1;
};

const projectPress = usePressAndHold(openProject, editProject);

const openJob = (jobId: number): void => {
    selectedJobEditTargetId.value = null;
    selectedJobId.value = jobId;
};

const closeJob = (): void => {
    selectedJobId.value = null;
    selectedJobEditRequestId.value = 0;
    selectedJobEditTargetId.value = null;
};

const editJob = (jobId: number): void => {
    selectedJobId.value = jobId;
    selectedJobEditTargetId.value = jobId;
    selectedJobEditRequestId.value += 1;
};

const jobPress = usePressAndHold(openJob, editJob);

const cancelEditing = (): void => {
    if (!workspace.value || saving.value) return;

    setDraft(workspace.value);
    errorMessage.value = "";
    successMessage.value = "";
    warningMessage.value = "";
};

const requestClose = (): void => {
    if (isEditing.value || hasChanges.value || saving.value || archiving.value) return;

    emit("close");
};

const loadWorkspace = async (): Promise<void> => {
    loading.value = true;
    errorMessage.value = "";

    try {
        const response = await axios.get(route("admin.customers.workspace.show", props.customerId));
        setDraft(response.data.data as WorkspaceData);
        staff.value = response.data.staff as StaffOption[];
        applyEditRequest();
    } catch {
        errorMessage.value = "Customer details could not be loaded. Try again.";
    } finally {
        loading.value = false;
    }
};

const refreshWorkspaceData = async (): Promise<void> => {
    try {
        const response = await axios.get(route("admin.customers.workspace.show", props.customerId));
        workspace.value = response.data.data as WorkspaceData;
        staff.value = response.data.staff as StaffOption[];
        emit("saved");
    } catch {
        errorMessage.value = "Customer details could not be refreshed.";
    }
};

const submit = async (): Promise<void> => {
    if (!workspace.value || !hasChanges.value) return;

    saving.value = true;
    errorMessage.value = "";
    successMessage.value = "";
    warningMessage.value = "";

    try {
        const response = await axios.put(route("admin.customers.workspace.update", props.customerId), {
            name: draft.name,
            company_name: draft.companyName || null,
            first_name: draft.firstName || null,
            middle_name: draft.middleName || null,
            last_name: draft.lastName || null,
            title: draft.title || null,
            suffix: draft.suffix || null,
            email: draft.email || null,
            phone: draft.phone || null,
            alternate_phone: draft.alternatePhone || null,
            mobile_phone: draft.mobilePhone || null,
            fax: draft.fax || null,
            website: draft.website || null,
            notes: draft.notes || null,
            billing_address: draft.billingAddress,
            sites: draft.sites.map(({ writePending: _writePending, ...site }) => site),
            contacts: draft.contacts.map((contact) => ({
                id: contact.id,
                site_id: contact.siteId,
                contact_type: contact.type,
                first_name: contact.firstName || null,
                middle_name: contact.middleName || null,
                last_name: contact.lastName || null,
                title: contact.title || null,
                suffix: contact.suffix || null,
                email: contact.email || null,
                phone: contact.phone || null,
                alternate_phone: contact.alternatePhone || null,
                mobile_phone: contact.mobilePhone || null,
                fax: contact.fax || null,
                website: contact.website || null,
                methods: contact.methods.map((method) => ({
                    id: method.id,
                    method_type: method.methodType,
                    label: method.label,
                    value: method.value,
                    is_primary: method.isPrimary,
                })),
                remove: contact.remove ?? false,
            })),
            email_addresses: draft.emailAddresses,
            assigned_user_ids: draft.assignedUserIds,
        });
        successMessage.value = response.data.message ?? "Customer profile saved.";
        warningMessage.value = (response.data.quickBooksWarnings as string[] | undefined)?.join(" ") ?? "";
        await loadWorkspace();
        emit("saved");
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? Object.values(error.response?.data?.errors ?? {}).flat()[0] ?? "Customer changes could not be saved."
            : "Customer changes could not be saved.";
    } finally {
        saving.value = false;
    }
};

const addSite = (): void => {
    draft.sites.push({ id: null, name: "", address: "" });
    selectedSiteIndex.value = draft.sites.length - 1;
};

const selectSite = (event: Event): void => {
    const value = Number((event.target as HTMLSelectElement).value);
    selectedSiteIndex.value = value < 0 ? null : value;
};

const addContact = (): void => {
    draft.contacts.push({
        id: null,
        siteId: null,
        type: "other",
        source: "manual",
        firstName: "",
        middleName: "",
        lastName: "",
        title: "",
        suffix: "",
        email: "",
        phone: "",
        alternatePhone: "",
        mobilePhone: "",
        fax: "",
        website: "",
        methods: [],
    });
};

const addContactMethod = (contactIndex: number, methodType: "email" | "phone"): void => {
    const contact = draft.contacts[contactIndex];
    if (!contact || contact.methods.length >= MAX_CONTACT_METHODS) return;

    contact.methods.push({
        clientKey: `new-contact-method-${nextContactMethodKey++}`,
        id: null,
        methodType,
        label: methodType === "email" ? "office" : "mobile",
        value: "",
        isPrimary: !contact.methods.some((method) => method.methodType === methodType && method.isPrimary),
    });
};

const setPrimaryMethod = (contactIndex: number, clientKey: string): void => {
    const contact = draft.contacts[contactIndex];
    const selected = contact?.methods.find((method) => method.clientKey === clientKey);
    if (!contact || !selected) return;

    for (const method of contact.methods) {
        if (method.methodType === selected.methodType) {
            method.isPrimary = method.clientKey === selected.clientKey;
        }
    }
};

const removeContactMethod = (contactIndex: number, clientKey: string): void => {
    const contact = draft.contacts[contactIndex];
    const methodIndex = contact?.methods.findIndex((method) => method.clientKey === clientKey) ?? -1;
    if (!contact || methodIndex < 0) return;

    const [method] = contact.methods.splice(methodIndex, 1);
    if (!method) return;

    if (method.id !== null && !window.confirm(`Remove this ${contactMethodTypeLabels[method.methodType].toLocaleLowerCase()} method?`)) {
        contact.methods.splice(methodIndex, 0, method);
        return;
    }

    if (method.isPrimary) {
        const replacement = contact.methods.find((candidate) => candidate.methodType === method.methodType);
        if (replacement) replacement.isPrimary = true;
    }
};

const removeContact = (index: number): void => {
    const contact = draft.contacts[index];
    if (!contact) return;
    const prompt = contact.source === "quickbooks"
        ? "Clear this QuickBooks contact’s name and communication fields? The changes will be queued to QuickBooks."
        : "Remove this contact from the customer profile? It will be archived and can be restored.";
    if (contact.id !== null && !window.confirm(prompt)) return;
    if (contact.id === null) draft.contacts.splice(index, 1);
    else contact.remove = true;
};

const addEmailAddress = (): void => {
    draft.emailAddresses.push({ id: null, email: "", label: "" });
};

const removeEmailAddress = (index: number): void => {
    const email = draft.emailAddresses[index];
    if (!email) return;
    if (email.id !== null && !window.confirm("Remove this email address from the customer profile? It will be archived and can be restored.")) return;
    if (email.id === null) draft.emailAddresses.splice(index, 1);
    else email.remove = true;
};

const uploadAttachment = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file || !workspace.value) return;

    uploading.value = true;
    errorMessage.value = "";
    const formData = new FormData();
    formData.append("file", file);

    try {
        const response = await axios.post(route("admin.customers.attachments.store", props.customerId), formData);
        workspace.value.attachments.push(response.data.data);
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "Attachment could not be uploaded."
            : "Attachment could not be uploaded.";
    } finally {
        uploading.value = false;
        input.value = "";
    }
};

const archiveCustomer = async (): Promise<void> => {
    if (!props.canArchive || archiving.value || !workspace.value) return;
    if (!window.confirm(`Archive ${workspace.value.customer.name}? Related records will remain recoverable.`)) return;

    archiving.value = true;
    errorMessage.value = "";

    try {
        await axios.post(route("admin.archives.archive", { type: "customers", record: props.customerId }), {
            reason: "Archived from customer profile.",
        });
        emit("archived");
        emit("close");
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "Customer could not be archived."
            : "Customer could not be archived.";
    } finally {
        archiving.value = false;
    }
};

const quickBooksErrorMessage = (code: string | null): string => {
    if (code === "quickbooks_conflict") return "QuickBooks changed since this profile was last synchronized.";
    if (code === "reauthorization_required") return "The QuickBooks connection needs to be reauthorized.";
    if (code === "quickbooks_record_not_found") return "The linked record no longer exists in QuickBooks.";

    return "The latest QuickBooks update could not be synchronized.";
};

const resolveQuickBooksConflict = async (): Promise<void> => {
    if (!workspace.value?.customer.quickBooksLinked || resolvingQuickBooks.value) return;
    const unsavedWarning = hasChanges.value ? " Unsaved edits in this profile will also be discarded." : "";
    if (!window.confirm(`QuickBooks is the source of truth. Refreshing will replace conflicting synchronized customer and Site fields from QuickBooks.${unsavedWarning} FieldOps-only contacts and additional email addresses will remain.`)) return;

    resolvingQuickBooks.value = true;
    errorMessage.value = "";
    successMessage.value = "";
    try {
        const response = await axios.post(route("admin.customers.quickbooks.resolve", props.customerId));
        successMessage.value = response.data.message ?? "QuickBooks refresh queued.";
        awaitingQuickBooksResolution.value = true;
        await loadWorkspace();
    } catch (error) {
        errorMessage.value = axios.isAxiosError(error)
            ? error.response?.data?.message ?? "QuickBooks conflict could not be resolved."
            : "QuickBooks conflict could not be resolved.";
    } finally {
        resolvingQuickBooks.value = false;
    }
};

const refreshQuickBooksStatus = async (): Promise<void> => {
    if (!workspace.value?.customer.quickBooksLinked || refreshingQuickBooksStatus.value) return;
    refreshingQuickBooksStatus.value = true;
    try {
        const response = await axios.get(route("admin.customers.workspace.show", props.customerId));
        const refreshedWorkspace = response.data.data as WorkspaceData;
        const customer = refreshedWorkspace.customer;
        Object.assign(workspace.value.customer, {
            quickBooksSyncStatus: customer.quickBooksSyncStatus,
            quickBooksSyncError: customer.quickBooksSyncError,
            quickBooksSyncEnabled: customer.quickBooksSyncEnabled,
            quickBooksWritePending: customer.quickBooksWritePending,
            quickBooksLastSyncedAt: customer.quickBooksLastSyncedAt,
        });

        if (awaitingQuickBooksResolution.value && customer.quickBooksSyncStatus === "synced") {
            setDraft(refreshedWorkspace);
            awaitingQuickBooksResolution.value = false;
        }
    } catch {
        errorMessage.value = "QuickBooks sync status could not be refreshed.";
    } finally {
        refreshingQuickBooksStatus.value = false;
    }
};

const removeMissingAttachment = (attachmentId: number): void => {
    if (workspace.value) {
        workspace.value.attachments = workspace.value.attachments.filter((attachment) => attachment.id !== attachmentId);
    }

    successMessage.value = "This file was missing from SharePoint; its stale FieldOps attachment link was removed.";
};

watch(() => [workspace.value?.customer.quickBooksSyncStatus, workspace.value?.customer.quickBooksSyncEnabled] as const, ([status, syncEnabled]) => {
    if (quickBooksStatusPoll !== null) {
        clearInterval(quickBooksStatusPoll);
        quickBooksStatusPoll = null;
    }

    if (status === "pending" && syncEnabled) {
        quickBooksStatusPoll = setInterval(() => void refreshQuickBooksStatus(), 5000);
    }
});

const formatDate = (value: string | null): string =>
    value ? new Date(value).toLocaleString() : "Not scheduled";

const formatBytes = (value: number): string => {
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(0)} KB`;

    return `${(value / 1024 / 1024).toFixed(1)} MB`;
};

onMounted(() => {
    panelElement.value?.focus();
    void loadWorkspace();
});

onBeforeUnmount(() => {
    if (quickBooksStatusPoll !== null) clearInterval(quickBooksStatusPoll);
});
</script>

<template>
    <div
        class="fieldops-workspace-backdrop"
        :class="{ 'fieldops-workspace-backdrop--inline-desktop': isDesktopInline }"
        tabindex="-1"
        @click.self="requestClose"
        @keydown.esc.stop="requestClose"
    >
        <section
            class="fieldops-workspace-panel"
            :class="{ 'fieldops-workspace-panel--inline-desktop': isDesktopInline }"
            ref="panelElement"
            tabindex="-1"
            :role="isDesktopInline ? 'region' : 'dialog'"
            :aria-modal="isDesktopInline ? undefined : 'true'"
            :aria-label="workspace ? `${workspace.customer.name} details` : 'Customer details'"
            @keydown="handleDialogKeydown"
        >
            <header class="fieldops-workspace-fixed-header">
                <h2 class="fieldops-workspace-title">Company Details</h2>
                <button v-if="!isEditing" type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Close customer details" title="Close details" @click="requestClose">
                    <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                </button>
            </header>

            <nav class="fieldops-workspace-tabs" aria-label="Customer details sections">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    class="fieldops-workspace-tab"
                    :class="{ 'fieldops-workspace-tab--active': activeTab === tab.id }"
                    :aria-current="activeTab === tab.id ? 'page' : undefined"
                    @click="activeTab = tab.id; tabSearch = ''"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <div v-if="errorMessage" role="alert" class="mx-4 mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 sm:mx-6">
                {{ errorMessage }}
            </div>
            <p v-if="successMessage" role="status" class="mx-4 mt-3 rounded-lg bg-green-50 px-3 py-2 text-sm text-green-800 sm:mx-6">
                {{ successMessage }}
            </p>
            <p v-if="warningMessage" role="alert" class="mx-4 mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-950 sm:mx-6">
                {{ warningMessage }}
            </p>

            <div v-if="loading" class="flex-1 px-6 py-12 text-center text-sm text-gray-600">Loading customer details…</div>

            <form v-else-if="workspace" class="flex min-h-0 flex-1 flex-col" @submit.prevent="submit">
                <div class="fieldops-workspace-scroll">
                    <section v-show="activeTab === 'contact'" class="space-y-5">
                        <p v-if="workspace.customer.clientId" class="rounded-md bg-gray-50 px-3 py-2 text-xs font-medium text-gray-600">{{ workspace.customer.clientId }}</p>
                        <fieldset class="grid gap-3 sm:grid-cols-2" :disabled="isQuickBooksBusy || (!hasChanges && !isEditing)">
                            <label class="fieldops-workspace-field sm:col-span-2">Company / customer name<input v-model="draft.name" required maxlength="255" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Company name<input v-model="draft.companyName" maxlength="255" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Primary contact first name<input v-model="draft.firstName" maxlength="100" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Middle name<input v-model="draft.middleName" maxlength="100" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Last name<input v-model="draft.lastName" maxlength="100" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Title<input v-model="draft.title" maxlength="100" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Suffix<input v-model="draft.suffix" maxlength="50" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Primary email<input v-model="draft.email" type="email" maxlength="255" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Phone<input v-model="draft.phone" maxlength="50" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Alternate phone<input v-model="draft.alternatePhone" maxlength="50" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Mobile phone<input v-model="draft.mobilePhone" maxlength="50" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field">Fax<input v-model="draft.fax" maxlength="50" :readonly="!isEditing" /></label>
                            <label class="fieldops-workspace-field sm:col-span-2">Website<input v-model="draft.website" type="url" maxlength="255" :readonly="!isEditing" /></label>
                        </fieldset>

                        <section
                            v-if="workspace.customer.quickBooksWritePending || workspace.customer.quickBooksSyncStatus === 'failed' || (workspace.customer.quickBooksLinked && (workspace.customer.quickBooksSyncStatus === 'pending' || !workspace.customer.quickBooksSyncEnabled))"
                            class="space-y-2 rounded-xl border border-amber-300 bg-amber-50 p-3"
                            :role="workspace.customer.quickBooksSyncStatus === 'failed' ? 'alert' : 'status'"
                        >
                            <h3 class="text-sm font-semibold text-amber-950">QuickBooks sync {{ workspace.customer.quickBooksSyncEnabled ? workspace.customer.quickBooksSyncStatus : 'paused' }}</h3>
                            <p v-if="!workspace.customer.quickBooksSyncEnabled" class="text-sm text-amber-950">
                                QuickBooks synchronization is paused. Pending changes are held until sync resumes.
                                <Link :href="route('admin.integrations.quickbooks.show')" class="font-semibold underline">Manage QuickBooks sync</Link>
                            </p>
                            <p v-if="workspace.customer.quickBooksLastSyncedAt" class="text-xs text-amber-900">Last synced {{ formatDate(workspace.customer.quickBooksLastSyncedAt) }}</p>
                            <p v-if="workspace.customer.quickBooksSyncStatus === 'failed' && workspace.customer.quickBooksLinked" class="text-sm text-amber-950">
                                {{ quickBooksErrorMessage(workspace.customer.quickBooksSyncError) }} QuickBooks is authoritative for synchronized fields.
                            </p>
                            <p v-else-if="workspace.customer.quickBooksSyncStatus === 'failed'" class="text-sm text-amber-950">
                                The QuickBooks Customer create failed. Fix the profile or connection, then retry it from Sync Logs.
                            </p>
                            <p v-else-if="workspace.customer.quickBooksSyncEnabled" class="text-sm text-amber-950">A QuickBooks update is running. Wait for it to finish before editing this profile again.</p>
                            <button
                                v-if="workspace.customer.quickBooksLinked"
                                type="button"
                                class="fieldops-secondary-action min-h-10 border-amber-500 text-amber-950"
                                :disabled="refreshingQuickBooksStatus || resolvingQuickBooks"
                                @click="refreshQuickBooksStatus"
                            >
                                {{ refreshingQuickBooksStatus ? "Checking…" : "Refresh sync status" }}
                            </button>
                            <button
                                v-if="workspace.customer.quickBooksLinked && workspace.customer.quickBooksSyncStatus === 'failed' && workspace.customer.quickBooksSyncEnabled"
                                type="button"
                                class="fieldops-secondary-action min-h-10 border-amber-500 text-amber-950"
                                :disabled="resolvingQuickBooks"
                                @click="resolveQuickBooksConflict"
                            >
                                {{ resolvingQuickBooks ? "Queuing refresh…" : "Resolve using QuickBooks" }}
                            </button>
                            <Link
                                v-if="!workspace.customer.quickBooksLinked && workspace.customer.quickBooksSyncStatus === 'failed'"
                                :href="route('admin.sync-logs')"
                                class="fieldops-secondary-action inline-flex min-h-10 items-center justify-center"
                            >
                                Open Sync Logs
                            </Link>
                            <p v-if="workspace.customer.quickBooksSyncStatus === 'failed'" class="text-xs text-amber-900">
                                This discards pending FieldOps write metadata, refreshes the parent and linked Sites, and applies the latest values reported by QuickBooks. FieldOps-only contacts and extra email addresses are retained.
                            </p>
                        </section>

                        <fieldset class="space-y-3" :disabled="!isEditing || isQuickBooksBusy">
                            <legend class="text-sm font-semibold text-gray-900">Primary billing address</legend>
                            <label class="fieldops-workspace-field">Street address<input v-model="draft.billingAddress.line1" maxlength="500" /></label>
                            <label class="fieldops-workspace-field">Address line 2<input v-model="draft.billingAddress.line2" maxlength="500" /></label>
                            <div class="grid gap-3 sm:grid-cols-3">
                                <label class="fieldops-workspace-field">City<input v-model="draft.billingAddress.city" maxlength="255" /></label>
                                <label class="fieldops-workspace-field">State / region<input v-model="draft.billingAddress.country_subdivision_code" maxlength="20" /></label>
                                <label class="fieldops-workspace-field">Postal code<input v-model="draft.billingAddress.postal_code" maxlength="40" /></label>
                            </div>
                            <label class="fieldops-workspace-field">Country<input v-model="draft.billingAddress.country" maxlength="255" /></label>
                        </fieldset>

                        <section class="space-y-3">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-gray-900">Sites</h3>
                                <button v-if="isEditing && !isQuickBooksBusy" type="button" class="fieldops-secondary-action fieldops-add-action min-h-9 px-3 py-1" @click="addSite">Add site</button>
                            </div>
                            <label v-if="draft.sites.length > 0" class="fieldops-workspace-field">Select a site
                                    <select :value="selectedSiteIndex ?? -1" :disabled="isQuickBooksBusy" @change="selectSite">
                                    <option :value="-1">Choose a site</option>
                                    <option v-for="(site, index) in draft.sites" :key="site.id ?? `new-site-${index}`" :value="index">{{ site.name || `New site ${index + 1}` }}</option>
                                </select>
                            </label>
                            <article v-if="selectedSite" class="grid gap-3 rounded-lg border border-gray-200 p-3">
                                <label class="fieldops-workspace-field">Site name<input v-model="selectedSite.name" required maxlength="255" :readonly="!isEditing || isQuickBooksBusy" /></label>
                                <label class="fieldops-workspace-field">Address<input v-model="selectedSite.address" maxlength="1000" :readonly="!isEditing || isQuickBooksBusy" /></label>
                                <p v-if="selectedSite.writePending" class="text-xs text-amber-800">QuickBooks site update is pending.</p>
                            </article>
                            <p v-if="draft.sites.length === 0" class="text-sm text-gray-600">No sites are recorded.</p>
                        </section>

                        <fieldset class="space-y-3" :disabled="!isEditing || isQuickBooksBusy">
                            <div class="flex items-center justify-between gap-3">
                                <legend class="text-sm font-semibold text-gray-900">Contacts</legend>
                                <button v-if="isEditing && !isQuickBooksBusy" type="button" class="fieldops-secondary-action fieldops-add-action min-h-9 px-3 py-1" @click="addContact">Add contact</button>
                            </div>
                            <article v-for="row in visibleContacts" :key="row.contact.id ?? `new-contact-${row.index}`" class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-2">
                                <label class="fieldops-workspace-field">First name<input v-model="row.contact.firstName" maxlength="100" /></label>
                                <label class="fieldops-workspace-field">Middle name<input v-model="row.contact.middleName" maxlength="100" /></label>
                                <label class="fieldops-workspace-field">Last name<input v-model="row.contact.lastName" maxlength="100" /></label>
                                <label class="fieldops-workspace-field">Title<input v-model="row.contact.title" maxlength="100" /></label>
                                <label class="fieldops-workspace-field">Suffix<input v-model="row.contact.suffix" maxlength="50" /></label>
                                <label class="fieldops-workspace-field">Contact role
                                    <select v-model="row.contact.type" :disabled="row.contact.source === 'quickbooks'">
                                        <option v-for="option in contactRoleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                        <option v-if="!contactRoleOptions.some((option) => option.value === row.contact.type)" :value="row.contact.type">{{ row.contact.type }}</option>
                                    </select>
                                </label>
                                <label class="fieldops-workspace-field">Email<input v-model="row.contact.email" type="email" maxlength="255" /></label>
                                <label class="fieldops-workspace-field">Phone<input v-model="row.contact.phone" maxlength="50" /></label>
                                <label class="fieldops-workspace-field">Alternate phone<input v-model="row.contact.alternatePhone" maxlength="50" /></label>
                                <label class="fieldops-workspace-field">Mobile phone<input v-model="row.contact.mobilePhone" maxlength="50" /></label>
                                <label class="fieldops-workspace-field">Fax<input v-model="row.contact.fax" maxlength="50" /></label>
                                <label class="fieldops-workspace-field">Website<input v-model="row.contact.website" type="url" maxlength="255" /></label>
                                <label class="fieldops-workspace-field">Site (optional)
                                    <select v-model="row.contact.siteId" :disabled="row.contact.source === 'quickbooks'">
                                        <option :value="null">Customer-wide</option>
                                        <option v-for="site in draft.sites.filter((item) => item.id !== null)" :key="site.id ?? site.name" :value="site.id">{{ site.name }}</option>
                                    </select>
                                </label>
                                <p class="text-xs text-gray-600 sm:col-span-2">Leave Site as Customer-wide, or choose a Site to scope this contact to that location.</p>
                                <section class="space-y-3 rounded-lg border border-gray-200 p-3 sm:col-span-2" :aria-label="`Communication methods for ${row.contact.firstName || 'contact'}`">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                        <div>
                                            <h4 class="text-sm font-semibold text-gray-900">Communication methods</h4>
                                            <p class="text-xs text-gray-600">Stored in FieldOps separately from the single-value contact fields; not synced to QuickBooks or Outlook.</p>
                                        </div>
                                        <div v-if="isEditing && !isQuickBooksBusy" class="flex flex-wrap gap-2">
                                            <button type="button" class="fieldops-secondary-action fieldops-add-action min-h-9 px-3 py-1" :disabled="row.contact.methods.length >= MAX_CONTACT_METHODS" @click="addContactMethod(row.index, 'email')">Add email</button>
                                            <button type="button" class="fieldops-secondary-action fieldops-add-action min-h-9 px-3 py-1" :disabled="row.contact.methods.length >= MAX_CONTACT_METHODS" @click="addContactMethod(row.index, 'phone')">Add phone</button>
                                        </div>
                                    </div>
                                    <article v-for="method in row.contact.methods" :key="method.clientKey" class="grid gap-3 rounded-lg bg-gray-50 p-3 sm:grid-cols-2">
                                        <div class="flex items-center justify-between gap-3 sm:col-span-2">
                                            <span class="text-sm font-medium text-gray-800">{{ contactMethodTypeLabels[method.methodType] }}</span>
                                            <label class="inline-flex min-h-10 items-center gap-2 text-sm text-gray-700">
                                                <input
                                                    type="radio"
                                                    :name="`primary-method-${row.contact.id ?? `new-${row.index}`}-${method.methodType}`"
                                                    :checked="method.isPrimary"
                                                    :aria-label="`Set ${contactMethodTypeLabels[method.methodType]} ${method.value || 'method'} as primary`"
                                                    @change="setPrimaryMethod(row.index, method.clientKey)"
                                                />
                                                Primary {{ contactMethodTypeLabels[method.methodType].toLocaleLowerCase() }}
                                            </label>
                                        </div>
                                        <label class="fieldops-workspace-field">Label
                                            <select v-model="method.label">
                                                <option v-for="option in contactMethodLabelOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                                            </select>
                                        </label>
                                        <label class="fieldops-workspace-field">{{ contactMethodTypeLabels[method.methodType] }}
                                            <input
                                                v-model="method.value"
                                                :type="method.methodType === 'email' ? 'email' : method.methodType === 'phone' ? 'tel' : 'text'"
                                                :maxlength="method.methodType === 'email' ? 255 : 1000"
                                                required
                                            />
                                        </label>
                                        <button
                                            v-if="isEditing && !isQuickBooksBusy && method.methodType !== 'teams'"
                                            type="button"
                                            class="fieldops-workspace-remove sm:col-span-2"
                                            @click="removeContactMethod(row.index, method.clientKey)"
                                        >
                                            Remove {{ contactMethodTypeLabels[method.methodType].toLocaleLowerCase() }}
                                        </button>
                                    </article>
                                    <p v-if="row.contact.methods.length === 0" class="text-sm text-gray-600">No email or phone methods added.</p>
                                    <p v-if="row.contact.methods.length >= MAX_CONTACT_METHODS" class="text-xs text-amber-800">This Contact has reached the communication-method limit.</p>
                                </section>
                                <button v-if="isEditing && !isQuickBooksBusy" type="button" class="fieldops-workspace-remove sm:col-span-2" @click="removeContact(row.index)">{{ row.contact.source === 'quickbooks' ? "Clear contact fields in QuickBooks" : "Remove contact" }}</button>
                                <p v-if="row.contact.source === 'quickbooks'" class="text-xs text-gray-500 sm:col-span-2">QuickBooks-synced Site contact. Edits queue to QuickBooks; any later conflict resolves to the QuickBooks value. The Site association remains managed in QuickBooks.</p>
                            </article>
                            <p v-if="visibleContacts.length === 0" class="text-sm text-gray-600">No contacts are recorded.</p>
                        </fieldset>

                        <fieldset class="space-y-3" :disabled="!isEditing || isQuickBooksBusy">
                            <div class="flex items-center justify-between gap-3">
                                <legend class="text-sm font-semibold text-gray-900">Additional email addresses</legend>
                                <button v-if="isEditing && !isQuickBooksBusy" type="button" class="fieldops-secondary-action fieldops-add-action min-h-9 px-3 py-1" @click="addEmailAddress">Add email</button>
                            </div>
                            <article v-for="row in visibleEmailAddresses" :key="row.email.id ?? `new-email-${row.index}`" class="grid gap-3 rounded-lg border border-gray-200 p-3 sm:grid-cols-2">
                                <label class="fieldops-workspace-field">Email address<input v-model="row.email.email" type="email" required maxlength="255" /></label>
                                <label class="fieldops-workspace-field">Label<input v-model="row.email.label" maxlength="100" placeholder="Billing, dispatch…" /></label>
                                <button v-if="isEditing && !isQuickBooksBusy" type="button" class="fieldops-workspace-remove sm:col-span-2" @click="removeEmailAddress(row.index)">Remove email</button>
                            </article>
                            <p v-if="visibleEmailAddresses.length === 0" class="text-sm text-gray-600">No additional addresses. These are not associated with a person.</p>
                        </fieldset>

                        <label class="fieldops-workspace-field">Internal customer notes<textarea v-model="draft.notes" rows="4" maxlength="20000" :readonly="!isEditing || isQuickBooksBusy" /></label>

                        <section class="space-y-2" aria-label="Customer attachments">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-sm font-semibold text-gray-900">Attachments</h3>
                                <label v-if="isEditing" class="fieldops-secondary-action min-h-9 cursor-pointer px-3 py-1">
                                    {{ uploading ? "Uploading…" : "Upload" }}
                                    <input class="sr-only" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.mov,.webm,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx" :disabled="uploading" @change="uploadAttachment" />
                                </label>
                            </div>
                            <div v-for="attachment in workspace.attachments" :key="attachment.url" class="flex flex-col gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between">
                                <a v-if="!attachment.onlineUrl" :href="attachment.previewUrl || attachment.url" :target="attachment.previewUrl ? '_blank' : undefined" :rel="attachment.previewUrl ? 'noreferrer' : undefined" class="flex min-w-0 items-center gap-3 text-blue-800 hover:text-blue-900">
                                    <AttachmentFileTypeIcon :file-name="attachment.name" :mime-type="attachment.mimeType" :thumbnail-url="attachment.thumbnailUrl" />
                                    <span class="min-w-0 flex-1 truncate">{{ attachment.name }}</span>
                                    <span class="shrink-0 text-xs text-gray-500">{{ formatBytes(attachment.size) }}</span>
                                </a>
                                <div v-else class="flex min-w-0 items-center gap-3">
                                    <AttachmentFileTypeIcon :file-name="attachment.name" :mime-type="attachment.mimeType" :thumbnail-url="attachment.thumbnailUrl" />
                                    <span class="min-w-0 flex-1 truncate text-gray-900">{{ attachment.name }}</span>
                                    <span class="shrink-0 text-xs text-gray-500">{{ formatBytes(attachment.size) }}</span>
                                </div>
                                <AttachmentOnlineActions
                                    v-if="attachment.onlineUrl"
                                    :attachment-id="attachment.id"
                                    :file-name="attachment.name"
                                    :online-url="attachment.onlineUrl"
                                    :open-url="attachment.previewUrl || attachment.url"
                                    :availability-url="attachment.availabilityUrl"
                                    @missing="removeMissingAttachment"
                                />
                            </div>
                            <p v-if="workspace.attachments.length === 0" class="text-sm text-gray-600">No attachments.</p>
                        </section>

                        <section class="space-y-2">
                            <h3 class="text-sm font-semibold text-gray-900">Assigned staff</h3>
                            <StaffAssignmentDropdown v-model="draft.assignedUserIds" :staff="staff" :disabled="!isEditing || isQuickBooksBusy" />
                        </section>

                        <section class="space-y-2" aria-label="Upcoming scheduled jobs">
                            <h3 class="text-sm font-semibold text-gray-900">Upcoming scheduled jobs</h3>
                            <div class="grid grid-rows-3 gap-2" style="min-height: 9rem">
                                <div v-for="(job, index) in upcomingSlots" :key="job?.id ?? `empty-upcoming-${index}`" class="flex min-h-10 items-center rounded-lg border border-gray-200 px-3 py-2 text-sm">
                                    <template v-if="job">
                                        <span class="min-w-0 flex-1 truncate font-medium">{{ job.number }} · {{ job.title }}</span>
                                        <span class="ml-3 shrink-0 text-xs text-gray-500">{{ formatDate(job.scheduledAt) }}</span>
                                    </template>
                                </div>
                            </div>
                        </section>
                    </section>

                    <section v-show="activeTab === 'projects'" class="space-y-3">
                        <p class="text-sm text-gray-600">Projects for this customer. Select one to open its existing details.</p>
                        <button v-for="project in visibleProjects" :key="project.id" type="button" class="fieldops-workspace-list-row" :aria-label="`Open ${project.name} project details. Press and hold to edit.`" title="Click to preview; press and hold to edit" v-on="projectPress(project.id)">
                            <span class="min-w-0 flex-1"><strong class="block truncate">{{ project.number ? `${project.number} · ` : "" }}{{ project.name }}</strong><small>{{ project.jobsCount }} jobs · {{ project.status }}</small></span>
                            <span aria-hidden="true">›</span>
                        </button>
                        <p v-if="visibleProjects.length === 0" class="py-8 text-center text-sm text-gray-600">No projects match this search.</p>
                    </section>

                    <section v-show="activeTab === 'jobs'" class="space-y-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm text-gray-600">Jobs are sorted by job number by default.</p>
                            <label class="text-xs text-gray-600">Sort
                                <select v-model="sortBy" class="ml-1 rounded border-gray-300 py-1 text-xs">
                                    <option value="job_number_asc">Job number ↑</option>
                                    <option value="job_number_desc">Job number ↓</option>
                                    <option value="title">Title</option>
                                    <option value="status">Status</option>
                                </select>
                            </label>
                        </div>
                        <button v-for="job in visibleJobs" :key="job.id" type="button" class="fieldops-workspace-list-row" :aria-label="`Open job ${job.number ?? job.title} details. Press and hold to edit.`" title="Click to preview; press and hold to edit" v-on="jobPress(job.id)">
                            <span class="min-w-0 flex-1"><strong class="block truncate">{{ job.number }} · {{ job.title }}</strong><small>{{ job.site || "No site" }}<span v-if="job.project"> · {{ job.project }}</span></small></span>
                            <span class="fieldops-status-badge shrink-0" :class="jobStatusBadgeClass(job.status)">{{ jobStatusLabel(job.status) }}</span>
                            <span aria-hidden="true">›</span>
                        </button>
                        <p v-if="visibleJobs.length === 0" class="py-8 text-center text-sm text-gray-600">No jobs match this search.</p>
                    </section>

                    <section v-show="activeTab === 'activity'" class="space-y-2">
                        <article v-for="event in workspace.activity" :key="event.id" class="rounded-lg border border-gray-200 px-3 py-3">
                            <p class="text-sm text-gray-900">{{ formatDate(event.createdAt) }} · {{ event.summary }}</p>
                        </article>
                        <p v-if="workspace.activity.length === 0" class="py-8 text-center text-sm text-gray-600">No activity has been recorded yet.</p>
                    </section>
                </div>

                <div v-if="activeTab === 'projects' || activeTab === 'jobs'" class="border-t border-gray-200 p-3">
                    <label class="sr-only" for="customer-workspace-search">Search this list</label>
                    <ClearableSearchInput id="customer-workspace-search" v-model="tabSearch" class="fieldops-client-input fieldops-directory-search-input" placeholder="Search this list" clear-label="Clear customer list search" />
                </div>

                <footer class="fieldops-workspace-footer">
                    <button v-if="isEditing" type="button" class="fieldops-delete-action" :disabled="!canArchive || archiving" @click="archiveCustomer">
                        {{ archiving ? "Archiving…" : "Delete" }}
                    </button>
                    <div class="ml-auto flex items-center gap-2">
                        <button v-if="isEditing" type="button" class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0" aria-label="Cancel customer edits" title="Cancel editing" :disabled="saving" @click="cancelEditing">
                            <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                        </button>
                        <button v-else type="button" class="fieldops-secondary-action fieldops-edit-action min-h-10" :disabled="isQuickBooksBusy" @click="beginEditing">
                            Edit
                        </button>
                        <button v-if="isEditing" type="submit" class="fieldops-submit-action min-h-10" :disabled="saving || isQuickBooksBusy || !hasChanges">
                            {{ saving ? "Saving…" : "Submit" }}
                        </button>
                    </div>
                </footer>
            </form>
        </section>
    </div>
    <ProjectWorkspace
        v-if="selectedProjectId !== null"
        :project-id="selectedProjectId"
        :can-archive="canArchive"
        :nested="true"
        :edit-request-id="selectedProjectEditTargetId === selectedProjectId ? selectedProjectEditRequestId : 0"
        @close="closeProject"
        @saved="refreshWorkspaceData"
        @archived="refreshWorkspaceData"
    />
    <JobWorkspace
        v-if="selectedJobId !== null"
        :job-id="selectedJobId"
        :can-archive="canArchive"
        :nested="true"
        :edit-request-id="selectedJobEditTargetId === selectedJobId ? selectedJobEditRequestId : 0"
        @close="closeJob"
        @saved="refreshWorkspaceData"
        @archived="refreshWorkspaceData"
    />
</template>
