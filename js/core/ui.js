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
        el.innerHTML = `<i class="ph ${icon}"></i> <span>${message}</span>`;
        container.appendChild(el);
        setTimeout(() => {
            el.style.opacity = '0';
            el.style.transform = 'translateX(100%)';
            setTimeout(() => el.remove(), 300);
        }, 3500);
    }

    // ── Modal System ──
    let _modalSaveCallback = null;

    function openModal(title, bodyHtml, onSave) {
        document.getElementById('modal-title').textContent = title;
        document.getElementById('modal-body').innerHTML = bodyHtml;
        _modalSaveCallback = onSave;
        document.getElementById('modal-overlay').classList.add('active');
    }

    function closeModal() {
        document.getElementById('modal-overlay').classList.remove('active');
        _modalSaveCallback = null;
    }

    function initModalEvents() {
        document.getElementById('modal-close').addEventListener('click', closeModal);
        document.getElementById('modal-cancel').addEventListener('click', closeModal);
        document.getElementById('modal-overlay').addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeModal();
        });
        document.getElementById('modal-save').addEventListener('click', () => {
            if (_modalSaveCallback) _modalSaveCallback();
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
        return `<span class="badge ${s.class}">${s.label}</span>`;
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

    // ── Escape HTML ──
    function esc(str) {
        const div = document.createElement('div');
        div.textContent = str || '';
        return div.innerHTML;
    }

    return {
        toast, openModal, closeModal, initModalEvents,
        formatCurrency, formatDate, formatNumber,
        statoBadge, populateYearSelect, esc
    };
})();

window.UI = UI;
