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
    let _modalReturnFocus = null;

    // opts.wide: modal largo (schede con tabelle); opts.readOnly: solo "Chiudi"
    function openModal(title, bodyHtml, onSave, opts = {}) {
        document.getElementById('modal').classList.toggle('modal-wide', !!opts.wide);
        document.getElementById('modal-title').textContent = title;
        document.getElementById('modal-body').innerHTML = bodyHtml;
        _modalSaveCallback = onSave;
        // Ripristina i pulsanti del footer (qualche modulo li nasconde o rinomina)
        const saveBtn = document.getElementById('modal-save');
        if (saveBtn) { saveBtn.style.display = ''; saveBtn.innerHTML = opts.saveLabel || 'Salva'; }
        const cancelBtn = document.getElementById('modal-cancel');
        if (cancelBtn) cancelBtn.textContent = 'Annulla';
        if (opts.readOnly) {
            if (saveBtn) saveBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.textContent = 'Chiudi';
        }
        const overlay = document.getElementById('modal-overlay');
        // Ricorda chi aveva il fuoco solo alla prima apertura (una modale può sostituirne un'altra)
        if (!overlay.classList.contains('active')) _modalReturnFocus = document.activeElement;
        overlay.classList.add('active');
        focusFirstField();
    }

    function closeModal() {
        document.getElementById('modal-overlay').classList.remove('active');
        _modalSaveCallback = null;
        const back = _modalReturnFocus;
        _modalReturnFocus = null;
        if (back && typeof back.focus === 'function' && document.contains(back)) back.focus();
    }

    // ── Gestione del fuoco nella modale ──
    const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    function modalFocusables() {
        return [...document.getElementById('modal').querySelectorAll(FOCUSABLE)]
            .filter(el => el.offsetParent !== null || el.getClientRects().length > 0);
    }

    // Primo campo utile del corpo; in mancanza, il primo pulsante del footer, poi la X
    function focusFirstField() {
        const body = document.getElementById('modal-body');
        const campo = [...body.querySelectorAll('input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled])')]
            .find(el => !el.readOnly && (el.offsetParent !== null || el.getClientRects().length > 0));
        const target = campo
            || modalFocusables().find(el => !body.contains(el) && el.id !== 'modal-close')
            || document.getElementById('modal-close');
        if (target) target.focus();
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
            if (!overlay.classList.contains('active')) return;
            if (e.key === 'Escape') { closeModal(); return; }
            // Il Tab resta dentro la modale
            if (e.key !== 'Tab') return;
            const items = modalFocusables();
            if (!items.length) { e.preventDefault(); return; }
            const first = items[0], last = items[items.length - 1];
            const modal = document.getElementById('modal');
            if (!modal.contains(document.activeElement)) { e.preventDefault(); (e.shiftKey ? last : first).focus(); }
            else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
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
        // useGrouping 'always': in italiano 6000 resterebbe senza punto, 12.000 no
        return num.toLocaleString('it-IT', { style: 'currency', currency: 'EUR', useGrouping: 'always' });
    }

    /** "1 fattura" / "3 fatture" */
    function plurale(n, uno, molti) {
        n = parseInt(n) || 0;
        return `${n} ${n === 1 ? uno : molti}`;
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

    // Tipi di commessa di offerte e incarichi: codice nel DB → etichetta (stesso elenco in CommessaService::TIPI)
    const TIPI_COMMESSA = {
        assistenza: 'Assistenza', dpo: 'DPO', formazione: 'Formazione', nis2: 'Consulenza NIS 2',
        ict: 'Consulenza ICT', digital: 'Consulenza Digital', sviluppo_software: 'Sviluppo Software', viaggio: 'Viaggio', noleggio: 'Noleggio', altro: 'Altro',
    };
    const tipoCommessa = t => TIPI_COMMESSA[t] || String(t || '');
    /** <option> dei tipi di commessa, con quello scelto selezionato. */
    const tipiCommessaOptions = scelto => Object.entries(TIPI_COMMESSA)
        .map(([v, l]) => `<option value="${v}" ${v === (scelto || 'assistenza') ? 'selected' : ''}>${esc(l)}</option>`).join('');

    /**
     * Ricerca unica della partita IVA (clienti, partner, nuovo partner da commessa).
     * Accetta "IT" davanti e spazi; restituisce i dati dell'azienda e, in gia_presenti,
     * le anagrafiche che hanno già quella P.IVA.
     */
    async function cercaPiva(valore) {
        const piva = String(valore || '').toUpperCase().replace(/\s+/g, '').replace(/^IT/, '').replace(/\D/g, '');
        if (!/^\d{11}$/.test(piva)) throw new Error('La partita IVA deve avere 11 cifre');
        const d = await Store.api('lookup-vat', 'clienti', { vat: piva }) || {};
        d.partita_iva = piva;
        d.gia_presenti = d.gia_presenti || [];
        return d;
    }

    /** "Già in anagrafica: …" per i risultati di cercaPiva, oppure stringa vuota. */
    function testoGiaPresenti(d) {
        const tipi = { cliente: 'cliente', partner: 'partner', fornitore: 'fornitore' };
        const l = (d?.gia_presenti || []).map(x => `${x.nome} (${tipi[x.tipo] || x.tipo})`);
        return l.length ? 'Già in anagrafica: ' + l.join(', ') : '';
    }

    /**
     * Piccola finestra per un solo valore (data, numero, testo) al posto di prompt():
     * su telefono una data si sceglie dal calendario. Restituisce il valore o null se si annulla.
     */
    function chiedi({ titolo, etichetta = '', tipo = 'date', valore = '', conferma = 'Conferma' }) {
        return new Promise(resolve => {
            const d = document.createElement('dialog');
            d.className = 'mini-dialog';
            d.innerHTML = `<form method="dialog">
                <h3>${esc(titolo)}</h3>
                <label class="mini-campo"><span>${esc(etichetta)}</span>
                    <input class="form-control" type="${['number', 'text', 'email'].includes(tipo) ? tipo : 'date'}" value="${esc(valore)}"${tipo === 'number' ? ' min="1" inputmode="numeric"' : ''}></label>
                <div class="mini-azioni">
                    <button class="btn btn-ghost" type="button" data-annulla>Annulla</button>
                    <button class="btn btn-primary" value="ok">${esc(conferma)}</button>
                </div></form>`;
            document.body.appendChild(d);
            const inp = d.querySelector('input');
            d.querySelector('[data-annulla]').addEventListener('click', () => d.close(''));
            d.addEventListener('close', () => {
                const v = d.returnValue === 'ok' ? inp.value : null;
                d.remove();
                resolve(!['text'].includes(tipo) && v === '' ? null : v);
            });
            d.showModal();
            inp.focus();
        });
    }

    // ── Anno di lavoro: uno solo per tutte le viste (selettori .sel-anno sincronizzati) ──
    let _anno = new Date().getFullYear();
    function anno() { return _anno; }
    /** Cambia l'anno di lavoro senza ricaricare (es. dopo aver salvato un documento di un altro anno). */
    function impostaAnno(y) {
        _anno = parseInt(y, 10) || _anno;
        document.querySelectorAll('.sel-anno').forEach(s => { s.value = _anno; });
    }
    function initAnno(onChange) {
        const corrente = new Date().getFullYear();
        document.querySelectorAll('.sel-anno').forEach(sel => {
            sel.innerHTML = '';
            for (let y = corrente + 1; y >= 2024; y--) sel.add(new Option(y, y, false, y === _anno));
            sel.addEventListener('change', () => {
                _anno = parseInt(sel.value, 10);
                document.querySelectorAll('.sel-anno').forEach(s => { s.value = _anno; });
                onChange?.(_anno);
            });
        });
    }

    /** Schede di una vista: pulsanti .vtab[data-pane] dentro .vtabs, pannelli .vpane. */
    let _suScheda = null;
    function initVtabs(onChange) {
        _suScheda = onChange;
        document.querySelectorAll('.vtabs').forEach(barra => {
            const tabs = [...barra.querySelectorAll('.vtab')];
            tabs.forEach(t => {
                t.id = t.id || 'tab-' + t.dataset.pane;
                t.setAttribute('aria-controls', t.dataset.pane);
                t.tabIndex = t.classList.contains('active') ? 0 : -1;
                document.getElementById(t.dataset.pane)?.setAttribute('aria-labelledby', t.id);
            });
            tabs.forEach(t => t.addEventListener('click', () => mostraPane(t.dataset.pane, onChange)));
            // Frecce sinistra/destra tra le schede, come da pattern ARIA tablist
            barra.addEventListener('keydown', e => {
                if (!['ArrowLeft', 'ArrowRight'].includes(e.key)) return;
                const i = tabs.indexOf(document.activeElement);
                if (i < 0) return;
                const n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
                n.focus(); n.click();
            });
        });
    }
    /** Mostra una scheda; onChange predefinito = quello registrato da initVtabs (carica e aggiorna l'indirizzo). */
    function mostraPane(paneId, onChange = _suScheda) {
        const pane = document.getElementById(paneId);
        const vista = pane?.closest('.view-section');
        if (!pane || !vista) return;
        vista.querySelectorAll('.vtab').forEach(t => {
            const on = t.dataset.pane === paneId;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
        });
        vista.querySelectorAll(':scope > .vpane').forEach(p => p.classList.toggle('active', p === pane));
        onChange?.(vista.id.replace('view-', ''), paneId);
    }

    /**
     * Tabelle .tabella-schede: su telefono diventano schede impilate (css/viste.css). Ogni cella prende
     * come data-label l'intestazione della sua colonna; un osservatore lo rifà sulle sole tabelle toccate
     * dai nodi aggiunti (non su tutto il documento a ogni modifica del DOM).
     */
    function etichettaTabella(t) {
        const nomi = [...t.querySelectorAll('thead th')].map(th => th.textContent.trim());
        t.querySelectorAll('tbody tr').forEach(tr => {
            if (tr.children.length !== nomi.length) return; // righe vuote con colspan
            [...tr.children].forEach((td, i) => { if (nomi[i]) td.dataset.label = nomi[i]; else td.classList.add('senza-etichetta'); });
        });
    }
    function etichettaTabelle(root = document) {
        root.querySelectorAll('table.tabella-schede').forEach(etichettaTabella);
    }
    function initTabelleSchede() {
        etichettaTabelle();
        const daFare = new Set();
        let attesa = false;
        new MutationObserver(mutazioni => {
            for (const m of mutazioni) {
                for (const n of m.addedNodes) {
                    if (n.nodeType !== Node.ELEMENT_NODE) continue;
                    const dentro = n.closest('table.tabella-schede');
                    if (dentro) daFare.add(dentro);
                    n.querySelectorAll('table.tabella-schede').forEach(t => daFare.add(t));
                }
            }
            if (!daFare.size || attesa) return;
            attesa = true;
            requestAnimationFrame(() => {
                attesa = false;
                daFare.forEach(t => { if (t.isConnected) etichettaTabella(t); });
                daFare.clear();
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    function isModalOpen() {
        return document.getElementById('modal-overlay').classList.contains('active');
    }

    return {
        toast, openModal, closeModal, initModalEvents, copyText, isModalOpen,
        formatCurrency, formatDate, formatNumber,
        statoBadge, populateYearSelect, esc, safeUrl, todayLocal, tipoCommessa, tipiCommessaOptions,
        cercaPiva, testoGiaPresenti, plurale, chiedi, anno, impostaAnno, initAnno, initVtabs, mostraPane, initTabelleSchede
    };
})();

window.UI = UI;
