<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from "vue";
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import GlobalSearchBox, {
    type GlobalSearchResult,
} from "@/Components/GlobalSearchBox.vue";
import { Link, router } from "@inertiajs/vue3";
import {
    ArchiveBoxIcon,
    BuildingOffice2Icon,
    ChartBarIcon,
    CurrencyDollarIcon,
    ExclamationTriangleIcon,
    Cog6ToothIcon,
    ClockIcon,
    ClipboardDocumentCheckIcon,
    ArrowPathIcon,
    RectangleStackIcon,
    ShieldCheckIcon,
    Squares2X2Icon,
    UserCircleIcon,
    UsersIcon,
    WrenchScrewdriverIcon,
} from "@heroicons/vue/24/outline";

const showingNavigationDropdown = ref(false);
const scheduleDateLabel = new Intl.DateTimeFormat(undefined, {
    weekday: "long",
    month: "long",
    day: "numeric",
}).format(new Date());

const adminTabs = [
    {
        key: "customers",
        label: "Customers",
        route: "admin.customers",
        icon: BuildingOffice2Icon,
    },
    {
        key: "projects",
        label: "Projects",
        route: "admin.projects",
        icon: RectangleStackIcon,
    },
    {
        key: "jobs",
        label: "Jobs",
        route: "admin.jobs",
        icon: WrenchScrewdriverIcon,
    },
    {
        key: "time-tracking",
        label: "Time Tracking",
        route: "admin.time-tracking.dashboard",
        icon: ClockIcon,
    },
    {
        key: "profile",
        label: "MyProfile",
        route: "profile.edit",
        icon: UserCircleIcon,
    },
    {
        key: "archive",
        label: "Archive",
        route: "admin.archive",
        icon: ArchiveBoxIcon,
    },
    {
        key: "operations",
        label: "Operations",
        route: "admin.home",
        icon: Cog6ToothIcon,
    },
] as const;

const isOperationsArea = (): boolean =>
    route().current("admin.home") ||
    route().current("admin.users") ||
    route().current("admin.sync-logs") ||
    route().current("admin.onboarding") ||
    route().current("admin.onboarding.*") ||
    route().current("admin.integrations.quickbooks.*") ||
    route().current("admin.integrations.bitwarden.*") ||
    route().current("admin.integrations.microsoft-graph.*");

const isOnboardingArea = (): boolean =>
    route().current("admin.onboarding") ||
    route().current("admin.onboarding.*") ||
    route().current("admin.integrations.quickbooks.*") ||
    route().current("admin.integrations.bitwarden.*") ||
    route().current("admin.integrations.microsoft-graph.*");

const isTimeTrackingArea = (): boolean => route().current("admin.time-tracking.*");

const isAdminTabActive = (key: (typeof adminTabs)[number]["key"]): boolean => {
    if (key === "operations") return isOperationsArea();

    if (key === "time-tracking") return isTimeTrackingArea();

    if (key === "profile") return route().current("profile.*");

    const tab = adminTabs.find((item) => item.key === key);

    return tab ? route().current(tab.route) : false;
};

const selectGlobalSearchResult = (result: GlobalSearchResult): void => {
    if (route().current("calendar")) {
        window.dispatchEvent(
            new CustomEvent("fieldops:global-search-result-selected", {
                detail: result,
            }),
        );

        return;
    }

    if (result.type === "client") {
        if (route().current("admin.customers")) {
            window.dispatchEvent(
                new CustomEvent("fieldops:customer-search-result-selected", {
                    detail: result.id,
                }),
            );

            return;
        }

        router.visit(route("admin.customers", { open_customer: result.id }));

        return;
    }

    const destination = result.type === "project"
          ? { open_project: result.id }
          : { open_job: result.type === "visit" ? result.parent_id ?? result.id : result.id };

    router.visit(route("calendar", destination));
};

const handleGlobalSearchRequested = (event: Event): void => {
    const result = (event as CustomEvent<GlobalSearchResult>).detail;

    if (result) selectGlobalSearchResult(result);
};

onMounted(() => window.addEventListener("fieldops:global-search-requested", handleGlobalSearchRequested));
onBeforeUnmount(() => window.removeEventListener("fieldops:global-search-requested", handleGlobalSearchRequested));

</script>

