'use strict';

/**
 * Auth — Login Flow per MV Consulting ERP
 */
const AuthFlow = (() => {
    let _abortAuth = new AbortController();

    function showLoginScreen(onLoginSuccess) {
        _abortAuth.abort();
        _abortAuth = new AbortController();

        document.getElementById('auth-screen').classList.remove('hidden');
        document.getElementById('app-shell').classList.add('hidden');
        
        // Ensure reset screen is hidden
        const resetScreen = document.getElementById('reset-screen');
        if (resetScreen) resetScreen.classList.add('hidden');

        const oldForm = document.getElementById('login-form');
        const form = oldForm.cloneNode(true);
        oldForm.parentNode.replaceChild(form, oldForm);

        const emailInput = form.querySelector('#login-email');
        const passwordInput = form.querySelector('#login-password');
        const btn = form.querySelector('#login-submit-btn');
        const errEl = form.querySelector('#login-error');

        // Forgot password link
        const forgotLink = document.getElementById('forgot-password-link');
        if (forgotLink) {
            forgotLink.addEventListener('click', (e) => {
                e.preventDefault();
                renderForgotPassword();
            }, { signal: _abortAuth.signal });
        }

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
                
                if (data.must_change) {
                    _showResetScreen(data.user_id, onLoginSuccess);
                    return;
                }

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

    function _showResetScreen(userId, onLoginSuccess) {
        document.getElementById('auth-screen').classList.add('hidden');
        const resetScreen = document.getElementById('reset-screen');
        
        if (!resetScreen) {
            alert("Errore UI: Schermata reset non trovata. Contatta l'amministratore.");
            return;
        }

        resetScreen.classList.remove('hidden');

        const form = document.getElementById('reset-form');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('reset-btn');
            const errEl = document.getElementById('reset-error');
            const current = document.getElementById('reset-current').value;
            const newPwd = document.getElementById('reset-new').value;

            if (newPwd.length < 12) {
                errEl.textContent = 'La password deve essere di almeno 12 caratteri';
                errEl.classList.remove('hidden');
                return;
            }

            btn.disabled = true;
            btn.textContent = 'SALVATAGGIO...';

            try {
                await Store.api('reset_password', 'auth', { user_id: userId, current_password: current, new_password: newPwd });
                
                // Usually we'd want a UI toast, but since we're in login flow:
                alert('Password aggiornata con successo. Effettua il login.');
                
                setTimeout(() => window.location.reload(), 500);
            } catch (err) {
                errEl.textContent = err.message || "Errore durante l'aggiornamento";
                errEl.classList.remove('hidden');
                btn.disabled = false;
                btn.textContent = 'SALVA NUOVA PASSWORD';
            }
        }, { once: true });
    }

    function renderForgotPassword() {
        const emailInput = document.querySelector('#login-email')?.value.trim() || '';
        
        const emailStr = window.prompt("Password dimenticata?\n\nInserisci il tuo indirizzo email. Ti invieremo una password temporanea valida per un solo utilizzo.", emailInput);
        
        if (emailStr && emailStr.includes('@')) {
            Store.api('request_reset', 'auth', { email: emailStr })
                .then(res => {
                    alert("Richiesta inviata. Se l'email è registrata riceverai una password temporanea a breve.");
                })
                .catch(err => {
                    alert("Errore durante la richiesta: " + (err.message || "Riprova più tardi."));
                });
        }
    }

    return { showLoginScreen };
})();

window.AuthFlow = AuthFlow;
