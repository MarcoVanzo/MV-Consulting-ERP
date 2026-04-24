'use strict';

/**
 * Modulo Clienti — Gestione clienti e sottoclienti con lookup P.IVA
 */
const ModClienti = (() => {
    let _clienti = [];
    let _sottoclientiCache = {};

    async function load() {
        try {
            const data = await Store.api('list', 'clienti');
            _clienti = data || [];
            render();
        } catch (err) {
            console.error('[Clienti] Load error:', err);
            UI.toast('Errore caricamento clienti', 'error');
        }
    }

    function render() {
        const tbody = document.getElementById('tbody-clienti');
        if (!_clienti.length) {
            tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><i class="ph ph-buildings"></i><h3>Nessun cliente</h3><p>Aggiungi il primo cliente con il pulsante sopra</p></div></td></tr>`;
            return;
        }

        tbody.innerHTML = _clienti.map(c => `
            <tr data-id="${c.id}">
                <td>
                    ${parseInt(c.num_sottoclienti) > 0 ? `<button class="expand-btn" data-cliente-id="${c.id}" title="Espandi sottoclienti"><i class="ph ph-caret-right"></i></button>` : ''}
                </td>
                <td class="td-primary">${UI.esc(c.ragione_sociale)}</td>
                <td class="td-mono">${UI.esc(c.partita_iva || '—')}</td>
                <td>${UI.esc(c.citta || '')}${c.provincia ? ` (${UI.esc(c.provincia)})` : ''}</td>
                <td>${UI.esc(c.email || c.pec || '—')}</td>
                <td>${parseInt(c.num_sottoclienti) > 0 ? `<span class="badge badge-purple">${c.num_sottoclienti}</span>` : '—'}</td>
                <td>
                    <div class="flex gap-2">
                        <button class="btn btn-sm btn-ghost" onclick="ModClienti.openSottoclienteModal(${c.id})" title="Aggiungi sottocliente"><i class="ph ph-plus-circle"></i></button>
                        <button class="btn btn-sm btn-ghost" onclick="ModClienti.edit(${c.id})" title="Modifica"><i class="ph ph-pencil-simple"></i></button>
                        <button class="btn btn-sm btn-danger" onclick="ModClienti.remove(${c.id})" title="Elimina"><i class="ph ph-trash"></i></button>
                    </div>
                </td>
            </tr>
        `).join('');

        // Expand buttons
        tbody.querySelectorAll('.expand-btn').forEach(btn => {
            btn.addEventListener('click', () => toggleSottoclienti(btn));
        });
    }

    async function toggleSottoclienti(btn) {
        if (btn.dataset.loading) return;
        btn.dataset.loading = "true";

        try {
            const clienteId = btn.dataset.clienteId;
            const parentRow = btn.closest('tr');
            const isExpanded = btn.classList.contains('expanded');

            // Remove existing sottoclienti rows
            let next = parentRow.nextElementSibling;
            while (next && next.classList.contains('sottoclienti-row')) {
                const toRemove = next;
                next = next.nextElementSibling;
                toRemove.remove();
            }

            if (isExpanded) {
                btn.classList.remove('expanded');
                return;
            }

            btn.classList.add('expanded');

            const subs = await Store.api('list', 'sottoclienti', { cliente_id: clienteId });
            if (!subs || !subs.length) return;
            
            _sottoclientiCache[clienteId] = subs;

            const rows = subs.map(s => `
                <tr class="sottoclienti-row">
                    <td></td>
                    <td class="td-primary" style="padding-left:1.5rem"><i class="ph ph-arrow-bend-down-right" style="color:var(--text-muted);margin-right:8px"></i> ${UI.esc(s.nome)} ${s.riferimento ? `<span style="color:var(--text-muted)">— ${UI.esc(s.riferimento)}</span>` : ''}</td>
                    <td class="td-mono">${UI.esc(s.partita_iva || s.codice_fiscale || '—')}</td>
                    <td>${UI.esc(s.citta || '—')} ${s.provincia ? `(${UI.esc(s.provincia)})` : ''}</td>
                    <td>${UI.esc(s.email || s.pec || '—')}</td>
                    <td></td>
                    <td>
                        <div class="flex gap-2">
                            <button class="btn btn-sm btn-ghost" onclick="ModClienti.editSotto(${clienteId}, ${s.id})" title="Modifica"><i class="ph ph-pencil-simple"></i></button>
                            <button class="btn btn-sm btn-danger" onclick="ModClienti.removeSotto(${s.id})" title="Elimina"><i class="ph ph-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `).join('');

            parentRow.insertAdjacentHTML('afterend', rows);
        } catch (err) {
            console.error('[Sottoclienti] Load error:', err);
        } finally {
            delete btn.dataset.loading;
        }
    }

    function getFormHtml(data = {}) {
        return `
            <div class="vat-lookup-row mb-2">
                <input type="text" class="form-control" id="f-vat-lookup" placeholder="Inserisci P.IVA per compilare automaticamente" value="${UI.esc(data.partita_iva || '')}">
                <button class="btn btn-primary" id="btn-vat-lookup" type="button">
                    <i class="ph ph-magnifying-glass"></i> Cerca
                </button>
            </div>
            <div id="vat-status"></div>
            <div class="form-grid mt-2">
                <div class="form-group full-width">
                    <label>Ragione Sociale *</label>
                    <input type="text" class="form-control" id="f-ragione-sociale" value="${UI.esc(data.ragione_sociale || '')}">
                </div>
                <div class="form-group">
                    <label>Partita IVA</label>
                    <input type="text" class="form-control" id="f-partita-iva" value="${UI.esc(data.partita_iva || '')}">
                </div>
                <div class="form-group">
                    <label>Codice Fiscale</label>
                    <input type="text" class="form-control" id="f-codice-fiscale" value="${UI.esc(data.codice_fiscale || '')}">
                </div>
                <div class="form-group full-width">
                    <label>Indirizzo</label>
                    <input type="text" class="form-control" id="f-indirizzo" value="${UI.esc(data.indirizzo || '')}">
                </div>
                <div class="form-group">
                    <label>Città</label>
                    <input type="text" class="form-control" id="f-citta" value="${UI.esc(data.citta || '')}">
                </div>
                <div class="form-group">
                    <label>CAP</label>
                    <input type="text" class="form-control" id="f-cap" value="${UI.esc(data.cap || '')}">
                </div>
                <div class="form-group">
                    <label>Provincia</label>
                    <input type="text" class="form-control" id="f-provincia" value="${UI.esc(data.provincia || '')}" maxlength="5">
                </div>
                <div class="form-group">
                    <label>SDI</label>
                    <input type="text" class="form-control" id="f-sdi" value="${UI.esc(data.sdi || '')}">
                </div>
                <div class="form-group">
                    <label>PEC</label>
                    <input type="email" class="form-control" id="f-pec" value="${UI.esc(data.pec || '')}">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" class="form-control" id="f-email" value="${UI.esc(data.email || '')}">
                </div>
                <div class="form-group">
                    <label>Telefono</label>
                    <input type="tel" class="form-control" id="f-telefono" value="${UI.esc(data.telefono || '')}">
                </div>
                <div class="form-group full-width">
                    <label>Note</label>
                    <textarea class="form-control" id="f-note">${UI.esc(data.note || '')}</textarea>
                </div>
            </div>
            <input type="hidden" id="f-cliente-id" value="${data.id || ''}">
        `;
    }

    function openNew() {
        UI.openModal('Nuovo Cliente', getFormHtml(), saveFromForm);
        setTimeout(() => initVatLookup(false), 100);
    }

    function edit(id) {
        const c = _clienti.find(x => x.id == id);
        if (!c) return;
        UI.openModal('Modifica Cliente', getFormHtml(c), saveFromForm);
        setTimeout(() => initVatLookup(false), 100);
    }

    function initVatLookup(isSotto = false) {
        const btn = document.getElementById('btn-vat-lookup');
        if (!btn) return;
        btn.addEventListener('click', async () => {
            const vat = document.getElementById('f-vat-lookup').value.trim();
            if (!vat) return;

            const statusEl = document.getElementById('vat-status');
            statusEl.innerHTML = '<div class="vat-status loading"><i class="ph ph-circle-notch ph-spin"></i> Ricerca in corso...</div>';

            try {
                const data = await Store.api('lookup-vat', 'clienti', { vat });
                statusEl.innerHTML = '<div class="vat-status success"><i class="ph ph-check-circle"></i> Dati trovati e compilati!</div>';

                // Populate form
                if (data.ragione_sociale) {
                    if (isSotto) document.getElementById('f-sotto-nome').value = data.ragione_sociale;
                    else document.getElementById('f-ragione-sociale').value = data.ragione_sociale;
                }
                const pf = isSotto ? 'f-sotto-' : 'f-';
                if (data.partita_iva) document.getElementById(pf + 'partita-iva').value = data.partita_iva;
                if (data.codice_fiscale) document.getElementById(pf + 'codice-fiscale').value = data.codice_fiscale;
                if (data.indirizzo) document.getElementById(pf + 'indirizzo').value = data.indirizzo;
                if (data.citta) document.getElementById(pf + 'citta').value = data.citta;
                if (data.cap) document.getElementById(pf + 'cap').value = data.cap;
                if (data.provincia) document.getElementById(pf + 'provincia').value = data.provincia;
                if (data.sdi) document.getElementById(pf + 'sdi').value = data.sdi;
                if (data.pec) document.getElementById(pf + 'pec').value = data.pec;
            } catch (err) {
                statusEl.innerHTML = `<div class="vat-status error"><i class="ph ph-warning-circle"></i> ${UI.esc(err.message)}</div>`;
            }
        });
    }

    async function saveFromForm() {
        const payload = {
            id: document.getElementById('f-cliente-id').value || undefined,
            ragione_sociale: document.getElementById('f-ragione-sociale').value,
            partita_iva: document.getElementById('f-partita-iva').value,
            codice_fiscale: document.getElementById('f-codice-fiscale').value,
            indirizzo: document.getElementById('f-indirizzo').value,
            citta: document.getElementById('f-citta').value,
            cap: document.getElementById('f-cap').value,
            provincia: document.getElementById('f-provincia').value,
            pec: document.getElementById('f-pec').value,
            sdi: document.getElementById('f-sdi').value,
            email: document.getElementById('f-email').value,
            telefono: document.getElementById('f-telefono').value,
            note: document.getElementById('f-note').value
        };

        try {
            await Store.api('save', 'clienti', payload);
            UI.closeModal();
            UI.toast(payload.id ? 'Cliente aggiornato' : 'Cliente creato');
            load();
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    async function remove(id) {
        if (!confirm('Eliminare questo cliente e tutti i suoi sottoclienti?')) return;
        try {
            await Store.api('delete', 'clienti', { id });
            UI.toast('Cliente eliminato');
            load();
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    // ── Sottoclienti ──
    function editSotto(clienteId, sottoId) {
        const subs = _sottoclientiCache[clienteId];
        if (!subs) return;
        const s = subs.find(x => x.id == sottoId);
        if (!s) return;
        openSottoclienteModal(clienteId, s);
    }

    function openSottoclienteModal(clienteId, data = {}) {
        const c = _clienti.find(x => x.id == clienteId);
        const clienteNome = c ? c.ragione_sociale : 'Cliente';
        const html = `
            <p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:var(--sp-2)">Sottocliente di <strong>${UI.esc(clienteNome)}</strong></p>
            <div class="vat-lookup-row mb-2">
                <input type="text" class="form-control" id="f-vat-lookup" placeholder="Inserisci P.IVA per compilare automaticamente" value="${UI.esc(data.partita_iva || '')}">
                <button class="btn btn-primary" id="btn-vat-lookup" type="button">
                    <i class="ph ph-magnifying-glass"></i> Cerca
                </button>
            </div>
            <div id="vat-status"></div>
            <div class="form-grid mt-2">
                <div class="form-group full-width">
                    <label>Nome Sottocliente *</label>
                    <input type="text" class="form-control" id="f-sotto-nome" placeholder="es. Sede di Roma, Dipartimento IT..." value="${UI.esc(data.nome || '')}">
                </div>
                <div class="form-group">
                    <label>Persona di Riferimento</label>
                    <input type="text" class="form-control" id="f-sotto-riferimento" placeholder="Persona di contatto" value="${UI.esc(data.riferimento || '')}">
                </div>
                <div class="form-group">
                    <label>Partita IVA</label>
                    <input type="text" class="form-control" id="f-sotto-partita-iva" value="${UI.esc(data.partita_iva || '')}">
                </div>
                <div class="form-group">
                    <label>Codice Fiscale</label>
                    <input type="text" class="form-control" id="f-sotto-codice-fiscale" value="${UI.esc(data.codice_fiscale || '')}">
                </div>
                <div class="form-group full-width">
                    <label>Indirizzo</label>
                    <input type="text" class="form-control" id="f-sotto-indirizzo" value="${UI.esc(data.indirizzo || '')}">
                </div>
                <div class="form-group">
                    <label>Città</label>
                    <input type="text" class="form-control" id="f-sotto-citta" value="${UI.esc(data.citta || '')}">
                </div>
                <div class="form-group">
                    <label>CAP</label>
                    <input type="text" class="form-control" id="f-sotto-cap" value="${UI.esc(data.cap || '')}">
                </div>
                <div class="form-group">
                    <label>Provincia</label>
                    <input type="text" class="form-control" id="f-sotto-provincia" maxlength="5" value="${UI.esc(data.provincia || '')}">
                </div>
                <div class="form-group">
                    <label>SDI</label>
                    <input type="text" class="form-control" id="f-sotto-sdi" value="${UI.esc(data.sdi || '')}">
                </div>
                <div class="form-group">
                    <label>PEC</label>
                    <input type="email" class="form-control" id="f-sotto-pec" value="${UI.esc(data.pec || '')}">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" class="form-control" id="f-sotto-email" value="${UI.esc(data.email || '')}">
                </div>
                <div class="form-group">
                    <label>Telefono</label>
                    <input type="tel" class="form-control" id="f-sotto-telefono" value="${UI.esc(data.telefono || '')}">
                </div>
                <div class="form-group full-width">
                    <label>Note</label>
                    <textarea class="form-control" id="f-sotto-note">${UI.esc(data.note || '')}</textarea>
                </div>
            </div>
            <input type="hidden" id="f-sotto-id" value="${data.id || ''}">
            <input type="hidden" id="f-sotto-cliente-id" value="${clienteId}">
        `;
        UI.openModal(data.id ? 'Modifica Sottocliente' : 'Nuovo Sottocliente', html, saveSottocliente);
        setTimeout(() => initVatLookup(true), 100);
    }

    async function saveSottocliente() {
        const payload = {
            id: document.getElementById('f-sotto-id').value || undefined,
            cliente_id: document.getElementById('f-sotto-cliente-id').value,
            nome: document.getElementById('f-sotto-nome').value,
            riferimento: document.getElementById('f-sotto-riferimento').value,
            partita_iva: document.getElementById('f-sotto-partita-iva').value,
            codice_fiscale: document.getElementById('f-sotto-codice-fiscale').value,
            indirizzo: document.getElementById('f-sotto-indirizzo').value,
            citta: document.getElementById('f-sotto-citta').value,
            cap: document.getElementById('f-sotto-cap').value,
            provincia: document.getElementById('f-sotto-provincia').value,
            sdi: document.getElementById('f-sotto-sdi').value,
            pec: document.getElementById('f-sotto-pec').value,
            telefono: document.getElementById('f-sotto-telefono').value,
            email: document.getElementById('f-sotto-email').value,
            note: document.getElementById('f-sotto-note').value
        };
        try {
            await Store.api('save', 'sottoclienti', payload);
            UI.closeModal();
            UI.toast(payload.id ? 'Sottocliente aggiornato' : 'Sottocliente aggiunto');
            load();
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    async function removeSotto(id) {
        if (!confirm('Eliminare questo sottocliente?')) return;
        try {
            await Store.api('delete', 'sottoclienti', { id });
            UI.toast('Sottocliente eliminato');
            load();
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    function getClienti() { return _clienti; }

    function initSearch() {
        document.getElementById('search-clienti').addEventListener('input', (e) => {
            const q = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#tbody-clienti tr[data-id]');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });
        });
    }

    return { load, render, openNew, edit, remove, openSottoclienteModal, editSotto, removeSotto, getClienti, initSearch };
})();

window.ModClienti = ModClienti;
