'use strict';

document.addEventListener('DOMContentLoaded', () => {
    // Determine if we need to show auth or dashboard
    const token = localStorage.getItem('erp_token');
    
    if (token) {
        initApplication();
    } else {
        AuthFlow.showLoginScreen((userData) => {
            // Save token
            if (userData.token) {
                localStorage.setItem('erp_token', userData.token);
            }
            initApplication();
        });
    }

    // Logout handling
    document.getElementById('logout-btn').addEventListener('click', () => {
        localStorage.removeItem('erp_token');
        window.location.reload();
    });
});

function initApplication() {
    document.getElementById('auth-screen').classList.add('hidden');
    document.getElementById('app-shell').classList.remove('hidden');
}
