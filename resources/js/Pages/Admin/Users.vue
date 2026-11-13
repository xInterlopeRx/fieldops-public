<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { reactive } from "vue";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import type { PageProps } from "@/types";

const props = defineProps<{
    currentUserId: number;
    onboarding: {
        required: boolean;
        invitationsUnlocked: boolean;
    };
    users: Array<{
        id: number;
        name: string;
        email: string;
        roles: string[];
        status: string;
        created_at: string;
    }>;
    pendingInvitations: Array<{
        id: number;
        name: string;
        email: string;
        roles: string[];
        expires_at: string;
    }>;
}>();

const inviteForm = useForm({
    name: "",
    email: "",
    management: false,
    billing: false,
    roles: [] as string[],
});
const page = usePage<PageProps<{ flash?: { invitation_url?: string; success?: string } }>>();
const roleSelections = reactive<
    Record<number, { management: boolean; billing: boolean; finance: boolean; hr: boolean }>
>(
    Object.fromEntries(
        props.users.map((user) => [
            user.id,
            {
                management: user.roles.includes("management"),
                billing: user.roles.includes("billing"),
                finance: user.roles.includes("finance"),
                hr: user.roles.includes("hr"),
            },
        ]),
    ),
);

const invite = () => {
    const roles = ["basic_user"];
    if (inviteForm.management) roles.push("management");
    if (inviteForm.billing) roles.push("billing");
    inviteForm.roles = roles;

    inviteForm.transform(() => ({
        name: inviteForm.name,
        email: inviteForm.email,
        roles,
    })).post(route("admin.users.invitations.store"), {
        preserveScroll: true,
        onSuccess: () => inviteForm.reset(),
    });
};

const updateRoles = (userId: number) => {
    const selection = roleSelections[userId];
    const securityGroups = [
        ...(selection.finance ? ["finance"] : []),
        ...(selection.hr ? ["hr"] : []),
    ];
    const notificationRoles = [
        ...(selection.management ? ["management"] : []),
        ...(selection.billing ? ["billing"] : []),
    ];

    router.patch(
        route("admin.users.roles.update", userId),
        { security_groups: securityGroups, notification_roles: notificationRoles },
        { preserveScroll: true },
    );
};

const roleLabels: Record<string, string> = {
    admin: "Admin",
    basic_user: "Basic User",
    management: "Management",
    billing: "Billing",
    finance: "Finance",
    hr: "HR",
};
const label = (role: string) => roleLabels[role] ?? role;
</script>

<template>
    <Head title="User management" />
    <AdminLayout title="User management">
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <p v-if="page.props.flash?.success" role="status" class="rounded-lg border border-green-300 bg-green-50 p-3 text-sm text-green-900">
                {{ page.props.flash.success }}
            </p>
            <section class="fieldops-panel bg-white p-6">
                <h1 class="text-lg font-semibold">Invite a user</h1>
                <p class="mt-1 text-sm text-gray-600">
                    New users start as Basic User. Admin is transferred separately after a new account is active. Security groups are assigned from each user's Staff profile; Management and Billing are notification roles only.
                </p>
                <div
                    v-if="onboarding.required && !onboarding.invitationsUnlocked"
                    class="mt-4 grid gap-2 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950"
                    role="status"
                >
                    <p class="font-semibold">Invitations are locked during first-run setup.</p>
                    <p>Complete the integration readiness checklist before adding staff.</p>
                    <Link :href="route('admin.onboarding')" class="w-fit font-semibold underline">
                        Resume setup
                    </Link>
                </div>
                <form
                    class="mt-4 grid gap-4 sm:grid-cols-2"
                    @submit.prevent="invite"
                >
                    <input
                        v-model="inviteForm.name"
                        class="fieldops-client-input"
                        placeholder="Name"
                        required
                    />
                    <input
                        v-model="inviteForm.email"
                        class="fieldops-client-input"
                        type="email"
                        placeholder="Email"
                        required
                    />
                    <p class="self-center text-sm text-gray-600">Account role: Basic User</p>
                    <fieldset class="flex flex-wrap items-center gap-4 text-sm">
                        <legend class="sr-only">Notification roles</legend>
                        <label class="flex items-center gap-2">
                            <input v-model="inviteForm.management" type="checkbox" />
                            Management notifications
                        </label>
                        <label class="flex items-center gap-2">
                            <input v-model="inviteForm.billing" type="checkbox" />
                            Billing notifications
                        </label>
                    </fieldset>
                    <button
                        class="fieldops-submit-action"
                        type="submit"
                        :disabled="inviteForm.processing || !onboarding.invitationsUnlocked"
                    >
                        Create invitation
                    </button>
                </form>
                <p v-if="inviteForm.errors.roles" class="mt-2 text-sm text-red-700">
                    {{ inviteForm.errors.roles }}
                </p>
                <p
                    v-if="page.props.flash?.invitation_url"
                    class="mt-3 break-all text-sm text-green-700"
                >
                    Invitation URL: {{ page.props.flash.invitation_url }}
                </p>
            </section>

            <section class="fieldops-panel bg-white p-6">
                <h2 class="text-lg font-semibold">Users</h2>
                <div class="mt-4 divide-y">
                    <div
                        v-for="user in users"
                        :key="user.id"
                        class="flex items-center justify-between py-3"
                    >
                        <div>
                            <strong>{{ user.name }}</strong>
                            <div class="text-sm text-gray-600">
                                {{ user.email }} · {{ user.roles.map(label).join(", ") }} ·
                                {{ user.status }}
                            </div>
                        </div>
                        <div class="grid gap-2 sm:min-w-72">
                            <p class="text-xs text-gray-600">
                                Account: {{ user.roles.includes("admin") ? "Admin" : "Basic User" }}
                                <span v-if="user.roles.includes('admin')"> · transferred separately</span>
                            </p>
                            <fieldset class="grid gap-1 text-xs">
                                <legend class="font-semibold text-gray-700">Security groups</legend>
                                <label class="flex items-center gap-2">
                                    <input v-model="roleSelections[user.id].finance" type="checkbox" />
                                    Finance
                                </label>
                                <label class="flex items-center gap-2">
                                    <input v-model="roleSelections[user.id].hr" type="checkbox" />
                                    HR
                                </label>
                            </fieldset>
                            <fieldset class="grid gap-1 text-xs">
                                <legend class="font-semibold text-gray-700">Notification roles</legend>
                                <label class="flex items-center gap-2">
                                    <input v-model="roleSelections[user.id].management" type="checkbox" />
                                    Management notifications
                                </label>
                                <label class="flex items-center gap-2">
                                    <input v-model="roleSelections[user.id].billing" type="checkbox" />
                                    Billing notifications
                                </label>
                            </fieldset>
                            <div class="flex flex-wrap gap-2">
                                <button class="fieldops-secondary-action min-h-9 px-3" type="button" @click="updateRoles(user.id)">
                                    Save profile roles
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="fieldops-panel bg-white p-6">
                <h2 class="text-lg font-semibold">Pending invitations</h2>
                <div class="mt-4 divide-y">
                    <div
                        v-for="invitation in pendingInvitations"
                        :key="invitation.id"
                        class="flex items-center justify-between py-3"
                    >
                        <div>
                            <strong>{{ invitation.name }}</strong>
                            <div class="text-sm text-gray-600">
                                {{ invitation.email }} · {{ invitation.roles.map(label).join(", ") }} ·
                                expires {{ invitation.expires_at }}
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="pendingInvitations.length === 0"
                        class="py-3 text-sm text-gray-600"
                    >
                        No pending invitations.
                    </p>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>
