'use strict';
/**
 * Modulo Commessa — scheda dell'incarico (piano di fatturazione, fatture collegate,
 * partner e loro fatture, margine) e vista Margini per anno.
 */
const ModCommessa = (() => {
    let _id = null, _d = null, _rate = [], _libere = [], _onClose = null;

    const num = v => parseFloat(String(v).replace(',', '.')) || 0;
    const STATO_RATA = {
        da_fatturare: ['badge-yellow', 'Da fatturare'],
        fatturata:    ['badge-blue', 'Fatturata'],
        incassata:    ['badge-green', 'Incassata'],
    };

    async function open(incaricoId, onClose) {
        _id = incaricoId;
        _onClose = onClose || null;
        UI.openModal('Scheda commessa', '<div id="cm-body"><div class="scad-empty"><i class="ph ph-spinner ph-spin"></i> Caricamento…</div></div>', null, { wide: true, readOnly: true });
        await refresh();
    }

    async function refresh() {
        try {
            const [d, libere] = await Promise.all([
                Store.api('get', 'commesse', { id: _id }),
                Store.api('fatture_libere', 'commesse', { incarico_id: _id }),
            ]);
            _d = d;
            _libere = libere || [];
            _rate = d.rate.map(r => ({ ...r }));
            render();
            if (_onClose) _onClose();
        } catch (e) {
            const b = document.getElementById('cm-body');
            if (b) b.innerHTML = `<div class="notice">${UI.esc(e.message)}</div>`;
        }
    }

    function render() {
        const body = document.getElementById('cm-body');
        if (!body) return;
        const i = _d.incarico, m = _d.margine || {};
        document.getElementById('modal-title').textContent = `Commessa — ${i.cliente_nome || ''}`;
        const pct = v => (v === null || v === undefined) ? '—' : UI.formatNumber(v, 0) + '%';
        body.innerHTML = `
            <div class="info-grid">
                <div><div class="k">Cliente</div><div class="v">${UI.esc(i.cliente_nome || '—')}${i.sottocliente_nome ? ' / ' + UI.esc(i.sottocliente_nome) : ''}</div></div>
                <div><div class="k">Oggetto</div><div class="v">${UI.esc(i.offerta_oggetto || i.descrizione || UI.tipoCommessa(i.tipo_commessa))}</div></div>
                <div><div class="k">Offerta</div><div class="v">${i.offerta_numero ? UI.esc(i.offerta_numero) + (i.offerta_versione > 1 ? ' v' + UI.esc(i.offerta_versione) : '') : '—'}</div></div>
                <div><div class="k">Protocollo cliente</div><div class="v">${UI.esc(i.numero_protocollo || '—')}</div></div>
                <div><div class="k">Data incarico</div><div class="v">${UI.formatDate(i.data_incarico)}</div></div>
                <div><div class="k">Pagamento</div><div class="v">${UI.esc(i.giorni_pagamento)} gg d.f.${i.condizioni_pagamento ? ' · ' + UI.esc(i.condizioni_pagamento) : ''}</div></div>
                ${i.pdf_path ? `<div><div class="k">Documento</div><div class="v"><a href="#" id="cm-pdf"><i class="ph ph-file-pdf"></i> Lettera d'incarico</a></div></div>` : ''}
            </div>

            <div class="margin-boxes" style="margin-top:14px">
                <div class="margin-box"><div class="mb-label">Valore commessa</div><div class="mb-value">${UI.formatCurrency(m.ricavo_previsto)}</div><div class="mb-sub">fatturato ${UI.formatCurrency(m.fatturato)} · incassato ${UI.formatCurrency(m.incassato)}</div></div>
                <div class="margin-box"><div class="mb-label">Costi partner</div><div class="mb-value">${UI.formatCurrency(m.costi_previsti)}</div><div class="mb-sub">ricevuti ${UI.formatCurrency(m.costi_effettivi)} · pagati ${UI.formatCurrency(m.pagato_partner)}</div></div>
                <div class="margin-box"><div class="mb-label">Margine previsto</div><div class="mb-value">${UI.formatCurrency(m.margine_previsto)}</div><div class="mb-sub">${pct(m.margine_previsto_pct)} del valore</div></div>
                <div class="margin-box"><div class="mb-label">Margine ad oggi</div><div class="mb-value">${UI.formatCurrency(m.margine_effettivo)}</div><div class="mb-sub">fatturato − fatture partner · ${pct(m.margine_effettivo_pct)}</div></div>
            </div>

            <div class="section-title"><i class="ph ph-calendar-dots"></i> Piano di fatturazione</div>
            <div id="cm-rate"></div>

            <div class="section-title"><i class="ph ph-handshake"></i> Partner della commessa</div>
            <div id="cm-costi"></div>

            ${_d.fatture_passive.length ? `<div class="section-title"><i class="ph ph-receipt"></i> Fatture dei partner</div>
            <table class="rows-editor"><tbody>${_d.fatture_passive.map(f => `<tr>
                <td>${UI.esc(f.fornitore_nome || '—')}</td><td class="td-mono">${UI.esc(f.numero)}</td><td>${UI.formatDate(f.data_emissione)}</td>
                <td class="text-right">${UI.formatCurrency(f.imponibile)}</td>
                <td>${f.stato === 'pagata' ? '<span class="badge badge-green">Pagata</span>' : `<span class="badge badge-yellow">Scade ${UI.formatDate(f.data_scadenza)}</span>`}</td></tr>`).join('')}</tbody></table>` : ''}

            ${_d.fatture.length ? `<div class="section-title"><i class="ph ph-invoice"></i> Fatture emesse</div>
            <table class="rows-editor"><tbody>${_d.fatture.map(f => `<tr>
                <td class="td-mono">${UI.esc(f.numero_fattura)}</td><td>${UI.formatDate(f.data_emissione)}</td>
                <td class="text-right">${UI.formatCurrency(f.imponibile)}</td><td>${UI.statoBadge(f.stato)}</td>
                <td>${f.data_scadenza ? 'scade ' + UI.formatDate(f.data_scadenza) : ''}</td></tr>`).join('')}</tbody></table>` : ''}`;

        const pdf = document.getElementById('cm-pdf');
        if (pdf) pdf.addEventListener('click', e => { e.preventDefault(); window.open(`api/router.php?module=incarichi&action=documento&id=${encodeURIComponent(_id)}`, '_blank', 'noopener'); });
        renderRate();
        ModPartner.renderCosti(document.getElementById('cm-costi'), _d.costi, { incarico_id: _id }, refresh);
    }

    function renderRate() {
        const box = document.getElementById('cm-rate');
        const valore = num(_d.incarico.importo_totale);
        const somma = _rate.reduce((a, r) => a + num(r.importo), 0);
        const libereOpts = _libere.map(f => `<option value="${f.id}">${UI.esc(f.numero_fattura)} del ${UI.formatDate(f.data_emissione)} — ${UI.formatCurrency(f.imponibile)}</option>`).join('');
        box.innerHTML = `<div class="table-container" style="border:none;background:none"><table class="rows-editor"><thead><tr>
                <th>Rata</th><th>Da fatturare il</th><th class="text-right">Importo</th><th>Pag. gg</th><th>Stato</th><th>Fattura</th></tr></thead><tbody>
            ${_rate.map((r, i) => {
                const bloccata = !!r.fattura_id;
                const [cls, lbl] = STATO_RATA[r.stato] || STATO_RATA.da_fatturare;
                const fattura = bloccata
                    ? `<span class="td-mono">${UI.esc(r.numero_fattura)}</span> <button type="button" class="btn btn-sm btn-ghost" data-unlink="${r.id}" title="Scollega" aria-label="Scollega fattura"><i class="ph ph-link-break"></i></button>`
                    : (r.id ? `<div class="flex" style="gap:4px"><button type="button" class="btn btn-sm btn-primary" data-copy="${i}" title="Copia i dati per Sistemi" aria-label="Copia per Sistemi"><i class="ph ph-copy"></i></button>
                        ${libereOpts ? `<select class="form-control" data-link="${r.id}" style="max-width:190px"><option value="">Collega fattura…</option>${libereOpts}</select>` : ''}</div>` : '<span class="scad-empty">salva il piano</span>');
                return `<tr>
                    <td><input class="form-control" data-r="${i}" data-k="descrizione" value="${UI.esc(r.descrizione)}"></td>
                    <td><input type="date" class="form-control" data-r="${i}" data-k="data_prevista" value="${UI.esc(r.data_prevista || '')}"></td>
                    <td><input type="number" step="0.01" class="form-control num" data-r="${i}" data-k="importo" value="${UI.esc(r.importo)}" ${bloccata ? 'disabled' : ''}></td>
                    <td><input type="number" class="form-control num-s" data-r="${i}" data-k="giorni_pagamento" value="${UI.esc(r.giorni_pagamento ?? _d.incarico.giorni_pagamento)}"></td>
                    <td><span class="badge ${cls}">${lbl}</span></td>
                    <td>${fattura}${!bloccata ? ` <button type="button" class="btn btn-sm btn-ghost" data-del="${i}" aria-label="Elimina rata"><i class="ph ph-x"></i></button>` : ''}</td></tr>`;
            }).join('')}</tbody></table></div>
            <div class="rows-total">
                <button type="button" class="chip-btn" id="cm-add"><i class="ph ph-plus"></i> Rata</button>
                <span>Totale rate <b id="cm-rate-tot" class="${Math.abs(somma - valore) > 0.01 ? 'text-danger' : 'text-ok'}">${UI.formatCurrency(somma)}</b> su ${UI.formatCurrency(valore)}</span>
                <button type="button" class="btn btn-sm btn-primary" id="cm-save-rate"><i class="ph ph-floppy-disk"></i> Salva piano</button>
            </div>`;

        // Sul change si aggiorna solo il totale: ridisegnare la tabella farebbe perdere il fuoco (Tab)
        box.querySelectorAll('input[data-r]').forEach(inp => inp.addEventListener('change', () => {
            _rate[inp.dataset.r][inp.dataset.k] = inp.value;
            if (inp.dataset.k !== 'importo') return;
            const somma = _rate.reduce((a, r) => a + num(r.importo), 0);
            const tot = document.getElementById('cm-rate-tot');
            tot.textContent = UI.formatCurrency(somma);
            tot.className = Math.abs(somma - valore) > 0.01 ? 'text-danger' : 'text-ok';
        }));
        box.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => { _rate.splice(b.dataset.del, 1); renderRate(); }));
        document.getElementById('cm-add').addEventListener('click', () => {
            const residuo = Math.max(0, num(_d.incarico.importo_totale) - _rate.reduce((a, r) => a + num(r.importo), 0));
            _rate.push({ descrizione: 'Rata', data_prevista: '', importo: residuo.toFixed(2), giorni_pagamento: _d.incarico.giorni_pagamento, stato: 'da_fatturare' });
            renderRate();
        });
        document.getElementById('cm-save-rate').addEventListener('click', async () => {
            try {
                await Store.api('save_rate', 'commesse', { incarico_id: _id, rate: JSON.stringify(_rate) });
                UI.toast('Piano salvato');
                refresh();
            } catch (e) { UI.toast(e.message, 'error'); }
        });
        box.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => UI.copyText(testoSistemi(_rate[b.dataset.copy]))));
        box.querySelectorAll('[data-link]').forEach(s => s.addEventListener('change', async () => {
            if (!s.value) return;
            try { await Store.api('collega_fattura', 'commesse', { rata_id: s.dataset.link, fattura_id: s.value }); refresh(); }
            catch (e) { UI.toast(e.message, 'error'); }
        }));
        box.querySelectorAll('[data-unlink]').forEach(b => b.addEventListener('click', async () => {
            try { await Store.api('collega_fattura', 'commesse', { rata_id: b.dataset.unlink, fattura_id: '' }); refresh(); }
            catch (e) { UI.toast(e.message, 'error'); }
        }));
    }

    function testoSistemi(r) {
        const i = _d.incarico;
        const imp = num(r.importo);
        return [
            `Cliente: ${i.cliente_nome || ''}`,
            i.cliente_piva ? `P.IVA: ${i.cliente_piva}` : '',
            i.cliente_sdi ? `Codice SDI: ${i.cliente_sdi}` : (i.cliente_pec ? `PEC: ${i.cliente_pec}` : ''),
            `Descrizione: ${r.testo_fattura || r.descrizione}`,
            `Imponibile: ${imp.toFixed(2).replace('.', ',')} €`,
            `Pagamento: bonifico a ${r.giorni_pagamento ?? i.giorni_pagamento} gg data fattura`,
        ].filter(Boolean).join('\n');
    }

    // ── Vista Margini ───────────────────────────────────

    async function loadMargini() {
        const box = document.getElementById('comm-margini');
        try {
            const res = await Store.api('margini', 'commesse', { year: ModCommerciale.year() });
            const t = res.totali, rows = res.commesse || [];
            const pct = v => (v === null || v === undefined) ? '—' : UI.formatNumber(v, 0) + '%';
            box.innerHTML = `
                <div class="kpi-grid">
                    <div class="kpi-card kpi-blue"><div class="kpi-label">Valore commesse</div><div class="kpi-value">${UI.formatCurrency(t.ricavo_previsto)}</div><div class="kpi-sub">${rows.length} commesse</div></div>
                    <div class="kpi-card kpi-yellow"><div class="kpi-label">Costi partner previsti</div><div class="kpi-value">${UI.formatCurrency(t.costi_previsti)}</div><div class="kpi-sub">ricevuti ${UI.formatCurrency(t.costi_effettivi)}</div></div>
                    <div class="kpi-card kpi-green"><div class="kpi-label">Margine previsto</div><div class="kpi-value">${UI.formatCurrency(t.margine_previsto)}</div><div class="kpi-sub">${pct(t.margine_previsto_pct)}</div></div>
                    <div class="kpi-card kpi-red"><div class="kpi-label">Margine ad oggi</div><div class="kpi-value">${UI.formatCurrency(t.margine_effettivo)}</div><div class="kpi-sub">su fatturato ${UI.formatCurrency(t.fatturato)} · ${pct(t.margine_effettivo_pct)}</div></div>
                </div>
                <div class="table-container"><table class="data-table"><thead><tr>
                    <th>Cliente</th><th>Commessa</th><th>Data</th><th class="text-right">Valore</th><th class="text-right">Costi prev.</th>
                    <th class="text-right">Margine prev.</th><th class="text-right">Fatturato</th><th class="text-right">Costi ricevuti</th><th class="text-right">Margine oggi</th></tr></thead><tbody>
                ${rows.length ? rows.map(r => `<tr style="cursor:pointer" data-open="${r.id}">
                    <td class="td-primary">${UI.esc(r.cliente_nome || '—')}${r.sottocliente_nome ? ` <span style="color:var(--text-muted)">/ ${UI.esc(r.sottocliente_nome)}</span>` : ''}</td>
                    <td>${UI.esc(r.descrizione || UI.tipoCommessa(r.tipo_commessa))}</td>
                    <td>${UI.formatDate(r.data_incarico)}</td>
                    <td class="text-right">${UI.formatCurrency(r.ricavo_previsto)}</td>
                    <td class="text-right">${UI.formatCurrency(r.costi_previsti)}</td>
                    <td class="text-right td-primary">${UI.formatCurrency(r.margine_previsto)} <span style="color:var(--text-muted)">${pct(r.margine_previsto_pct)}</span></td>
                    <td class="text-right">${UI.formatCurrency(r.fatturato)}</td>
                    <td class="text-right">${UI.formatCurrency(r.costi_effettivi)}</td>
                    <td class="text-right td-primary">${UI.formatCurrency(r.margine_effettivo)}</td></tr>`).join('')
                    : '<tr><td colspan="9"><div class="empty-state"><i class="ph ph-chart-pie-slice"></i><h3>Nessuna commessa</h3></div></td></tr>'}
                </tbody></table></div>`;
            box.querySelectorAll('[data-open]').forEach(tr => tr.addEventListener('click', () => open(parseInt(tr.dataset.open, 10), loadMargini)));
        } catch (e) {
            box.innerHTML = `<div class="empty-state"><h3>Errore</h3><p>${UI.esc(e.message)}</p></div>`;
        }
    }

    return { open, loadMargini };
})();
window.ModCommessa = ModCommessa;
