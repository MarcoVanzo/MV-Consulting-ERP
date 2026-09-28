'use strict';
/**
 * Modulo Riconciliazione — estratto conto (XML CBI o PDF) ↔ fatture emesse e fatture dei fornitori.
 * Tab "Riconciliazione" della Contabilità. La logica di abbinamento è lato server (api/Shared/Riconciliatore.php).
 */
const ModRiconciliazione = (() => {
    let _movimenti = [], _stato = 'da_riconciliare', _proposte = {}, _aperti = [], _manuale = null;

    const STATI = {
        da_riconciliare: { cls: 'badge-yellow', label: 'Da riconciliare' },
        riconciliato:    { cls: 'badge-green',  label: 'Riconciliato' },
        ignorato:        { cls: 'badge-purple', label: 'Ignorato' },
    };

    function periodo() {
        const y = UI.anno();
        return { dal: `${y}-01-01`, al: `${y}-12-31` };
    }

    async function load() {
        const params = { ...periodo() };
        if (_stato) params.stato = _stato;
        // La coda da riconciliare mostra solo i movimenti agganciabili a fatture (gli altri sono in "Andamento")
        if (_stato === 'da_riconciliare') params.abbinabili = '1';
        try {
            // Le categorie servono alle tendine delle righe (si caricano una volta, poi restano in memoria)
            const [mov] = await Promise.all([
                Store.api('movimenti', 'riconciliazione', params),
                window.ModMovimenti ? ModMovimenti.caricaCategorie().catch(() => []) : null,
            ]);
            _movimenti = mov || [];
        } catch (e) {
            _movimenti = [];
            document.getElementById('tbody-riconciliazione').innerHTML = `<tr><td colspan="6"><div class="empty-state"><i class="ph ph-warning-circle"></i><h3>${UI.esc(e.message)}</h3></div></td></tr>`;
            return;
        }
        _proposte = {};
        render();
        if (window.ModMovimenti) ModMovimenti.aggiornaBadge();
    }

    function importo(v) {
        const n = parseFloat(v) || 0;
        return `<span style="color:${n >= 0 ? 'var(--accent-green)' : '#ef4444'};font-weight:600">${n >= 0 ? '+' : ''}${UI.esc(UI.formatCurrency(n))}</span>`;
    }

    function docLabel(d) {
        const rate = (d.incarichi || []).map(i => `Incarico #${UI.esc(i.id)}: rate ${UI.esc(i.rate_incassate)}/${UI.esc(i.rate_totali)} incassate`).join(' · ');
        return `<div><span class="td-mono">${UI.esc(d.numero)}</span> ${UI.esc(d.anagrafica_nome || '')} — ${UI.esc(UI.formatCurrency(d.importo))}`
            + `${d.righe > 1 ? ` <span style="color:var(--text-muted)">(${UI.esc(d.righe)} righe)</span>` : ''}`
            + `${d.metodo === 'auto' ? ' <span class="badge badge-blue">auto</span>' : ''}`
            + `${rate ? `<div style="font-size:0.75rem;color:var(--text-muted)">${rate}</div>` : ''}</div>`;
    }

    function render() {
        const tbody = document.getElementById('tbody-riconciliazione');
        if (!_movimenti.length) {
            tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><i class="ph ph-bank"></i><h3>Nessun movimento</h3><p>Importa l\'estratto conto per iniziare</p></div></td></tr>';
            return;
        }
        tbody.innerHTML = _movimenti.map(m => {
            const s = STATI[m.stato] || { cls: 'badge-blue', label: m.stato };
            const avviso = m.origine === 'avviso_pagamento' ? ' <span class="badge badge-blue">avviso</span>'
                : m.origine === 'estratto_carta' ? ' <span class="badge badge-purple">carta</span>' : '';
            // Categoria: tendina sulla riga ("Da classificare" se il sistema non l'ha riconosciuta)
            const categoria = m.origine !== 'estratto_conto' || !('classificazione' in m) || !window.ModMovimenti ? ''
                : ' ' + ModMovimenti.tendina(m);
            let abbinato = (m.riconciliazioni || []).map(docLabel).join('');
            if (m.avviso_id) abbinato = '<span style="color:var(--text-muted)">Coperto dall\'avviso di pagamento</span>';
            let azioni = '';
            if (m.stato === 'da_riconciliare') {
                azioni = `<button class="btn btn-sm btn-secondary" onclick="ModRiconciliazione.mostraProposte(${m.id})"><i class="ph ph-lightbulb"></i> Proposte</button>
                    <button class="btn btn-sm btn-ghost" onclick="ModRiconciliazione.sceltaManuale(${m.id})" title="Scegli le fatture"><i class="ph ph-list-checks"></i></button>`;
                if (!(m.riconciliazioni || []).length) azioni += `<button class="btn btn-sm btn-ghost" onclick="ModRiconciliazione.ignora(${m.id})" title="Ignora"><i class="ph ph-eye-slash"></i></button>`;
                else azioni += `<button class="btn btn-sm btn-ghost" onclick="ModRiconciliazione.annulla(${m.id})" title="Annulla"><i class="ph ph-arrow-counter-clockwise"></i></button>`;
            } else if (m.stato === 'riconciliato') {
                azioni = `<button class="btn btn-sm btn-ghost" onclick="ModRiconciliazione.annulla(${m.id})" title="Annulla la riconciliazione"><i class="ph ph-arrow-counter-clockwise"></i> Annulla</button>`;
            } else {
                azioni = `<button class="btn btn-sm btn-ghost" onclick="ModRiconciliazione.ripristina(${m.id})"><i class="ph ph-arrow-u-up-left"></i> Ripristina</button>`;
            }
            return `<tr data-id="${UI.esc(m.id)}">
                <td>${UI.formatDate(m.data_valuta || m.data_operazione)}</td>
                <td><div class="td-primary">${UI.esc(m.controparte || '')}${avviso}${categoria}</div><div style="font-size:0.78rem;color:var(--text-muted);max-width:520px;white-space:normal">${UI.esc(m.descrizione || '')}</div></td>
                <td class="text-right">${importo(m.importo)}</td>
                <td><span class="badge ${s.cls}">${UI.esc(s.label)}</span></td>
                <td style="font-size:0.82rem">${abbinato || '—'}</td>
                <td><div class="flex gap-2">${azioni}</div></td>
            </tr><tr id="ric-prop-${m.id}" style="display:none"><td colspan="6"></td></tr>`;
        }).join('');
        tbody.querySelectorAll('.cat-tendina').forEach(sel => sel.addEventListener('change', async () => {
            const m = _movimenti.find(x => x.id == sel.dataset.mov);
            if (!m) return;
            if (sel.value === 'altro') { categoria(m.id); return; }
            if (!sel.value) return;
            sel.disabled = true;
            await ModMovimenti.scegliRapido(m, sel.value, () => load());
        }));
    }

    // ── Proposte ──
    async function mostraProposte(id) {
        const row = document.getElementById(`ric-prop-${id}`);
        if (!row) return;
        if (row.style.display !== 'none') { row.style.display = 'none'; return; }
        row.style.display = '';
        const cell = row.firstElementChild;
        cell.innerHTML = '<i class="ph ph-spinner ph-spin"></i> Ricerca proposte...';
        try {
            const prop = await Store.api('proposte', 'riconciliazione', { id }) || [];
            _proposte[id] = prop;
            if (!prop.length) { cell.innerHTML = '<span style="color:var(--text-muted)">Nessuna proposta: usa la scelta manuale.</span>'; return; }
            cell.innerHTML = prop.map((p, i) => {
                const docs = p.tipo === 'avviso'
                    ? `Avviso di pagamento: ${(p.documenti || []).map(d => UI.esc(d.numero)).join(', ')}`
                    : (p.documenti || []).map(d => `<span class="td-mono">${UI.esc(d.numero)}</span> ${UI.esc(d.anagrafica_nome || '')} ${UI.esc(UI.formatCurrency(d.importo))}`).join(' + ');
                return `<div style="display:flex;gap:12px;align-items:center;padding:6px 0;border-bottom:1px solid var(--border-subtle)">
                    <span class="badge badge-blue">${UI.esc(p.punteggio)}</span>
                    <div style="flex:1">${docs}<div style="font-size:0.75rem;color:var(--text-muted)">${(p.motivi || []).map(UI.esc).join(' · ')}</div></div>
                    <strong>${UI.esc(UI.formatCurrency(p.totale))}</strong>
                    <button class="btn btn-sm btn-primary" onclick="ModRiconciliazione.confermaProposta(${id},${i})"><i class="ph ph-check"></i> Conferma</button>
                </div>`;
            }).join('');
        } catch (e) { cell.innerHTML = `<span style="color:#ef4444">${UI.esc(e.message)}</span>`; }
    }

    async function confermaProposta(id, i) {
        const p = (_proposte[id] || [])[i];
        if (!p) return;
        const payload = { movimento_id: id };
        if (p.tipo === 'avviso') payload.avviso_id = p.avviso_id;
        else payload.documenti = JSON.stringify(p.documenti.map(d => ({ tipo: d.tipo, id: d.id, importo: d.importo })));
        await invia('conferma', payload);
    }

    // ── Scelta manuale (più fatture, importi modificabili) ──
    async function sceltaManuale(id) {
        const m = _movimenti.find(x => x.id == id);
        if (!m) return;
        const tipo = parseFloat(m.importo) > 0 ? 'fattura' : 'fattura_passiva';
        try { _aperti = await Store.api('documenti_aperti', 'riconciliazione', { tipo }) || []; }
        catch (e) { UI.toast(e.message, 'error'); return; }
        const gia = (m.riconciliazioni || []).reduce((s, r) => s + (parseFloat(r.importo) || 0), 0);
        _manuale = { id, disponibile: Math.abs(parseFloat(m.importo)) - gia };
        const righe = _aperti.map((d, i) => `<tr>
            <td><input type="checkbox" class="ric-chk" data-i="${i}"></td>
            <td class="td-mono">${UI.esc(d.numero)}${d.nota_credito ? ' <span class="badge badge-purple">NC</span>' : ''}</td>
            <td>${UI.formatDate(d.data_emissione)}</td>
            <td>${UI.esc(d.anagrafica_nome || '—')}${d.sottoclienti?.length ? `<div style="font-size:0.72rem;color:var(--text-muted)">${UI.esc(d.sottoclienti.join(', '))}</div>` : ''}</td>
            <td class="text-right">${UI.esc(UI.formatCurrency(d.residuo))}</td>
            <td><input type="number" step="0.01" class="form-control ric-imp" data-i="${i}" value="${UI.esc(d.residuo)}" style="width:110px" title="${d.nota_credito ? 'Nota di credito: importo negativo, va insieme a una fattura' : ''}"></td>
        </tr>`).join('');
        const html = `<div style="margin-bottom:12px">${UI.esc(m.descrizione || '')}</div>
            <div style="display:flex;gap:16px;margin-bottom:12px;flex-wrap:wrap">
                <div>Movimento: ${importo(m.importo)}</div>
                <div>Selezionato: <strong id="ric-sel">${UI.esc(UI.formatCurrency(0))}</strong></div>
                <div>Da coprire: <strong id="ric-diff">${UI.esc(UI.formatCurrency(_manuale.disponibile))}</strong></div>
            </div>
            <input type="text" class="form-control" id="ric-cerca" placeholder="Cerca numero o intestatario" style="margin-bottom:8px">
            <div style="max-height:380px;overflow-y:auto"><table class="data-table"><thead><tr><th></th><th>Numero</th><th>Data</th><th>Intestatario</th><th class="text-right">Residuo</th><th>Importo</th></tr></thead>
            <tbody id="ric-man-body">${righe || '<tr><td colspan="6">Nessuna fattura aperta</td></tr>'}</tbody></table></div>`;
        UI.openModal('Abbina fatture', html, salvaManuale, { wide: true });
        const body = document.getElementById('ric-man-body');
        body.addEventListener('input', aggiornaSomma);
        body.addEventListener('change', aggiornaSomma);
        document.getElementById('ric-cerca').addEventListener('input', e => {
            const q = e.target.value.toLowerCase();
            body.querySelectorAll('tr').forEach(tr => { tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none'; });
        });
    }

    function selezionati() {
        return Array.from(document.querySelectorAll('#ric-man-body .ric-chk:checked')).map(chk => {
            const i = parseInt(chk.dataset.i, 10);
            const inp = document.querySelector(`#ric-man-body .ric-imp[data-i="${i}"]`);
            return { tipo: _aperti[i].tipo, id: _aperti[i].id, importo: Math.round((parseFloat(inp?.value) || 0) * 100) / 100 };
        });
    }

    function aggiornaSomma() {
        const tot = selezionati().reduce((s, d) => s + d.importo, 0);
        document.getElementById('ric-sel').textContent = UI.formatCurrency(tot);
        document.getElementById('ric-diff').textContent = UI.formatCurrency(_manuale.disponibile - tot);
    }

    async function salvaManuale() {
        const docs = selezionati();
        if (!docs.length) { UI.toast('Seleziona almeno una fattura', 'error'); return; }
        // Il modal resta aperto se il server rifiuta (es. importo oltre il residuo): si corregge e si riprova
        if (await invia('conferma', { movimento_id: _manuale.id, documenti: JSON.stringify(docs) })) UI.closeModal();
    }

    // ── Azioni ──
    async function invia(action, payload, conferma) {
        if (conferma && !confirm(conferma)) return false;
        try {
            await Store.api(action, 'riconciliazione', payload);
            UI.toast(action === 'conferma' ? 'Pagamento registrato' : 'Fatto');
            load();
            return true;
        } catch (e) { UI.toast(e.message, 'error'); return false; }
    }
    const annulla = id => invia('annulla', { movimento_id: id }, 'Annullare la riconciliazione? Le fatture non più coperte tornano da pagare.');
    const ignora = id => invia('ignora', { movimento_id: id });
    const ripristina = id => invia('ignora', { movimento_id: id, ripristina: '1' });

    /**
     * Riprova l'abbinamento dei movimenti da riconciliare con le fatture presenti ora: all'import
     * dell'estratto quelle pagate potevano non esserci ancora. Lo lancia anche l'import di fatture.
     * silenzioso: nessun avviso se non si abbina niente.
     */
    async function riabbina(silenzioso = false) {
        const btn = document.getElementById('btn-riabbina');
        if (btn) btn.disabled = true;
        try {
            const r = await Store.api('riabbina', 'riconciliazione', {}) || {};
            if (r.abbinati) UI.toast(`${r.abbinati} ${r.abbinati === 1 ? 'movimento abbinato' : 'movimenti abbinati'} a fatture`);
            else if (!silenzioso) UI.toast(r.da_verificare ? `Nessun abbinamento sicuro: ${r.da_verificare} restano da verificare con le proposte` : 'Nessun movimento da abbinare');
            if (document.getElementById('tab-riconciliazione')?.offsetParent) load();
            return r;
        } catch (e) {
            if (!silenzioso) UI.toast(e.message, 'error');
            return null;
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // ── Import estratto conto ──
    function init() {
        document.getElementById('btn-riabbina')?.addEventListener('click', () => riabbina());
        document.querySelectorAll('#tab-riconciliazione .filter-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('#tab-riconciliazione .filter-chip').forEach(c => c.classList.remove('active'));
                chip.classList.add('active'); _stato = chip.dataset.ricStato || ''; load();
            });
        });
    }

    function categoria(id) {
        const m = _movimenti.find(x => x.id == id);
        if (m) ModMovimenti.classifica(m, load);
    }

    return { load, init, categoria, mostraProposte, confermaProposta, sceltaManuale, annulla, ignora, ripristina, riabbina };
})();
window.ModRiconciliazione = ModRiconciliazione;
