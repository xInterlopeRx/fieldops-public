<script setup lang="ts">
import axios from "axios";
import { computed, reactive, ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";

interface AddressDraft {
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

interface SiteDraft {
    name: string;
    addressSource: "billing" | "shipping";
    address: AddressDraft;
}

interface ProjectDraft {
    name: string;
}

const emit = defineEmits<{
    cancel: [];
    created: [customerId: number];
}>();

const page = usePage();
const saving = ref(false);
const errors = ref<Record<string, string[]>>({});
const errorMessage = ref("");
const shippingAddressSameAsBilling = ref(false);

const emptyAddress = (): AddressDraft => ({
    line1: "",
    line2: "",
    line3: "",
    line4: "",
    line5: "",
    city: "",
    country_subdivision_code: "",
    postal_code: "",
    country: "",
});

const form = reactive({
    name: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    billing_address: emptyAddress(),
    shipping_address: emptyAddress(),
    notes: "",
    sites: [] as SiteDraft[],
    projects: [] as ProjectDraft[],
});

const hasUnsavedChanges = (): boolean => {
    const textValues = [
        form.name,
        form.first_name,
        form.last_name,
        form.email,
        form.phone,
        form.notes,
        ...Object.values(form.billing_address),
        ...Object.values(form.shipping_address),
    ];

    return shippingAddressSameAsBilling.value
        || textValues.some((value) => value.trim() !== "")
        || form.sites.length > 0
        || form.projects.length > 0;
};

const addressDraftSummary = (address: AddressDraft): string => [
    [address.line1, address.line2, address.line3, address.line4, address.line5]
        .map((value) => value.trim())
        .filter(Boolean)
        .join(", "),
    [address.city, address.country_subdivision_code, address.postal_code]
        .map((value) => value.trim())
        .filter(Boolean)
        .join(" "),
    address.country.trim(),
].filter(Boolean).join(" · ");

const copyBillingAddressToShipping = (): void => {
    Object.assign(form.shipping_address, form.billing_address);
};

watch(
    () => ({ ...form.billing_address }),
    () => {
        if (shippingAddressSameAsBilling.value) copyBillingAddressToShipping();
    },
);

const addSite = (addressSource: SiteDraft["addressSource"]): void => {
    const sourceCount = form.sites.filter((site) => site.addressSource === addressSource).length;
    const label = addressSource === "billing" ? "Billing" : "Shipping";

    form.sites.push({
        name: `${label} Site${sourceCount ? ` ${sourceCount + 1}` : ""}`,
        addressSource,
        address: { ...form[`${addressSource}_address`] },
    });
};

const addProject = (): void => {
    form.projects.push({ name: "" });
};

const removeProject = (index: number): void => {
    form.projects.splice(index, 1);
};

const messageFor = (key: string): string | undefined => errors.value[key]?.[0];

const submit = async (): Promise<boolean> => {
    if (saving.value) return false;

    saving.value = true;
    errors.value = {};
    errorMessage.value = "";

    try {
        const response = await axios.post(route("customers.store"), {
            ...form,
            shipping_address_same_as_billing: shippingAddressSameAsBilling.value,
            sites: form.sites.map(({ name, address }) => ({ name, address })),
            projects: form.projects.map(({ name }) => ({ name })),
        }, {
            headers: { Accept: "application/json" },
        });

        emit("created", Number(response.data.data.id));
        return true;
    } catch (error: unknown) {
        const response = (error as {
            response?: {
                data?: {
                    errors?: Record<string, string[]>;
                    message?: string;
                };
            };
        }).response;

        if (response?.data?.errors) {
            errors.value = response.data.errors;
        } else {
            errorMessage.value = response?.data?.message
                ?? "The Customer could not be created. Try again.";
        }

        return false;
    } finally {
        saving.value = false;
    }
};

const states = computed(() => page.props.us_states);

defineExpose({ hasUnsavedChanges, submit });
</script>

<template>
    <form class="grid min-w-0 grid-cols-1 content-start gap-4" @submit.prevent="submit">
        <label class="fieldops-workspace-field">
            Customer name
            <input v-model="form.name" required maxlength="255" autofocus />
            <span v-if="messageFor('name')" class="fieldops-client-error">{{ messageFor('name') }}</span>
        </label>

        <section class="grid gap-3" aria-labelledby="new-client-primary-contact-heading">
            <h3 id="new-client-primary-contact-heading" class="fieldops-client-label mb-0">Primary contact</h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="fieldops-workspace-field">
                    First name
                    <input v-model="form.first_name" maxlength="100" />
                    <span v-if="messageFor('first_name')" class="fieldops-client-error">{{ messageFor('first_name') }}</span>
                </label>
                <label class="fieldops-workspace-field">
                    Last name
                    <input v-model="form.last_name" maxlength="100" />
                    <span v-if="messageFor('last_name')" class="fieldops-client-error">{{ messageFor('last_name') }}</span>
                </label>
                <label class="fieldops-workspace-field">
                    Email
                    <input v-model="form.email" type="email" maxlength="255" />
                    <span v-if="messageFor('email')" class="fieldops-client-error">{{ messageFor('email') }}</span>
                </label>
                <label class="fieldops-workspace-field">
                    Phone
                    <input v-model="form.phone" type="tel" maxlength="30" />
                    <span v-if="messageFor('phone')" class="fieldops-client-error">{{ messageFor('phone') }}</span>
                </label>
            </div>
        </section>

        <fieldset class="grid gap-3 rounded-xl border border-fieldops-blue/20 p-3 sm:p-4">
            <legend class="fieldops-client-label px-1">Billing address</legend>
            <div class="flex justify-end">
                <button type="button" class="fieldops-secondary-action fieldops-add-action" @click="addSite('billing')">Add site</button>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="fieldops-workspace-field sm:col-span-2">Street address or PO Box<input v-model="form.billing_address.line1" maxlength="500" /></label>
                <label class="fieldops-workspace-field sm:col-span-2">Address line 2<input v-model="form.billing_address.line2" maxlength="500" /></label>
                <label class="fieldops-workspace-field">City<input v-model="form.billing_address.city" maxlength="255" /></label>
                <label class="fieldops-workspace-field">State
                    <select v-model="form.billing_address.country_subdivision_code">
                        <option value="">Select a state</option>
                        <option v-for="state in states" :key="state.value" :value="state.value">{{ state.label }}</option>
                    </select>
                </label>
                <label class="fieldops-workspace-field">ZIP code<input v-model="form.billing_address.postal_code" maxlength="64" /></label>
                <label class="fieldops-workspace-field">Country (optional)<input v-model="form.billing_address.country" maxlength="100" /></label>
            </div>
            <span v-if="messageFor('billing_address.line1')" class="fieldops-client-error">{{ messageFor('billing_address.line1') }}</span>
        </fieldset>

        <fieldset class="grid gap-3 rounded-xl border border-fieldops-blue/20 p-3 sm:p-4">
            <legend class="fieldops-client-label px-1">Shipping address</legend>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <label class="inline-flex min-h-10 items-center gap-2 text-sm text-gray-700">
                    <input
                        v-model="shippingAddressSameAsBilling"
                        type="checkbox"
                        class="h-4 w-4 rounded border-gray-300 text-blue-700 focus:ring-blue-700"
                        @change="shippingAddressSameAsBilling && copyBillingAddressToShipping()"
                    />
                    Same as billing
                </label>
                <button type="button" class="fieldops-secondary-action fieldops-add-action" @click="addSite('shipping')">Add site</button>
            </div>
            <p v-if="shippingAddressSameAsBilling" class="text-sm text-gray-600">Billing is also saved as the Customer’s default shipping address.</p>
            <div v-else class="grid gap-3 sm:grid-cols-2">
                <label class="fieldops-workspace-field sm:col-span-2">Street address or PO Box<input v-model="form.shipping_address.line1" maxlength="500" /></label>
                <label class="fieldops-workspace-field sm:col-span-2">Address line 2<input v-model="form.shipping_address.line2" maxlength="500" /></label>
                <label class="fieldops-workspace-field">City<input v-model="form.shipping_address.city" maxlength="255" /></label>
                <label class="fieldops-workspace-field">State
                    <select v-model="form.shipping_address.country_subdivision_code">
                        <option value="">Select a state</option>
                        <option v-for="state in states" :key="state.value" :value="state.value">{{ state.label }}</option>
                    </select>
                </label>
                <label class="fieldops-workspace-field">ZIP code<input v-model="form.shipping_address.postal_code" maxlength="64" /></label>
                <label class="fieldops-workspace-field">Country (optional)<input v-model="form.shipping_address.country" maxlength="100" /></label>
            </div>
            <span v-if="messageFor('shipping_address.line1')" class="fieldops-client-error">{{ messageFor('shipping_address.line1') }}</span>
        </fieldset>

        <section class="grid gap-3" aria-labelledby="new-client-sites-heading">
            <h3 id="new-client-sites-heading" class="fieldops-client-label mb-0">Sites</h3>
            <article v-for="(site, index) in form.sites" :key="`new-site-${index}`" class="grid gap-2 rounded-xl border border-fieldops-blue/20 bg-white/35 p-3">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-gray-900">{{ site.addressSource === 'billing' ? 'Billing' : 'Shipping' }} address site</p>
                    <button type="button" class="fieldops-workspace-remove" :aria-label="`Remove ${site.name}`" @click="form.sites.splice(index, 1)">Remove site</button>
                </div>
                <label class="fieldops-workspace-field">Site name<input v-model="site.name" required maxlength="255" /></label>
                <p class="text-sm text-gray-600">{{ addressDraftSummary(site.address) || 'No address entered yet. Add an address above or save it for later.' }}</p>
                <span v-if="messageFor(`sites.${index}.name`)" class="fieldops-client-error">{{ messageFor(`sites.${index}.name`) }}</span>
            </article>
            <p v-if="form.sites.length === 0" class="text-sm text-gray-600">No Sites added yet. Add a Site from the Billing or Shipping address above.</p>
        </section>

        <section class="grid gap-3" aria-labelledby="new-client-projects-heading">
            <div class="flex items-center justify-between gap-3">
                <h3 id="new-client-projects-heading" class="fieldops-client-label mb-0">Projects</h3>
                <button type="button" class="fieldops-secondary-action fieldops-add-action" @click="addProject">Add project</button>
            </div>
            <article v-for="(project, index) in form.projects" :key="`new-project-${index}`" class="grid gap-3 rounded-xl border border-fieldops-blue/20 bg-white/35 p-3 sm:grid-cols-2">
                <label class="fieldops-workspace-field sm:col-span-2">Project name<input v-model="project.name" required maxlength="255" /></label>
                <button type="button" class="fieldops-workspace-remove self-end" @click="removeProject(index)">Remove project</button>
                <span v-if="messageFor(`projects.${index}.name`)" class="fieldops-client-error sm:col-span-2">{{ messageFor(`projects.${index}.name`) }}</span>
            </article>
            <p v-if="form.projects.length === 0" class="text-sm text-gray-600">No Projects added yet.</p>
        </section>

        <label class="fieldops-workspace-field">Notes<textarea v-model="form.notes" rows="4" maxlength="2000" /></label>
        <p v-if="errorMessage" role="alert" class="text-sm text-red-700">{{ errorMessage }}</p>
        <div class="flex justify-end gap-2 border-t border-fieldops-blue/10 pt-4">
            <button type="button" class="fieldops-secondary-action min-h-10" :disabled="saving" @click="emit('cancel')">Cancel</button>
            <button type="submit" class="fieldops-submit-action fieldops-add-action min-h-10" :disabled="saving || !form.name.trim()">
                {{ saving ? "Saving…" : "Add Customer" }}
            </button>
        </div>
    </form>
</template>
