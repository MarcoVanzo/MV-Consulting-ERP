'use strict';

/**
 * Modulo Trasferte — Gestione trasferte con KPI
 */
const ModTrasferte = (() => {
    let _trasferte = [];
    let _totali = {};

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
                document.getElementById('trasferte-costo-km').value = storedCosto;
            }

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
            km_bloccati: document.getElementById('f-t-km-bloccati').checked ? 1 : 0
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

        const btnSync = document.getElementById('btn-sync-google');
        if (btnSync) {
            btnSync.addEventListener('click', syncGoogle);
        }

        const btnCalcola = document.getElementById('btn-calcola-viaggi');
        if (btnCalcola) {
            btnCalcola.addEventListener('click', calcolaTuttiKm);
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

    return { load, openNew, edit, remove, initFilters, syncGoogle, calcolaKm, calcolaTuttiKm, togglePernottamento };
})();

window.ModTrasferte = ModTrasferte;
