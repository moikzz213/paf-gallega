import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    {
        path: '/login',
        name: 'login',
        component: () => import('../pages/LoginPage.vue'),
        meta: { guest: true },
    },
    {
        path: '/forgot-password',
        name: 'forgot-password',
        component: () => import('../pages/ForgotPasswordPage.vue'),
        meta: { guest: true },
    },
    {
        // Deliberately neither guest nor auth: an emailed reset link must open whether or not
        // there is a session in that browser. Marking it guest would bounce a signed-in visitor
        // to the dashboard with no explanation.
        path: '/reset-password',
        name: 'reset-password',
        component: () => import('../pages/ResetPasswordPage.vue'),
    },
    {
        path: '/',
        component: () => import('../layouts/AppLayout.vue'),
        meta: { auth: true },
        children: [
            { path: '', redirect: '/dashboard' },
            { path: 'dashboard', name: 'dashboard', component: () => import('../pages/DashboardPage.vue') },
            { path: 'invoices', name: 'invoices', component: () => import('../pages/invoices/InvoiceListPage.vue') },
            { path: 'invoices/new', name: 'invoice-create', component: () => import('../pages/invoices/InvoiceFormPage.vue'), meta: { write: true } },
            { path: 'invoices/:id', name: 'invoice-detail', component: () => import('../pages/invoices/InvoiceDetailPage.vue'), props: true },
            { path: 'invoices/:id/edit', name: 'invoice-edit', component: () => import('../pages/invoices/InvoiceFormPage.vue'), props: true, meta: { write: true } },
            // Gated on the store getter rather than a role list: eligibility now depends on the
            // user's approval level too, and `canApprove` is the client's copy of that rule.
            // `canViewApprovals` adds auditors, who see the queue without acting on it.
            { path: 'approvals', name: 'approvals', component: () => import('../pages/ApprovalsPage.vue'), meta: { gate: 'canViewApprovals' } },
            { path: 'payment-requests', name: 'payment-requests', component: () => import('../pages/PaymentRequestsPage.vue') },
            { path: 'payment-requests/:id', name: 'payment-request-detail', component: () => import('../pages/PaymentRequestDetailPage.vue'), props: true },
            { path: 'reports', name: 'reports', component: () => import('../pages/ReportsPage.vue') },
            { path: 'profile', name: 'profile', component: () => import('../pages/ProfilePage.vue') },
            { path: 'audit-log', name: 'audit-log', component: () => import('../pages/AuditLogPage.vue'), meta: { roles: ['admin', 'auditor'] } },
            { path: 'admin/users', name: 'users', component: () => import('../pages/admin/UsersPage.vue'), meta: { roles: ['admin', 'auditor'] } },
            { path: 'admin/approval-levels', name: 'approval-levels', component: () => import('../pages/admin/ApprovalLevelsPage.vue'), meta: { roles: ['admin', 'auditor'] } },
            { path: 'admin/master-data', name: 'master-data', component: () => import('../pages/admin/MasterDataPage.vue'), meta: { roles: ['admin', 'finance', 'auditor'] } },
        ],
    },
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    await auth.fetchUser();

    if (to.meta.guest && auth.user) {
        return { name: 'dashboard' };
    }

    if (to.matched.some((r) => r.meta.auth) && !auth.user) {
        return { name: 'login' };
    }

    const required = to.meta.roles;
    if (required && !required.includes(auth.user?.role)) {
        return { name: 'dashboard' };
    }

    const gate = to.matched.map((r) => r.meta.gate).find(Boolean);
    if (gate && !auth[gate]) {
        return { name: 'dashboard' };
    }

    // Auditors are view-only, so the create/edit forms are closed to them.
    if (to.meta.write && auth.isAuditor) {
        return { name: 'dashboard' };
    }

    return true;
});

export default router;
