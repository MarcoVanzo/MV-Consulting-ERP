'use strict';
/**
 * Modulo Offerte — preventivi ai clienti: righe, piano di pagamento, costi dei partner,
 * invio con data di ricontatto, accettazione (nasce l'incarico), revisioni, import da Cowork.
 */
const ModOfferte = (() => {
    let _offerte = [], _kpis = {}, _filtro = '';
    // Stato del form aperto
    let _righe = [], _piano = [], _cur = null;

    const STATI = {
        bozza:      ['badge-blue', 'Bozza'],
        inviata:    ['badge-yellow', 'Inviata'],
        accettata:  ['badge-green', 'Accettata'],
        rifiutata:  ['badge-red', 'Rifiutata'],
        scaduta:    ['badge-red', 'Scaduta'],
        sostituita: ['badge-purple', 'Sostituita'],
    };
    const PRESET = {
        '100% all\'accettazione': [['Saldo', 100, 0]],
        '30% + 70% a 60 gg': [['Acconto', 30, 0], ['Saldo', 70, 60]],
        '50% + 50% a 90 gg': [['Acconto', 50, 0], ['Saldo', 50, 90]],
        'Trimestrale (4 rate)': [['I trimestre', 25, 0], ['II trimestre', 25, 90], ['III trimestre', 25, 180], ['IV trimestre', 25, 270]],
    };

    const badge = s => { const [c, l] = STATI[s] || ['badge-blue', s]; return `<span class="badge ${c}">${UI.esc(l)}</span>`; };
    const num = v => parseFloat(String(v).replace(',', '.')) || 0;

    async function load() {
        const box = document.getElementById('comm-offerte');
        try {
            const res = await Store.api('list', 'offerte', { year: ModCommerciale.year(), stato: _filtro });
            _offerte = res.offerte || [];
            _kpis = res.kpis || {};
            render(box);
        } catch (e) {
            box.innerHTML = `<div class="empty-state"><h3>Errore</h3><p>${UI.esc(e.message)}</p></div>`;
        }
    }

    function render(box) {
        const k = _kpis;
        const chips = [['', 'Aperte e chiuse'], ['bozza', 'Bozze'], ['inviata', 'Inviate'], ['accettata', 'Accettate'], ['rifiutata', 'Rifiutate'], ['sostituita', 'Versioni superate']]
            .map(([v, l]) => `<span class="filter-chip ${_filtro === v ? 'active' : ''}" data-f="${v}">${l}</span>`).join('');
        box.innerHTML = `
            <div class="comm-toolbar">
                <button class="btn btn-ghost" data-importa><i class="ph ph-upload-simple"></i> Importa offerta</button>
                <button class="btn btn-primary" id="off-new"><i class="ph ph-plus"></i> Nuova offerta</button>
            </div>
            <div class="kpi-grid">
                <div class="kpi-card kpi-blue"><div class="kpi-label">In trattativa</div><div class="kpi-value">${UI.formatCurrency(k.pipeline)}</div><div class="kpi-sub">${UI.plurale(k.num_inviate, 'offerta inviata', 'offerte inviate')}${k.num_bozze ? ` · ${k.num_bozze} in bozza` : ''}</div></div>
                <div class="kpi-card kpi-green"><div class="kpi-label">Accettato</div><div class="kpi-value">${UI.formatCurrency(k.accettato)}</div><div class="kpi-sub">${UI.plurale(k.num_accettate, 'offerta', 'offerte')}</div></div>
                <div class="kpi-card kpi-yellow"><div class="kpi-label">Conversione</div><div class="kpi-value">${k.tasso_conversione !== null && k.tasso_conversione !== undefined ? k.tasso_conversione + '%' : '—'}</div><div class="kpi-sub">accettate su chiuse</div></div>
                <div class="kpi-card kpi-red"><div class="kpi-label">Perse</div><div class="kpi-value">${k.num_perse || 0}</div><div class="kpi-sub">rifiutate o scadute</div></div>
            </div>
            <div class="table-container">
                <div class="table-toolbar"><div class="filters-row" style="margin-bottom:0">${chips}</div></div>
                <table class="data-table"><thead><tr>
                    <th>Numero</th><th>Cliente</th><th>Oggetto</th><th>Data</th>
                    <th class="text-right">Imponibile</th><th class="text-right">Margine prev.</th><th>Stato</th><th></th>
                </tr></thead><tbody>${rows()}</tbody></table>
            </div>`;

        box.querySelectorAll('.filter-chip').forEach(c => c.addEventListener('click', () => { _filtro = c.dataset.f; load(); }));
        document.getElementById('off-new').addEventListener('click', openNew);
        box.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', () => azione(b.dataset.act, parseInt(b.dataset.id, 10))));
    }

    function rows() {
        if (!_offerte.length) return `<tr><td colspan="8"><div class="empty-state"><i class="ph ph-file-text"></i><h3>Nessuna offerta</h3><p>Creane una o importa un preventivo fatto con Cowork</p></div></td></tr>`;
        return _offerte.map(o => {
            const imp = num(o.imponibile), costi = num(o.costi_previsti);
            const marg = imp - costi;
            const pct = imp > 0 ? Math.round(marg / imp * 100) : null;
            const b = (act, icon, title, cls = 'btn-ghost') => `<button class="btn btn-sm ${cls}" data-act="${act}" data-id="${o.id}" title="${title}" aria-label="${title}"><i class="ph ${icon}"></i></button>`;
            const azioni = [
                b('edit', 'ph-pencil-simple', 'Apri'),
                o.stato === 'bozza' ? b('invia', 'ph-paper-plane-tilt', 'Segna come inviata') : '',
                ['bozza', 'inviata'].includes(o.stato) ? b('accetta', 'ph-check-circle', 'Accettata: crea incarico') : '',
                o.stato === 'inviata' ? b('rifiuta', 'ph-x-circle', 'Rifiutata') : '',
                ['bozza', 'inviata', 'rifiutata', 'scaduta'].includes(o.stato) ? b('versione', 'ph-copy', 'Nuova versione') : '',
                o.incarico_id ? b('commessa', 'ph-folder-open', 'Scheda commessa') : '',
                o.file_path ? b('doc', 'ph-file-pdf', 'Documento') : '',
                o.stato !== 'accettata' ? b('elimina', 'ph-trash', 'Elimina', 'btn-danger') : '',
            ].join('');
            return `<tr>
                <td class="td-mono">${UI.esc(o.numero)}${o.versione > 1 ? ' v' + UI.esc(o.versione) : ''}${o.origine === 'cowork' ? ' <i class="ph ph-magic-wand" title="Importata da Cowork"></i>' : ''}</td>
                <td class="td-primary">${UI.esc(o.cliente_nome_vis || '—')}${o.sottocliente_nome ? ` <span style="color:var(--text-muted)">/ ${UI.esc(o.sottocliente_nome)}</span>` : ''}</td>
                <td>${UI.esc(o.oggetto)}${o.da_ricontattare == 1 ? ' <span class="badge badge-red">Ricontattare</span>' : ''}</td>
                <td>${UI.formatDate(o.data_offerta)}</td>
                <td class="text-right td-primary">${UI.formatCurrency(o.imponibile)}</td>
                <td class="text-right">${costi > 0 ? UI.formatCurrency(marg) + ` <span style="color:var(--text-muted)">${pct}%</span>` : '—'}</td>
                <td>${badge(o.stato)}</td>
                <td><div class="flex" style="gap:4px;flex-wrap:wrap">${azioni}</div></td></tr>`;
        }).join('');
    }

    // ── Form ────────────────────────────────────────────

    function openNew() {
        _cur = { stato: 'bozza', data_offerta: UI.todayLocal(), iva_percentuale: 22, giorni_pagamento: 30, tipo_commessa: 'assistenza', costi: [] };
        _righe = [{ descrizione: '', quantita: 1, unita: 'giornate', prezzo_unitario: 0 }];
        _piano = PRESET['100% all\'accettazione'].map(([d, p, g]) => ({ descrizione: d, percentuale: p, giorni_da_accettazione: g }));
        openForm('Nuova offerta');
    }

    async function edit(id) {
        try {
            _cur = await Store.api('get', 'offerte', { id });
            _righe = (_cur.righe || []).map(r => ({ ...r }));
            _piano = (_cur.piano_rate || []).map(r => ({ ...r }));
            openForm(`Offerta ${_cur.numero}${_cur.versione > 1 ? ' v' + _cur.versione : ''}`);
        } catch (e) { UI.toast(e.message, 'error'); }
    }

    function openForm(title) {
        const d = _cur;
        const bloccata = ['accettata', 'sostituita'].includes(d.stato);
        const clienti = ModClienti.getClienti();
        const cOpts = clienti.map(c => `<option value="${UI.esc(c.id)}" ${c.id == d.cliente_id ? 'selected' : ''}>${UI.esc(c.ragione_sociale)}</option>`).join('');
        const tOpts = UI.tipiCommessaOptions(d.tipo_commessa);
        const html = `
            ${d.note && d.origine === 'cowork' && d.stato === 'bozza' ? `<div class="notice">${UI.esc(d.note)}</div>` : ''}
            ${bloccata ? `<div class="notice">Offerta ${UI.esc(d.stato)}: per cambiarla crea una nuova versione.</div>` : ''}
            <div class="form-grid">
                <div class="form-group"><label>Cliente *</label><select class="form-control" id="of-cliente"><option value="">— Prospect non in anagrafica —</option>${cOpts}</select></div>
                <div class="form-group" id="of-prospect-wrap"><label>Nome prospect</label><input class="form-control" id="of-cliente-nome" value="${UI.esc(d.cliente_nome || '')}"></div>
                <div class="form-group"><label>Sottocliente</label><select class="form-control" id="of-sotto"><option value="">— Nessuno —</option></select></div>
                <div class="form-group"><label>Tipo</label><select class="form-control" id="of-tipo">${tOpts}</select></div>
                <div class="form-group full-width"><label>Oggetto *</label><input class="form-control" id="of-oggetto" value="${UI.esc(d.oggetto || '')}" placeholder="es. Adeguamento GDPR e formazione del personale"></div>
                <div class="form-group"><label>Data offerta</label><input type="date" class="form-control" id="of-data" value="${UI.esc(d.data_offerta || '')}"></div>
                <div class="form-group"><label>Valida fino al</label><input type="date" class="form-control" id="of-scadenza" value="${UI.esc(d.data_scadenza || '')}"></div>
                <div class="form-group"><label>Giornate previste</label><input type="number" step="0.5" class="form-control" id="of-gg" value="${UI.esc(d.num_giornate || 0)}"></div>
                <div class="form-group"><label>Ricontattare il</label><input type="date" class="form-control" id="of-followup" value="${UI.esc(d.data_followup || '')}"></div>
                <div class="form-group full-width"><label>Descrizione</label><textarea class="form-control" id="of-desc">${UI.esc(d.descrizione || '')}</textarea></div>
            </div>

            <div class="section-title"><i class="ph ph-list-numbers"></i> Voci dell'offerta</div>
            <div id="of-righe"></div>

            <div class="section-title"><i class="ph ph-calendar-dots"></i> Come ti paga il cliente</div>
            <div class="form-grid">
                <div class="form-group"><label>Pagamento a (giorni data fattura)</label><input type="number" class="form-control" id="of-giorni" value="${UI.esc(d.giorni_pagamento ?? 30)}"></div>
                <div class="form-group"><label>IVA %</label><input type="number" step="0.01" class="form-control" id="of-iva" value="${UI.esc(d.iva_percentuale ?? 22)}"></div>
                <div class="form-group full-width"><label>Condizioni di pagamento (testo)</label><input class="form-control" id="of-condizioni" value="${UI.esc(d.condizioni_pagamento || '')}" placeholder="es. 30% all'ordine, saldo a fine lavori, bonifico 30 gg d.f."></div>
            </div>
            <div class="preset-row">${Object.keys(PRESET).map(k => `<button type="button" class="chip-btn" data-preset="${UI.esc(k)}">${UI.esc(k)}</button>`).join('')}</div>
            <div id="of-piano"></div>

            <div class="section-title"><i class="ph ph-handshake"></i> Partner coinvolti (costi previsti)</div>
            <div id="of-costi">${d.id ? '' : '<div class="scad-empty">Salva l\'offerta per aggiungere i partner e le loro offerte.</div>'}</div>

            <div class="form-grid" style="margin-top:18px">
                <div class="form-group full-width"><label>Documento dell'offerta (PDF/Word)${d.ha_documento ? ' — già caricato, <a href="#" id="of-doc">apri</a>' : ''}</label><input type="file" class="form-control" id="of-file" accept=".pdf,.docx,.doc"></div>
                <div class="form-group full-width"><label>Note</label><textarea class="form-control" id="of-note">${UI.esc(d.note || '')}</textarea></div>
            </div>
            <input type="hidden" id="of-id" value="${UI.esc(d.id || '')}">`;

        UI.openModal(title, html, bloccata ? null : save, { wide: true, readOnly: bloccata });

        const sel = document.getElementById('of-cliente');
        const toggleProspect = () => document.getElementById('of-prospect-wrap').classList.toggle('hidden', !!sel.value);
        sel.addEventListener('change', () => { toggleProspect(); loadSotto(sel.value); });
        toggleProspect();
        if (d.cliente_id) loadSotto(d.cliente_id, d.sottocliente_id);
        document.querySelectorAll('[data-preset]').forEach(b => b.addEventListener('click', () => {
            _piano = PRESET[b.dataset.preset].map(([de, p, g]) => ({ descrizione: de, percentuale: p, giorni_da_accettazione: g }));
            renderPiano();
        }));
        const docLink = document.getElementById('of-doc');
        if (docLink) docLink.addEventListener('click', e => { e.preventDefault(); apriDocumento(d.id); });
        renderRighe();
        renderPiano();
        if (d.id) {
            // Aggiornare i costi non deve far perdere le modifiche non salvate del form
            const box = document.getElementById('of-costi');
            const refresh = async () => {
                const o = await Store.api('get', 'offerte', { id: d.id });
                ModPartner.renderCosti(box, o.costi || [], { offerta_id: d.id }, refresh);
                load();
            };
            ModPartner.renderCosti(box, d.costi || [], { offerta_id: d.id }, refresh);
        }
    }

    async function loadSotto(cid, selId) {
        const sel = document.getElementById('of-sotto');
        sel.innerHTML = '<option value="">— Nessuno —</option>';
        if (!cid) return;
        try {
            const subs = await Store.api('list', 'sottoclienti', { cliente_id: cid });
            (subs || []).forEach(s => { const o = document.createElement('option'); o.value = s.id; o.textContent = s.nome; if (s.id == selId) o.selected = true; sel.appendChild(o); });
        } catch (e) { /* elenco opzionale */ }
    }

    function renderRighe() {
        const box = document.getElementById('of-righe');
        const tot = _righe.reduce((a, r) => a + num(r.quantita) * num(r.prezzo_unitario), 0);
        box.innerHTML = `<div class="table-container" style="border:none;background:none"><table class="rows-editor"><thead><tr>
                <th>Descrizione</th><th>Q.tà</th><th>Unità</th><th>Prezzo unit.</th><th class="text-right">Importo</th><th></th></tr></thead><tbody>
            ${_righe.map((r, i) => `<tr>
                <td><input class="form-control" data-r="${i}" data-k="descrizione" value="${UI.esc(r.descrizione)}"></td>
                <td><input class="form-control num-s" type="number" step="0.5" data-r="${i}" data-k="quantita" value="${UI.esc(r.quantita)}"></td>
                <td><input class="form-control num" data-r="${i}" data-k="unita" value="${UI.esc(r.unita || '')}"></td>
                <td><input class="form-control num" type="number" step="0.01" data-r="${i}" data-k="prezzo_unitario" value="${UI.esc(r.prezzo_unitario)}"></td>
                <td class="text-right" style="white-space:nowrap" data-imp="${i}">${UI.formatCurrency(num(r.quantita) * num(r.prezzo_unitario))}</td>
                <td><button type="button" class="btn btn-sm btn-ghost" data-del="${i}" aria-label="Elimina voce"><i class="ph ph-x"></i></button></td></tr>`).join('')}
            </tbody></table></div>
            <div class="rows-total"><button type="button" class="chip-btn" id="of-add-riga"><i class="ph ph-plus"></i> Voce</button><span>Imponibile <b id="of-tot">${UI.formatCurrency(tot)}</b></span></div>`;
        // Sul change si aggiornano solo importi e totali: ridisegnare la tabella farebbe perdere il fuoco (Tab)
        box.querySelectorAll('input[data-r]').forEach(inp => inp.addEventListener('change', () => {
            const r = _righe[inp.dataset.r];
            r[inp.dataset.k] = inp.value;
            if (inp.dataset.k === 'descrizione' || inp.dataset.k === 'unita') return;
            delete r.importo;
            const cell = box.querySelector(`[data-imp="${inp.dataset.r}"]`);
            if (cell) cell.textContent = UI.formatCurrency(num(r.quantita) * num(r.prezzo_unitario));
            document.getElementById('of-tot').textContent = UI.formatCurrency(totaleRighe());
            aggiornaImportiPiano();
        }));
        box.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', () => { _righe.splice(b.dataset.del, 1); renderRighe(); renderPiano(); }));
        document.getElementById('of-add-riga').addEventListener('click', () => {
            _righe.push({ descrizione: '', quantita: 1, unita: '', prezzo_unitario: 0 });
            renderRighe();
            box.querySelector(`input[data-r="${_righe.length - 1}"][data-k="descrizione"]`)?.focus();
        });
    }

    const totaleRighe = () => _righe.reduce((a, r) => a + num(r.quantita) * num(r.prezzo_unitario), 0);

    // Aggiorna importi delle rate e totale % senza ridisegnare il piano
    function aggiornaImportiPiano() {
        const box = document.getElementById('of-piano');
        if (!box) return;
        const tot = totaleRighe();
        _piano.forEach((r, i) => {
            const cell = box.querySelector(`[data-pimp="${i}"]`);
            if (cell) cell.textContent = UI.formatCurrency(tot * num(r.percentuale) / 100);
        });
        const somma = _piano.reduce((a, r) => a + num(r.percentuale), 0);
        const b = document.getElementById('of-piano-tot');
        if (b) {
            b.textContent = UI.formatNumber(somma, 0) + '%';
            b.className = Math.abs(somma - 100) > 0.01 ? 'text-danger' : 'text-ok';
        }
        const fasi = document.getElementById('of-piano-fasi');
        if (fasi) fasi.hidden = !(Math.abs(somma - 100) > 0.01 && pianoPerFaseSulTotale());
    }

    /**
     * Piano scritto per fase (es. "Fase 1 — 40% …" 40/40/20, "Fase 2 — …" 40/30/30): ogni fase somma a 100.
     * Riporta le percentuali sul totale usando l'importo della riga corrispondente
     * (per nome, oppure per ordine se fasi e righe sono tante quante). Null se non si può.
     */
    function pianoPerFaseSulTotale() {
        const tot = totaleRighe();
        if (tot <= 0) return null;
        const fasi = [];
        _piano.forEach((r, i) => {
            const nome = String(r.descrizione || '').split(/\s+[—–-]\s+/)[0].trim().toLowerCase();
            let f = fasi.find(x => x.nome === nome);
            if (!f) fasi.push(f = { nome, idx: [], somma: 0 });
            f.idx.push(i);
            f.somma += num(r.percentuale);
        });
        if (fasi.length < 2 || fasi.some(f => Math.abs(f.somma - 100) > 0.01)) return null;
        const righe = _righe.map(r => ({ nome: String(r.descrizione || '').toLowerCase(), imp: num(r.quantita) * num(r.prezzo_unitario) })).filter(r => r.imp > 0);
        const perNome = fasi.map(f => righe.find(r => f.nome && (r.nome.startsWith(f.nome) || r.nome.includes(f.nome))));
        const abbinate = perNome.every(Boolean) && new Set(perNome).size === fasi.length ? perNome
            : (righe.length === fasi.length ? righe : null);
        if (!abbinate) return null;
        const nuovo = _piano.map(r => ({ ...r }));
        fasi.forEach((f, k) => f.idx.forEach(i => { nuovo[i].percentuale = Math.round(num(_piano[i].percentuale) * abbinate[k].imp / tot * 100) / 100; }));
        const scarto = Math.round((100 - nuovo.reduce((a, r) => a + num(r.percentuale), 0)) * 100) / 100;
        if (Math.abs(scarto) > 0.5) return null; // le righe non coprono le fasi: meglio non indovinare
        nuovo[nuovo.length - 1].percentuale = Math.round((num(nuovo[nuovo.length - 1].percentuale) + scarto) * 100) / 100;
        return nuovo;
    }

    function renderPiano() {
        const box = document.getElementById('of-piano');
        const tot = _righe.reduce((a, r) => a + num(r.quantita) * num(r.prezzo_unitario), 0);
        const somma = _piano.reduce((a, r) => a + num(r.percentuale), 0);
        box.innerHTML = `<table class="rows-editor"><thead><tr><th>Rata</th><th>%</th><th>Fatturare dopo (gg dall'accettazione)</th><th class="text-right">Importo</th><th></th></tr></thead><tbody>
            ${_piano.map((r, i) => `<tr>
                <td><input class="form-control" data-p="${i}" data-k="descrizione" value="${UI.esc(r.descrizione)}"></td>
                <td><input class="form-control num-s" type="number" step="0.01" data-p="${i}" data-k="percentuale" value="${UI.esc(r.percentuale)}"></td>
                <td><input class="form-control num" type="number" data-p="${i}" data-k="giorni_da_accettazione" value="${UI.esc(r.giorni_da_accettazione ?? '')}" placeholder="da decidere"></td>
                <td class="text-right" data-pimp="${i}">${UI.formatCurrency(tot * num(r.percentuale) / 100)}</td>
                <td><button type="button" class="btn btn-sm btn-ghost" data-pdel="${i}" aria-label="Elimina rata"><i class="ph ph-x"></i></button></td></tr>`).join('')}
            </tbody></table>
            <div class="rows-total"><button type="button" class="chip-btn" id="of-add-rata"><i class="ph ph-plus"></i> Rata</button>
            <button type="button" class="chip-btn" id="of-piano-fasi" title="Le percentuali sono per fase: riportale sul totale dell'offerta"${Math.abs(somma - 100) > 0.01 && pianoPerFaseSulTotale() ? '' : ' hidden'}><i class="ph ph-arrows-in"></i> Riporta al 100%</button>
            <span>Totale rate <b id="of-piano-tot" class="${Math.abs(somma - 100) > 0.01 ? 'text-danger' : 'text-ok'}">${UI.formatNumber(somma, 0)}%</b></span></div>`;
        document.getElementById('of-piano-fasi')?.addEventListener('click', () => {
            const nuovo = pianoPerFaseSulTotale();
            if (nuovo) { _piano = nuovo; renderPiano(); }
        });
        box.querySelectorAll('input[data-p]').forEach(inp => inp.addEventListener('change', () => {
            _piano[inp.dataset.p][inp.dataset.k] = inp.value;
            if (inp.dataset.k === 'percentuale') aggiornaImportiPiano();
        }));
        box.querySelectorAll('[data-pdel]').forEach(b => b.addEventListener('click', () => { _piano.splice(b.dataset.pdel, 1); renderPiano(); }));
        document.getElementById('of-add-rata').addEventListener('click', () => {
            _piano.push({ descrizione: 'Rata', percentuale: 0, giorni_da_accettazione: '' });
            renderPiano();
            box.querySelector(`input[data-p="${_piano.length - 1}"][data-k="descrizione"]`)?.focus();
        });
    }

    async function save() {
        const v = id => document.getElementById(id).value;
        const fd = new FormData();
        const fields = {
            id: v('of-id'), cliente_id: v('of-cliente'), cliente_nome: v('of-cliente-nome'), sottocliente_id: v('of-sotto'),
            tipo_commessa: v('of-tipo'), oggetto: v('of-oggetto'), data_offerta: v('of-data'), data_scadenza: v('of-scadenza'),
            num_giornate: v('of-gg'), data_followup: v('of-followup'), descrizione: v('of-desc'), giorni_pagamento: v('of-giorni'),
            iva_percentuale: v('of-iva'), condizioni_pagamento: v('of-condizioni'), note: v('of-note'),
            righe: JSON.stringify(_righe), piano_rate: JSON.stringify(_piano),
        };
        Object.entries(fields).forEach(([k, val]) => fd.append(k, val ?? ''));
        const file = document.getElementById('of-file').files[0];
        if (file) fd.append('file', file);
        if (!fields.oggetto.trim()) { UI.toast('Scrivi l\'oggetto', 'error'); return; }
        const res = await Store.upload('save', 'offerte', fd);
        UI.toast(fields.id ? 'Offerta aggiornata' : 'Offerta creata');
        if (!fields.cliente_id) await ModClienti.load(); // il prospect è entrato in anagrafica
        load();
        if (!fields.id && res?.id) edit(res.id); // riapre per aggiungere i partner
        else UI.closeModal();
    }

    // ── Azioni ──────────────────────────────────────────

    function apriDocumento(id) {
        window.open(`api/router.php?module=offerte&action=documento&id=${encodeURIComponent(id)}`, '_blank', 'noopener');
    }

    function openAccetta(o) {
        UI.openModal(`Accettata: ${o.numero}`, `
            <p style="color:var(--text-secondary);font-size:0.9rem;margin-top:0">Nasce l'incarico con le rate dell'offerta; i costi dei partner passano alla commessa.</p>
            <div class="form-grid">
                <div class="form-group"><label>Data accettazione</label><input type="date" class="form-control" id="acc-data" value="${UI.todayLocal()}"></div>
                <div class="form-group"><label>N. protocollo / ordine del cliente</label><input class="form-control" id="acc-prot" placeholder="se il cliente ne ha dato uno"></div>
            </div>`, async () => {
            const res = await Store.api('accetta', 'offerte', { id: o.id, data_accettazione: document.getElementById('acc-data').value, numero_protocollo: document.getElementById('acc-prot').value });
            UI.toast('Incarico creato');
            load();
            if (res?.incarico_id) ModCommessa.open(res.incarico_id, load);
            else UI.closeModal();
        });
    }

    async function azione(act, id) {
        const o = _offerte.find(x => x.id == id);
        try {
            switch (act) {
                case 'edit': edit(id); break;
                case 'invia': {
                    await Store.api('set_stato', 'offerte', { id, stato: 'inviata' });
                    UI.toast('Inviata: ti ricordo di ricontattare il cliente tra 7 giorni');
                    load();
                    break;
                }
                case 'accetta': if (o) openAccetta(o); break;
                case 'rifiuta': {
                    const motivo = await UI.chiedi({ titolo: 'Offerta rifiutata', etichetta: 'Motivo (facoltativo): prezzo, tempi, concorrente…', tipo: 'text', conferma: 'Segna rifiutata' });
                    if (motivo === null) return;
                    await Store.api('set_stato', 'offerte', { id, stato: 'rifiutata', motivo_esito: motivo });
                    load();
                    break;
                }
                case 'versione': {
                    if (!confirm('Creare una nuova versione? Quella attuale diventa "sostituita".')) return;
                    const res = await Store.api('nuova_versione', 'offerte', { id });
                    load();
                    if (res?.id) edit(res.id);
                    break;
                }
                case 'commessa': if (o?.incarico_id) ModCommessa.open(o.incarico_id, load); break;
                case 'doc': apriDocumento(id); break;
                case 'elimina': {
                    if (!confirm('Eliminare l\'offerta?')) return;
                    await Store.api('delete', 'offerte', { id });
                    load();
                    break;
                }
            }
        } catch (e) { UI.toast(e.message || 'Errore', 'error'); }
    }

    return { load, edit, openNew };
})();
window.ModOfferte = ModOfferte;
