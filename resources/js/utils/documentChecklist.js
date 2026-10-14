/**
 * Freight Forwarding's supporting-document checklist, client side. The server holds the same rules
 * (Invoice::usesDocumentChecklist / missingChecklistItems) and is the one that enforces them.
 *
 * CR: ai/change-requests/add-freight-forwarding-document-checklist-to-invoice-submission.md
 */

/** Whether the department picked on an invoice files its documents against the checklist. */
export function usesDocumentChecklist(department, checklist) {
    const target = String(checklist?.department ?? '').trim().toLowerCase();
    return !!target && String(department ?? '').trim().toLowerCase() === target;
}

/**
 * Required checklist items with no file, as their labels — counting documents already stored and
 * files waiting to be uploaded.
 */
export function missingChecklistItems(checklist, existing = [], pending = []) {
    const present = new Set([
        ...existing.map((d) => d.document_type),
        ...pending.map((p) => p.type),
    ].filter(Boolean));

    return (checklist?.types ?? [])
        .filter((t) => t.required && !present.has(t.value))
        .map((t) => t.title);
}

/** Opens a file the user has picked but not yet uploaded, in a new tab. */
export function viewLocalFile(file) {
    const url = URL.createObjectURL(file);
    window.open(url, '_blank');
    // The new tab has loaded it by then; the URL only holds the file in memory.
    setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

/** Opens a stored invoice document in a new tab, rather than downloading it. */
export function viewStoredDocument(doc) {
    window.open(`/api/documents/${doc.id}/download?inline=1`, '_blank');
}
