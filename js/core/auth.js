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

        // Clona il form per eliminare listener di chiamate precedenti (come showLoginScreen)
        const oldForm = document.getElementById('reset-form');
        if (!oldForm) return;
        const form = oldForm.cloneNode(true);
        oldForm.parentNode.replaceChild(form, oldForm);

        form.addEventListener('submit', async (e) => {
            // Sempre: il form non deve mai ricaricare la pagina
            e.preventDefault();
            const btn = form.querySelector('#reset-btn');
            const errEl = form.querySelector('#reset-error');
            const current = form.querySelector('#reset-current').value;
            const newPwd = form.querySelector('#reset-new').value;

            const complexityErr = _checkPasswordComplexity(newPwd);
            if (!current) {
                errEl.textContent = 'Inserisci la password attuale';
                errEl.classList.remove('hidden');
                return;
            }
            if (complexityErr) {
                errEl.textContent = complexityErr;
                errEl.classList.remove('hidden');
                return;
            }

            errEl.classList.add('hidden');
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
                btn.innerHTML = 'SALVA NUOVA PASSWORD <i class="ph ph-caret-right"></i>';
            }
        }, { signal: _abortAuth.signal });
    }

    /**
     * Reset da link email (#reset-token=...): riusa la schermata del cambio password
     * senza il campo "password attuale".
     */
    function showTokenResetScreen(token) {
        document.getElementById('auth-screen').classList.add('hidden');
        const resetScreen = document.getElementById('reset-screen');
        if (!resetScreen) return;
        resetScreen.classList.remove('hidden');
        // Il token non deve restare nella barra degli indirizzi né nella cronologia
        history.replaceState(null, '', window.location.pathname);

        const title = resetScreen.querySelector('h2');
        const intro = resetScreen.querySelector('h2 + p');
        if (title) title.textContent = 'Reimposta la password';
        if (intro) intro.textContent = 'Scegli la nuova password per il tuo account.';

        const oldForm = document.getElementById('reset-form');
        if (!oldForm) return;
        const form = oldForm.cloneNode(true);
        oldForm.parentNode.replaceChild(form, oldForm);
        const currentGroup = form.querySelector('#reset-current')?.closest('.form-group-login');
        if (currentGroup) currentGroup.classList.add('hidden');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = form.querySelector('#reset-btn');
            const errEl = form.querySelector('#reset-error');
            const newPwd = form.querySelector('#reset-new').value;

            const complexityErr = _checkPasswordComplexity(newPwd);
            if (complexityErr) {
                errEl.textContent = complexityErr;
                errEl.classList.remove('hidden');
                return;
            }

            errEl.classList.add('hidden');
            btn.disabled = true;
            btn.textContent = 'SALVATAGGIO...';
            try {
                await Store.api('confirm_reset', 'auth', { token, new_password: newPwd });
                alert('Password aggiornata. Ora puoi accedere.');
                window.location.reload();
            } catch (err) {
                errEl.textContent = err.message || "Errore durante l'aggiornamento";
                errEl.classList.remove('hidden');
                btn.disabled = false;
                btn.innerHTML = 'SALVA NUOVA PASSWORD <i class="ph ph-caret-right"></i>';
            }
        }, { signal: _abortAuth.signal });
    }

    /** Stesse regole del backend (Security::validatePasswordComplexity). Ritorna il messaggio d'errore o '' */
    function _checkPasswordComplexity(pwd) {
        const missing = [];
        if (pwd.length < 12) missing.push('almeno 12 caratteri');
        if (!/[A-Z]/.test(pwd)) missing.push('una lettera maiuscola');
        if (!/[a-z]/.test(pwd)) missing.push('una lettera minuscola');
        if (!/[0-9]/.test(pwd)) missing.push('un numero');
        if (!/[^A-Za-z0-9]/.test(pwd)) missing.push('un carattere speciale');
        return missing.length ? 'La nuova password deve contenere: ' + missing.join(', ') + '.' : '';
    }

    async function renderForgotPassword() {
        const emailInput = document.querySelector('#login-email')?.value.trim() || '';

        const emailStr = await UI.chiedi({ titolo: 'Password dimenticata', tipo: 'email', valore: emailInput, conferma: 'Invia il link',
            etichetta: 'Il tuo indirizzo email: ti mandiamo un link per reimpostare la password, valido un\'ora.' });
        
        if (emailStr && emailStr.includes('@')) {
            Store.api('request_reset', 'auth', { email: emailStr })
                .then(res => {
                    alert("Richiesta inviata. Se l'email è registrata riceverai a breve un link per reimpostare la password.");
                })
                .catch(err => {
                    alert("Errore durante la richiesta: " + (err.message || "Riprova più tardi."));
                });
        }
    }

    return { showLoginScreen, showTokenResetScreen };
})();

window.AuthFlow = AuthFlow;
