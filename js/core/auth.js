'use strict';

/**
 * Auth — Login Flow per MV Consulting ERP
 */
const AuthFlow = (() => {
    function showLoginScreen(onLoginSuccess) {
        document.getElementById('auth-screen').classList.remove('hidden');
        document.getElementById('app-shell').classList.add('hidden');

        const form = document.getElementById('login-form');
        const emailInput = document.getElementById('login-email');
        const passwordInput = document.getElementById('login-password');
        const btn = document.getElementById('login-submit-btn');
        const errEl = document.getElementById('login-error');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const email = emailInput.value.trim();
            const pwd = passwordInput.value;

            if (!email || !pwd) {
                errEl.innerText = 'Inserisci email e password';
                errEl.classList.remove('hidden');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = 'ACCESSO IN CORSO...';
            errEl.classList.add('hidden');

            try {
                const data = await Store.api('login', 'auth', { email, password: pwd });
                onLoginSuccess(data);
            } catch (err) {
                errEl.innerText = err.message || "Credenziali non valide.";
                errEl.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btn.innerHTML = 'ACCEDI <i class="ph ph-caret-right"></i>';
            }
        });
    }

    return { showLoginScreen };
})();

window.AuthFlow = AuthFlow;
