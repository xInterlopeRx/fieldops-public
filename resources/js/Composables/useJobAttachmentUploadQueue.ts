import axios from "axios";
import { onBeforeUnmount, onMounted, ref, shallowRef } from "vue";

const DATABASE_NAME = "fieldops-job-attachment-queue";
const STORE_NAME = "uploads";
const DATABASE_VERSION = 1;
const MAX_PENDING_FILES = 20;
const MAX_FILE_BYTES = 20 * 1024 * 1024;

type StoredStatus = "queued" | "failed";
export type JobAttachmentUploadStatus = StoredStatus | "uploading";

export interface JobAttachmentUpload {
    clientUploadId: string;
    userId: number;
    workOrderId: number;
    name: string;
    mimeType: string;
    size: number;
    lastModified: number;
    createdAt: number;
    blob: Blob;
    status: JobAttachmentUploadStatus;
    error: string | null;
    retryCount: number;
}

interface JobAttachmentUploadQueueOptions {
    userId: number;
    workOrderId: number;
    onUploaded: (attachment: Record<string, unknown>) => void;
}

let databasePromise: Promise<IDBDatabase> | null = null;

const openDatabase = (): Promise<IDBDatabase> => {
    if (databasePromise !== null) return databasePromise;

    const pendingDatabase = new Promise<IDBDatabase>((resolve, reject) => {
        if (typeof indexedDB === "undefined") {
            reject(new Error("This browser cannot save pending uploads on this device."));

            return;
        }

        const request = indexedDB.open(DATABASE_NAME, DATABASE_VERSION);
        request.onupgradeneeded = () => {
            const database = request.result;
            const store = database.objectStoreNames.contains(STORE_NAME)
                ? request.transaction?.objectStore(STORE_NAME)
                : database.createObjectStore(STORE_NAME, { keyPath: "clientUploadId" });

            if (store && !store.indexNames.contains("userAndJob")) {
                store.createIndex("userAndJob", ["userId", "workOrderId"], { unique: false });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error ?? new Error("Could not open this device’s upload queue."));
        request.onblocked = () => reject(new Error("Close other FieldOps tabs to update this device’s upload queue."));
    }).catch((error: unknown): never => {
        databasePromise = null;
        throw error;
    });

    databasePromise = pendingDatabase;

    return pendingDatabase;
};

const listStoredUploads = async (userId: number, workOrderId: number): Promise<JobAttachmentUpload[]> => {
    const database = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = database.transaction(STORE_NAME, "readonly");
        const request = transaction.objectStore(STORE_NAME)
            .index("userAndJob")
            .getAll(IDBKeyRange.only([userId, workOrderId]));

        request.onsuccess = () => resolve((request.result as JobAttachmentUpload[])
            .sort((left, right) => left.createdAt - right.createdAt));
        request.onerror = () => reject(request.error ?? new Error("Could not read pending uploads."));
    });
};

const storeUploads = async (uploads: JobAttachmentUpload[]): Promise<void> => {
    const database = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = database.transaction(STORE_NAME, "readwrite");
        const store = transaction.objectStore(STORE_NAME);

        uploads.forEach((upload) => store.put({ ...upload, status: "queued" as const }));
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error ?? new Error("Could not save uploads on this device."));
        transaction.onabort = () => reject(transaction.error ?? new Error("Could not save uploads on this device."));
    });
};

const updateStoredUpload = async (upload: JobAttachmentUpload): Promise<void> => {
    const database = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = database.transaction(STORE_NAME, "readwrite");
        transaction.objectStore(STORE_NAME).put({
            ...upload,
            status: upload.status === "failed" ? "failed" : "queued",
        });
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error ?? new Error("Could not update the saved upload."));
        transaction.onabort = () => reject(transaction.error ?? new Error("Could not update the saved upload."));
    });
};

