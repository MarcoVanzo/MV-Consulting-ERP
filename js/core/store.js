'use strict';

/**
 * Store — API wrapper con supporto FormData e GET params
 */
const Store = (() => {
    async function api(action, module = 'auth', payload = {}) {
        try {
            const formData = new FormData();
            formData.append('module', module);
            formData.append('action', action);
            
            for (const key in payload) {
                if (payload[key] !== undefined && payload[key] !== null) {
                    formData.append(key, payload[key]);
                }
            }

            const response = await fetch('api/router.php', {
                method: 'POST',
                body: formData
            });

            const text = await response.text();
            let result;
            try {
                result = JSON.parse(text);
            } catch (parseErr) {
                throw new Error('Risposta server non valida');
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
