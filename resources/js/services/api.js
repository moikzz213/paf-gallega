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

api.interceptors.response.use(
    (response) => response,
    (error) => {
        if (error.response?.status === 401 && !window.location.pathname.startsWith('/login')) {
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
