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
 * Entity-stable status colors (CVD-validated ordering for the dashboard donut).
 * The same hex follows a status everywhere: chips, charts, legends.
 */
export const STATUS_META = {
    paid: { label: 'Paid', color: '#008300', icon: 'mdi-check-circle' },
    scheduled: { label: 'Scheduled', color: '#2a78d6', icon: 'mdi-calendar-clock' },
    pending_approval: { label: 'Pending Approval', color: '#eda100', icon: 'mdi-clock-outline' },
    approved: { label: 'Approved', color: '#1baf7a', icon: 'mdi-thumb-up-outline' },
    rejected: { label: 'Rejected', color: '#e34948', icon: 'mdi-close-circle-outline' },
    draft: { label: 'Draft', color: '#898781', icon: 'mdi-pencil-outline' },
    cancelled: { label: 'Cancelled', color: '#eb6834', icon: 'mdi-cancel' },
};

export function statusLabel(status) {
    return STATUS_META[status]?.label ?? status;
}

export const PRIORITY_META = {
    urgent: { label: 'Urgent', color: '#d03b3b' },
    high: { label: 'High', color: '#ec835a' },
    normal: { label: 'Normal', color: '#52514e' },
    low: { label: 'Low', color: '#898781' },
};
