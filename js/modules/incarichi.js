'use strict';
const ModIncarichi = (() => {
    let _incarichi = [], _kpis = {}, _filter = '';

    async function load() {
        const year = UI.anno();
        try {
            const ov = await Store.api('overview', 'incarichi', { year });
            _kpis = ov?.kpis || {};
            renderKpis(ov);
            const list = await Store.api('list', 'incarichi', { year });
            _incarichi = list || [];
            renderTable();
        } catch (e) { console.error('[Incarichi]', e); UI.toast('Errore caricamento incarichi','error'); }
    }

    function renderKpis(ov) {
        const k = _kpis;
        document.getElementById('incarichi-kpis').innerHTML = `
            <div class="kpi-card kpi-blue"><div class="kpi-label">Valore commesse</div><div class="kpi-value">${UI.formatCurrency(k.valore)}</div><div class="kpi-sub">imponibile · ${UI.plurale(k.num_commesse,'commessa','commesse')}</div></div>
            <div class="kpi-card kpi-green"><div class="kpi-label">Fatturato</div><div class="kpi-value">${UI.formatCurrency(k.fatturato)}</div><div class="kpi-sub">${UI.plurale(k.num_fatturati,'completamente fatturata','completamente fatturate')}</div></div>
            <div class="kpi-card kpi-yellow"><div class="kpi-label">Da fatturare</div><div class="kpi-value">${UI.formatCurrency(k.da_fatturare)}</div><div class="kpi-sub">${UI.plurale(k.num_da_fatturare,'commessa','commesse')}</div></div>
            <div class="kpi-card kpi-red"><div class="kpi-label">Incassato</div><div class="kpi-value">${UI.formatCurrency(k.incassato_netto)}</div><div class="kpi-sub">imponibile delle fatture pagate</div></div>`;
    }

    function renderTable() {
        const tbody = document.getElementById('tbody-incarichi');
        let data = _incarichi;
        if (_filter) data = data.filter(i => i.stato === _filter);
        if (!data.length) { tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><i class="ph ph-clipboard-text"></i><h3>Nessun incarico</h3></div></td></tr>'; return; }

        const tipoBadge = t => {
            const colors = {assistenza:'#6366f1',dpo:'#f59e0b',formazione:'#10b981',nis2:'#ef4444',ict:'#0ea5e9',digital:'#ec4899',sviluppo_software:'#8b5cf6',altro:'#64748b'};
            return `<span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;background:${colors[t]||'#666'}22;color:${colors[t]||'#666'}">${UI.esc(UI.tipoCommessa(t))}</span>`;
        };
        const statoBadge = s => {
            const c = {attivo:'#3b82f6',parziale:'#f59e0b',fatturato:'#8b5cf6',pagato:'#10b981'};
            return `<span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;background:${c[s]||'#666'}22;color:${c[s]||'#666'}">${UI.esc(s?s.charAt(0).toUpperCase()+s.slice(1):'—')}</span>`;
        };

        tbody.innerHTML = data.map(i => {
            const tot = parseFloat(i.importo_totale)||1;
            const fatt = parseFloat(i.importo_fatturato)||0;
            const pag = parseFloat(i.importo_pagato)||0;
            const pctF = Math.min((fatt/tot)*100,100).toFixed(0);
            const pctP = Math.min((pag/tot)*100,100).toFixed(0);
            const cliente = UI.esc(i.cliente_nome||'—') + (i.sottocliente_nome ? ` <span style="color:var(--text-muted)">/ ${UI.esc(i.sottocliente_nome)}</span>` : '');
            const protLabel = i.numero_protocollo ? `<span style="font-size:0.7rem;color:var(--accent-secondary);opacity:0.8" title="Protocollo"><i class="ph ph-hash"></i> ${UI.esc(i.numero_protocollo)}</span>` : '';
            return `<tr data-id="${UI.esc(i.id)}">
                <td>${protLabel}</td>
                <td class="td-primary">${cliente}</td>
                <td>${tipoBadge(i.tipo_commessa)}</td>
                <td>${UI.formatDate(i.data_incarico)}</td>
                <td class="text-right">${parseFloat(i.num_giornate)||0}</td>
                <td class="text-right td-primary">${UI.formatCurrency(i.importo_totale)}</td>
                <td style="min-width:140px">
                    <div style="display:flex;flex-direction:column;gap:2px">
                        <div style="display:flex;align-items:center;gap:6px;font-size:0.7rem">
                            <div style="flex:1;height:6px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden">
                                <div style="height:100%;width:${pctF}%;background:#6366f1;border-radius:3px;transition:width .3s"></div>
                            </div>
                            <span style="color:var(--text-muted);min-width:32px">${pctF}%</span>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;font-size:0.7rem">
                            <div style="flex:1;height:6px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden">
                                <div style="height:100%;width:${pctP}%;background:#10b981;border-radius:3px;transition:width .3s"></div>
                            </div>
                            <span style="color:var(--text-muted);min-width:32px">${pctP}%</span>
                        </div>
                    </div>
                </td>
                <td>${statoBadge(i.stato)}</td>
                <td><div class="flex gap-2">
                    <button class="btn btn-sm btn-primary" onclick="ModCommessa.open(${i.id}, ModIncarichi.load)" title="Scheda commessa: rate, partner, margine" aria-label="Scheda commessa"><i class="ph ph-folder-open"></i></button>
                    <button class="btn btn-sm btn-ghost" onclick="ModIncarichi.edit(${i.id})"><i class="ph ph-pencil-simple"></i></button>
                    <button class="btn btn-sm btn-danger" onclick="ModIncarichi.remove(${i.id})"><i class="ph ph-trash"></i></button>
                </div></td>
            </tr>`;
        }).join('');
    }

    function getFormHtml(d={}) {
        const clienti = ModClienti.getClienti();
        const cOpts = clienti.map(c => `<option value="${UI.esc(c.id)}" ${c.id==d.cliente_id?'selected':''}>${UI.esc(c.ragione_sociale)}</option>`).join('');
        const tOpts = UI.tipiCommessaOptions(d.tipo_commessa);
        return `<div class="form-grid">
            <div class="form-group"><label>Cliente *</label><select class="form-control" id="f-inc-cliente"><option value="">— Seleziona —</option>${cOpts}</select></div>
            <div class="form-group"><label>Sottocliente</label><select class="form-control" id="f-inc-sotto"><option value="">— Nessuno —</option></select></div>
            <div class="form-group"><label>Data Incarico *</label><input type="date" class="form-control" id="f-inc-data" value="${UI.esc(d.data_incarico||UI.todayLocal())}"></div>
            <div class="form-group"><label>Tipo Commessa *</label><select class="form-control" id="f-inc-tipo">${tOpts}</select></div>
            <div class="form-group"><label>N. Protocollo</label><input type="text" class="form-control" id="f-inc-protocollo" value="${UI.esc(d.numero_protocollo||'')}" placeholder="es. SZ.DPS.F142.26"></div>
            <div class="form-group"><label>N. Giornate</label><input type="number" class="form-control" id="f-inc-gg" value="${UI.esc(d.num_giornate||0)}" step="0.5"></div>
            <div class="form-group"><label>Importo Totale (€) *</label><input type="number" class="form-control" id="f-inc-importo" value="${UI.esc(d.importo_totale||0)}" step="0.01"></div>
            <div class="form-group full-width"><label>Descrizione</label><input class="form-control" id="f-inc-desc" value="${UI.esc(d.descrizione||'')}"></div>
            <div class="form-group"><label>Pagamento (gg data fattura)</label><input type="number" class="form-control" id="f-inc-giorni" value="${UI.esc(d.giorni_pagamento ?? 30)}"></div>
            ${d.id ? '' : `<div class="form-group"><label>Da fatturare il</label><input type="date" class="form-control" id="f-inc-fatt" value="${UI.esc(d.data_fatturazione||'')}" title="Data della rata di saldo: lo scadenzario te la ricorda"></div>`}
            <div class="form-group full-width"><label>Condizioni di pagamento</label><input class="form-control" id="f-inc-cond" value="${UI.esc(d.condizioni_pagamento||'')}"></div>
            <div class="form-group full-width"><label>Note</label><textarea class="form-control" id="f-inc-note">${UI.esc(d.note||'')}</textarea></div>
        </div><input type="hidden" id="f-inc-id" value="${UI.esc(d.id||'')}">
        <input type="hidden" id="f-inc-pdf" value="${UI.esc(d.pdf_ref||'')}">
        <input type="hidden" id="f-inc-sotto-nuovo" value="${UI.esc(d.sottocliente_nuovo||'')}">`;
    }

    function openNew() {
        UI.openModal('Nuova commessa', getFormHtml(), saveForm);
        setTimeout(initClienteWatch, 100);
    }
    function edit(id) {
        const i = _incarichi.find(x => x.id==id);
        if (!i) return;
        UI.openModal('Modifica commessa', getFormHtml(i), saveForm);
        setTimeout(() => { initClienteWatch(); if (i.cliente_id) loadSotto(i.cliente_id, i.sottocliente_id); }, 100);
    }
    function initClienteWatch() {
        const sel = document.getElementById('f-inc-cliente');
        if (sel) sel.addEventListener('change', () => {
            // Il sottocliente letto dal PDF appartiene al cliente riconosciuto, non a quello scelto a mano
            const nuovo = document.getElementById('f-inc-sotto-nuovo');
            if (nuovo) nuovo.value = '';
            loadSotto(sel.value);
        });
    }
    async function loadSotto(cid, selId) {
        const sel = document.getElementById('f-inc-sotto');
        sel.innerHTML = '<option value="">— Nessuno —</option>';
        if (!cid) return;
        try {
            const subs = await Store.api('list','sottoclienti',{cliente_id:cid});
            if (subs?.length) subs.forEach(s => { const o=document.createElement('option'); o.value=s.id; o.textContent=s.nome; if(s.id==selId)o.selected=true; sel.appendChild(o); });
        } catch(e){}
        // Sottocliente letto dal PDF ma non in anagrafica: si crea al salvataggio se resta selezionato
        const nuovo = document.getElementById('f-inc-sotto-nuovo')?.value;
        if (nuovo && !selId) {
            const o = document.createElement('option');
            o.value = '__nuovo__';
            o.textContent = '+ Nuovo: ' + nuovo;
            o.selected = true;
            sel.appendChild(o);
        }
    }
    async function saveForm() {
        const sottoSel = document.getElementById('f-inc-sotto').value;
        const fattEl = document.getElementById('f-inc-fatt');
        const p = {
            id: document.getElementById('f-inc-id').value||undefined,
            cliente_id: document.getElementById('f-inc-cliente').value,
            sottocliente_id: sottoSel === '__nuovo__' ? '' : sottoSel,
            sottocliente_nuovo: sottoSel === '__nuovo__' ? document.getElementById('f-inc-sotto-nuovo').value : '',
            descrizione: document.getElementById('f-inc-desc').value,
            giorni_pagamento: document.getElementById('f-inc-giorni').value,
            condizioni_pagamento: document.getElementById('f-inc-cond').value,
            data_fatturazione: fattEl ? fattEl.value : '',
            pdf_path: document.getElementById('f-inc-pdf').value,
            data_incarico: document.getElementById('f-inc-data').value,
            tipo_commessa: document.getElementById('f-inc-tipo').value,
            numero_protocollo: document.getElementById('f-inc-protocollo').value,
            num_giornate: document.getElementById('f-inc-gg').value,
            importo_totale: document.getElementById('f-inc-importo').value,
            note: document.getElementById('f-inc-note').value
        };
        if (!p.cliente_id) { UI.toast('Seleziona un cliente','error'); return; }
        if (!p.importo_totale || parseFloat(p.importo_totale) <= 0) { UI.toast('Importo deve essere maggiore di zero','error'); return; }
        try {
            await Store.api('save','incarichi',p);
            UI.closeModal();
            UI.toast(p.id?'Commessa aggiornata':'Commessa creata');
            // Se l'anno dell'incarico è diverso da quello selezionato, cambia il selettore
            if (p.data_incarico) {
                UI.impostaAnno(p.data_incarico.substring(0, 4));
            }
            load();
        }
        catch(e) { UI.toast(e.message||'Errore durante il salvataggio','error'); }
    }
    async function remove(id) {
        if (!confirm('Eliminare questo incarico?')) return;
        try { await Store.api('delete','incarichi',{id}); UI.toast('Incarico eliminato'); load(); }
        catch(e) { UI.toast(e.message,'error'); }
    }

    async function importPdf(file) {
        if (!file || !(file.type === 'application/pdf' || /\.pdf$/i.test(file.name))) { UI.toast('Seleziona un PDF valido','error'); return; }
        UI.toast('Lettura della lettera d\'incarico…');
        try {
            // Testo con pdf.js per il metodo a regole di riserva; il PDF intero va all'AI
            const ab = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({data:ab.slice(0)}).promise;
            const pages = [];
            for (let i=1;i<=pdf.numPages;i++) { const pg=await pdf.getPage(i); const tc=await pg.getTextContent(); pages.push(tc.items.map(x=>x.str).join(' ')); }
            const fd = Store.formDataFromArray('pages', pages);
            fd.append('file', file);
            const res = await Store.upload('import_pdf', 'incarichi', fd);
            if (res) {
                const avvisi = res.avvisi || [];
                const intro = `<div class="notice">${res.metodo === 'ai' ? 'Dati letti dall\'AI dal PDF.' : 'Dati letti con il metodo a regole.'} Verificali prima di salvare.${avvisi.length ? '\n• ' + avvisi.map(UI.esc).join('\n• ') : ''}</div>`;
                UI.openModal('Nuova commessa (da PDF)', intro + getFormHtml({
                    cliente_id: res.cliente_id, sottocliente_id: res.sottocliente_id,
                    data_incarico: res.data_incarico, importo_totale: res.importo_totale,
                    num_giornate: res.num_giornate, tipo_commessa: res.tipo_commessa,
                    numero_protocollo: res.numero_protocollo, descrizione: res.descrizione,
                    condizioni_pagamento: res.condizioni_pagamento, giorni_pagamento: res.giorni_pagamento ?? 30,
                    pdf_ref: res.pdf_path, sottocliente_nuovo: res.sottocliente_nuovo
                }), saveForm);
                setTimeout(() => { initClienteWatch(); if(res.cliente_id) loadSotto(res.cliente_id, res.sottocliente_id); }, 100);
            }
        } catch(e) { UI.toast('Errore lettura PDF: '+e.message,'error'); }
    }

    function initFilters() {
        document.querySelectorAll('#tab-incarichi .filter-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('#tab-incarichi .filter-chip').forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                _filter = chip.dataset.incStato || '';
                renderTable();
            });
        });
    }

    function getAll() { return _incarichi; }
    return { load, openNew, edit, remove, importPdf, initFilters, getAll };
})();
window.ModIncarichi = ModIncarichi;
