<script setup>
import { computed, ref } from 'vue';
import { fileSize } from '../utils/format';
import { viewLocalFile, viewStoredDocument } from '../utils/documentChecklist';

/**
 * Freight Forwarding's supporting-document checklist on the invoice form: one row per document type,
 * files attached under the row they belong to, "+" to add more, a name for anything filed as Other.
 * Files waiting to be uploaded live in `pending` (v-model); documents already stored come in as
 * `existing`, and their removal is the parent's to confirm and perform.
 *
 * CR: ai/change-requests/add-freight-forwarding-document-checklist-to-invoice-submission.md
 */
const props = defineProps({
    types: { type: Array, required: true }, // [{ value, title, required }]
    existing: { type: Array, default: () => [] },
    pending: { type: Array, required: true }, // [{ uid, file, type, label }]
    // Whether the required items are enforced for this invoice — not for one submitted before the
    // checklist, nor while it is being corrected.
    enforceRequired: { type: Boolean, default: true },
    canRemoveExisting: { type: Function, default: () => false },
    removingId: { type: [Number, String], default: null },
    accept: { type: String, default: '' },
    maxDocuments: { type: Number, default: 30 },
});

const emit = defineEmits(['update:pending', 'remove-existing', 'download-existing']);

const inputs = ref({});
let seq = 0;

const existingOf = (type) => props.existing.filter((d) => d.document_type === type);
const pendingOf = (type) => props.pending.filter((p) => p.type === type);
const unclassified = computed(() => props.existing.filter((d) => !d.document_type));
const unfiled = computed(() => props.pending.filter((p) => !p.type));
const total = computed(() => props.existing.length + props.pending.length);
const hasFile = (type) => existingOf(type).length > 0 || pendingOf(type).length > 0;

function pick(type) {
    inputs.value[type]?.click();
}

function onPicked(type, event) {
    const files = Array.from(event.target.files ?? []);
    event.target.value = ''; // so picking the same file again still fires
    if (!files.length) return;

    emit('update:pending', [
        ...props.pending,
        ...files.map((file) => ({ uid: `${Date.now()}-${seq++}`, file, type, label: '' })),
    ]);
}

function update(uid, changes) {
    emit('update:pending', props.pending.map((p) => (p.uid === uid ? { ...p, ...changes } : p)));
}

function removePending(uid) {
    emit('update:pending', props.pending.filter((p) => p.uid !== uid));
}
</script>

