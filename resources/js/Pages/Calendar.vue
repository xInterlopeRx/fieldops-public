<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import {
    ArrowLeftIcon,
    ChevronLeftIcon,
    ChevronRightIcon,
    PencilIcon,
    PlusIcon,
    XMarkIcon,
} from "@heroicons/vue/24/outline";
import { useSwipeNavigation } from "@/Composables/useSwipeNavigation";
import { useWorkOrderStatus } from "@/Composables/useWorkOrderStatus";
import ClearableSearchInput from "@/Components/ClearableSearchInput.vue";
import type { GlobalSearchResult } from "@/Composables/useGlobalSearchAutocomplete";
import AttachmentFileTypeIcon from "@/Components/AttachmentFileTypeIcon.vue";
import AttachmentOnlineActions from "@/Components/AttachmentOnlineActions.vue";
import TimeEntryTimer from "@/Components/TimeEntryTimer.vue";
import VisitItemUsageEditor from "@/Components/VisitItemUsageEditor.vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import axios from "axios";

interface Visit {
    id: number;
    scheduled_at: string; // ISO 8601 format
    title?: string;
    status: string;
    duration_minutes?: number;
    workOrder?: WorkOrderSummary | null;
    work_order?: WorkOrderSummary | null;
    assignedUsers?: any[];
}

// TODO: Replace duration-derived month segments with explicit intervals and overlap queries per ADR-0019.

interface WorkOrderSummary {
    id: number;
    job_number?: string;
    title?: string;
    status?: string;
    site?: {
        customer?: { name?: string };
    };
}

interface CalendarDay {
    date: Date;
    dateKey: string;
    isCurrentMonth: boolean;
    isSelected: boolean;
    isToday: boolean;
}

interface CalendarDropTarget {
    date: Date;
    dateKey: string;
    minuteOfDay: number | null;
}

type VisitDragMode = "move" | "resize-start" | "resize-end";

interface VisitScheduleInterval {
    scheduledAt: Date;
    durationMinutes: number;
}

interface VisitDragPreview {
    visitId: number;
    title: string;
    mode: VisitDragMode;
    durationMinutes: number;
    dateKey: string | null;
    minuteOfDay: number | null;
    dateLabel: string;
    timeLabel: string;
    left: number;
    top: number;
    hasTarget: boolean;
}

interface MonthDropPreviewPosition {
    left: number;
    top: number;
}

interface PendingVisitMove {
    visitId: number;
    jobLabel: string;
    mode: VisitDragMode;
    fromLabel: string;
    toLabel: string;
    scheduledAt: string;
    durationMinutes: number;
}

interface CustomerSummary {
    id: number;
    client_id: string | null;
    name: string;
    first_name: string | null;
    last_name: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    notes: string | null;
}

interface CustomerQuickBooksSync {
    linked: boolean;
    write_pending: boolean;
    status: "idle" | "pending" | "synced" | "failed" | "not_connected" | "sync_paused";
    last_synced_at: string | null;
    error: string | null;
}

interface CustomerAddressFields {
    line1: string | null;
    line2: string | null;
    line3: string | null;
    line4: string | null;
    line5: string | null;
    city: string | null;
    country_subdivision_code: string | null;
    postal_code: string | null;
    country: string | null;
}

interface CustomerAddressForm {
    line1: string;
    line2: string;
    line3: string;
    line4: string;
    line5: string;
    city: string;
    country_subdivision_code: string;
    postal_code: string;
    country: string;
}

interface CustomerAddressDefaults {
    billing: CustomerAddressFields;
    shipping: CustomerAddressFields;
}

interface CustomerDetail extends CustomerSummary {
    quickbooks_sync: CustomerQuickBooksSync;
    address_defaults: CustomerAddressDefaults;
    contacts: Array<{
        id: number;
        contact_type: string;
        first_name: string | null;
        last_name: string | null;
        email: string | null;
        phone: string | null;
    }>;
    sites: Array<{ id: number; name: string; address: string | null }>;
}

interface WorkOrderAttachment {
    id: number;
    kind: "photos" | "documents";
    original_name: string;
    mime_type: string;
    size: number;
    url: string;
    download_url: string;
    created_at: string;
    preview_url?: string | null;
    thumbnail_url?: string | null;
    online_url?: string | null;
    availability_url?: string | null;
    office_app?: "word" | "excel" | "powerpoint" | null;
}

interface WorkOrderDetail {
    id: number;
    job_number: string;
    title: string;
    subtitle: string | null;
    description: string | null;
    scope: string | null;
    client_completion_summary: string | null;
    client_contact_name: string | null;
    client_contact_phone: string | null;
    client_contact_email: string | null;
    client_contact_id: number | null;
    technical_contact_name: string | null;
    technical_contact_phone: string | null;
    technical_contact_email: string | null;
    technical_contact_id: number | null;
    project_manager_name: string | null;
    project_manager_phone: string | null;
    project_manager_email: string | null;
    project_manager_contact_id: number | null;
    contractor_check_in_name: string | null;
    contractor_check_in_phone: string | null;
    contractor_check_in_email: string | null;
    contractor_check_in_contact_id: number | null;
    job_notes: string | null;
    field_notes: string | null;
    status: string;
    status_history?: Array<{ id: number; from_status: string | null; to_status: string; reason: string | null; changed_by: string | null; created_at: string }>;
    site: {
        id: number;
        name: string;
        address: string | null;
        customer: CustomerDetail;
    };
    project?: {
        id: number;
        project_number: string | null;
        name: string;
    } | null;
    attachments: WorkOrderAttachment[];
    visits: Array<{
        id: number;
        visit_type: "initial" | "revisit";
        scheduled_at: string;
        scope: string | null;
        notes: string | null;
        items_editable: boolean;
        items: Array<{ id: number; itemId: number; name: string; description: string | null; type: string | null; quantity: string }>;
    }>;
}

interface TimeEntryTimerVisit {
    id: number;
    visitType: "initial" | "revisit";
    scheduledAt: string;
}

interface ProjectDetail {
    id: number;
    project_number: string | null;
    name: string;
    notes: string | null;
    customer: CustomerSummary;
    sites: Array<{ id: number; name: string; address: string | null }>;
    work_orders: Array<{
        id: number;
        job_number: string;
        title: string;
        status: string;
    }>;
}

const props = defineProps<{
    initialData: Visit[];
}>();
const page = usePage();
const {
    definition: workOrderStatusDefinition,
    label: workOrderStatusLabel,
    color: workOrderStatusColor,
    badgeClass: workOrderStatusBadgeClass,
} = useWorkOrderStatus();
const calendarSelectableWorkOrderStatuses = computed(() =>
    page.props.work_order_statuses.filter((status) => status.calendar_selectable),
);
const isJobNotesRequired = (status: string): boolean =>
    workOrderStatusDefinition(status)?.job_notes_required ?? false;
const isClientCompletionSummaryRequired = (status: string): boolean =>
    workOrderStatusDefinition(status)?.client_completion_summary_required ?? false;
const isPendingReasonRequired = (status: string): boolean =>
    workOrderStatusDefinition(status)?.pending_reason_required ?? false;
const timerStartConflictError = (errors: object): string | undefined =>
    (errors as Record<string, string>).timer_start_conflict;
const archiveError = ref("");
const archivingRecord = ref(false);

const currentView = ref<"month" | "week" | "day">("month");
const currentDate = ref(new Date());
const selectedDate = ref(new Date());

type WeekStartDay = "sunday" | "monday";

// TODO: Add a Settings control and persist this preference for the signed-in user.
const weekStartsOn = ref<WeekStartDay>("monday");
const weekStartIndex = computed(() => (weekStartsOn.value === "monday" ? 1 : 0));
const weekdayLabels = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
const weekdayNames = computed(() =>
    Array.from({ length: 7 }, (_, index) =>
        weekdayLabels[(weekStartIndex.value + index) % 7],
    ),
);
const getWeekStartOffset = (date: Date): number =>
    (date.getDay() - weekStartIndex.value + 7) % 7;

const toDateKey = (date: Date): string => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
};

const startOfDay = (date: Date): Date =>
    new Date(date.getFullYear(), date.getMonth(), date.getDate());

const isSameDay = (firstDate: Date, secondDate: Date): boolean =>
    toDateKey(firstDate) === toDateKey(secondDate);

const monthLabel = computed(() =>
    currentDate.value.toLocaleDateString(undefined, {
        month: "long",
        year: "numeric",
    }),
);

const weekLabel = computed(() => {
    const weekStart = new Date(currentDate.value);
    weekStart.setDate(weekStart.getDate() - getWeekStartOffset(weekStart));
    const weekEnd = new Date(weekStart);
    weekEnd.setDate(weekStart.getDate() + 6);

    return `${weekStart.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
    })} - ${weekEnd.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
        year: "numeric",
    })}`;
});

const navigationLabel = computed(() => {
    if (currentView.value === "week") return weekLabel.value;
    if (currentView.value === "day") {
        return currentDate.value.toLocaleDateString(undefined, {
            month: "short",
            day: "numeric",
            year: "numeric",
        });
    }

    return monthLabel.value;
});

const fullDateLabel = computed(() =>
    selectedDate.value.toLocaleDateString(undefined, {
        weekday: "long",
        month: "long",
        day: "numeric",
        year: "numeric",
    }),
);

const calendarDays = computed<CalendarDay[]>(() => {
    const year = currentDate.value.getFullYear();
    const month = currentDate.value.getMonth();
    const firstDayOfMonth = new Date(year, month, 1);
    const lastDayOfMonth = new Date(year, month + 1, 0);
    const firstDayOffset = getWeekStartOffset(firstDayOfMonth);
    const lastDayOffset = getWeekStartOffset(lastDayOfMonth);
    const firstGridDate = new Date(year, month, 1 - firstDayOffset);
    const lastGridDate = new Date(
        year,
        month,
        lastDayOfMonth.getDate() + (6 - lastDayOffset),
    );
    const days: CalendarDay[] = [];
    const today = new Date();

    for (
        const date = new Date(firstGridDate);
        date <= lastGridDate;
        date.setDate(date.getDate() + 1)
    ) {
        days.push({
            date: new Date(date),
            dateKey: toDateKey(date),
            isCurrentMonth: date.getMonth() === month,
            isSelected: isSameDay(date, selectedDate.value),
            isToday: isSameDay(date, today),
        });
    }

    return days;
});

const weekDays = computed<CalendarDay[]>(() => {
    const weekStart = new Date(currentDate.value);
    weekStart.setDate(weekStart.getDate() - getWeekStartOffset(weekStart));
    const today = new Date();

    return Array.from({ length: 7 }, (_, index) => {
        const date = new Date(weekStart);
        date.setDate(weekStart.getDate() + index);

        return {
            date,
            dateKey: toDateKey(date),
            isCurrentMonth: date.getMonth() === currentDate.value.getMonth(),
            isSelected: isSameDay(date, selectedDate.value),
            isToday: isSameDay(date, today),
        };
    });
});

const visitsByDate = computed(() => {
    const visits = new Map<string, Visit[]>();

    for (const visit of props.initialData) {
        const dateKey = toDateKey(new Date(visit.scheduled_at));
        const dailyVisits = visits.get(dateKey) ?? [];
        dailyVisits.push(visit);
        visits.set(dateKey, dailyVisits);
    }

    return visits;
});

const visitsByMonthDate = computed(() => {
    const visits = new Map<string, Visit[]>();
    const days = calendarDays.value;
    if (days.length === 0) return visits;

    const rangeStart = startOfDay(days[0].date);
    const rangeEnd = startOfDay(days[days.length - 1].date);
    rangeEnd.setDate(rangeEnd.getDate() + 1);

    for (const visit of props.initialData) {
        const visitStart = new Date(visit.scheduled_at);
        const duration = Math.max(1, visit.duration_minutes ?? 60);
        const visitEnd = new Date(visitStart.getTime() + duration * 60_000);

        if (visitStart >= rangeEnd || visitEnd <= rangeStart) continue;

        const firstVisibleDay =
            visitStart < rangeStart ? rangeStart : startOfDay(visitStart);
        for (
            const date = new Date(firstVisibleDay);
            date < visitEnd && date < rangeEnd;
            date.setDate(date.getDate() + 1)
        ) {
            const dateKey = toDateKey(date);
            const dailyVisits = visits.get(dateKey) ?? [];
            dailyVisits.push(visit);
            visits.set(dateKey, dailyVisits);
        }
    }

    return visits;
});

const visitsForDate = (date: Date): Visit[] => {
    return visitsByDate.value.get(toDateKey(date)) ?? [];
};

const visitsForMonthDate = (date: Date): Visit[] =>
    visitsByMonthDate.value.get(toDateKey(date)) ?? [];

const getVisitMonthSegmentLabel = (visit: Visit, date: Date): string => {
    const visitStart = new Date(visit.scheduled_at);
    const visitEnd = new Date(
        visitStart.getTime() + Math.max(1, visit.duration_minutes ?? 60) * 60_000,
    );
    const dayStart = startOfDay(date);
    const nextDay = new Date(dayStart);
    nextDay.setDate(nextDay.getDate() + 1);
    const startsToday = visitStart >= dayStart && visitStart < nextDay;
    const endsToday = visitEnd > dayStart && visitEnd <= nextDay;
    const time = (value: Date) =>
        value.toLocaleTimeString(undefined, {
            hour: "numeric",
            minute: "2-digit",
        });

    if (startsToday && endsToday) {
        return getVisitRange(
            visitStart,
            Math.max(1, visit.duration_minutes ?? 60),
        );
    }
    if (startsToday) return `${time(visitStart)} – continues`;
    if (endsToday) return `Continues – ${time(visitEnd)}`;

    return "Continues";
};

const getCompactVisitMonthSegmentLabel = (visit: Visit, date: Date): string => {
    const visitStart = new Date(visit.scheduled_at);
    const visitEnd = new Date(
        visitStart.getTime() + Math.max(1, visit.duration_minutes ?? 60) * 60_000,
    );
    const dayStart = startOfDay(date);
    const nextDay = new Date(dayStart);
    nextDay.setDate(nextDay.getDate() + 1);
    const startsToday = visitStart >= dayStart && visitStart < nextDay;
    const endsToday = visitEnd > dayStart && visitEnd <= nextDay;

    if (startsToday && endsToday) return formatCompactClockTime(visitStart);
    if (startsToday) return `${formatCompactClockTime(visitStart)}+`;
    if (endsToday) return `+${formatCompactClockTime(visitEnd)}`;

    return "↔";
};

const visitStartsOnDate = (visit: Visit, date: Date): boolean =>
    isSameDay(new Date(visit.scheduled_at), date);

const visitEndsOnDate = (visit: Visit, date: Date): boolean => {
    const start = new Date(visit.scheduled_at);
    const end = new Date(
        start.getTime() + Math.max(1, visit.duration_minutes ?? 60) * 60_000,
    );
    const dayStart = startOfDay(date);
    const nextDay = new Date(dayStart);
    nextDay.setDate(nextDay.getDate() + 1);

    return end > dayStart && end <= nextDay;
};

const visitsForHour = (date: Date, hour: number): Visit[] =>
    visitsForDate(date).filter(
        (visit) => new Date(visit.scheduled_at).getHours() === hour,
    );

const handleTimeSlotClick = (date: Date, hour: number): void => {
    if (visitsForHour(date, hour).length === 0) {
        openJobFormAtSlot(date, hour);
    }
};

const getVisitSlotHeight = (visit: Visit): string => {
    const hours = Math.max(1, Math.ceil((visit.duration_minutes ?? 60) / 60));

    return `${hours * 48 - 4}px`;
};

const formatDayNumber = (date: Date): string =>
    date.toLocaleDateString(undefined, { day: "numeric" });

const setCalendarView = (view: "month" | "week" | "day") => {
    currentDate.value = new Date(selectedDate.value);
    currentView.value = view;
};

/** FAB State Management **/
const fabMode = ref<
    "default" | "menu" | "client" | "job" | "project"