const deleteStoredUpload = async (clientUploadId: string): Promise<void> => {
    const database = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = database.transaction(STORE_NAME, "readwrite");
        transaction.objectStore(STORE_NAME).delete(clientUploadId);
        transaction.oncomplete = () => resolve();
        transaction.onerror = () => reject(transaction.error ?? new Error("Could not remove the completed upload from this device."));
        transaction.onabort = () => reject(transaction.error ?? new Error("Could not remove the completed upload from this device."));
    });
};

const newUploadId = (): string => {
    if (typeof crypto.randomUUID === "function") return crypto.randomUUID();

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((value) => value.toString(16).padStart(2, "0")).join("");

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
};

const failureMessage = (error: unknown): { message: string; retryAutomatically: boolean } => {
    if (!axios.isAxiosError(error)) {
        return { message: "Upload failed. The file is still saved in the device queue.", retryAutomatically: false };
    }

    if (!error.response) {
        return {
            message: "Connection lost. This file is saved on this device and will retry when the connection returns.",
            retryAutomatically: true,
        };
    }

    const status = error.response.status;
    const serverMessage = error.response.data?.message;

    if (status === 401 || status === 419) {
        return { message: "Your sign-in expired. Sign in again, then retry this saved upload.", retryAutomatically: false };
    }

    if (status === 429 || status >= 500) {
        return {
            message: "The upload service is temporarily unavailable. This file is saved on this device; retry when ready.",
            retryAutomatically: false,
        };
    }

    return {
        message: typeof serverMessage === "string" ? serverMessage : "This file was rejected. Review it and retry or remove it.",
        retryAutomatically: false,
    };
};