<template>
    <div
        class="fieldops-app-shell min-h-screen"
        :class="{
            'fieldops-app-shell--admin': $page.props.auth.is_admin,
            'fieldops-app-shell--calendar': route().current('calendar'),
        }"
    >
        <nav
            class="fieldops-nav"
            :class="{
                'fieldops-nav--admin': $page.props.auth.is_admin,
            }"
            aria-label="Primary navigation"
        >
            <div class="fieldops-topbar-inner">
                <Link
                    :href="route('calendar')"
                    class="fieldops-brand-link"
                    aria-label="FieldOps schedule"
                >
                    <ApplicationLogo />
                </Link>

                <Link
                    :href="route('calendar')"
                    class="fieldops-schedule-link"
                    :class="{
                        'fieldops-schedule-link--active': route().current('calendar'),
                    }"
                    :aria-current="route().current('calendar') ? 'page' : undefined"
                >
                    <span class="fieldops-schedule-copy">
                        <strong>Schedule</strong>
                        <small>{{ scheduleDateLabel }}</small>
                    </span>
                </Link>

                <div class="fieldops-topbar-actions">
                    <Link
                        v-if="!$page.props.auth.is_admin"
                        :href="route('profile.edit')"
                        class="fieldops-basic-profile-link"
                        :aria-current="route().current('profile.*') ? 'page' : undefined"
                    >
                        Profile
                    </Link>

                    <button
                        v-if="!$page.props.auth.is_admin"
                        type="button"
                        class="fieldops-mobile-menu-button"
                        :aria-expanded="showingNavigationDropdown"
                        aria-label="Toggle navigation menu"
                        @click="showingNavigationDropdown = !showingNavigationDropdown"
                    >
                        <svg
                            class="h-6 w-6"
                            stroke="currentColor"
                            fill="none"
                            viewBox="0 0 24 24"
                            aria-hidden="true"
                        >
                            <path
                                v-if="!showingNavigationDropdown"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16"
                            />
                            <path
                                v-else
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>

                    <div class="fieldops-header-search-shell">
                        <GlobalSearchBox @select="selectGlobalSearchResult" />
                    </div>

                    <Link
                        v-if="$page.props.auth.can_view_sync_logs"
                        :href="route('admin.sync-logs')"
                        class="fieldops-sync-status-trigger"
                        :class="`fieldops-sync-status-trigger--${$page.props.sync_health?.status ?? 'warning'}`"
                        :aria-label="`${$page.props.sync_health?.label ?? 'Sync status'}; open synchronization logs`"
                        :title="$page.props.sync_health?.summary ?? 'Open synchronization logs'"
                        :aria-current="route().current('admin.sync-logs') ? 'page' : undefined"
                    >
                        <ArrowPathIcon class="fieldops-sync-status-icon" aria-hidden="true" />
                        <span class="fieldops-sync-status-dot" aria-hidden="true"></span>
                    </Link>
                    <span
                        v-else
                        class="fieldops-sync-status-placeholder"
                        role="img"
                        aria-label="Synchronization details require integration access"
                        title="Synchronization status"
                    >
                        <ArrowPathIcon class="fieldops-sync-status-icon" aria-hidden="true" />
                    </span>

                </div>
            </div>

            <div
                v-if="$page.props.auth.is_admin"
                class="fieldops-admin-tabs-shell"
            >
                <nav class="fieldops-admin-tabs" aria-label="Admin sections">
                    <Link
                        v-for="tab in adminTabs"
                        :key="tab.key"
                        :href="route(tab.route)"
                        class="fieldops-admin-tab"
                        :aria-label="tab.label"
                        :title="tab.label"
                        :class="{
                            'fieldops-admin-tab--active': isAdminTabActive(tab.key),
                            'fieldops-admin-tab--archive': tab.key === 'archive',
                        }"
                        :aria-current="isAdminTabActive(tab.key) ? 'page' : undefined"
                    >
                        <component
                            :is="tab.icon"
                            class="fieldops-admin-tab-icon"
                            aria-hidden="true"
                        />
                        <span class="fieldops-admin-tab-label">{{ tab.label }}</span>
                    </Link>
                </nav>
            </div>

            <div
                v-if="$page.props.auth.is_admin && isOperationsArea()"
                class="fieldops-operations-subnav-shell"
            >
                <nav class="fieldops-operations-subnav" aria-label="Operations tools">
                    <Link
                        :href="route('admin.users')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.users'),
                        }"
                        :aria-current="route().current('admin.users') ? 'page' : undefined"
                    >
                        <UsersIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Staff</span>
                    </Link>
                    <Link
                        v-if="$page.props.auth.can_view_sync_logs"
                        :href="route('admin.sync-logs')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.sync-logs'),
                        }"
                        :aria-current="route().current('admin.sync-logs') ? 'page' : undefined"
                    >
                        <ArrowPathIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Logs</span>
                    </Link>
                    <Link
                        v-if="$page.props.auth.can_view_sync_logs"
                        :href="route('admin.onboarding.access')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': isOnboardingArea(),
                            'fieldops-operations-subnav-link--onboarding-active': isOnboardingArea(),
                        }"
                        :aria-current="isOnboardingArea() ? 'page' : undefined"
                    >
                        <ShieldCheckIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Onboarding</span>
                    </Link>
                </nav>
            </div>

            <div
                v-if="$page.props.auth.is_admin && isOnboardingArea() && $page.props.auth.onboarding_area_unlocked"
                class="fieldops-operations-subnav-shell"
            >
                <nav class="fieldops-operations-subnav" aria-label="Onboarding and integrations">
                    <Link
                        :href="route('admin.integrations.quickbooks.show')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.integrations.quickbooks.*'),
                        }"
                        :aria-current="route().current('admin.integrations.quickbooks.*') ? 'page' : undefined"
                    >
                        <WrenchScrewdriverIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>QuickBooks</span>
                    </Link>
                    <Link
                        :href="route('admin.integrations.bitwarden.show')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.integrations.bitwarden.*'),
                        }"
                        :aria-current="route().current('admin.integrations.bitwarden.*') ? 'page' : undefined"
                    >
                        <WrenchScrewdriverIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Bitwarden</span>
                    </Link>
                    <Link
                        :href="route('admin.integrations.microsoft-graph.onboarding-targets.show')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.integrations.microsoft-graph.*'),
                        }"
                        :aria-current="route().current('admin.integrations.microsoft-graph.*') ? 'page' : undefined"
                    >
                        <WrenchScrewdriverIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Microsoft Graph</span>
                    </Link>
                    <Link
                        :href="route('admin.onboarding')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.onboarding'),
                        }"
                        :aria-current="route().current('admin.onboarding') ? 'page' : undefined"
                    >
                        <ClipboardDocumentCheckIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>First-run setup</span>
                    </Link>
                </nav>
            </div>

            <div
                v-if="$page.props.auth.is_admin && isTimeTrackingArea()"
                class="fieldops-operations-subnav-shell"
            >
                <nav class="fieldops-operations-subnav" aria-label="Time tracking sections">
                    <Link
                        :href="route('admin.time-tracking.dashboard')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.time-tracking.dashboard'),
                        }"
                        :aria-current="route().current('admin.time-tracking.dashboard') ? 'page' : undefined"
                    >
                        <Squares2X2Icon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Dashboard</span>
                    </Link>
                    <Link
                        :href="route('admin.time-tracking.review')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.time-tracking.review'),
                        }"
                        :aria-current="route().current('admin.time-tracking.review') ? 'page' : undefined"
                    >
                        <ClipboardDocumentCheckIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Review</span>
                    </Link>
                    <Link
                        :href="route('admin.time-tracking.payroll')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.time-tracking.payroll'),
                        }"
                        :aria-current="route().current('admin.time-tracking.payroll') ? 'page' : undefined"
                    >
                        <ChartBarIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Payroll</span>
                    </Link>
                    <Link
                        :href="route('admin.time-tracking.billing')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.time-tracking.billing'),
                        }"
                        :aria-current="route().current('admin.time-tracking.billing') ? 'page' : undefined"
                    >
                        <CurrencyDollarIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Billing</span>
                    </Link>
                    <Link
                        :href="route('admin.time-tracking.exceptions')"
                        class="fieldops-operations-subnav-link"
                        :class="{
                            'fieldops-operations-subnav-link--active': route().current('admin.time-tracking.exceptions'),
                        }"
                        :aria-current="route().current('admin.time-tracking.exceptions') ? 'page' : undefined"
                    >
                        <ExclamationTriangleIcon class="fieldops-operations-subnav-icon" aria-hidden="true" />
                        <span>Exceptions</span>
                    </Link>
                </nav>
            </div>

            <div
                v-if="showingNavigationDropdown && !$page.props.auth.is_admin"
                class="fieldops-mobile-nav"
            >
                <Link
                    :href="route('calendar')"
                    class="fieldops-mobile-nav-link"
                    :class="{
                        'fieldops-mobile-nav-link--active': route().current('calendar'),
                    }"
                >
                    Schedule
                </Link>
                <div class="fieldops-mobile-user">
                    <div class="fieldops-mobile-user-name">
                        {{ $page.props.auth.user.name }}
                    </div>
                    <div class="fieldops-mobile-user-email">
                        {{ $page.props.auth.user.email }}
                    </div>
                    <div class="mt-2 grid gap-1">
                        <Link
                            :href="route('profile.edit')"
                            class="fieldops-mobile-nav-link"
                        >
                            Profile
                        </Link>
                    </div>
                </div>
            </div>
        </nav>

        <header class="fieldops-page-header" v-if="$slots.header">
            <div class="fieldops-page-header-inner">
                <slot name="header" />
            </div>
        </header>

        <main class="fieldops-main">
            <slot />
        </main>

        <Link
            :href="route('logout')"
            method="post"
            as="button"
            class="fieldops-logout-action"
            aria-label="Log out of FieldOps"
        >
            Log out
        </Link>
    </div>
</template>
