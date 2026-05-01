'use strict';

/**
 * Modulo Trasferte — Gestione trasferte con KPI
 */
const ModTrasferte = (() => {
    let _trasferte = [];
    let _totali = {};
    let _mezziCache = [];

    async function load() {
        const year = document.getElementById('trasferte-year').value;
        const month = document.getElementById('trasferte-month').value;
        try {
            const params = { year };
            if (month) params.month = month;
            const data = await Store.api('list', 'trasferte', params);
            _trasferte = data?.trasferte || [];
            _totali = data?.totali || {};
            
            // Sync costo km da localStorage prima del render
            const storedCosto = localStorage.getItem('trasferte_costo_km');
            if (storedCosto) {
                document.getElementById('trasferte-costo-km').value = parseFloat(storedCosto).toFixed(4);
            }

            // Precarica mezzi se non ancora in cache
            if (!_mezziCache.length) {
                try { _mezziCache = await Store.api('getAllVehicles', 'mezzi') || []; } catch(e) { _mezziCache = []; }
            }
            populateMezzoToolbar();
            renderKpis();
            renderTable();
        } catch (err) {
            console.error('[Trasferte] Load error:', err);
            UI.toast('Errore caricamento trasferte', 'error');
        }

        // Controlla URL per success dal callback Google
        if (window.location.href.includes('google_sync=success')) {
            UI.toast('Autenticazione Google avvenuta con successo! Clicca Sincronizza per scaricare i viaggi.', 'success');
            // Pulisci l'URL
            window.history.replaceState({}, document.title, window.location.pathname + '#view-trasferte');
        }
    }

    function renderKpis() {
        const costoKm = parseFloat(document.getElementById('trasferte-costo-km').value) || 0;
        let totIndennita = 0;
        
        // Calcoliamo in modo grezzo le indennità totali
        const grouped = {};
        _trasferte.forEach(t => {
            if (!grouped[t.data_trasferta]) grouped[t.data_trasferta] = false;
            if (t.cliente_id || t.sottocliente_id) grouped[t.data_trasferta] = true;
        });
        Object.values(grouped).forEach(hasClient => {
            if (hasClient) totIndennita += 46.48;
        });

        const costoKmTotale = (_totali.km_totali || 0) * costoKm;
        const totaleComplessivo = (_totali.totale_spese || 0) + costoKmTotale + totIndennita;

        document.getElementById('trasferte-kpis').innerHTML = `
            <div class="kpi-card kpi-blue">
                <div class="kpi-label">Trasferte</div>
                <div class="kpi-value">${_totali.num_trasferte || 0}</div>
            </div>
            <div class="kpi-card kpi-green">
                <div class="kpi-label">KM Totali</div>
                <div class="kpi-value">${UI.formatNumber(_totali.km_totali)}</div>
            </div>
            <div class="kpi-card kpi-yellow">
                <div class="kpi-label">Rimborso KM + Ind.</div>
                <div class="kpi-value">${UI.formatCurrency(costoKmTotale + totIndennita)}</div>
            </div>
            <div class="kpi-card kpi-red">
                <div class="kpi-label">Spese Extra</div>
                <div class="kpi-value">${UI.formatCurrency(_totali.totale_spese)}</div>
            </div>
        `;
    }

    function renderTable() {
        const tbody = document.getElementById('tbody-trasferte');
        if (!_trasferte.length) {
            tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><i class="ph ph-car-profile"></i><h3>Nessuna trasferta</h3><p>Aggiungi la prima trasferta</p></div></td></tr>`;
            return;
        }

        const grouped = {};
        _trasferte.forEach(t => {
            if (!grouped[t.data_trasferta]) {
                grouped[t.data_trasferta] = {
                    data: t.data_trasferta,
                    mattina: { nome: '', id: null },
                    pomeriggio: { nome: '', id: null },
                    extra: [], // Per eventi aggiuntivi oltre 2 nella stessa giornata
                    km_totali: 0,
                    has_client: false,
                    pernottamento: false
                };
            }
            if (t.pernottamento == 1 || t.pernottamento == true) grouped[t.data_trasferta].pernottamento = true;
            
            const nome = t.sottocliente_nome ? UI.esc(t.sottocliente_nome) : (t.cliente_nome ? UI.esc(t.cliente_nome) : '');
            if (nome) grouped[t.data_trasferta].has_client = true;
            
            const displayName = nome || '';
            const entry = { nome: displayName, id: t.id };
            
            if (t.fascia_oraria === 'mattino') {
                if (!grouped[t.data_trasferta].mattina.id) {
                    grouped[t.data_trasferta].mattina = entry;
                } else {
                    grouped[t.data_trasferta].extra.push({ ...entry, fascia: 'mattino' });
                }
            } else if (t.fascia_oraria === 'pomeriggio') {
                if (!grouped[t.data_trasferta].pomeriggio.id) {
                    grouped[t.data_trasferta].pomeriggio = entry;
                } else {
                    grouped[t.data_trasferta].extra.push({ ...entry, fascia: 'pomeriggio' });
                }
            } else {
                // "intera" → riempi prima mattina, poi pomeriggio, poi extra
                if (!grouped[t.data_trasferta].mattina.id) {
                    grouped[t.data_trasferta].mattina = entry;
                } else if (!grouped[t.data_trasferta].pomeriggio.id) {
                    grouped[t.data_trasferta].pomeriggio = entry;
                } else {
                    grouped[t.data_trasferta].extra.push({ ...entry, fascia: 'intera' });
                }
            }
            
            grouped[t.data_trasferta].km_totali += (parseFloat(t.km_andata || 0) + parseFloat(t.km_ritorno || 0));
        });

        const rows = Object.values(grouped).sort((a, b) => b.data.localeCompare(a.data));
        const costoKm = parseFloat(document.getElementById('trasferte-costo-km').value) || 0;

        tbody.innerHTML = rows.map(g => {
            const indennita = g.has_client ? 46.48 : 0;
            const rimborsoTotale = (g.km_totali * costoKm) + indennita;
            
            const nameMattina = g.mattina.id ? (g.mattina.nome || '<span style="color: var(--danger); font-size: 0.8rem; font-weight: 500;">Da assegnare</span>') : '';
            const namePomeriggio = g.pomeriggio.id ? (g.pomeriggio.nome || '<span style="color: var(--danger); font-size: 0.8rem; font-weight: 500;">Da assegnare</span>') : '';
            
            return `
            <tr>
                <td>${UI.formatDate(g.data)}</td>
                <td class="td-primary">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <span style="flex: 1;">${nameMattina}</span>
                        ${g.mattina.id ? `
                        <div class="flex gap-1" style="flex-shrink: 0;">
                            <button class="btn btn-sm btn-ghost" style="padding: 2px" onclick="ModTrasferte.edit(${g.mattina.id})"><i class="ph ph-pencil-simple"></i></button>
                            <button class="btn btn-sm btn-danger" style="padding: 2px" onclick="ModTrasferte.remove(${g.mattina.id})"><i class="ph ph-trash"></i></button>
                        </div>` : ''}
                    </div>
                </td>
                <td class="td-primary">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <span style="flex: 1;">${namePomeriggio}</span>
                        ${g.pomeriggio.id && g.pomeriggio.id !== g.mattina.id ? `
                        <div class="flex gap-1" style="flex-shrink: 0;">
                            <button class="btn btn-sm btn-ghost" style="padding: 2px" onclick="ModTrasferte.edit(${g.pomeriggio.id})"><i class="ph ph-pencil-simple"></i></button>
                            <button class="btn btn-sm btn-danger" style="padding: 2px" onclick="ModTrasferte.remove(${g.pomeriggio.id})"><i class="ph ph-trash"></i></button>
                        </div>` : ''}
                    </div>
                </td>
                <td class="text-right">${UI.formatNumber(g.km_totali)}</td>
                <td class="text-right">${UI.formatCurrency(indennita)}</td>
                <td class="text-right fw-600">${UI.formatCurrency(rimborsoTotale)}</td>
                <td>
                    <div class="flex gap-2 justify-end" style="align-items: center;">
                        <button type="button" class="btn btn-sm ${g.pernottamento ? 'btn-primary' : 'btn-ghost'}" style="margin-right: 10px; display: flex; align-items: center; gap: 6px; ${g.pernottamento ? 'box-shadow: 0 0 8px var(--accent);' : ''}" title="Dormo fuori" onclick="ModTrasferte.togglePernottamento('${g.data}', ${!g.pernottamento}, this)">
                            <i class="ph ${g.pernottamento ? 'ph-moon-stars' : 'ph-moon'}"></i> Dormo fuori
                        </button>
                        <button class="btn btn-sm btn-ghost" title="Calcola KM per questa giornata" onclick="ModTrasferte.calcolaKm('${g.data}')"><i class="ph ph-map-pin-line"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    function getFormHtml(data = {}) {
        const clienti = ModClienti.getClienti();
        const clientiOpts = clienti.map(c => `<option value="${c.id}" ${c.id == data.cliente_id ? 'selected' : ''}>${UI.esc(c.ragione_sociale)}</option>`).join('');

        return `
            <div class="form-grid">
                <div class="form-group">
                    <label>Data *</label>
                    <input type="date" class="form-control" id="f-t-data" value="${data.data_trasferta || new Date().toISOString().split('T')[0]}">
                </div>
                <div class="form-group">
                    <label>Fascia Oraria</label>
                    <select class="form-control" id="f-t-fascia">
                        <option value="intera" ${data.fascia_oraria === 'intera' ? 'selected' : ''}>Intera Giornata</option>
                        <option value="mattino" ${data.fascia_oraria === 'mattino' ? 'selected' : ''}>Mattino</option>
                        <option value="pomeriggio" ${data.fascia_oraria === 'pomeriggio' ? 'selected' : ''}>Pomeriggio</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Cliente</label>
                    <select class="form-control" id="f-t-cliente">
                        <option value="">— Seleziona —</option>
                        ${clientiOpts}
                    </select>
                </div>
                <div class="form-group">
                    <label>Sottocliente</label>
                    <select class="form-control" id="f-t-sottocliente">
                        <option value="">— Nessuno —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Destinazione</label>
                    <input type="text" class="form-control" id="f-t-luogo" value="${UI.esc(data.luogo_arrivo || '')}">
                </div>
                <div class="form-group">
                    <label>KM Andata</label>
                    <input type="number" class="form-control" id="f-t-km-andata" value="${data.km_andata || 0}" step="0.1">
                </div>
                <div class="form-group">
                    <label>KM Ritorno</label>
                    <input type="number" class="form-control" id="f-t-km-ritorno" value="${data.km_ritorno || 0}" step="0.1">
                </div>

                <div class="form-group">
                    <label>Vitto</label>
                    <input type="number" class="form-control" id="f-t-vitto" value="${data.vitto || 0}" step="0.01">
                </div>
                <div class="form-group">
                    <label>Alloggio</label>
                    <input type="number" class="form-control" id="f-t-alloggio" value="${data.alloggio || 0}" step="0.01">
                </div>
                <div class="form-group full-width" style="display: flex; gap: 20px; align-items: center; margin-top: 10px;">
                    <input type="hidden" id="f-t-pernottamento" value="${data.pernottamento || 0}">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="f-t-km-bloccati" ${data.km_bloccati == 1 ? 'checked' : ''}> Blocca Ricalcolo KM (valori manuali)
                    </label>
                </div>
                <div class="form-group full-width">
                    <label>Descrizione / Note</label>
                    <textarea class="form-control" id="f-t-desc">${UI.esc(data.descrizione || '')}</textarea>
                </div>
            </div>
            <input type="hidden" id="f-t-id" value="${data.id || ''}">
        `;
    }

    function openNew() {
        UI.openModal('Nuova Trasferta', getFormHtml(), saveFromForm);
        setTimeout(() => {
            initClienteWatch();
            initKmWatch();
        }, 100);
    }

    function edit(id) {
        const t = _trasferte.find(x => x.id == id);
        if (!t) return;
        UI.openModal('Modifica Trasferta', getFormHtml(t), saveFromForm);
        setTimeout(() => {
            initClienteWatch();
            initKmWatch();
            if (t.cliente_id) loadSottoclienti(t.cliente_id, t.sottocliente_id);
        }, 100);
    }

    function initKmWatch() {
        const andata = document.getElementById('f-t-km-andata');
        const ritorno = document.getElementById('f-t-km-ritorno');
        const bloccati = document.getElementById('f-t-km-bloccati');
        if (!andata || !ritorno || !bloccati) return;

        const setBloccato = () => { bloccati.checked = true; };
        andata.addEventListener('input', setBloccato);
        ritorno.addEventListener('input', setBloccato);
    }

    function initClienteWatch() {
        const sel = document.getElementById('f-t-cliente');
        if (!sel) return;
        sel.addEventListener('change', () => {
            loadSottoclienti(sel.value);
        });
    }

    async function loadSottoclienti(clienteId, selectedId) {
        const sel = document.getElementById('f-t-sottocliente');
        sel.innerHTML = '<option value="">— Nessuno —</option>';
        if (!clienteId) return;
        try {
            const subs = await Store.api('list', 'sottoclienti', { cliente_id: clienteId });
            if (subs && subs.length) {
                subs.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.nome;
                    if (s.id == selectedId) opt.selected = true;
                    sel.appendChild(opt);
                });
            }
        } catch (err) {
            // ignore
        }
    }

    function populateMezzoToolbar() {
        const sel = document.getElementById('trasferte-mezzo');
        if (!sel) return;
        // Preserve only the first placeholder option
        sel.innerHTML = '<option value="">\u2014 Nessun mezzo \u2014</option>';
        _mezziCache.forEach(m => {
            if (m.stato !== 'attivo') return;
            const opt = document.createElement('option');
            opt.value = m.id;
            opt.textContent = `${m.nome} (${m.targa})`;
            sel.appendChild(opt);
        });
        // Auto-detect mezzo from current trasferte (use the first one found)
        const currentMezzo = _trasferte.find(t => t.mezzo_id);
        if (currentMezzo) {
            sel.value = currentMezzo.mezzo_id;
        }
    }

    async function updateMezzoForAll(mezzoId) {
        if (!_trasferte.length) {
            UI.toast('Nessuna trasferta da aggiornare', 'error');
            return;
        }
        const mezzo = _mezziCache.find(m => m.id == mezzoId);
        const label = mezzo ? `${mezzo.nome} (${mezzo.targa})` : 'nessun mezzo';
        if (!confirm(`Assegnare "${label}" a tutte le ${_trasferte.length} trasferte visualizzate?`)) {
            // Revert select
            const currentMezzo = _trasferte.find(t => t.mezzo_id);
            document.getElementById('trasferte-mezzo').value = currentMezzo?.mezzo_id || '';
            return;
        }
        try {
            // Update mezzo_id for ALL currently displayed trasferte
            await Promise.all(_trasferte.map(t =>
                Store.api('save', 'trasferte', {
                    id: t.id,
                    data_trasferta: t.data_trasferta,
                    fascia_oraria: t.fascia_oraria,
                    cliente_id: t.cliente_id,
                    sottocliente_id: t.sottocliente_id,
                    luogo_arrivo: t.luogo_arrivo,
                    km_andata: t.km_andata,
                    km_ritorno: t.km_ritorno,
                    vitto: t.vitto,
                    alloggio: t.alloggio,
                    descrizione: t.descrizione,
                    pernottamento: t.pernottamento,
                    km_bloccati: t.km_bloccati,
                    mezzo_id: mezzoId || null
                })
            ));
            // Update local cache
            _trasferte.forEach(t => t.mezzo_id = mezzoId || null);
            UI.toast(mezzoId ? `Mezzo ${mezzo?.nome || ''} assegnato a tutte le trasferte` : 'Mezzo rimosso da tutte le trasferte');
        } catch (err) {
            console.error('[Trasferte] updateMezzoForAll error:', err);
            UI.toast('Errore aggiornamento mezzo', 'error');
            load();
        }
    }

    async function saveFromForm() {
        const payload = {
            id: document.getElementById('f-t-id').value || undefined,
            data_trasferta: document.getElementById('f-t-data').value,
            fascia_oraria: document.getElementById('f-t-fascia').value,
            cliente_id: document.getElementById('f-t-cliente').value,
            sottocliente_id: document.getElementById('f-t-sottocliente').value,
            luogo_arrivo: document.getElementById('f-t-luogo').value,
            km_andata: document.getElementById('f-t-km-andata').value,
            km_ritorno: document.getElementById('f-t-km-ritorno').value,

            vitto: document.getElementById('f-t-vitto').value,
            alloggio: document.getElementById('f-t-alloggio').value,
            descrizione: document.getElementById('f-t-desc').value,
            pernottamento: parseInt(document.getElementById('f-t-pernottamento').value) || 0,
            km_bloccati: document.getElementById('f-t-km-bloccati').checked ? 1 : 0,
            mezzo_id: document.getElementById('trasferte-mezzo').value || null
        };
        try {
            await Store.api('save', 'trasferte', payload);
            UI.closeModal();
            UI.toast(payload.id ? 'Trasferta aggiornata' : 'Trasferta creata');
            load();
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    async function remove(id) {
        if (!confirm('Eliminare questa trasferta?')) return;
        try {
            await Store.api('delete', 'trasferte', { id });
            UI.toast('Trasferta eliminata');
            load();
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    function initFilters() {
        UI.populateYearSelect('trasferte-year');
        // Seleziona "Tutti i mesi" di default per vedere l'intero anno come richiesto
        document.getElementById('trasferte-month').value = '';

        document.getElementById('trasferte-year').addEventListener('change', load);
        document.getElementById('trasferte-month').addEventListener('change', load);
        
        const costoKmInput = document.getElementById('trasferte-costo-km');
        if (costoKmInput) {
            costoKmInput.addEventListener('input', () => {
                localStorage.setItem('trasferte_costo_km', costoKmInput.value);
                renderKpis();
                renderTable();
            });
        }

        const mezzoSelect = document.getElementById('trasferte-mezzo');
        if (mezzoSelect) {
            mezzoSelect.addEventListener('change', () => updateMezzoForAll(mezzoSelect.value));
        }

        const btnSync = document.getElementById('btn-sync-google');
        if (btnSync) {
            btnSync.addEventListener('click', syncGoogle);
        }

        const btnCalcola = document.getElementById('btn-calcola-viaggi');
        if (btnCalcola) {
            btnCalcola.addEventListener('click', calcolaTuttiKm);
        }

        const btnExportPdf = document.getElementById('btn-export-pdf-trasferte');
        if (btnExportPdf) {
            btnExportPdf.addEventListener('click', exportPdf);
        }

        // Setup sub-tabs
        const viewTrasferte = document.getElementById('view-trasferte');
        if (viewTrasferte) {
            const tabs = viewTrasferte.querySelectorAll('.tab');
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    // Remove active from all tabs
                    tabs.forEach(t => {
                        t.classList.remove('active');
                        t.style.color = 'var(--text-muted)';
                        t.style.borderBottom = '2px solid transparent';
                    });
                    
                    // Hide all content
                    viewTrasferte.querySelectorAll('.tab-content').forEach(c => {
                        c.classList.remove('active');
                        c.classList.add('hidden');
                        c.style.display = 'none';
                    });
                    
                    // Activate clicked tab
                    tab.classList.add('active');
                    tab.style.color = 'var(--accent-secondary)';
                    tab.style.borderBottom = '2px solid var(--accent-secondary)';
                    
                    const targetId = tab.getAttribute('data-target');
                    const targetContent = document.getElementById(targetId);
                    if (targetContent) {
                        targetContent.classList.remove('hidden');
                        targetContent.classList.add('active');
                        targetContent.style.display = 'block';
                    }

                    // Trigger initialize for Mezzi if needed
                    if (targetId === 'trasferte-mezzi' && window.ModMezzi) {
                        ModMezzi.init();
                    }
                });
            });
        }
    }

    async function syncGoogle() {
        const btnSync = document.getElementById('btn-sync-google');
        try {
            if (btnSync) btnSync.classList.add('loading');
            const data = await Store.api('sync', 'google');
            
            if (data?.auth_required) {
                // Auth necessaria, avvio flow oauth
                const authData = await Store.api('auth', 'google');
                if (authData && authData.url) {
                    window.location.href = authData.url;
                }
                return;
            }

            UI.toast(data?.message || `Sincronizzazione completata: ${data?.imported || 0} aggiornati.`, 'success');
            load();
        } catch (err) {
            UI.toast(err.message || 'Errore durante la sincronizzazione con Google Calendar', 'error');
        } finally {
            if (btnSync) btnSync.classList.remove('loading');
        }
    }

    async function calcolaKm(date) {
        try {
            // Simuliamo stato di loading 
            const data = await Store.api('calcolaKmGiorno', 'trasferte', { data: date });
            UI.toast(data?.message || 'Calcolo KM effettuato con successo', 'success');
            load();
        } catch (err) {
            UI.toast(err.message || 'Non è stato possibile calcolare i KM per questa giornata', 'error');
        }
    }

    async function togglePernottamento(date, state, btn) {
        if (btn) {
            btn.classList.toggle('btn-primary');
            btn.classList.toggle('btn-ghost');
            btn.style.pointerEvents = 'none';
            btn.style.opacity = '0.7';
            const icon = btn.querySelector('i');
            if (icon) {
                icon.classList.toggle('ph-moon-stars');
                icon.classList.toggle('ph-moon');
            }
        }
        try {
            const data = await Store.api('togglePernottamento', 'trasferte', { data: date, state: state ? 1 : 0 });
            UI.toast(data?.message || 'Stato pernottamento aggiornato', 'success');
            load();
        } catch (err) {
            UI.toast(err.message || 'Errore aggiornamento', 'error');
            load();
        }
    }

    async function calcolaTuttiKm() {
        const year = document.getElementById('trasferte-year').value;
        const month = document.getElementById('trasferte-month').value;
        const btn = document.getElementById('btn-calcola-viaggi');
        
        if (!confirm('Ricalcolare i chilometri per tutte le trasferte non bloccate del periodo selezionato?')) return;

        try {
            if (btn) btn.classList.add('loading');
            const params = { year };
            if (month) params.month = month;
            
            const data = await Store.api('calcolaTuttiKm', 'trasferte', params);
            UI.toast(data?.message || 'Calcolo di tutti i viaggi terminato', 'success');
            load();
        } catch (err) {
            UI.toast(err.message || 'Errore nel calcolo viaggi', 'error');
        } finally {
            if (btn) btn.classList.remove('loading');
        }
    }

    function exportPdf() {
        const year = document.getElementById('trasferte-year').value;
        const month = document.getElementById('trasferte-month').value;
        const costoKm = parseFloat(document.getElementById('trasferte-costo-km').value) || 0;

        if (!_trasferte.length) {
            UI.toast('Nessuna trasferta da esportare nel periodo selezionato', 'error');
            return;
        }

        // Group by date (same logic as renderTable)
        const grouped = {};
        _trasferte.forEach(t => {
            if (!grouped[t.data_trasferta]) {
                grouped[t.data_trasferta] = {
                    data: t.data_trasferta,
                    mattina: '',
                    pomeriggio: '',
                    km_totali: 0,
                    has_client: false,
                    pernottamento: false,
                    vitto: 0,
                    alloggio: 0
                };
            }
            if (t.pernottamento == 1 || t.pernottamento == true) grouped[t.data_trasferta].pernottamento = true;

            const nome = t.sottocliente_nome || t.cliente_nome || '';
            if (nome) grouped[t.data_trasferta].has_client = true;

            if (t.fascia_oraria === 'mattino') {
                grouped[t.data_trasferta].mattina = grouped[t.data_trasferta].mattina || nome;
            } else if (t.fascia_oraria === 'pomeriggio') {
                grouped[t.data_trasferta].pomeriggio = grouped[t.data_trasferta].pomeriggio || nome;
            } else {
                if (!grouped[t.data_trasferta].mattina) grouped[t.data_trasferta].mattina = nome;
                else if (!grouped[t.data_trasferta].pomeriggio) grouped[t.data_trasferta].pomeriggio = nome;
            }

            grouped[t.data_trasferta].km_totali += (parseFloat(t.km_andata || 0) + parseFloat(t.km_ritorno || 0));
            grouped[t.data_trasferta].vitto += parseFloat(t.vitto || 0);
            grouped[t.data_trasferta].alloggio += parseFloat(t.alloggio || 0);
        });

        const rows = Object.values(grouped).sort((a, b) => a.data.localeCompare(b.data));

        let totKm = 0, totIndennita = 0, totRimborsoKm = 0, totVitto = 0, totAlloggio = 0, totTotale = 0;

        const tableRows = rows.map(g => {
            const indennita = g.has_client ? 46.48 : 0;
            const rimborsoKm = g.km_totali * costoKm;
            const totaleRiga = rimborsoKm + indennita + g.vitto + g.alloggio;

            totKm += g.km_totali;
            totIndennita += indennita;
            totRimborsoKm += rimborsoKm;
            totVitto += g.vitto;
            totAlloggio += g.alloggio;
            totTotale += totaleRiga;

            const d = new Date(g.data);
            const dataFmt = d.toLocaleDateString('it-IT', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });

            return `<tr>
                <td>${dataFmt}</td>
                <td>${g.mattina || '—'}</td>
                <td>${g.pomeriggio || '—'}</td>
                <td class="num">${g.km_totali.toFixed(1)}</td>
                <td class="num">${indennita.toFixed(2)} €</td>
                <td class="num">${rimborsoKm.toFixed(2)} €</td>
                <td class="num">${g.vitto.toFixed(2)} €</td>
                <td class="num">${g.alloggio.toFixed(2)} €</td>
                <td class="num tot">${totaleRiga.toFixed(2)} €</td>
            </tr>`;
        }).join('');

        const mesi = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno','Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre'];
        const periodo = month ? `${mesi[parseInt(month) - 1]} ${year}` : `Anno ${year}`;

        const html = `<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Trasferte — ${periodo} — MV Consulting</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1a1a1a; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; border-bottom: 2px solid #0B0E14; padding-bottom: 12px; }
        .header h1 { font-size: 18px; font-weight: 700; }
        .header .sub { font-size: 12px; color: #666; margin-top: 4px; }
        .header .info { text-align: right; font-size: 11px; color: #666; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th { background: #0B0E14; color: #fff; padding: 8px 6px; text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; }
        td { padding: 6px; border-bottom: 1px solid #e0e0e0; font-size: 11px; }
        tr:nth-child(even) { background: #f7f7f7; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .tot { font-weight: 700; }
        .footer-row td { background: #0B0E14; color: #fff; font-weight: 700; font-size: 11px; border: none; }
        .summary { display: flex; gap: 24px; margin-top: 12px; padding: 12px; background: #f0f0f0; border-radius: 4px; }
        .summary-item { text-align: center; }
        .summary-item .label { font-size: 10px; color: #666; text-transform: uppercase; }
        .summary-item .value { font-size: 16px; font-weight: 700; margin-top: 2px; }
        .footer-note { margin-top: 20px; font-size: 10px; color: #999; text-align: center; }
        @media print {
            body { padding: 0; }
            @page { size: landscape; margin: 12mm; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1>MV Consulting S.r.l.</h1>
            <div class="sub">Riepilogo Trasferte — ${periodo}</div>
        </div>
        <div class="info">
            Costo KM: ${costoKm.toFixed(4)} €/km<br>
            Indennità giornaliera: 46,48 €<br>
            ${(() => { const m = _trasferte.find(t => t.mezzo_id); const mezzo = m ? _mezziCache.find(v => v.id == m.mezzo_id) : null; return mezzo ? `Mezzo: ${mezzo.nome} (${mezzo.targa})<br>` : ''; })()}
            Stampato il: ${new Date().toLocaleDateString('it-IT')}
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Mattina</th>
                <th>Pomeriggio</th>
                <th style="text-align:right">KM</th>
                <th style="text-align:right">Indennità</th>
                <th style="text-align:right">Rimb. KM</th>
                <th style="text-align:right">Vitto</th>
                <th style="text-align:right">Alloggio</th>
                <th style="text-align:right">Totale</th>
            </tr>
        </thead>
        <tbody>
            ${tableRows}
            <tr class="footer-row">
                <td colspan="3">TOTALE (${rows.length} giornate)</td>
                <td class="num">${totKm.toFixed(1)}</td>
                <td class="num">${totIndennita.toFixed(2)} €</td>
                <td class="num">${totRimborsoKm.toFixed(2)} €</td>
                <td class="num">${totVitto.toFixed(2)} €</td>
                <td class="num">${totAlloggio.toFixed(2)} €</td>
                <td class="num">${totTotale.toFixed(2)} €</td>
            </tr>
        </tbody>
    </table>
    <div class="footer-note">Documento generato automaticamente da MV Consulting ERP</div>
</body>
</html>`;

        // Usa iframe nascosto per evitare il popup blocker
        const existing = document.getElementById('pdf-print-frame');
        if (existing) existing.remove();

        const iframe = document.createElement('iframe');
        iframe.id = 'pdf-print-frame';
        iframe.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:0;height:0;border:none;';
        document.body.appendChild(iframe);

        const doc = iframe.contentDocument || iframe.contentWindow.document;
        doc.open();
        doc.write(html);
        doc.close();

        iframe.onload = () => {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (e) {
                // Fallback: apri in nuova tab
                const blob = new Blob([html], { type: 'text/html' });
                const url = URL.createObjectURL(blob);
                window.open(url, '_blank');
                setTimeout(() => URL.revokeObjectURL(url), 5000);
            }
            // Cleanup dopo 2 secondi
            setTimeout(() => iframe.remove(), 2000);
        };
    }

    return { load, openNew, edit, remove, initFilters, syncGoogle, calcolaKm, calcolaTuttiKm, togglePernottamento, exportPdf, updateMezzoForAll };
})();

window.ModTrasferte = ModTrasferte;