export function useJobAttachmentUploadQueue(options: JobAttachmentUploadQueueOptions) {
    const uploads = shallowRef<JobAttachmentUpload[]>([]);
    const isOnline = ref(typeof navigator === "undefined" || navigator.onLine);
    const loading = ref(true);
    const processing = ref(false);
    const ready = ref(false);
    const error = ref("");
    let disposed = false;
    let retryTimer: number | undefined;
    let currentRequest: AbortController | null = null;

    const refresh = async (): Promise<void> => {
        try {
            uploads.value = await listStoredUploads(options.userId, options.workOrderId);
        } catch (cause) {
            error.value = cause instanceof Error ? cause.message : "Could not load this device’s upload queue.";
        } finally {
            loading.value = false;
        }
    };

    const replaceUpload = (updated: JobAttachmentUpload): void => {
        uploads.value = uploads.value.map((item) => item.clientUploadId === updated.clientUploadId ? updated : item);
    };

    const processQueue = async (): Promise<void> => {
        if (processing.value || disposed || !ready.value || !isOnline.value) return;

        processing.value = true;
        error.value = "";

        try {
            for (const queued of [...uploads.value]) {
                if (disposed || !isOnline.value) break;
                if (queued.status === "failed") continue;

                const uploading: JobAttachmentUpload = { ...queued, status: "uploading", error: null };
                replaceUpload(uploading);
                currentRequest = new AbortController();

                try {
                    const formData = new FormData();
                    formData.append("files[]", queued.blob, queued.name);
                    formData.append("client_upload_id", queued.clientUploadId);

                    const response = await axios.post(
                        route("admin.jobs.attachments.store", options.workOrderId),
                        formData,
                        {
                            headers: { Accept: "application/json" },
                            signal: currentRequest.signal,
                            timeout: 120_000,
                        },
                    );

                    const uploaded = response.data?.data?.[0];

                    if (response.status !== 201 || ! uploaded || typeof uploaded.id !== "number") {
                        throw new Error("The server did not confirm this upload. The saved file is still in the queue.");
                    }

                    await deleteStoredUpload(queued.clientUploadId);
                    uploads.value = uploads.value.filter((item) => item.clientUploadId !== queued.clientUploadId);
                    options.onUploaded(uploaded as Record<string, unknown>);
                } catch (cause) {
                    if (disposed) break;

                    const failure = failureMessage(cause);
                    const updated: JobAttachmentUpload = {
                        ...queued,
                        status: failure.retryAutomatically ? "queued" : "failed",
                        error: failure.message,
                        retryCount: queued.retryCount + 1,
                    };

                    replaceUpload(updated);

                    try {
                        await updateStoredUpload(updated);
                    } catch (storageError) {
                        error.value = storageError instanceof Error
                            ? storageError.message
                            : "Could not save the failed upload state on this device.";
                    }

                    if (failure.retryAutomatically && isOnline.value) {
                        const delay = Math.min(60_000, 1_000 * (2 ** Math.min(updated.retryCount, 6)));
                        retryTimer = window.setTimeout(() => void processQueue(), delay);
                        break;
                    }
                } finally {
                    currentRequest = null;
                }
            }
        } finally {
            processing.value = false;
        }
    };

    const enqueue = async (files: File[]): Promise<void> => {
        error.value = "";

        if (files.length === 0) return;
        if (files.length > MAX_PENDING_FILES) {
            error.value = `Select no more than ${MAX_PENDING_FILES} files at a time.`;

            return;
        }
        if (uploads.value.length + files.length > MAX_PENDING_FILES) {
            error.value = `This Job already has ${uploads.value.length} pending file(s) on this device. Upload or remove them before adding more.`;

            return;
        }

        const oversizedFile = files.find((file) => file.size > MAX_FILE_BYTES);

        if (oversizedFile) {
            error.value = `${oversizedFile.name} is larger than the 20 MB per-file limit.`;

            return;
        }

        const createdAt = Date.now();
        const additions: JobAttachmentUpload[] = files.map((file, index) => ({
            clientUploadId: newUploadId(),
            userId: options.userId,
            workOrderId: options.workOrderId,
            name: file.name,
            mimeType: file.type || "application/octet-stream",
            size: file.size,
            lastModified: file.lastModified,
            createdAt: createdAt + index,
            blob: file,
            status: "queued",
            error: null,
            retryCount: 0,
        }));

        try {
            await storeUploads(additions);
            uploads.value = [...uploads.value, ...additions];
            if (ready.value) void processQueue();
        } catch (cause) {
            error.value = cause instanceof Error
                ? `${cause.message} No files were added to the queue.`
                : "No files were added because this device could not save them.";
        }
    };

    const retry = async (clientUploadId: string): Promise<void> => {
        const upload = uploads.value.find((item) => item.clientUploadId === clientUploadId);
        if (!upload || upload.status === "uploading") return;

        const updated: JobAttachmentUpload = { ...upload, status: "queued", error: null, retryCount: 0 };
        replaceUpload(updated);

        try {
            await updateStoredUpload(updated);
            void processQueue();
        } catch (cause) {
            error.value = cause instanceof Error ? cause.message : "Could not retry the saved upload.";
        }
    };

    const discard = async (clientUploadId: string): Promise<void> => {
        const upload = uploads.value.find((item) => item.clientUploadId === clientUploadId);
        if (!upload || upload.status === "uploading") return;

        try {
            await deleteStoredUpload(clientUploadId);
            uploads.value = uploads.value.filter((item) => item.clientUploadId !== clientUploadId);
        } catch (cause) {
            error.value = cause instanceof Error ? cause.message : "Could not remove the saved upload.";
        }
    };

    const updateOnlineStatus = (): void => {
        isOnline.value = navigator.onLine;

        if (isOnline.value && ready.value) void processQueue();
    };

    const resume = async (): Promise<void> => {
        await refresh();
        ready.value = true;
        if (navigator.onLine) void processQueue();
    };

    onMounted(() => {
        window.addEventListener("online", updateOnlineStatus);
        window.addEventListener("offline", updateOnlineStatus);
    });

    onBeforeUnmount(() => {
        disposed = true;
        currentRequest?.abort();
        window.removeEventListener("online", updateOnlineStatus);
        window.removeEventListener("offline", updateOnlineStatus);

        if (retryTimer !== undefined) window.clearTimeout(retryTimer);
    });

    return { uploads, isOnline, loading, processing, error, enqueue, retry, discard, resume };
}
