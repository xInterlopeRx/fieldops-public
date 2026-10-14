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
    accountRole: "basic_user",
    management: false,
    billing: false,
    roles: [] as string[],
});
const page = usePage<PageProps<{ flash?: { invitation_url?: string } }>>();
const roleSelections = reactive<
    Record<number, { accountRole: string; management: boolean; billing: boolean }>
>(
    Object.fromEntries(
        props.users.map((user) => [
            user.id,
            {
                accountRole: user.roles.includes("admin") ? "admin" : "basic_user",
                management: user.roles.includes("management"),
                billing: user.roles.includes("billing"),
            },
        ]),
    ),
);

const invite = () => {
    const roles = [inviteForm.accountRole];
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
    const roles = [selection.accountRole];
    if (selection.management) roles.push("management");
    if (selection.billing) roles.push("billing");

    router.patch(
        route("admin.users.roles.update", userId),
        { roles },
        { preserveScroll: true },
    );
};

const roleLabels: Record<string, string> = {
    admin: "Admin",
    basic_user: "Basic User",
    management: "Management",
    billing: "Billing",
};
const label = (role: string) => roleLabels[role] ?? role;
</script>

<template>
    <Head title="User management" />
    <AdminLayout title="User management">
        <div class="mx-auto max-w-7xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <section class="fieldops-panel bg-white p-6">
                <h1 class="text-lg font-semibold">Invite a user</h1>
                <p class="mt-1 text-sm text-gray-600">
                    Choose one account role. Management and Billing add notification routing only.
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
                    <label class="grid gap-1 text-sm">
                        <span>Account role</span>
                        <select v-model="inviteForm.accountRole" class="fieldops-client-input">
                            <option value="basic_user">Basic User</option>
                            <option value="admin">Admin</option>
                        </select>
                    </label>
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
                        <form class="grid gap-2 sm:grid-cols-[12rem_auto]" @submit.prevent="updateRoles(user.id)">
                            <div class="grid gap-2">
                                <label class="grid gap-1 text-xs">
                                    <span>Account role</span>
                                    <select
                                        v-model="roleSelections[user.id].accountRole"
                                        class="fieldops-client-input"
                                        :disabled="user.id === currentUserId"
                                        :aria-label="`Account role for ${user.name}`"
                                    >
                                        <option value="basic_user">Basic User</option>
                                        <option value="admin">Admin</option>
                                    </select>
                                </label>
                                <label class="flex items-center gap-2 text-xs">
                                    <input v-model="roleSelections[user.id].management" type="checkbox" />
                                    Management notifications
                                </label>
                                <label class="flex items-center gap-2 text-xs">
                                    <input v-model="roleSelections[user.id].billing" type="checkbox" />
                                    Billing notifications
                                </label>
                            </div>
                            <button class="fieldops-submit-action self-start" type="submit">
                                Save roles
                            </button>
                        </form>
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
