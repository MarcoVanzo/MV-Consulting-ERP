'use strict';

/**
 * Modulo Contabilità — Fatture, KPI, grafico mensile
 */
const ModContabilita = (() => {
    let _fatture = [];
    let _kpis = {};
    let _mensile = [];
    let _statoFilter = '';

    async function load() {
        const year = document.getElementById('contabilita-year').value;
        try {
            // Load overview KPIs
            const ov = await Store.api('overview', 'contabilita', { year });
            _kpis = ov?.kpis || {};
            _mensile = ov?.mensile || [];
            renderKpis();
            renderChart();

            // Load fatture list
            const params = { year };
            if (_statoFilter) params.stato = _statoFilter;
            const list = await Store.api('list', 'contabilita', params);
            _fatture = list || [];
            renderTable();
        } catch (err) {
            console.error('[Contabilita] Load error:', err);
            UI.toast('Errore caricamento contabilità', 'error');
        }
    }

    function renderKpis() {
        document.getElementById('contabilita-kpis').innerHTML = `
            <div class="kpi-card kpi-blue">
                <div class="kpi-label">Fatturato Totale</div>
                <div class="kpi-value">${UI.formatCurrency(_kpis.fatturato_totale)}</div>
                <div class="kpi-sub">${_kpis.num_fatture || 0} fatture emesse</div>
            </div>
            <div class="kpi-card kpi-green">
                <div class="kpi-label">Incassato</div>
                <div class="kpi-value">${UI.formatCurrency(_kpis.totale_pagato)}</div>
                <div class="kpi-sub">${_kpis.num_pagate || 0} fatture pagate</div>
            </div>
            <div class="kpi-card kpi-yellow">
                <div class="kpi-label">In Attesa</div>
                <div class="kpi-value">${UI.formatCurrency(_kpis.in_attesa)}</div>
                <div class="kpi-sub">${_kpis.num_attesa || 0} in attesa</div>
            </div>
            <div class="kpi-card kpi-red">
                <div class="kpi-label">Scaduto</div>
                <div class="kpi-value">${UI.formatCurrency(_kpis.scaduto)}</div>
                <div class="kpi-sub">${_kpis.num_scadute || 0} fatture scadute</div>
            </div>
        `;
    }

    function renderChart() {
        const container = document.getElementById('contabilita-chart');
        if (!_mensile.length) {
            container.innerHTML = '<div style="color:var(--text-muted);font-size:0.8rem;padding:40px;text-align:center">Nessun dato per il grafico</div>';
            return;
        }

        const mesi = ['', 'Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
        const maxVal = Math.max(..._mensile.map(m => parseFloat(m.fatturato) || 0), 1);

        // Fill all 12 months
        const fullData = [];
        for (let i = 1; i <= 12; i++) {
            const found = _mensile.find(m => parseInt(m.mese) === i);
            fullData.push({
                mese: i,
                fatturato: found ? parseFloat(found.fatturato) : 0,
                pagato: found ? parseFloat(found.pagato) : 0,
                num_fatture: found ? parseInt(found.num_fatture) : 0
            });
        }

        container.innerHTML = fullData.map(m => {
            const hFatt = Math.max((m.fatturato / maxVal) * 100, 4);
            const hPag = Math.max((m.pagato / maxVal) * 100, 0);
            return `
                <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;position:relative;height:100%"
                     title="Mese: ${mesi[m.mese]}\nSomma Fatturato: ${UI.formatCurrency(m.fatturato)}\nSomma Pagato: ${UI.formatCurrency(m.pagato)}\nN. Fatture: ${m.num_fatture}">
                    <div style="flex:1;width:100%;display:flex;align-items:flex-end;gap:2px">
                        <div class="chart-bar" style="height:${hFatt}%;opacity:0.4"></div>
                        <div class="chart-bar" style="height:${hPag > 0 ? hPag + '%' : '0%'};background:var(--accent-green)"></div>
                    </div>
                    <span style="font-size:0.6rem;color:var(--text-muted)">${mesi[m.mese]}</span>
                </div>
            `;
        }).join('');
    }

    function renderTable() {
        const tbody = document.getElementById('tbody-fatture');
        if (!_fatture.length) {
            tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><i class="ph ph-chart-line-up"></i><h3>Nessuna fattura</h3><p>Aggiungi la prima fattura</p></div></td></tr>`;
            return;
        }
        tbody.innerHTML = _fatture.map(f => `
            <tr data-id="${f.id}">
                <td class="td-mono">${UI.esc(f.numero_fattura)}</td>
                <td>${UI.formatDate(f.data_emissione)}</td>
                <td class="td-primary">${UI.esc(f.cliente_nome || '—')}${f.sottocliente_nome ? ` <span style="color:var(--text-muted)">/ ${UI.esc(f.sottocliente_nome)}</span>` : ''}</td>
                <td class="text-right">${UI.formatCurrency(f.imponibile)}</td>
                <td class="text-right td-primary">${UI.formatCurrency(f.importo_totale)}</td>
                <td>${UI.statoBadge(f.stato)}</td>
                <td>${f.data_scadenza ? UI.formatDate(f.data_scadenza) : '—'}</td>
                <td>
                    <div class="flex gap-2">
                        <button class="btn btn-sm btn-ghost" onclick="ModContabilita.edit(${f.id})"><i class="ph ph-pencil-simple"></i></button>
                        <button class="btn btn-sm btn-danger" onclick="ModContabilita.remove(${f.id})"><i class="ph ph-trash"></i></button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    function getFormHtml(data = {}) {
        const clienti = ModClienti.getClienti();
        const clientiOpts = clienti.map(c => `<option value="${c.id}" ${c.id == data.cliente_id ? 'selected' : ''}>${UI.esc(c.ragione_sociale)}</option>`).join('');

        const stati = ['emessa', 'inviata', 'pagata', 'scaduta'];
        const statiOpts = stati.map(s => `<option value="${s}" ${s === (data.stato || 'emessa') ? 'selected' : ''}>${s.charAt(0).toUpperCase() + s.slice(1)}</option>`).join('');

        return `
            <div class="form-grid">
                <div class="form-group">
                    <label>Numero Fattura *</label>
                    <input type="text" class="form-control" id="f-f-numero" value="${UI.esc(data.numero_fattura || '')}" placeholder="es. 2026/001">
                </div>
                <div class="form-group">
                    <label>Data Emissione *</label>
                    <input type="date" class="form-control" id="f-f-data" value="${data.data_emissione || new Date().toISOString().split('T')[0]}">
                </div>
                <div class="form-group">
                    <label>Cliente</label>
                    <select class="form-control" id="f-f-cliente">
                        <option value="">— Seleziona —</option>
                        ${clientiOpts}
                    </select>
                </div>
                <div class="form-group">
                    <label>Sottocliente</label>
                    <select class="form-control" id="f-f-sottocliente">
                        <option value="">— Nessuno —</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Imponibile (€)</label>
                    <input type="number" class="form-control" id="f-f-imponibile" value="${data.imponibile || 0}" step="0.01">
                </div>
                <div class="form-group">
                    <label>IVA %</label>
                    <input type="number" class="form-control" id="f-f-iva" value="${data.iva_percentuale || 22}" step="0.01">
                </div>
                <div class="form-group">
                    <label>Stato</label>
                    <select class="form-control" id="f-f-stato">${statiOpts}</select>
                </div>
                <div class="form-group">
                    <label>Data Scadenza</label>
                    <input type="date" class="form-control" id="f-f-scadenza" value="${data.data_scadenza || ''}">
                </div>
                <div class="form-group">
                    <label>Data Pagamento</label>
                    <input type="date" class="form-control" id="f-f-pagamento" value="${data.data_pagamento || ''}">
                </div>
                <div class="form-group">
                    <label>Metodo Pagamento</label>
                    <select class="form-control" id="f-f-metodo">
                        <option value="" ${!data.metodo_pagamento ? 'selected' : ''}>—</option>
                        <option value="bonifico" ${data.metodo_pagamento === 'bonifico' ? 'selected' : ''}>Bonifico</option>
                        <option value="carta" ${data.metodo_pagamento === 'carta' ? 'selected' : ''}>Carta</option>
                        <option value="contanti" ${data.metodo_pagamento === 'contanti' ? 'selected' : ''}>Contanti</option>
                        <option value="paypal" ${data.metodo_pagamento === 'paypal' ? 'selected' : ''}>PayPal</option>
                    </select>
                </div>
                <div class="form-group full-width">
                    <label>Descrizione</label>
                    <textarea class="form-control" id="f-f-desc">${UI.esc(data.descrizione || '')}</textarea>
                </div>
                <div class="form-group full-width">
                    <label>Note</label>
                    <textarea class="form-control" id="f-f-note">${UI.esc(data.note || '')}</textarea>
                </div>
            </div>
            <input type="hidden" id="f-f-id" value="${data.id || ''}">
        `;
    }

    function openNew() {
        UI.openModal('Nuova Fattura', getFormHtml(), saveFromForm);
        setTimeout(initClienteWatch, 100);
    }

    function edit(id) {
        const f = _fatture.find(x => x.id == id);
        if (!f) return;
        UI.openModal('Modifica Fattura', getFormHtml(f), saveFromForm);
        setTimeout(() => {
            initClienteWatch();
            if (f.cliente_id) loadSottoclienti(f.cliente_id, f.sottocliente_id);
        }, 100);
    }

    function initClienteWatch() {
        const sel = document.getElementById('f-f-cliente');
        if (!sel) return;
        sel.addEventListener('change', () => loadSottoclienti(sel.value));
    }

    async function loadSottoclienti(clienteId, selectedId) {
        const sel = document.getElementById('f-f-sottocliente');
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
        } catch (err) { /* ignore */ }
    }

    async function saveFromForm() {
        const payload = {
            id: document.getElementById('f-f-id').value || undefined,
            numero_fattura: document.getElementById('f-f-numero').value,
            data_emissione: document.getElementById('f-f-data').value,
            cliente_id: document.getElementById('f-f-cliente').value,
            sottocliente_id: document.getElementById('f-f-sottocliente').value,
            imponibile: document.getElementById('f-f-imponibile').value,
            iva_percentuale: document.getElementById('f-f-iva').value,
            stato: document.getElementById('f-f-stato').value,
            data_scadenza: document.getElementById('f-f-scadenza').value,
            data_pagamento: document.getElementById('f-f-pagamento').value,
            metodo_pagamento: document.getElementById('f-f-metodo').value,
            descrizione: document.getElementById('f-f-desc').value,
            note: document.getElementById('f-f-note').value
        };
        try {
            await Store.api('save', 'contabilita', payload);
            UI.closeModal();
            UI.toast(payload.id ? 'Fattura aggiornata' : 'Fattura creata');
            load();
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    async function remove(id) {
        if (!confirm('Eliminare questa fattura?')) return;
        try {
            await Store.api('delete', 'contabilita', { id });
            UI.toast('Fattura eliminata');
            load();
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    function initFilters() {
        UI.populateYearSelect('contabilita-year');
        document.getElementById('contabilita-year').addEventListener('change', load);

        // Status filter chips
        document.querySelectorAll('#view-contabilita .filter-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('#view-contabilita .filter-chip').forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                _statoFilter = chip.dataset.stato || '';
                load();
            });
        });
    }

    async function importPdf(file) {
        if (!file || file.type !== 'application/pdf') {
            UI.toast('Seleziona un file PDF valido', 'error');
            return;
        }

        const btn = document.getElementById('btn-import-pdf-fatture');
        const prevHtml = btn.innerHTML;
        btn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> Lettura PDF in corso...';
        btn.disabled = true;

        try {
            const arrayBuffer = await file.arrayBuffer();
            const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
            const numPages = pdf.numPages;
            const pagesText = [];

            for (let i = 1; i <= numPages; i++) {
                const page = await pdf.getPage(i);
                const textContent = await page.getTextContent();
                const textItems = textContent.items.map(item => item.str);
                pagesText.push(textItems.join(' '));
            }

            btn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> Analisi dati in corso...';
            
            const req = await Store.api('import_pdf', 'contabilita', { pages: pagesText });
            
            if (req && req.success) {
                UI.toast(`Importazione completata: ${req.num_imported} fatture importate.`);
                if (req.errors && req.errors.length > 0) {
                    req.errors.forEach(err => console.warn('[Contabilita Import]', err));
                    UI.toast(`${req.errors.length} errori durante l'importazione, controlla la console.`, 'error');
                }
                load(); // Reload table
            } else {
                throw new Error("Risposta anomala dal server");
            }
        } catch (err) {
            console.error('[Contabilita Import] Error:', err);
            UI.toast('Errore durante l\'importazione PDF: ' + err.message, 'error');
        } finally {
            btn.innerHTML = prevHtml;
            btn.disabled = false;
        }
    }

    async function importXml(files) {
        if (!Array.isArray(files)) files = [files];
        
        const validFiles = files.filter(f => f.type === 'text/xml' || f.type === 'application/xml' || f.name.endsWith('.xml'));
        if (validFiles.length === 0) {
            UI.toast('Seleziona almeno un file XML valido', 'error');
            return;
        }

        const btn = document.getElementById('btn-import-xml-fatture');
        const prevHtml = btn.innerHTML;
        btn.disabled = true;

        let totalImported = 0;
        let totalErrors = 0;

        try {
            for (let i = 0; i < validFiles.length; i++) {
                const file = validFiles[i];
                btn.innerHTML = `<i class="ph ph-spinner ph-spin"></i> Lettura XML (${i+1}/${validFiles.length})...`;
                
                const text = await file.text();
                
                btn.innerHTML = `<i class="ph ph-spinner ph-spin"></i> Analisi DB (${i+1}/${validFiles.length})...`;
                
                const req = await Store.api('import_xml', 'contabilita', { xml: text });
                
                if (req && req.success) {
                    totalImported += req.num_imported || 0;
                    if (req.errors && req.errors.length > 0) {
                        req.errors.forEach(err => console.warn(`[Contabilita Import XML - ${file.name}]`, err));
                        totalErrors += req.errors.length;
                    }
                } else {
                    console.error(`Error importing ${file.name}:`, req);
                    totalErrors++;
                }
            }
            
            if (totalErrors > 0) {
                UI.toast(`Importazione terminata: ${totalImported} righe, ${totalErrors} anomalie (vedi console).`, 'warning');
            } else {
                UI.toast(`Importazione completata: ${totalImported} righe create/aggiornate.`);
            }
            load(); // Reload table
        } catch (err) {
            console.error('[Contabilita Import XML] Error:', err);
            UI.toast('Errore durante l\'importazione XML: ' + err.message, 'error');
        } finally {
            btn.innerHTML = prevHtml;
            btn.disabled = false;
        }
    }

    return { load, openNew, edit, remove, initFilters, importPdf, importXml };
})();

window.ModContabilita = ModContabilita;
