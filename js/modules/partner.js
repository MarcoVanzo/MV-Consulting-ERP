'use strict';
/**
 * Modulo Partner — partner e fornitori, le loro fatture (a mano o da XML/p7m)
 * e l'editor dei costi di commessa usato da offerte e scheda commessa.
 */
const ModPartner = (() => {
    let _fornitori = [], _passive = [], _filtro = 'da_pagare';

    const num = v => parseFloat(String(v).replace(',', '.')) || 0;

    async function fornitori(force = false) {
        if (force || !_fornitori.length) _fornitori = (await Store.api('list', 'fornitori')) || [];
        return _fornitori;
    }

    /** Ricarica le due schede: fatture ricevute (Fatture) e anagrafica partner/fornitori (Anagrafiche). */
    async function load() {
        await Promise.all([loadPassive(), loadFornitori()]);
    }

    async function loadPassive() {
        const box = document.getElementById('inc-ricevute');
        if (!box) return;
        try {
            await fornitori(true);
            _passive = (await Store.api('list', 'passive', { year: UI.anno(), stato: _filtro })) || [];
            const chips = [['da_pagare', 'Da pagare'], ['pagata', 'Pagate'], ['', 'Tutte']]
                .map(([v, l]) => `<button type="button" class="filter-chip ${_filtro === v ? 'active' : ''}" data-f="${v}">${l}</button>`).join('');
            const daPagare = _passive.filter(f => f.stato === 'da_pagare').reduce((a, f) => a + num(f.importo_totale), 0);
            box.innerHTML = `
                <div class="comm-toolbar">
                    <button class="btn btn-ghost" data-importa type="button"><i class="ph ph-upload-simple"></i> Importa file</button>
                    <button class="btn btn-ghost" id="pa-abbina" type="button" title="Abbina i bonifici alle fatture, fornitore per fornitore"><i class="ph ph-arrows-merge"></i> Abbina ai bonifici</button>
                    <button class="btn btn-ghost" id="pa-pagate" type="button" disabled title="Per le fatture pagate fuori dagli estratti caricati"><i class="ph ph-checks"></i> Segna pagate</button>
                    <button class="btn btn-primary" id="pa-new-fatt" type="button"><i class="ph ph-plus"></i> Fattura fornitore</button>
                </div>
                <div class="table-container">
                    <div class="table-toolbar"><div class="filters-row" style="margin-bottom:0">${chips}</div>
                        <span style="color:var(--text-muted);font-size:0.85rem">Da pagare: ${UI.formatCurrency(daPagare)}</span></div>
                    <table class="data-table tabella-schede"><thead><tr>
                        <th><input type="checkbox" id="pa-tutte" aria-label="Seleziona tutte le fatture da pagare"></th><th>Numero</th><th>Data</th><th>Fornitore</th><th>Commessa</th><th class="text-right">Imponibile</th>
                        <th class="text-right">Netto a pagare</th><th>Scadenza</th><th>Stato</th><th></th></tr></thead>
                    <tbody>${rowsPassive()}</tbody></table>
                </div>`;
            box.querySelectorAll('.filter-chip').forEach(c => c.addEventListener('click', () => { _filtro = c.dataset.f; loadPassive(); }));
            document.getElementById('pa-new-fatt').addEventListener('click', () => openPassiva({}));
            document.getElementById('pa-abbina').addEventListener('click', () => abbinaPerFornitore());
            const scelte = () => [...box.querySelectorAll('.pa-sel:checked')].map(c => c.value);
            const aggiorna = () => {
                const n = scelte().length;
                const b = document.getElementById('pa-pagate');
                b.disabled = !n;
                b.innerHTML = `<i class="ph ph-checks"></i> Segna pagate${n ? ` (${n})` : ''}`;
            };
            box.querySelectorAll('.pa-sel').forEach(c => c.addEventListener('change', aggiorna));
            document.getElementById('pa-tutte').addEventListener('change', e => {
                box.querySelectorAll('.pa-sel').forEach(c => { c.checked = e.target.checked; });
                aggiorna();
            });
            document.getElementById('pa-pagate').addEventListener('click', () => segnaPagate(scelte()));
            bindAzioni(box);
        } catch (e) {
            box.innerHTML = `<div class="empty-state"><h3>Errore</h3><p>${UI.esc(e.message)}</p></div>`;
        }
    }

    async function loadFornitori() {
        const box = document.getElementById('anag-fornitori');
        if (!box) return;
        try {
            await fornitori(true);
            const daPagare = _fornitori.reduce((a, f) => a + num(f.da_pagare), 0);
            box.innerHTML = `
                <div class="comm-toolbar">
                    <button class="btn btn-primary" id="pa-new" type="button"><i class="ph ph-plus"></i> Nuovo partner o fornitore</button>
                </div>
                <div class="table-container">
                    <div class="table-toolbar"><b>Partner e fornitori</b><span style="color:var(--text-muted);font-size:0.85rem">Da pagare in totale: ${UI.formatCurrency(daPagare)}</span></div>
                    <table class="data-table"><thead><tr>
                        <th>Ragione sociale</th><th>Tipo</th><th>P.IVA</th><th>IBAN</th><th class="text-right">Commesse</th><th class="text-right">Da pagare</th><th></th></tr></thead>
                    <tbody>${rowsFornitori()}</tbody></table>
                </div>`;
            document.getElementById('pa-new').addEventListener('click', () => openFornitore({}));
            bindAzioni(box);
        } catch (e) {
            box.innerHTML = `<div class="empty-state"><h3>Errore</h3><p>${UI.esc(e.message)}</p></div>`;
        }
    }

    function bindAzioni(box) {
        box.querySelectorAll('[data-act]').forEach(b => b.addEventListener('click', e => {
            e.preventDefault(); // i link hanno href="#": senza, cambierebbe l'hash e la pagina salterebbe in cima
            azione(b.dataset.act, parseInt(b.dataset.id, 10));
        }));
    }

    function rowsPassive() {
        if (!_passive.length) return '<tr><td colspan="10"><div class="empty-state"><i class="ph ph-receipt"></i><h3>Nessuna fattura</h3><p>Importa gli XML scaricati da Sistemi o dal cassetto fiscale</p></div></td></tr>';
        const oggi = UI.todayLocal();
        return _passive.map(f => {
            const scaduta = f.stato === 'da_pagare' && f.data_scadenza && f.data_scadenza < oggi;
            return `<tr>
                <td>${f.stato === 'da_pagare' ? `<input type="checkbox" class="pa-sel" value="${f.id}" aria-label="Seleziona la fattura ${UI.esc(f.numero)}">` : ''}</td>
                <td class="td-mono">${UI.esc(f.numero)}</td>
                <td>${UI.formatDate(f.data_emissione)}</td>
                <td class="td-primary cella-titolo">${UI.esc(f.fornitore_nome || '—')}</td>
                <td>${f.incarico_id ? `<a href="#" data-act="commessa" data-id="${f.incarico_id}">${UI.esc(f.cliente_nome || '#' + f.incarico_id)}</a>` : '<span class="text-danger">da collegare</span>'}${f.condizione_pagamento === 'back_to_back' ? ' <span class="badge badge-purple" title="Pago quando incasso">B2B</span>' : ''}</td>
                <td class="text-right">${UI.formatCurrency(f.imponibile)}</td>
                <td class="text-right td-primary">${UI.formatCurrency(f.importo_totale)}${num(f.ritenuta) ? `<div style="font-size:0.72rem;color:var(--text-muted)">rit. ${UI.formatCurrency(f.ritenuta)}</div>` : ''}</td>
                <td class="${scaduta ? 'text-danger' : ''}">${UI.formatDate(f.data_scadenza)}</td>
                <td>${f.stato === 'pagata' ? `<span class="badge badge-green">Pagata ${UI.formatDate(f.data_pagamento)}</span>` : '<span class="badge badge-yellow">Da pagare</span>'}</td>
                <td><div class="flex" style="gap:4px">
                    ${f.stato === 'da_pagare' ? `<button class="btn btn-sm btn-primary" data-act="paga" data-id="${f.id}" title="Segna pagata" aria-label="Segna pagata"><i class="ph ph-check"></i></button>` : ''}
                    <button class="btn btn-sm btn-ghost" data-act="edit-passiva" data-id="${f.id}" aria-label="Modifica"><i class="ph ph-pencil-simple"></i></button>
                    <button class="btn btn-sm btn-danger" data-act="del-passiva" data-id="${f.id}" aria-label="Elimina"><i class="ph ph-trash"></i></button>
                </div></td></tr>`;
        }).join('');
    }

    function rowsFornitori() {
        if (!_fornitori.length) return '<tr><td colspan="7"><div class="empty-state"><i class="ph ph-handshake"></i><h3>Nessun partner</h3></div></td></tr>';
        return _fornitori.map(f => `<tr>
            <td class="td-primary">${UI.esc(f.ragione_sociale)}</td>
            <td>${f.tipo === 'partner' ? '<span class="badge badge-blue">Partner</span>' : '<span class="badge badge-purple">Fornitore</span>'}</td>
            <td class="td-mono">${UI.esc(f.partita_iva || '—')}</td>
            <td class="td-mono" style="font-size:0.72rem">${UI.esc(f.iban || '—')}</td>
            <td class="text-right">${UI.esc(f.num_commesse)}</td>
            <td class="text-right td-primary">${UI.formatCurrency(f.da_pagare)}</td>
            <td><div class="flex" style="gap:4px">
                <button class="btn btn-sm btn-ghost" data-act="edit-forn" data-id="${f.id}" aria-label="Modifica"><i class="ph ph-pencil-simple"></i></button>
                <button class="btn btn-sm btn-danger" data-act="del-forn" data-id="${f.id}" aria-label="Elimina"><i class="ph ph-trash"></i></button>
            </div></td></tr>`).join('');
    }

    async function azione(act, id) {
        try {
            switch (act) {
                case 'edit-forn': openFornitore(_fornitori.find(f => f.id == id) || {}); break;
                case 'del-forn':
                    if (!confirm('Eliminare il partner? Le fatture restano.')) return;
                    await Store.api('delete', 'fornitori', { id });
                    load();
                    break;
                case 'edit-passiva': openPassiva(_passive.find(f => f.id == id) || {}); break;
                case 'del-passiva':
                    if (!confirm('Eliminare la fattura del fornitore?')) return;
                    await Store.api('delete', 'passive', { id });
                    load();
                    break;
                case 'paga': {
                    const d = await UI.chiedi({ titolo: 'Pagamento della fattura', etichetta: 'Data del pagamento', valore: UI.todayLocal(), conferma: 'Registra pagamento' });
                    if (!d) return;
                    await Store.api('set_pagata', 'passive', { id, data_pagamento: d });
                    UI.toast('Pagamento registrato');
                    load();
                    break;
                }
                case 'commessa': ModCommessa.open(id, load); break;
            }
        } catch (e) { UI.toast(e.message || 'Errore', 'error'); }
    }

    // ── Pagamenti ───────────────────────────────────────

    /** Fatture pagate fuori dagli estratti caricati (carta, altro conto): stato pagata con una data. */
    async function segnaPagate(ids) {
        if (!ids.length) return;
        const d = await UI.chiedi({ titolo: `Segna pagate ${ids.length} fatture`, etichetta: 'Data del pagamento', valore: UI.todayLocal(), conferma: 'Segna pagate' });
        if (!d) return;
        try {
            const r = await Store.api('set_pagate', 'passive', { ids: JSON.stringify(ids), data_pagamento: d }) || {};
            UI.toast(`${r.pagate ?? ids.length} fatture segnate pagate`);
            load();
        } catch (e) { UI.toast(e.message || 'Errore', 'error'); }
    }

    /**
     * Abbinamento per fornitore: per ogni fornitore le fatture da pagare e i bonifici che sembrano suoi
     * (anche precedenti alla fattura). Si tolgono o aggiungono bonifici e si conferma: i bonifici pagano
     * le fatture in ordine di data. Una differenza piccola (tassa di soggiorno, extra) si può chiudere.
     */
    async function abbinaPerFornitore() {
        let dati;
        try { dati = await Store.api('per_fornitore', 'riconciliazione') || {}; }
        catch (e) { UI.toast(e.message || 'Errore', 'error'); return; }
        const gruppi = dati.fornitori || [];
        const liberi = dati.liberi || [];
        const maxPerc = Math.round((dati.max_differenza || 0.05) * 100);
        const conBonifici = gruppi.filter(g => g.movimenti.length);
        const senza = gruppi.filter(g => !g.movimenti.length);
        const riga = (m, sel) => `<label class="pf-riga"><input type="checkbox" class="pf-mov" value="${m.id}" data-imp="${m.importo}" ${sel ? 'checked' : ''}>
            <span>${UI.formatDate(m.data)}</span><span class="pf-desc" title="${UI.esc(m.descrizione)}">${UI.esc(m.nome_banca || m.descrizione)}${m.come === 'parola' ? ' <span class="badge badge-yellow">da verificare</span>' : ''}</span>
            <b>${UI.formatCurrency(m.importo)}</b></label>`;
        const scheda = g => `<details class="pf-gruppo" data-f="${g.fornitore_id}">
            <summary><b>${UI.esc(g.nome)}</b><span class="pf-tot" data-tot></span></summary>
            <div class="pf-col"><div class="pf-tit">Fatture da pagare</div>
                ${g.fatture.map(f => `<label class="pf-riga"><input type="checkbox" class="pf-fat" value="${f.id}" data-imp="${f.residuo}" checked>
                    <span>${UI.formatDate(f.data_emissione)}</span><span class="pf-desc">n. ${UI.esc(f.numero)}</span><b>${UI.formatCurrency(f.residuo)}</b></label>`).join('')}</div>
            <div class="pf-col"><div class="pf-tit">Bonifici</div><div data-movs>${g.movimenti.map(m => riga(m, m.proposto)).join('') || '<p class="pf-vuoto">Nessun bonifico riconosciuto</p>'}</div>
                <button type="button" class="btn btn-sm btn-ghost" data-aggiungi><i class="ph ph-plus"></i> Aggiungi un bonifico</button>
                <div data-cerca hidden><input class="form-control" placeholder="Cerca per nome o importo" data-filtro><div class="pf-liberi" data-liberi></div></div></div>
            <div class="pf-azioni"><label class="pf-chiudi" hidden><input type="checkbox" data-chiudi> Chiudi anche la differenza</label>
                <button type="button" class="btn btn-sm btn-primary" data-conferma><i class="ph ph-arrows-merge"></i> Abbina</button></div>
        </details>`;
        UI.openModal('Abbina i bonifici alle fatture', `
            <p class="pf-nota">Per ogni fornitore scegli i bonifici che pagano le sue fatture, anche se partiti prima della fattura (acconti, caparre).
            I bonifici pagano le fatture in ordine di data; il nome in banca del fornitore si impara. Si annulla dalla Banca, come ogni abbinamento.
            Una differenza fino al ${maxPerc}% si può chiudere; per le fatture pagate in altro modo usa «Segna pagate».</p>
            ${conBonifici.length ? conBonifici.map(scheda).join('') : '<p class="pf-vuoto">Nessun fornitore con bonifici riconosciuti.</p>'}
            ${senza.length ? `<div class="pf-tit" style="margin-top:12px">Senza bonifici riconosciuti</div>${senza.map(scheda).join('')}` : ''}`,
            null, { wide: true, readOnly: true });

        const body = document.getElementById('modal-body');
        const somma = (el, sel) => [...el.querySelectorAll(sel + ':checked')].reduce((a, c) => a + num(c.dataset.imp), 0);
        const ricalcola = el => {
            const f = somma(el, '.pf-fat'), m = somma(el, '.pf-mov');
            const diff = Math.round((f - m) * 100) / 100;
            const chiudibile = m > 0 && diff > 0.01 && diff <= f * maxPerc / 100;
            el.querySelector('[data-tot]').innerHTML = `fatture ${UI.formatCurrency(f)} · bonifici ${UI.formatCurrency(m)}`
                + (Math.abs(diff) > 0.01 ? ` · <span class="${chiudibile ? '' : 'text-danger'}">${diff > 0 ? 'mancano' : 'avanzano'} ${UI.formatCurrency(Math.abs(diff))}</span>` : ' · <span class="badge badge-green">pari</span>');
            const ch = el.querySelector('.pf-chiudi');
            ch.hidden = !chiudibile;
            if (!chiudibile) ch.querySelector('input').checked = false;
        };
        body.querySelectorAll('.pf-gruppo').forEach(el => {
            ricalcola(el);
            el.addEventListener('change', e => { if (e.target.matches('.pf-fat, .pf-mov')) ricalcola(el); });
            const box = el.querySelector('[data-cerca]');
            const filtra = () => {
                const q = el.querySelector('[data-filtro]').value.trim().toLowerCase();
                const gia = new Set([...el.querySelectorAll('[data-movs] .pf-mov')].map(c => c.value));
                const trovati = liberi.filter(m => !gia.has(String(m.id)) && (!q || (m.descrizione + ' ' + m.importo).toLowerCase().includes(q.replace(',', '.')))).slice(0, 30);
                el.querySelector('[data-liberi]').innerHTML = trovati.map(m => `<button type="button" class="pf-riga pf-libero" data-id="${m.id}">
                    <span>${UI.formatDate(m.data)}</span><span class="pf-desc">${UI.esc(m.nome_banca || m.descrizione)}</span><b>${UI.formatCurrency(m.importo)}</b></button>`).join('') || '<p class="pf-vuoto">Nessun bonifico</p>';
            };
            el.querySelector('[data-aggiungi]').addEventListener('click', () => { box.hidden = !box.hidden; if (!box.hidden) filtra(); });
            el.querySelector('[data-filtro]').addEventListener('input', filtra);
            el.querySelector('[data-liberi]').addEventListener('click', e => {
                const b = e.target.closest('.pf-libero');
                if (!b) return;
                const m = liberi.find(x => String(x.id) === b.dataset.id);
                const movs = el.querySelector('[data-movs]');
                movs.querySelector('.pf-vuoto')?.remove();
                movs.insertAdjacentHTML('beforeend', riga(m, true));
                ricalcola(el);
                filtra();
            });
            const btn = el.querySelector('[data-conferma]');
            btn.addEventListener('click', async () => {
                const ids = sel => [...el.querySelectorAll(sel + ':checked')].map(c => c.value);
                const fatture = ids('.pf-fat'), movimenti = ids('.pf-mov');
                if (!fatture.length) { UI.toast('Scegli almeno una fattura', 'error'); return; }
                if (!movimenti.length) { UI.toast('Scegli almeno un bonifico, o usa «Segna pagate»', 'error'); return; }
                btn.disabled = true;
                try {
                    const r = await Store.api('abbina_fornitore', 'riconciliazione', { fornitore_id: el.dataset.f, fatture: JSON.stringify(fatture),
                        movimenti: JSON.stringify(movimenti), chiudi_differenza: el.querySelector('[data-chiudi]').checked ? '1' : '' }) || {};
                    const extra = [r.chiuse_con_differenza?.length ? `${r.chiuse_con_differenza.length} chiuse con la differenza` : '',
                        r.scoperto > 0 ? `restano ${UI.formatCurrency(r.scoperto)} da pagare` : '',
                        r.avanzo > 0 ? `${UI.formatCurrency(r.avanzo)} di bonifici restano da abbinare` : ''].filter(Boolean).join(', ');
                    UI.toast(`${r.fatture_saldate} fatture saldate${extra ? ': ' + extra : ''}`);
                    // Bonifici usati: non più proponibili agli altri fornitori (uno usato in parte lo ricalcola il server)
                    movimenti.forEach(id => { const i = liberi.findIndex(m => String(m.id) === id); if (i >= 0) liberi.splice(i, 1); });
                    body.querySelectorAll('.pf-gruppo').forEach(altro => movimenti.forEach(id => altro.querySelector(`.pf-mov[value="${id}"]`)?.closest('.pf-riga')?.remove()));
                    body.querySelectorAll('.pf-gruppo').forEach(ricalcola);
                    el.remove();
                    load();
                } catch (err) {
                    UI.toast(err.message || 'Errore', 'error');
                    btn.disabled = false;
                }
            });
        });
    }

    // ── Anagrafica partner ──────────────────────────────

    function openFornitore(f) {
        const t = ['partner', 'fornitore'].map(x => `<option value="${x}" ${x === (f.tipo || 'partner') ? 'selected' : ''}>${x === 'partner' ? 'Partner' : 'Fornitore'}</option>`).join('');
        UI.openModal(f.id ? 'Modifica partner' : 'Nuovo partner', `<div class="form-grid">
            <div class="form-group full-width"><label>Ragione sociale *</label><input class="form-control" id="fo-rs" value="${UI.esc(f.ragione_sociale || '')}"></div>
            <div class="form-group"><label>Tipo</label><select class="form-control" id="fo-tipo">${t}</select></div>
            <div class="form-group"><label>Pagamento standard (gg)</label><input type="number" class="form-control" id="fo-gg" value="${UI.esc(f.giorni_pagamento ?? 30)}"></div>
            <div class="form-group"><label>P.IVA</label><div style="display:flex;gap:4px"><input class="form-control" id="fo-piva" value="${UI.esc(f.partita_iva || '')}" inputmode="numeric">
                <button type="button" class="btn btn-secondary btn-sm" id="fo-cerca" title="Compila dai dati della partita IVA" aria-label="Compila dai dati della partita IVA"><i class="ph ph-magnifying-glass"></i></button></div></div>
            <div class="form-group"><label>Codice fiscale</label><input class="form-control" id="fo-cf" value="${UI.esc(f.codice_fiscale || '')}"></div>
            <div class="form-group full-width"><label>IBAN</label><input class="form-control" id="fo-iban" value="${UI.esc(f.iban || '')}"></div>
            <div class="form-group full-width" id="fo-cat-box" style="display:none"><label>Categoria dei pagamenti</label><select class="form-control" id="fo-cat"><option value="">— Fornitori e partner —</option></select></div>
            <div class="form-group"><label>Email</label><input class="form-control" id="fo-email" value="${UI.esc(f.email || '')}"></div>
            <div class="form-group"><label>Telefono</label><input class="form-control" id="fo-tel" value="${UI.esc(f.telefono || '')}"></div>
            <div class="form-group full-width"><label>Note</label><textarea class="form-control" id="fo-note">${UI.esc(f.note || '')}</textarea></div>
        </div>`, async () => {
            const v = id => document.getElementById(id).value;
            const dati = { id: f.id, ragione_sociale: v('fo-rs'), tipo: v('fo-tipo'), giorni_pagamento: v('fo-gg'),
                partita_iva: v('fo-piva'), codice_fiscale: v('fo-cf'), iban: v('fo-iban'), email: v('fo-email'), telefono: v('fo-tel'), note: v('fo-note') };
            // Categoria inviata solo se il campo è stato caricato (categorie attive)
            if (document.getElementById('fo-cat-box').style.display !== 'none') dati.categoria_default_id = v('fo-cat');
            await Store.api('save', 'fornitori', dati);
            UI.closeModal();
            UI.toast('Partner salvato');
            load();
        });
        // Compila ragione sociale, CF e PEC dalla partita IVA (stesso servizio dei clienti)
        document.getElementById('fo-cerca').addEventListener('click', async () => {
            try {
                const d = await UI.cercaPiva(document.getElementById('fo-piva').value);
                if (d.ragione_sociale) document.getElementById('fo-rs').value = d.ragione_sociale;
                if (d.codice_fiscale) document.getElementById('fo-cf').value = d.codice_fiscale;
                document.getElementById('fo-piva').value = d.partita_iva;
                const gia = UI.testoGiaPresenti({ gia_presenti: d.gia_presenti.filter(x => !(x.tipo !== 'cliente' && x.id === f.id)) });
                UI.toast(gia || 'Dati compilati dalla partita IVA', gia ? 'error' : 'success');
            } catch (e) { UI.toast(e.message, 'error'); }
        });
        // Categorie di uscita (grafici "Andamento"): il campo compare solo se il modulo categorie è attivo
        if (window.ModMovimenti) ModMovimenti.categorie('uscita').then(cats => {
            const sel = document.getElementById('fo-cat');
            if (!sel || !cats.length) return;
            cats.forEach(c => { const o = document.createElement('option'); o.value = c.id; o.textContent = c.nome; if (c.id == f.categoria_default_id) o.selected = true; sel.appendChild(o); });
            document.getElementById('fo-cat-box').style.display = '';
        }).catch(() => {});
    }

    // ── Fattura fornitore ───────────────────────────────

    async function openPassiva(f) {
        await fornitori();
        const fOpts = _fornitori.map(x => `<option value="${x.id}" ${x.id == f.fornitore_id ? 'selected' : ''}>${UI.esc(x.ragione_sociale)}</option>`).join('');
        UI.openModal(f.id ? `Fattura ${f.numero}` : 'Fattura fornitore', `<div class="form-grid">
            <div class="form-group"><label>Fornitore *</label><select class="form-control" id="fp-forn"><option value="">—</option>${fOpts}</select></div>
            <div class="form-group"><label>Costo di commessa</label><select class="form-control" id="fp-costo"><option value="">— da collegare —</option></select></div>
            <div class="form-group"><label>Numero *</label><input class="form-control" id="fp-num" value="${UI.esc(f.numero || '')}"></div>
            <div class="form-group"><label>Data</label><input type="date" class="form-control" id="fp-data" value="${UI.esc(f.data_emissione || UI.todayLocal())}"></div>
            <div class="form-group"><label>Imponibile</label><input type="number" step="0.01" class="form-control" id="fp-imp" value="${UI.esc(f.imponibile || 0)}"></div>
            <div class="form-group"><label>IVA</label><input type="number" step="0.01" class="form-control" id="fp-iva" value="${UI.esc(f.importo_iva || 0)}"></div>
            <div class="form-group"><label>Ritenuta d'acconto</label><input type="number" step="0.01" class="form-control" id="fp-rit" value="${UI.esc(f.ritenuta || 0)}"></div>
            <div class="form-group"><label>Scadenza</label><input type="date" class="form-control" id="fp-scad" value="${UI.esc(f.data_scadenza || '')}"></div>
            <div class="form-group"><label>Pagata il</label><input type="date" class="form-control" id="fp-pag" value="${UI.esc(f.data_pagamento || '')}"></div>
            <div class="form-group full-width"><label>Descrizione</label><textarea class="form-control" id="fp-desc">${UI.esc(f.descrizione || '')}</textarea></div>
        </div>`, async () => {
            const v = id => document.getElementById(id).value;
            await Store.api('save', 'passive', { id: f.id, fornitore_id: v('fp-forn'), costo_id: v('fp-costo'), numero: v('fp-num'),
                data_emissione: v('fp-data'), imponibile: v('fp-imp'), importo_iva: v('fp-iva'), ritenuta: v('fp-rit'),
                data_scadenza: v('fp-scad'), data_pagamento: v('fp-pag'), descrizione: v('fp-desc'), incarico_id: f.incarico_id || '' });
            UI.closeModal();
            UI.toast('Fattura salvata');
            load();
        });
        const sel = document.getElementById('fp-forn');
        const loadCosti = async (fid, selId) => {
            const cs = document.getElementById('fp-costo');
            cs.innerHTML = '<option value="">— da collegare —</option>';
            if (!fid) return;
            const costi = (await Store.api('costi_fornitore', 'fornitori', { fornitore_id: fid })) || [];
            costi.forEach(c => {
                const o = document.createElement('option');
                o.value = c.id;
                o.textContent = `${c.cliente_nome || 'Commessa #' + c.incarico_id} — ${c.descrizione} (${UI.formatCurrency(c.importo_previsto)})`;
                if (c.id == selId) o.selected = true;
                cs.appendChild(o);
            });
        };
        sel.addEventListener('change', () => loadCosti(sel.value));
        if (f.fornitore_id) loadCosti(f.fornitore_id, f.costo_id);
    }

    // ── Editor costi di commessa (offerta o incarico) ───

    /**
     * @param {HTMLElement} box
     * @param {Array} costi
     * @param {{offerta_id?:number, incarico_id?:number}} ctx
     * @param {Function} onChange ricarica il contenitore
     */
    async function renderCosti(box, costi, ctx, onChange) {
        await fornitori();
        const fOpts = sel => _fornitori.map(f => `<option value="${f.id}" ${f.id == sel ? 'selected' : ''}>${UI.esc(f.ragione_sociale)}</option>`).join('');
        const tot = costi.reduce((a, c) => a + num(c.importo_previsto), 0);
        box.innerHTML = `
            ${costi.length ? `<table class="rows-editor"><thead><tr><th>Partner</th><th>Cosa</th><th class="text-right">Previsto</th><th class="text-right">Fatturato</th><th>Pagamento</th><th></th></tr></thead><tbody>
            ${costi.map(c => `<tr>
                <td class="td-primary" style="color:var(--text-primary)">${UI.esc(c.fornitore_nome || '—')}</td>
                <td style="font-size:0.82rem">${UI.esc(c.descrizione)}${c.offerta_fornitore_numero ? `<div style="color:var(--text-muted);font-size:0.75rem">Offerta partner n. ${UI.esc(c.offerta_fornitore_numero)}${c.offerta_fornitore_data ? ' del ' + UI.formatDate(c.offerta_fornitore_data) : ''}</div>` : ''}</td>
                <td class="text-right">${UI.formatCurrency(c.importo_previsto)}</td>
                <td class="text-right">${c.fatturato !== undefined ? UI.formatCurrency(c.fatturato) : '—'}${num(c.pagato) ? `<div style="font-size:0.72rem;color:var(--accent-green)">pagato ${UI.formatCurrency(c.pagato)}</div>` : ''}</td>
                <td style="font-size:0.78rem">${c.condizione_pagamento === 'back_to_back' ? 'Quando incasso' : UI.esc(c.giorni_pagamento) + ' gg'}</td>
                <td style="white-space:nowrap">
                    ${c.offerta_fornitore_file ? `<button type="button" class="btn btn-sm btn-ghost" data-cdoc="${c.id}" title="Offerta del partner" aria-label="Offerta del partner"><i class="ph ph-file-pdf"></i></button>` : ''}
                    <button type="button" class="btn btn-sm btn-ghost" data-cedit="${c.id}" aria-label="Modifica"><i class="ph ph-pencil-simple"></i></button>
                    <button type="button" class="btn btn-sm btn-danger" data-cdel="${c.id}" aria-label="Elimina"><i class="ph ph-trash"></i></button>
                </td></tr>`).join('')}</tbody></table>
            <div class="rows-total"><span>Costi previsti <b>${UI.formatCurrency(tot)}</b></span></div>` : '<div class="scad-empty">Nessun partner su questa commessa.</div>'}
            <div class="inline-form" id="cst-form">
                <div><label>Partner</label><div style="display:flex;gap:4px"><select class="form-control" id="cst-forn"><option value="">—</option>${fOpts('')}</select>
                    <button type="button" class="btn btn-secondary btn-sm" id="cst-nuovo" title="Nuovo partner da partita IVA" aria-label="Nuovo partner da partita IVA"><i class="ph ph-plus"></i></button></div></div>
                <div><label>Cosa fa</label><input class="form-control" id="cst-desc" placeholder="es. docenza, audit tecnico"></div>
                <div><label>Importo €</label><input type="number" step="0.01" class="form-control" id="cst-imp"></div>
                <div><label>Pagamento</label><select class="form-control" id="cst-cond"><option value="scadenza">A scadenza</option><option value="back_to_back">Quando incasso</option></select></div>
                <div><label>Offerta partner (n. / file)</label><input class="form-control" id="cst-offnum" placeholder="n."><input type="file" class="form-control" id="cst-file" accept=".pdf,.docx,.jpg,.png" style="margin-top:4px"></div>
                <div><button type="button" class="btn btn-primary btn-sm" id="cst-save"><i class="ph ph-plus"></i> <span id="cst-save-lbl">Aggiungi</span></button></div>
            </div>
            <div class="inline-form" id="np-form" style="display:none">
                <div><label>P.IVA nuovo partner</label><input class="form-control" id="np-piva" inputmode="numeric" placeholder="11 cifre"></div>
                <div><button type="button" class="btn btn-secondary btn-sm" id="np-cerca"><i class="ph ph-magnifying-glass"></i> Cerca</button></div>
                <div><label>Ragione sociale</label><input class="form-control" id="np-rs"></div>
                <div><button type="button" class="btn btn-primary btn-sm" id="np-crea"><i class="ph ph-check"></i> Crea partner</button></div>
                <div id="np-stato" style="font-size:0.8rem;color:var(--text-muted)"></div>
            </div>
            <input type="hidden" id="cst-id">
            ${_fornitori.length ? '' : '<div class="scad-empty">Nessun partner in anagrafica: crealo con il pulsante + accanto a Partner.</div>'}`;

        // Nuovo partner da P.IVA senza lasciare l'offerta (la modale è una sola: aprirne un'altra perderebbe il form)
        let trovato = {};
        const np = id => document.getElementById(id);
        const stato = (t, err) => { np('np-stato').textContent = t; np('np-stato').style.color = err ? 'var(--danger)' : 'var(--text-muted)'; };
        const scegli = id => { np('cst-forn').innerHTML = `<option value="">—</option>${fOpts(id)}`; np('np-form').style.display = 'none'; };
        np('cst-nuovo').addEventListener('click', () => {
            const f = np('np-form'); f.style.display = f.style.display === 'none' ? '' : 'none';
            if (f.style.display === '') np('np-piva').focus();
        });
        np('np-cerca').addEventListener('click', async () => {
            const piva = np('np-piva').value.replace(/\D/g, '');
            if (piva.length !== 11) { stato('La partita IVA ha 11 cifre', true); return; }
            const gia = _fornitori.find(f => String(f.partita_iva || '').replace(/\D/g, '') === piva);
            if (gia) { scegli(gia.id); UI.toast(`${gia.ragione_sociale} è già tra i partner: selezionato`); return; }
            stato('Ricerca in corso...');
            try {
                trovato = await UI.cercaPiva(piva);
                np('np-rs').value = trovato.ragione_sociale || '';
                const gia = UI.testoGiaPresenti(trovato);
                stato(gia ? gia + ' — controlla prima di creare il partner.' : 'Dati trovati: controlla e crea il partner.', !!gia);
            } catch (e) { trovato = {}; stato(e.message + ' — scrivi la ragione sociale a mano.', true); np('np-rs').focus(); }
        });
        np('np-crea').addEventListener('click', async () => {
            const piva = np('np-piva').value.replace(/\D/g, ''), rs = np('np-rs').value.trim();
            if (!rs) { stato('Serve la ragione sociale', true); return; }
            try {
                const r = await Store.api('save', 'fornitori', { ragione_sociale: rs, tipo: 'partner', partita_iva: piva,
                    codice_fiscale: trovato.codice_fiscale || '', pec: trovato.pec || '', giorni_pagamento: 30 });
                await fornitori(true);
                scegli(r?.id);
                UI.toast('Partner creato');
            } catch (e) { stato(e.message, true); }
        });

        box.querySelectorAll('[data-cdoc]').forEach(b => b.addEventListener('click', () =>
            window.open(`api/router.php?module=fornitori&action=documento_costo&id=${encodeURIComponent(b.dataset.cdoc)}`, '_blank', 'noopener')));
        box.querySelectorAll('[data-cdel]').forEach(b => b.addEventListener('click', async () => {
            if (!confirm('Eliminare il costo?')) return;
            try { await Store.api('delete_costo', 'fornitori', { id: b.dataset.cdel }); onChange(); } catch (e) { UI.toast(e.message, 'error'); }
        }));
        box.querySelectorAll('[data-cedit]').forEach(b => b.addEventListener('click', () => {
            const c = costi.find(x => x.id == b.dataset.cedit);
            document.getElementById('cst-id').value = c.id;
            document.getElementById('cst-forn').value = c.fornitore_id || '';
            document.getElementById('cst-desc').value = c.descrizione;
            document.getElementById('cst-imp').value = c.importo_previsto;
            document.getElementById('cst-cond').value = c.condizione_pagamento;
            document.getElementById('cst-offnum').value = c.offerta_fornitore_numero || '';
            document.getElementById('cst-save-lbl').textContent = 'Salva';
            document.getElementById('cst-desc').focus();
        }));
        document.getElementById('cst-save').addEventListener('click', async () => {
            const v = id => document.getElementById(id).value;
            const fd = new FormData();
            const id = v('cst-id');
            const orig = id ? costi.find(x => x.id == id) : null;
            const data = { id, fornitore_id: v('cst-forn'), descrizione: v('cst-desc'), importo_previsto: v('cst-imp'),
                condizione_pagamento: v('cst-cond'), offerta_fornitore_numero: v('cst-offnum'),
                offerta_id: ctx.offerta_id || orig?.offerta_id || '', incarico_id: ctx.incarico_id || orig?.incarico_id || '',
                giorni_pagamento: orig?.giorni_pagamento ?? (_fornitori.find(f => f.id == v('cst-forn'))?.giorni_pagamento ?? 30),
                offerta_fornitore_data: orig?.offerta_fornitore_data || '' };
            Object.entries(data).forEach(([k, val]) => fd.append(k, val ?? ''));
            const file = document.getElementById('cst-file').files[0];
            if (file) fd.append('file', file);
            if (!data.descrizione.trim()) { UI.toast('Descrivi il costo', 'error'); return; }
            try {
                await Store.upload('save_costo', 'fornitori', fd);
                UI.toast('Costo salvato');
                onChange();
            } catch (e) { UI.toast(e.message, 'error'); }
        });
    }

    return { load, loadPassive, loadFornitori, renderCosti, fornitori };
})();
window.ModPartner = ModPartner;
