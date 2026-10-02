'use strict';

/**
 * Modulo Trasferte — Gestione trasferte con KPI
 */
const ModTrasferte = (() => {
    let _trasferte = [];
    let _totali = {};
    let _giornate = {};
    let _mezziCache = [];
    let _richiesta = 0;
    const DA_ASSEGNARE = '<span style="color: var(--danger); font-size: 0.8rem; font-weight: 500;">Da assegnare</span>';

    async function load(opts = {}) {
        // Entrando nella vista si ricaricano i mezzi (possono essere cambiati in "Gestione Mezzi")
        if (opts.refreshMezzi === true) _mezziCache = [];
        const year = document.getElementById('trasferte-year').value;
        const month = document.getElementById('trasferte-month').value;
        // Cambi di filtro ravvicinati: vale solo la risposta dell'ultima richiesta
        const richiesta = ++_richiesta;
        try {
            const params = { year };
            if (month) params.month = month;
            const data = await Store.api('list', 'trasferte', params);
            if (richiesta !== _richiesta) return;
            _trasferte = data?.trasferte || [];
            _totali = data?.totali || {};
            _giornate = data?.giornate || {};
            await syncCostoKm(data?.costo_km);

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

    /**
     * Costo al km: sta sul server, uguale per tutti i browser. Se il server non ce l'ha ancora
     * e questo browser ne aveva uno nel localStorage (vecchia versione), lo si porta sul server.
     */
    async function syncCostoKm(costoServer) {
        const input = document.getElementById('trasferte-costo-km');
        let costo = costoServer;
        if (costo === null || costo === undefined) {
            const locale = localStorage.getItem('trasferte_costo_km');
            if (locale && !isNaN(parseFloat(locale))) {
                try {
                    const r = await Store.api('salvaCostoKm', 'trasferte', { costo_km: parseFloat(locale) });
                    costo = r?.costo_km ?? parseFloat(locale);
                    localStorage.removeItem('trasferte_costo_km');
                } catch (e) { costo = parseFloat(locale); }
            }
        } else {
            localStorage.removeItem('trasferte_costo_km');
        }
        if (costo !== null && costo !== undefined && document.activeElement !== input) {
            input.value = parseFloat(costo).toFixed(4);
        }
    }

    let _timerCosto = null;
    function salvaCostoKmDifferito() {
        clearTimeout(_timerCosto);
        _timerCosto = setTimeout(async () => {
            const v = parseFloat(document.getElementById('trasferte-costo-km').value);
            if (isNaN(v)) return;
            try {
                await Store.api('salvaCostoKm', 'trasferte', { costo_km: v });
            } catch (err) {
                UI.toast(err.message || 'Costo al km non salvato', 'error');
            }
        }, 700);
    }

    /** Rimborso km di una trasferta: costo ACI del mezzo se c'è, altrimenti il costo al km generale. */
    function rimborsoKmDi(t) {
        const km = parseFloat(t.km_andata || 0) + parseFloat(t.km_ritorno || 0);
        const costo = t.mezzo_costo_km !== null && t.mezzo_costo_km !== undefined && t.mezzo_costo_km !== ''
            ? parseFloat(t.mezzo_costo_km) : (parseFloat(document.getElementById('trasferte-costo-km').value) || 0);
        return km * costo;
    }

    /** Indennità di una giornata: la calcola il server (TrasferteRegole), qui si legge soltanto */
    function indennitaDi(data) {
        return parseFloat(_giornate[data]?.indennita) || 0;
    }

    /**
     * Trasferte raggruppate per giornata. Mattina: fascia mattino; pomeriggio: fascia pomeriggio;
     * le giornate intere vanno nella colonna meno piena. Nessuna trasferta resta fuori.
     */
    function raggruppa() {
        const grouped = {};
        _trasferte.forEach(t => {
            const g = grouped[t.data_trasferta] ??= {
                data: t.data_trasferta, mattina: [], pomeriggio: [], km_totali: 0,
                vitto: 0, alloggio: 0, altre: 0, aziendali: 0, rimborso_km: 0, pernottamento: false, destinazioni: []
            };
            if (t.pernottamento == 1 || t.pernottamento == true) g.pernottamento = true;
            const entry = { nome: t.sottocliente_nome || t.cliente_nome || '', id: t.id };
            if (t.fascia_oraria === 'mattino') g.mattina.push(entry);
            else if (t.fascia_oraria === 'pomeriggio') g.pomeriggio.push(entry);
            else (g.mattina.length <= g.pomeriggio.length ? g.mattina : g.pomeriggio).push(entry);

            const dest = t.sottocliente_citta || t.cliente_citta || t.luogo_arrivo || '';
            if (dest && !g.destinazioni.includes(dest)) g.destinazioni.push(dest);
            g.km_totali += (parseFloat(t.km_andata || 0) + parseFloat(t.km_ritorno || 0));
            g.vitto += parseFloat(t.vitto || 0);
            g.alloggio += parseFloat(t.alloggio || 0);
            g.altre += parseFloat(t.altre_spese || 0);
            g.aziendali += parseFloat(t.spese_aziendali || 0);
            g.rimborso_km += rimborsoKmDi(t);
        });
        return Object.values(grouped);
    }

    function renderKpis() {
        const totIndennita = parseFloat(_totali.indennita) || 0;

        const costoKmTotale = _trasferte.reduce((a, t) => a + rimborsoKmDi(t), 0);
        const speseDaRimborsare = (_totali.totale_spese || 0) - (_totali.spese_aziendali || 0);
        const totaleComplessivo = speseDaRimborsare + costoKmTotale + totIndennita;

        document.getElementById('trasferte-kpis').innerHTML = `
            <div class="kpi-card kpi-blue">
                <div class="kpi-label">Trasferte</div>
                <div class="kpi-value">${parseInt(_totali.num_trasferte) || 0}</div>
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
                <div class="kpi-label">Spese da rimborsare</div>
                <div class="kpi-value">${UI.formatCurrency(speseDaRimborsare)}</div>
            </div>
        `;
    }

    function renderTable() {
        const tbody = document.getElementById('tbody-trasferte');
        if (!_trasferte.length) {
            tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><i class="ph ph-car-profile"></i><h3>Nessuna trasferta</h3><p>Aggiungi la prima trasferta</p></div></td></tr>`;
            return;
        }

        const rows = raggruppa().sort((a, b) => b.data.localeCompare(a.data));

        const cella = (entries) => entries.map(e => `
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                        <span style="flex: 1;">${e.nome ? UI.esc(e.nome) : DA_ASSEGNARE}</span>
                        <div class="flex gap-1" style="flex-shrink: 0;">
                            <button class="btn btn-sm btn-ghost" style="padding: 2px" title="Modifica" onclick="ModTrasferte.edit(${parseInt(e.id)})"><i class="ph ph-pencil-simple"></i></button>
                            <button class="btn btn-sm btn-danger" style="padding: 2px" title="Elimina" onclick="ModTrasferte.remove(${parseInt(e.id)})"><i class="ph ph-trash"></i></button>
                        </div>
                    </div>`).join('');

        tbody.innerHTML = rows.map(g => {
            const indennita = indennitaDi(g.data);
            // Le spese pagate dalla società (carta aziendale, bonifico) non si rimborsano
            const spese = g.vitto + g.alloggio + g.altre - g.aziendali;
            const rimborsoTotale = g.rimborso_km + indennita + spese;
            const dataAttr = UI.esc(g.data);
            // Una spesa di alloggio vale come notte fuori anche senza il pulsante (così calcola il server)
            const daAlloggio = !g.pernottamento && g.alloggio > 0;
            const notte = g.pernottamento || daAlloggio;

            return `
            <tr>
                <td>${UI.formatDate(g.data)}</td>
                <td class="td-primary">${cella(g.mattina)}</td>
                <td class="td-primary">${cella(g.pomeriggio)}</td>
                <td class="text-right">${UI.formatNumber(g.km_totali)}</td>
                <td class="text-right">${UI.formatCurrency(indennita)}</td>
                <td class="text-right fw-600" title="${spese ? 'Comprende ' + UI.esc(UI.formatCurrency(spese)) + ' di spese da rimborsare (scheda Spese)' : ''}${g.aziendali ? ' · ' + UI.esc(UI.formatCurrency(g.aziendali)) + ' pagati dalla società' : ''}">${UI.formatCurrency(rimborsoTotale)}</td>
                <td>
                    <div class="flex gap-2 justify-end" style="align-items: center;">
                        <button type="button" class="btn btn-sm ${notte ? 'btn-primary' : 'btn-ghost'}" style="margin-right: 10px; display: flex; align-items: center; gap: 6px; ${notte ? 'box-shadow: 0 0 8px var(--accent);' : ''}" ${daAlloggio ? 'disabled title="Notte fuori: c\'è una spesa di alloggio in questa giornata"' : 'title="Dormo fuori"'} onclick="ModTrasferte.togglePernottamento('${dataAttr}', ${!g.pernottamento}, this)">
                            <i class="ph ${notte ? 'ph-moon-stars' : 'ph-moon'}"></i> Dormo fuori
                        </button>
                        <button class="btn btn-sm btn-ghost" title="Calcola KM per questa giornata" onclick="ModTrasferte.calcolaKm('${dataAttr}')"><i class="ph ph-map-pin-line"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('') + (parseFloat(_totali.spese_fuori_giornata) > 0 ? `
            <tr><td colspan="5" class="td-sub">Spese in giorni senza trasferta (es. treno o hotel del giorno prima): vedi la scheda Spese</td>
                <td class="text-right fw-600">${UI.formatCurrency(_totali.spese_fuori_giornata)}</td><td></td></tr>` : '');
    }

    function getFormHtml(data = {}) {
        const clienti = ModClienti.getClienti();
        const clientiOpts = clienti.map(c => `<option value="${UI.esc(c.id)}" ${c.id == data.cliente_id ? 'selected' : ''}>${UI.esc(c.ragione_sociale)}</option>`).join('');
        // Mezzo: quello della trasferta; per una nuova, quello scelto nella barra
        const mezzoSel = data.id ? (data.mezzo_id || '') : (document.getElementById('trasferte-mezzo')?.value || '');
        const mezziOpts = _mezziCache
            .filter(m => m.stato === 'attivo' || m.id == mezzoSel)
            .map(m => `<option value="${UI.esc(m.id)}" ${m.id == mezzoSel ? 'selected' : ''}>${UI.esc(m.nome)} (${UI.esc(m.targa)})</option>`).join('');

        return `
            <div class="form-grid">
                <div class="form-group">
                    <label>Data *</label>
                    <input type="date" class="form-control" id="f-t-data" required value="${UI.esc(data.data_trasferta || UI.todayLocal())}">
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
                    <label>Mezzo</label>
                    <select class="form-control" id="f-t-mezzo">
                        <option value="">— Nessun mezzo —</option>
                        ${mezziOpts}
                    </select>
                </div>
                <div class="form-group">
                    <label>KM Andata</label>
                    <input type="number" class="form-control" id="f-t-km-andata" value="${UI.esc(data.km_andata || 0)}" step="0.1" min="0">
                </div>
                <div class="form-group">
                    <label>KM Ritorno</label>
                    <input type="number" class="form-control" id="f-t-km-ritorno" value="${UI.esc(data.km_ritorno || 0)}" step="0.1" min="0">
                </div>

                <p class="form-group full-width td-sub" style="margin:0">Vitto, alloggio, pedaggi e altre spese si registrano nella scheda «Spese», con il giustificativo.</p>
                <div class="form-group full-width" style="display: flex; gap: 20px; align-items: center; margin-top: 10px;">
                    <input type="hidden" id="f-t-pernottamento" value="${UI.esc(data.pernottamento || 0)}">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="f-t-km-bloccati" ${data.km_bloccati == 1 ? 'checked' : ''}> Blocca Ricalcolo KM (valori manuali)
                    </label>
                </div>
                <div class="form-group full-width">
                    <label>Descrizione / Note</label>
                    <textarea class="form-control" id="f-t-desc">${UI.esc(data.descrizione || '')}</textarea>
                </div>
            </div>
            <input type="hidden" id="f-t-id" value="${UI.esc(data.id || '')}">
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
        const ripristina = () => {
            const currentMezzo = _trasferte.find(t => t.mezzo_id);
            document.getElementById('trasferte-mezzo').value = currentMezzo?.mezzo_id || '';
        };
        if (!_trasferte.length) {
            UI.toast('Nessuna trasferta da aggiornare', 'error');
            ripristina();
            return;
        }
        const mezzo = _mezziCache.find(m => m.id == mezzoId);
        const label = mezzo ? `${mezzo.nome} (${mezzo.targa})` : 'nessun mezzo';
        const month = document.getElementById('trasferte-month').value;
        const periodo = month ? 'del mese' : "dell'intero anno";
        if (!confirm(`Assegnare "${label}" a tutte le ${_trasferte.length} trasferte ${periodo}?`)) {
            ripristina();
            return;
        }
        try {
            // Un solo aggiornamento sul server, senza ricalcolo km (il mezzo non cambia il percorso)
            const params = { year: document.getElementById('trasferte-year').value, mezzo_id: mezzoId || '' };
            if (month) params.month = month;
            await Store.api('setMezzo', 'trasferte', params);
            _trasferte.forEach(t => t.mezzo_id = mezzoId || null);
            UI.toast(mezzoId ? `Mezzo ${mezzo?.nome || ''} assegnato a tutte le trasferte` : 'Mezzo rimosso da tutte le trasferte');
        } catch (err) {
            console.error('[Trasferte] updateMezzoForAll error:', err);
            UI.toast(err.message || 'Errore aggiornamento mezzo', 'error');
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

            descrizione: document.getElementById('f-t-desc').value,
            pernottamento: parseInt(document.getElementById('f-t-pernottamento').value) || 0,
            km_bloccati: document.getElementById('f-t-km-bloccati').checked ? 1 : 0,
            mezzo_id: document.getElementById('f-t-mezzo').value || '' // '' = nessun mezzo
        };
        if (!payload.data_trasferta) {
            UI.toast('Indica la data della trasferta', 'error');
            return;
        }
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
                renderKpis();
                renderTable();
                salvaCostoKmDifferito();
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

        // Le schede Viaggi/Mezzi sono gestite da UI.initVtabs (app.js)
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

    async function exportPdf() {
        const year = document.getElementById('trasferte-year').value;
        const month = document.getElementById('trasferte-month').value;
        const costoKm = parseFloat(document.getElementById('trasferte-costo-km').value) || 0;
        const mesePdf = month ? `${year}-${String(month).padStart(2, '0')}` : null;
        // Elenco delle spese del mese, se il periodo è un mese (per l'anno la tabella per giornata basta)
        const speseMese = mesePdf ? ((await Store.api('list', 'spese', { mese: mesePdf }).catch(() => null))?.spese || []) : [];

        if (!_trasferte.length) {
            UI.toast('Nessuna trasferta da esportare nel periodo selezionato', 'error');
            return;
        }

        // Stesso raggruppamento della tabella: tutte le trasferte, anche la terza della giornata
        const rows = raggruppa().sort((a, b) => a.data.localeCompare(b.data));
        const nomi = (entries) => entries.map(e => e.nome || 'Da assegnare').join(', ');

        let totKm = 0, totIndennita = 0, totRimborsoKm = 0, totVitto = 0, totAlloggio = 0, totAltre = 0, totTotale = 0;

        const tableRows = rows.map(g => {
            const indennita = indennitaDi(g.data);
            const rimborsoKm = g.rimborso_km;
            const totaleRiga = rimborsoKm + indennita + g.vitto + g.alloggio + g.altre - g.aziendali;

            totKm += g.km_totali;
            totIndennita += indennita;
            totRimborsoKm += rimborsoKm;
            totVitto += g.vitto;
            totAlloggio += g.alloggio;
            totAltre += g.altre;
            totTotale += totaleRiga;

            const [y, m, d] = g.data.split('-').map(Number);
            const dataFmt = new Date(y, m - 1, d).toLocaleDateString('it-IT', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });

            return `<tr>
                <td>${dataFmt}</td>
                <td>${UI.esc(nomi(g.mattina)) || '—'}</td>
                <td>${UI.esc(nomi(g.pomeriggio)) || '—'}</td>
                <td>${UI.esc(g.destinazioni.join(', ')) || '—'}</td>
                <td class="num">${g.km_totali.toFixed(1)}</td>
                <td class="num">${UI.formatCurrency(rimborsoKm)}</td>
                <td class="num">${UI.formatCurrency(indennita)}</td>
                <td class="num">${g.vitto ? UI.formatCurrency(g.vitto) : '—'}</td>
                <td class="num">${g.alloggio ? UI.formatCurrency(g.alloggio) : '—'}</td>
                <td class="num">${g.altre ? UI.formatCurrency(g.altre) : '—'}</td>
                <td class="num tot">${UI.formatCurrency(totaleRiga)}</td>
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
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 8.5px; color: #1a1a1a; padding: 0; }
        .header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 6px; border-bottom: 2px solid #0B0E14; padding-bottom: 5px; }
        .header h1 { font-size: 13px; font-weight: 700; }
        .header .sub { font-size: 9.5px; color: #666; margin-top: 2px; }
        .header .info { text-align: right; font-size: 8px; color: #666; line-height: 1.4; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        th { background: #0B0E14; color: #fff; padding: 4px 4px; text-align: left; font-size: 7.5px; text-transform: uppercase; letter-spacing: 0.3px; }
        td { padding: 3.5px 4px; border-bottom: 1px solid #e0e0e0; font-size: 8.5px; line-height: 2; }
        tr:nth-child(even) { background: #f7f7f7; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .tot { font-weight: 700; }
        .footer-row td { background: #0B0E14; color: #fff; font-weight: 700; font-size: 8.5px; border: none; padding: 3px 4px; }
        .summary { display: flex; gap: 14px; margin-top: 5px; padding: 5px 10px; background: #f0f0f0; border-radius: 3px; }
        .summary-item { text-align: center; }
        .summary-item .label { font-size: 7.5px; color: #666; text-transform: uppercase; }
        .summary-item .value { font-size: 11px; font-weight: 700; margin-top: 1px; }
        .footer-note { margin-top: 5px; font-size: 7.5px; color: #999; text-align: center; }
        @media print {
            body { padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            @page { size: landscape; margin: 5mm; }
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
            Costo KM: ${costoKm.toFixed(4)} €/km (costo ACI del mezzo, se indicato)<br>
            Indennità giornaliera: 46,48 € (30,99 € con vitto o alloggio rimborsato, 15,49 € con entrambi)<br>
            ${(() => { const selId = document.getElementById('trasferte-mezzo')?.value; const mezzo = selId ? _mezziCache.find(v => v.id == selId) : null; return mezzo ? `Mezzo: <strong>${UI.esc(mezzo.nome)}</strong> — Targa: <strong>${UI.esc(mezzo.targa)}</strong><br>` : ''; })()}
            Stampato il: ${new Date().toLocaleDateString('it-IT')}
        </div>
    </div>
    <table>
        <thead>
            <tr>
                <th>Data</th>
                <th>Mattina</th>
                <th>Pomeriggio</th>
                <th>Destinazione</th>
                <th style="text-align:right">KM</th>
                <th style="text-align:right">Rimb. KM</th>
                <th style="text-align:right">Indennità</th>
                <th style="text-align:right">Vitto</th>
                <th style="text-align:right">Alloggio</th>
                <th style="text-align:right">Altre spese</th>
                <th style="text-align:right">Totale</th>
            </tr>
        </thead>
        <tbody>
            ${tableRows}
            ${parseFloat(_totali.spese_fuori_giornata) > 0 ? `<tr><td colspan="9">Spese in giorni senza trasferta (dettaglio sotto)</td>
                <td class="num">${UI.formatCurrency(_totali.spese_fuori_giornata)}</td><td class="num tot">${UI.formatCurrency(_totali.spese_fuori_giornata)}</td></tr>` : ''}
            <tr class="footer-row">
                <td colspan="4">DA RIMBORSARE (${rows.length} giornate)</td>
                <td class="num">${totKm.toFixed(1)}</td>
                <td class="num">${UI.formatCurrency(totRimborsoKm)}</td>
                <td class="num">${UI.formatCurrency(totIndennita)}</td>
                <td class="num">${UI.formatCurrency(totVitto)}</td>
                <td class="num">${UI.formatCurrency(totAlloggio)}</td>
                <td class="num">${UI.formatCurrency(totAltre + (parseFloat(_totali.spese_fuori_giornata) || 0))}</td>
                <td class="num">${UI.formatCurrency(totTotale + (parseFloat(_totali.spese_fuori_giornata) || 0))}</td>
            </tr>
            ${parseFloat(_totali.spese_aziendali) > 0 ? `<tr><td colspan="11">Vitto, alloggio e altre spese comprendono ${UI.formatCurrency(_totali.spese_aziendali)} pagati dalla società (carta aziendale, bonifico): esclusi dal totale da rimborsare</td></tr>` : ''}
        </tbody>
    </table>
    ${speseMese.length ? `<table>
        <thead><tr><th>Data</th><th>Spesa</th><th>Esercente / descrizione</th><th>Pagamento</th><th>Giustificativo</th><th style="text-align:right">Importo</th><th style="text-align:right">Da rimborsare</th></tr></thead>
        <tbody>${speseMese.slice().reverse().map(sp => `<tr><td>${UI.formatDate(sp.data)}</td><td>${UI.esc(sp.categoria)}</td>
            <td>${UI.esc(sp.esercente || sp.descrizione || '—')}</td><td>${UI.esc(sp.metodo)}${sp.da_segnalare ? ' (non tracciabile)' : ''}${sp.carburante_doppio ? ' (già nel rimborso km)' : ''}</td>
            <td>${sp.ha_documento ? 'allegato' : 'manca'}</td><td class="num">${UI.formatCurrency(sp.importo)}</td>
            <td class="num">${sp.rimborsabile ? UI.formatCurrency(sp.importo) : 'pagata dalla società'}</td></tr>`).join('')}</tbody>
    </table>` : ''}
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
