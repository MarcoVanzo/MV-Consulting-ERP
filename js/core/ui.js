'use strict';

/**
 * UI — Utility di interfaccia: Modal, Toast, Helpers
 */
const UI = (() => {
    // ── Toast Notifications ──
    function toast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        const icon = type === 'success' ? 'ph-check-circle' : 'ph-warning-circle';
        el.innerHTML = `<i class="ph ${icon}"></i> <span>${UI.esc(message)}</span>`;
        container.appendChild(el);
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateX(100%)';
            setTimeout(() => el.remove(), 300);
        }, 3500);
    }

    // ── Modal System ──
    let _modalSaveCallback = null;

    // opts.wide: modal largo (schede con tabelle); opts.readOnly: solo "Chiudi"
    function openModal(title, bodyHtml, onSave, opts = {}) {
        document.getElementById('modal').classList.toggle('modal-wide', !!opts.wide);
        document.getElementById('modal-title').textContent = title;
        document.getElementById('modal-body').innerHTML = bodyHtml;
        _modalSaveCallback = onSave;
        // Ripristina i pulsanti del footer (qualche modulo li nasconde o rinomina)
        const saveBtn = document.getElementById('modal-save');
        if (saveBtn) saveBtn.style.display = '';
        const cancelBtn = document.getElementById('modal-cancel');
        if (cancelBtn) cancelBtn.textContent = 'Annulla';
        if (opts.readOnly) {
            if (saveBtn) saveBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.textContent = 'Chiudi';
        }
        document.getElementById('modal-overlay').classList.add('active');
    }

    function closeModal() {
        document.getElementById('modal-overlay').classList.remove('active');
        _modalSaveCallback = null;
    }

    function initModalEvents() {
        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('modal-cancel').addEventListener('click', closeModal);
        // Chiudi solo se pressione e rilascio avvengono entrambi sull'overlay
        const overlay = document.getElementById('modal-overlay');
        let downOnOverlay = false;
        overlay.addEventListener('mousedown', (e) => { downOnOverlay = e.target === overlay; });
        overlay.addEventListener('click', (e) => {
            if (downOnOverlay && e.target === overlay) closeModal();
            downOnOverlay = false;
        });
        // Chiusura con Esc
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.classList.contains('active')) closeModal();
        });
        const saveBtn = document.getElementById('modal-save');
        saveBtn.addEventListener('click', async () => {
            if (_modalSaveCallback) {
                const prevHtml = saveBtn.innerHTML;
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="ph ph-spinner ph-spin"></i>';
                try {
                    const res = _modalSaveCallback();
                    if (res instanceof Promise) await res;
                } catch (err) {
                    console.error("Modal save error:", err);
                    UI.toast(err.message || 'Errore durante il salvataggio', 'error');
                } finally {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = prevHtml;
                }
            }
        });
    }

    // ── Formatting ──
    function formatCurrency(val) {
        const num = parseFloat(val) || 0;
        return num.toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
    }

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const d = new Date(dateStr);
        return d.toLocaleDateString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric' });
    }

    function formatNumber(val, decimals = 1) {
        return parseFloat(val || 0).toLocaleString('it-IT', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    // ── Stato badge ──
    function statoBadge(stato) {
        const map = {
            'emessa':  { class: 'badge-blue',   label: 'Emessa' },
            'inviata': { class: 'badge-yellow', label: 'Inviata' },
            'pagata':  { class: 'badge-green',  label: 'Pagata' },
            'scaduta': { class: 'badge-red',    label: 'Scaduta' }
        };
        const s = map[stato] || { class: 'badge-blue', label: stato || '—' };
        return `<span class="badge ${s.class}">${esc(s.label)}</span>`;
    }

    // ── Year Selector ──
    function populateYearSelect(selectId, startYear = 2024) {
        const sel = document.getElementById(selectId);
        const currentYear = new Date().getFullYear();
        sel.innerHTML = '';
        for (let y = currentYear + 1; y >= startYear; y--) {
            const opt = document.createElement('option');
            opt.value = y;
            opt.textContent = y;
            if (y === currentYear) opt.selected = true;
            sel.appendChild(opt);
        }
    }

    // ── Escape HTML (anche per attributi: include " e ') ──
    function esc(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ── URL sicuro per href: solo percorsi 'uploads/' o http(s) ──
    function safeUrl(url) {
        const u = String(url || '').trim();
        if (/^uploads\//.test(u) || /^https?:\/\//i.test(u)) return esc(u);
        return '';
    }

    // ── Data di oggi in formato YYYY-MM-DD (fuso locale, non UTC) ──
    function todayLocal() {
        const d = new Date();
        const pad = n => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    // ── Copia negli appunti (con ripiego per browser senza Clipboard API) ──
    async function copyText(text) {
        try {
            await navigator.clipboard.writeText(text);
        } catch (e) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }
        toast('Copiato negli appunti');
    }

    function isModalOpen() {
        return document.getElementById('modal-overlay').classList.contains('active');
    }

    return {
        toast, openModal, closeModal, initModalEvents, copyText, isModalOpen,
        formatCurrency, formatDate, formatNumber,
        statoBadge, populateYearSelect, esc, safeUrl, todayLocal
    };
})();

window.UI = UI;
