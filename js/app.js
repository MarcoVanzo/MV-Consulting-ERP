'use strict';

/**
 * App — Main orchestrator per MV Consulting ERP
 */
document.addEventListener('DOMContentLoaded', () => {
    // Non richiediamo più erp_token rigorosamente perché gestito tramite Cookie HttpOnly
    const userData = JSON.parse(localStorage.getItem('erp_user') || 'null');

    if (userData) {
        initApplication(userData);
    } else {
        AuthFlow.showLoginScreen((data) => {
            if (data.token) {
                localStorage.setItem('erp_token', data.token);
            }
            localStorage.setItem('erp_user', JSON.stringify(data));
            initApplication(data);
        });
    }
});

function initApplication(userData) {
    document.getElementById('auth-screen').classList.add('hidden');
    document.getElementById('app-shell').classList.remove('hidden');

    // Set user info in sidebar
    if (userData) {
        const name = userData.name || userData.email || 'User';
        document.getElementById('user-display-name').textContent = name;
        const initials = name.split(' ').map(w => w[0]).join('').substring(0, 2).toUpperCase();
        document.getElementById('user-avatar').textContent = initials;
    }

    // ── Init core UI ──
    UI.initModalEvents();

    // ── Navigation ──
    const navItems = document.querySelectorAll('.nav-item[data-view]');
    const views = document.querySelectorAll('.view-section');

    navItems.forEach(item => {
        item.addEventListener('click', () => {
            const viewId = item.dataset.view;
            
            navItems.forEach(n => n.classList.remove('active'));
            item.classList.add('active');

            views.forEach(v => v.classList.remove('active'));
            const target = document.getElementById('view-' + viewId);
            if (target) target.classList.add('active');

            // Lazy-load modules
            switch (viewId) {
                case 'clienti':     ModClienti.load(); break;
                case 'trasferte':   ModTrasferte.load(); break;
                case 'contabilita': ModContabilita.load(); break;
                case 'utenti':      ModAdmin.loadUsers(); break;
                case 'backup':      ModAdmin.loadBackups(); break;
                case 'logs':        ModAdmin.loadLogs(); break;
            }

            // Close dropdown if it was a dropdown item
            if (item.classList.contains('dropdown-item')) {
                document.getElementById('user-dropdown').classList.add('hidden');
            }
        });
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
    
    // ── PDF Import ──
    const btnImportPdf = document.getElementById('btn-import-pdf-fatture');
    const inputImportPdf = document.getElementById('input-pdf-fatture');
    if (btnImportPdf && inputImportPdf) {
        btnImportPdf.addEventListener('click', () => inputImportPdf.click());
        inputImportPdf.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                ModContabilita.importPdf(e.target.files[0]);
                e.target.value = ''; // Reset
            }
        });
    }

    // ── XML Import ──
    const btnImportXml = document.getElementById('btn-import-xml-fatture');
    const inputImportXml = document.getElementById('input-xml-fatture');
    if (btnImportXml && inputImportXml) {
        btnImportXml.addEventListener('click', () => inputImportXml.click());
        inputImportXml.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                ModContabilita.importXml(Array.from(e.target.files));
                e.target.value = ''; // Reset
            }
        });
    }

    // ── Payment PDF Import ──
    const btnImportPayment = document.getElementById('btn-import-payment-pdf');
    const inputImportPayment = document.getElementById('input-payment-pdf');
    if (btnImportPayment && inputImportPayment) {
        btnImportPayment.addEventListener('click', () => inputImportPayment.click());
        inputImportPayment.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                ModContabilita.importPaymentPdf(Array.from(e.target.files));
                e.target.value = ''; // Reset
            }
        });
    }

    // ── Incarichi PDF Import ──
    const btnImportIncPdf = document.getElementById('btn-import-pdf-incarico');
    const inputImportIncPdf = document.getElementById('input-pdf-incarico');
    if (btnImportIncPdf && inputImportIncPdf) {
        btnImportIncPdf.addEventListener('click', () => inputImportIncPdf.click());
        inputImportIncPdf.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                ModIncarichi.importPdf(e.target.files[0]);
                e.target.value = '';
            }
        });
    }

    // ── Nuovo Incarico ──
    const btnAddIncarico = document.getElementById('btn-add-incarico');
    if (btnAddIncarico) {
        btnAddIncarico.addEventListener('click', () => ModIncarichi.openNew());
    }

    // ── Logout ──
    document.getElementById('logout-btn').addEventListener('click', () => {
        localStorage.removeItem('erp_token');
        localStorage.removeItem('erp_user');
        window.location.reload();
    });

    // ── Init Filters ──
    ModTrasferte.initFilters();
    ModContabilita.initFilters();
    ModClienti.initSearch();

    // ── Load first view ──
    ModClienti.load();
}