>("default");
const selectedCustomer = ref<CustomerDetail | null>(null);
const quickBooksSyncRequestBusy = ref(false);
const quickBooksSyncRequestError = ref<string | null>(null);
let quickBooksSyncPollGeneration = 0;
const clientFormMode = ref<"create" | "view" | "edit">("create");
const clientForm = useForm({
    name: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    billing_address: {
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
    shipping_address: {
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
    notes: "",
});
const quickBooksCustomerFormError = computed(
    () => (clientForm.errors as unknown as Record<string, string | undefined>).quickbooks,
);

const addressFormValues = (address: CustomerAddressFields): CustomerAddressForm => ({
    line1: address.line1 ?? "",
    line2: address.line2 ?? "",
    line3: address.line3 ?? "",
    line4: address.line4 ?? "",
    line5: address.line5 ?? "",
    city: address.city ?? "",
    country_subdivision_code: address.country_subdivision_code ?? "",
    postal_code: address.postal_code ?? "",
    country: address.country ?? "",
});
const jobFormMode = ref<"create" | "edit">("create");
const jobPanelMode = ref<"view" | "form" | "revisit">("form");
const selectedWorkOrder = ref<WorkOrderDetail | null>(null);
const attachmentStatusMessage = ref("");
const calendarJobPanelElement = ref<HTMLElement | null>(null);
const calendarJobPanelReturnFocus = ref<HTMLElement | null>(null);
const selectedTimerVisitId = ref<number | null>(null);
const timeEntryTimerVisits = computed<TimeEntryTimerVisit[]>(() =>
    (selectedWorkOrder.value?.visits ?? []).map((visit) => ({
        id: visit.id,
        visitType: visit.visit_type,
        scheduledAt: visit.scheduled_at,
    })),
);
const selectedProject = ref<ProjectDetail | null>(null);
const jobCustomerQuery = ref("");
const jobCustomer = ref<CustomerDetail | null>(null);
const selectedJobContactIndex = ref(0);
const jobCustomerMode = ref<"search" | "create">("search");
const jobSiteMode = ref<"select" | "create">("select");
const jobForm = useForm({
    title: "",
    subtitle: "",
    description: "",
    scope: "",
    client_completion_summary: "",
    client_contact_name: "",
    client_contact_phone: "",
    client_contact_email: "",
    client_contact_id: null as number | null,
    technical_contact_name: "",
    technical_contact_phone: "",
    technical_contact_email: "",
    technical_contact_id: null as number | null,
    project_manager_name: "",
    project_manager_phone: "",
    project_manager_email: "",
    project_manager_contact_id: null as number | null,
    contractor_check_in_name: "",
    contractor_check_in_phone: "",
    contractor_check_in_email: "",
    contractor_check_in_contact_id: null as number | null,
    job_notes: "",
    field_notes: "",
    status: page.props.work_order_default_status,
    pending_reason: "",
    timer_visit_id: null as number | null,
    scheduled_at: "",
    scheduling_override: false,
    customer_id: null as number | null,
    customer_name: "",
    customer_email: "",
    customer_phone: "",
    customer_address: "",
    site_id: null as number | null,
    site_name: "",
    site_address: "",
    files: [] as File[],
});
const revisitForm = useForm({
    scheduled_at: "",
    notes: "",
    duration_minutes: 60,
    scheduling_override: false,
    files: [] as File[],
});
const viewStatusForm = useForm({
    status: page.props.work_order_default_status,
    client_completion_summary: "",
    pending_reason: "",
    scheduled_at: "",
    scheduling_override: false,
    timer_visit_id: null as number | null,
});
const viewAttachmentForm = useForm({ files: [] as File[] });
const visitScopeDrafts = reactive<Record<number, string>>({});

/** FAB Actions **/
const openMenu = () => {
    fabMode.value = fabMode.value === "menu" ? "default" : "menu";
};

const openClientForm = () => {
    closeFab();
    clientForm.reset();
    clientForm.clearErrors();
    clientFormMode.value = "create";
    fabMode.value = "client";
};

const openJobForm = () => {
    closeFab();
    jobForm.reset();
    jobForm.clearErrors();
    jobFormMode.value = "create";
    jobPanelMode.value = "form";
    selectedWorkOrder.value = null;
    selectedTimerVisitId.value = null;
    selectedProject.value = null;
    jobCustomer.value = null;
    jobCustomerMode.value = "search";
    jobCustomerQuery.value = "";
    jobSiteMode.value = "select";
    fabMode.value = "job";
};

const openJobFormAtSlot = (date: Date, hour: number): void => {
    openJobForm();
    jobForm.status = "scheduled";

    const scheduledAt = new Date(date);
    scheduledAt.setHours(hour, 0, 0, 0);
    jobForm.scheduled_at = localDateTimeValue(scheduledAt);
};

const openJobFormForDate = (date: Date): void => {
    const selectedDay = startOfDay(date);
    selectedDate.value = selectedDay;

    if (selectedDay.getMonth() !== currentDate.value.getMonth()) {
        currentDate.value = new Date(
            selectedDay.getFullYear(),
            selectedDay.getMonth(),
            1,
        );
    }

    openJobFormAtSlot(selectedDay, 8);
};

const selectJobCustomer = async (customerId: number) => {
    quickBooksSyncPollGeneration += 1;
    const response = await fetch(route("customers.show", customerId), {
        headers: { Accept: "application/json" },
    });
    const payload = (await response.json()) as {
        data: CustomerDetail;
        quickbooks_sync: CustomerQuickBooksSync;
        address_defaults: CustomerAddressDefaults;
    };

    jobCustomer.value = {
        ...payload.data,
        quickbooks_sync: payload.quickbooks_sync,
        address_defaults: payload.address_defaults,
    };
    if (payload.quickbooks_sync.status === "pending") {
        void pollQuickBooksCustomer(payload.data.id, "job");
    }
    jobCustomerMode.value = "search";
    jobForm.customer_id = payload.data.id;
    jobForm.customer_name = "";
    const primaryContact = payload.data.contacts.find(
        (contact) => contact.contact_type === "primary",
    );
    jobForm.client_contact_name = [
        primaryContact?.first_name ?? payload.data.first_name,
        primaryContact?.last_name ?? payload.data.last_name,
    ]
        .filter(Boolean)
        .join(" ");
    jobForm.client_contact_phone =
        primaryContact?.phone ?? payload.data.phone ?? "";
    jobForm.client_contact_email =
        primaryContact?.email ?? payload.data.email ?? "";
    selectedJobContactIndex.value = Math.max(
        0,
        payload.data.contacts.findIndex(
            (contact) => contact.contact_type === "primary",
        ),
    );
    jobForm.site_id = payload.data.sites[0]?.id ?? null;
    jobCustomerQuery.value = payload.data.name;
};

const selectJobCustomerSuggestion = (result: GlobalSearchResult): void => {
    if (result.type === "client") void selectJobCustomer(result.id);
};

const selectJobContact = (index: number): void => {
    if (!jobCustomer.value) return;

    const contact = jobCustomer.value.contacts[index];

    if (!contact) return;

    selectedJobContactIndex.value = index;
    jobForm.client_contact_id = contact.id;
    jobForm.client_contact_name = [contact.first_name, contact.last_name]
        .filter(Boolean)
        .join(" ");
    jobForm.client_contact_phone = contact.phone ?? "";
    jobForm.client_contact_email = contact.email ?? "";
};

const selectJobContactRole = (
    role:
        | "client_contact"
        | "technical_contact"
        | "project_manager"
        | "contractor_check_in",
    contactId: number | null,
): void => {
    if (!jobCustomer.value || contactId === null) {
        return;
    }

    const contact = jobCustomer.value.contacts.find(
        (item) => item.id === contactId,
    );

    if (!contact) return;

    const idField =
        role === "project_manager"
            ? "project_manager_contact_id"
            : role === "contractor_check_in"
              ? "contractor_check_in_contact_id"
              : `${role}_id`;
    (jobForm as unknown as Record<string, string | number | null>)[idField] =
        contact.id;
    (jobForm as unknown as Record<string, string | number | null>)[
        `${role}_name`
    ] = [contact.first_name, contact.last_name].filter(Boolean).join(" ");
    (jobForm as unknown as Record<string, string | number | null>)[
        `${role}_phone`
    ] = contact.phone ?? "";
    (jobForm as unknown as Record<string, string | number | null>)[
        `${role}_email`
    ] = contact.email ?? "";
};

const handleJobContactRoleChange = (
    role:
        | "client_contact"
        | "technical_contact"
        | "project_manager"
        | "contractor_check_in",
    event: Event,
): void => {
    const value = Number((event.target as HTMLSelectElement).value);
    selectJobContactRole(role, value || null);
};

const clearJobCustomer = () => {
    jobCustomer.value = null;
    jobCustomerMode.value = "search";
    jobForm.customer_id = null;
    jobForm.site_id = null;
    jobCustomerQuery.value = "";
};

const startNewJobCustomer = () => {
    jobCustomer.value = null;
    jobCustomerMode.value = "create";
    jobCustomerQuery.value = "";
    jobForm.customer_id = null;
};

const handleJobFiles = (event: Event) => {
    const input = event.target as HTMLInputElement;
    jobForm.files = input.files ? Array.from(input.files) : [];
};

const updateJobContact = (
    role: string,
    field: "name" | "phone" | "email",
    event: Event,
) => {
    const value = (event.target as HTMLInputElement).value;
    const key = `${role}_${field}`;

    (jobForm as unknown as Record<string, string>)[key] = value;
};

const openRevisitForm = () => {
    revisitForm.reset();
    revisitForm.clearErrors();
    revisitForm.duration_minutes = 60;
    revisitForm.scheduling_override = false;
    revisitForm.files = [];
    jobPanelMode.value = "revisit";
};

const handleRevisitFiles = (event: Event) => {
    const input = event.target as HTMLInputElement;
    revisitForm.files = input.files ? Array.from(input.files) : [];
};

const submitRevisit = () => {
    if (!selectedWorkOrder.value) return;

    revisitForm.post(
        route("work-orders.revisits.store", selectedWorkOrder.value.id),
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => openExistingJob(selectedWorkOrder.value!.id),
        },
    );
};

const proceedWithRevisitConflict = () => {
    revisitForm.scheduling_override = true;
    submitRevisit();
};

const submitJob = (): void => {
    if (jobFormMode.value === "edit" && selectedWorkOrder.value) {
        jobForm.timer_visit_id = selectedTimerVisitId.value;
        jobForm.clearErrors();
    }

    const options = {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            if (jobFormMode.value === "edit") {
                if (selectedWorkOrder.value) {
                    selectedWorkOrder.value = {
                        ...selectedWorkOrder.value,
                        title: jobForm.title,
                        subtitle: jobForm.subtitle,
                        description: jobForm.description,
                        scope: jobForm.scope,
                        client_completion_summary:
                            jobForm.client_completion_summary || null,
                        client_contact_name: jobForm.client_contact_name,
                        client_contact_phone: jobForm.client_contact_phone,
                        client_contact_email: jobForm.client_contact_email,
                        technical_contact_name: jobForm.technical_contact_name,
                        technical_contact_phone:
                            jobForm.technical_contact_phone,
                        technical_contact_email:
                            jobForm.technical_contact_email,
                        project_manager_name: jobForm.project_manager_name,
                        project_manager_phone: jobForm.project_manager_phone,
                        project_manager_email: jobForm.project_manager_email,
                        contractor_check_in_name:
                            jobForm.contractor_check_in_name,
                        contractor_check_in_phone:
                            jobForm.contractor_check_in_phone,
                        contractor_check_in_email:
                            jobForm.contractor_check_in_email,
                        job_notes: jobForm.job_notes,
                        field_notes: jobForm.field_notes,
                        status: jobForm.status as WorkOrderDetail["status"],
                        visits: selectedWorkOrder.value.visits.map(
                            (visit, index) =>
                                index === 0
                                        ? {
                                              ...visit,
                                              scheduled_at: jobForm.scheduled_at,
                                          }
                                    : visit,
                        ),
                    };
                }
                jobPanelMode.value = "view";
                jobForm.clearErrors();
                window.dispatchEvent(new Event("fieldops:time-entry-timer-refresh"));
                if (selectedWorkOrder.value) {
                    void openExistingJob(selectedWorkOrder.value.id);
                }
            } else {
                closeFab();
            }
        },
    };

    if (jobFormMode.value === "edit" && selectedWorkOrder.value) {
        jobForm.put(
            route("work-orders.update", selectedWorkOrder.value.id),
            options,
        );

        return;
    }

    jobForm.post(route("work-orders.store"), options);
};

const proceedWithScheduleConflict = () => {
    jobForm.scheduling_override = true;
    submitJob();
};

const openExistingJob = async (workOrderId: number, visitId?: number) => {
    if (!selectedWorkOrder.value && document.activeElement instanceof HTMLElement) {
        calendarJobPanelReturnFocus.value = document.activeElement;
    }

    if (selectedWorkOrder.value?.id !== workOrderId) {
        selectedTimerVisitId.value = visitId ?? null;
        attachmentStatusMessage.value = "";
    } else if (visitId !== undefined) {
        selectedTimerVisitId.value = visitId;
    }

    if (import.meta.env.DEV) {
        console.debug("[calendar] opening job", { workOrderId, visitId });
    }

    try {
        const response = await fetch(route("work-orders.show", workOrderId), {
            headers: { Accept: "application/json" },
        });

        if (!response.ok) {
            throw new Error(`Job detail request failed: ${response.status}`);
        }

        const payload = (await response.json()) as { data: WorkOrderDetail };

        selectedWorkOrder.value = payload.data;
        if (
            selectedTimerVisitId.value === null &&
            payload.data.visits.length === 1
        ) {
            selectedTimerVisitId.value = payload.data.visits[0]?.id ?? null;
        }
        Object.keys(visitScopeDrafts).forEach((visitId) =>
            delete visitScopeDrafts[Number(visitId)],
        );
        payload.data.visits.forEach((visit) => {
            visitScopeDrafts[visit.id] = visit.scope ?? payload.data.scope ?? "";
        });
        selectedProject.value = null;
        viewStatusForm.status = payload.data.status;
        viewStatusForm.client_completion_summary =
            payload.data.client_completion_summary ?? "";
        viewStatusForm.pending_reason = "";
        viewStatusForm.scheduled_at = payload.data.visits[0]?.scheduled_at
            ? new Date(payload.data.visits[0].scheduled_at).toISOString().slice(0, 16)
            : "";
        viewStatusForm.scheduling_override = false;
        viewStatusForm.timer_visit_id = selectedTimerVisitId.value;
        viewStatusForm.clearErrors();
        jobFormMode.value = "edit";
        jobPanelMode.value = "view";
        jobCustomer.value = payload.data.site.customer;
        jobCustomerMode.value = "search";
        selectedJobContactIndex.value = Math.max(
            0,
            payload.data.site.customer.contacts.findIndex(
                (contact) => contact.contact_type === "primary",
            ),
        );
        jobCustomerQuery.value = payload.data.site.customer.name;
        jobSiteMode.value = "select";
        jobForm.defaults({
            title: payload.data.title,
            subtitle: payload.data.subtitle ?? "",
            description: payload.data.description ?? "",
            scope: payload.data.scope ?? "",
            client_completion_summary:
                payload.data.client_completion_summary ?? "",
            client_contact_name: payload.data.client_contact_name ?? "",
            client_contact_phone: payload.data.client_contact_phone ?? "",
            client_contact_email: payload.data.client_contact_email ?? "",
            client_contact_id: payload.data.client_contact_id ?? null,
            technical_contact_name: payload.data.technical_contact_name ?? "",
            technical_contact_phone: payload.data.technical_contact_phone ?? "",
            technical_contact_email: payload.data.technical_contact_email ?? "",
            technical_contact_id: payload.data.technical_contact_id ?? null,
            project_manager_name: payload.data.project_manager_name ?? "",
            project_manager_phone: payload.data.project_manager_phone ?? "",
            project_manager_email: payload.data.project_manager_email ?? "",
            project_manager_contact_id:
                payload.data.project_manager_contact_id ?? null,
            contractor_check_in_name:
                payload.data.contractor_check_in_name ?? "",
            contractor_check_in_phone:
                payload.data.contractor_check_in_phone ?? "",
            contractor_check_in_email:
                payload.data.contractor_check_in_email ?? "",
            contractor_check_in_contact_id:
                payload.data.contractor_check_in_contact_id ?? null,
            job_notes: payload.data.job_notes ?? "",
            field_notes: payload.data.field_notes ?? "",
            status: payload.data.status,
            pending_reason: "",
            timer_visit_id: selectedTimerVisitId.value,
            scheduled_at: payload.data.visits[0]?.scheduled_at
                ? new Date(payload.data.visits[0].scheduled_at)
                      .toISOString()
                      .slice(0, 16)
                : "",
            scheduling_override: false,
            customer_id: payload.data.site.customer.id,
            customer_name: "",
            customer_email: "",
            customer_phone: "",
            customer_address: "",
            site_id: payload.data.site.id,
            site_name: "",
            site_address: "",
            files: [],
        });
        jobForm.reset();
        jobForm.clearErrors();
        fabMode.value = "job";
    } catch (error) {
        console.error("[calendar] failed to open job", { workOrderId, error });
    }
};

const removeMissingWorkOrderAttachment = (attachmentId: number): void => {
    if (selectedWorkOrder.value) {
        selectedWorkOrder.value.attachments = selectedWorkOrder.value.attachments
            .filter((attachment) => attachment.id !== attachmentId);
    }

    attachmentStatusMessage.value = "This file was missing from SharePoint; its stale FieldOps attachment link was removed.";
};

watch(selectedWorkOrder, (workOrder) => {
    if (workOrder) {
        void nextTick(() => calendarJobPanelElement.value?.focus());
    }
});

const beginJobEdit = () => {
    jobPanelMode.value = "form";
    jobForm.clearErrors();
};

const handleJobStatusSelection = (): void => {
    if (!selectedWorkOrder.value) return;
    if (viewStatusForm.status === "pending" && selectedWorkOrder.value.status !== "pending") return;
    if (
        viewStatusForm.status === "scheduled" &&
        selectedWorkOrder.value.status !== "scheduled" &&
        selectedWorkOrder.value.visits.length === 0
    ) return;

    updateJobStatus();
};

const updateJobStatus = (): void => {
    if (!selectedWorkOrder.value) return;
    if (isClientCompletionSummaryRequired(viewStatusForm.status) && !viewStatusForm.client_completion_summary.trim()) {
        return;
    }
    if (isPendingReasonRequired(viewStatusForm.status) && selectedWorkOrder.value.status !== viewStatusForm.status && !viewStatusForm.pending_reason.trim()) {
        return;
    }
    if (viewStatusForm.status === "scheduled" && selectedWorkOrder.value.visits.length === 0 && !viewStatusForm.scheduled_at) {
        return;
    }

    viewStatusForm.timer_visit_id = selectedTimerVisitId.value;
    viewStatusForm.clearErrors();
    archiveError.value = "";

    viewStatusForm.patch(
        route("work-orders.status.update", selectedWorkOrder.value.id),
        {
            preserveScroll: true,
            onSuccess: () => {
                selectedWorkOrder.value!.status =
                    viewStatusForm.status as WorkOrderDetail["status"];
                selectedWorkOrder.value!.client_completion_summary =
                    viewStatusForm.client_completion_summary || null;
                window.dispatchEvent(new Event("fieldops:time-entry-timer-refresh"));
                void openExistingJob(selectedWorkOrder.value!.id);
            },
        },
    );
};

const saveVisitScope = (visitId: number) => {
    if (!selectedWorkOrder.value) return;

    router.patch(
        route("visits.scope.update", visitId),
        { scope: visitScopeDrafts[visitId] ?? "" },
        {
            preserveScroll: true,
            onSuccess: () => {
                const visit = selectedWorkOrder.value?.visits.find(
                    (item) => item.id === visitId,
                );
                if (visit) visit.scope = visitScopeDrafts[visitId] ?? "";
            },
        },
    );
};

const handleViewAttachmentFiles = (event: Event) => {
    const input = event.target as HTMLInputElement;
    viewAttachmentForm.files = input.files ? Array.from(input.files) : [];
};

const uploadViewAttachments = () => {
    if (!selectedWorkOrder.value || viewAttachmentForm.files.length === 0)
        return;

    viewAttachmentForm.post(
        route("work-orders.attachments.store", selectedWorkOrder.value.id),
        {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                viewAttachmentForm.reset();
                openExistingJob(selectedWorkOrder.value!.id);
            },
        },
    );
};

const beginCustomerEdit = () => {
    if (!selectedCustomer.value) return;

    clientForm.defaults({
        name: selectedCustomer.value.name,
        first_name: selectedCustomer.value.first_name ?? "",
        last_name: selectedCustomer.value.last_name ?? "",
        email: selectedCustomer.value.email ?? "",
        phone: selectedCustomer.value.phone ?? "",
        billing_address: addressFormValues(selectedCustomer.value.address_defaults.billing),
        shipping_address: addressFormValues(selectedCustomer.value.address_defaults.shipping),
        notes: selectedCustomer.value.notes ?? "",
    });
    clientForm.reset();
    clientForm.clearErrors();
    clientFormMode.value = "edit";
};

const cancelCustomerEdit = () => {
    clientForm.reset();
    clientForm.clearErrors();
    clientFormMode.value = "view";
};

const submitClient = () => {
    if (clientFormMode.value === "edit" && selectedCustomer.value) {
        clientForm.put(route("customers.update", selectedCustomer.value.id), {
            preserveScroll: true,
            onSuccess: () => {
                clientFormMode.value = "view";
                if (selectedCustomer.value) {
                    void openCustomer(selectedCustomer.value);
                }
            },
        });

        return;
    }

    clientForm.post(route("customers.store"), {
        preserveScroll: true,
        onSuccess: closeFab,
    });
};

const closeFab = () => {
    const shouldRestoreJobFocus = fabMode.value === "job" && jobPanelMode.value === "view";
    const returnFocusTarget = calendarJobPanelReturnFocus.value;
    quickBooksSyncPollGeneration += 1;
    fabMode.value = "default";
    selectedCustomer.value = null;
    quickBooksSyncRequestError.value = null;
    clientFormMode.value = "create";
    selectedWorkOrder.value = null;
    selectedProject.value = null;
    jobCustomer.value = null;
    archiveError.value = "";

    if (shouldRestoreJobFocus && returnFocusTarget?.isConnected) {
        void nextTick(() => returnFocusTarget.focus({ preventScroll: true }));
    }
};

const archiveBusinessRecord = async (
    type: "customers" | "projects" | "work-orders" | "visits",
    id: number,
    label: string,
): Promise<void> => {
    if (!page.props.auth.is_admin || archivingRecord.value) return;
    if (!window.confirm(`Archive ${label}? It can be restored from Archive.`)) return;

    archivingRecord.value = true;
    archiveError.value = "";

    try {
        await axios.post(route("admin.archives.archive", { type, record: id }), {
            reason: `Archived from the ${type.replaceAll('-', ' ')} details panel.`,
        });
        closeFab();
        router.reload({ only: ["initialData"] });
    } catch {
        archiveError.value = `Could not archive ${label}. Try again or contact an administrator.`;
    } finally {
        archivingRecord.value = false;
    }
};

onMounted(() => {
    document.addEventListener("click", closeFab);
    nextTick(scrollToBusinessHours);

    const query = new URLSearchParams(window.location.search);
    const requestedJob = Number(query.get("open_job"));
    if (Number.isSafeInteger(requestedJob) && requestedJob > 0) {
        void openExistingJob(requestedJob);
    } else {
        const requestedCustomer = Number(query.get("open_customer"));
        const requestedProject = Number(query.get("open_project"));

        if (Number.isSafeInteger(requestedCustomer) && requestedCustomer > 0) {
            void openCustomer({
                id: requestedCustomer,
                client_id: null,
                name: "",
                first_name: null,
                last_name: null,
                email: null,
                phone: null,
                address: null,
                notes: null,
            });
        } else if (Number.isSafeInteger(requestedProject) && requestedProject > 0) {
            void openProject(requestedProject);
        }
    }

});

onBeforeUnmount(() => {
    document.removeEventListener("click", closeFab);
});

const openGlobalResult = (result: GlobalSearchResult) => {
    if (result.type === "client") {
        openCustomer({
            id: result.id,
            client_id: result.reference,
            name: result.name,
            first_name: null,
            last_name: null,
            email: null,
            phone: null,
            address: null,
            notes: null,
        });

        return;
    }

    if (result.type === "job" || result.type === "visit") {
        openExistingJob(
            result.type === "visit" ? result.parent_id! : result.id,
            result.type === "visit" ? result.id : undefined,
        );

        return;
    }

    openProject(result.id);
};

const handleGlobalSearchResultSelected = (event: Event): void => {
    const result = (event as CustomEvent<GlobalSearchResult>).detail;

    if (result) openGlobalResult(result);
};

onMounted(() => {
    window.addEventListener(
        "fieldops:global-search-result-selected",
        handleGlobalSearchResultSelected,
    );
});

onBeforeUnmount(() => {
    window.removeEventListener(
        "fieldops:global-search-result-selected",
        handleGlobalSearchResultSelected,
    );
});

const openProject = async (projectId: number) => {
    const response = await fetch(route("projects.show", projectId), {
        headers: { Accept: "application/json" },
    });
    const payload = (await response.json()) as { data: ProjectDetail };

    selectedProject.value = payload.data;
    fabMode.value = "project";
};

const openCustomer = async (customer: CustomerSummary) => {
    quickBooksSyncPollGeneration += 1;
    const response = await fetch(route("customers.show", customer.id), {
        headers: { Accept: "application/json" },
    });
    const payload = (await response.json()) as {
        data: CustomerDetail;
        quickbooks_sync: CustomerQuickBooksSync;
        address_defaults: CustomerAddressDefaults;
    };

    selectedCustomer.value = {
        ...payload.data,
        quickbooks_sync: payload.quickbooks_sync,
        address_defaults: payload.address_defaults,
    };
    clientFormMode.value = "view";
    fabMode.value = "client";
    quickBooksSyncRequestError.value = null;

    if (payload.quickbooks_sync.status === "pending") {
        void pollQuickBooksCustomer(payload.data.id, "selected");
    }
};

const currentCustomerForTarget = (
    target: "selected" | "job",
): CustomerDetail | null =>
    target === "selected" ? selectedCustomer.value : jobCustomer.value;