<template>
    <div>
        <div class="text-caption text-medium-emphasis mb-3">
            Attach each document under the item it belongs to. Use <strong>+</strong> to add more than one file to an
            item. {{ total }} of {{ maxDocuments }} files.
        </div>

        <v-sheet
            v-for="type in types"
            :key="type.value"
            border
            rounded
            class="pa-3 mb-3"
            :class="{ 'border-error': enforceRequired && type.required && !hasFile(type.value) }"
        >
            <div class="d-flex align-center ga-2">
                <v-icon
                    size="small"
                    :color="hasFile(type.value) ? 'success' : 'grey'"
                    :icon="hasFile(type.value) ? 'mdi-check-circle' : 'mdi-circle-outline'"
                />
                <span class="text-body-2 font-weight-medium">{{ type.title }}</span>
                <v-chip v-if="type.required && enforceRequired" size="x-small" color="error" variant="tonal">Required</v-chip>
                <v-chip v-else size="x-small" variant="tonal">Optional</v-chip>
                <v-spacer />
                <v-btn
                    size="small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="mdi-plus"
                    :title="`Add a file under ${type.title}`"
                    @click="pick(type.value)"
                >Add</v-btn>
                <input
                    :ref="(el) => (inputs[type.value] = el)"
                    type="file"
                    multiple
                    :accept="accept"
                    class="d-none"
                    @change="onPicked(type.value, $event)"
                >
            </div>

            <v-list v-if="existingOf(type.value).length || pendingOf(type.value).length" density="compact" class="pa-0 mt-2">
                <v-list-item
                    v-for="doc in existingOf(type.value)"
                    :key="`doc-${doc.id}`"
                    class="px-1"
                    prepend-icon="mdi-paperclip"
                    :title="doc.original_name"
                    :subtitle="`${fileSize(doc.size)} · uploaded${doc.document_label ? ` · ${doc.document_label}` : ''}`"
                >
                    <template #append>
                        <v-btn icon="mdi-eye-outline" variant="text" size="small" title="View" @click="viewStoredDocument(doc)" />
                        <v-btn icon="mdi-download" variant="text" size="small" title="Download" @click="emit('download-existing', doc)" />
                        <v-btn
                            v-if="canRemoveExisting(doc)"
                            icon="mdi-delete-outline"
                            variant="text"
                            size="small"
                            color="error"
                            title="Remove"
                            :loading="removingId === doc.id"
                            @click="emit('remove-existing', doc)"
                        />
                    </template>
                </v-list-item>

                <v-list-item
                    v-for="p in pendingOf(type.value)"
                    :key="p.uid"
                    class="px-1"
                    prepend-icon="mdi-file-upload-outline"
                    :title="p.file.name"
                    :subtitle="`${fileSize(p.file.size)} · not yet uploaded`"
                >
                    <v-text-field
                        v-if="type.value === 'other'"
                        :model-value="p.label"
                        label="Document name *"
                        density="compact"
                        variant="outlined"
                        class="mt-2"
                        maxlength="150"
                        :error-messages="p.label?.trim() ? [] : ['Name the document']"
                        @update:model-value="update(p.uid, { label: $event })"
                    />
                    <template #append>
                        <v-btn icon="mdi-eye-outline" variant="text" size="small" title="View" @click="viewLocalFile(p.file)" />
                        <v-btn icon="mdi-close" variant="text" size="small" color="error" title="Remove" @click="removePending(p.uid)" />
                    </template>
                </v-list-item>
            </v-list>
        </v-sheet>

        <!-- Files picked before the department became Freight Forwarding: they need an item. -->
        <v-sheet v-if="unfiled.length" border rounded class="pa-3 mb-3">
            <div class="text-body-2 font-weight-medium mb-1">Not yet filed</div>
            <div class="text-caption text-medium-emphasis mb-2">Choose the checklist item each of these files belongs to.</div>
            <div v-for="p in unfiled" :key="p.uid" class="d-flex align-center ga-2 mb-2 flex-wrap">
                <v-icon icon="mdi-file-upload-outline" size="small" />
                <span class="text-body-2" style="min-width: 160px">{{ p.file.name }}</span>
                <v-select
                    :model-value="p.type"
                    :items="types"
                    item-title="title"
                    item-value="value"
                    label="Checklist item *"
                    density="compact"
                    variant="outlined"
                    hide-details
                    style="max-width: 320px"
                    @update:model-value="update(p.uid, { type: $event })"
                />
                <v-btn icon="mdi-eye-outline" variant="text" size="small" title="View" @click="viewLocalFile(p.file)" />
                <v-btn icon="mdi-close" variant="text" size="small" color="error" title="Remove" @click="removePending(p.uid)" />
            </div>
        </v-sheet>

        <!-- Documents attached before the checklist existed. Left exactly as they are. -->
        <v-sheet v-if="unclassified.length" border rounded class="pa-3 mb-3">
            <div class="text-body-2 font-weight-medium mb-1">Unclassified</div>
            <div class="text-caption text-medium-emphasis">Attached before the document checklist was introduced.</div>
            <v-list density="compact" class="pa-0">
                <v-list-item
                    v-for="doc in unclassified"
                    :key="`doc-${doc.id}`"
                    class="px-1"
                    prepend-icon="mdi-paperclip"
                    :title="doc.original_name"
                    :subtitle="fileSize(doc.size)"
                >
                    <template #append>
                        <v-btn icon="mdi-eye-outline" variant="text" size="small" title="View" @click="viewStoredDocument(doc)" />
                        <v-btn icon="mdi-download" variant="text" size="small" title="Download" @click="emit('download-existing', doc)" />
                        <v-btn
                            v-if="canRemoveExisting(doc)"
                            icon="mdi-delete-outline"
                            variant="text"
                            size="small"
                            color="error"
                            title="Remove"
                            :loading="removingId === doc.id"
                            @click="emit('remove-existing', doc)"
                        />
                    </template>
                </v-list-item>
            </v-list>
        </v-sheet>
    </div>
</template>
