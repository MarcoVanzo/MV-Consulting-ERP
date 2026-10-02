'use strict';

/**
 * App — Main orchestrator per MV Consulting ERP
 */
document.addEventListener('DOMContentLoaded', async () => {
    // Link di reset password ricevuto via email. Va gestito anche quando il link
    // viene aperto in una scheda dove l'ERP è già caricato (cambia solo l'hash).
    // Il token resta nella chiusura del form di reset: in caso di errore si può riprovare.
    function checkResetLink() {
        const m = window.location.hash.match(/reset-token=([a-f0-9]{64})/);
        if (!m) return false;
        document.getElementById('app-shell')?.classList.add('hidden');
        UI.closeModal();
        AuthFlow.showTokenResetScreen(m[1]);
        return true;
    }
    window.addEventListener('hashchange', checkResetLink);
    if (checkResetLink()) return;

    // Autenticazione basata SOLO su cookie HttpOnly.
    // erp_user in localStorage è usato solo per dati di visualizzazione (nome, iniziali),
    // MAI come prova di autenticazione.
    let cachedUser = null;
    try {
        cachedUser = JSON.parse(localStorage.getItem('erp_user') || 'null');
    } catch (e) {
        // Valore corrotto: si riparte dal login
        localStorage.removeItem('erp_user');
    }

    if (cachedUser) {
        // Valida la sessione con il server prima di fidarsi del localStorage
        try {
            const verified = await Store.api('verify', 'auth');
            if (verified) {
                // Aggiorna i dati locali con quelli verificati dal server
                const userData = { ...cachedUser, ...verified };
                // Il token non contiene il nome: verify ripiega sull'email, si tiene quello del login
                if (cachedUser.name && (!verified.name || verified.name === verified.email)) {
                    userData.name = cachedUser.name;
                }
                localStorage.setItem('erp_user', JSON.stringify(userData));
                initApplication(userData);
            } else {
                throw new Error('Sessione non valida');
            }
        } catch (e) {
            // Cookie scaduto o invalido — mostra login
            localStorage.removeItem('erp_user');
            showLoginFlow();
        }
    } else {
        showLoginFlow();
    }

    function showLoginFlow() {
        AuthFlow.showLoginScreen((data) => {
            // Token gestito SOLO via cookie HttpOnly, NON salvato in localStorage
            localStorage.setItem('erp_user', JSON.stringify(data));
            initApplication(data);
        });
    }
});

