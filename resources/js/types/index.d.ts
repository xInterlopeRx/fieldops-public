export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    status?: "active" | "inactive";
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
        is_admin: boolean;
        can_view_sync_logs: boolean;
        onboarding_area_unlocked: boolean;
    };
    sync_health: {
        status: "healthy" | "warning" | "error";
        label: string;
        summary: string;
        errors: number;
        pending: number;
        configuration: number;
        quickBooks: {
            linkedCustomers: number;
            failed: number;
            pending: number;
            connected: boolean;
            environment: string;
            syncEnabled: boolean;
        };
        graph: {
            calendarEnabled: boolean;
            mailEnabled: boolean;
            failed: number;
            pending: number;
            configuration: number;
        };
    } | null;
    work_order_default_status: string;
    smtp_plain_transport_allowed: boolean;
    work_order_statuses: Array<{
        value: string;
        label: string;
        status_color: "gray" | "blue" | "purple" | "orange" | "magenta" | "green" | "red";
        calendar_selectable: boolean;
        active_for_upcoming_jobs: boolean;
        job_notes_required: boolean;
        client_completion_summary_required: boolean;
        pending_reason_required: boolean;
    }>;
};
