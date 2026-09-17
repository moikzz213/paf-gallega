import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    },
});

// Laravel sets the XSRF-TOKEN cookie; axios echoes it automatically.
// The meta tag is the fallback for the very first request after a full page load.
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    api.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

// Pages a signed-out visitor is meant to reach. On a fresh load the router calls /api/me, which
// answers 401 when there is no session; without this list that 401 would bounce the visitor to
// /login and make the password-reset pages unreachable from an emailed link.
const PUBLIC_PATHS = ['/login', '/forgot-password', '/reset-password'];

function onPublicPage() {
    return PUBLIC_PATHS.some((path) => window.location.pathname.startsWith(path));
}

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401 && !onPublicPage()) {
            window.location.href = '/login';
        }
        return Promise.reject(error);
    }
);

/** Human-readable message out of a Laravel error response. */
export function errorMessage(error) {
    const data = error.response?.data;
    if (data?.errors) {
        return Object.values(data.errors).flat().join(' ');
    }
    return data?.message || error.message || 'Something went wrong.';
}

export default api;