function initApplication(userData) {
    document.getElementById('auth-screen').classList.add('hidden');
    document.getElementById('app-shell').classList.remove('hidden');

    // Set user info in sidebar
    const isAdmin = userData?.role === 'admin';
    if (userData) {
        const name = userData.name || userData.email || 'User';
        document.getElementById('user-display-name').textContent = name;
        const initials = name.split(' ').filter(Boolean).map(w => w[0]).join('').substring(0, 2).toUpperCase();
        document.getElementById('user-avatar').textContent = initials;
        const roleLabels = { admin: 'Admin', operatore: 'Operatore' };
        const roleEl = document.getElementById('user-display-role');
        if (roleEl) roleEl.textContent = roleLabels[userData.role] || userData.role || '';
    }

    // Voci admin visibili solo agli admin (il backend blocca comunque il modulo admin)
    document.querySelectorAll('.admin-only').forEach(el => el.classList.toggle('hidden', !isAdmin));

    // "Il mio profilo" non ha ancora una vista: nascosto
    const profileBtn = document.getElementById('profile-btn');
    if (profileBtn) profileBtn.classList.add('hidden');

    // ── Init core UI ──
    UI.initModalEvents();
    UI.initTabelleSchede();

    // ── Navigazione: sei viste, ognuna con le sue schede; indirizzo nell'URL (#vista/scheda) ──
    const CARICA = {
        oggi:        () => ModOggi.load(),
        vendite:     { 'comm-offerte': () => ModOfferte.load(), 'tab-incarichi': () => ModIncarichi.load() },
        incassi:     { 'tab-fatture': () => ModContabilita.load(), 'inc-ricevute': () => ModPartner.loadPassive() },
        banca:       { 'tab-riconciliazione': () => ModRiconciliazione.load(), 'tab-classificare': () => ModMovimenti.loadCoda(), 'tab-andamento': () => ModAndamento.load() },
        trasferte:   { 'trasferte-viaggi': () => ModTrasferte.load({ refreshMezzi: true }), 'trasferte-spese': () => ModSpese.load(), 'trasferte-mezzi': () => ModMezzi.init() },
        anagrafiche: { 'anag-clienti': () => ModClienti.load(), 'anag-fornitori': () => ModPartner.loadFornitori() },
        utenti:      () => ModAdmin.loadUsers(),
        backup:      () => ModAdmin.loadBackups(),
        logs:        () => ModAdmin.loadLogs(),
    };
    const paneAttivo = view => document.querySelector(`#view-${view} > .vpane.active`)?.id;

    function carica(view, pane) {
        const c = CARICA[view];
        const f = typeof c === 'function' ? c : c?.[pane || paneAttivo(view)];
        try { f?.(); } catch (e) { console.error('[Vista]', view, e); }
    }
    const VISTE_ADMIN = ['utenti', 'backup', 'logs'];

    function apriVista(view, pane, { storia = true } = {}) {
        if (!document.getElementById('view-' + view) || (VISTE_ADMIN.includes(view) && !isAdmin)) view = 'oggi';
        document.querySelectorAll('.nav-item[data-view]').forEach(n => {
            const on = n.dataset.view === view;
            n.classList.toggle('active', on);
            if (on) n.setAttribute('aria-current', 'page'); else n.removeAttribute('aria-current');
        });
        document.querySelectorAll('.view-section').forEach(v => v.classList.toggle('active', v.id === 'view-' + view));
        if (pane && document.getElementById(pane)) UI.mostraPane(pane, () => {});
        const p = paneAttivo(view);
        const hash = '#' + view + (p ? '/' + p : '');
        if (storia && location.hash !== hash) history.pushState(null, '', hash);
        carica(view, p);
        if (window.ModMovimenti) ModMovimenti.aggiornaBadge();
        document.getElementById('user-dropdown')?.classList.add('hidden');
        window.scrollTo(0, 0);
    }
    window.apriVista = apriVista;

    document.querySelectorAll('.nav-item[data-view]').forEach(item => {
        item.addEventListener('click', () => apriVista(item.dataset.view));
    });
    UI.initVtabs((view, pane) => {
        history.replaceState(null, '', '#' + view + '/' + pane);
        carica(view, pane);
    });
    // Anno condiviso: cambiarlo ricarica la scheda aperta
    UI.initAnno(() => {
        const v = document.querySelector('.view-section.active')?.id.replace('view-', '');
        if (v) carica(v);
    });
    window.addEventListener('popstate', () => {
        // Link di reimpostazione password e simili: non sono viste
        if (!/^#[a-z]+(\/[a-z0-9-]+)?$/i.test(location.hash) && location.hash !== '') return;
        // Sul telefono «indietro» con una finestra aperta la chiude
        if (UI.isModalOpen()) UI.closeModal();
        const [v, p] = location.hash.slice(1).split('/');
        apriVista(v || 'oggi', p, { storia: false });
    });

    // ── User Dropdown ──
    const userBtn = document.getElementById('user-menu-btn');
    const userDropdown = document.getElementById('user-dropdown');
    
    if (userBtn && userDropdown) {
        userBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            userDropdown.classList.toggle('hidden');
        });
        
        document.addEventListener('click', (e) => {
            if (!userBtn.contains(e.target) && !userDropdown.contains(e.target)) {
                userDropdown.classList.add('hidden');
            }
        });
    }

    // ── Action Buttons ──
    document.getElementById('btn-add-cliente').addEventListener('click', () => ModClienti.openNew());
    document.getElementById('btn-add-giornata').addEventListener('click', () => ModTrasferte.openNew());
    document.getElementById('btn-add-fattura').addEventListener('click', () => ModContabilita.openNew());
    
    // ── Importazione: un solo punto d'ingresso (pulsanti [data-importa] e file trascinati sulla finestra) ──
    ModImporta.initTrascina();

    // ── Clienti nel conteggio di KPI e grafico ──
    document.getElementById('btn-filtro-clienti')?.addEventListener('click', () => ModContabilita.apriFiltroClienti());

    // ── Nuovo Incarico ──
    const btnAddIncarico = document.getElementById('btn-add-incarico');
    if (btnAddIncarico) {
        btnAddIncarico.addEventListener('click', () => ModIncarichi.openNew());
    }

    // ── Logout ──
    document.getElementById('logout-btn').addEventListener('click', async () => {
        // Invalida il cookie lato server (se endpoint disponibile) e pulisci localStorage
        try { await Store.api('logout', 'auth'); } catch(e) { /* best-effort */ }
        localStorage.removeItem('erp_user');
        window.location.reload();
    });

    // ── Init Filters ──
    ModTrasferte.initFilters();
    ModContabilita.initFilters();
    ModClienti.initSearch();

    // L'elenco clienti serve ai moduli di offerte, commesse, fatture, trasferte e spese: si carica subito
    ModClienti.load();

    // ── Prima vista: quella dell'indirizzo (#vista/scheda) o la dashboard ──
    const [v0, p0] = location.hash.slice(1).split('/');
    apriVista(v0 || 'oggi', p0, { storia: false });
    history.replaceState(null, '', location.hash || '#oggi');

    // Aperto dal pulsante «Da FattureWeb»: riceve le fatture e apre Importa file
    ModFattureWeb.init();
}
