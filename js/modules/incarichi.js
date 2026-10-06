'use strict';
const ModIncarichi = (() => {
    let _incarichi = [], _kpis = {}, _filter = '';

    async function load() {
        const year = UI.anno();
        try {
            const ov = await Store.api('overview', 'incarichi', { year });
            _kpis = ov?.kpis || {};
            renderKpis(ov);
            renderDaCollegare();
            renderGrafici(ov);
            const list = await Store.api('list', 'incarichi', { year });
            _incarichi = list || [];
            renderTable();
        } catch (e) { console.error('[Incarichi]', e); UI.toast('Errore caricamento incarichi','error'); }
    }

    function renderKpis(ov) {
        const k = _kpis;
        document.getElementById('incarichi-kpis').innerHTML = `
            <div class="kpi-card kpi-blue"><div class="kpi-label">Valore commesse</div><div class="kpi-value">${UI.formatCurrency(k.valore)}</div><div class="kpi-sub">imponibile · ${UI.plurale(k.num_commesse,'commessa','commesse')}</div></div>
            <div class="kpi-card kpi-green"><div class="kpi-label">Fatturato su commesse</div><div class="kpi-value">${UI.formatCurrency(k.fatturato)}</div><div class="kpi-sub">${UI.plurale(k.num_fatturati,'completamente fatturata','completamente fatturate')}</div></div>
            <div class="kpi-card kpi-yellow"><div class="kpi-label">Da fatturare</div><div class="kpi-value">${UI.formatCurrency(k.da_fatturare)}</div><div class="kpi-sub">${UI.plurale(k.num_da_fatturare,'commessa','commesse')}</div></div>
            ${margineKpi(k)}`;
    }

    // Grafici di Vendite (js/core/grafici.js): dove sta il valore, quanto resta da fatturare, numero contro valore
    function renderGrafici(ov) {
        const box = document.getElementById('incarichi-grafici');
        if (!box || !window.Grafici) return;
        const tipi = (ov?.per_tipo || []).map(t => ({ etichetta: UI.tipoCommessa(t.tipo_commessa), valore: parseFloat(t.totale) || 0,
            n: parseInt(t.conteggio) || 0 })).filter(t => t.valore > 0 || t.n > 0);
        const k = _kpis, valore = parseFloat(k.valore) || 0, daFatt = parseFloat(k.da_fatturare) || 0;
        box.innerHTML = [
            Grafici.impilata('Quanto resta da fatturare', `Sui ${UI.formatCurrency(valore)} di valore delle commesse del ${UI.anno()}`, [
                { etichetta: 'Coperto da fatture', valore: Math.max(0, valore - daFatt), colore: 'var(--accent-green)' },
                { etichetta: 'Da fatturare', valore: daFatt, colore: 'var(--accent-purple)' },
            ]),
            Grafici.barre('Dove sta il valore', 'Valore delle commesse per tipo', tipi.map(t => ({ ...t, nota: `${t.n} ${t.n === 1 ? 'commessa' : 'commesse'}` }))),
            Grafici.divergenti('Quante commesse e quanto valgono', 'Per tipo: quota sul numero delle commesse e sul valore', tipi,
                { sinistra: 'commesse', destra: 'valore' }),
        ].join('');
    }

    // Il margine dice qualcosa solo sulle commesse con i costi registrati: sulle altre sarebbe il 100%
    function margineKpi(k) {
        const n = k.num_con_costi || 0;
        if (!n) return `<div class="kpi-card kpi-purple"><div class="kpi-label">Margine previsto</div><div class="kpi-value">—</div><div class="kpi-sub">nessuna commessa ha costi partner registrati</div></div>`;
        const m = (parseFloat(k.valore_con_costi) || 0) - (parseFloat(k.costi_previsti) || 0);
        const pct = k.valore_con_costi > 0 ? Math.round(m / k.valore_con_costi * 100) : 0;
        return `<div class="kpi-card kpi-purple"><div class="kpi-label">Margine previsto</div><div class="kpi-value">${UI.formatCurrency(m)} <span class="kpi-pct">${pct}%</span></div>
            <div class="kpi-sub">su ${UI.plurale(n, 'commessa', 'commesse')} con costi registrati (${UI.formatCurrency(k.valore_con_costi)}); le altre ${k.num_commesse - n} non hanno costi</div></div>`;
    }

    // ── Fatture senza commessa: avviso sotto i KPI e finestra per collegarle ──
    function renderDaCollegare() {
        const box = document.getElementById('incarichi-da-collegare');
        const pt = _kpis.ponte || {};
        // Un solo valore, lo stesso del ponte (con i clienti esclusi della vista Fatture)
        const senza = parseFloat(pt.senza_commessa ?? _kpis.fatturato_senza_commessa) || 0;
        const fuori = parseFloat(pt.fuori_anno) || 0, altri = parseFloat(pt.commesse_altri_anni) || 0;
        const anno = UI.esc(UI.anno());
        // Ponte con Fatture: stesse fatture, ma qui si parte dalle commesse dell'anno. Ogni voce è un termine con il
        // suo segno (una nota di credito rende negativo anche un termine che di solito si somma)
        const voce = (v, testo) => Math.abs(v) < 0.01 ? '' : `<span class="ponte-voce"><b>${v < 0 ? '−' : '+'} ${UI.formatCurrency(Math.abs(v))}</b> ${testo}</span>`;
        const esclusi = parseInt(pt.num_esclusi) || 0;
        const ponte = pt.fatturato === undefined ? '' : `<div class="ponte"><span class="ponte-voce"><b>${UI.formatCurrency(pt.su_commesse)}</b> fatturati sulle commesse del ${anno}</span>
            ${voce(-fuori, fuori < 0 ? 'di note di credito emesse in altri anni' : 'emessi in altri anni')}
            ${voce(altri, 'su commesse di altri anni')}
            ${voce(senza, senza < 0 ? 'di note di credito senza commessa' : 'senza commessa')}
            <span class="ponte-voce ponte-tot">= <b>${UI.formatCurrency(pt.fatturato)}</b> imponibile in Fatture ${anno}${esclusi ? ` (senza ${UI.plurale(esclusi, 'cliente escluso', 'clienti esclusi')} dal conteggio)` : ''}</span></div>`;
        box.innerHTML = ponte + (Math.abs(senza) < 0.01 ? '' : `<div class="notice da-collegare"><span>${senza < 0 ? `<b>${UI.formatCurrency(-senza)}</b> di note di credito del ${anno} non sono collegati a una commessa.`
                : `<b>${UI.formatCurrency(senza)}</b> fatturati nel ${anno} non sono collegati a una commessa.`}</span>
            <button class="btn btn-sm btn-primary" type="button" id="btn-da-collegare"><i class="ph ph-link"></i> Collega alle commesse</button></div>`);
        document.getElementById('btn-da-collegare')?.addEventListener('click', apriCollega);
    }

    let _dc = { fatture: [], commesse: {} };

    async function apriCollega() {
        try { _dc = await Store.api('da_collegare', 'commesse', { year: UI.anno() }); }
        catch (e) { UI.toast(e.message || 'Errore caricamento', 'error'); return; }
        const fatture = _dc.fatture || [];
        const gruppi = {};
        fatture.forEach(f => { (gruppi[f.cliente_id || 0] ||= { nome: f.cliente_nome || 'Senza cliente', righe: [], tot: 0 }).righe.push(f); });
        Object.values(gruppi).forEach(g => { g.tot = g.righe.reduce((a, f) => a + (parseFloat(f.imponibile) || 0), 0); });
        const ordinati = Object.entries(gruppi).sort((a, b) => b[1].tot - a[1].tot);
        const totale = fatture.reduce((a, f) => a + (parseFloat(f.imponibile) || 0), 0);
        const opzioni = f => {
            const lista = _dc.commesse[f.cliente_id] || [];
            const prop = f.proposta?.incarico_id;
            return `<option value="">— nessuna —</option>` + lista.map(c => `<option value="${c.id}" ${c.id == prop ? 'selected' : ''}>${UI.esc(
                (c.descrizione || UI.tipoCommessa(c.tipo_commessa)).slice(0, 50))} · ${UI.formatDate(c.data_incarico)} · residuo ${UI.formatCurrency(c.residuo)}${c.id == prop ? ' (proposta)' : ''}</option>`).join('');
        };
        const riga = f => `<tr data-f="${f.id}" data-cli="${f.cliente_id || 0}" data-ric="${f.ricorrente ? 1 : 0}">
            <td><input type="checkbox" class="dc-sel" aria-label="Seleziona fattura ${UI.esc(f.numero_fattura)}"></td>
            <td class="td-mono">${UI.esc(f.numero_fattura)}<br><span style="color:var(--text-muted)">${UI.formatDate(f.data_emissione)}</span></td>
            <td class="dc-desc cella-titolo">${UI.esc((f.descrizione || '').replace(/\s+/g, ' ').slice(0, 90))}${f.ricorrente ? ' <span class="dc-tag">ricorrente</span>' : ''}</td>
            <td class="text-right">${UI.formatCurrency(f.imponibile)}</td>
            <td><select class="form-control dc-commessa">${opzioni(f)}</select>${f.proposta ? `<div class="dc-motivo">${UI.esc(f.proposta.motivo)}</div>` : ''}</td>
        </tr>`;
        const html = `<p class="dc-intro">${UI.plurale(fatture.length, 'fattura', 'fatture')} del ${UI.esc(UI.anno())} senza commessa, per ${UI.formatCurrency(totale)}.
            Conferma le proposte o scegli la commessa; se manca, seleziona le fatture di un cliente e creala qui sotto.</p>
            <div class="dc-tutte"><button class="btn btn-sm btn-secondary" type="button" id="dc-crea-tutte"><i class="ph ph-magic-wand"></i> Crea tutte le commesse mancanti</button>
                <span>Di tutti gli anni: collega le fatture con protocollo, offerta o rata corrispondente; per le altre crea una commessa per fattura
                (una sola a canone per le fatture che si ripetono). Le proposte incerte restano qui.</span></div>
            ${ordinati.map(([, g]) => `<h4 class="dc-cliente">${UI.esc(g.nome)} <span>${UI.formatCurrency(g.tot)}</span></h4>
                <div class="table-container"><table class="data-table dc-tabella tabella-schede"><thead><tr><th></th><th>Fattura</th><th>Descrizione</th>
                <th class="text-right">Imponibile</th><th>Commessa</th></tr></thead><tbody>${g.righe.map(riga).join('')}</tbody></table></div>`).join('')}
            <div class="dc-crea">
                <h4>Crea la commessa dalle fatture selezionate <span id="dc-sel-info"></span></h4>
                <div class="form-grid">
                    <div class="form-group"><label for="dc-tipo">Tipo</label><select class="form-control" id="dc-tipo">${UI.tipiCommessaOptions('altro')}</select></div>
                    <div class="form-group"><label for="dc-desc">Descrizione</label><input class="form-control" id="dc-desc" placeholder="es. Viaggio Volley giugno 2026"></div>
                    <div class="form-group"><label><input type="checkbox" id="dc-ric"> Canone ricorrente</label>
                        <input type="number" class="form-control" id="dc-mesi" value="12" min="1" max="60" aria-label="Mesi del canone" title="Mesi del canone"></div>
                    <div class="form-group" style="align-self:end"><button class="btn btn-secondary" type="button" id="dc-crea"><i class="ph ph-plus"></i> Crea commessa</button></div>
                </div>
            </div>`;
        UI.openModal('Fatture senza commessa', html, salvaCollegamenti, { wide: true, saveLabel: '<i class="ph ph-link"></i> Collega le scelte' });
        const body = document.getElementById('modal-body');
        const aggiornaSel = () => {
            const sel = [...body.querySelectorAll('.dc-sel:checked')].map(c => c.closest('tr'));
            const somma = sel.reduce((a, tr) => a + (parseFloat(fatture.find(f => f.id == tr.dataset.f)?.imponibile) || 0), 0);
            document.getElementById('dc-sel-info').textContent = sel.length ? `· ${UI.plurale(sel.length, 'fattura', 'fatture')}, ${UI.formatCurrency(somma)}` : '';
            if (sel.length && sel.every(tr => tr.dataset.ric === '1')) document.getElementById('dc-ric').checked = true;
        };
        body.querySelectorAll('.dc-sel').forEach(c => c.addEventListener('change', aggiornaSel));
        document.getElementById('dc-crea').addEventListener('click', creaCommessa);
        document.getElementById('dc-crea-tutte').addEventListener('click', creaTutte);
    }

    async function salvaCollegamenti() {
        const coppie = [...document.querySelectorAll('#modal-body tr[data-f]')]
            .map(tr => ({ fattura_id: tr.dataset.f, incarico_id: tr.querySelector('.dc-commessa').value }))
            .filter(c => c.incarico_id);
        if (!coppie.length) { UI.toast('Nessuna commessa scelta', 'error'); return; }
        try {
            const r = await Store.api('collega_commessa', 'commesse', { collegamenti: JSON.stringify(coppie) });
            UI.toast(r?.message || 'Fatture collegate');
            UI.closeModal();
            load();
        } catch (e) { UI.toast(e.message || 'Errore', 'error'); }
    }

    async function creaCommessa() {
        const righe = [...document.querySelectorAll('#modal-body .dc-sel:checked')].map(c => c.closest('tr'));
        if (!righe.length) { UI.toast('Seleziona le fatture della commessa', 'error'); return; }
        if (new Set(righe.map(tr => tr.dataset.cli)).size > 1) { UI.toast('Le fatture devono essere dello stesso cliente', 'error'); return; }
        try {
            await Store.api('crea_da_fatture', 'commesse', {
                fatture: JSON.stringify(righe.map(tr => tr.dataset.f)),
                tipo_commessa: document.getElementById('dc-tipo').value,
                descrizione: document.getElementById('dc-desc').value,
                ricorrente: document.getElementById('dc-ric').checked ? 1 : 0,
                mesi: document.getElementById('dc-mesi').value,
            });
            UI.toast('Commessa creata');
            await load();
            apriCollega();
        } catch (e) { UI.toast(e.message || 'Errore', 'error'); }
    }

    async function creaTutte(e) {
        e.currentTarget.disabled = true;
        try {
            const r = await Store.api('crea_mancanti', 'commesse', {});
            UI.toast(r?.messaggio || 'Commesse create');
            await load();
            if ((r?.da_scegliere || 0) > 0) apriCollega(); else UI.closeModal();
        } catch (err) { UI.toast(err.message || 'Errore', 'error'); e.currentTarget.disabled = false; }
    }

    function renderTable() {
        const tbody = document.getElementById('tbody-incarichi');
        let data = _incarichi;
        if (_filter) data = data.filter(i => _filter === 'fatturato' ? ['fatturato', 'pagato'].includes(i.stato) : i.stato === _filter);
        if (!data.length) { tbody.innerHTML = '<tr><td colspan="9"><div class="empty-state"><i class="ph ph-clipboard-text"></i><h3>Nessuna commessa</h3></div></td></tr>'; return; }

        const tipoBadge = t => {
            const colors = {assistenza:'#6366f1',dpo:'#f59e0b',formazione:'#10b981',nis2:'#ef4444',ict:'#0ea5e9',digital:'#ec4899',sviluppo_software:'#8b5cf6',viaggio:'#14b8a6',noleggio:'#a3a3a3',altro:'#64748b'};
            return `<span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;background:${colors[t]||'#666'}22;color:${colors[t]||'#666'}">${UI.esc(UI.tipoCommessa(t))}</span>`;
        };
        // In Vendite la commessa arriva fino alla fattura: l'incasso si segue in Fatture
        const STATI = { attivo: ['Da fatturare', '#3b82f6'], parziale: ['Fatturata in parte', '#f59e0b'], fatturato: ['Fatturata', '#10b981'], pagato: ['Fatturata', '#10b981'] };
        const statoBadge = s => {
            const [testo, c] = STATI[s] || ['—', '#666'];
            return `<span style="padding:2px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;background:${c}22;color:${c};white-space:nowrap">${testo}</span>`;
        };

        tbody.innerHTML = data.map(i => {
            const valore = parseFloat(i.importo_totale) || 0;
            const fatt = parseFloat(i.importo_fatturato) || 0;
            const pctF = Math.min(fatt / (valore || 1) * 100, 100).toFixed(0);
            const margine = valore - (parseFloat(i.costi_previsti) || 0);
            const cliente = UI.esc(i.cliente_nome||'—') + (i.sottocliente_nome ? ` <span style="color:var(--text-muted)">/ ${UI.esc(i.sottocliente_nome)}</span>` : '');
            const desc = i.descrizione ? `<div class="cella-desc" title="${UI.esc(i.descrizione)}">${UI.esc(i.descrizione)}</div>` : '';
            const conCosti = (parseFloat(i.costi_previsti) || 0) > 0;
            const protLabel = i.numero_protocollo ? `<span style="font-size:0.7rem;color:var(--accent-secondary);opacity:0.8" title="Protocollo"><i class="ph ph-hash"></i> ${UI.esc(i.numero_protocollo)}</span>` : '';
            return `<tr data-id="${UI.esc(i.id)}">
                <td>${protLabel}</td>
                <td class="td-primary cella-titolo">${cliente}${desc}</td>
                <td>${tipoBadge(i.tipo_commessa)}</td>
                <td>${UI.formatDate(i.data_incarico)}</td>
                <td class="text-right td-primary">${UI.formatCurrency(valore)}</td>
                <td class="text-right">${conCosti ? `${UI.formatCurrency(margine)}${valore > 0 ? ` <span style="color:var(--text-muted)">${Math.round(margine / valore * 100)}%</span>` : ''}` : '<span style="color:var(--text-muted)" title="Nessun costo partner registrato">—</span>'}</td>
                <td style="min-width:120px">
                    <div style="display:flex;align-items:center;gap:6px;font-size:0.7rem" title="${UI.esc(UI.formatCurrency(fatt))} fatturati">
                        <div style="flex:1;height:6px;background:rgba(255,255,255,0.08);border-radius:3px;overflow:hidden">
                            <div style="height:100%;width:${pctF}%;background:#6366f1;border-radius:3px;transition:width .3s"></div>
                        </div>
                        <span style="color:var(--text-muted);min-width:32px">${pctF}%</span>
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
        if (!confirm('Spostare la commessa nel cestino? Si può ripristinare.')) return;
        try { await Store.api('delete','incarichi',{id}); UI.toast('Commessa spostata nel cestino'); load(); }
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
