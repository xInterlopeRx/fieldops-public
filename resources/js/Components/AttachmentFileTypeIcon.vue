<script setup lang="ts">
import { computed, ref, watch } from "vue";
import type { Component } from "vue";
import {
    DocumentIcon,
    DocumentTextIcon,
    FilmIcon,
    PhotoIcon,
    PresentationChartLineIcon,
    TableCellsIcon,
    VideoCameraIcon,
} from "@heroicons/vue/24/outline";

const props = defineProps<{
    fileName: string;
    mimeType: string;
    thumbnailUrl?: string | null;
}>();

const thumbnailFailed = ref(false);
watch(() => props.thumbnailUrl, () => { thumbnailFailed.value = false; });

const fileType = computed(() => {
    const extension = props.fileName.split(".").pop()?.toLowerCase() ?? "";
    const mimeType = props.mimeType.toLowerCase();

    const typeMap: Record<string, { icon: Component; color: string; label: string }> = {
        pdf: { icon: DocumentTextIcon, color: "text-red-700", label: "PDF" },
        doc: { icon: DocumentTextIcon, color: "text-blue-700", label: "DOC" },
        docx: { icon: DocumentTextIcon, color: "text-blue-700", label: "DOCX" },
        xls: { icon: TableCellsIcon, color: "text-green-700", label: "XLS" },
        xlsx: { icon: TableCellsIcon, color: "text-green-700", label: "XLSX" },
        csv: { icon: TableCellsIcon, color: "text-green-700", label: "CSV" },
        ppt: { icon: PresentationChartLineIcon, color: "text-orange-700", label: "PPT" },
        pptx: { icon: PresentationChartLineIcon, color: "text-orange-700", label: "PPTX" },
        txt: { icon: DocumentTextIcon, color: "text-gray-600", label: "TXT" },
        jpg: { icon: PhotoIcon, color: "text-purple-700", label: "JPG" },
        jpeg: { icon: PhotoIcon, color: "text-purple-700", label: "JPEG" },
        png: { icon: PhotoIcon, color: "text-purple-700", label: "PNG" },
        gif: { icon: PhotoIcon, color: "text-purple-700", label: "GIF" },
        webp: { icon: PhotoIcon, color: "text-purple-700", label: "WEBP" },
        mp4: { icon: FilmIcon, color: "text-rose-700", label: "MP4" },
        mov: { icon: FilmIcon, color: "text-rose-700", label: "MOV" },
        webm: { icon: VideoCameraIcon, color: "text-rose-700", label: "WEBM" },
    };

    const extensionType = typeMap[extension];

    if (extensionType) return extensionType;
    if (mimeType.startsWith("image/")) return { icon: PhotoIcon, color: "text-purple-700", label: "IMAGE" };
    if (mimeType.startsWith("video/")) return { icon: VideoCameraIcon, color: "text-rose-700", label: "VIDEO" };
    if (mimeType.startsWith("text/")) return { icon: DocumentTextIcon, color: "text-gray-600", label: "TEXT" };

    return { icon: DocumentIcon, color: "text-gray-600", label: extension ? extension.slice(0, 5).toUpperCase() : "FILE" };
});
</script>

<template>
    <span
        class="relative grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-lg border border-gray-200 bg-gray-50"
        role="img"
        :aria-label="`${fileType.label} file`"
    >
        <component :is="fileType.icon" class="h-7 w-7" :class="fileType.color" aria-hidden="true" />
        <img
            v-if="thumbnailUrl && !thumbnailFailed"
            :src="thumbnailUrl"
            alt=""
            aria-hidden="true"
            loading="lazy"
            decoding="async"
            class="absolute inset-0 h-full w-full bg-gray-100 object-cover"
            @error="thumbnailFailed = true"
        />
        <span class="absolute inset-x-0 bottom-0 bg-white/90 px-0.5 text-center text-[0.5rem] font-bold leading-3 text-gray-700" aria-hidden="true">
            {{ fileType.label }}
        </span>
    </span>
</template>