const updateCustomerForTarget = (
    target: "selected" | "job",
    customer: CustomerDetail,
): void => {
    if (target === "selected") {
        selectedCustomer.value = customer;
    } else {
        jobCustomer.value = customer;
        if (jobForm.site_id === null && customer.sites.length > 0) {
            jobForm.site_id = customer.sites[0].id;
        }
    }
};

const pollQuickBooksCustomer = async (
    customerId: number,
    target: "selected" | "job",
): Promise<void> => {
    const generation = ++quickBooksSyncPollGeneration;

    for (let attempt = 0; attempt < 60; attempt += 1) {
        await new Promise<void>((resolve) => window.setTimeout(resolve, 2000));

        if (
            generation !== quickBooksSyncPollGeneration ||
            currentCustomerForTarget(target)?.id !== customerId
        ) {
            return;
        }

        try {
            const response = await window.axios.get(
                route("customers.show", customerId),
            );
            const payload = response.data as {
                data: CustomerDetail;
                quickbooks_sync: CustomerQuickBooksSync;
                address_defaults: CustomerAddressDefaults;
            };
            const refreshedCustomer: CustomerDetail = {
                ...payload.data,
                quickbooks_sync: payload.quickbooks_sync,
                address_defaults: payload.address_defaults,
            };

            updateCustomerForTarget(target, refreshedCustomer);

            if (payload.quickbooks_sync.status !== "pending") {
                return;
            }
        } catch {
            return;
        }
    }
};

const checkQuickBooksForUpdates = async (): Promise<void> => {
    const customer = selectedCustomer.value;

    if (! customer?.quickbooks_sync.linked || quickBooksSyncRequestBusy.value) {
        return;
    }

    quickBooksSyncRequestBusy.value = true;
    quickBooksSyncRequestError.value = null;

    try {
        const response = await window.axios.post(
            route("customers.quickbooks.refresh", customer.id),
        );

        selectedCustomer.value = {
            ...customer,
            quickbooks_sync: {
                ...customer.quickbooks_sync,
                status: response.data.status,
                error: null,
            },
        };

        if (response.data.status === "pending") {
            void pollQuickBooksCustomer(customer.id, "selected");
        }
    } catch (error: unknown) {
        const responseMessage = (error as {
            response?: { data?: { message?: string } };
        }).response?.data?.message;
        quickBooksSyncRequestError.value = responseMessage ??
            "Could not check QuickBooks for updates. Try again later.";
    } finally {
        quickBooksSyncRequestBusy.value = false;
    }
};

const quickBooksSyncStatusLabel = (
    status: CustomerQuickBooksSync["status"],
    writePending = false,
): string => {
    switch (status) {
        case "pending":
            return "Syncing with QuickBooks…";
        case "synced":
            return "QuickBooks data is up to date.";
        case "failed":
            return writePending
                ? "The QuickBooks write needs administrator retry."
                : "The last QuickBooks sync did not complete.";
        case "not_connected":
            return "QuickBooks is not connected for this environment.";
        case "sync_paused":
            return "QuickBooks synchronization is paused by an Admin.";
        default:
            return "QuickBooks data has not been checked yet.";
    }
};

watch([currentView, navigationLabel], () => {
    nextTick(scrollToBusinessHours);
});

/**
 * Helper to get the title of a visit or its work order
 */
const getVisitTitle = (visit: Visit): string => {
    if (visit.title) return visit.title;
    const workOrder = getVisitWorkOrder(visit);

    if (workOrder?.title) return workOrder.title;
    return "No Title";
};

const getVisitTime = (visit: Visit): string =>
    new Date(visit.scheduled_at).toLocaleTimeString(undefined, {
        hour: "numeric",
        minute: "2-digit",
    });

const formatCompactClockTime = (date: Date): string =>
    date.toLocaleTimeString(undefined, {
        hour: "numeric",
        minute: "2-digit",
    })
        .replace(/\s*([AP])\.?M\.?$/i, (_, period: string) => period.toLowerCase())
        .replace(/\s+/g, "");

const getCompactVisitTime = (visit: Visit): string =>
    formatCompactClockTime(new Date(visit.scheduled_at));

const getVisitRange = (start: Date, durationMinutes: number): string => {
    const end = new Date(start.getTime() + durationMinutes * 60_000);
    const timeOptions: Intl.DateTimeFormatOptions = {
        hour: "numeric",
        minute: "2-digit",
    };
    const startTime = start.toLocaleTimeString(undefined, timeOptions);
    const endTime = end.toLocaleTimeString(undefined, timeOptions);

    if (isSameDay(start, end)) return `${startTime} – ${endTime}`;

    const endDate = end.toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
    });

    return `${startTime} – ${endDate}, ${endTime}`;
};

const minimumResizableDurationMinutes = 15;
const maximumResizableDurationMinutes = 24 * 60;

const calculateVisitInterval = (
    visit: Visit,
    target: CalendarDropTarget,
    mode: VisitDragMode,
): VisitScheduleInterval => {
    const originalStart = new Date(visit.scheduled_at);
    const originalDuration = Math.max(1, visit.duration_minutes ?? 60);
    const originalEnd = new Date(
        originalStart.getTime() + originalDuration * 60_000,
    );
    const fallbackTime = mode === "resize-end" ? originalEnd : originalStart;
    const targetTime = new Date(target.date);
    const minuteOfDay =
        target.minuteOfDay ??
        fallbackTime.getHours() * 60 + fallbackTime.getMinutes();
    targetTime.setHours(
        Math.floor(minuteOfDay / 60),
        minuteOfDay % 60,
        0,
        0,
    );

    if (mode === "move") {
        return { scheduledAt: targetTime, durationMinutes: originalDuration };
    }

    if (mode === "resize-start") {
        const requestedDuration = Math.round(
            (originalEnd.getTime() - targetTime.getTime()) / (15 * 60_000),
        ) * 15;
        const durationMinutes = Math.max(
            minimumResizableDurationMinutes,
            Math.min(maximumResizableDurationMinutes, requestedDuration),
        );

        return {
            scheduledAt: new Date(originalEnd.getTime() - durationMinutes * 60_000),
            durationMinutes,
        };
    }

    const requestedDuration = Math.round(
        (targetTime.getTime() - originalStart.getTime()) / (15 * 60_000),
    ) * 15;
    const durationMinutes = Math.max(
        minimumResizableDurationMinutes,
        Math.min(maximumResizableDurationMinutes, requestedDuration),
    );

    return { scheduledAt: originalStart, durationMinutes };
};

const getVisitWorkOrder = (visit: Visit): WorkOrderSummary | null =>
    visit.workOrder ?? visit.work_order ?? null;

const getVisitStatus = (visit: Visit): string =>
    getVisitWorkOrder(visit)?.status ?? visit.status ?? "unscheduled";

const draggedVisitId = ref<number | null>(null);
const heldVisitId = ref<number | null>(null);
const visitDragStarted = ref(false);
const visitDragMode = ref<VisitDragMode>("move");
const activeVisitId = ref<number | null>(null);
const visitDragPreview = ref<VisitDragPreview | null>(null);
const monthDropPreviewPosition = ref<MonthDropPreviewPosition | null>(null);
const confirmVisitMoveButton = ref<HTMLButtonElement | null>(null);
const visitPointerOrigin = ref<{
    x: number;
    y: number;
    pointerType: string;
} | null>(null);
const suppressNextVisitClick = ref(false);
const pendingVisitMove = ref<PendingVisitMove | null>(null);
const cancelVisitMoveButton = ref<HTMLButtonElement | null>(null);
let visitHoldTimer: ReturnType<typeof setTimeout> | null = null;

const calendarSlotHours = Array.from({ length: 24 }, (_, index) => index);
const timeSlotScrollers = ref<HTMLElement[]>([]);
const keyboardTimeSlotByDate = reactive<Record<string, number>>({});

const firstAvailableKeyboardHour = (date: Date): number | null => {
    const daytimeHours = calendarSlotHours.filter((hour) => hour >= 8 && hour <= 17);
    const otherHours = calendarSlotHours.filter((hour) => hour < 8 || hour > 17);

    return [...daytimeHours, ...otherHours].find(
        (hour) => visitsForHour(date, hour).length === 0,
    ) ?? null;
};

const keyboardTimeSlotHour = (date: Date): number | null => {
    const dateKey = toDateKey(date);
    const current = keyboardTimeSlotByDate[dateKey];

    return current !== undefined && visitsForHour(date, current).length === 0
        ? current
        : firstAvailableKeyboardHour(date);
};

const isKeyboardTimeSlot = (date: Date, hour: number): boolean =>
    keyboardTimeSlotHour(date) === hour;

const handleTimeSlotKeydown = (
    event: KeyboardEvent,
    date: Date,
    hour: number,
): void => {
    if (event.key === "Enter" || event.key === " ") {
        event.preventDefault();
        handleTimeSlotClick(date, hour);

        return;
    }

    if (event.key !== "ArrowUp" && event.key !== "ArrowDown") return;

    const direction = event.key === "ArrowUp" ? -1 : 1;
    let nextHour = hour + direction;

    while (nextHour >= 0 && nextHour < 24) {
        if (visitsForHour(date, nextHour).length === 0) {
            const dateKey = toDateKey(date);
            keyboardTimeSlotByDate[dateKey] = nextHour;
            event.preventDefault();
            void nextTick(() => {
                const target = document.querySelector<HTMLElement>(
                    `.calendar-time-slot[data-calendar-date="${dateKey}"][data-calendar-slot-hour="${nextHour}"]`,
                );
                target?.focus();
                target?.scrollIntoView({ block: "nearest" });
            });

            return;
        }

        nextHour += direction;
    }
};

const registerTimeSlotScroller = (element: unknown): void => {
    if (
        element instanceof HTMLElement &&
        !timeSlotScrollers.value.includes(element)
    ) {
        timeSlotScrollers.value.push(element);
    } else if (element === null) {
        timeSlotScrollers.value = timeSlotScrollers.value.filter(
            (scroller) => scroller.isConnected,
        );
    }
};

const scrollToBusinessHours = (): void => {
    const businessHourOffset = 8 * (48 + 4);

    timeSlotScrollers.value.forEach((scroller) => {
        scroller.scrollTop = businessHourOffset;
    });
};

const formatSlotHour = (hour: number): string => {
    const date = new Date(2000, 0, 1, hour);

    return date.toLocaleTimeString(undefined, {
        hour: "numeric",
        minute: "2-digit",
    });
};

const formatCompactSlotHour = (hour: number): string =>
    formatSlotHour(hour)
        .replace(/:00/, "")
        .replace(/\s*([AP])\.?M\.?$/i, (_, period: string) => period.toLowerCase())
        .replace(/\s+/g, "");

const minuteWithinHourAt = (
    hourElement: HTMLElement,
    hour: number,
    clientY: number,
): number => {
    const rect = hourElement.getBoundingClientRect();
    const fraction = Math.max(
        0,
        Math.min(0.999999, (clientY - rect.top) / Math.max(1, rect.height)),
    );
    const quarter = Math.min(3, Math.floor(fraction * 4));

    return hour * 60 + quarter * 15;
};

const nearestCalendarHourElement = (
    container: HTMLElement,
    clientY: number,
): HTMLElement | null => {
    let nearest: HTMLElement | null = null;
    let nearestDistance = Number.POSITIVE_INFINITY;

    for (const element of Array.from(
        container.querySelectorAll<HTMLElement>("[data-calendar-slot-hour]"),
    )) {
        const rect = element.getBoundingClientRect();
        const distance =
            clientY < rect.top
                ? rect.top - clientY
                : clientY > rect.bottom
                  ? clientY - rect.bottom
                  : 0;

        if (distance < nearestDistance) {
            nearest = element;
            nearestDistance = distance;
        }
    }

    return nearest;
};

const updateMonthDropPreviewPosition = (
    element: Element | null,
    target: CalendarDropTarget | null,
): void => {
    const calendarCell = element?.closest<HTMLElement>(".calendar-cell");
    if (!calendarCell || !target) {
        monthDropPreviewPosition.value = null;
        return;
    }

    const rect = calendarCell.getBoundingClientRect();
    const width = Math.min(256, window.innerWidth - 16);
    const height = 112;
    let left = rect.right + 8;

    if (left + width > window.innerWidth - 8) {
        left = rect.left - width - 8;
    }

    monthDropPreviewPosition.value = {
        left: Math.max(8, Math.min(left, window.innerWidth - width - 8)),
        top: Math.max(
            8,
            Math.min(rect.top, window.innerHeight - height - 8),
        ),
    };
};

const resolveCalendarDropTarget = (
    clientX: number,
    clientY: number,
): CalendarDropTarget | null => {
    const element = document.elementFromPoint(clientX, clientY);
    const dateElement = element?.closest<HTMLElement>("[data-calendar-date]");
    const dateKey = dateElement?.dataset.calendarDate;

    if (!dateKey) return null;

    const [year, month, day] = dateKey.split("-").map(Number);
    if (![year, month, day].every(Number.isFinite)) return null;

    let minuteOfDay: number | null = null;
    const hourElement = element?.closest<HTMLElement>("[data-calendar-slot-hour]");
    const rawHour = hourElement?.dataset.calendarSlotHour;
    const hour = rawHour === undefined ? null : Number(rawHour);
    const timeScroller = element?.closest<HTMLElement>(".calendar-time-slots");

    if (hourElement && hour !== null && Number.isInteger(hour) && hour >= 0 && hour < 24) {
        minuteOfDay = minuteWithinHourAt(hourElement, hour, clientY);
    } else if (timeScroller) {
        const nearestHourElement = timeScroller
            ? nearestCalendarHourElement(timeScroller, clientY)
            : null;

        if (!nearestHourElement) return null;

        const nearestHour = Number(nearestHourElement.dataset.calendarSlotHour);
        minuteOfDay = minuteWithinHourAt(
            nearestHourElement,
            nearestHour,
            clientY,
        );
    } else {
        const calendarCell = element?.closest<HTMLElement>(".calendar-cell");

        if (calendarCell) {
            const rect = calendarCell.getBoundingClientRect();
            const timelineTop = Math.min(rect.bottom, rect.top + 40);
            const fraction = Math.max(
                0,
                Math.min(
                    0.999999,
                    (clientY - timelineTop) /
                        Math.max(1, rect.bottom - timelineTop),
                ),
            );
            minuteOfDay = Math.min(1425, Math.round(fraction * 96) * 15);
        }
    }

    return {
        date: new Date(year, month - 1, day),
        dateKey,
        minuteOfDay,
    };
};

const updateVisitDragPreview = (
    visit: Visit,
    clientX: number,
    clientY: number,
): void => {
    const target = resolveCalendarDropTarget(clientX, clientY);
    const element = document.elementFromPoint(clientX, clientY);
    updateMonthDropPreviewPosition(element, target);
    const interval = target
        ? calculateVisitInterval(visit, target, visitDragMode.value)
        : {
              scheduledAt: new Date(visit.scheduled_at),
              durationMinutes: Math.max(1, visit.duration_minutes ?? 60),
          };

    const previewWidth = 248;
    const previewHeight = 92;

    visitDragPreview.value = {
        visitId: visit.id,
        title: getVisitTitle(visit),
        mode: visitDragMode.value,
        durationMinutes: interval.durationMinutes,
        dateKey: target?.dateKey ?? null,
        minuteOfDay: target?.minuteOfDay ?? null,
        dateLabel: target
            ? target.date.toLocaleDateString(undefined, {
                  weekday: "short",
                  month: "short",
                  day: "numeric",
                  year: "numeric",
              })
            : "Move over a calendar day",
        timeLabel:
            getVisitRange(interval.scheduledAt, interval.durationMinutes),
        left: Math.max(
            8,
            Math.min(clientX + 14, window.innerWidth - previewWidth - 8),
        ),
        top: Math.max(
            8,
            Math.min(clientY + 14, window.innerHeight - previewHeight - 8),
        ),
        hasTarget: target !== null,
    };
};

const startVisitDrag = (
    visit: Visit,
    clientX: number,
    clientY: number,
): void => {
    heldVisitId.value = visit.id;
    draggedVisitId.value = visit.id;
    visitDragStarted.value = true;
    updateVisitDragPreview(visit, clientX, clientY);
};

const beginVisitHold = (
    event: PointerEvent,
    visitId: number,
    mode: VisitDragMode = "move",
): void => {
    if (visitHoldTimer) clearTimeout(visitHoldTimer);

    const visit = props.initialData.find((item) => item.id === visitId);
    if (!visit) return;

    activeVisitId.value = visitId;
    heldVisitId.value = null;
    draggedVisitId.value = null;
    visitDragStarted.value = false;
    visitDragMode.value = mode;
    visitDragPreview.value = null;
    monthDropPreviewPosition.value = null;
    suppressNextVisitClick.value = false;
    visitPointerOrigin.value = {
        x: event.clientX,
        y: event.clientY,
        pointerType: event.pointerType,
    };
    if (event.currentTarget instanceof HTMLElement) {
        event.currentTarget.setPointerCapture(event.pointerId);
    }

    window.addEventListener("pointermove", handleGlobalVisitPointerMove, {
        passive: false,
    });
    window.addEventListener("pointerup", handleGlobalVisitPointerUp);
    window.addEventListener("pointercancel", handleGlobalVisitPointerCancel);

    if (event.pointerType === "touch") {
        const { clientX, clientY } = event;
        visitHoldTimer = setTimeout(() => {
            visitHoldTimer = null;
            if (activeVisitId.value === visitId) {
                startVisitDrag(visit, clientX, clientY);
            }
        }, 500);
    }
};

const endVisitHold = (): void => {
    if (visitHoldTimer) {
        clearTimeout(visitHoldTimer);
        visitHoldTimer = null;
    }

    heldVisitId.value = null;
    activeVisitId.value = null;
    visitPointerOrigin.value = null;
    window.removeEventListener("pointermove", handleGlobalVisitPointerMove);
    window.removeEventListener("pointerup", handleGlobalVisitPointerUp);
    window.removeEventListener("pointercancel", handleGlobalVisitPointerCancel);
};

const releaseVisitPointer = (): void => {
    endVisitHold();
    draggedVisitId.value = null;
    visitDragStarted.value = false;
    visitDragPreview.value = null;
    monthDropPreviewPosition.value = null;
};

const handleGlobalVisitPointerMove = (event: PointerEvent): void => {
    if (activeVisitId.value === null) return;
    handleVisitPointerMove(event);
};

const handleGlobalVisitPointerUp = (event: PointerEvent): void => {
    if (activeVisitId.value === null) return;

    const visit = props.initialData.find(
        (item) => item.id === activeVisitId.value,
    );

    if (visit) handleVisitPointerUp(event, visit);
    else releaseVisitPointer();
};

const handleGlobalVisitPointerCancel = (): void => {
    releaseVisitPointer();
};

const handleVisitPointerMove = (event: PointerEvent): void => {
    const activeId = activeVisitId.value;
    if (activeId === null) return;

    const visit = props.initialData.find((item) => item.id === activeId);
    if (!visit) return;

    const origin = visitPointerOrigin.value;
    if (!visitDragStarted.value && origin) {
        const distance = Math.hypot(
            event.clientX - origin.x,
            event.clientY - origin.y,
        );

        if (origin.pointerType === "touch") {
            if (distance > 12 && visitHoldTimer) {
                clearTimeout(visitHoldTimer);
                visitHoldTimer = null;
            }
            if (!visitDragStarted.value) return;
        } else {
            if (distance < 5) return;
            startVisitDrag(visit, event.clientX, event.clientY);
        }
    }

    if (!visitDragStarted.value) return;

    updateVisitDragPreview(visit, event.clientX, event.clientY);
    event.preventDefault();
};

const handleVisitPointerUp = (event: PointerEvent, visit: Visit): void => {
    const wasDragging =
        visitDragStarted.value && activeVisitId.value === visit.id;
    const target = wasDragging
        ? resolveCalendarDropTarget(event.clientX, event.clientY)
        : null;
    const interval =
        target && wasDragging
            ? calculateVisitInterval(visit, target, visitDragMode.value)
            : null;

    if (import.meta.env.DEV) {
        console.debug("[calendar] job pointerup", {
            visitId: visit.id,
            workOrderId: getVisitWorkOrder(visit)?.id,
            dragging: wasDragging,
            mode: visitDragMode.value,
            dropDate: target?.dateKey,
            dropTime: target?.minuteOfDay,
        });
    }

    event.stopPropagation();
    endVisitHold();

    if (!wasDragging) {
        draggedVisitId.value = null;
        visitDragStarted.value = false;
        visitDragPreview.value = null;
        monthDropPreviewPosition.value = null;
        return;
    }

    suppressNextVisitClick.value = true;
    if (
        interval &&
        (interval.scheduledAt.getTime() !== new Date(visit.scheduled_at).getTime() ||
            interval.durationMinutes !== (visit.duration_minutes ?? 60))
    ) {
        requestVisitMoveConfirmation(
            visit,
            interval.scheduledAt,
            interval.durationMinutes,
            visitDragMode.value,
        );
    }

    draggedVisitId.value = null;
    visitDragStarted.value = false;
    visitDragPreview.value = null;
    monthDropPreviewPosition.value = null;
    event.preventDefault();
};

const handleVisitElementPointerUp = (
    event: PointerEvent,
    visit: Visit,
): void => {
    if (activeVisitId.value !== visit.id) return;
    handleVisitPointerUp(event, visit);
};

