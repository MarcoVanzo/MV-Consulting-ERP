'use strict';

/**
 * Scheda cliente — tutto su un cliente o prospect in una finestra: situazione delle fatture,
 * referenti, note datate e storico (note + eventi di offerte, commesse e fatture), offerte e commesse.
 * Dati da clienti/scheda (SchedaClienteController).
 */
const ModScheda = (() => {
    let _id = null, _d = null;
    const TIPI_NOTA = { nota: ['ph-note', 'Nota'], chiamata: ['ph-phone', 'Chiamata'], email: ['ph-envelope', 'Email'], incontro: ['ph-users', 'Incontro'] };
    const ICONE = { offerta: 'ph-file-text', commessa: 'ph-briefcase', fattura: 'ph-receipt', incasso: 'ph-coins' };
    const STATI_OFF = { lead: 'Lead', bozza: 'Bozza', inviata: 'Inviata', accettata: 'Accettata', rifiutata: 'Rifiutata', scaduta: 'Scaduta' };

    async function apri(id) {
        _id = id;
        try {
            _d = await Store.api('scheda', 'clienti', { id });
            UI.openModal(_d.cliente.ragione_sociale, html(), null, { wide: true, readOnly: true });
            bind();
        } catch (e) { UI.toast(e.message, 'error'); }
    }

    /** Ricarica la scheda se è aperta (dopo aver salvato un lead, una nota, un referente). */
    async function aggiorna() {
        if (!_id || !document.getElementById('sc-scheda')) return;
        _d = await Store.api('scheda', 'clienti', { id: _id });
        document.getElementById('modal-body').innerHTML = html();
        bind();
    }

    function html() {
        const c = _d.cliente, s = _d.situazione;
        const contatti = [c.partita_iva && `P.IVA ${c.partita_iva}`, [c.citta, c.provincia && `(${c.provincia})`].filter(Boolean).join(' '), c.email, c.pec, c.telefono]
            .filter(Boolean).map(UI.esc).join(' · ');
        return `<div id="sc-scheda" class="sc">
            <div class="sc-testa">
                <span class="badge ${c.tipo === 'cliente' ? 'badge-green' : 'badge-gray'}">${c.tipo === 'cliente' ? 'Cliente' : 'Prospect'}</span>
                <span class="sc-contatti">${contatti || 'Nessun recapito'}</span>
                <span class="sc-azioni">
                    <button type="button" class="btn btn-ghost btn-sm" data-sc="modifica"><i class="ph ph-pencil-simple"></i> Dati</button>
                    <button type="button" class="btn btn-secondary btn-sm" data-sc="lead"><i class="ph ph-plus"></i> Nuovo lead</button>
                </span>
            </div>
            <div class="sc-numeri">
                <div><b>${UI.formatCurrency(s.fatturato_totale)}</b><span>fatturato (imponibile)</span></div>
                <div><b>${UI.formatCurrency(s.da_incassare)}</b><span>da incassare</span></div>
                <div><b class="${s.scaduto > 0 ? 'oggi-rosso' : ''}">${UI.formatCurrency(s.scaduto)}</b><span>scaduto</span></div>
            </div>
            <div class="sc-colonne">
                <section class="sc-sezione" aria-labelledby="sc-ref-t">
                    <h3 id="sc-ref-t">Referenti</h3>
                    ${_d.referenti.length ? `<ul class="sc-lista">${_d.referenti.map(r => `<li>
                        <div><b>${UI.esc(r.nome)}</b>${r.principale == 1 ? ' <span class="badge badge-blue">principale</span>' : ''}${r.ruolo ? ` <span class="td-sub">${UI.esc(r.ruolo)}</span>` : ''}
                            <div class="td-sub">${[r.email, r.telefono].filter(Boolean).map(UI.esc).join(' · ') || '—'}</div></div>
                        <span><button type="button" class="btn btn-ghost btn-sm" data-ref-edit="${r.id}" aria-label="Modifica ${UI.esc(r.nome)}"><i class="ph ph-pencil-simple"></i></button>
                        <button type="button" class="btn btn-ghost btn-sm" data-ref-del="${r.id}" aria-label="Elimina ${UI.esc(r.nome)}"><i class="ph ph-trash"></i></button></span>
                    </li>`).join('')}</ul>` : '<p class="td-sub">Nessun referente</p>'}
                    <button type="button" class="btn btn-ghost btn-sm" data-sc="referente"><i class="ph ph-plus"></i> Referente</button>
                </section>
                <section class="sc-sezione" aria-labelledby="sc-nota-t">
                    <h3 id="sc-nota-t">Nuova nota</h3>
                    <form id="sc-nota" class="sc-nota">
                        <div class="sc-nota-riga">
                            <select class="form-control" id="sc-nota-tipo" aria-label="Tipo">${Object.entries(TIPI_NOTA).map(([k, [, l]]) => `<option value="${k}">${l}</option>`).join('')}</select>
                            <input type="date" class="form-control" id="sc-nota-data" value="${UI.todayLocal()}" aria-label="Data">
                        </div>
                        <textarea class="form-control" id="sc-nota-testo" rows="2" placeholder="Cosa vi siete detti, cosa resta da fare" aria-label="Testo della nota"></textarea>
                        <button type="submit" class="btn btn-primary btn-sm">Salva nota</button>
                    </form>
                </section>
            </div>
            <section class="sc-sezione" aria-labelledby="sc-off-t">
                <h3 id="sc-off-t">Offerte e lead</h3>
                ${_d.offerte.length ? `<ul class="sc-lista">${_d.offerte.map(o => `<li>
                    <button type="button" class="sc-link" data-off="${o.id}">${UI.esc(o.numero)}${o.versione > 1 ? ' v' + o.versione : ''} · ${UI.esc(o.oggetto)}</button>
                    <span class="sc-dx">${UI.formatCurrency(o.imponibile)} <span class="badge badge-gray">${STATI_OFF[o.stato] || UI.esc(o.stato)}</span></span>
                </li>`).join('')}</ul>` : '<p class="td-sub">Nessuna offerta</p>'}
            </section>
            <section class="sc-sezione" aria-labelledby="sc-com-t">
                <h3 id="sc-com-t">Commesse</h3>
                ${_d.commesse.length ? `<ul class="sc-lista">${_d.commesse.map(c => `<li>
                    <button type="button" class="sc-link" data-com="${c.id}">${UI.formatDate(c.data_incarico)} · ${UI.esc(c.descrizione || 'Commessa #' + c.id)}</button>
                    <span class="sc-dx">${UI.formatCurrency(c.fatturato)} di ${UI.formatCurrency(c.importo_totale)}</span>
                </li>`).join('')}</ul>` : '<p class="td-sub">Nessuna commessa</p>'}
            </section>
            <section class="sc-sezione" aria-labelledby="sc-sto-t">
                <h3 id="sc-sto-t">Storico</h3>
                ${_d.storico.length ? `<ol class="sc-storico">${_d.storico.map(e => {
                    const [icona] = TIPI_NOTA[e.tipo] || [ICONE[e.tipo] || 'ph-dot'];
                    return `<li><span class="sc-data">${UI.formatDate(e.data)}</span><i class="ph ${icona}" aria-hidden="true"></i>
                        <span class="sc-testo">${UI.esc(e.testo)}</span>
                        ${e.nota_id ? `<button type="button" class="btn btn-ghost btn-sm" data-nota-del="${e.nota_id}" aria-label="Elimina la nota"><i class="ph ph-trash"></i></button>` : ''}</li>`;
                }).join('')}</ol>` : '<p class="td-sub">Ancora niente</p>'}
            </section>
        </div>`;
    }

    function bind() {
        const q = sel => document.querySelectorAll(`#sc-scheda ${sel}`);
        q('[data-sc="modifica"]').forEach(b => b.addEventListener('click', () => ModClienti.edit(_id)));
        q('[data-sc="lead"]').forEach(b => b.addEventListener('click', () => ModOfferte.openLead({}, _id)));
        q('[data-sc="referente"]').forEach(b => b.addEventListener('click', () => referente({})));
        q('[data-ref-edit]').forEach(b => b.addEventListener('click', () => referente(_d.referenti.find(r => r.id == b.dataset.refEdit) || {})));
        q('[data-ref-del]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Eliminare il referente?')) return;
            try { await Store.api('referente_delete', 'clienti', { id: b.dataset.refDel }); aggiorna(); } catch (e) { UI.toast(e.message, 'error'); }
        }));
        q('[data-nota-del]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Eliminare la nota?')) return;
            try { await Store.api('nota_delete', 'clienti', { id: b.dataset.notaDel }); aggiorna(); } catch (e) { UI.toast(e.message, 'error'); }
        }));
        q('[data-off]').forEach(b => b.addEventListener('click', () => ModOfferte.edit(+b.dataset.off)));
        q('[data-com]').forEach(b => b.addEventListener('click', () => ModCommessa.open(+b.dataset.com, () => apri(_id))));
        document.getElementById('sc-nota')?.addEventListener('submit', async e => {
            e.preventDefault();
            const testo = document.getElementById('sc-nota-testo').value.trim();
            if (!testo) { UI.toast('Scrivi il testo della nota', 'error'); return; }
            try {
                await Store.api('nota_save', 'clienti', { cliente_id: _id, tipo: document.getElementById('sc-nota-tipo').value,
                    data: document.getElementById('sc-nota-data').value, testo });
                UI.toast('Nota salvata');
                aggiorna();
            } catch (err) { UI.toast(err.message, 'error'); }
        });
    }

    /** Referente: finestra sopra la scheda, che poi si riapre aggiornata. */
    function referente(r) {
        UI.openModal(r.id ? 'Modifica referente' : 'Nuovo referente', `<div class="form-grid">
            <div class="form-group"><label for="rf-nome">Nome *</label><input class="form-control" id="rf-nome" value="${UI.esc(r.nome || '')}"></div>
            <div class="form-group"><label for="rf-ruolo">Ruolo</label><input class="form-control" id="rf-ruolo" value="${UI.esc(r.ruolo || '')}" placeholder="es. Amministrazione, DPO"></div>
            <div class="form-group"><label for="rf-email">Email</label><input type="email" class="form-control" id="rf-email" value="${UI.esc(r.email || '')}"></div>
            <div class="form-group"><label for="rf-tel">Telefono</label><input type="tel" class="form-control" id="rf-tel" value="${UI.esc(r.telefono || '')}"></div>
            <div class="form-group full-width"><label for="rf-note">Note</label><input class="form-control" id="rf-note" value="${UI.esc(r.note || '')}"></div>
            <label class="form-group full-width sc-check"><input type="checkbox" id="rf-princ" ${r.principale == 1 ? 'checked' : ''}> Referente principale</label>
        </div>`, async () => {
            const v = id => document.getElementById(id).value;
            await Store.api('referente_save', 'clienti', { id: r.id || '', cliente_id: _id, nome: v('rf-nome'), ruolo: v('rf-ruolo'),
                email: v('rf-email'), telefono: v('rf-tel'), note: v('rf-note'), principale: document.getElementById('rf-princ').checked ? '1' : '0' });
            UI.toast('Referente salvato');
            apri(_id);
        });
    }

    return { apri, aggiorna };
})();

window.ModScheda = ModScheda;
