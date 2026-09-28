'use strict';
/**
 * Scadenzario — le scadenze nel dettaglio, sotto la dashboard «Oggi»:
 * cosa fatturare in Sistemi, chi sollecitare, quali partner pagare, quali offerte ricontattare.
 * I numeri di sintesi stanno nella dashboard (ModOggi): qui solo gli elenchi con le azioni.
 */
const ModCommerciale = (() => {
    let _giorni = 7;
    let _scad = null;

    /** Anno di lavoro (condiviso da tutte le viste). */
    function year() {
        return UI.anno();
    }

    function load() {
        return loadScadenzario();
    }

    // ── Scadenzario ─────────────────────────────────────

    async function loadScadenzario() {
        const box = document.getElementById('comm-scadenzario');
        try {
            _scad = await Store.api('scadenzario', 'commesse', { giorni: _giorni });
            renderScadenzario(box);
        } catch (e) {
            box.innerHTML = `<div class="empty-state"><h3>Errore</h3><p>${UI.esc(e.message)}</p></div>`;
        }
    }

    function renderScadenzario(box) {
        const s = _scad;
        const opts = [7, 15, 30].map(g => `<option value="${g}" ${g === _giorni ? 'selected' : ''}>${g} giorni</option>`).join('');

        box.innerHTML = `
            <div class="comm-toolbar">
                <span style="color:var(--text-muted);font-size:0.85rem">Orizzonte</span>
                <select class="form-control" id="scad-giorni" style="width:auto">${opts}</select>
                <span class="spacer"></span>
                <button class="btn btn-ghost btn-sm" id="scad-refresh"><i class="ph ph-arrows-clockwise"></i> Aggiorna</button>
            </div>
            <div class="scad-grid">
                ${card('ph-receipt', 'Rate da fatturare in Sistemi', s.rate_da_fatturare, itemRata, 'Nessuna rata da emettere')}
                ${card('ph-warning-circle', 'Clienti da sollecitare', s.incassi_scaduti, itemScaduto, 'Nessun incasso in ritardo')}
                ${card('ph-hand-coins', 'Partner da pagare', s.pagamenti_partner, itemPartner, 'Nessun pagamento in scadenza')}
                ${card('ph-phone-call', 'Lead e offerte da ricontattare', s.offerte_da_ricontattare, itemFollowup, 'Nessuno da ricontattare')}
                ${card('ph-hourglass-medium', 'Offerte in scadenza', s.offerte_in_scadenza, itemOffScad, 'Nessuna offerta in scadenza')}
                ${card('ph-calendar-check', 'Incassi attesi', s.incassi_in_arrivo, itemInArrivo, 'Nessun incasso atteso')}
            </div>`;

        document.getElementById('scad-giorni').addEventListener('change', e => { _giorni = parseInt(e.target.value, 10); loadScadenzario(); });
        document.getElementById('scad-refresh').addEventListener('click', loadScadenzario);
        box.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => azione(b.dataset.act, b.dataset.i)));
    }

    function card(icon, title, rows, fmt, empty) {
        const badgeCls = rows.length ? 'badge-red' : 'badge-green';
        return `<div class="scad-card"><h3><i class="ph ${icon}"></i> ${UI.esc(title)} <span class="badge ${badgeCls}">${rows.length}</span></h3>
            ${rows.length ? rows.map(fmt).join('') : `<div class="scad-empty">${UI.esc(empty)}</div>`}</div>`;
    }

    const idx = (arr, r) => arr.indexOf(r);

    function itemRata(r) {
        const i = idx(_scad.rate_da_fatturare, r);
        const inRitardo = r.data_prevista < _scad.oggi;
        return `<div class="scad-item"><div class="scad-main">
            <div class="scad-title">${UI.esc(r.cliente_nome || '—')}${r.sottocliente_nome ? ' / ' + UI.esc(r.sottocliente_nome) : ''}</div>
            <div class="scad-sub">${UI.esc(r.testo_fattura)}</div>
            <div class="scad-sub ${inRitardo ? 'text-danger' : ''}">Da emettere il ${UI.formatDate(r.data_prevista)} · pagamento a ${UI.esc(r.giorni_pagamento)} gg</div>
            <div class="scad-actions">
                <button class="btn btn-sm btn-primary" data-act="copia-rata" data-i="${i}"><i class="ph ph-copy"></i> Copia per Sistemi</button>
                <button class="btn btn-sm btn-ghost" data-act="apri-commessa-rata" data-i="${i}"><i class="ph ph-folder-open"></i> Commessa</button>
            </div></div><div class="scad-amount">${UI.formatCurrency(r.importo)}</div></div>`;
    }

    function itemScaduto(r) {
        const i = idx(_scad.incassi_scaduti, r);
        return `<div class="scad-item"><div class="scad-main">
            <div class="scad-title">${UI.esc(r.cliente_nome || '—')}</div>
            <div class="scad-sub">Fattura ${UI.esc(r.numero_fattura)} del ${UI.formatDate(r.data_emissione)}</div>
            <div class="scad-sub text-danger">Scaduta il ${UI.formatDate(r.data_scadenza)} · ${UI.esc(r.giorni_ritardo)} giorni di ritardo</div>
            <div class="scad-actions">
                <button class="btn btn-sm btn-primary" data-act="sollecito" data-i="${i}"><i class="ph ph-envelope-simple"></i> Sollecito</button>
                <button class="btn btn-sm btn-ghost" data-act="incassata" data-i="${i}"><i class="ph ph-check"></i> Incassata</button>
            </div></div><div class="scad-amount">${UI.formatCurrency(r.importo_totale)}</div></div>`;
    }

    function itemInArrivo(r) {
        return `<div class="scad-item"><div class="scad-main">
            <div class="scad-title">${UI.esc(r.cliente_nome || '—')}</div>
            <div class="scad-sub">Fattura ${UI.esc(r.numero_fattura)} · scade il ${UI.formatDate(r.data_scadenza)}</div>
            </div><div class="scad-amount">${UI.formatCurrency(r.importo_totale)}</div></div>`;
    }

    function itemPartner(r) {
        const i = idx(_scad.pagamenti_partner, r);
        return `<div class="scad-item"><div class="scad-main">
            <div class="scad-title">${UI.esc(r.fornitore_nome || '—')}</div>
            <div class="scad-sub">Fattura ${UI.esc(r.numero)} del ${UI.formatDate(r.data_emissione)}${r.cliente_nome ? ' · commessa ' + UI.esc(r.cliente_nome) : ''}</div>
            <div class="scad-sub">${UI.esc(r.motivo)}${r.data_scadenza ? ' · scade il ' + UI.formatDate(r.data_scadenza) : ''}</div>
            <div class="scad-actions">
                ${r.fornitore_iban ? `<button class="btn btn-sm btn-ghost" data-act="iban" data-i="${i}"><i class="ph ph-bank"></i> Copia IBAN</button>` : ''}
                <button class="btn btn-sm btn-primary" data-act="pagata" data-i="${i}"><i class="ph ph-check"></i> Pagata</button>
            </div></div><div class="scad-amount">${UI.formatCurrency(r.importo_totale)}</div></div>`;
    }

    function itemFollowup(r) {
        const i = idx(_scad.offerte_da_ricontattare, r);
        return `<div class="scad-item"><div class="scad-main">
            <div class="scad-title">${UI.esc(r.cliente_nome || '—')}</div>
            <div class="scad-sub">${UI.esc(r.numero)} · ${UI.esc(r.oggetto)}</div>
            <div class="scad-sub">${r.stato === 'lead' ? `Lead${r.prossima_azione ? ' · ' + UI.esc(r.prossima_azione) : ''}` : 'Inviata il ' + UI.formatDate(r.data_invio)} · da ricontattare dal ${UI.formatDate(r.data_followup)}</div>
            <div class="scad-actions">
                <button class="btn btn-sm btn-ghost" data-act="ricontattato" data-i="${i}"><i class="ph ph-phone"></i> Sentito, riprova tra…</button>
                <button class="btn btn-sm btn-ghost" data-act="apri-offerta" data-i="${i}"><i class="ph ph-file-text"></i> Offerta</button>
            </div></div><div class="scad-amount">${UI.formatCurrency(r.imponibile)}</div></div>`;
    }

    function itemOffScad(r) {
        return `<div class="scad-item"><div class="scad-main">
            <div class="scad-title">${UI.esc(r.cliente_nome || '—')}</div>
            <div class="scad-sub">${UI.esc(r.numero)} · ${UI.esc(r.oggetto)} · valida fino al ${UI.formatDate(r.data_scadenza)}</div>
            </div><div class="scad-amount">${UI.formatCurrency(r.imponibile)}</div></div>`;
    }

    // Testo pronto da ricopiare nella fattura su Sistemi
    function testoSistemi(r) {
        // 22 solo se l'aliquota manca: 0 (esente / non imponibile) è un valore valido
        const iva = (r.iva_percentuale === null || r.iva_percentuale === undefined || r.iva_percentuale === '') ? 22 : (parseFloat(r.iva_percentuale) || 0);
        const imp = parseFloat(r.importo) || 0;
        return [
            `Cliente: ${r.cliente_nome || ''}`,
            r.cliente_piva ? `P.IVA: ${r.cliente_piva}` : (r.cliente_cf ? `C.F.: ${r.cliente_cf}` : ''),
            r.cliente_sdi ? `Codice SDI: ${r.cliente_sdi}` : (r.cliente_pec ? `PEC: ${r.cliente_pec}` : ''),
            `Descrizione: ${r.testo_fattura}`,
            `Imponibile: ${imp.toFixed(2).replace('.', ',')} €`,
            `IVA ${iva}%: ${(imp * iva / 100).toFixed(2).replace('.', ',')} €`,
            `Totale: ${(imp * (1 + iva / 100)).toFixed(2).replace('.', ',')} €`,
            `Pagamento: bonifico a ${r.giorni_pagamento} gg data fattura`,
        ].filter(Boolean).join('\n');
    }

    function mailSollecito(r) {
        const to = r.cliente_pec || r.cliente_email || '';
        const oggetto = `Sollecito pagamento fattura n. ${r.numero_fattura}`;
        const corpo = `Gentili,\n\ndalle nostre verifiche la fattura n. ${r.numero_fattura} del ${UI.formatDate(r.data_emissione)}, `
            + `di ${UI.formatCurrency(r.importo_totale)}, scaduta il ${UI.formatDate(r.data_scadenza)}, risulta ancora da saldare.\n\n`
            + `Vi chiediamo di verificare e di provvedere al pagamento. Se lo avete già disposto, non considerate questo messaggio.\n\n`
            + `Cordiali saluti\nMV Consulting S.r.l.`;
        if (!to) UI.toast('Il cliente non ha email in anagrafica: completa il destinatario', 'error');
        window.location.href = `mailto:${encodeURIComponent(to)}?subject=${encodeURIComponent(oggetto)}&body=${encodeURIComponent(corpo)}`;
    }

    /** Dopo un'azione si aggiornano anche i numeri della dashboard. */
    function aggiorna() {
        return window.ModOggi ? ModOggi.load() : loadScadenzario();
    }

    async function azione(act, i) {
        i = parseInt(i, 10);
        try {
            switch (act) {
                case 'copia-rata': await UI.copyText(testoSistemi(_scad.rate_da_fatturare[i])); break;
                case 'apri-commessa-rata': ModCommessa.open(_scad.rate_da_fatturare[i].incarico_id, aggiorna); break;
                case 'sollecito': mailSollecito(_scad.incassi_scaduti[i]); break;
                case 'incassata': {
                    const f = _scad.incassi_scaduti[i];
                    const d = await UI.chiedi({ titolo: `Incasso della fattura ${f.numero_fattura}`, etichetta: 'Data dell\'incasso', valore: UI.todayLocal(), conferma: 'Registra incasso' });
                    if (!d) return;
                    await Store.api('segna_incassata', 'commesse', { fattura_id: f.id, data_pagamento: d });
                    UI.toast('Incasso registrato');
                    aggiorna();
                    break;
                }
                case 'iban': await UI.copyText(_scad.pagamenti_partner[i].fornitore_iban); break;
                case 'pagata': {
                    const f = _scad.pagamenti_partner[i];
                    const d = await UI.chiedi({ titolo: `Pagamento a ${f.fornitore_nome}`, etichetta: 'Data del pagamento', valore: UI.todayLocal(), conferma: 'Registra pagamento' });
                    if (!d) return;
                    await Store.api('set_pagata', 'passive', { id: f.id, data_pagamento: d });
                    UI.toast('Pagamento registrato');
                    aggiorna();
                    break;
                }
                case 'ricontattato': {
                    const o = _scad.offerte_da_ricontattare[i];
                    const g = await UI.chiedi({ titolo: 'Ricontattare più avanti', etichetta: 'Tra quanti giorni', tipo: 'number', valore: '7', conferma: 'Sposta promemoria' });
                    if (!g) return;
                    const d = new Date();
                    d.setDate(d.getDate() + (parseInt(g, 10) || 7));
                    const pad = n => String(n).padStart(2, '0');
                    await Store.api('set_stato', 'offerte', { id: o.id, stato: o.stato === 'lead' ? 'lead' : 'inviata', data_followup: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` });
                    UI.toast('Promemoria spostato');
                    aggiorna();
                    break;
                }
                case 'apri-offerta': ModOfferte.edit(_scad.offerte_da_ricontattare[i].id); break;
            }
        } catch (e) {
            UI.toast(e.message || 'Errore', 'error');
        }
    }

    return { load, year, loadScadenzario };
})();
window.ModCommerciale = ModCommerciale;
