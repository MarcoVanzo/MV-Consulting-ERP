'use strict';
/**
 * Modulo Contabilità — Fatture, KPI, grafico, tab system, verifica pagamenti
 * Delega la gestione incarichi a ModIncarichi
 */
const ModContabilita = (() => {
    let _fatture = [], _kpis = {}, _mensile = [], _statoFilter = '', _activeTab = 'tab-incarichi';

    async function load() {
        if (_activeTab === 'tab-incarichi') { ModIncarichi.load(); return; }
        if (_activeTab === 'tab-verifica') { loadVerifica(); return; }
        const year = document.getElementById('contabilita-year').value;
        try {
            const ov = await Store.api('overview','contabilita',{year});
            _kpis = ov?.kpis||{}; _mensile = ov?.mensile||[];
            renderKpis(); renderChart();
            const params = {year}; if (_statoFilter) params.stato = _statoFilter;
            const list = await Store.api('list','contabilita',params);
            _fatture = list||[]; renderTable();
        } catch(e) { console.error('[Contabilita]',e); UI.toast('Errore caricamento contabilità','error'); }
    }

    function renderKpis() {
        document.getElementById('contabilita-kpis').innerHTML = `
            <div class="kpi-card kpi-blue"><div class="kpi-label">Fatturato Totale</div><div class="kpi-value">${UI.formatCurrency(_kpis.fatturato_totale)}</div><div class="kpi-sub">${_kpis.num_fatture||0} fatture emesse</div></div>
            <div class="kpi-card kpi-green"><div class="kpi-label">Incassato</div><div class="kpi-value">${UI.formatCurrency(_kpis.totale_pagato)}</div><div class="kpi-sub">${_kpis.num_pagate||0} fatture pagate</div></div>
            <div class="kpi-card kpi-yellow"><div class="kpi-label">In Attesa</div><div class="kpi-value">${UI.formatCurrency(_kpis.in_attesa)}</div><div class="kpi-sub">${_kpis.num_attesa||0} in attesa</div></div>
            <div class="kpi-card kpi-red"><div class="kpi-label">Scaduto</div><div class="kpi-value">${UI.formatCurrency(_kpis.scaduto)}</div><div class="kpi-sub">${_kpis.num_scadute||0} fatture scadute</div></div>`;
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
            return `<div class="chart-column-container" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;position:relative;height:100%">
                <div class="custom-tooltip"><span class="tooltip-title">${mesi[m.mese]}</span><div class="tooltip-row"><span>Fatturato:</span> <span class="tooltip-val">${UI.formatCurrency(m.fatturato)}</span></div><div class="tooltip-row"><span>Incassato:</span> <span class="tooltip-val" style="color:var(--accent-green)">${UI.formatCurrency(m.pagato)}</span></div></div>
                <div style="flex:1;width:100%;display:flex;align-items:flex-end;gap:2px"><div class="chart-bar" style="height:${hF}%;opacity:0.4"></div><div class="chart-bar" style="height:${hP>0?hP+'%':'0%'};background:var(--accent-green)"></div></div>
                <span style="font-size:0.6rem;color:var(--text-muted)">${mesi[m.mese]}</span></div>`;
        }).join('');
    }

    function renderTable() {
        const tbody = document.getElementById('tbody-fatture');
        if (!_fatture.length) { tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><i class="ph ph-chart-line-up"></i><h3>Nessuna fattura</h3></div></td></tr>'; return; }
        tbody.innerHTML = _fatture.map(f => `<tr data-id="${f.id}">
            <td class="td-mono">${UI.esc(f.numero_fattura)}</td>
            <td>${UI.formatDate(f.data_emissione)}</td>
            <td class="td-primary">${UI.esc(f.cliente_nome||'—')}${f.sottocliente_nome?` <span style="color:var(--text-muted)">/ ${UI.esc(f.sottocliente_nome)}</span>`:''}</td>
            <td style="font-size:0.8rem;color:var(--text-muted)">${f.incarico_id?'<i class="ph ph-link" style="color:var(--accent-secondary)"></i> #'+f.incarico_id:'—'}</td>
            <td class="text-right">${UI.formatCurrency(f.imponibile)}</td>
            <td class="text-right td-primary">${UI.formatCurrency(f.importo_totale)}</td>
            <td>${UI.statoBadge(f.stato)}</td>
            <td>${f.data_scadenza?UI.formatDate(f.data_scadenza):'—'}</td>
            <td><div class="flex gap-2">
                <button class="btn btn-sm btn-ghost" onclick="ModContabilita.edit(${f.id})"><i class="ph ph-pencil-simple"></i></button>
                <button class="btn btn-sm btn-danger" onclick="ModContabilita.remove(${f.id})"><i class="ph ph-trash"></i></button>
            </div></td></tr>`).join('');
    }

    function getFormHtml(d={}) {
        const clienti = ModClienti.getClienti();
        const cOpts = clienti.map(c => `<option value="${c.id}" ${c.id==d.cliente_id?'selected':''}>${UI.esc(c.ragione_sociale)}</option>`).join('');
        const stati = ['emessa','inviata','pagata','scaduta'];
        const sOpts = stati.map(s => `<option value="${s}" ${s===(d.stato||'emessa')?'selected':''}>${s.charAt(0).toUpperCase()+s.slice(1)}</option>`).join('');
        return `<div class="form-grid">
            <div class="form-group"><label>Numero Fattura *</label><input type="text" class="form-control" id="f-f-numero" value="${UI.esc(d.numero_fattura||'')}" placeholder="es. 2026/001"></div>
            <div class="form-group"><label>Data Emissione *</label><input type="date" class="form-control" id="f-f-data" value="${d.data_emissione||new Date().toISOString().split('T')[0]}"></div>
            <div class="form-group"><label>Cliente</label><select class="form-control" id="f-f-cliente"><option value="">— Seleziona —</option>${cOpts}</select></div>
            <div class="form-group"><label>Sottocliente</label><select class="form-control" id="f-f-sottocliente"><option value="">— Nessuno —</option></select></div>
            <div class="form-group"><label>Incarico collegato</label><select class="form-control" id="f-f-incarico"><option value="">— Nessuno —</option></select></div>
            <div class="form-group"><label>Imponibile (€)</label><input type="number" class="form-control" id="f-f-imponibile" value="${d.imponibile||0}" step="0.01"></div>
            <div class="form-group"><label>IVA %</label><input type="number" class="form-control" id="f-f-iva" value="${d.iva_percentuale||22}" step="0.01"></div>
            <div class="form-group"><label>Stato</label><select class="form-control" id="f-f-stato">${sOpts}</select></div>
            <div class="form-group"><label>Data Scadenza</label><input type="date" class="form-control" id="f-f-scadenza" value="${d.data_scadenza||''}"></div>
            <div class="form-group"><label>Data Pagamento</label><input type="date" class="form-control" id="f-f-pagamento" value="${d.data_pagamento||''}"></div>
            <div class="form-group"><label>Metodo Pagamento</label><select class="form-control" id="f-f-metodo">
                <option value="" ${!d.metodo_pagamento?'selected':''}>—</option>
                <option value="bonifico" ${d.metodo_pagamento==='bonifico'?'selected':''}>Bonifico</option>
                <option value="carta" ${d.metodo_pagamento==='carta'?'selected':''}>Carta</option>
                <option value="contanti" ${d.metodo_pagamento==='contanti'?'selected':''}>Contanti</option>
            </select></div>
            <div class="form-group full-width"><label>Descrizione</label><textarea class="form-control" id="f-f-desc">${UI.esc(d.descrizione||'')}</textarea></div>
            <div class="form-group full-width"><label>Note</label><textarea class="form-control" id="f-f-note">${UI.esc(d.note||'')}</textarea></div>
        </div><input type="hidden" id="f-f-id" value="${d.id||''}">`;
    }

    function openNew() { UI.openModal('Nuova Fattura',getFormHtml(),saveFromForm); setTimeout(initClienteWatch,100); }
    function edit(id) { const f=_fatture.find(x=>x.id==id); if(!f)return; UI.openModal('Modifica Fattura',getFormHtml(f),saveFromForm); setTimeout(()=>{ initClienteWatch(); if(f.cliente_id){loadSottoclienti(f.cliente_id,f.sottocliente_id); loadIncarichi(f.cliente_id,f.incarico_id);} },100); }

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
                const residuo = (parseFloat(i.importo_totale)-parseFloat(i.importo_fatturato)).toFixed(2);
                const o = document.createElement('option');
                o.value = i.id;
                o.textContent = `${i.tipo_commessa.toUpperCase()} ${UI.formatDate(i.data_incarico)} — Residuo: €${residuo}${i.sottocliente_nome?' ('+i.sottocliente_nome+')':''}`;
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

    // ── Verifica Pagamenti ──
    async function loadVerifica() {
        const year = document.getElementById('contabilita-year').value;
        try {
            const ov = await Store.api('overview','incarichi',{year});
            const k = ov?.kpis||{};
            const nonPagate = ov?.fatture_non_pagate||[];
            document.getElementById('verifica-kpis').innerHTML = `
                <div class="kpi-card kpi-green"><div class="kpi-label">Totale Pagato</div><div class="kpi-value">${UI.formatCurrency(k.totale_pagato)}</div><div class="kpi-sub">${k.num_pagati||0} incarichi chiusi</div></div>
                <div class="kpi-card kpi-yellow"><div class="kpi-label">Fatturato Non Pagato</div><div class="kpi-value">${UI.formatCurrency(k.fatturato_non_pagato)}</div><div class="kpi-sub">da incassare</div></div>
                <div class="kpi-card kpi-red"><div class="kpi-label">Da Fatturare</div><div class="kpi-value">${UI.formatCurrency(k.residuo_da_fatturare)}</div><div class="kpi-sub">${k.num_attivi||0} incarichi attivi</div></div>
                <div class="kpi-card kpi-blue"><div class="kpi-label">% Incasso</div><div class="kpi-value">${k.totale_incarichi>0?((parseFloat(k.totale_pagato)/parseFloat(k.totale_incarichi))*100).toFixed(0):'0'}%</div><div class="kpi-sub">sul totale incarichi</div></div>`;

            const tbody = document.getElementById('tbody-verifica');
            if (!nonPagate.length) { tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><i class="ph ph-check-square"></i><h3>Tutto regolare</h3><p>Nessuna fattura in sospeso</p></div></td></tr>'; return; }
            const today = new Date();
            tbody.innerHTML = nonPagate.map(f => {
                const scad = f.data_scadenza ? new Date(f.data_scadenza) : null;
                const gg = scad ? Math.floor((today-scad)/(1000*60*60*24)) : null;
                const ggStyle = gg>0 ? 'color:#ef4444;font-weight:600' : 'color:var(--text-muted)';
                return `<tr><td class="td-mono">${UI.esc(f.numero_fattura)}</td><td>${UI.formatDate(f.data_emissione)}</td><td class="td-primary">${UI.esc(f.cliente_nome||'—')}${f.sottocliente_nome?` <span style="color:var(--text-muted)">/ ${UI.esc(f.sottocliente_nome)}</span>`:''}</td><td class="text-right td-primary">${UI.formatCurrency(f.importo_totale)}</td><td>${UI.statoBadge(f.stato)}</td><td>${f.data_scadenza?UI.formatDate(f.data_scadenza):'—'}</td><td style="${ggStyle}">${gg!==null?(gg>0?'+'+gg+'gg':gg+'gg'):'—'}</td></tr>`;
            }).join('');
        } catch(e) { console.error('[Verifica]',e); }
    }

    // ── Tab System ──
    function initTabs() {
        document.querySelectorAll('#contabilita-tabs .tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('#contabilita-tabs .tab').forEach(t => { t.style.color='var(--text-muted)'; t.style.borderBottomColor='transparent'; });
                tab.style.color='var(--accent-secondary)'; tab.style.borderBottomColor='var(--accent-secondary)';
                const target = tab.dataset.target;
                document.querySelectorAll('#view-contabilita .tab-content').forEach(c => { c.style.display='none'; c.classList.remove('active'); });
                const el = document.getElementById(target);
                if (el) { el.style.display=''; el.classList.add('active'); }
                _activeTab = target;
                load();
            });
        });
    }

    function initFilters() {
        UI.populateYearSelect('contabilita-year');
        document.getElementById('contabilita-year').addEventListener('change', load);
        // Fatture status filter
        document.querySelectorAll('#tab-fatture .filter-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('#tab-fatture .filter-chip').forEach(c=>c.classList.remove('active'));
                chip.classList.add('active'); _statoFilter = chip.dataset.stato||''; load();
            });
        });
        initTabs();
        ModIncarichi.initFilters();
    }

    async function importPdf(file) {
        if (!file||file.type!=='application/pdf'){UI.toast('Seleziona un file PDF valido','error');return;}
        const btn=document.getElementById('btn-import-pdf-fatture'); const prev=btn.innerHTML;
        btn.innerHTML='<i class="ph ph-spinner ph-spin"></i> Lettura PDF...'; btn.disabled=true;
        try {
            const ab=await file.arrayBuffer(); const pdf=await pdfjsLib.getDocument({data:ab}).promise;
            const pages=[];
            for(let i=1;i<=pdf.numPages;i++){const pg=await pdf.getPage(i);const tc=await pg.getTextContent();pages.push(tc.items.map(x=>x.str).join(' '));}
            btn.innerHTML='<i class="ph ph-spinner ph-spin"></i> Analisi...';
            const req=await Store.api('import_pdf','contabilita',{pages});
            if(req?.success){UI.toast(`Importazione: ${req.num_imported} fatture.`); if(req.errors?.length)UI.toast(`${req.errors.length} errori (console)`,'error'); load();}
            else throw new Error('Risposta anomala');
        }catch(e){UI.toast('Errore importazione PDF: '+e.message,'error');}
        finally{btn.innerHTML=prev;btn.disabled=false;}
    }

    async function importXml(files) {
        if(!Array.isArray(files))files=[files];
        const valid=files.filter(f=>f.type==='text/xml'||f.type==='application/xml'||f.name.endsWith('.xml'));
        if(!valid.length){UI.toast('Seleziona file XML validi','error');return;}
        const btn=document.getElementById('btn-import-xml-fatture'); const prev=btn.innerHTML; btn.disabled=true;
        let tot=0,errs=0;
        try {
            for(let i=0;i<valid.length;i++){
                btn.innerHTML=`<i class="ph ph-spinner ph-spin"></i> XML (${i+1}/${valid.length})...`;
                const text=await valid[i].text();
                const req=await Store.api('import_xml','contabilita',{xml:text});
                if(req?.success){tot+=req.num_imported||0; if(req.errors?.length)errs+=req.errors.length;}else errs++;
            }
            UI.toast(errs>0?`${tot} righe, ${errs} anomalie`:`${tot} righe importate`); load();
        }catch(e){UI.toast('Errore XML: '+e.message,'error');}
        finally{btn.innerHTML=prev;btn.disabled=false;}
    }

    async function importPaymentPdf(files) {
        if(!Array.isArray(files))files=[files];
        const valid=files.filter(f=>f.type==='application/pdf'||f.name.endsWith('.pdf'));
        if(!valid.length){UI.toast('Seleziona PDF validi','error');return;}
        const btn=document.getElementById('btn-import-payment-pdf'); const prev=btn.innerHTML; btn.disabled=true;
        let totalM=0,totalAP=0,totalNF=0,msgs=[];
        try {
            for(let i=0;i<valid.length;i++){
                const file=valid[i];
                btn.innerHTML=`<i class="ph ph-spinner ph-spin"></i> PDF (${i+1}/${valid.length})...`;
                const ab=await file.arrayBuffer(); const pdf=await pdfjsLib.getDocument({data:ab}).promise; const pages=[];
                for(let p=1;p<=pdf.numPages;p++){const pg=await pdf.getPage(p);const tc=await pg.getTextContent();pages.push(tc.items.map(x=>x.str).join(' '));}
                btn.innerHTML=`<i class="ph ph-spinner ph-spin"></i> Analisi (${i+1}/${valid.length})...`;
                const token=localStorage.getItem('erp_token'); const hdr={'Content-Type':'application/json'};
                if(token)hdr['Authorization']='Bearer '+token;
                const resp=await fetch('api/router.php',{method:'POST',headers:hdr,credentials:'include',body:JSON.stringify({module:'contabilita',action:'import_payment_pdf',pages})});
                const result=await resp.json(); const req=result.success?result.data:result;
                if(result.success){totalM+=req.num_matched||0;totalAP+=req.num_already_paid||0;totalNF+=req.num_not_found||0;if(req.messages?.length){msgs.push(`<strong>${file.name}</strong>`);msgs.push(...req.messages);msgs.push('');}}
                else msgs.push(`<strong style="color:var(--danger)">❌ ${file.name}</strong>: ${req?.message||'Errore'}`);
            }
            const html=`<div style="margin-bottom:16px;display:flex;gap:12px;flex-wrap:wrap">
                <div style="flex:1;min-width:120px;padding:12px 16px;background:rgba(16,185,129,0.1);border:1px solid rgba(16,185,129,0.2);border-radius:8px;text-align:center"><div style="font-size:1.8rem;font-weight:700;color:#10b981">${totalM}</div><div style="font-size:0.75rem;color:var(--text-muted)">Pagate ora</div></div>
                <div style="flex:1;min-width:120px;padding:12px 16px;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.2);border-radius:8px;text-align:center"><div style="font-size:1.8rem;font-weight:700;color:#6366f1">${totalAP}</div><div style="font-size:0.75rem;color:var(--text-muted)">Già pagate</div></div>
                <div style="flex:1;min-width:120px;padding:12px 16px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:8px;text-align:center"><div style="font-size:1.8rem;font-weight:700;color:#ef4444">${totalNF}</div><div style="font-size:0.75rem;color:var(--text-muted)">Non trovate</div></div>
            </div><div style="max-height:300px;overflow-y:auto;padding:12px;background:rgba(0,0,0,0.2);border-radius:8px;font-size:0.85rem;line-height:1.8">${msgs.map(m=>`<div>${m.startsWith('<strong')?m:UI.esc(m)}</div>`).join('')}</div>`;
            UI.openModal('Risultato Importazione Pagamenti',html,null);
            const saveBtn=document.getElementById('modal-save'); if(saveBtn)saveBtn.style.display='none';
            const cancelBtn=document.getElementById('modal-cancel'); if(cancelBtn)cancelBtn.textContent='Chiudi';
            if(totalM>0){UI.toast(`${totalM} fatture pagate!`);load();}
        }catch(e){UI.toast('Errore pagamenti: '+e.message,'error');}
        finally{btn.innerHTML=prev;btn.disabled=false;}
    }

    return { load, openNew, edit, remove, initFilters, importPdf, importXml, importPaymentPdf };
})();
window.ModContabilita = ModContabilita;
