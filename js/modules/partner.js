'use strict';
/**
 * Modulo Partner — partner e fornitori, le loro fatture (a mano o da XML/p7m)
 * e l'editor dei costi di commessa usato da offerte e scheda commessa.
 */
const ModPartner = (() => {
    let _fornitori = [], _passive = [], _filtro = 'da_pagare';

    const num = v => parseFloat(String(v).replace(',', '.')) || 0;

    async function fornitori(force = false) {
        if (force || !_fornitori.length) _fornitori = (await Store.api('list', 'fornitori')) || [];
        return _fornitori;
    }

    async function load() {
        const box = document.getElementById('comm-partner');
        try {
            await fornitori(true);
            _passive = (await Store.api('list', 'passive', { year: ModCommerciale.year(), stato: _filtro })) || [];
            render(box);
        } catch (e) {
            box.innerHTML = `<div class="empty-state"><h3>Errore</h3><p>${UI.esc(e.message)}</p></div>`;
        }
    }

    function render(box) {
        const daPagare = _fornitori.reduce((a, f) => a + num(f.da_pagare), 0);
        const chips = [['da_pagare', 'Da pagare'], ['pagata', 'Pagate'], ['', 'Tutte']]
            .map(([v, l]) => `<span class="filter-chip ${_filtro === v ? 'active' : ''}" data-f="${v}">${l}</span>`).join('');
        box.innerHTML = `
            <div class="comm-toolbar">
                <button class="btn btn-ghost" id="pa-import"><i class="ph ph-file-code"></i> Importa fatture fornitori (XML/p7m)</button>
                <input type="file" id="pa-import-file" accept=".xml,.p7m" multiple class="hidden">
                <button class="btn btn-ghost" id="pa-new-fatt"><i class="ph ph-plus"></i> Fattura fornitore</button>
                <button class="btn btn-primary" id="pa-new"><i class="ph ph-plus"></i> Nuovo partner</button>
            </div>
            <div class="table-container" style="margin-bottom:20px">
                <div class="table-toolbar"><b>Fatture ricevute</b><div class="filters-row" style="margin-bottom:0">${chips}</div></div>
                <table class="data-table"><thead><tr>
                    <th>Numero</th><th>Data</th><th>Fornitore</th><th>Commessa</th><th class="text-right">Imponibile</th>
                    <th class="text-right">Netto a pagare</th><th>Scadenza</th><th>Stato</th><th></th></tr></thead>
                <tbody>${rowsPassive()}</tbody></table>
            </div>
            <div class="table-container">
                <div class="table-toolbar"><b>Partner e fornitori</b><span style="color:var(--text-muted);font-size:0.85rem">Da pagare in totale: ${UI.formatCurrency(daPagare)}</span></div>
                <table class="data-table"><thead><tr>
                    <th>Ragione sociale</th><th>Tipo</th><th>P.IVA</th><th>IBAN</th><th class="text-right">Commesse</th><th class="text-right">Da pagare</th><th></th></tr></thead>
                <tbody>${rowsFornitori()}</tbody></table>
            </div>`;

        box.querySelectorAll('.filter-chip').forEach(c => c.addEventListener('click', () => { _filtro = c.dataset.f; load(); }));
        document.getElementById('pa-new').addEventListener('click', () => openFornitore({}));
        document.getElementById('pa-new-fatt').addEventListener('click', () => openPassiva({}));
        const inp = document.getElementById('pa-import-file');
        document.getElementById('pa-import').addEventListener('click', () => inp.click());
        inp.addEventListener('change', e => { if (e.target.files.length) importXml(Array.from(e.target.files)); e.target.value = ''; });
        box.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', e => {
            e.preventDefault(); // i link hanno href="#": senza, cambierebbe l'hash e la pagina salterebbe in cima
            azione(b.dataset.act, parseInt(b.dataset.id, 10));
        }));
    }

    function rowsPassive() {
        if (!_passive.length) return '<tr><td colspan="9"><div class="empty-state"><i class="ph ph-receipt"></i><h3>Nessuna fattura</h3><p>Importa gli XML scaricati da Sistemi o dal cassetto fiscale</p></div></td></tr>';
        const oggi = UI.todayLocal();
        return _passive.map(f => {
            const scaduta = f.stato === 'da_pagare' && f.data_scadenza && f.data_scadenza < oggi;
            return `<tr>
                <td class="td-mono">${UI.esc(f.numero)}</td>
                <td>${UI.formatDate(f.data_emissione)}</td>
                <td class="td-primary">${UI.esc(f.fornitore_nome || '—')}</td>
                <td>${f.incarico_id ? `<a href="#" data-act="commessa" data-id="${f.incarico_id}">${UI.esc(f.cliente_nome || '#' + f.incarico_id)}</a>` : '<span class="text-danger">da collegare</span>'}${f.condizione_pagamento === 'back_to_back' ? ' <span class="badge badge-purple" title="Pago quando incasso">B2B</span>' : ''}</td>
                <td class="text-right">${UI.formatCurrency(f.imponibile)}</td>
                <td class="text-right td-primary">${UI.formatCurrency(f.importo_totale)}${num(f.ritenuta) ? `<div style="font-size:0.72rem;color:var(--text-muted)">rit. ${UI.formatCurrency(f.ritenuta)}</div>` : ''}</td>
                <td class="${scaduta ? 'text-danger' : ''}">${UI.formatDate(f.data_scadenza)}</td>
                <td>${f.stato === 'pagata' ? `<span class="badge badge-green">Pagata ${UI.formatDate(f.data_pagamento)}</span>` : '<span class="badge badge-yellow">Da pagare</span>'}</td>
                <td><div class="flex" style="gap:4px">
                    ${f.stato === 'da_pagare' ? `<button class="btn btn-sm btn-primary" data-act="paga" data-id="${f.id}" title="Segna pagata" aria-label="Segna pagata"><i class="ph ph-check"></i></button>` : ''}
                    <button class="btn btn-sm btn-ghost" data-act="edit-passiva" data-id="${f.id}" aria-label="Modifica"><i class="ph ph-pencil-simple"></i></button>
                    <button class="btn btn-sm btn-danger" data-act="del-passiva" data-id="${f.id}" aria-label="Elimina"><i class="ph ph-trash"></i></button>
                </div></td></tr>`;
        }).join('');
    }

    function rowsFornitori() {
        if (!_fornitori.length) return '<tr><td colspan="7"><div class="empty-state"><i class="ph ph-handshake"></i><h3>Nessun partner</h3></div></td></tr>';
        return _fornitori.map(f => `<tr>
            <td class="td-primary">${UI.esc(f.ragione_sociale)}</td>
            <td>${f.tipo === 'partner' ? '<span class="badge badge-blue">Partner</span>' : '<span class="badge badge-purple">Fornitore</span>'}</td>
            <td class="td-mono">${UI.esc(f.partita_iva || '—')}</td>
            <td class="td-mono" style="font-size:0.72rem">${UI.esc(f.iban || '—')}</td>
            <td class="text-right">${UI.esc(f.num_commesse)}</td>
            <td class="text-right td-primary">${UI.formatCurrency(f.da_pagare)}</td>
            <td><div class="flex" style="gap:4px">
                <button class="btn btn-sm btn-ghost" data-act="edit-forn" data-id="${f.id}" aria-label="Modifica"><i class="ph ph-pencil-simple"></i></button>
                <button class="btn btn-sm btn-danger" data-act="del-forn" data-id="${f.id}" aria-label="Elimina"><i class="ph ph-trash"></i></button>
            </div></td></tr>`).join('');
    }

    async function azione(act, id) {
        try {
            switch (act) {
                case 'edit-forn': openFornitore(_fornitori.find(f => f.id == id) || {}); break;
                case 'del-forn':
                    if (!confirm('Eliminare il partner? Le fatture restano.')) return;
                    await Store.api('delete', 'fornitori', { id });
                    load();
                    break;
                case 'edit-passiva': openPassiva(_passive.find(f => f.id == id) || {}); break;
                case 'del-passiva':
                    if (!confirm('Eliminare la fattura del fornitore?')) return;
                    await Store.api('delete', 'passive', { id });
                    load();
                    break;
                case 'paga': {
                    const d = prompt('Data del pagamento (AAAA-MM-GG)', UI.todayLocal());
                    if (!d) return;
                    await Store.api('set_pagata', 'passive', { id, data_pagamento: d });
                    UI.toast('Pagamento registrato');
                    load();
                    break;
                }
                case 'commessa': ModCommessa.open(id, load); break;
            }
        } catch (e) { UI.toast(e.message || 'Errore', 'error'); }
    }

    // ── Anagrafica partner ──────────────────────────────

    function openFornitore(f) {
        const t = ['partner', 'fornitore'].map(x => `<option value="${x}" ${x === (f.tipo || 'partner') ? 'selected' : ''}>${x === 'partner' ? 'Partner' : 'Fornitore'}</option>`).join('');
        UI.openModal(f.id ? 'Modifica partner' : 'Nuovo partner', `<div class="form-grid">
            <div class="form-group full-width"><label>Ragione sociale *</label><input class="form-control" id="fo-rs" value="${UI.esc(f.ragione_sociale || '')}"></div>
            <div class="form-group"><label>Tipo</label><select class="form-control" id="fo-tipo">${t}</select></div>
            <div class="form-group"><label>Pagamento standard (gg)</label><input type="number" class="form-control" id="fo-gg" value="${UI.esc(f.giorni_pagamento ?? 30)}"></div>
            <div class="form-group"><label>P.IVA</label><div style="display:flex;gap:4px"><input class="form-control" id="fo-piva" value="${UI.esc(f.partita_iva || '')}" inputmode="numeric">
                <button type="button" class="btn btn-secondary btn-sm" id="fo-cerca" title="Compila dai dati della partita IVA" aria-label="Compila dai dati della partita IVA"><i class="ph ph-magnifying-glass"></i></button></div></div>
            <div class="form-group"><label>Codice fiscale</label><input class="form-control" id="fo-cf" value="${UI.esc(f.codice_fiscale || '')}"></div>
            <div class="form-group full-width"><label>IBAN</label><input class="form-control" id="fo-iban" value="${UI.esc(f.iban || '')}"></div>
            <div class="form-group full-width" id="fo-cat-box" style="display:none"><label>Categoria dei pagamenti</label><select class="form-control" id="fo-cat"><option value="">— Fornitori e partner —</option></select></div>
            <div class="form-group"><label>Email</label><input class="form-control" id="fo-email" value="${UI.esc(f.email || '')}"></div>
            <div class="form-group"><label>Telefono</label><input class="form-control" id="fo-tel" value="${UI.esc(f.telefono || '')}"></div>
            <div class="form-group full-width"><label>Note</label><textarea class="form-control" id="fo-note">${UI.esc(f.note || '')}</textarea></div>
        </div>`, async () => {
            const v = id => document.getElementById(id).value;
            const dati = { id: f.id, ragione_sociale: v('fo-rs'), tipo: v('fo-tipo'), giorni_pagamento: v('fo-gg'),
                partita_iva: v('fo-piva'), codice_fiscale: v('fo-cf'), iban: v('fo-iban'), email: v('fo-email'), telefono: v('fo-tel'), note: v('fo-note') };
            // Categoria inviata solo se il campo è stato caricato (categorie attive)
            if (document.getElementById('fo-cat-box').style.display !== 'none') dati.categoria_default_id = v('fo-cat');
            await Store.api('save', 'fornitori', dati);
            UI.closeModal();
            UI.toast('Partner salvato');
            load();
        });
        // Compila ragione sociale, CF e PEC dalla partita IVA (stesso servizio dei clienti)
        document.getElementById('fo-cerca').addEventListener('click', async () => {
            const piva = document.getElementById('fo-piva').value.replace(/\D/g, '');
            try {
                const d = await Store.api('lookup-vat', 'clienti', { vat: piva }) || {};
                if (d.ragione_sociale) document.getElementById('fo-rs').value = d.ragione_sociale;
                if (d.codice_fiscale) document.getElementById('fo-cf').value = d.codice_fiscale;
                document.getElementById('fo-piva').value = piva;
                UI.toast('Dati compilati dalla partita IVA');
            } catch (e) { UI.toast(e.message, 'error'); }
        });
        // Categorie di uscita (grafici "Andamento"): il campo compare solo se il modulo categorie è attivo
        if (window.ModMovimenti) ModMovimenti.categorie('uscita').then(cats => {
            const sel = document.getElementById('fo-cat');
            if (!sel || !cats.length) return;
            cats.forEach(c => { const o = document.createElement('option'); o.value = c.id; o.textContent = c.nome; if (c.id == f.categoria_default_id) o.selected = true; sel.appendChild(o); });
            document.getElementById('fo-cat-box').style.display = '';
        }).catch(() => {});
    }

    // ── Fattura fornitore ───────────────────────────────

    async function openPassiva(f) {
        await fornitori();
        const fOpts = _fornitori.map(x => `<option value="${x.id}" ${x.id == f.fornitore_id ? 'selected' : ''}>${UI.esc(x.ragione_sociale)}</option>`).join('');
        UI.openModal(f.id ? `Fattura ${f.numero}` : 'Fattura fornitore', `<div class="form-grid">
            <div class="form-group"><label>Fornitore *</label><select class="form-control" id="fp-forn"><option value="">—</option>${fOpts}</select></div>
            <div class="form-group"><label>Costo di commessa</label><select class="form-control" id="fp-costo"><option value="">— da collegare —</option></select></div>
            <div class="form-group"><label>Numero *</label><input class="form-control" id="fp-num" value="${UI.esc(f.numero || '')}"></div>
            <div class="form-group"><label>Data</label><input type="date" class="form-control" id="fp-data" value="${UI.esc(f.data_emissione || UI.todayLocal())}"></div>
            <div class="form-group"><label>Imponibile</label><input type="number" step="0.01" class="form-control" id="fp-imp" value="${UI.esc(f.imponibile || 0)}"></div>
            <div class="form-group"><label>IVA</label><input type="number" step="0.01" class="form-control" id="fp-iva" value="${UI.esc(f.importo_iva || 0)}"></div>
            <div class="form-group"><label>Ritenuta d'acconto</label><input type="number" step="0.01" class="form-control" id="fp-rit" value="${UI.esc(f.ritenuta || 0)}"></div>
            <div class="form-group"><label>Scadenza</label><input type="date" class="form-control" id="fp-scad" value="${UI.esc(f.data_scadenza || '')}"></div>
            <div class="form-group"><label>Pagata il</label><input type="date" class="form-control" id="fp-pag" value="${UI.esc(f.data_pagamento || '')}"></div>
            <div class="form-group full-width"><label>Descrizione</label><textarea class="form-control" id="fp-desc">${UI.esc(f.descrizione || '')}</textarea></div>
        </div>`, async () => {
            const v = id => document.getElementById(id).value;
            await Store.api('save', 'passive', { id: f.id, fornitore_id: v('fp-forn'), costo_id: v('fp-costo'), numero: v('fp-num'),
                data_emissione: v('fp-data'), imponibile: v('fp-imp'), importo_iva: v('fp-iva'), ritenuta: v('fp-rit'),
                data_scadenza: v('fp-scad'), data_pagamento: v('fp-pag'), descrizione: v('fp-desc'), incarico_id: f.incarico_id || '' });
            UI.closeModal();
            UI.toast('Fattura salvata');
            load();
        });
        const sel = document.getElementById('fp-forn');
        const loadCosti = async (fid, selId) => {
            const cs = document.getElementById('fp-costo');
            cs.innerHTML = '<option value="">— da collegare —</option>';
            if (!fid) return;
            const costi = (await Store.api('costi_fornitore', 'fornitori', { fornitore_id: fid })) || [];
            costi.forEach(c => {
                const o = document.createElement('option');
                o.value = c.id;
                o.textContent = `${c.cliente_nome || 'Commessa #' + c.incarico_id} — ${c.descrizione} (${UI.formatCurrency(c.importo_previsto)})`;
                if (c.id == selId) o.selected = true;
                cs.appendChild(o);
            });
        };
        sel.addEventListener('change', () => loadCosti(sel.value));
        if (f.fornitore_id) loadCosti(f.fornitore_id, f.costo_id);
    }

    async function importXml(files) {
        const btn = document.getElementById('pa-import');
        const prev = btn.innerHTML;
        btn.disabled = true;
        const msgs = [];
        let tot = 0;
        try {
            for (let i = 0; i < files.length; i++) {
                btn.innerHTML = `<i class="ph ph-spinner ph-spin"></i> ${i + 1}/${files.length}…`;
                const f = files[i];
                const payload = {};
                if (/\.p7m$/i.test(f.name)) {
                    // File firmato: si manda in base64, il server estrae l'XML
                    const buf = new Uint8Array(await f.arrayBuffer());
                    let bin = '';
                    for (let j = 0; j < buf.length; j += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(j, j + 0x8000));
                    payload.file_b64 = btoa(bin);
                } else {
                    payload.xml = await f.text();
                }
                try {
                    const res = await Store.api('import_xml', 'passive', payload);
                    tot += res?.num_imported || 0;
                    msgs.push(...(res?.messages || []));
                } catch (e) {
                    msgs.push(`${f.name}: ${e.message}`);
                }
            }
            UI.openModal('Import fatture fornitori', `<p><b>${tot}</b> fatture importate</p>
                <div style="max-height:300px;overflow-y:auto;font-size:0.85rem;line-height:1.8">${msgs.map(m => `<div>${UI.esc(m)}</div>`).join('')}</div>`, null, { readOnly: true });
            load();
        } finally {
            btn.disabled = false;
            btn.innerHTML = prev;
        }
    }

    // ── Editor costi di commessa (offerta o incarico) ───

    /**
     * @param {HTMLElement} box
     * @param {Array} costi
     * @param {{offerta_id?:number, incarico_id?:number}} ctx
     * @param {Function} onChange ricarica il contenitore
     */
    async function renderCosti(box, costi, ctx, onChange) {
        await fornitori();
        const fOpts = sel => _fornitori.map(f => `<option value="${f.id}" ${f.id == sel ? 'selected' : ''}>${UI.esc(f.ragione_sociale)}</option>`).join('');
        const tot = costi.reduce((a, c) => a + num(c.importo_previsto), 0);
        box.innerHTML = `
            ${costi.length ? `<table class="rows-editor"><thead><tr><th>Partner</th><th>Cosa</th><th class="text-right">Previsto</th><th class="text-right">Fatturato</th><th>Pagamento</th><th></th></tr></thead><tbody>
            ${costi.map(c => `<tr>
                <td class="td-primary" style="color:var(--text-primary)">${UI.esc(c.fornitore_nome || '—')}</td>
                <td style="font-size:0.82rem">${UI.esc(c.descrizione)}${c.offerta_fornitore_numero ? `<div style="color:var(--text-muted);font-size:0.75rem">Offerta partner n. ${UI.esc(c.offerta_fornitore_numero)}${c.offerta_fornitore_data ? ' del ' + UI.formatDate(c.offerta_fornitore_data) : ''}</div>` : ''}</td>
                <td class="text-right">${UI.formatCurrency(c.importo_previsto)}</td>
                <td class="text-right">${c.fatturato !== undefined ? UI.formatCurrency(c.fatturato) : '—'}${num(c.pagato) ? `<div style="font-size:0.72rem;color:var(--accent-green)">pagato ${UI.formatCurrency(c.pagato)}</div>` : ''}</td>
                <td style="font-size:0.78rem">${c.condizione_pagamento === 'back_to_back' ? 'Quando incasso' : UI.esc(c.giorni_pagamento) + ' gg'}</td>
                <td style="white-space:nowrap">
                    ${c.offerta_fornitore_file ? `<button type="button" class="btn btn-sm btn-ghost" data-cdoc="${c.id}" title="Offerta del partner" aria-label="Offerta del partner"><i class="ph ph-file-pdf"></i></button>` : ''}
                    <button type="button" class="btn btn-sm btn-ghost" data-cedit="${c.id}" aria-label="Modifica"><i class="ph ph-pencil-simple"></i></button>
                    <button type="button" class="btn btn-sm btn-danger" data-cdel="${c.id}" aria-label="Elimina"><i class="ph ph-trash"></i></button>
                </td></tr>`).join('')}</tbody></table>
            <div class="rows-total"><span>Costi previsti <b>${UI.formatCurrency(tot)}</b></span></div>` : '<div class="scad-empty">Nessun partner su questa commessa.</div>'}
            <div class="inline-form" id="cst-form">
                <div><label>Partner</label><div style="display:flex;gap:4px"><select class="form-control" id="cst-forn"><option value="">—</option>${fOpts('')}</select>
                    <button type="button" class="btn btn-secondary btn-sm" id="cst-nuovo" title="Nuovo partner da partita IVA" aria-label="Nuovo partner da partita IVA"><i class="ph ph-plus"></i></button></div></div>
                <div><label>Cosa fa</label><input class="form-control" id="cst-desc" placeholder="es. docenza, audit tecnico"></div>
                <div><label>Importo €</label><input type="number" step="0.01" class="form-control" id="cst-imp"></div>
                <div><label>Pagamento</label><select class="form-control" id="cst-cond"><option value="scadenza">A scadenza</option><option value="back_to_back">Quando incasso</option></select></div>
                <div><label>Offerta partner (n. / file)</label><input class="form-control" id="cst-offnum" placeholder="n."><input type="file" class="form-control" id="cst-file" accept=".pdf,.docx,.jpg,.png" style="margin-top:4px"></div>
                <div><button type="button" class="btn btn-primary btn-sm" id="cst-save"><i class="ph ph-plus"></i> <span id="cst-save-lbl">Aggiungi</span></button></div>
            </div>
            <div class="inline-form" id="np-form" style="display:none">
                <div><label>P.IVA nuovo partner</label><input class="form-control" id="np-piva" inputmode="numeric" placeholder="11 cifre"></div>
                <div><button type="button" class="btn btn-secondary btn-sm" id="np-cerca"><i class="ph ph-magnifying-glass"></i> Cerca</button></div>
                <div><label>Ragione sociale</label><input class="form-control" id="np-rs"></div>
                <div><button type="button" class="btn btn-primary btn-sm" id="np-crea"><i class="ph ph-check"></i> Crea partner</button></div>
                <div id="np-stato" style="font-size:0.8rem;color:var(--text-muted)"></div>
            </div>
            <input type="hidden" id="cst-id">
            ${_fornitori.length ? '' : '<div class="scad-empty">Nessun partner in anagrafica: crealo con il pulsante + accanto a Partner.</div>'}`;

        // Nuovo partner da P.IVA senza lasciare l'offerta (la modale è una sola: aprirne un'altra perderebbe il form)
        let trovato = {};
        const np = id => document.getElementById(id);
        const stato = (t, err) => { np('np-stato').textContent = t; np('np-stato').style.color = err ? 'var(--danger)' : 'var(--text-muted)'; };
        const scegli = id => { np('cst-forn').innerHTML = `<option value="">—</option>${fOpts(id)}`; np('np-form').style.display = 'none'; };
        np('cst-nuovo').addEventListener('click', () => {
            const f = np('np-form'); f.style.display = f.style.display === 'none' ? '' : 'none';
            if (f.style.display === '') np('np-piva').focus();
        });
        np('np-cerca').addEventListener('click', async () => {
            const piva = np('np-piva').value.replace(/\D/g, '');
            if (piva.length !== 11) { stato('La partita IVA ha 11 cifre', true); return; }
            const gia = _fornitori.find(f => String(f.partita_iva || '').replace(/\D/g, '') === piva);
            if (gia) { scegli(gia.id); UI.toast(`${gia.ragione_sociale} è già tra i partner: selezionato`); return; }
            stato('Ricerca in corso...');
            try {
                trovato = await Store.api('lookup-vat', 'clienti', { vat: piva }) || {};
                np('np-rs').value = trovato.ragione_sociale || '';
                stato('Dati trovati: controlla e crea il partner.');
            } catch (e) { trovato = {}; stato(e.message + ' — scrivi la ragione sociale a mano.', true); np('np-rs').focus(); }
        });
        np('np-crea').addEventListener('click', async () => {
            const piva = np('np-piva').value.replace(/\D/g, ''), rs = np('np-rs').value.trim();
            if (!rs) { stato('Serve la ragione sociale', true); return; }
            try {
                const r = await Store.api('save', 'fornitori', { ragione_sociale: rs, tipo: 'partner', partita_iva: piva,
                    codice_fiscale: trovato.codice_fiscale || '', pec: trovato.pec || '', giorni_pagamento: 30 });
                await fornitori(true);
                scegli(r?.id);
                UI.toast('Partner creato');
            } catch (e) { stato(e.message, true); }
        });

        box.querySelectorAll('[data-cdoc]').forEach(b => b.addEventListener('click', () =>
            window.open(`api/router.php?module=fornitori&action=documento_costo&id=${encodeURIComponent(b.dataset.cdoc)}`, '_blank', 'noopener')));
        box.querySelectorAll('[data-cdel]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Eliminare il costo?')) return;
            try { await Store.api('delete_costo', 'fornitori', { id: b.dataset.cdel }); onChange(); } catch (e) { UI.toast(e.message, 'error'); }
        }));
        box.querySelectorAll('[data-cedit]').forEach(b => b.addEventListener('click', () => {
            const c = costi.find(x => x.id == b.dataset.cedit);
            document.getElementById('cst-id').value = c.id;
            document.getElementById('cst-forn').value = c.fornitore_id || '';
            document.getElementById('cst-desc').value = c.descrizione;
            document.getElementById('cst-imp').value = c.importo_previsto;
            document.getElementById('cst-cond').value = c.condizione_pagamento;
            document.getElementById('cst-offnum').value = c.offerta_fornitore_numero || '';
            document.getElementById('cst-save-lbl').textContent = 'Salva';
            document.getElementById('cst-desc').focus();
        }));
        document.getElementById('cst-save').addEventListener('click', async () => {
            const v = id => document.getElementById(id).value;
            const fd = new FormData();
            const id = v('cst-id');
            const orig = id ? costi.find(x => x.id == id) : null;
            const data = { id, fornitore_id: v('cst-forn'), descrizione: v('cst-desc'), importo_previsto: v('cst-imp'),
                condizione_pagamento: v('cst-cond'), offerta_fornitore_numero: v('cst-offnum'),
                offerta_id: ctx.offerta_id || orig?.offerta_id || '', incarico_id: ctx.incarico_id || orig?.incarico_id || '',
                giorni_pagamento: orig?.giorni_pagamento ?? (_fornitori.find(f => f.id == v('cst-forn'))?.giorni_pagamento ?? 30),
                offerta_fornitore_data: orig?.offerta_fornitore_data || '' };
            Object.entries(data).forEach(([k, val]) => fd.append(k, val ?? ''));
            const file = document.getElementById('cst-file').files[0];
            if (file) fd.append('file', file);
            if (!data.descrizione.trim()) { UI.toast('Descrivi il costo', 'error'); return; }
            try {
                await Store.upload('save_costo', 'fornitori', fd);
                UI.toast('Costo salvato');
                onChange();
            } catch (e) { UI.toast(e.message, 'error'); }
        });
    }

    return { load, renderCosti, fornitori };
})();
window.ModPartner = ModPartner;
