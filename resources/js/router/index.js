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
            { path: 'invoices/new', name: 'invoice-create', component: () => import('../pages/invoices/InvoiceFormPage.vue') },
            { path: 'invoices/:id', name: 'invoice-detail', component: () => import('../pages/invoices/InvoiceDetailPage.vue'), props: true },
            { path: 'invoices/:id/edit', name: 'invoice-edit', component: () => import('../pages/invoices/InvoiceFormPage.vue'), props: true },
            { path: 'approvals', name: 'approvals', component: () => import('../pages/ApprovalsPage.vue'), meta: { roles: ['approver', 'admin'] } },
            { path: 'payment-requests', name: 'payment-requests', component: () => import('../pages/PaymentRequestsPage.vue') },
            { path: 'payment-requests/:id', name: 'payment-request-detail', component: () => import('../pages/PaymentRequestDetailPage.vue'), props: true },
            { path: 'reports', name: 'reports', component: () => import('../pages/ReportsPage.vue') },
            { path: 'audit-log', name: 'audit-log', component: () => import('../pages/AuditLogPage.vue'), meta: { roles: ['admin'] } },
            { path: 'admin/users', name: 'users', component: () => import('../pages/admin/UsersPage.vue'), meta: { roles: ['admin'] } },
            { path: 'admin/approval-levels', name: 'approval-levels', component: () => import('../pages/admin/ApprovalLevelsPage.vue'), meta: { roles: ['admin'] } },
            { path: 'admin/master-data', name: 'master-data', component: () => import('../pages/admin/MasterDataPage.vue'), meta: { roles: ['admin'] } },
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

    return true;
});

export default router;
