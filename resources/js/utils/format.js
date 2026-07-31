export function money(value, currency = 'AED') {
    const number = Number(value ?? 0);
    return `${currency} ${number.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function shortDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

export function dateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}

export function fileSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`;
}

/**
 * Entity-stable status colors. The same hex follows a status everywhere:
 * chips, charts, legends. Covers invoice statuses, invoice payment statuses,
 * and payment-request statuses (shared keys carry the same meaning).
 */
export const STATUS_META = {
    // Invoice Log lifecycle
    submitted: { label: 'Submitted', color: '#2a78d6', icon: 'mdi-file-send-outline' },
    posted: { label: 'Posted', color: '#008300', icon: 'mdi-checkbox-marked-circle-outline' },
    query_raised: { label: 'Query Raised', color: '#e34948', icon: 'mdi-help-circle-outline' },
    cancelled: { label: 'Cancelled', color: '#eb6834', icon: 'mdi-cancel' },

    // Invoice payment status
    not_initiated: { label: 'Not Initiated', color: '#898781', icon: 'mdi-timer-sand-empty' },
    in_approval: { label: 'In Approval', color: '#eda100', icon: 'mdi-clock-outline' },
    approved_for_payment: { label: 'Approved for Payment', color: '#1baf7a', icon: 'mdi-cash-check' },
    paid: { label: 'Paid', color: '#008300', icon: 'mdi-check-circle' },

    // Payment-request status (draft/approved/rejected in addition to the shared ones)
    draft: { label: 'Draft', color: '#898781', icon: 'mdi-pencil-outline' },
    approved: { label: 'Approved', color: '#1baf7a', icon: 'mdi-thumb-up-outline' },
    rejected: { label: 'Rejected', color: '#e34948', icon: 'mdi-close-circle-outline' },
};

export function statusLabel(status) {
    return STATUS_META[status]?.label ?? status;
}

export const PRIORITY_META = {
    urgent: { label: 'Urgent (24hrs)', color: '#d03b3b' },
    high: { label: 'High (2 days)', color: '#ec835a' },
    normal: { label: 'Normal (Credit days)', color: '#52514e' },
};
