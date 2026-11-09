'use strict';
/**
 * Fatture emesse (vista Fatture): KPI dagli Indicatori, grafico mensile, elenco con filtri.
 * Le fatture non pagate si vedono col filtro «Scadute»/«Da incassare»: non c'è più una scheda a parte.
 */
const ModContabilita = (() => {
    let _fatture = [], _aperte = [], _tutte = null, _top = [], _kpis = {}, _mensile = [], _clienti = [], _esclusi = [], _statoFilter = '';

    async function load() {
        const year = UI.anno();
        try {
            const ov = await Store.api('overview','contabilita',{year});
            _kpis = ov?.kpis||{}; _tutte = null; _mensile = ov?.mensile||[]; _clienti = ov?.clienti||[]; _esclusi = (ov?.esclusi||[]).map(Number);
            renderKpis(); renderChart(); renderFiltroClienti(); renderGrafici(ov);
            const params = {year}; if (_statoFilter) params.stato = _statoFilter;
            const list = await Store.api('list','contabilita',params);
            _fatture = list||[]; renderTable();
        } catch(e) { console.error('[Contabilita]',e); UI.toast('Errore caricamento contabilità','error'); }
    }

    function renderKpis() {
        document.getElementById('contabilita-kpis').innerHTML = `
            <div class="kpi-card kpi-blue" data-chiave="fatturato" role="button" tabindex="0" title="Vedi le fatture"><div class="kpi-label">Fatturato</div><div class="kpi-value">${UI.formatCurrency(_kpis.totale)}</div><div class="kpi-sub">IVA inclusa · ${UI.plurale(_kpis.num_documenti,'fattura','fatture')} · imponibile ${UI.formatCurrency(_kpis.fatturato)}</div></div>
            <div class="kpi-card kpi-green" data-chiave="incassato" role="button" tabindex="0" title="Vedi le fatture"><div class="kpi-label">Incassato</div><div class="kpi-value">${UI.formatCurrency(_kpis.incassato)}</div><div class="kpi-sub">IVA inclusa · ${UI.plurale(_kpis.num_incassati,'fattura saldata','fatture saldate')}</div></div>
            <div class="kpi-card kpi-yellow" data-chiave="da_incassare" role="button" tabindex="0" title="Vedi le fatture"><div class="kpi-label">Da incassare</div><div class="kpi-value">${UI.formatCurrency(_kpis.da_incassare)}</div><div class="kpi-sub">IVA inclusa · ${UI.plurale(_kpis.num_da_incassare,'fattura','fatture')}</div></div>
            <div class="kpi-card kpi-red" data-chiave="scaduto" role="button" tabindex="0" title="Vedi le fatture"><div class="kpi-label">Scaduto</div><div class="kpi-value">${UI.formatCurrency(_kpis.scaduto)}</div><div class="kpi-sub">${UI.plurale(_kpis.num_scaduti,'fattura','fatture')} oltre la scadenza</div></div>`;
        Grafici.alClic(document.getElementById('contabilita-kpis'), apriKpi);
    }

    // ── Clienti nel conteggio (KPI e grafico): la scelta è salvata sul server ──
    function renderFiltroClienti() {
        const btn = document.getElementById('btn-filtro-clienti'); if (!btn) return;
        const fuori = _clienti.filter(c => _esclusi.includes(Number(c.id))).length;
        btn.querySelector('span').textContent = fuori ? `Clienti nel conteggio: ${_clienti.length - fuori} di ${_clienti.length}` : 'Clienti nel conteggio: tutti';
        btn.classList.toggle('btn-primary', fuori > 0); btn.classList.toggle('btn-secondary', !fuori);
    }

    function apriFiltroClienti() {
        if (!_clienti.length) { UI.toast('Nessuna fattura nell\'anno selezionato','error'); return; }
        const righe = _clienti.map(c => `<label style="display:flex;align-items:center;gap:10px;padding:8px 4px;border-bottom:1px solid var(--border-subtle);cursor:pointer">
                <input type="checkbox" class="fc-cliente" value="${UI.esc(c.id)}" ${_esclusi.includes(Number(c.id)) ? '' : 'checked'} style="width:18px;height:18px">
                <span style="flex:1">${UI.esc(c.nome)} <span style="color:var(--text-muted);font-size:0.8rem">(${UI.esc(c.num_fatture)})</span></span>
                <span class="td-mono" style="font-size:0.85rem">${UI.esc(UI.formatCurrency(c.fatturato))}</span></label>`).join('');
        const html = `<div style="display:flex;gap:8px;margin-bottom:10px">
                <button type="button" class="btn btn-sm btn-secondary" id="fc-tutti">Tutti</button>
                <button type="button" class="btn btn-sm btn-secondary" id="fc-nessuno">Nessuno</button></div>
            <div style="max-height:55vh;overflow-y:auto">${righe}</div>
            <div style="font-size:0.8rem;color:var(--text-muted);margin-top:10px">I clienti senza spunta restano nell'elenco delle fatture ma non entrano in KPI e grafico. I clienti nuovi entrano da soli.</div>`;
        UI.openModal('Clienti nel conteggio', html, salvaFiltroClienti);
        const tutte = v => document.querySelectorAll('.fc-cliente').forEach(x => { x.checked = v; });
        document.getElementById('fc-tutti').addEventListener('click', () => tutte(true));
        document.getElementById('fc-nessuno').addEventListener('click', () => tutte(false));
    }

    async function salvaFiltroClienti() {
        const visibili = [...document.querySelectorAll('.fc-cliente')];
        // Si tengono gli esclusi di altri anni (non in elenco) e si aggiornano quelli dell'anno mostrato
        const inElenco = new Set(visibili.map(x => Number(x.value)));
        const esclusi = _esclusi.filter(id => !inElenco.has(id)).concat(visibili.filter(x => !x.checked).map(x => Number(x.value)));
        const fd = new FormData(); fd.append('esclusi', JSON.stringify(esclusi));
        await Store.upload('filtro_clienti', 'contabilita', fd);
        UI.closeModal(); UI.toast('Scelta salvata'); load();
    }

    // Grafici di Fatture (js/core/grafici.js): da quanto aspettiamo i soldi e quanto pesa ogni cliente
    function renderGrafici(ov) {
        const box = document.getElementById('contabilita-grafici');
        if (!box || !window.Grafici) return;
        const a = ov?.anzianita || {};
        _aperte = ov?.anzianita_fatture || [];
        const tot = parseFloat(_kpis.fatturato) || 0;
        const top = (ov?.top_clienti || []).map((c, i) => ({ chiave: 'cliente:' + (c.cliente_id ?? 0), etichetta: c.ragione_sociale || 'Senza cliente', valore: parseFloat(c.fatturato) || 0,
            colore: Grafici.PALETTE[i] }));
        _top = (ov?.top_clienti || []).map(c => Number(c.cliente_id ?? 0));
        const altri = tot - top.reduce((x, c) => x + c.valore, 0);
        if (altri > 0.5) top.push({ chiave: 'cliente:altri', etichetta: 'Tutti gli altri', valore: altri, colore: 'var(--text-muted)' });
        const primo = tot > 0 && top.length ? Math.round(top[0].valore / tot * 100) : 0;
        box.innerHTML = [
            Grafici.barre('Da quanto aspetti i soldi', 'Da incassare per giorni dalla scadenza · IVA inclusa', [
                { chiave: 'a_scadere', etichetta: 'Non ancora scadute', valore: parseFloat(a.a_scadere) || 0, colore: 'var(--accent-green)' },
                { chiave: 'giorni_1_30', etichetta: 'Scadute da 1–30 giorni', valore: parseFloat(a.giorni_1_30) || 0, colore: 'var(--accent-warm)' },
                { chiave: 'giorni_31_60', etichetta: 'Scadute da 31–60 giorni', valore: parseFloat(a.giorni_31_60) || 0, colore: 'var(--accent-purple)' },
                { chiave: 'oltre_60', etichetta: 'Scadute da oltre 60 giorni', valore: parseFloat(a.oltre_60) || 0, colore: 'var(--accent-red)', evidenzia: (parseFloat(a.oltre_60) || 0) > 0 },
            ], { vuoto: 'Nessuna fattura da incassare' }),
            Grafici.ciambella('Quanto pesa ogni cliente', `Fatturato ${UI.anno()} · imponibile`, top, { testo: primo + '%', sotto: 'primo cliente' }),
        ].join('');
        Grafici.alClic(box, (chiave, voce) => {
            if (chiave.startsWith('cliente:')) apriCliente(chiave.slice(8), top.find(t => t.chiave === chiave)?.etichetta || '');
            else apriFascia(chiave, voce.querySelector('.graf-etich')?.textContent || '');
        });
    }

    // ── Dettaglio dietro KPI e grafici: le fatture che fanno quel numero ──
    const COL_FATTURA = [
        { titolo: 'N. fattura', valore: f => f.numero_fattura },
        { titolo: 'Data', valore: f => UI.formatDate(f.data_emissione) },
        { titolo: 'Cliente', valore: f => f.cliente_nome || '—' },
    ];
    const colScadenza = { titolo: 'Scadenza', valore: f => f.data_scadenza ? UI.formatDate(f.data_scadenza) : '—' };
    const colImponibile = { titolo: 'Imponibile', destra: true, valore: f => UI.formatCurrency(f.imponibile), somma: f => f.imponibile };
    const colTotale = { titolo: 'Totale', destra: true, valore: f => UI.formatCurrency(f.importo_totale), somma: f => f.importo_totale };

    /** Tutte le fatture dell'anno senza i clienti esclusi dai conteggi (come KPI e grafici), lette una volta per load */
    async function tutte() {
        if (!_tutte) _tutte = (await Store.api('list', 'contabilita', { year: UI.anno() }) || []);
        return _tutte.filter(f => !_esclusi.includes(Number(f.cliente_id || 0)));
    }

    // Ogni riga delle finestre apre la sua fattura
    const apriRiga = (o = {}) => ({ ...o, apri: f => edit(f.id, (f.data_emissione || '').slice(0, 4)) });

    async function apriKpi(chiave) {
        const oggi = UI.todayLocal();
        const fa = await tutte();
        const aperte = fa.filter(f => f.stato !== 'pagata');
        if (chiave === 'fatturato') Grafici.elenco(`Fatture ${UI.anno()}`, [...COL_FATTURA, colImponibile, colTotale], fa, apriRiga());
        else if (chiave === 'incassato') Grafici.elenco('Fatture incassate', [...COL_FATTURA, { titolo: 'Pagata il', valore: f => f.data_pagamento ? UI.formatDate(f.data_pagamento) : '—' }, colTotale], fa.filter(f => f.stato === 'pagata'), apriRiga());
        else if (chiave === 'da_incassare') Grafici.elenco('Fatture da incassare', [...COL_FATTURA, colScadenza, colTotale], aperte,
            apriRiga({ nota: 'IVA inclusa. Le note di credito aperte sono righe negative.' }));
        else if (chiave === 'scaduto') Grafici.elenco('Fatture scadute', [...COL_FATTURA, colScadenza, colTotale], aperte.filter(f => f.data_scadenza && f.data_scadenza.slice(0, 10) < oggi), apriRiga());
    }

    async function apriCliente(id, nome) {
        const fa = await tutte();
        const righe = id === 'altri' ? fa.filter(f => !_top.includes(Number(f.cliente_id || 0))) : fa.filter(f => Number(f.cliente_id || 0) === Number(id));
        Grafici.elenco(id === 'altri' ? 'Tutti gli altri clienti' : nome, [...COL_FATTURA, { titolo: 'Stato', html: f => UI.statoBadge(f.stato) }, colImponibile], righe, apriRiga());
    }

    async function apriMese(m) {
        const mm = String(m).padStart(2, '0');
        const righe = (await tutte()).filter(f => (f.data_emissione || '').slice(5, 7) === mm);
        Grafici.elenco(`Fatture di ${['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'][m]} ${UI.anno()}`,
            [...COL_FATTURA, { titolo: 'Stato', html: f => UI.statoBadge(f.stato) }, colImponibile, colTotale], righe, apriRiga());
    }

    // Le fatture dietro una fascia di «Da quanto aspetti i soldi»
    function apriFascia(chiave, titolo) {
        const ritardo = g => g > 0 ? `${g} ${g === 1 ? 'giorno' : 'giorni'}` : g === 0 ? 'scade oggi' : '—';
        Grafici.elenco(titolo, [...COL_FATTURA, colScadenza, { titolo: 'Ritardo', valore: f => ritardo(f.giorni) }, colTotale],
            _aperte.filter(f => f.fascia === chiave),
            apriRiga({ nota: 'IVA inclusa. Nel grafico le note di credito aperte si compensano per cliente: il totale può differire di poco.' }));
    }

    function renderChart() {
        const container = document.getElementById('contabilita-chart');
        if (!_mensile.length) { container.innerHTML = '<div style="color:var(--text-muted);font-size:0.8rem;padding:40px;text-align:center">Nessun dato</div>'; return; }
        const mesi = ['','Gen','Feb','Mar','Apr','Mag','Giu','Lug','Ago','Set','Ott','Nov','Dic'];
        const maxVal = Math.max(..._mensile.map(m => parseFloat(m.fatturato)||0), 1);
        const fullData = [];
        for (let i=1;i<=12;i++) { const f=_mensile.find(m=>parseInt(m.mese)===i); fullData.push({mese:i,fatturato:f?parseFloat(f.fatturato):0,pagato:f?parseFloat(f.pagato):0,num_fatture:f?parseInt(f.num_fatture):0}); }
        container.innerHTML = fullData.map(m => {
            const hF=Math.max((m.fatturato/maxVal)*100,4), hP=Math.max((m.pagato/maxVal)*100,0);
            return `<div class="chart-column-container" data-chiave="${m.mese}" role="button" tabindex="0" title="Vedi le fatture del mese" style="cursor:pointer;flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;position:relative;height:100%">
                <div class="custom-tooltip"><span class="tooltip-title">${mesi[m.mese]}</span><div class="tooltip-row"><span>Fatturato:</span> <span class="tooltip-val">${UI.formatCurrency(m.fatturato)}</span></div><div class="tooltip-row"><span>Incassato:</span> <span class="tooltip-val" style="color:var(--accent-green)">${UI.formatCurrency(m.pagato)}</span></div></div>
                <div style="flex:1;width:100%;display:flex;align-items:flex-end;gap:2px"><div class="chart-bar" style="height:${hF}%;opacity:0.4"></div><div class="chart-bar" style="height:${hP>0?hP+'%':'0%'};background:var(--accent-green)"></div></div>
                <span style="font-size:0.6rem;color:var(--text-muted)">${mesi[m.mese]}</span></div>`;
        }).join('');
        Grafici.alClic(container, m => apriMese(+m));
    }

    function renderTable() {
        const tbody = document.getElementById('tbody-fatture');
        if (!_fatture.length) { tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><i class="ph ph-chart-line-up"></i><h3>Nessuna fattura</h3></div></td></tr>'; return; }
        tbody.innerHTML = _fatture.map(f => `<tr data-id="${UI.esc(f.id)}">
            <td class="td-mono">${UI.esc(f.numero_fattura)}</td>
            <td>${UI.formatDate(f.data_emissione)}</td>
            <td class="td-primary cella-titolo">${UI.esc(f.cliente_nome||'—')}${f.sottocliente_nome?` <span style="color:var(--text-muted)">/ ${UI.esc(f.sottocliente_nome)}</span>`:''}</td>
            <td class="cella-commessa">${f.incarico_id ? `<a href="#" onclick="event.preventDefault();ModCommessa.open(${+f.incarico_id})" title="${UI.esc(f.commessa_descrizione || '')}"><i class="ph ph-link"></i> ${UI.esc((f.commessa_descrizione || UI.tipoCommessa(f.commessa_tipo) || '#' + f.incarico_id).slice(0, 40))}</a>${f.commessa_anno && String(f.commessa_anno) !== String(UI.anno()) ? ` <span class="badge badge-gray" title="Commessa di un altro anno">${UI.esc(f.commessa_anno)}</span>` : ''}` : '<span class="text-muted">—</span>'}</td>
            <td class="text-right">${UI.formatCurrency(f.imponibile)}</td>
            <td class="text-right td-primary">${UI.formatCurrency(f.importo_totale)}</td>
            <td>${UI.statoBadge(f.stato)}</td>
            <td>${f.data_scadenza?UI.formatDate(f.data_scadenza):'—'}</td>
            <td><div class="flex gap-2">
                ${f.documento_ext ? `<a class="btn btn-sm btn-ghost" href="api/router.php?module=contabilita&action=documento&id=${f.id}" target="_blank" rel="noopener" title="${f.documento_ext === 'pdf' ? 'Apri il PDF' : 'Scarica il file ' + UI.esc(f.documento_ext.toUpperCase())}" aria-label="${f.documento_ext === 'pdf' ? 'Apri il PDF' : 'Scarica il file'}"><i class="ph ${f.documento_ext === 'pdf' ? 'ph-file-pdf' : 'ph-file-code'}"></i></a>` : ''}
                <button class="btn btn-sm btn-ghost" onclick="ModContabilita.edit(${f.id})"><i class="ph ph-pencil-simple"></i></button>
                <button class="btn btn-sm btn-danger" onclick="ModContabilita.remove(${f.id})"><i class="ph ph-trash"></i></button>
            </div></td></tr>`).join('');
    }

    function getFormHtml(d={}) {
        const clienti = ModClienti.getClienti();
        const cOpts = clienti.map(c => `<option value="${UI.esc(c.id)}" ${c.id==d.cliente_id?'selected':''}>${UI.esc(c.ragione_sociale)}</option>`).join('');
        const stati = ['emessa','inviata','pagata','scaduta'];
        const sOpts = stati.map(s => `<option value="${s}" ${s===(d.stato||'emessa')?'selected':''}>${s.charAt(0).toUpperCase()+s.slice(1)}</option>`).join('');
        return `<div class="form-grid">
            <div class="form-group"><label>Numero Fattura *</label><input type="text" class="form-control" id="f-f-numero" value="${UI.esc(d.numero_fattura||'')}" placeholder="es. 2026/001"></div>
            <div class="form-group"><label>Data Emissione *</label><input type="date" class="form-control" id="f-f-data" value="${UI.esc(d.data_emissione||UI.todayLocal())}"></div>
            <div class="form-group"><label>Cliente</label><select class="form-control" id="f-f-cliente"><option value="">— Seleziona —</option>${cOpts}</select></div>
            <div class="form-group"><label>Sottocliente</label><select class="form-control" id="f-f-sottocliente"><option value="">— Nessuno —</option></select></div>
            <div class="form-group"><label>Incarico collegato</label><select class="form-control" id="f-f-incarico"><option value="">— Nessuno —</option></select></div>
            <div class="form-group"><label>Imponibile (€)</label><input type="number" class="form-control" id="f-f-imponibile" value="${UI.esc(d.imponibile||0)}" step="0.01"></div>
            <div class="form-group"><label>IVA %</label><input type="number" class="form-control" id="f-f-iva" value="${UI.esc(d.iva_percentuale||22)}" step="0.01"></div>
            <div class="form-group"><label>Stato</label><select class="form-control" id="f-f-stato">${sOpts}</select></div>
            <div class="form-group"><label>Data Scadenza</label><input type="date" class="form-control" id="f-f-scadenza" value="${UI.esc(d.data_scadenza||'')}"></div>
            <div class="form-group"><label>Data Pagamento</label><input type="date" class="form-control" id="f-f-pagamento" value="${UI.esc(d.data_pagamento||'')}"></div>
            <div class="form-group"><label>Metodo Pagamento</label><select class="form-control" id="f-f-metodo">
                <option value="" ${!d.metodo_pagamento?'selected':''}>—</option>
                <option value="bonifico" ${d.metodo_pagamento==='bonifico'?'selected':''}>Bonifico</option>
                <option value="carta" ${d.metodo_pagamento==='carta'?'selected':''}>Carta</option>
                <option value="contanti" ${d.metodo_pagamento==='contanti'?'selected':''}>Contanti</option>
            </select></div>
            <div class="form-group full-width"><label>Descrizione</label><textarea class="form-control" id="f-f-desc">${UI.esc(d.descrizione||'')}</textarea></div>
            <div class="form-group full-width"><label>Note</label><textarea class="form-control" id="f-f-note">${UI.esc(d.note||'')}</textarea></div>
        </div><input type="hidden" id="f-f-id" value="${UI.esc(d.id||'')}">`;
    }

    function openNew() { UI.openModal('Nuova Fattura',getFormHtml(),saveFromForm); setTimeout(initClienteWatch,100); }
    async function edit(id, anno) {
        let f = _fatture.find(x => x.id == id) || (_tutte || []).find(x => x.id == id);
        if (!f && anno) f = (await Store.api('list', 'contabilita', { year: anno }) || []).find(x => x.id == id);
        if (!f) { UI.toast('Fattura non trovata', 'error'); return; } UI.openModal('Modifica Fattura',getFormHtml(f),saveFromForm); setTimeout(()=>{ initClienteWatch(); if(f.cliente_id){loadSottoclienti(f.cliente_id,f.sottocliente_id); loadIncarichi(f.cliente_id,f.incarico_id);} },100); }

    function initClienteWatch() {
        const sel = document.getElementById('f-f-cliente');
        if (!sel) return;
        sel.addEventListener('change', () => { loadSottoclienti(sel.value); loadIncarichi(sel.value); });
    }

    async function loadSottoclienti(cid, selId) {
        const sel = document.getElementById('f-f-sottocliente');
        sel.innerHTML = '<option value="">— Nessuno —</option>';
        if (!cid) return;
        try { const subs = await Store.api('list','sottoclienti',{cliente_id:cid}); if(subs?.length) subs.forEach(s=>{ const o=document.createElement('option'); o.value=s.id; o.textContent=s.nome; if(s.id==selId)o.selected=true; sel.appendChild(o); }); } catch(e){}
    }

    async function loadIncarichi(cid, selId) {
        const sel = document.getElementById('f-f-incarico');
        sel.innerHTML = '<option value="">— Nessuno —</option>';
        if (!cid) return;
        try {
            const list = await Store.api('get_by_cliente','incarichi',{cliente_id:cid});
            if (list?.length) list.forEach(i => {
                const residuo = UI.formatCurrency((parseFloat(i.importo_totale)||0)-(parseFloat(i.importo_fatturato)||0));
                const o = document.createElement('option');
                o.value = i.id;
                o.textContent = `${UI.tipoCommessa(i.tipo_commessa)} ${UI.formatDate(i.data_incarico)} — Residuo: ${residuo}${i.sottocliente_nome?' ('+i.sottocliente_nome+')':''}`;
                if (i.id==selId) o.selected = true;
                sel.appendChild(o);
            });
        } catch(e){}
    }

    async function saveFromForm() {
        const p = { id:document.getElementById('f-f-id').value||undefined, numero_fattura:document.getElementById('f-f-numero').value, data_emissione:document.getElementById('f-f-data').value, cliente_id:document.getElementById('f-f-cliente').value, sottocliente_id:document.getElementById('f-f-sottocliente').value, incarico_id:document.getElementById('f-f-incarico').value, imponibile:document.getElementById('f-f-imponibile').value, iva_percentuale:document.getElementById('f-f-iva').value, stato:document.getElementById('f-f-stato').value, data_scadenza:document.getElementById('f-f-scadenza').value, data_pagamento:document.getElementById('f-f-pagamento').value, metodo_pagamento:document.getElementById('f-f-metodo').value, descrizione:document.getElementById('f-f-desc').value, note:document.getElementById('f-f-note').value };
        try { await Store.api('save','contabilita',p); UI.closeModal(); UI.toast(p.id?'Fattura aggiornata':'Fattura creata'); load(); } catch(e){ UI.toast(e.message,'error'); }
    }

    async function remove(id) { if(!confirm('Eliminare questa fattura?'))return; try{ await Store.api('delete','contabilita',{id}); UI.toast('Fattura eliminata'); load(); }catch(e){ UI.toast(e.message,'error'); } }

    function initFilters() {
        // Fatture status filter
        document.querySelectorAll('#tab-fatture .filter-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('#tab-fatture .filter-chip').forEach(c=>c.classList.remove('active'));
                chip.classList.add('active'); _statoFilter = chip.dataset.stato||''; load();
            });
        });
        ModIncarichi.initFilters();
        if (window.ModRiconciliazione) ModRiconciliazione.init();
        if (window.ModMovimenti) ModMovimenti.init();
        if (window.ModAndamento) ModAndamento.init();
    }

    return { load, openNew, edit, remove, initFilters, apriFiltroClienti };
})();
window.ModContabilita = ModContabilita;
