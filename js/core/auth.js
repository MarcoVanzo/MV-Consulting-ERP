'use strict';

/**
 * Auth — Login Flow per MV Consulting ERP
 */
const AuthFlow = (() => {
    function showLoginScreen(onLoginSuccess) {
        document.getElementById('auth-screen').classList.remove('hidden');
        document.getElementById('app-shell').classList.add('hidden');

        const oldForm = document.getElementById('login-form');
        // Clona il form per rimuovere eventuali listener precedenti (previene memory leak)
        const form = oldForm.cloneNode(true);
        oldForm.parentNode.replaceChild(form, oldForm);

        const emailInput = form.querySelector('#login-email');
        const passwordInput = form.querySelector('#login-password');
        const btn = form.querySelector('#login-submit-btn');
        const errEl = form.querySelector('#login-error');

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
