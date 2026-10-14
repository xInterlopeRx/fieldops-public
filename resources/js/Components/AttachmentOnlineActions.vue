<script setup lang="ts">
import axios from "axios";
import { ref } from "vue";

const props = defineProps<{
    attachmentId: number;
    fileName: string;
    onlineUrl?: string | null;
    openUrl?: string | null;
    availabilityUrl?: string | null;
}>();

const emit = defineEmits<{ missing: [attachmentId: number] }>();
const opening = ref(false);
const message = ref("");
const missing = ref(false);

const open = async (): Promise<void> => {
    if (!props.onlineUrl || opening.value) return;

    message.value = "";
    missing.value = false;
    const popup = window.open("about:blank", "_blank");

    if (!popup) {
        message.value = "Allow pop-ups to open this attachment.";

        return;
    }

    popup.opener = null;
    opening.value = true;

    try {
        if (props.availabilityUrl) {
            await axios.get(props.availabilityUrl, {
                headers: { Accept: "application/json" },
            });
        }

        popup.location.replace(props.openUrl || props.onlineUrl);
    } catch (error) {
        if (axios.isAxiosError(error) && [404, 410].includes(error.response?.status ?? 0)) {
            popup.close();
            missing.value = true;
            message.value = "SharePoint no longer has this file. Its stale attachment link was removed.";
            emit("missing", props.attachmentId);
        } else if (axios.isAxiosError(error) && !error.response) {
            message.value = "Could not check SharePoint; opening the saved link instead.";
            popup.location.replace(props.onlineUrl || props.openUrl || "about:blank");
        } else {
            message.value = axios.isAxiosError(error)
                ? error.response?.data?.message ?? "This attachment could not be checked. Its record was left unchanged."
                : "This attachment could not be checked. Its record was left unchanged.";
            popup.location.replace(props.onlineUrl || props.openUrl || "about:blank");
        }
    } finally {
        opening.value = false;
    }
};
</script>

<template>
    <span v-if="onlineUrl" class="flex flex-col items-start gap-1">
        <button
            type="button"
            class="fieldops-secondary-action min-h-9 px-3 py-1 text-xs"
            :disabled="opening"
            :aria-label="`Open ${fileName}`"
            @click="open"
        >
            {{ opening ? "Checking…" : "Open" }}
        </button>
        <span v-if="message" :role="missing ? 'status' : 'alert'" class="max-w-56 text-xs text-amber-900">
            {{ message }}
        </span>
    </span>
</template>
