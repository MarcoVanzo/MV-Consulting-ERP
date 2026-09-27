'use strict';
/**
 * Modulo Movimenti — categorie dei movimenti bancari e coda "Da classificare".
 * Se il sistema non riconosce un movimento lo chiede qui: si sceglie la categoria in un clic e,
 * con "Applica sempre ai movimenti simili", nasce una regola che classifica da sola i prossimi.
 */
const ModMovimenti = (() => {
    let _cat = null, _ai = false, _coda = [], _mov = null;

    const isAdmin = () => { try { return JSON.parse(localStorage.getItem('erp_user') || '{}').role === 'admin'; } catch (e) { return false; } };
    const colore = c => /^#[0-9A-Fa-f]{6}$/.test(c || '') ? c : '#64748B';
    const importo = v => { const n = parseFloat(v) || 0; return `<span style="color:${n >= 0 ? 'var(--accent-green)' : '#ef4444'};font-weight:600">${n >= 0 ? '+' : ''}${UI.esc(UI.formatCurrency(n))}</span>`; };

    async function caricaCategorie(force) {
        if (_cat && !force) return _cat;
        const r = await Store.api('categorie', 'movimenti') || {};
        _cat = r.categorie || [];
        _ai = !!r.ai;
        setBadge(r.da_classificare);
        return _cat;
    }

    /** Categorie attive di un tipo ('entrata' | 'uscita'), per i form di altri moduli. */
    async function categorie(tipo) {
        return (await caricaCategorie()).filter(c => c.attiva == 1 && (!tipo || c.tipo === tipo));
    }

    function chip(c, extra = '') {
        return `<span class="badge" style="background:${colore(c.categoria_colore || c.colore)}22;color:${colore(c.categoria_colore || c.colore)};border:1px solid ${colore(c.categoria_colore || c.colore)}55" ${extra}>${UI.esc(c.categoria_nome || c.nome)}</span>`;
    }

    // ── Badge "Da classificare" (tab Contabilità e voce di menu) ──
    function setBadge(n) {
        if (n === undefined || n === null) return;
        ['badge-da-classificare', 'nav-badge-classificare'].forEach(id => {
            const el = document.getElementById(id);
            if (!el) return;
            el.textContent = n;
            el.style.display = n > 0 ? '' : 'none';
        });
    }
    async function aggiornaBadge() {
        try { setBadge((await Store.api('conteggio', 'movimenti') || {}).da_classificare); } catch (e) { /* categorie non attive */ }
    }

    // ── Coda "Da classificare" ──
    async function loadCoda() {
        const tbody = document.getElementById('tbody-classificare');
        try {
            await caricaCategorie(true);
            _coda = await Store.api('elenco', 'movimenti', { classificazione: 'da_classificare' }) || [];
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="5"><div class="empty-state"><i class="ph ph-warning-circle"></i><h3>${UI.esc(e.message)}</h3></div></td></tr>`;
            return;
        }
        document.getElementById('btn-mov-ai').style.display = _ai ? '' : 'none';
        document.getElementById('btn-mov-categorie').style.display = isAdmin() ? '' : 'none';
        setBadge(_coda.length);
        if (!_coda.length) {
            tbody.innerHTML = '<tr><td colspan="5"><div class="empty-state"><i class="ph ph-check-circle"></i><h3>Tutto classificato</h3><p>I nuovi movimenti non riconosciuti arriveranno qui</p></div></td></tr>';
            return;
        }
        tbody.innerHTML = _coda.map(m => {
            const prop = m.categoria_proposta_id
                ? `<button class="btn btn-sm btn-secondary" onclick="ModMovimenti.accetta(${m.id})" title="${UI.esc(m.proposta_motivo || '')}"><i class="ph ph-check"></i> ${UI.esc(m.proposta_nome || '')}</button>
                   <div style="font-size:0.72rem;color:var(--text-muted);margin-top:2px">${UI.esc(m.proposta_motivo || '')}</div>`
                : '<span style="color:var(--text-muted);font-size:0.8rem">Nessuna proposta</span>';
            return `<tr>
                <td>${UI.formatDate(m.data_valuta || m.data_operazione)}</td>
                <td><div class="td-primary">${UI.esc(m.controparte || '')}</div><div style="font-size:0.78rem;color:var(--text-muted);white-space:normal;max-width:520px">${UI.esc(m.descrizione || '')}</div></td>
                <td class="text-right">${importo(m.importo)}</td>
                <td>${prop}</td>
                <td><button class="btn btn-sm btn-primary" onclick="ModMovimenti.classifica(${m.id})"><i class="ph ph-tag"></i> Scegli</button></td>
            </tr>`;
        }).join('');
    }

    /** Accetta la proposta in un clic (con la regola per i simili, se la chiave è buona). */
    async function accetta(id) {
        const m = _coda.find(x => x.id == id);
        if (!m) return;
        await invia({ movimento_id: id, categoria_id: m.categoria_proposta_id, applica_simili: m.chiave_suggerita ? '1' : '0', chiave: m.chiave_suggerita || '' }, true);
    }

    // ── Scelta della categoria ──
    /** m: id di un movimento della coda oppure l'oggetto movimento (da Riconciliazione / Andamento). */
    async function classifica(m, dopo) {
        if (typeof m !== 'object') m = _coda.find(x => x.id == m);
        if (!m) return;
        try { await caricaCategorie(); } catch (e) { UI.toast(e.message, 'error'); return; }
        _mov = { m, dopo };
        const tipo = parseFloat(m.importo) >= 0 ? 'entrata' : 'uscita';
        const cats = _cat.filter(c => c.tipo === tipo && c.attiva == 1);
        const daRegola = m.categoria_fonte === 'regola' && m.regola_id;
        const fonti = { fattura: 'dalla fattura riconciliata', regola: 'da una regola', codice_banca: 'dal tipo di operazione', ai: 'dall\'AI', utente: 'scelta manuale' };
        const html = `<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:8px">
                <div><div class="td-primary">${UI.esc(m.controparte || '')}</div><div style="font-size:0.82rem;color:var(--text-muted)">${UI.formatDate(m.data_valuta || m.data_operazione)}${m.codice_operazione ? ' · operazione ' + UI.esc(m.codice_operazione) : ''}</div></div>
                <div style="font-size:1.2rem">${importo(m.importo)}</div>
            </div>
            <div style="font-size:0.85rem;padding:8px 10px;background:rgba(0,0,0,0.15);border-radius:6px;margin-bottom:12px;white-space:normal">${UI.esc(m.descrizione || '')}</div>
            ${m.categoria_id ? `<div style="margin-bottom:8px">Ora: ${chip(m)} <span style="font-size:0.78rem;color:var(--text-muted)">${UI.esc(fonti[m.categoria_fonte] || '')}</span></div>` : ''}
            ${m.categoria_proposta_id ? `<div style="margin-bottom:8px">Proposta: <strong>${UI.esc(m.proposta_nome || '')}</strong> <span style="font-size:0.78rem;color:var(--text-muted)">${UI.esc(m.proposta_motivo || '')}</span></div>` : ''}
            <div style="font-size:0.8rem;color:var(--text-muted);margin:10px 0 6px">Scegli la categoria (${tipo === 'entrata' ? 'entrate' : 'uscite'}):</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">${cats.map(c => `<button class="btn btn-sm" style="border:1px solid ${colore(c.colore)};color:${colore(c.colore)};background:${c.id == m.categoria_proposta_id ? colore(c.colore) + '33' : 'transparent'}" onclick="ModMovimenti.scegli(${c.id})">${UI.esc(c.nome)}</button>`).join('')}</div>
            ${daRegola ? `<label style="display:flex;gap:8px;align-items:center;margin-bottom:8px"><input type="checkbox" id="mov-aggiorna-regola" checked> Aggiorna anche la regola «${UI.esc(m.regola_chiave || '')}» e i movimenti che ha classificato</label>` : ''}
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" id="mov-simili" ${daRegola ? '' : 'checked'}> Applica sempre ai movimenti simili</label>
            <div id="mov-chiave-box" style="margin:8px 0 0 24px">
                <div style="font-size:0.78rem;color:var(--text-muted);margin-bottom:4px">Movimenti simili = ${tipo === 'entrata' ? 'entrate' : 'uscite'} che contengono queste parole (date, numeri e ID sono già tolti; accorcia per renderla più generale):</div>
                <input class="form-control" id="mov-chiave" value="${UI.esc(m.chiave_suggerita || '')}">
                ${m.codice_operazione ? `<label style="display:flex;gap:8px;align-items:center;margin-top:6px;font-size:0.82rem"><input type="checkbox" id="mov-codice"> Solo per il tipo operazione ${UI.esc(m.codice_operazione)}</label>` : ''}
            </div>`;
        UI.openModal('Categoria del movimento', html, null, { readOnly: true });
        const simili = document.getElementById('mov-simili');
        const box = document.getElementById('mov-chiave-box');
        const sync = () => { box.style.display = simili.checked ? '' : 'none'; };
        simili.addEventListener('change', sync);
        document.getElementById('mov-aggiorna-regola')?.addEventListener('change', e => { if (e.target.checked) { simili.checked = false; sync(); } });
        sync();
    }

    async function scegli(categoriaId) {
        if (!_mov) return;
        const agg = document.getElementById('mov-aggiorna-regola')?.checked;
        const simili = !agg && document.getElementById('mov-simili')?.checked;
        const ok = await invia({
            movimento_id: _mov.m.id, categoria_id: categoriaId,
            aggiorna_regola: agg ? '1' : '0', applica_simili: simili ? '1' : '0',
            chiave: document.getElementById('mov-chiave')?.value || '',
            usa_codice: document.getElementById('mov-codice')?.checked ? '1' : '0',
        });
        if (ok) { UI.closeModal(); if (typeof _mov.dopo === 'function') _mov.dopo(); }
    }

    async function invia(payload, silenziosoSuChiave) {
        try {
            const r = await Store.api('classifica', 'movimenti', payload) || {};
            UI.toast(r.aggiornati ? `Classificato, e altri ${r.aggiornati} simili` : 'Movimento classificato');
            setBadge(r.da_classificare);
            if (document.getElementById('tab-classificare')?.classList.contains('active')) loadCoda();
            return true;
        } catch (e) {
            // Proposta accettata al volo con una chiave non utilizzabile: si classifica solo questo movimento
            if (silenziosoSuChiave && payload.applica_simili === '1') return invia({ ...payload, applica_simili: '0' }, false);
            UI.toast(e.message, 'error');
            return false;
        }
    }

    // ── Azioni della coda ──
    async function riclassifica() {
        try {
            const r = await Store.api('riclassifica', 'movimenti') || {};
            UI.toast(`Classificati: ${(r.fattura || 0) + (r.regola || 0) + (r.codice_banca || 0)}, da classificare: ${r.da_classificare ?? 0}`);
            loadCoda();
        } catch (e) { UI.toast(e.message, 'error'); }
    }

    async function proponiAi() {
        const btn = document.getElementById('btn-mov-ai'); const prev = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> Analisi...';
        try { const r = await Store.api('proponi_ai', 'movimenti') || {}; UI.toast(`${r.proposte || 0} proposte dall'AI`); loadCoda(); }
        catch (e) { UI.toast(e.message, 'error'); }
        finally { btn.disabled = false; btn.innerHTML = prev; }
    }

    // ── Gestione categorie (admin) ──
    async function gestisciCategorie() {
        try { await caricaCategorie(true); } catch (e) { UI.toast(e.message, 'error'); return; }
        const riga = c => `<tr data-cat="${UI.esc(c.id)}">
            <td><input class="form-control cat-nome" value="${UI.esc(c.nome)}"></td>
            <td>${c.tipo === 'entrata' ? 'Entrata' : 'Uscita'}</td>
            <td><input type="color" class="cat-colore" value="${UI.esc(colore(c.colore))}" style="width:44px;height:32px;border:none;background:none"></td>
            <td><input type="number" class="form-control cat-ordine" value="${UI.esc(c.ordine)}" style="width:80px"></td>
            <td><input type="checkbox" class="cat-attiva" ${c.attiva == 1 ? 'checked' : ''}></td>
            <td><button class="btn btn-sm btn-secondary" onclick="ModMovimenti.salvaCategoria(${c.id})"><i class="ph ph-floppy-disk"></i></button></td>
        </tr>`;
        const html = `<div style="max-height:420px;overflow-y:auto"><table class="data-table"><thead><tr><th>Nome</th><th>Tipo</th><th>Colore</th><th>Ordine</th><th>Attiva</th><th></th></tr></thead>
            <tbody>${_cat.map(riga).join('')}</tbody></table></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px">
                <input class="form-control" id="cat-new-nome" placeholder="Nuova categoria" style="flex:1;min-width:160px">
                <select class="form-control" id="cat-new-tipo" style="width:auto"><option value="uscita">Uscita</option><option value="entrata">Entrata</option></select>
                <input type="color" id="cat-new-colore" value="#64748B" style="width:44px;height:32px;border:none;background:none">
                <button class="btn btn-primary" onclick="ModMovimenti.salvaCategoria(0)"><i class="ph ph-plus"></i> Aggiungi</button>
            </div>`;
        UI.openModal('Categorie dei movimenti', html, null, { readOnly: true, wide: true });
    }

    async function salvaCategoria(id) {
        let dati;
        if (id) {
            const tr = document.querySelector(`tr[data-cat="${id}"]`);
            dati = { id, nome: tr.querySelector('.cat-nome').value, colore: tr.querySelector('.cat-colore').value,
                ordine: tr.querySelector('.cat-ordine').value, attiva: tr.querySelector('.cat-attiva').checked ? '1' : '0' };
        } else {
            dati = { nome: document.getElementById('cat-new-nome').value, tipo: document.getElementById('cat-new-tipo').value,
                colore: document.getElementById('cat-new-colore').value };
        }
        try {
            await Store.api('salva_categoria', 'movimenti', dati);
            UI.toast('Categoria salvata');
            if (!id) gestisciCategorie(); else await caricaCategorie(true);
        } catch (e) { UI.toast(e.message, 'error'); }
    }

    // ── Regole apprese ──
    async function regole() {
        let list;
        try { list = await Store.api('regole', 'movimenti') || []; } catch (e) { UI.toast(e.message, 'error'); return; }
        const admin = isAdmin();
        const html = list.length ? `<div style="max-height:440px;overflow-y:auto"><table class="data-table"><thead><tr><th>Se il movimento contiene</th><th>Verso</th><th>Categoria</th><th class="text-right">Usata</th><th></th></tr></thead><tbody>
            ${list.map(r => `<tr><td class="td-mono">${UI.esc(r.chiave || '—')}${r.codice_operazione ? ` <span style="color:var(--text-muted)">[op. ${UI.esc(r.codice_operazione)}]</span>` : ''}</td>
                <td>${r.segno == 1 ? 'Entrata' : 'Uscita'}</td><td>${chip(r)}</td><td class="text-right">${UI.esc(r.utilizzi)}</td>
                <td>${admin ? `<button class="btn btn-sm btn-danger" onclick="ModMovimenti.eliminaRegola(${r.id})"><i class="ph ph-trash"></i></button>` : ''}</td></tr>`).join('')}
            </tbody></table></div>` : '<div class="empty-state"><h3>Nessuna regola</h3><p>Nascono quando classifichi un movimento con "Applica sempre ai movimenti simili"</p></div>';
        UI.openModal('Regole di classificazione', html, null, { readOnly: true, wide: true });
    }

    async function eliminaRegola(id) {
        if (!confirm('Eliminare la regola? I movimenti già classificati restano nella loro categoria.')) return;
        try { await Store.api('elimina_regola', 'movimenti', { id }); UI.toast('Regola eliminata'); regole(); } catch (e) { UI.toast(e.message, 'error'); }
    }

    function init() {
        document.getElementById('btn-mov-riclassifica')?.addEventListener('click', riclassifica);
        document.getElementById('btn-mov-ai')?.addEventListener('click', proponiAi);
        document.getElementById('btn-mov-categorie')?.addEventListener('click', gestisciCategorie);
        document.getElementById('btn-mov-regole')?.addEventListener('click', regole);
        aggiornaBadge();
    }

    return { init, loadCoda, aggiornaBadge, categorie, chip, classifica, scegli, accetta, gestisciCategorie, salvaCategoria, regole, eliminaRegola };
})();
window.ModMovimenti = ModMovimenti;
