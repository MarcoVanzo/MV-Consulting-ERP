'use strict';

/**
 * Store — API wrapper con supporto FormData e GET params
 */
const Store = (() => {
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

            const headers = {};
            const token = localStorage.getItem('erp_token');
            if (token) {
                headers['Authorization'] = 'Bearer ' + token;
            }

            const fetchOptions = {
                method: options.method || 'POST',
                headers: headers
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
                localStorage.removeItem('erp_token');
                localStorage.removeItem('erp_user');
                window.location.reload();
                return;
            }

            if (!result.success) {
                throw new Error(result.error || result.message || 'Errore sconosciuto');
            }

            return result.data;
        } catch (error) {
            console.error('[API Error]', module + '/' + action, error);
            throw error;
        }
    }

    return { api };
})();

window.Store = Store;