const handleVisitElementPointerCancel = (
    event: PointerEvent,
    visit: Visit,
): void => {
    if (activeVisitId.value !== visit.id) return;
    event.stopPropagation();
    releaseVisitPointer();
};

const localDateTimeValue = (date: Date): string => {
    const pad = (value: number): string => String(value).padStart(2, "0");

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const handleVisitDragStart = (event: DragEvent, visit: Visit): void => {
    if (heldVisitId.value !== visit.id) {
        event.preventDefault();

        return;
    }

    draggedVisitId.value = visit.id;
    visitDragStarted.value = true;
    event.dataTransfer?.setData("text/plain", String(visit.id));
    if (event.dataTransfer) event.dataTransfer.effectAllowed = "move";
};

const handleVisitDragEnd = (): void => {
    draggedVisitId.value = null;
    heldVisitId.value = null;
    endVisitHold();
    visitDragStarted.value = false;
    visitDragPreview.value = null;
    monthDropPreviewPosition.value = null;
};

const handleVisitClick = (visit: Visit): void => {
    if (suppressNextVisitClick.value) {
        suppressNextVisitClick.value = false;
        return;
    }

    if (import.meta.env.DEV) {
        console.debug("[calendar] job click", {
            visitId: visit.id,
            workOrderId: getVisitWorkOrder(visit)?.id,
            dragging: visitDragStarted.value,
        });
    }

    if (visitDragStarted.value) {
        visitDragStarted.value = false;

        return;
    }

    const workOrder = getVisitWorkOrder(visit);

    if (workOrder) {
        selectedDate.value = startOfDay(new Date(visit.scheduled_at));
        openExistingJob(workOrder.id, visit.id);
    }
};

const handleCalendarDrop = (event: DragEvent, date: Date): void => {
    event.preventDefault();
    const visitId = Number(
        event.dataTransfer?.getData("text/plain") || draggedVisitId.value,
    );
    const visit = props.initialData.find((item) => item.id === visitId);

    if (!visit) return;

    const scheduledAt = new Date(visit.scheduled_at);
    scheduledAt.setFullYear(
        date.getFullYear(),
        date.getMonth(),
        date.getDate(),
    );
    requestVisitMoveConfirmation(
        visit,
        scheduledAt,
        Math.max(1, visit.duration_minutes ?? 60),
    );
    draggedVisitId.value = null;
};

const rescheduleVisit = (
    visitId: number,
    scheduledAt: string,
    durationMinutes: number,
    schedulingOverride = false,
): void => {
    router.put(
        route("visits.schedule", visitId),
        {
            scheduled_at: scheduledAt,
            duration_minutes: durationMinutes,
            scheduling_override: schedulingOverride,
        },
        {
            preserveScroll: true,
            onError: (errors) => {
                const conflict = errors.scheduled_at;

                if (
                    typeof conflict === "string" &&
                    conflict.includes("overlaps")
                ) {
                    const suggestion = conflict.match(
                        /Suggested time: (\d{4}-\d{2}-\d{2} \d{2}:\d{2})\./,
                    )?.[1];

                    if (suggestion) {
                        const suggestedDateTime = `${suggestion.replace(" ", "T")}:00.000Z`;
                        const acceptSuggestion = window.confirm(
                            `${conflict}\n\nMove this visit to the suggested time?`,
                        );

                        if (acceptSuggestion) {
                            rescheduleVisit(
                                visitId,
                                suggestedDateTime,
                                durationMinutes,
                                true,
                            );

                            return;
                        }
                    }

                    if (
                        window.confirm("Keep the overlapping schedule anyway?")
                    ) {
                        rescheduleVisit(
                            visitId,
                            scheduledAt,
                            durationMinutes,
                            true,
                        );
                    }
                }
            },
        },
    );
};

const formatVisitScheduleLabel = (
    scheduledAt: Date,
    durationMinutes: number,
): string =>
    `${scheduledAt.toLocaleDateString(undefined, {
        weekday: "short",
        month: "short",
        day: "numeric",
        year: "numeric",
    })} · ${getVisitRange(scheduledAt, durationMinutes)}`;

const requestVisitMoveConfirmation = (
    visit: Visit,
    scheduledAt: Date,
    durationMinutes: number,
    mode: VisitDragMode = "move",
): void => {
    pendingVisitMove.value = {
        visitId: visit.id,
        jobLabel: getVisitTitle(visit),
        mode,
        fromLabel: formatVisitScheduleLabel(
            new Date(visit.scheduled_at),
            Math.max(1, visit.duration_minutes ?? 60),
        ),
        toLabel: formatVisitScheduleLabel(scheduledAt, durationMinutes),
        scheduledAt: scheduledAt.toISOString(),
        durationMinutes,
    };
    void nextTick(() => cancelVisitMoveButton.value?.focus());
};

const adjustVisitEdgeByKeyboard = (
    visit: Visit,
    mode: "resize-start" | "resize-end",
    direction: -1 | 1,
): void => {
    const scheduledAt = new Date(visit.scheduled_at);
    const edge =
        mode === "resize-start"
            ? scheduledAt
            : new Date(
                  scheduledAt.getTime() +
                      Math.max(1, visit.duration_minutes ?? 60) * 60_000,
              );
    edge.setMinutes(edge.getMinutes() + direction * 15);

    const interval = calculateVisitInterval(
        visit,
        {
            date: startOfDay(edge),
            dateKey: toDateKey(edge),
            minuteOfDay: edge.getHours() * 60 + edge.getMinutes(),
        },
        mode,
    );

    if (
        interval.scheduledAt.getTime() !== scheduledAt.getTime() ||
        interval.durationMinutes !== (visit.duration_minutes ?? 60)
    ) {
        requestVisitMoveConfirmation(
            visit,
            interval.scheduledAt,
            interval.durationMinutes,
            mode,
        );
    }
};

const confirmVisitMove = (): void => {
    const pendingMove = pendingVisitMove.value;
    if (!pendingMove) return;

    pendingVisitMove.value = null;
    rescheduleVisit(
        pendingMove.visitId,
        pendingMove.scheduledAt,
        pendingMove.durationMinutes,
    );
};

const cancelVisitMove = (): void => {
    pendingVisitMove.value = null;
};

const handleVisitMoveDialogKeydown = (event: KeyboardEvent): void => {
    if (!pendingVisitMove.value) return;

    if (event.key === "Escape") {
        event.preventDefault();
        cancelVisitMove();
        return;
    }

    if (event.key === "Tab") {
        const cancelButton = cancelVisitMoveButton.value;
        const confirmButton = confirmVisitMoveButton.value;
        if (!cancelButton || !confirmButton) return;

        if (event.shiftKey && document.activeElement === cancelButton) {
            event.preventDefault();
            confirmButton.focus();
        } else if (!event.shiftKey && document.activeElement === confirmButton) {
            event.preventDefault();
            cancelButton.focus();
        }
    }
};

onMounted(() => {
    window.addEventListener("keydown", handleVisitMoveDialogKeydown);
});

onBeforeUnmount(() => {
    window.removeEventListener("keydown", handleVisitMoveDialogKeydown);
});

/**
 * Helper to determine status color class
 */
const getStatusClass = (status: string): string =>
    `calendar-status--${workOrderStatusColor(status)}`;

const mapUrl = (address: string): string =>
    `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`;

const navigateCalendar = (direction: -1 | 1) => {
    if (currentView.value === "month") {
        const nextDate = new Date(
            currentDate.value.getFullYear(),
            currentDate.value.getMonth() + direction,
            1,
        );
        currentDate.value = nextDate;
        selectedDate.value = new Date(nextDate);
        return;
    }

    const daysToMove = currentView.value === "week" ? 7 : 1;
    const nextDate = new Date(currentDate.value);
    nextDate.setDate(nextDate.getDate() + direction * daysToMove);
    currentDate.value = nextDate;
    selectedDate.value = new Date(nextDate);
};

const navigateToday = (): void => {
    const today = startOfDay(new Date());
    currentDate.value = today;
    selectedDate.value = new Date(today);
};

const { handleTouchStart, handleTouchEnd } = useSwipeNavigation({
    onSwipe: (direction) => {
        navigateCalendar(direction === "left" ? 1 : -1);
    },
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="Calendar" />

        <div class="fieldops-calendar">
        <!-- Header Section -->
        <header
            class="calendar-header relative mb-6 grid gap-4 sm:mb-8 lg:flex lg:items-center lg:justify-between"
        >
            <div
                class="min-w-0 text-center sm:flex-1 lg:absolute lg:left-1/2 lg:top-0 lg:w-max lg:-translate-x-1/2"
            >
                <h1 class="sr-only">Schedule</h1>
                <p class="sr-only">
                    {{ fullDateLabel }}
                </p>
            </div>

            <div
                class="calendar-view-controls mx-auto flex w-fit max-w-full items-stretch gap-1 rounded-lg border bg-white p-1 shadow-sm lg:mx-0 lg:items-center lg:gap-2"
            >
                <!-- View Toggles -->
                <button
                    @click="setCalendarView('month')"
                    :aria-pressed="currentView === 'month'"
                    :class="[
                        'calendar-view-button',
                        currentView === 'month'
                            ? 'calendar-view-button--active'
                            : 'calendar-view-button--inactive',
                    ]"
                >
                    Month
                </button>
                <button
                    @click="setCalendarView('week')"
                    :aria-pressed="currentView === 'week'"
                    :class="[
                        'calendar-view-button',
                        currentView === 'week'
                            ? 'calendar-view-button--active'
                            : 'calendar-view-button--inactive',
                    ]"
                >
                    Week
                </button>
                <button
                    @click="setCalendarView('day')"
                    :aria-pressed="currentView === 'day'"
                    :class="[
                        'calendar-view-button',
                        currentView === 'day'
                            ? 'calendar-view-button--active'
                            : 'calendar-view-button--inactive',
                    ]"
                >
                    Day
                </button>
            </div>

            <div
                class="calendar-navigation flex w-full items-center justify-center gap-1 lg:w-auto lg:gap-2"
                role="group"
                aria-label="Calendar date navigation"
            >
                <button
                    type="button"
                    @click="navigateCalendar(-1)"
                    class="calendar-navigation-button"
                    :aria-label="`Previous ${currentView}`"
                >
                    <ChevronLeftIcon class="h-5 w-5" aria-hidden="true" />
                </button>
                <span
                    class="calendar-navigation__label min-w-0 flex-1 whitespace-nowrap px-2 text-center text-sm font-semibold lg:w-44 lg:flex-none"
                    aria-live="polite"
                >
                    {{ navigationLabel }}
                </span>
                <button
                    type="button"
                    class="calendar-navigation-today"
                    @click="navigateToday"
                >
                    Today
                </button>
                <button
                    type="button"
                    @click="navigateCalendar(1)"
                    class="calendar-navigation-button"
                    :aria-label="`Next ${currentView}`"
                >
                    <ChevronRightIcon class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
        </header>

        <!-- Calendar Content -->
        <section
            class="calendar-content fieldops-swipe-surface overflow-hidden rounded-lg border bg-white shadow-sm"
            :class="{ 'calendar-content--week': currentView === 'week' }"
            aria-label="Calendar"
            @touchstart="handleTouchStart"
            @touchend="handleTouchEnd"
        >
            <Transition
                name="calendar-view"
                mode="out-in"
                @after-enter="scrollToBusinessHours"
            >
                <div :key="currentView + '-' + navigationLabel">
                    <div
                        v-if="currentView === 'month'"
                        class="p-0 sm:p-4 md:p-6"
                    >
                        <div class="calendar-month">
                            <div class="calendar-weekdays" aria-hidden="true">
                                <div
                                    v-for="weekday in weekdayNames"
                                    :key="weekday"
                                    class="calendar-weekday"
                                >
                                    {{ weekday }}
                                </div>
                            </div>

                            <div class="calendar-month-grid">
                                <div
                                    v-for="day in calendarDays"
                                    :key="day.dateKey"
                                    :data-calendar-date="day.dateKey"
                                    class="calendar-cell"
                                    :class="{
                                        'calendar-cell--outside': !day.isCurrentMonth,
                                        'calendar-cell--selected': day.isSelected,
                                        'calendar-cell--today': day.isToday,
                                        'calendar-cell--drop-target': visitDragPreview?.hasTarget && visitDragPreview.dateKey === day.dateKey,
                                    }"
                                    role="group"
                                    :aria-label="day.date.toLocaleDateString()"
                                    @dragover.prevent
                                    @drop="handleCalendarDrop($event, day.date)"
                                >
                                    <button
                                        type="button"
                                        class="calendar-cell-add"
                                        :aria-label="`Add a job on ${day.date.toLocaleDateString()}`"
                                        @click="openJobFormForDate(day.date)"
                                    >
                                        <span class="calendar-cell-add__top">
                                            <span
                                                class="calendar-cell-number"
                                                :class="{
                                                    'calendar-cell-number--today': day.isToday,
                                                    'calendar-cell-number--selected': day.isSelected,
                                                    'calendar-cell-number--outside': !day.isCurrentMonth,
                                                }"
                                            >
                                                {{ formatDayNumber(day.date) }}
                                            </span>
                                            <span
                                                v-if="day.isToday"
                                                class="calendar-cell-today-label"
                                            >
                                                Today
                                            </span>
                                        </span>
                                    </button>

                                    <div class="calendar-cell-events">
                                        <div
                                            v-for="visit in visitsForMonthDate(day.date)"
                                            :key="visit.id"
                                            :class="[
                                                'calendar-job calendar-job--month',
                                                getStatusClass(getVisitStatus(visit)),
                                            ]"
                                        >
                                            <button
                                                type="button"
                                                draggable="false"
                                                class="calendar-job__open calendar-job__open--month"
                                                :aria-label="`${getVisitMonthSegmentLabel(visit, day.date)} — ${getVisitTitle(visit)}. Status: ${workOrderStatusLabel(getVisitStatus(visit))}. Open job details.`"
                                                @pointerdown.stop="beginVisitHold($event, visit.id)"
                                                @pointermove.stop="handleVisitPointerMove"
                                                @pointerup="handleVisitElementPointerUp($event, visit)"
                                                @pointercancel="handleVisitElementPointerCancel($event, visit)"
                                                @click.stop="handleVisitClick(visit)"
                                            >
                                                <span class="calendar-job__time calendar-job__time--month-full">
                                                    {{ getVisitMonthSegmentLabel(visit, day.date) }}
                                                </span>
                                                <span class="calendar-job__time calendar-job__time--month-compact" aria-hidden="true">
                                                    {{ getCompactVisitMonthSegmentLabel(visit, day.date) }}
                                                </span>
                                                <span class="calendar-job__title">
                                                    {{ getVisitTitle(visit) }}
                                                </span>
                                            </button>
                                            <button
                                                v-if="visitStartsOnDate(visit, day.date)"
                                                type="button"
                                                class="calendar-job__resize calendar-job__resize--start"
                                                :aria-label="`Adjust start time for ${getVisitTitle(visit)}; use up or down arrow keys to adjust by 15 minutes.`"
                                                @pointerdown.stop="beginVisitHold($event, visit.id, 'resize-start')"
                                                @pointermove.stop="handleVisitPointerMove"
                                                @pointerup="handleVisitElementPointerUp($event, visit)"
                                                @pointercancel="handleVisitElementPointerCancel($event, visit)"
                                                @click.stop.prevent
                                                @keydown.up.prevent="adjustVisitEdgeByKeyboard(visit, 'resize-start', -1)"
                                                @keydown.down.prevent="adjustVisitEdgeByKeyboard(visit, 'resize-start', 1)"
                                            ></button>
                                            <button
                                                v-if="visitEndsOnDate(visit, day.date)"
                                                type="button"
                                                class="calendar-job__resize calendar-job__resize--end"
                                                :aria-label="`Adjust end time for ${getVisitTitle(visit)}; use up or down arrow keys to adjust by 15 minutes.`"
                                                @pointerdown.stop="beginVisitHold($event, visit.id, 'resize-end')"
                                                @pointermove.stop="handleVisitPointerMove"
                                                @pointerup="handleVisitElementPointerUp($event, visit)"
                                                @pointercancel="handleVisitElementPointerCancel($event, visit)"
                                                @click.stop.prevent
                                                @keydown.up.prevent="adjustVisitEdgeByKeyboard(visit, 'resize-end', -1)"
                                                @keydown.down.prevent="adjustVisitEdgeByKeyboard(visit, 'resize-end', 1)"
                                            ></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else-if="currentView === 'week'"
                        class="calendar-week-scroll min-w-0"
                        @touchstart.stop
                        @touchend.stop
                    >
                        <!-- Keep all seven days in the weekly schedule. -->
                        <div
                            class="min-w-0 rounded-lg border bg-white p-0 shadow-sm sm:p-4 md:p-6"
                        >
                            <div
                                class="calendar-week-grid grid min-w-0 grid-cols-7 gap-px sm:gap-2 md:gap-4"
                            >
                                <template
                                    v-for="day in weekDays"
                                    :key="day.dateKey"
                                >
                                    <div
                                        :data-calendar-date="day.dateKey"
                                        @dragover.prevent
                                        @drop="
                                            handleCalendarDrop($event, day.date)
                                        "
                                    >
                                        <h3
                                            class="mb-2 truncate border-b pb-1 text-center text-[10px] font-bold sm:text-xs md:text-sm"
                                        >
                                            {{
                                                day.date.toLocaleDateString(
                                                    undefined,
                                                    {
                                                        weekday: "short",
                                                        day: "numeric",
                                                    },
                                                )
                                            }}
                                        </h3>
                                        <!-- Time slots would go here -->
                                        <div
                                            class="relative min-h-[320px] min-w-0 rounded border bg-gray-50 p-0.5 sm:min-h-[420px] sm:p-1 md:min-h-[500px]"
                                        >
                                            <div
                                                :ref="registerTimeSlotScroller"
                                                class="calendar-time-slots max-h-[28rem] touch-pan-y overflow-y-auto space-y-1"
                                            >
                                                <div
                                                    v-for="hour in calendarSlotHours"
                                                    :key="hour"
                                                    :data-calendar-date="
                                                        day.dateKey
                                                    "
                                                    :data-calendar-slot-hour="
                                                        hour
                                                    "
                                                    class="calendar-time-slot h-12 w-full shrink-0 cursor-crosshair"
                                                    :role="isKeyboardTimeSlot(day.date, hour) ? 'button' : undefined"
                                                    :tabindex="isKeyboardTimeSlot(day.date, hour) ? 0 : undefined"
                                                    :aria-label="isKeyboardTimeSlot(day.date, hour) ? `Add a Job at ${formatSlotHour(hour)} on ${day.date.toLocaleDateString()}` : undefined"
                                                    :class="{
                                                        'calendar-time-slot--drop-target':
                                                            visitDragPreview?.hasTarget &&
                                                            visitDragPreview.dateKey === day.dateKey &&
                                                            Math.floor((visitDragPreview.minuteOfDay ?? -1) / 60) === hour,
                                                    }"
                                                    @click.stop="
                                                        handleTimeSlotClick(
                                                            day.date,
                                                            hour,
                                                        )
                                                    "
                                                    @keydown="handleTimeSlotKeydown($event, day.date, hour)"
                                                >
                                                    <span
                                                        class="calendar-time-slot-label"
                                                        v-if="
                                                            visitsForHour(
                                                                day.date,
                                                                hour,
                                                            ).length === 0
                                                        "
                                                    >
                                                        {{
                                                            formatCompactSlotHour(hour)
                                                        }}
                                                    </span>
                                                    <div
                                                        v-for="visit in visitsForHour(
                                                            day.date,
                                                            hour,
                                                        )"
                                                        :key="visit.id"
                                                        class="calendar-week-visit"
                                                        role="group"
                                                        :aria-label="`Job event. Status: ${workOrderStatusLabel(getVisitStatus(visit))}. Starts at ${getVisitTime(visit)}.`"
                                                        :style="{ minHeight: getVisitSlotHeight(visit) }"
                                                    >
                                                        <button
                                                            type="button"
                                                            draggable="false"
                                                            class="calendar-job calendar-job--week calendar-job__open calendar-job__open--week"
                                                            :class="getStatusClass(getVisitStatus(visit))"
                                                            :style="{ minHeight: getVisitSlotHeight(visit) }"
                                                            :aria-label="`Open ${getVisitTitle(visit)}. Status: ${workOrderStatusLabel(getVisitStatus(visit))}. Starts at ${getVisitTime(visit)}.`"
                                                            @pointerdown.stop="beginVisitHold($event, visit.id, 'move')"
                                                            @pointermove.stop="handleVisitPointerMove"
                                                            @pointerup="handleVisitElementPointerUp($event, visit)"
                                                            @pointercancel="handleVisitElementPointerCancel($event, visit)"
                                                            @click.stop="handleVisitClick(visit)"
                                                        >
                                                            <time class="calendar-week-start-time" :datetime="visit.scheduled_at">{{ getCompactVisitTime(visit) }}</time>
                                                        </button>
                                                        <button
                                                            v-if="visitStartsOnDate(visit, day.date)"
                                                            type="button"
                                                            class="calendar-job__resize calendar-job__resize--start"
                                                            :aria-label="`Adjust start time for ${getVisitTitle(visit)}. Status: ${workOrderStatusLabel(getVisitStatus(visit))}.`"
                                                            @pointerdown.stop="beginVisitHold($event, visit.id, 'resize-start')"
                                                            @pointermove.stop="handleVisitPointerMove"
                                                            @pointerup="handleVisitElementPointerUp($event, visit)"
                                                            @pointercancel="handleVisitElementPointerCancel($event, visit)"
                                                            @click.stop.prevent
                                                            @keydown.up.prevent="adjustVisitEdgeByKeyboard(visit, 'resize-start', -1)"
                                                            @keydown.down.prevent="adjustVisitEdgeByKeyboard(visit, 'resize-start', 1)"
                                                        ></button>
                                                        <button
                                                            v-if="visitEndsOnDate(visit, day.date)"
                                                            type="button"
                                                            class="calendar-job__resize calendar-job__resize--end"
                                                            :aria-label="`Adjust end time for ${getVisitTitle(visit)}. Status: ${workOrderStatusLabel(getVisitStatus(visit))}.`"
                                                            @pointerdown.stop="beginVisitHold($event, visit.id, 'resize-end')"
                                                            @pointermove.stop="handleVisitPointerMove"
                                                            @pointerup="handleVisitElementPointerUp($event, visit)"
                                                            @pointercancel="handleVisitElementPointerCancel($event, visit)"
                                                            @click.stop.prevent
                                                            @keydown.up.prevent="adjustVisitEdgeByKeyboard(visit, 'resize-end', -1)"
                                                            @keydown.down.prevent="adjustVisitEdgeByKeyboard(visit, 'resize-end', 1)"
                                                        ></button>
                                                    </div>
                                                </div>
                                            </div>
                                            <template
                                                v-for="visit in visitsForDate(
                                                    day.date,
                                                )"
                                                :key="visit.id"
                                            >
                                                <!-- Simple stacked representation for now -->
                                                <div
                                                    :class="[
                                                        'calendar-job mb-1 cursor-pointer truncate rounded border p-0.5 text-[9px] shadow-sm sm:mb-2 sm:p-1 sm:text-[10px]',
                                                        getStatusClass(
                                                            getVisitStatus(
                                                                visit,
                                                            ),
                                                        ),
                                                    ]"
                                                    v-if="false"
                                                    draggable="false"
                                                    @pointerdown.stop="
                                                        beginVisitHold(
                                                            $event,
                                                            visit.id,
                                                        )
                                                    "
                                                    @pointermove.stop="
                                                        handleVisitPointerMove
                                                    "
                                                    @pointerup="
                                                        handleVisitElementPointerUp(
                                                            $event,
                                                            visit,
                                                        )
                                                    "
                                                    @pointercancel="
                                                        handleVisitElementPointerCancel(
                                                            $event,
                                                            visit,
                                                        )
                                                    "
                                                    @dragstart="
                                                        handleVisitDragStart(
                                                            $event,
                                                            visit,
                                                        )
                                                    "
                                                    @dragend="
                                                        handleVisitDragEnd
                                                    "
                                                    @click.stop="
                                                        handleVisitClick(visit)
                                                    "
                                                >
                                                    <span
                                                        class="flex items-center justify-between gap-1"
                                                    >
                                                        <span
                                                            class="shrink-0 text-[9px] font-semibold opacity-80"
                                                            >{{
                                                                getVisitTime(
                                                                    visit,
                                                                )
                                                            }}</span
                                                        >
                                                        <span
                                                            class="min-w-0 truncate text-right font-bold"
                                                            >{{
                                                                getVisitTitle(
                                                                    visit,
                                                                )
                                                            }}</span
                                                        >
                                                    </span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div v-else class="p-2 sm:p-4 md:p-6">
                        <!-- Day View -->
                        <div
                            class="mx-auto max-w-2xl rounded border bg-white p-3 shadow sm:p-6"
                        >
                            <h3 class="text-lg font-bold mb-4 text-center">
                                {{ currentDate.toLocaleDateString() }}
                            </h3>
                            <div
                                v-if="
                                    visitsForDate(startOfDay(currentDate))
                                        .length === 0
                                "
                                class="text-gray-500 italic text-sm text-center py-20 border rounded bg-gray-50"
                            >
                                No visits scheduled for this day.
                            </div>
                            <div v-else>
                                <!-- Day View List -->
                                <ul class="space-y-4">
                                    <li
                                        v-for="visit in visitsForDate(
                                            startOfDay(currentDate),
                                        )"
                                        :key="visit.id"
                                    >
                                        <button
                                            type="button"
                                            class="calendar-day-visit"
                                            :disabled="!getVisitWorkOrder(visit)"
                                            :aria-label="`Open ${getVisitTitle(visit)}. Status: ${workOrderStatusLabel(getVisitStatus(visit))}. Starts at ${getVisitTime(visit)}.`"
                                            @click="
                                                getVisitWorkOrder(visit) &&
                                                openExistingJob(
                                                    getVisitWorkOrder(visit)!.id,
                                                    visit.id,
                                                )
                                            "
                                        >
                                            <span class="calendar-day-visit__details">
                                                <span class="calendar-day-visit__time">{{ getVisitTime(visit) }}</span>
                                                <span class="calendar-day-visit__title">{{ getVisitTitle(visit) }}</span>
                                                <span class="calendar-day-visit__customer">{{ getVisitWorkOrder(visit)?.site?.customer?.name || "No Site" }}</span>
                                            </span>
                                            <span :class="['calendar-day-visit__status', getStatusClass(getVisitStatus(visit))]">
                                                {{ workOrderStatusLabel(getVisitStatus(visit)) }}
                                            </span>
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </Transition>
        </section>

        <div
            v-if="visitDragStarted && monthDropPreviewPosition && visitDragPreview"
            class="calendar-month-drop-preview"
            role="status"
            aria-live="polite"
            :aria-label="`Drop preview: ${visitDragPreview?.title}, ${visitDragPreview?.dateLabel}, ${visitDragPreview?.timeLabel}`"
            :style="{
                left: `${monthDropPreviewPosition.left}px`,
                top: `${monthDropPreviewPosition.top}px`,
            }"
        >
            <header class="calendar-month-drop-preview__header">
                <span class="calendar-month-drop-preview__eyebrow">Drop preview</span>
                <strong>{{ visitDragPreview?.dateLabel }}</strong>
                <span class="calendar-month-drop-preview__time">{{ visitDragPreview?.timeLabel }}</span>
                <span class="calendar-month-drop-preview__job">
                    {{ visitDragPreview?.title }}
                </span>
            </header>
        </div>

        <div
            v-if="visitDragPreview && visitDragStarted && !monthDropPreviewPosition"
            class="calendar-drag-preview"
            role="status"
            aria-live="polite"
            :style="{
                left: `${visitDragPreview.left}px`,
                top: `${visitDragPreview.top}px`,
            }"
        >
            <span class="calendar-drag-preview__eyebrow">
                {{
                    visitDragPreview.mode === 'move'
                        ? 'Moving visit'
                        : visitDragPreview.mode === 'resize-start'
                          ? 'Adjusting visit start'
                          : 'Adjusting visit end'
                }}
            </span>
            <strong class="calendar-drag-preview__title">
                {{ visitDragPreview.title }}
            </strong>
            <span v-if="visitDragPreview.hasTarget">
                {{ visitDragPreview.dateLabel }} · {{ visitDragPreview.timeLabel }}
            </span>
            <span v-else>Move over a calendar day or time slot</span>
        </div>

        <div
            v-if="pendingVisitMove"
            class="calendar-drop-confirm-backdrop"
            @click.stop
            @click.self="cancelVisitMove"
        >
            <section
                class="calendar-drop-confirm"
                role="alertdialog"
                aria-modal="true"
                aria-labelledby="calendar-drop-confirm-title"
                aria-describedby="calendar-drop-confirm-description"
                @click.stop
            >
                <p class="calendar-drop-confirm__eyebrow">Confirm schedule change</p>
                <h2 id="calendar-drop-confirm-title">
                    {{ pendingVisitMove.mode === 'move' ? 'Move this visit?' : 'Adjust visit duration?' }}
                </h2>
                <p id="calendar-drop-confirm-description" class="calendar-drop-confirm__job">
                    {{ pendingVisitMove.jobLabel }}
                </p>

                <dl class="calendar-drop-confirm__schedule">
                    <div>
                        <dt>From</dt>
                        <dd>{{ pendingVisitMove.fromLabel }}</dd>
                    </div>
                    <div>
                        <dt>To</dt>
                        <dd>{{ pendingVisitMove.toLabel }}</dd>
                    </div>
                </dl>

                <div class="calendar-drop-confirm__actions">
                    <button
                        ref="cancelVisitMoveButton"
                        type="button"
                        class="calendar-drop-confirm__cancel inline-flex h-11 w-11 items-center justify-center p-0"
                        aria-label="Cancel visit move"
                        title="Cancel"
                        @click="cancelVisitMove"
                    >
                        <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                    </button>
                    <button
                        type="button"
                        ref="confirmVisitMoveButton"
                        class="calendar-drop-confirm__accept"
                        @click="confirmVisitMove"
                    >
                        {{
                            pendingVisitMove.mode === 'move'
                                ? 'Yes, move visit'
                                : 'Yes, adjust visit'
                        }}
                    </button>
                </div>
            </section>
        </div>

        <div
            class="fieldops-calendar-panels fixed bottom-4 right-4 z-50 flex flex-col items-end"
            :class="{ 'fieldops-calendar-panels--admin': $page.props.auth.is_admin }"
            @click.stop
        >
            <div
                v-if="fabMode === 'project' && selectedProject"
                class="fieldops-client-panel relative max-h-[88vh] w-[min(94vw,46rem)] overflow-y-auto rounded-lg p-4 pb-20 sm:p-6"
                role="dialog"
                :aria-label="`${selectedProject.name} project details`"
            >
                <div class="mb-5 flex items-center justify-between">
                    <button
                        type="button"
                        aria-label="Back"
                        class="fieldops-icon-button"
                        @click="closeFab"
                    >
                        <ArrowLeftIcon class="h-5 w-5" />
                    </button>
                    <div class="text-center">
                        <p
                            class="text-xs font-bold uppercase tracking-[0.2em] opacity-70"
                        >
                            Project
                        </p>
                        <h2 class="font-brand text-lg font-bold">
                            {{ selectedProject.name }}
                        </h2>
                    </div>
                    <span class="h-10 w-10" aria-hidden="true"></span>
                </div>
                <p v-if="archiveError" role="alert" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ archiveError }}</p>
                <div class="space-y-4 text-sm">
                    <div class="fieldops-client-id">
                        {{ selectedProject.project_number || "Project" }}
                    </div>
                    <div>
                        <div class="fieldops-client-label">Client</div>
                        <div>{{ selectedProject.customer.name }}</div>
                    </div>
                    <div>
                        <div class="fieldops-client-label">Sites</div>
                        <ul class="space-y-1">
                            <li
                                v-for="site in selectedProject.sites"
                                :key="site.id"
                                class="rounded-lg bg-white/55 p-2"
                            >
                                <strong>{{ site.name }}</strong
                                ><span
                                    v-if="site.address"
                                    class="ml-2 opacity-70"
                                    >{{ site.address }}</span
                                >
                            </li>
                        </ul>
                    </div>
                    <div>
                        <div class="fieldops-client-label">Jobs</div>
                        <ul class="space-y-1">
                            <li
                                v-for="job in selectedProject.work_orders"
                                :key="job.id"
                                class="flex flex-wrap items-center gap-2 rounded-lg bg-white/55 p-2"
                            >
                                <strong>{{ job.job_number }}</strong
                                ><span class="min-w-0 flex-1">{{ job.title }}</span>
                                <span class="fieldops-status-badge shrink-0" :class="workOrderStatusBadgeClass(job.status)">{{ workOrderStatusLabel(job.status) }}</span>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <div class="fieldops-client-label">Notes</div>
                        <div class="whitespace-pre-wrap">
                            {{ selectedProject.notes || "No notes" }}
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="fabMode === 'client'"
                class="fieldops-client-panel relative max-h-[88vh] w-[min(92vw,40rem)] overflow-y-auto rounded-lg p-4 sm:p-6"
                role="dialog"
                :aria-label="clientFormMode === 'create' ? 'New customer form' : `${selectedCustomer?.name ?? 'Customer'} details`"
            >
                <div class="mb-5 flex items-center justify-between">
                    <button
                        type="button"
                        aria-label="Back"
                        class="fieldops-icon-button"
                        @click="closeFab"
                    >
                        <ArrowLeftIcon class="h-5 w-5" />
                    </button>
                    <div class="text-center">
                        <p
                            class="text-xs font-bold uppercase tracking-[0.2em] opacity-70"
                        >
                            {{
                                clientFormMode === "create"
                                    ? "New Client"
                                    : "Client"
                            }}
                        </p>
                        <h2 class="text-lg font-bold">
                            {{
                                clientFormMode === "create"
                                    ? "Add client"
                                    : selectedCustomer?.name
                            }}
                        </h2>
                    </div>
                    <button
                        v-if="clientFormMode === 'view'"
                        type="button"
                        aria-label="Edit client"
                        class="fieldops-icon-button fieldops-edit-action"
                        @click="beginCustomerEdit"
                    >
                        <PencilIcon class="h-5 w-5" />
                    </button>
                    <span v-else class="h-10 w-10" aria-hidden="true"></span>
                </div>
                <p v-if="archiveError" role="alert" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ archiveError }}</p>

                <div
                    v-if="clientFormMode === 'view' && selectedCustomer"
                    class="space-y-4"
                >
                    <div class="fieldops-client-id">
                        {{ selectedCustomer.client_id }}
                    </div>
                    <dl class="grid gap-3 text-sm">
                        <div>
                            <dt
                                class="text-xs font-bold uppercase tracking-wide opacity-60"
                            >
                                Person name
                            </dt>
                            <dd>
                                {{
                                    [
                                        selectedCustomer.first_name,
                                        selectedCustomer.last_name,
                                    ]
                                        .filter(Boolean)
                                        .join(" ") || "Not provided"
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-xs font-bold uppercase tracking-wide opacity-60"
                            >
                                Email
                            </dt>
                            <dd>
                                {{ selectedCustomer.email || "Not provided" }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-xs font-bold uppercase tracking-wide opacity-60"
                            >
                                Phone
                            </dt>
                            <dd>
                                {{ selectedCustomer.phone || "Not provided" }}
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-xs font-bold uppercase tracking-wide opacity-60"
                            >
                                Address
                            </dt>
                            <dd>
                                <a
                                    v-if="selectedCustomer.address"
                                    :href="mapUrl(selectedCustomer.address)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="font-semibold text-[var(--fieldops-green-dark)] underline decoration-[var(--fieldops-border)] underline-offset-2 hover:text-[var(--fieldops-green)]"
                                >
                                    {{ selectedCustomer.address }}
                                </a>
                                <span v-else>Not provided</span>
                            </dd>
                        </div>
                        <div>
                            <dt
                                class="text-xs font-bold uppercase tracking-wide opacity-60"
                            >
                                Notes
                            </dt>
                            <dd class="whitespace-pre-wrap">
                                {{ selectedCustomer.notes || "No notes" }}
                            </dd>
                        </div>
                    </dl>
                    <section
                        v-if="
                            selectedCustomer.quickbooks_sync.linked ||
                            selectedCustomer.quickbooks_sync.status === 'pending' ||
                            selectedCustomer.quickbooks_sync.status === 'failed' ||
                            selectedCustomer.quickbooks_sync.status === 'sync_paused'
                        "
                        class="space-y-2 rounded-xl border border-fieldops-blue/20 bg-white/60 p-3 text-sm"
                        aria-live="polite"
                    >
                        <p class="font-semibold">
                            {{
                                quickBooksSyncStatusLabel(
                                    selectedCustomer.quickbooks_sync.status,
                                    selectedCustomer.quickbooks_sync.write_pending,
                                )
                            }}
                        </p>
                        <p
                            v-if="selectedCustomer.quickbooks_sync.last_synced_at"
                            class="text-xs opacity-70"
                        >
                            Last checked
                            {{
                                new Date(
                                    selectedCustomer.quickbooks_sync.last_synced_at,
                                ).toLocaleString()
                            }}
                        </p>
                        <p
                            v-if="selectedCustomer.quickbooks_sync.error"
                            role="alert"
                            class="text-xs text-red-800"
                        >
                            {{ selectedCustomer.quickbooks_sync.error }}
                        </p>
                        <p
                            v-if="quickBooksSyncRequestError"
                            role="alert"
                            class="text-xs text-red-800"
                        >
                            {{ quickBooksSyncRequestError }}
                        </p>
                        <p v-if="selectedCustomer.quickbooks_sync.status === 'sync_paused'" class="text-xs text-amber-900">
                            <Link :href="route('admin.integrations.quickbooks.show')" class="font-semibold underline">Manage QuickBooks sync</Link>
                        </p>
                        <button
                            v-if="
                                selectedCustomer.quickbooks_sync.linked &&
                                !selectedCustomer.quickbooks_sync.write_pending &&
                                selectedCustomer.quickbooks_sync.status !== 'sync_paused'
                            "
                            type="button"
                            class="fieldops-secondary-action text-xs"
                            :disabled="
                                quickBooksSyncRequestBusy ||
                                selectedCustomer.quickbooks_sync.status ===
                                    'pending'
                            "
                            @click="checkQuickBooksForUpdates"
                        >
                            {{
                                quickBooksSyncRequestBusy ||
                                selectedCustomer.quickbooks_sync.status ===
                                'pending'
                                    ? "Syncing…"
                                    : "Check QuickBooks for updates"
                            }}
                        </button>
                    </section>
                </div>

                <form v-else class="space-y-4" @submit.prevent="submitClient">
                    <p
                        v-if="quickBooksCustomerFormError"
                        role="alert"
                        class="fieldops-client-error"
                    >
                        {{ quickBooksCustomerFormError }}
                    </p>
                    <div
                        v-if="clientFormMode === 'edit'"
                        class="fieldops-client-id"
                    >
                        {{ selectedCustomer?.client_id }}
                    </div>
                    <div>
                        <label for="client-name" class="fieldops-client-label"
                            >Client name</label
                        >
                        <input
                            id="client-name"
                            v-model="clientForm.name"
                            class="fieldops-client-input"
                            required
                            autofocus
                        />
                        <p
                            v-if="clientForm.errors.name"
                            class="fieldops-client-error"
                        >
                            {{ clientForm.errors.name }}
                        </p>
                    </div>
                    <div>
                        <label
                            for="client-first-name"
                            class="fieldops-client-label"
                            >First name</label
                        >
                        <input
                            id="client-first-name"
                            v-model="clientForm.first_name"
                            class="fieldops-client-input"
                        />
                    </div>
                    <div>
                        <label
                            for="client-last-name"
                            class="fieldops-client-label"
                            >Last name</label
                        >
                        <input
                            id="client-last-name"
                            v-model="clientForm.last_name"
                            class="fieldops-client-input"
                        />
                    </div>
                    <div>
                        <label for="client-email" class="fieldops-client-label"
                            >Email</label
                        >
                        <input
                            id="client-email"
                            v-model="clientForm.email"
                            type="email"
                            class="fieldops-client-input"
                        />
                        <p
                            v-if="clientForm.errors.email"
                            class="fieldops-client-error"
                        >
                            {{ clientForm.errors.email }}
                        </p>
                    </div>
                    <div>
                        <label for="client-phone" class="fieldops-client-label"
                            >Phone</label
                        >
                        <input
                            id="client-phone"
                            v-model="clientForm.phone"
                            type="tel"
                            class="fieldops-client-input"
                        />
                        <p
                            v-if="clientForm.errors.phone"
                            class="fieldops-client-error"
                        >
                            {{ clientForm.errors.phone }}
                        </p>
                    </div>
                    <fieldset class="space-y-2 rounded-xl border border-fieldops-blue/20 p-3">
                        <legend class="fieldops-client-label px-1">Billing address</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="clientForm.billing_address.line1" class="fieldops-client-input" placeholder="Street address 1" aria-label="Billing street address 1" />
                            <input v-model="clientForm.billing_address.line2" class="fieldops-client-input" placeholder="Street address 2" aria-label="Billing street address 2" />
                            <input v-model="clientForm.billing_address.line3" class="fieldops-client-input" placeholder="Street address 3" aria-label="Billing street address 3" />
                            <input v-model="clientForm.billing_address.line4" class="fieldops-client-input" placeholder="Street address 4" aria-label="Billing street address 4" />
                            <input v-model="clientForm.billing_address.line5" class="fieldops-client-input" placeholder="Street address 5" aria-label="Billing street address 5" />
                            <input v-model="clientForm.billing_address.city" class="fieldops-client-input" placeholder="City" aria-label="Billing city" />
                            <input v-model="clientForm.billing_address.country_subdivision_code" class="fieldops-client-input" placeholder="State / province" aria-label="Billing state or province" />
                            <input v-model="clientForm.billing_address.postal_code" class="fieldops-client-input" placeholder="Postal code" aria-label="Billing postal code" />
                            <input v-model="clientForm.billing_address.country" class="fieldops-client-input sm:col-span-2" placeholder="Country" aria-label="Billing country" />
                        </div>
                        <p v-if="clientForm.errors['billing_address.line1']" class="fieldops-client-error">
                            {{ clientForm.errors['billing_address.line1'] }}
                        </p>
                    </fieldset>
                    <fieldset class="space-y-2 rounded-xl border border-fieldops-blue/20 p-3">
                        <legend class="fieldops-client-label px-1">Shipping address</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <input v-model="clientForm.shipping_address.line1" class="fieldops-client-input" placeholder="Street address 1" aria-label="Shipping street address 1" />
                            <input v-model="clientForm.shipping_address.line2" class="fieldops-client-input" placeholder="Street address 2" aria-label="Shipping street address 2" />
                            <input v-model="clientForm.shipping_address.line3" class="fieldops-client-input" placeholder="Street address 3" aria-label="Shipping street address 3" />
                            <input v-model="clientForm.shipping_address.line4" class="fieldops-client-input" placeholder="Street address 4" aria-label="Shipping street address 4" />
                            <input v-model="clientForm.shipping_address.line5" class="fieldops-client-input" placeholder="Street address 5" aria-label="Shipping street address 5" />
                            <input v-model="clientForm.shipping_address.city" class="fieldops-client-input" placeholder="City" aria-label="Shipping city" />
                            <input v-model="clientForm.shipping_address.country_subdivision_code" class="fieldops-client-input" placeholder="State / province" aria-label="Shipping state or province" />
                            <input v-model="clientForm.shipping_address.postal_code" class="fieldops-client-input" placeholder="Postal code" aria-label="Shipping postal code" />
                            <input v-model="clientForm.shipping_address.country" class="fieldops-client-input sm:col-span-2" placeholder="Country" aria-label="Shipping country" />
                        </div>
                        <p v-if="clientForm.errors['shipping_address.line1']" class="fieldops-client-error">
                            {{ clientForm.errors['shipping_address.line1'] }}
                        </p>
                    </fieldset>
                    <div>
                        <label for="client-notes" class="fieldops-client-label"
                            >Notes</label
                        >
                        <textarea
                            id="client-notes"
                            v-model="clientForm.notes"
                            rows="4"
                            class="fieldops-client-input"
                        ></textarea>
                        <p
                            v-if="clientForm.errors.notes"
                            class="fieldops-client-error"
                        >
                            {{ clientForm.errors.notes }}
                        </p>
                    </div>
                    <div class="mt-6 flex justify-end gap-2 border-t border-fieldops-blue/10 pt-4">
                        <button
                            v-if="clientFormMode === 'edit'"
                            type="button"
                            class="fieldops-secondary-action inline-flex h-10 w-10 items-center justify-center p-0"
                            aria-label="Cancel customer edit"
                            title="Cancel"
                            @click="cancelCustomerEdit"
                        >
                            <XMarkIcon class="h-5 w-5" aria-hidden="true" />
                        </button>
                        <button
                            type="submit"
                            class="fieldops-submit-action"
                            :class="{ 'fieldops-add-action': clientFormMode === 'create' }"
                            :disabled="clientForm.processing"
                        >
                            {{
                                clientForm.processing
                                    ? "Saving..."
                                    : clientFormMode === "edit"
                                      ? "Save"
                                      : "Submit"
                            }}
                        </button>
                    </div>
                </form>
                <button
                    v-if="selectedCustomer && clientFormMode === 'edit'"
                    type="button"
                    class="fieldops-delete-action mt-4"
                    :disabled="!page.props.auth.is_admin || archivingRecord"
                    @click="archiveBusinessRecord('customers', selectedCustomer.id, `customer ${selectedCustomer.name}`)"
                >
                    {{ archivingRecord ? "Archiving…" : "Delete" }}
                </button>
            </div>

            <div
                v-else-if="fabMode === 'job'"
                class="fieldops-client-panel relative max-h-[88vh] w-[min(94vw,46rem)] overflow-y-auto rounded-lg p-4 pb-20 sm:p-6"
                role="dialog"
                :aria-label="jobPanelMode === 'view' ? `Job ${selectedWorkOrder?.job_number ?? ''} details` : jobFormMode === 'edit' ? 'Edit Job' : 'New Job form'"
            >
                <div class="mb-5 flex items-center justify-between">
                    <button
                        type="button"
                        aria-label="Back"
                        class="fieldops-icon-button"
                        @click="closeFab"
                    >
                        <ArrowLeftIcon class="h-5 w-5" />
                    </button>
                    <div class="text-center">
                        <p
                            class="text-xs font-bold uppercase tracking-[0.2em] opacity-70"
                        >
                            {{
                                jobPanelMode === "view"
                                    ? "Job"
                                    : jobFormMode === "edit"
                                      ? "Edit Job"
                                      : "New Job"
                            }}
                        </p>
                        <h2
                            class="font-brand text-lg font-bold"
                            :class="{
                                'font-mono': jobPanelMode === 'view' && selectedWorkOrder?.job_number,
                            }"
                        >
                            {{ selectedWorkOrder?.job_number || "Add job" }}
                        </h2>
                    </div>
                    <button
                        v-if="jobPanelMode === 'view'"
                        type="button"
                        aria-label="Edit job"
                        class="fieldops-icon-button fieldops-edit-action"
                        @click="beginJobEdit"
                    >
                        <PencilIcon class="h-5 w-5" />
                    </button>
                    <span v-else class="h-10 w-10" aria-hidden="true"></span>
                </div>
                <p v-if="archiveError" role="alert" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800">{{ archiveError }}</p>

                <div
                    v-if="jobPanelMode === 'view' && selectedWorkOrder"
                    ref="calendarJobPanelElement"
                    tabindex="-1"
                    :aria-label="`Job ${selectedWorkOrder.job_number} details`"
                    class="calendar-job-panel-region space-y-4 text-sm"
                >
                    <div class="fieldops-client-id">
                        {{ selectedWorkOrder.job_number }}
                    </div>
                    <div>
                        <div class="fieldops-client-label">Title</div>
                        <div class="text-base font-bold">
                            {{ selectedWorkOrder.title }}
                        </div>
                        <div
                            v-if="selectedWorkOrder.subtitle"
                            class="mt-1 opacity-75"
                        >
                            {{ selectedWorkOrder.subtitle }}
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <div class="mb-2 flex items-center gap-2">
                                <div class="fieldops-client-label">Status</div>
                                <span class="fieldops-status-badge" :class="workOrderStatusBadgeClass(selectedWorkOrder.status)">{{ workOrderStatusLabel(selectedWorkOrder.status) }}</span>
                            </div>
                            <select
                                v-model="viewStatusForm.status"
                                class="fieldops-client-input"
                                :disabled="viewStatusForm.processing"
                                @change="handleJobStatusSelection"
                            >
                                <option v-for="status in calendarSelectableWorkOrderStatuses" :key="status.value" :value="status.value">
                                    {{ status.label }}
                                </option>
                            </select>
                            <p
                                v-if="viewStatusForm.errors.status"
                                class="fieldops-client-error"
                            >
                                {{ viewStatusForm.errors.status }}
                            </p>
                            <p v-if="timerStartConflictError(viewStatusForm.errors)" class="fieldops-client-error">
                                {{ timerStartConflictError(viewStatusForm.errors) }}
                            </p>
                            <p v-if="viewStatusForm.errors.timer_visit_id" class="fieldops-client-error">
                                {{ viewStatusForm.errors.timer_visit_id }}
                            </p>
                            <div v-if="isPendingReasonRequired(viewStatusForm.status) && viewStatusForm.status !== selectedWorkOrder.status" class="mt-3 grid gap-2">
                                <label for="pending-status-reason" class="fieldops-client-label">Reason for Pending (required)</label>
                                <textarea id="pending-status-reason" v-model="viewStatusForm.pending_reason" rows="2" class="fieldops-client-input" required maxlength="2000"></textarea>
                                <p v-if="viewStatusForm.errors.pending_reason" class="fieldops-client-error">{{ viewStatusForm.errors.pending_reason }}</p>
                                <button type="button" class="fieldops-submit-action" :disabled="viewStatusForm.processing || !viewStatusForm.pending_reason.trim()" @click="updateJobStatus()">Move to Pending</button>
                            </div>
                            <div v-if="viewStatusForm.status === 'scheduled' && viewStatusForm.status !== selectedWorkOrder.status && selectedWorkOrder.visits.length === 0" class="mt-3 grid gap-2">
                                <label for="status-scheduled-at" class="fieldops-client-label">Scheduled date and time (required)</label>
                                <input id="status-scheduled-at" v-model="viewStatusForm.scheduled_at" type="datetime-local" step="900" class="fieldops-client-input fieldops-datetime-input" required @input="viewStatusForm.scheduling_override = false" />
                                <p v-if="viewStatusForm.errors.scheduled_at" class="fieldops-client-error">{{ viewStatusForm.errors.scheduled_at }}</p>
                                <button v-if="viewStatusForm.errors.scheduled_at?.includes('overlaps')" type="button" class="fieldops-secondary-action" @click="viewStatusForm.scheduling_override = true; updateJobStatus()">Proceed anyway</button>
                                <button type="button" class="fieldops-submit-action" :disabled="viewStatusForm.processing || !viewStatusForm.scheduled_at" @click="updateJobStatus()">Schedule job</button>
                            </div>
                            <div
                                v-if="isClientCompletionSummaryRequired(viewStatusForm.status) && !isClientCompletionSummaryRequired(selectedWorkOrder.status)"
                                class="mt-3 grid gap-2"
                            >
                                <label for="client-completion-summary" class="fieldops-client-label">
                                    Client-facing completion summary
                                </label>
                                <textarea
                                    id="client-completion-summary"
                                    v-model="viewStatusForm.client_completion_summary"
                                    rows="3"
                                    class="fieldops-client-input"
                                    required
                                    placeholder="Describe the result in nontechnical language."
                                ></textarea>
                                <button
                                    type="button"
                                    class="fieldops-submit-action"
                                    :disabled="viewStatusForm.processing || !viewStatusForm.client_completion_summary.trim()"
                                    @click="updateJobStatus()"
                                >
                                    Complete job
                                </button>
                            </div>
                        </div>
                        <div>
                            <div class="fieldops-client-label">Scheduled</div>
                            <div>
                                {{
                                    selectedWorkOrder.visits[0]?.scheduled_at
                                        ? new Date(
                                              selectedWorkOrder.visits[0]
                                                  .scheduled_at,
                                          ).toLocaleString()
                                        : "Not scheduled"
                                }}
                            </div>
                        </div>
                    </div>
                    <section v-if="selectedWorkOrder.status_history?.length" class="grid gap-2" aria-label="Job status history">
                        <h3 class="fieldops-client-label">Status history</h3>
                        <article v-for="entry in selectedWorkOrder.status_history" :key="entry.id" class="rounded-lg border border-white/50 bg-white/30 p-2">
                            <p class="flex flex-wrap items-center gap-2"><span class="fieldops-status-badge" :class="workOrderStatusBadgeClass(entry.from_status ?? 'created')">{{ workOrderStatusLabel(entry.from_status ?? 'created') }}</span> → <span class="fieldops-status-badge" :class="workOrderStatusBadgeClass(entry.to_status)">{{ workOrderStatusLabel(entry.to_status) }}</span> · {{ entry.changed_by ?? 'Former staff member' }}</p>
                            <p v-if="entry.reason" class="mt-1 whitespace-pre-wrap">Reason: {{ entry.reason }}</p>
                        </article>
                    </section>
                    <TimeEntryTimer
                        :key="`time-entry-timer-${selectedWorkOrder.id}`"
                        :customer-id="selectedWorkOrder.site.customer.id"
                        :customer-name="selectedWorkOrder.site.customer.name"
                        :project-id="selectedWorkOrder.project?.id ?? null"
                        :project-name="selectedWorkOrder.project?.name ?? null"
                        :project-number="selectedWorkOrder.project?.project_number ?? null"
                        :job-id="selectedWorkOrder.id"
                        :job-number="selectedWorkOrder.job_number"
                        :job-title="selectedWorkOrder.title"
                        :visits="timeEntryTimerVisits"
                        :initial-visit-id="selectedTimerVisitId"
                        @visit-selected="selectedTimerVisitId = $event"
                    />
                    <div v-for="visit in selectedWorkOrder.visits" :key="visit.id" class="grid gap-2 rounded-xl bg-white/45 p-3">
                        <div class="fieldops-client-label">
                            {{ visit.visit_type === "initial" ? "Initial Visit" : "Revisit" }} Scope ·
                            {{ new Date(visit.scheduled_at).toLocaleString() }}
                        </div>
                        <textarea
                            v-model="visitScopeDrafts[visit.id]"
                            rows="3"
                            class="fieldops-client-input"
                            :aria-label="`${visit.visit_type} Visit scope`"
                            required
                        ></textarea>
                        <button type="button" class="fieldops-submit-action justify-self-start" @click="saveVisitScope(visit.id)">
                            Save Visit Scope
                        </button>
                        <VisitItemUsageEditor
                            :visit-id="visit.id"
                            :items="visit.items"
                            :editable="visit.items_editable"
                        />
                    </div>
                    <div>
                        <div class="fieldops-client-label">Client and site</div>
                        <div>
                            {{ selectedWorkOrder.site.customer.name }} ·
                            {{ selectedWorkOrder.site.name }}
                        </div>
                        <a
                            v-if="selectedWorkOrder.site.address"
                            :href="mapUrl(selectedWorkOrder.site.address)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-semibold text-[var(--fieldops-green-dark)] underline decoration-[var(--fieldops-border)] underline-offset-2 hover:text-[var(--fieldops-green)]"
                        >
                            {{ selectedWorkOrder.site.address }}
                        </a>
                        <div v-else class="opacity-70">No site address</div>
                    </div>
                    <div>
                        <div class="fieldops-client-label">Job Scope</div>
                        <div class="whitespace-pre-wrap">{{ selectedWorkOrder.scope || "No scope recorded" }}</div>
                    </div>
                    <div v-if="selectedWorkOrder.client_completion_summary">
                        <div class="fieldops-client-label">Client completion summary</div>
                        <div class="whitespace-pre-wrap">{{ selectedWorkOrder.client_completion_summary }}</div>
                    </div>
                    <div v-if="selectedWorkOrder.project">
                        <div class="fieldops-client-label">Project</div>
                        <button
                            type="button"
                            class="w-full rounded-xl border border-fieldops-blue-dark bg-white/55 p-3 text-left transition hover:bg-white/80"
                            @click="openProject(selectedWorkOrder.project.id)"
                        >
                            <span class="font-bold">{{
                                selectedWorkOrder.project.name
                            }}</span>
                            <span class="ml-2 text-xs opacity-70">{{
                                selectedWorkOrder.project.project_number
                            }}</span>
                        </button>
                    </div>
                    <div>
                        <div class="fieldops-client-label">Description</div>
                        <div class="whitespace-pre-wrap">
                            {{
                                selectedWorkOrder.description ||
                                "No description"
                            }}
                        </div>
                    </div>
                    <div>
                        <div class="fieldops-client-label">Job notes</div>
                        <div class="whitespace-pre-wrap">
                            {{ selectedWorkOrder.job_notes || "No job notes" }}
                        </div>
                    </div>
                    <div>
                        <div class="fieldops-client-label">Field notes</div>
                        <div class="whitespace-pre-wrap">
                            {{
                                selectedWorkOrder.field_notes ||
                                "No field notes"
                            }}
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div
                            v-for="contact in [
                                {
                                    label: 'Client contact',
                                    name: selectedWorkOrder.client_contact_name,
                                    phone: selectedWorkOrder.client_contact_phone,
                                    email: selectedWorkOrder.client_contact_email,
                                },
                                {
                                    label: 'Technical contact',
                                    name: selectedWorkOrder.technical_contact_name,
                                    phone: selectedWorkOrder.technical_contact_phone,
                                    email: selectedWorkOrder.technical_contact_email,
                                },
                                {
                                    label: 'Project manager',
                                    name: selectedWorkOrder.project_manager_name,
                                    phone: selectedWorkOrder.project_manager_phone,
                                    email: selectedWorkOrder.project_manager_email,
                                },
                                {
                                    label: 'Contractor check-in',
                                    name: selectedWorkOrder.contractor_check_in_name,
                                    phone: selectedWorkOrder.contractor_check_in_phone,
                                    email: selectedWorkOrder.contractor_check_in_email,
                                },
                            ]"
                            :key="contact.label"
                            class="rounded-xl bg-white/45 p-3"
                        >
                            <div class="fieldops-client-label">
                                {{ contact.label }}
                            </div>
                            <div>{{ contact.name || "No name" }}</div>
                            <div class="opacity-75">
                                {{ contact.phone || "No phone" }}
                            </div>
                            <div class="break-all opacity-75">
                                {{ contact.email || "No email" }}
                            </div>
                        </div>
                    </div>
                    <div>
                        <div class="fieldops-client-label">Files</div>
                        <p v-if="attachmentStatusMessage" role="status" class="mb-2 text-sm text-green-900">{{ attachmentStatusMessage }}</p>
                        <p
                            v-if="selectedWorkOrder.attachments.length === 0"
                            class="mb-2 opacity-70"
                        >
                            No files uploaded.
                        </p>
                        <ul v-else class="space-y-1">
                            <li
                                v-for="attachment in selectedWorkOrder.attachments"
                                :key="attachment.url"
                                class="flex flex-col gap-2 rounded-lg bg-white/55 p-2"
                            >
                                <div class="flex min-w-0 items-center justify-between gap-2">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <AttachmentFileTypeIcon :file-name="attachment.original_name" :mime-type="attachment.mime_type" :thumbnail-url="attachment.thumbnail_url" />
                                        <span class="truncate">{{ attachment.original_name }}</span>
                                    </span>
                                    <span v-if="!attachment.online_url" class="flex shrink-0 gap-2">
                                        <a
                                            :href="attachment.preview_url || attachment.url"
                                            target="_blank"
                                            class="font-bold uppercase"
                                            >View</a
                                        >
                                        <a
                                            :href="attachment.download_url"
                                            class="font-bold uppercase"
                                            >Download</a
                                        >
                                    </span>
                                    <AttachmentOnlineActions
                                        v-else
                                        :attachment-id="attachment.id"
                                        :file-name="attachment.original_name"
                                        :online-url="attachment.online_url"
                                        :open-url="attachment.preview_url || attachment.url"
                                        :availability-url="attachment.availability_url"
                                        @missing="removeMissingWorkOrderAttachment"
                                    />
                                </div>
                            </li>
                        </ul>
                        <div class="mt-3 flex gap-2">
                            <input
                                id="view-job-files"
                                type="file"
                                multiple
                                accept="image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                                class="fieldops-client-input"
                                @change="handleViewAttachmentFiles"
                            />
                            <button
                                type="button"
                                class="fieldops-submit-action shrink-0"
                                :disabled="
                                    viewAttachmentForm.processing ||
                                    viewAttachmentForm.files.length === 0
                                "
                                @click="uploadViewAttachments"
                            >
                                {{
                                    viewAttachmentForm.processing
                                        ? "Uploading..."
                                        : "Upload"
                                }}
                            </button>
                        </div>
                    </div>
                    <div class="flex justify-end pt-2">
                        <button
                            type="button"
                            class="fieldops-submit-action fieldops-add-action"
                            @click="openRevisitForm"
                        >
                            Add Revisit
                        </button>
                    </div>
                </div>

                <form
                    v-else-if="jobPanelMode === 'revisit'"
                    class="space-y-4"
                    @submit.prevent="submitRevisit"
                >
                    <div>
                        <label
                            for="revisit-scheduled-at"
                            class="fieldops-client-label"
                            >Revisit date and time</label
                        >
                        <input
                            id="revisit-scheduled-at"
                            v-model="revisitForm.scheduled_at"
                            type="datetime-local"
                            step="900"
                            class="fieldops-client-input fieldops-datetime-input"
                            required
                            @input="revisitForm.scheduling_override = false"
                        />
                        <p
                            v-if="revisitForm.errors.scheduled_at"
                            class="fieldops-client-error"
                        >
                            {{ revisitForm.errors.scheduled_at }}
                        </p>
                        <div
                            v-if="
                                revisitForm.errors.scheduled_at?.includes(
                                    'overlaps',
                                )
                            "
                            class="mt-2 rounded-xl border border-amber-600 bg-amber-100 p-3 text-sm text-amber-950"
                        >
                            <p class="font-bold">Scheduling conflict</p>
                            <p class="mt-1">
                                {{ revisitForm.errors.scheduled_at }}
                            </p>
                            <button
                                type="button"
                                class="mt-2 rounded-lg bg-amber-800 px-3 py-2 text-xs font-bold uppercase tracking-wide text-white"
                                @click="proceedWithRevisitConflict"
                            >
                                Proceed anyway
                            </button>
                        </div>
                    </div>
                    <div>
                        <label
                            for="revisit-duration"
                            class="fieldops-client-label"
                            >Duration in minutes</label
                        >
                        <input
                            id="revisit-duration"
                            v-model.number="revisitForm.duration_minutes"
                            type="number"
                            min="1"
                            max="1440"
                            class="fieldops-client-input"
                            required
                        />
                    </div>
                    <div>
                        <label for="revisit-notes" class="fieldops-client-label"
                            >Revisit notes</label
                        >
                        <textarea
                            id="revisit-notes"
                            v-model="revisitForm.notes"
                            rows="5"
                            class="fieldops-client-input"
                        ></textarea>
                    </div>
                    <div>
                        <label for="revisit-files" class="fieldops-client-label"
                            >Photos and documents</label
                        >
                        <input
                            id="revisit-files"
                            type="file"
                            multiple
                            accept="image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                            class="fieldops-client-input"
                            @change="handleRevisitFiles"
                        />
                        <ul
                            v-if="revisitForm.files.length"
                            class="mt-2 space-y-1 text-xs"
                        >
                            <li
                                v-for="file in revisitForm.files"
                                :key="file.name + file.lastModified"
                                class="rounded-lg bg-white/55 p-2"
                            >
                                {{ file.name }}
                            </li>
                        </ul>
                    </div>
                    <div class="flex justify-between pt-2">
                        <button
                            type="button"
                            class="fieldops-secondary-action"
                            @click="jobPanelMode = 'view'"
                        >
                            Back
                        </button>
                        <button
                            type="submit"
                            class="fieldops-submit-action fieldops-add-action"
                            :disabled="revisitForm.processing"
                        >
                            {{
                                revisitForm.processing
                                    ? "Saving..."
                                    : "Add Revisit"
                            }}
                        </button>
                    </div>
                </form>

                <form v-else class="space-y-4" @submit.prevent="submitJob()">
                    <div>
                        <label for="job-title" class="fieldops-client-label"
                            >Job title</label
                        >
                        <input
                            id="job-title"
                            v-model="jobForm.title"
                            class="fieldops-client-input"
                            required
                        />
                        <p
                            v-if="jobForm.errors.title"
                            class="fieldops-client-error"
                        >
                            {{ jobForm.errors.title }}
                        </p>
                    </div>
                    <div>
                        <label for="job-subtitle" class="fieldops-client-label"
                            >Subtitle</label
                        >
                        <input
                            id="job-subtitle"
                            v-model="jobForm.subtitle"
                            class="fieldops-client-input"
                        />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div
                            v-for="role in [
                                {
                                    label: 'Client contact',
                                    key: 'client_contact',
                                    value: jobForm.client_contact_id,
                                },
                                {
                                    label: 'Technical contact',
                                    key: 'technical_contact',
                                    value: jobForm.technical_contact_id,
                                },
                                {
                                    label: 'Project manager',
                                    key: 'project_manager',
                                    value: jobForm.project_manager_contact_id,
                                },
                                {
                                    label: 'Contractor check-in contact',
                                    key: 'contractor_check_in',
                                    value: jobForm.contractor_check_in_contact_id,
                                },
                            ]"
                            :key="role.key"
                            class="rounded-xl bg-white/35 p-3"
                        >
                            <label
                                :for="`${role.key}-contact-picker`"
                                class="fieldops-client-label"
                                >{{ role.label }}</label
                            >
                            <select
                                :id="`${role.key}-contact-picker`"
                                :value="role.value ?? ''"
                                class="fieldops-client-input"
                                :disabled="!jobCustomer?.contacts.length"
                                @change="
                                    handleJobContactRoleChange(
                                        role.key as
                                            | 'client_contact'
                                            | 'technical_contact'
                                            | 'project_manager'
                                            | 'contractor_check_in',
                                        $event,
                                    )
                                "
                            >
                                <option value="">No contact selected</option>
                                <option
                                    v-for="contact in jobCustomer?.contacts ||
                                    []"
                                    :key="contact.id"
                                    :value="contact.id"
                                >
                                    {{
                                        [contact.first_name, contact.last_name]
                                            .filter(Boolean)
                                            .join(" ") ||
                                        contact.email ||
                                        contact.phone ||
                                        `Contact ${contact.id}`
                                    }}
                                    · {{ contact.contact_type }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="job-customer" class="fieldops-client-label"
                            >Client</label
                        >
                        <div
                            v-if="jobCustomer"
                            class="flex items-center justify-between rounded-xl border border-fieldops-blue-dark bg-white/60 p-3 text-sm"
                        >
                            <span
                                ><strong>{{ jobCustomer.name }}</strong
                                ><span class="ml-2 opacity-60">{{
                                    jobCustomer.client_id
                                }}</span></span
                            >
                            <button
                                type="button"
                                class="text-xs font-bold uppercase"
                                @click="clearJobCustomer"
                            >
                                Change
                            </button>
                        </div>
                        <p
                            v-if="jobCustomer?.quickbooks_sync.status === 'pending'"
                            role="status"
                            aria-live="polite"
                            class="mt-2 text-xs opacity-70"
                        >
                            Checking QuickBooks. Site and contact details will
                            update here when the check finishes.
                        </p>
                        <p v-else-if="jobCustomer?.quickbooks_sync.status === 'sync_paused'" role="status" class="mt-2 text-xs text-amber-900">
                            QuickBooks sync is paused by an Admin.
                            <Link :href="route('admin.integrations.quickbooks.show')" class="font-semibold underline">Manage QuickBooks sync</Link>
                        </p>
                        <div v-if="jobCustomer?.contacts.length" class="mt-2">
                            <label
                                for="job-client-contact"
                                class="fieldops-client-label"
                                >Client contact</label
                            >
                            <select
                                id="job-client-contact"
                                v-model.number="selectedJobContactIndex"
                                class="fieldops-client-input"
                                @change="
                                    selectJobContact(selectedJobContactIndex)
                                "
                            >
                                <option
                                    v-for="(
                                        contact, index
                                    ) in jobCustomer.contacts"
                                    :key="`${contact.contact_type}-${index}`"
                                    :value="index"
                                >
                                    {{
                                        [contact.first_name, contact.last_name]
                                            .filter(Boolean)
                                            .join(" ") ||
                                        contact.email ||
                                        contact.phone ||
                                        `Contact ${index + 1}`
                                    }}
                                    · {{ contact.contact_type }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs opacity-70">
                                Selecting a contact fills the client contact
                                name, phone, and email below.
                            </p>
                        </div>
                        <template v-if="!jobCustomer">
                            <div
                                v-if="jobCustomerMode === 'search'"
                                class="flex gap-2"
                            >
                                <ClearableSearchInput
                                    id="job-customer"
                                    v-model="jobCustomerQuery"
                                    class="fieldops-client-input"
                                    placeholder="Search clients"
                                    clear-label="Clear Customer search"
                                    :suggestion-types="['client']"
                                    :dispatch-selection="false"
                                    @select="selectJobCustomerSuggestion"
                                />
                                <button
                                    type="button"
                                    aria-label="Add new client"
                                    title="Add new client"
                                    class="fieldops-client-add-button fieldops-add-action shrink-0"
                                    @click="startNewJobCustomer"
                                >
                                    <PlusIcon class="h-5 w-5" />
                                </button>
                            </div>
                            <div
                                v-if="jobCustomerMode === 'create'"
                                class="mt-2 grid gap-2 sm:grid-cols-2"
                            >
                                <input
                                    v-model="jobForm.customer_name"
                                    class="fieldops-client-input"
                                    placeholder="New client name"
                                />
                                <input
                                    v-model="jobForm.customer_email"
                                    class="fieldops-client-input"
                                    type="email"
                                    placeholder="Client email"
                                />
                                <input
                                    v-model="jobForm.customer_phone"
                                    class="fieldops-client-input"
                                    type="tel"
                                    placeholder="Client phone"
                                />
                                <input
                                    v-model="jobForm.customer_address"
                                    class="fieldops-client-input"
                                    placeholder="Client address"
                                />
                                <button
                                    type="button"
                                    class="text-left text-xs font-bold uppercase opacity-70"
                                    @click="jobCustomerMode = 'search'"
                                >
                                    Back to client search
                                </button>
                            </div>
                        </template>
                        <p
                            v-if="
                                jobForm.errors.customer_id ||
                                jobForm.errors.customer_name
                            "
                            class="fieldops-client-error"
                        >
                            {{
                                jobForm.errors.customer_id ||
                                jobForm.errors.customer_name
                            }}
                        </p>
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <label class="fieldops-client-label mb-0"
                                >Site</label
                            >
                            <button
                                type="button"
                                class="text-xs font-bold uppercase"
                                @click="
                                    jobSiteMode =
                                        jobSiteMode === 'select'
                                            ? 'create'
                                            : 'select'
                                "
                            >
                                {{
                                    jobSiteMode === "select"
                                        ? "New site"
                                        : "Choose site"
                                }}
                            </button>
                        </div>
                        <select
                            v-if="jobSiteMode === 'select'"
                            v-model="jobForm.site_id"
                            class="fieldops-client-input"
                            :disabled="!jobCustomer"
                        >
                            <option :value="null">Select a site</option>
                            <option
                                v-for="site in jobCustomer?.sites || []"
                                :key="site.id"
                                :value="site.id"
                            >
                                {{ site.name }}
                            </option>
                        </select>
                        <div v-else class="grid gap-2 sm:grid-cols-2">
                            <input
                                v-model="jobForm.site_name"
                                class="fieldops-client-input"
                                placeholder="New site name"
                            />
                            <input
                                v-model="jobForm.site_address"
                                class="fieldops-client-input"
                                placeholder="Site address"
                            />
                        </div>
                        <p
                            v-if="
                                jobForm.errors.site_id ||
                                jobForm.errors.site_name
                            "
                            class="fieldops-client-error"
                        >
                            {{
                                jobForm.errors.site_id ||
                                jobForm.errors.site_name
                            }}
                        </p>
                    </div>

                    <div>
                        <label
                            for="job-description"
                            class="fieldops-client-label"
                            >Description</label
                        >
                        <textarea
                            id="job-description"
                            v-model="jobForm.description"
                            rows="2"
                            class="fieldops-client-input"
                        ></textarea>
                    </div>
                    <div>
                        <label for="job-scope" class="fieldops-client-label">
                            Job Scope (required)
                        </label>
                        <textarea
                            id="job-scope"
                            v-model="jobForm.scope"
                            rows="4"
                            class="fieldops-client-input"
                            required
                        ></textarea>
                        <p v-if="jobForm.errors.scope" class="fieldops-client-error">
                            {{ jobForm.errors.scope }}
                        </p>
                    </div>
                    <div v-if="isClientCompletionSummaryRequired(jobForm.status)">
                        <label for="job-client-completion-summary" class="fieldops-client-label">
                            Client-facing completion summary (required)
                        </label>
                        <textarea
                            id="job-client-completion-summary"
                            v-model="jobForm.client_completion_summary"
                            rows="3"
                            class="fieldops-client-input"
                            required
                            placeholder="Describe the result in nontechnical language."
                        ></textarea>
                        <p v-if="jobForm.errors.client_completion_summary" class="fieldops-client-error">
                            {{ jobForm.errors.client_completion_summary }}
                        </p>
                    </div>
                    <div>
                        <label for="job-notes" class="fieldops-client-label"
                            >Job notes{{ isJobNotesRequired(jobForm.status) ? " (required)" : "" }}</label
                        >
                        <textarea
                            id="job-notes"
                            v-model="jobForm.job_notes"
                            rows="3"
                            class="fieldops-client-input"
                            :required="isJobNotesRequired(jobForm.status)"
                        ></textarea>
                        <p
                            v-if="isJobNotesRequired(jobForm.status)"
                            class="mt-1 text-xs opacity-70"
                        >
                            Add the notes required for this status.
                        </p>
                    </div>
                    <div>
                        <label for="field-notes" class="fieldops-client-label"
                            >Field notes</label
                        >
                        <textarea
                            id="field-notes"
                            v-model="jobForm.field_notes"
                            rows="3"
                            class="fieldops-client-input"
                        ></textarea>
                    </div>
                    <div>
                        <label for="job-status" class="fieldops-client-label"
                            >Status</label
                        >
                        <select
                            id="job-status"
                            v-model="jobForm.status"
                            class="fieldops-client-input"
                        >
                            <option v-for="status in calendarSelectableWorkOrderStatuses" :key="status.value" :value="status.value">
                                {{ status.label }}
                            </option>
                        </select>
                        <p v-if="timerStartConflictError(jobForm.errors)" class="fieldops-client-error">
                            {{ timerStartConflictError(jobForm.errors) }}
                        </p>
                        <p v-if="jobForm.errors.timer_visit_id" class="fieldops-client-error">
                            {{ jobForm.errors.timer_visit_id }}
                        </p>
                    </div>
                    <div v-if="isPendingReasonRequired(jobForm.status) && selectedWorkOrder?.status !== jobForm.status">
                        <label for="job-pending-reason" class="fieldops-client-label">Reason for Pending (required)</label>
                        <textarea id="job-pending-reason" v-model="jobForm.pending_reason" rows="2" class="fieldops-client-input" required maxlength="2000"></textarea>
                        <p v-if="jobForm.errors.pending_reason" class="fieldops-client-error">{{ jobForm.errors.pending_reason }}</p>
                    </div>
                    <div v-if="jobForm.status === 'scheduled' || jobForm.scheduled_at">
                        <label
                            for="job-scheduled-at"
                            class="fieldops-client-label"
                            >Scheduled date and time</label
                        >
                        <input
                            id="job-scheduled-at"
                            v-model="jobForm.scheduled_at"
                            type="datetime-local"
                            step="900"
                            class="fieldops-client-input fieldops-datetime-input"
                            :required="jobForm.status === 'scheduled'"
                            @input="jobForm.scheduling_override = false"
                        />
                        <p
                            v-if="jobForm.errors.scheduled_at"
                            class="fieldops-client-error"
                        >
                            {{ jobForm.errors.scheduled_at }}
                        </p>
                        <div
                            v-if="
                                jobForm.errors.scheduled_at?.includes(
                                    'overlaps',
                                )
                            "
                            class="mt-2 rounded-xl border border-amber-600 bg-amber-100 p-3 text-sm text-amber-950"
                        >
                            <p class="font-bold">Scheduling conflict</p>
                            <p class="mt-1">
                                {{ jobForm.errors.scheduled_at }}
                            </p>
                            <button
                                type="button"
                                class="mt-2 rounded-lg bg-amber-800 px-3 py-2 text-xs font-bold uppercase tracking-wide text-white hover:bg-amber-900"
                                @click="proceedWithScheduleConflict"
                            >
                                Proceed anyway
                            </button>
                        </div>
                    </div>
                    <div>
                        <label for="job-files" class="fieldops-client-label"
                            >Photos and documents</label
                        >
                        <input
                            id="job-files"
                            type="file"
                            multiple
                            accept="image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                            class="fieldops-client-input"
                            @change="handleJobFiles"
                        />
                        <p class="mt-1 text-xs opacity-70">
                            Photos and documents are stored separately. Existing
                            files cannot be deleted here.
                        </p>
                        <p v-if="attachmentStatusMessage" role="status" class="mt-2 text-sm text-green-900">{{ attachmentStatusMessage }}</p>
                        <ul
                            v-if="
                                jobForm.files.length ||
                                selectedWorkOrder?.attachments.length
                            "
                            class="mt-2 space-y-1 text-xs"
                        >
                            <li
                                v-for="file in jobForm.files"
                                :key="file.name + file.lastModified"
                                class="rounded-lg bg-white/55 p-2"
                            >
                                {{ file.name }}
                            </li>
                            <li
                                v-for="attachment in selectedWorkOrder?.attachments ||
                                []"
                                :key="attachment.url"
                                class="flex flex-col gap-2 rounded-lg bg-white/55 p-2"
                            >
                                <div class="flex min-w-0 items-center justify-between gap-2">
                                    <span class="flex min-w-0 items-center gap-2">
                                        <AttachmentFileTypeIcon :file-name="attachment.original_name" :mime-type="attachment.mime_type" :thumbnail-url="attachment.thumbnail_url" />
                                        <span class="truncate">{{ attachment.original_name }}</span>
                                    </span>
                                    <span v-if="!attachment.online_url" class="flex shrink-0 gap-2">
                                        <a
                                            :href="attachment.preview_url || attachment.url"
                                            target="_blank"
                                            class="font-bold uppercase"
                                            >View</a
                                        >
                                        <a
                                            :href="attachment.download_url"
                                            class="font-bold uppercase"
                                            >Download</a
                                        >
                                    </span>
                                    <AttachmentOnlineActions
                                        v-else
                                        :attachment-id="attachment.id"
                                        :file-name="attachment.original_name"
                                        :online-url="attachment.online_url"
                                        :open-url="attachment.preview_url || attachment.url"
                                        :availability-url="attachment.availability_url"
                                        @missing="removeMissingWorkOrderAttachment"
                                    />
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button
                            type="submit"
                            class="fieldops-submit-action"
                            :class="{ 'fieldops-add-action': jobFormMode === 'create' }"
                            :disabled="jobForm.processing"
                        >
                            {{
                                jobForm.processing
                                    ? "Saving..."
                                    : jobFormMode === "edit"
                                      ? "Save"
                                      : "Submit"
                            }}
                        </button>
                    </div>
                </form>
                <button
                    v-if="selectedWorkOrder && jobPanelMode === 'form' && jobFormMode === 'edit'"
                    type="button"
                    class="fieldops-delete-action absolute bottom-5 left-5"
                    :disabled="!page.props.auth.is_admin || archivingRecord"
                    @click="archiveBusinessRecord('work-orders', selectedWorkOrder.id, `job ${selectedWorkOrder.job_number}`)"
                >
                    {{ archivingRecord ? "Archiving…" : "Delete" }}
                </button>
            </div>

            <template v-else>
                <!-- FAB Button Container -->
                <div class="relative">
                    <!-- Tap Menu (Visible only in menu mode) -->
                    <Transition name="fade-up">
                        <div
                            v-if="fabMode === 'menu'"
                            class="fieldops-fab-menu absolute bottom-16 right-0 flex flex-col gap-2 rounded-lg p-3"
                            id="calendar-create-menu"
                            role="group"
                            aria-label="Create a Customer, Project, or Job"
                        >
                            <button
                                type="button"
                                @click="openClientForm"
                                class="fieldops-fab-menu-item fieldops-add-action rounded-lg px-4 py-2 text-left text-sm font-bold uppercase tracking-wide transition-colors"
                            >
                                Add Client
                            </button>
                            <button
                                type="button"
                                @click="$emit('action', 'add_project')"
                                class="fieldops-fab-menu-item fieldops-add-action rounded-lg px-4 py-2 text-left text-sm font-bold uppercase tracking-wide transition-colors"
                            >
                                Add Project
                            </button>
                            <button
                                type="button"
                                @click="openJobForm"
                                class="fieldops-fab-menu-item fieldops-add-action rounded-lg px-4 py-2 text-left text-sm font-bold uppercase tracking-wide transition-colors"
                            >
                                Add Job
                            </button>
                        </div>
                    </Transition>

                    <!-- FAB Button -->
                    <button
                        type="button"
                        @click="openMenu"
                        class="fieldops-fab flex h-16 w-16 transform items-center justify-center rounded-full transition-all duration-300 active:scale-95"
                        :aria-expanded="fabMode === 'menu'"
                        aria-controls="calendar-create-menu"
                        aria-label="Open create menu"
                    >
                        <PlusIcon class="h-8 w-8" />
                    </button>
                </div>
            </template>
        </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.fieldops-swipe-surface {
    touch-action: pan-y;
}

.fieldops-swipe-surface.calendar-content--week {
    touch-action: pan-x pan-y;
}

.calendar-view-enter-active,
.calendar-view-leave-active {
    transition:
        opacity 180ms ease,
        transform 180ms ease;
}

.calendar-view-enter-from {
    opacity: 0;
    transform: translateY(8px);
}

.calendar-view-leave-to {
    opacity: 0;
    transform: translateY(-8px);
}

.calendar-month {
    display: grid;
    gap: 0.45rem;
}

.calendar-view-button {
    min-height: 2.75rem;
    width: 5rem;
    flex: none;
    border: 1px solid transparent;
    border-radius: 6px;
    padding: 0.5rem 0.65rem;
    font-size: 0.875rem;
    font-weight: 700;
    transition:
        background-color 150ms ease,
        border-color 150ms ease,
        color 150ms ease;
}

.calendar-view-button--active {
    border-color: var(--fieldops-navy);
    background: var(--fieldops-navy);
    color: var(--fieldops-white);
}

.calendar-view-button--inactive {
    background: var(--fieldops-blue-dark);
    color: var(--fieldops-white);
}

.calendar-view-button--inactive:hover {
    background: var(--fieldops-navy);
}

.calendar-view-controls {
    border-color: var(--fieldops-border);
    border-radius: 8px;
    box-shadow: 0 4px 12px rgb(8 19 46 / 7%);
}

.calendar-navigation-button {
    display: inline-grid;
    width: 2.75rem;
    height: 2.75rem;
    flex: 0 0 auto;
    place-items: center;
    border: 1px solid var(--fieldops-blue-dark);
    border-radius: 8px;
    background: var(--fieldops-blue-dark);
    color: var(--fieldops-white);
    box-shadow: 0 2px 6px rgb(8 19 46 / 14%);
}

.calendar-navigation-button:hover {
    background: var(--fieldops-navy);
}

.calendar-navigation-button svg {
    color: var(--fieldops-white);
}

.calendar-view-button:focus-visible,
.calendar-navigation-button:focus-visible,
.calendar-navigation-today:focus-visible {
    outline: 3px solid var(--fieldops-blue-strong);
    outline-offset: 2px;
}

.calendar-navigation-today {
    display: inline-flex;
    min-width: 3rem;
    min-height: 2.75rem;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--fieldops-blue-dark);
    border-radius: 8px;
    background: var(--fieldops-white);
    padding: 0 0.65rem;
    color: var(--fieldops-blue-dark);
    font-size: 0.75rem;
    font-weight: 800;
}

