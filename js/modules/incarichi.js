'use strict';
const ModIncarichi = (() => {
    let _incarichi = [], _kpis = {}, _filter = '';

    async function load() {
        const year = document.getElementById('contabilita-year').value;
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
            <div class="kpi-card kpi-blue"><div class="kpi-label">Valore Incarichi</div><div class="kpi-value">${UI.formatCurrency(k.totale_incarichi)}</div><div class="kpi-sub">${k.num_incarichi||0} incarichi</div></div>
            <div class="kpi-card kpi-green"><div class="kpi-label">Fatturato</div><div class="kpi-value">${UI.formatCurrency(k.totale_fatturato)}</div><div class="kpi-sub">${k.num_fatturati||0} completati</div></div>
            <div class="kpi-card kpi-yellow"><div class="kpi-label">Da Fatturare</div><div class="kpi-value">${UI.formatCurrency(k.residuo_da_fatturare)}</div><div class="kpi-sub">${k.num_attivi||0} attivi</div></div>
            <div class="kpi-card kpi-red"><div class="kpi-label">Non Pagato</div><div class="kpi-value">${UI.formatCurrency(k.fatturato_non_pagato)}</div><div class="kpi-sub">fatturato non incassato</div></div>`;
    }

    function renderTable() {
        const tbody = document.getElementById('tbody-incarichi');
        let data = _incarichi;
        if (_filter) data = data.filter(i => i.stato === _filter);
        if (!data.length) { tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><i class="ph ph-clipboard-text"></i><h3>Nessun incarico</h3></div></td></tr>'; return; }

        const tipoBadge = t => {
            const colors = {assistenza:'#6366f1',dpo:'#f59e0b',formazione:'#10b981'};
            return `<span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;background:${colors[t]||'#666'}22;color:${colors[t]||'#666'}">${t.toUpperCase()}</span>`;
        };
        const statoBadge = s => {
            const c = {attivo:'#3b82f6',parziale:'#f59e0b',fatturato:'#8b5cf6',pagato:'#10b981'};
            return `<span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;background:${c[s]||'#666'}22;color:${c[s]||'#666'}">${s.charAt(0).toUpperCase()+s.slice(1)}</span>`;
        };

        tbody.innerHTML = data.map(i => {
            const tot = parseFloat(i.importo_totale)||1;
            const fatt = parseFloat(i.importo_fatturato)||0;
            const pag = parseFloat(i.importo_pagato)||0;
            const pctF = Math.min((fatt/tot)*100,100).toFixed(0);
            const pctP = Math.min((pag/tot)*100,100).toFixed(0);
            const cliente = UI.esc(i.cliente_nome||'—') + (i.sottocliente_nome ? ` <span style="color:var(--text-muted)">/ ${UI.esc(i.sottocliente_nome)}</span>` : '');
            return `<tr data-id="${i.id}">
                <td></td>
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
                    <button class="btn btn-sm btn-ghost" onclick="ModIncarichi.edit(${i.id})"><i class="ph ph-pencil-simple"></i></button>
                    <button class="btn btn-sm btn-danger" onclick="ModIncarichi.remove(${i.id})"><i class="ph ph-trash"></i></button>
                </div></td>
            </tr>`;
        }).join('');
    }

    function getFormHtml(d={}) {
        const clienti = ModClienti.getClienti();
        const cOpts = clienti.map(c => `<option value="${c.id}" ${c.id==d.cliente_id?'selected':''}>${UI.esc(c.ragione_sociale)}</option>`).join('');
        const tipi = ['assistenza','dpo','formazione'];
        const tOpts = tipi.map(t => `<option value="${t}" ${t===(d.tipo_commessa||'assistenza')?'selected':''}>${t.charAt(0).toUpperCase()+t.slice(1)}</option>`).join('');
        return `<div class="form-grid">
            <div class="form-group"><label>Cliente *</label><select class="form-control" id="f-inc-cliente"><option value="">— Seleziona —</option>${cOpts}</select></div>
            <div class="form-group"><label>Sottocliente</label><select class="form-control" id="f-inc-sotto"><option value="">— Nessuno —</option></select></div>
            <div class="form-group"><label>Data Incarico *</label><input type="date" class="form-control" id="f-inc-data" value="${d.data_incarico||new Date().toISOString().split('T')[0]}"></div>
            <div class="form-group"><label>Tipo Commessa *</label><select class="form-control" id="f-inc-tipo">${tOpts}</select></div>
            <div class="form-group"><label>N. Giornate</label><input type="number" class="form-control" id="f-inc-gg" value="${d.num_giornate||0}" step="0.5"></div>
            <div class="form-group"><label>Importo Totale (€) *</label><input type="number" class="form-control" id="f-inc-importo" value="${d.importo_totale||0}" step="0.01"></div>
            <div class="form-group full-width"><label>Descrizione</label><textarea class="form-control" id="f-inc-desc">${UI.esc(d.descrizione||'')}</textarea></div>
            <div class="form-group full-width"><label>Note</label><textarea class="form-control" id="f-inc-note">${UI.esc(d.note||'')}</textarea></div>
        </div><input type="hidden" id="f-inc-id" value="${d.id||''}">`;
    }

    function openNew() {
        UI.openModal('Nuovo Incarico', getFormHtml(), saveForm);
        setTimeout(initClienteWatch, 100);
    }
    function edit(id) {
        const i = _incarichi.find(x => x.id==id);
        if (!i) return;
        UI.openModal('Modifica Incarico', getFormHtml(i), saveForm);
        setTimeout(() => { initClienteWatch(); if (i.cliente_id) loadSotto(i.cliente_id, i.sottocliente_id); }, 100);
    }
    function initClienteWatch() {
        const sel = document.getElementById('f-inc-cliente');
        if (sel) sel.addEventListener('change', () => loadSotto(sel.value));
    }
    async function loadSotto(cid, selId) {
        const sel = document.getElementById('f-inc-sotto');
        sel.innerHTML = '<option value="">— Nessuno —</option>';
        if (!cid) return;
        try {
            const subs = await Store.api('list','sottoclienti',{cliente_id:cid});
            if (subs?.length) subs.forEach(s => { const o=document.createElement('option'); o.value=s.id; o.textContent=s.nome; if(s.id==selId)o.selected=true; sel.appendChild(o); });
        } catch(e){}
    }
    async function saveForm() {
        const p = {
            id: document.getElementById('f-inc-id').value||undefined,
            cliente_id: document.getElementById('f-inc-cliente').value,
            sottocliente_id: document.getElementById('f-inc-sotto').value,
            data_incarico: document.getElementById('f-inc-data').value,
            tipo_commessa: document.getElementById('f-inc-tipo').value,
            num_giornate: document.getElementById('f-inc-gg').value,
            importo_totale: document.getElementById('f-inc-importo').value,
            descrizione: document.getElementById('f-inc-desc').value,
            note: document.getElementById('f-inc-note').value
        };
        try { await Store.api('save','incarichi',p); UI.closeModal(); UI.toast(p.id?'Incarico aggiornato':'Incarico creato'); load(); }
        catch(e) { UI.toast(e.message,'error'); }
    }
    async function remove(id) {
        if (!confirm('Eliminare questo incarico?')) return;
        try { await Store.api('delete','incarichi',{id}); UI.toast('Incarico eliminato'); load(); }
        catch(e) { UI.toast(e.message,'error'); }
    }

    async function importPdf(file) {
        if (!file||file.type!=='application/pdf') { UI.toast('Seleziona un PDF valido','error'); return; }
        const btn = document.getElementById('btn-import-pdf-incarico');
        const prev = btn.innerHTML;
        btn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> Analisi...'; btn.disabled = true;
        try {
            const ab = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({data:ab}).promise;
            const pages = [];
            for (let i=1;i<=pdf.numPages;i++) { const pg=await pdf.getPage(i); const tc=await pg.getTextContent(); pages.push(tc.items.map(x=>x.str).join(' ')); }
            const res = await Store.api('import_pdf','incarichi',{pages});
            if (res) {
                UI.openModal('Nuovo Incarico (da PDF)', getFormHtml({
                    cliente_id: res.cliente_id, sottocliente_id: res.sottocliente_id,
                    data_incarico: res.data_incarico, importo_totale: res.importo_totale,
                    num_giornate: res.num_giornate, tipo_commessa: res.tipo_commessa,
                    descrizione: res.descrizione
                }), saveForm);
                setTimeout(() => { initClienteWatch(); if(res.cliente_id) loadSotto(res.cliente_id, res.sottocliente_id); }, 100);
                UI.toast('Dati estratti dal PDF — verifica e salva');
            }
        } catch(e) { UI.toast('Errore parsing PDF: '+e.message,'error'); }
        finally { btn.innerHTML = prev; btn.disabled = false; }
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
