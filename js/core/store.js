'use strict';

/**
 * Store — API wrapper con supporto FormData e GET params
 */
const Store = (() => {
    /** Legge un cookie per nome (usato per CSRF token) */
    function _getCookie(name) {
        const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? decodeURIComponent(match[2]) : '';
    }

    /** Invio comune: header CSRF, cookie di sessione, parsing e gestione 401 */
    async function _send(formData, options = {}) {
        const headers = {};
        // Auth gestita SOLO via cookie HttpOnly (credentials: 'include')
        // Nessun token in localStorage — previene XSS token theft

        // CSRF Double Submit: leggi il token dal cookie e invialo come header
        const csrfToken = _getCookie('csrf_token');
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        const fetchOptions = {
            method: options.method || 'POST',
            headers: headers,
            credentials: 'include'
        };

        // fetch doesn't allow body for GET requests
        if (fetchOptions.method !== 'GET' && fetchOptions.method !== 'HEAD') {
            fetchOptions.body = formData;
        }

        if (options.signal) {
            fetchOptions.signal = options.signal;
        }

        const response = await fetch('api/router.php', fetchOptions);

        const text = await response.text();
        let result;
        try {
            result = JSON.parse(text);
        } catch (parseErr) {
            console.error("Raw response:", text);
            throw new Error('Risposta server non valida: ' + text.substring(0, 150));
        }

        if (response.status === 401 || (result && result.message === 'Token non valido o scaduto')) {
            localStorage.removeItem('erp_user');
            window.location.reload();
            return;
        }

        if (!result.success) {
            throw new Error(result.error || result.message || 'Errore sconosciuto');
        }

        return result.data;
    }

    async function api(action, module = 'auth', payload = {}, options = {}) {
        // Compatibilità con vecchio parametro method passato come stringa
        if (typeof options === 'string') {
            options = { method: options };
        }

        try {
            const formData = new FormData();
            formData.append('module', module);
            formData.append('action', action);

            for (const key in payload) {
                if (payload[key] !== undefined && payload[key] !== null) {
                    formData.append(key, payload[key]);
                }
            }

            return await _send(formData, options);
        } catch (error) {
            console.error('[API Error]', module + '/' + action, error);
            throw error;
        }
    }

    /**
     * Upload di un FormData già costruito (file, array come "chiave[]", ...).
     * Stessi header CSRF, credenziali e gestione 401 di api().
     */
    async function upload(action, module, formData, options = {}) {
        try {
            formData.set('module', module);
            formData.set('action', action);
            return await _send(formData, { ...options, method: 'POST' });
        } catch (error) {
            console.error('[API Error]', module + '/' + action, error);
            throw error;
        }
    }

    /** Helper: FormData con un array di stringhe inviato come "chiave[]" (PHP lo riceve come array) */
    function formDataFromArray(key, values) {
        const fd = new FormData();
        values.forEach(v => fd.append(key + '[]', v));
        return fd;
    }

    return { api, upload, formDataFromArray };
})();

window.Store = Store;
