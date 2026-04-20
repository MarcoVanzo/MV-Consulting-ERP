'use strict';

/**
 * Store - API wrapper
 */
const Store = (() => {
    async function api(action, module = 'auth', payload = {}) {
        try {
            const formData = new FormData();
            formData.append('module', module);
            formData.append('action', action);
            
            // Append payload
            for (const key in payload) {
                formData.append(key, payload[key]);
            }

            const response = await fetch(`api/router.php`, {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                const errorText = await response.text();
                throw new Error(errorText || 'Server error');
            }

            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.error || result.message || 'Unknown error');
            }

            return result.data;
        } catch (error) {
            console.error('[API Error]', error);
            throw error;
        }
    }

    return { api };
})();

window.Store = Store;
