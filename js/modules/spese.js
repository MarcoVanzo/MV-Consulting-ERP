'use strict';

/**
 * Spese — note spese del mese (scheda Trasferte › Spese): spese con giustificativo e metodo di
 * pagamento, uscite della carta da registrare, nota spese con i totali (km al costo ACI del mezzo,
 * indennità, spese) e il suo stato: presentata, rimborsata.
 * Gli scontrini entrano da «Importa file» (lettura AI); qui si correggono e si completano.
 */
const ModSpese = (() => {
    const CATEGORIE = { vitto: 'Vitto', alloggio: 'Alloggio', treno: 'Treno', aereo: 'Aereo', taxi: 'Taxi / NCC', pedaggio: 'Pedaggio',
        parcheggio: 'Parcheggio', carburante: 'Carburante', altro: 'Altro' };
    // La carta dell'estratto è aziendale: le spese pagate con lei (o con bonifico) si rendicontano ma non si rimborsano
    const METODI = { carta: 'Carta aziendale', carta_personale: 'Carta personale', bancomat: 'Bancomat personale', contanti: 'Contanti', bonifico: 'Bonifico della società', altro: 'Altro (di tasca propria)' };
    let _mese = UI.todayLocal().slice(0, 7);
    let _d = null;

    async function load() {
        const box = document.getElementById('trasferte-spese');
        if (!box) return;
        try {
            _d = await Store.api('list', 'spese', { mese: _mese });
            render(box);
        } catch (e) {
            box.innerHTML = `<div class="empty-state"><h3>Spese non disponibili</h3><p>${UI.esc(e.message)}</p></div>`;
        }
    }

    function render(box) {
        const t = _d.totali || {}, rb = _d.rimborso, spese = _d.spese || [], carta = _d.carta_libera || [];
        const senzaDoc = spese.filter(s => !s.ha_documento).length;
        const nonTracc = spese.filter(s => s.da_segnalare).length;
        const bloccata = !!rb;
        const statoNota = !rb ? '<span class="badge badge-gray">Aperta</span>'
            : rb.stato === 'rimborsata' ? `<span class="badge badge-green">Rimborsata il ${UI.formatDate(rb.data_rimborso)}</span>`
            : `<span class="badge badge-yellow">Presentata il ${UI.formatDate(rb.data_presentazione)}</span>`;
        const v = rb || { km: t.km, importo_km: t.rimborso_km, indennita: t.indennita, spese: t.spese_da_rimborsare, totale: t.da_rimborsare };

        box.innerHTML = `
            <div class="comm-toolbar">
                <label class="sp-mese"><span>Mese</span><input type="month" class="form-control" id="sp-mese" value="${_mese}"></label>
                <span class="spacer"></span>
                <button type="button" class="btn btn-ghost" data-importa><i class="ph ph-receipt"></i> Importa scontrini</button>
                <button type="button" class="btn btn-primary" id="sp-nuova" ${bloccata ? 'disabled title="Nota spese già presentata: riaprila"' : ''}><i class="ph ph-plus"></i> Nuova spesa</button>
            </div>
            <section class="oggi-pannello sp-nota" aria-labelledby="sp-nota-t">
                <div class="sp-nota-testa"><h2 id="sp-nota-t">Nota spese di ${UI.esc(nomeMese(_mese))}</h2>${statoNota}</div>
                <div class="sp-totali">
                    <div><b>${UI.formatNumber(v.km)} km</b><span>${UI.formatCurrency(v.importo_km)} rimborso km</span></div>
                    <div><b>${UI.formatCurrency(v.indennita)}</b><span>indennità</span></div>
                    <div><b>${UI.formatCurrency(v.spese)}</b><span>spese da rimborsare</span></div>
                    <div><b>${UI.formatCurrency(v.totale)}</b><span>da rimborsare</span></div>
                </div>
                ${!rb && t.spese_aziendali > 0 ? `<p class="td-sub">Più ${UI.formatCurrency(t.spese_aziendali)} di spese pagate dalla società (carta aziendale, bonifico): rendicontate, non si rimborsano.</p>` : ''}
                ${!rb && t.carburante_doppio > 0 ? `<p class="td-sub">Esclusi ${UI.formatCurrency(t.carburante_doppio)} di carburante pagato di tasca propria nei giorni con rimborso km: la tariffa ACI lo comprende già.</p>` : ''}
                ${!rb && t.num_da_verificare > 0 ? `<p class="td-sub oggi-rosso">${UI.plurale(t.num_da_verificare, 'giornata', 'giornate')} senza indennità finché non inserisci la città del cliente o del sottocliente (serve a sapere se è fuori dal comune della sede).</p>` : ''}
                ${t.km_senza_costo && !rb ? '<p class="td-sub">Alcuni km non hanno un costo: imposta il costo €/km generale o quello ACI del mezzo.</p>' : ''}
                ${rb ? '<p class="td-sub">Totali congelati alla presentazione: per cambiare le spese riapri la nota.</p>' : ''}
                <div class="sp-azioni">
                    ${!rb ? '<button type="button" class="btn btn-primary btn-sm" data-nota="presenta"><i class="ph ph-paper-plane-tilt"></i> Presenta</button>' : ''}
                    ${rb && rb.stato === 'presentata' ? '<button type="button" class="btn btn-primary btn-sm" data-nota="rimborsata"><i class="ph ph-check"></i> Rimborsata</button>' : ''}
                    ${rb ? '<button type="button" class="btn btn-ghost btn-sm" data-nota="riapri"><i class="ph ph-arrow-counter-clockwise"></i> Riapri</button>' : ''}
                    <span class="td-sub">Il PDF del mese si stampa dalla scheda Viaggi, con l'elenco di queste spese.</span>
                </div>
            </section>
            ${senzaDoc || nonTracc ? `<div class="sp-avvisi">
                ${senzaDoc ? `<span class="badge badge-yellow">${UI.plurale(senzaDoc, 'spesa', 'spese')} senza giustificativo</span>` : ''}
                ${nonTracc ? `<span class="badge badge-red" title="Vitto, alloggio, viaggio e taxi pagati in contanti non sono deducibili (L. 207/2024)">${UI.plurale(nonTracc, 'spesa in contanti non deducibile', 'spese in contanti non deducibili')}</span>` : ''}
            </div>` : ''}
            <div class="table-container">
                <table class="data-table">
                    <thead><tr><th>Data</th><th>Spesa</th><th>Esercente</th><th>Pagamento</th><th>Giustificativo</th><th class="text-right">Importo</th><th></th></tr></thead>
                    <tbody>${spese.length ? spese.map(riga).join('') : `<tr><td colspan="7"><div class="empty-state"><i class="ph ph-receipt"></i><h3>Nessuna spesa</h3><p>Importa gli scontrini o registra le uscite della carta qui sotto</p></div></td></tr>`}</tbody>
                </table>
            </div>
            ${carta.length ? `<section class="sp-carta" aria-labelledby="sp-carta-t">
                <h3 id="sp-carta-t">Uscite della carta non registrate <span class="td-sub">— se sono di trasferta, registrale</span></h3>
                <ul class="sc-lista">${carta.map(m => `<li>
                    <span>${UI.formatDate(m.data_operazione)} · ${UI.esc(m.controparte || m.descrizione || '')}</span>
                    <span class="sc-dx">${UI.formatCurrency(Math.abs(m.importo))}
                        <select class="form-control sp-cat" data-mov="${m.id}" aria-label="Categoria">${Object.entries(CATEGORIE).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select>
                        <button type="button" class="btn btn-secondary btn-sm" data-registra="${m.id}" ${bloccata ? 'disabled' : ''}>Registra</button></span>
                </li>`).join('')}</ul>
            </section>` : ''}`;
        bind(box);
    }

    function riga(s) {
        return `<tr>
            <td>${UI.formatDate(s.data)}</td>
            <td class="td-primary">${UI.esc(CATEGORIE[s.categoria] || s.categoria)}${s.descrizione ? `<div class="td-sub">${UI.esc(s.descrizione)}</div>` : ''}</td>
            <td>${UI.esc(s.esercente || '—')}${s.cliente_nome ? `<div class="td-sub">${UI.esc(s.cliente_nome)}</div>` : ''}</td>
            <td>${UI.esc(METODI[s.metodo] || s.metodo)}${s.movimento_id ? ' <span class="badge badge-green" title="Abbinata al movimento della carta">carta ✓</span>' : ''}${s.da_segnalare ? ' <span class="badge badge-red" title="Non deducibile se pagata in contanti">non tracciabile</span>' : ''}${s.carburante_doppio ? ' <span class="badge badge-red" title="Quel giorno hai il rimborso km: la tariffa ACI comprende già il carburante">già nel rimborso km</span>' : ''}</td>
            <td>${s.ha_documento ? `<a href="api/router.php?module=spese&action=documento&id=${encodeURIComponent(s.id)}" target="_blank" rel="noopener"><i class="ph ph-file"></i> apri</a>` : '<span class="oggi-rosso">manca</span>'}</td>
            <td class="text-right td-primary">${UI.formatCurrency(s.importo)}</td>
            <td><div class="flex" style="gap:4px">
                <button type="button" class="btn btn-sm btn-ghost" data-mod="${s.id}" aria-label="Modifica"><i class="ph ph-pencil-simple"></i></button>
                <button type="button" class="btn btn-sm btn-danger" data-del="${s.id}" aria-label="Elimina"><i class="ph ph-trash"></i></button></div></td>
        </tr>`;
    }

    function bind(box) {
        document.getElementById('sp-mese').addEventListener('change', e => { if (e.target.value) { _mese = e.target.value; load(); } });
        document.getElementById('sp-nuova')?.addEventListener('click', () => form({ data: UI.todayLocal().slice(0, 7) === _mese ? UI.todayLocal() : _mese + '-01', metodo: 'carta', categoria: 'vitto' }));
        box.querySelectorAll('[data-mod]').forEach(b => b.addEventListener('click', () => form(_d.spese.find(s => s.id == b.dataset.mod))));
        box.querySelectorAll('[data-del]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Eliminare la spesa?')) return;
            try { await Store.api('delete', 'spese', { id: b.dataset.del }); UI.toast('Spesa eliminata'); load(); } catch (e) { UI.toast(e.message, 'error'); }
        }));
        box.querySelectorAll('[data-registra]').forEach(b => b.addEventListener('click', async () => {
            const cat = box.querySelector(`.sp-cat[data-mov="${b.dataset.registra}"]`).value;
            try { await Store.api('da_movimento', 'spese', { movimento_id: b.dataset.registra, categoria: cat }); UI.toast('Spesa registrata'); load(); } catch (e) { UI.toast(e.message, 'error'); }
        }));
        box.querySelectorAll('[data-nota]').forEach(b => b.addEventListener('click', async () => {
            const azione = b.dataset.nota;
            const par = { mese: _mese, azione };
            if (azione === 'rimborsata') {
                const d = await UI.chiedi({ titolo: 'Rimborso ricevuto', etichetta: 'Data del rimborso', valore: UI.todayLocal(), conferma: 'Registra' });
                if (!d) return;
                par.data = d;
            }
            if (azione === 'riapri' && !confirm('Riaprire la nota spese? I totali verranno ricalcolati.')) return;
            try {
                await Store.api('rimborso', 'spese', par);
                UI.toast({ presenta: 'Nota spese presentata', rimborsata: 'Rimborso registrato', riapri: 'Nota spese riaperta' }[azione]);
                load();
            } catch (e) { UI.toast(e.message, 'error'); }
        }));
    }

    function form(s) {
        const clienti = ModClienti.getClienti();
        const opts = (obj, sel) => Object.entries(obj).map(([k, l]) => `<option value="${k}" ${k === sel ? 'selected' : ''}>${l}</option>`).join('');
        UI.openModal(s.id ? 'Modifica spesa' : 'Nuova spesa', `<div class="form-grid">
            <div class="form-group"><label for="sp-data">Data *</label><input type="date" class="form-control" id="sp-data" value="${UI.esc(s.data || '')}"></div>
            <div class="form-group"><label for="sp-importo">Importo € *</label><input type="number" step="0.01" min="0" class="form-control" id="sp-importo" value="${UI.esc(s.importo ?? '')}" inputmode="decimal"></div>
            <div class="form-group"><label for="sp-cat">Spesa</label><select class="form-control" id="sp-cat">${opts(CATEGORIE, s.categoria)}</select></div>
            <div class="form-group"><label for="sp-metodo">Pagata con</label><select class="form-control" id="sp-metodo">${opts(METODI, s.metodo)}</select></div>
            <div class="form-group"><label for="sp-eserc">Esercente</label><input class="form-control" id="sp-eserc" value="${UI.esc(s.esercente || '')}"></div>
            <div class="form-group"><label for="sp-cli">Cliente</label><select class="form-control" id="sp-cli"><option value="">—</option>${clienti.map(c => `<option value="${c.id}" ${c.id == s.cliente_id ? 'selected' : ''}>${UI.esc(c.ragione_sociale)}</option>`).join('')}</select></div>
            <div class="form-group full-width"><label for="sp-desc">Descrizione</label><input class="form-control" id="sp-desc" value="${UI.esc(s.descrizione || '')}"></div>
            <div class="form-group full-width"><label for="sp-file">Giustificativo (foto o PDF)${s.ha_documento ? ' — già allegato: uno nuovo lo sostituisce' : ''}</label>
                <input type="file" class="form-control" id="sp-file" accept="image/*,application/pdf"></div>
            <p class="form-group full-width td-sub" id="sp-tracc" style="margin:0"></p>
        </div>`, async () => {
            const v = id => document.getElementById(id).value;
            const fd = new FormData();
            Object.entries({ id: s.id || '', data: v('sp-data'), importo: v('sp-importo'), categoria: v('sp-cat'), metodo: v('sp-metodo'),
                esercente: v('sp-eserc'), cliente_id: v('sp-cli'), descrizione: v('sp-desc') }).forEach(([k, val]) => fd.append(k, val ?? ''));
            const f = document.getElementById('sp-file').files[0];
            if (f) fd.append('file', f);
            const r = await Store.upload('save', 'spese', fd);
            UI.closeModal();
            UI.toast('Spesa salvata');
            load();
            return r;
        });
        // Avviso di tracciabilità mentre si compila
        const avvisa = () => {
            const tr = ['vitto', 'alloggio', 'treno', 'aereo', 'taxi'].includes(document.getElementById('sp-cat').value) && document.getElementById('sp-metodo').value === 'contanti';
            document.getElementById('sp-tracc').textContent = tr ? 'In contanti vitto, alloggio, viaggio e taxi non sono deducibili: se puoi, paga con carta.' : '';
        };
        ['sp-cat', 'sp-metodo'].forEach(id => document.getElementById(id).addEventListener('change', avvisa));
        avvisa();
    }

    function nomeMese(m) {
        const [y, mm] = m.split('-').map(Number);
        return new Date(y, mm - 1, 1).toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });
    }

    return { load };
})();

window.ModSpese = ModSpese;
