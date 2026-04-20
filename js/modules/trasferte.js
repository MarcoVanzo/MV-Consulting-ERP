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
            renderKpis();
            renderTable();
        } catch (err) {
            console.error('[Trasferte] Load error:', err);
            UI.toast('Errore caricamento trasferte', 'error');
        }
    }

    function renderKpis() {
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
                <div class="kpi-label">Pedaggi</div>
                <div class="kpi-value">${UI.formatCurrency(_totali.pedaggio)}</div>
            </div>
            <div class="kpi-card kpi-red">
                <div class="kpi-label">Totale Spese</div>
                <div class="kpi-value">${UI.formatCurrency(_totali.totale_spese)}</div>
            </div>
        `;
    }

    function renderTable() {
        const tbody = document.getElementById('tbody-trasferte');
        if (!_trasferte.length) {
            tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><i class="ph ph-car-profile"></i><h3>Nessuna trasferta</h3><p>Aggiungi la prima trasferta</p></div></td></tr>`;
            return;
        }
        tbody.innerHTML = _trasferte.map(t => {
            const kmTot = parseFloat(t.km_andata || 0) + parseFloat(t.km_ritorno || 0);
            return `
            <tr data-id="${t.id}">
                <td>${UI.formatDate(t.data_trasferta)}</td>
                <td class="td-primary">${UI.esc(t.cliente_nome || '—')}${t.sottocliente_nome ? ` <span style="color:var(--text-muted)">/ ${UI.esc(t.sottocliente_nome)}</span>` : ''}</td>
                <td>${UI.esc(t.luogo_arrivo || '—')}</td>
                <td class="text-right">${UI.formatNumber(kmTot)}</td>
                <td class="text-right">${UI.formatCurrency(t.pedaggio)}</td>
                <td class="text-right">${UI.formatCurrency(t.vitto)}</td>
                <td class="text-right">${UI.formatCurrency(parseFloat(t.alloggio || 0) + parseFloat(t.altre_spese || 0))}</td>
                <td>
                    <div class="flex gap-2">
                        <button class="btn btn-sm btn-ghost" onclick="ModTrasferte.edit(${t.id})"><i class="ph ph-pencil-simple"></i></button>
                        <button class="btn btn-sm btn-danger" onclick="ModTrasferte.remove(${t.id})"><i class="ph ph-trash"></i></button>
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
                    <label>Pedaggio</label>
                    <input type="number" class="form-control" id="f-t-pedaggio" value="${data.pedaggio || 0}" step="0.01">
                </div>
                <div class="form-group">
                    <label>Vitto</label>
                    <input type="number" class="form-control" id="f-t-vitto" value="${data.vitto || 0}" step="0.01">
                </div>
                <div class="form-group">
                    <label>Alloggio</label>
                    <input type="number" class="form-control" id="f-t-alloggio" value="${data.alloggio || 0}" step="0.01">
                </div>
                <div class="form-group">
                    <label>Altre Spese</label>
                    <input type="number" class="form-control" id="f-t-altre" value="${data.altre_spese || 0}" step="0.01">
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
        setTimeout(initClienteWatch, 100);
    }

    function edit(id) {
        const t = _trasferte.find(x => x.id == id);
        if (!t) return;
        UI.openModal('Modifica Trasferta', getFormHtml(t), saveFromForm);
        setTimeout(() => {
            initClienteWatch();
            if (t.cliente_id) loadSottoclienti(t.cliente_id, t.sottocliente_id);
        }, 100);
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
            cliente_id: document.getElementById('f-t-cliente').value,
            sottocliente_id: document.getElementById('f-t-sottocliente').value,
            luogo_arrivo: document.getElementById('f-t-luogo').value,
            km_andata: document.getElementById('f-t-km-andata').value,
            km_ritorno: document.getElementById('f-t-km-ritorno').value,
            pedaggio: document.getElementById('f-t-pedaggio').value,
            vitto: document.getElementById('f-t-vitto').value,
            alloggio: document.getElementById('f-t-alloggio').value,
            altre_spese: document.getElementById('f-t-altre').value,
            descrizione: document.getElementById('f-t-desc').value
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
        document.getElementById('trasferte-year').addEventListener('change', load);
        document.getElementById('trasferte-month').addEventListener('change', load);
    }

    return { load, openNew, edit, remove, initFilters };
})();

window.ModTrasferte = ModTrasferte;
