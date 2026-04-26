const API_BASE = '/api';
const TOKEN_KEY = 'apphub_token';
const USER_KEY  = 'apphub_user';

// ── Token y sesión ──────────────────────────────────────────

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function getUser() {
    const raw = localStorage.getItem(USER_KEY);
    return raw ? JSON.parse(raw) : null;
}

export function saveSession(token, usuario) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(usuario));
}

export function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
}

export function isSuperuser() {
    return getUser()?.rol_global === 'superuser';
}

export function requireAuth() {
    if (!getToken()) {
        window.location.href = '/app/login.html';
    }
}

// ── Fetch wrapper ───────────────────────────────────────────

export async function apiFetch(path, options = {}) {
    const token = getToken();

    const headers = {
        'Accept': 'application/json',
        ...(options.body && !(options.body instanceof FormData)
            ? { 'Content-Type': 'application/json' }
            : {}),
        ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
        ...(options.headers || {}),
    };

    const res = await fetch(`${API_BASE}${path}`, {
        ...options,
        headers,
        body: options.body instanceof FormData
            ? options.body
            : options.body ? JSON.stringify(options.body) : undefined,
    });

    if (res.status === 401) {
        clearSession();
        window.location.href = '/app/login.html';
        return;
    }

    return res;
}

// ── Helpers JSON ────────────────────────────────────────────

export async function apiGet(path) {
    const res = await apiFetch(path);
    if (!res.ok) throw await res.json();
    return res.json();
}

export async function apiPost(path, body) {
    const res = await apiFetch(path, { method: 'POST', body });
    if (!res.ok) throw await res.json();
    return res.json();
}

export async function apiPut(path, body) {
    const res = await apiFetch(path, { method: 'PUT', body });
    if (!res.ok) throw await res.json();
    return res.json();
}

export async function apiDelete(path) {
    const res = await apiFetch(path, { method: 'DELETE' });
    if (!res.ok) throw await res.json();
    return res;
}

// ── Manejo de errores de validación ────────────────────────

export function showValidationErrors(errorsObj, formEl) {
    formEl.querySelectorAll('.field-error').forEach(el => el.remove());
    formEl.querySelectorAll('.is-error').forEach(el => el.classList.remove('is-error'));

    if (!errorsObj?.errors) return;

    for (const [field, messages] of Object.entries(errorsObj.errors)) {
        const input = formEl.querySelector(`[name="${field}"]`);
        if (input) {
            input.classList.add('is-error');
            const span = document.createElement('span');
            span.className = 'field-error';
            span.textContent = messages[0];
            input.parentElement.appendChild(span);
        }
    }
}