.calendar-navigation-today:hover {
    background: var(--fieldops-blue-light);
}

.calendar-navigation__label {
    min-height: 2.75rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: var(--fieldops-navy);
    color: var(--fieldops-white);
    font-family: var(--font-brand);
    font-size: 0.95rem;
    font-weight: 800;
}

.calendar-day-visit {
    display: flex;
    width: 100%;
    min-height: 44px;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    border: 1px solid var(--fieldops-border);
    border-radius: 8px;
    background: var(--fieldops-white);
    padding: 0.65rem 0.75rem;
    color: var(--fieldops-ink);
    text-align: left;
    cursor: pointer;
}

.calendar-day-visit:hover:not(:disabled) {
    background: var(--fieldops-page);
}

.calendar-day-visit:focus-visible {
    outline: 3px solid var(--fieldops-blue-strong);
    outline-offset: 2px;
}

.calendar-day-visit:disabled {
    cursor: not-allowed;
    opacity: 0.75;
}

.calendar-day-visit__details {
    display: grid;
    min-width: 0;
    flex: 1 1 auto;
    gap: 0.15rem;
}

.calendar-day-visit__time {
    color: var(--fieldops-slate);
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
    font-weight: 700;
}

.calendar-day-visit__title {
    overflow: hidden;
    color: var(--fieldops-ink);
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.calendar-day-visit__customer {
    overflow: hidden;
    color: var(--fieldops-slate);
    font-size: 0.75rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.calendar-day-visit__status {
    display: inline-flex;
    min-height: 2rem;
    flex: 0 0 auto;
    align-items: center;
    border: 1px solid currentColor;
    border-radius: 6px;
    padding: 0.25rem 0.45rem;
    font-size: 0.65rem;
    font-weight: 800;
    line-height: 1.1;
    text-transform: uppercase;
}

@media (min-width: 64rem) {
    .calendar-navigation-button,
    .calendar-navigation-today,
    .calendar-navigation__label {
        min-height: 3.5rem;
    }

    .calendar-navigation-button {
        width: 3.5rem;
    }
}

.calendar-weekdays,
.calendar-month-grid {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
}

.calendar-weekdays {
    gap: 1px;
    border: 1px solid var(--fieldops-border);
    border-radius: 8px 8px 0 0;
    background: var(--fieldops-border);
    overflow: hidden;
}

.calendar-weekday {
    background: var(--fieldops-surface-soft);
    padding: 0.75rem 0.25rem;
    color: #50617b;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-align: center;
    text-transform: uppercase;
}

.calendar-month-grid {
    grid-auto-rows: clamp(10rem, 20vh, 14rem);
    gap: 1px;
    overflow: hidden;
    border: 1px solid var(--fieldops-border);
    border-radius: 0 0 8px 8px;
    background: var(--fieldops-border);
}

.calendar-cell {
    position: relative;
    min-width: 0;
    overflow: hidden;
    background: var(--fieldops-white);
    transition:
        background-color 150ms ease,
        box-shadow 150ms ease;
}

.calendar-cell--outside {
    background: var(--fieldops-page);
}

.calendar-cell--today {
    background: var(--fieldops-blue-light);
}

.calendar-cell--selected {
    box-shadow: inset 0 0 0 2px var(--fieldops-blue-dark);
}

.calendar-cell--drop-target {
    background: rgb(59 130 246 / 12%);
    box-shadow: inset 0 0 0 3px var(--fieldops-blue-dark);
}

.calendar-cell-add {
    position: absolute;
    z-index: 0;
    inset: 0;
    display: flex;
    width: 100%;
    flex-direction: column;
    align-items: stretch;
    border: 0;
    background: transparent;
    padding: 0.4rem;
    color: var(--fieldops-ink);
    text-align: left;
    cursor: pointer;
}

.calendar-cell-add:hover {
    background: rgb(59 130 246 / 10%);
}

.calendar-cell-add:focus-visible {
    z-index: 2;
    outline: 3px solid var(--fieldops-blue-strong);
    outline-offset: -3px;
}

.calendar-cell-add__top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.25rem;
}

.calendar-cell-number {
    display: inline-flex;
    min-width: 1.8rem;
    height: 1.8rem;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: #26354d;
    font-size: 0.8rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
}

.calendar-cell-number--today,
.calendar-cell-number--selected {
    background: var(--fieldops-blue-dark);
    color: white;
    font-weight: 800;
}

.calendar-cell-number--outside {
    color: #9ca3af;
}

.calendar-cell-today-label {
    color: var(--fieldops-blue-dark);
    font-size: 0.62rem;
    font-weight: 700;
    text-transform: uppercase;
}

.calendar-cell-events {
    position: absolute;
    z-index: 1;
    inset: 2.7rem 0.35rem 0.35rem;
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    overflow-y: auto;
    overscroll-behavior: contain;
    padding: 0.05rem;
    pointer-events: none;
    scrollbar-width: thin;
}

.calendar-job--month {
    position: relative;
    display: flex;
    width: 100%;
    min-width: 0;
    flex: none;
    min-height: 2.75rem;
    border: 1px solid currentColor;
    border-radius: 6px;
    padding: 0;
    font-size: 0.68rem;
    line-height: 1.25;
    pointer-events: auto;
}

.calendar-job--week {
    position: relative;
    display: flex;
    width: 100%;
    flex: none;
    flex-direction: column;
    overflow: visible;
    border: 1px solid currentColor;
    border-radius: 6px;
    padding: 0;
    text-align: center;
}

.calendar-week-visit {
    position: relative;
    z-index: 10;
    width: 100%;
    min-width: 0;
    flex: none;
}

.calendar-job--month:hover,
.calendar-job--week:hover {
    filter: brightness(0.96);
}

.calendar-job__open {
    display: block;
    width: 100%;
    min-width: 0;
    border: 0;
    border-radius: inherit;
    background: transparent;
    padding: 0.25rem 0.35rem;
    color: inherit;
    text-align: left;
    cursor: grab;
    touch-action: none;
    user-select: none;
}

.calendar-job__open--month {
    display: grid;
    grid-template-columns: max-content minmax(0, 1fr);
    align-items: start;
    gap: 0.4rem;
    padding: 0.3rem 0.4rem;
}

.calendar-job__open--week {
    display: flex;
    flex: 1;
    height: 100%;
    min-height: inherit;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    border: 1px solid currentColor;
    padding: 0.15rem 0.1rem;
    text-align: center;
}

.calendar-week-start-time {
    display: block;
    overflow: hidden;
    font-size: 0.6rem;
    font-variant-numeric: tabular-nums;
    font-weight: 800;
    line-height: 1.1;
    text-overflow: clip;
    white-space: nowrap;
}

.calendar-week-scroll {
    overflow-x: hidden;
}

.calendar-job__open:active {
    cursor: grabbing;
    transform: scale(0.985);
}

.calendar-job__open:focus-visible {
    z-index: 2;
    outline: 2px solid var(--fieldops-white);
    outline-offset: 1px;
    box-shadow: 0 0 0 4px var(--fieldops-navy);
}

.calendar-job__resize {
    position: absolute;
    z-index: 3;
    right: 0;
    left: 0;
    height: 1.5rem;
    border: 0;
    background: transparent;
    padding: 0;
    cursor: ns-resize;
    touch-action: none;
    user-select: none;
}

.calendar-job__resize--start {
    top: 0;
}

.calendar-job__resize--end {
    bottom: 0;
}

.calendar-job__resize:focus-visible {
    outline: 3px solid var(--fieldops-blue-strong);
    outline-offset: -2px;
}

.calendar-status--green {
    border-color: var(--fieldops-success-dark);
    background: var(--fieldops-success-dark);
    color: var(--fieldops-white);
}

.calendar-status--gray {
    border-color: var(--fieldops-slate);
    background: var(--fieldops-slate-light);
    color: var(--fieldops-slate-dark);
}

.calendar-status--blue {
    border-color: var(--fieldops-blue-dark);
    background: var(--fieldops-blue-dark);
    color: var(--fieldops-white);
}

.calendar-status--purple {
    border-color: var(--fieldops-purple-dark);
    background: var(--fieldops-purple);
    color: var(--fieldops-white);
}

.calendar-status--magenta {
    border-color: var(--fieldops-magenta-dark);
    background: var(--fieldops-magenta-dark);
    color: var(--fieldops-white);
}

.calendar-status--orange {
    border-color: var(--fieldops-warning-dark);
    background: var(--fieldops-warning-dark);
    color: var(--fieldops-white);
}

.calendar-status--red {
    border-color: var(--fieldops-danger-dark);
    background: var(--fieldops-danger-dark);
    color: var(--fieldops-white);
}

.calendar-job__time {
    white-space: nowrap;
    font-size: 0.62rem;
    font-weight: 700;
    opacity: 0.82;
}

.calendar-job__time--month-compact {
    display: none;
}

.calendar-job__title {
    display: -webkit-box;
    min-width: 0;
    overflow: hidden;
    font-weight: 700;
    overflow-wrap: anywhere;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    line-clamp: 2;
}

.calendar-job {
    transition: transform 150ms ease;
    user-select: none;
}

.calendar-job:active {
    transform: none;
}

.calendar-time-slot {
    display: flex;
    height: 3rem;
    min-height: 3rem;
    align-items: center;
    justify-content: center;
    border: 1px dashed rgb(37 99 235 / 32%);
    border-radius: 6px;
    color: var(--fieldops-slate-dark);
    font-size: 0.625rem;
    transition:
        background-color 150ms ease,
        border-color 150ms ease;
}

.calendar-time-slots {
    overscroll-behavior: contain;
    scroll-behavior: smooth;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
}

.calendar-time-slots::-webkit-scrollbar {
    display: none;
}

.calendar-time-slot:hover {
    border-color: var(--fieldops-blue-dark);
    background: rgb(59 130 246 / 10%);
}

.calendar-time-slot--drop-target {
    border: 2px solid var(--fieldops-blue-dark);
    background: rgb(59 130 246 / 14%);
    box-shadow: inset 0 0 0 1px white;
}

.calendar-time-slot:focus-visible {
    position: relative;
    z-index: 2;
    outline: 3px solid var(--fieldops-blue-strong);
    outline-offset: -3px;
}

.calendar-time-slot-label {
    font-size: 0.55rem;
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    white-space: nowrap;
}

.calendar-month-drop-preview {
    position: fixed;
    z-index: 1100;
    display: grid;
    width: min(16rem, calc(100vw - 1rem));
    overflow: hidden;
    border: 1px solid var(--fieldops-border);
    border-radius: 8px;
    background: var(--fieldops-navy);
    color: white;
    pointer-events: none;
    box-shadow: 0 12px 30px rgb(8 19 46 / 24%);
}

.calendar-month-drop-preview__header {
    display: grid;
    gap: 0.2rem;
    padding: 0.65rem 0.8rem;
    color: white;
    font-size: 0.75rem;
    line-height: 1.35;
}

.calendar-month-drop-preview__eyebrow {
    color: var(--fieldops-orange);
    font-size: 0.58rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.calendar-month-drop-preview__time {
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}

.calendar-month-drop-preview__job {
    overflow: hidden;
    color: rgb(255 255 255 / 78%);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.calendar-drag-preview {
    position: fixed;
    z-index: 1000;
    display: grid;
    width: min(15.5rem, calc(100vw - 1rem));
    gap: 0.18rem;
    border: 1px solid var(--fieldops-blue-dark);
    border-radius: 8px;
    background: var(--fieldops-navy);
    padding: 0.65rem 0.8rem;
    color: white;
    font-size: 0.75rem;
    line-height: 1.35;
    pointer-events: none;
    box-shadow: 0 12px 30px rgb(8 19 46 / 24%);
}

.calendar-drag-preview__eyebrow {
    color: var(--fieldops-orange);
    font-size: 0.62rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.calendar-drag-preview__title {
    overflow: hidden;
    font-size: 0.82rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.calendar-drop-confirm-backdrop {
    position: fixed;
    z-index: 1200;
    inset: 0;
    display: grid;
    place-items: center;
    background: rgb(15 23 42 / 56%);
    padding: 1rem;
}

.calendar-drop-confirm {
    width: min(100%, 31rem);
    border: 1px solid var(--fieldops-blue-dark);
    border-radius: 8px;
    background: var(--fieldops-white);
    padding: clamp(1.25rem, 4vw, 2rem);
    color: var(--fieldops-ink);
    box-shadow: 0 14px 40px rgb(8 19 46 / 22%);
}

.calendar-drop-confirm__eyebrow {
    margin: 0 0 0.35rem;
    color: var(--fieldops-blue-dark);
    font-size: 0.67rem;
    font-weight: 800;
    letter-spacing: 0.13em;
    text-transform: uppercase;
}

.calendar-drop-confirm h2 {
    margin: 0;
    color: var(--fieldops-ink);
    font-size: 1.45rem;
    font-weight: 800;
}

.calendar-drop-confirm__job {
    margin: 0.45rem 0 1.1rem;
    color: #405448;
    font-weight: 600;
}

.calendar-drop-confirm__schedule {
    display: grid;
    gap: 0.6rem;
    margin: 0;
}

.calendar-drop-confirm__schedule div {
    display: grid;
    grid-template-columns: 3rem minmax(0, 1fr);
    gap: 0.75rem;
    border-top: 1px solid rgb(37 99 235 / 24%);
    padding-top: 0.6rem;
}

.calendar-drop-confirm__schedule dt {
    color: #536358;
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
}

.calendar-drop-confirm__schedule dd {
    margin: 0;
    color: var(--fieldops-ink);
    font-size: 0.875rem;
    font-weight: 600;
}

.calendar-drop-confirm__actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.65rem;
    margin-top: 1.5rem;
}

.calendar-drop-confirm__cancel,
.calendar-drop-confirm__accept {
    min-height: 2.75rem;
    border-radius: 0.6rem;
    padding: 0.65rem 1rem;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.calendar-drop-confirm__cancel {
    border: 1px solid #9ca3af;
    background: white;
    color: #374151;
}

.calendar-drop-confirm__accept {
    border: 1px solid var(--fieldops-blue-dark);
    background: var(--fieldops-blue-dark);
    color: var(--fieldops-white);
}

.calendar-drop-confirm__accept:hover {
    background: var(--fieldops-navy);
}

.calendar-drop-confirm__cancel:focus-visible,
.calendar-drop-confirm__accept:focus-visible {
    outline: 3px solid var(--fieldops-blue-strong);
    outline-offset: 3px;
}

@media (prefers-reduced-motion: reduce) {
    .calendar-view-enter-active,
    .calendar-view-leave-active,
    .calendar-cell,
    .calendar-job {
        transition: none;
    }

    .calendar-job:active {
        transform: none;
    }
}

@media (max-width: 640px) {
    .calendar-navigation {
        gap: 0.25rem;
    }

    .calendar-navigation__label {
        font-size: 0.8rem;
    }

    .calendar-weekday {
        padding: 0.55rem 0.1rem;
        font-size: 0.58rem;
        letter-spacing: 0;
    }

    .calendar-month-grid {
        grid-auto-rows: clamp(6.25rem, 14vh, 8rem);
    }

    .calendar-cell-add {
        padding: 0.2rem;
    }

    .calendar-cell-number {
        min-width: 1.5rem;
        height: 1.5rem;
        font-size: 0.7rem;
    }

    .calendar-cell-events {
        inset: 2rem 0 0.2rem;
        gap: 0.15rem;
        padding: 0;
    }

    .calendar-job--month {
        grid-template-columns: minmax(0, 1fr);
        gap: 0.05rem;
        border-radius: 6px;
        padding: 0;
        font-size: 0.6rem;
    }

    .calendar-job__open--month {
        width: calc(100% + 2px);
        min-height: 2.75rem;
        margin-inline: -1px;
        padding: 0;
        gap: 0.05rem;
    }

    .calendar-job__open--month > span {
        padding-inline: 0.1rem;
    }

    .calendar-job__time--month-full {
        display: none;
    }

    .calendar-job__time--month-compact {
        display: block;
        font-size: 0.56rem;
        letter-spacing: -0.025em;
    }

    .calendar-job__time {
        font-size: 0.55rem;
    }

    .calendar-week-start-time {
        font-size: 0.58rem;
    }

    .calendar-week-scroll {
        overflow-x: auto;
        scrollbar-width: thin;
        touch-action: pan-x pan-y;
    }

    .calendar-week-grid {
        min-width: 0;
        grid-template-columns: repeat(7, minmax(0, 1fr));
    }

    .calendar-day-visit__status {
        font-size: 0.7rem;
    }

}

@media (max-width: 360px) {
    .calendar-weekday {
        font-size: 0.55rem;
    }

    .calendar-navigation-today {
        min-width: 2.75rem;
        padding-inline: 0.35rem;
    }

    .calendar-navigation__label {
        font-size: 0.7rem;
        padding-inline: 0.35rem;
    }

}

@media (max-width: 640px) {
    .fieldops-calendar-panels--admin {
        right: calc(3.5rem + 0.5rem);
    }

    .fieldops-calendar-panels--admin .fieldops-client-panel {
        width: calc(100vw - 4.5rem);
        max-width: 46rem;
    }
}

.overflow-x-auto::-webkit-scrollbar {
    height: 8px;
}
.overflow-x-auto::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 4px;
}

.fieldops-fab-menu {
    border: 1px solid var(--fieldops-border);
    background: var(--fieldops-white);
    color: var(--fieldops-ink);
    box-shadow: 0 12px 32px rgb(8 19 46 / 18%);
}

.fieldops-fab-menu-item:hover {
    background: rgb(255 255 255 / 56%);
}

.fieldops-fab-menu-item {
    min-height: 44px;
}

.fieldops-fab-menu-item.fieldops-add-action:not(:disabled) {
    border-color: var(--fieldops-success-light);
    background: var(--fieldops-success-light);
    color: var(--fieldops-success-dark);
}

.fieldops-fab-menu-item.fieldops-add-action:not(:disabled):hover {
    border-color: var(--fieldops-success);
    background: var(--fieldops-success);
    color: var(--fieldops-white);
}

.fieldops-client-panel {
    border: 1px solid var(--fieldops-border);
    background: var(--fieldops-white);
    color: var(--fieldops-ink);
    box-shadow: 0 16px 40px rgb(8 19 46 / 18%);
}

.calendar-job-panel-region:focus {
    outline: 3px solid var(--fieldops-blue-strong);
    outline-offset: 2px;
}

.fieldops-icon-button {
    display: inline-flex;
    height: 2.75rem;
    width: 2.75rem;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    color: var(--fieldops-blue-dark);
    transition:
        background-color 150ms ease,
        transform 150ms ease;
}

.fieldops-icon-button:focus-visible {
    outline: 3px solid var(--fieldops-blue-strong);
    outline-offset: 2px;
}

.fieldops-icon-button:hover {
    background: rgb(255 255 255 / 56%);
    transform: translateY(-1px);
}

.fieldops-icon-button.fieldops-edit-action {
    border: 1px solid var(--fieldops-warning-light);
    background: var(--fieldops-warning-light);
    color: var(--fieldops-ink);
}

.fieldops-icon-button.fieldops-edit-action:hover {
    border-color: var(--fieldops-warning);
    background: var(--fieldops-warning-light);
    color: var(--fieldops-ink);
}

.fieldops-client-id {
    width: fit-content;
    border-radius: 9999px;
    background: var(--fieldops-blue-dark);
    padding: 0.3rem 0.7rem;
    color: white;
    font-family: var(--font-mono);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    font-variant-numeric: tabular-nums;
}

.fieldops-client-label {
    display: block;
    margin-bottom: 0.35rem;
    color: var(--fieldops-navy);
    font-family: var(--font-brand);
    font-size: 0.7rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.fieldops-datetime-input {
    min-height: 46px;
    color-scheme: light;
    accent-color: var(--fieldops-blue);
    font-variant-numeric: tabular-nums;
}

.fieldops-datetime-input::-webkit-calendar-picker-indicator {
    cursor: pointer;
    opacity: 0.75;
    filter: saturate(1.25);
}

.fieldops-datetime-input::-webkit-calendar-picker-indicator:hover {
    opacity: 1;
}

.fieldops-client-add-button {
    display: inline-flex;
    height: 46px;
    width: 46px;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--fieldops-blue-dark);
    border-radius: 8px;
    background: var(--fieldops-blue-dark);
    color: white;
    transition:
        background-color 150ms ease,
        transform 150ms ease;
}

.fieldops-client-add-button:hover {
    background: var(--fieldops-navy);
    transform: translateY(-1px);
}

.fieldops-client-add-button.fieldops-add-action:not(:disabled) {
    border-color: var(--fieldops-success-light);
    background: var(--fieldops-success-light);
    color: var(--fieldops-success-dark);
}

.fieldops-client-add-button.fieldops-add-action:not(:disabled):hover {
    border-color: var(--fieldops-success);
    background: var(--fieldops-success);
    color: var(--fieldops-white);
}

.fieldops-client-error {
    margin-top: 0.25rem;
    color: #9f1239;
    font-size: 0.75rem;
}

</style>
