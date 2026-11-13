<script setup lang="ts">
import { Head, Link } from "@inertiajs/vue3";
import AdminLayout from "@/Layouts/AdminLayout.vue";
</script>

<template>
    <Head title="Operations" />

    <AdminLayout title="Operations">
        <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
            <section class="fieldops-panel bg-white p-4 sm:p-5">
                <p class="text-sm text-gray-600">
                    Occasional staff and integration maintenance. The monthly
                    schedule remains the primary workspace for technicians.
                </p>
            </section>
            <section v-if="$page.props.auth.has_admin_account_role" class="fieldops-panel grid gap-3 bg-white p-4 sm:p-5">
                <div>
                    <h2 class="font-semibold text-gray-900">Admin account</h2>
                    <p class="mt-1 text-sm text-gray-600">Transfer the single Admin account role through a recipient-acceptance handoff. Security groups remain assigned to each person separately.</p>
                </div>
                <Link :href="route('admin.admin-role-transfers.create')" class="fieldops-secondary-action min-h-10 w-fit">Transfer Admin role</Link>
            </section>
        </div>
    </AdminLayout>
</template>
