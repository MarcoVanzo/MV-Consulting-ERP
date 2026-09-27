'use strict';
/**
 * Modulo Andamento — entrate e uscite del conto corrente, per mese e per categoria.
 * Grafici in SVG generato qui (la CSP ammette solo script 'self': niente librerie).
 */
const ModAndamento = (() => {
    let _stat = null, _lista = [];
    const MESI = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
    const colore = c => /^#[0-9A-Fa-f]{6}$/.test(c || '') ? c : '#64748B';
    const breve = v => {
        const a = Math.abs(v);
        const s = a >= 1e6 ? (a / 1e6).toLocaleString('it-IT', { maximumFractionDigits: 1 }) + ' M' : a >= 1e3 ? Math.round(a / 1e3).toLocaleString('it-IT') + ' k' : Math.round(a).toLocaleString('it-IT');
        return (v < 0 ? '−' : '') + s + ' €';
    };

    function periodo() {
        const y = document.getElementById('contabilita-year')?.value || new Date().getFullYear();
        const p = document.getElementById('andamento-periodo')?.value || 'anno';
        const r = { anno: ['01-01', '12-31'], s1: ['01-01', '06-30'], s2: ['07-01', '12-31'],
            t1: ['01-01', '03-31'], t2: ['04-01', '06-30'], t3: ['07-01', '09-30'], t4: ['10-01', '12-31'] }[p] || ['01-01', '12-31'];
        return { dal: `${y}-${r[0]}`, al: `${y}-${r[1]}` };
    }

    async function load() {
        const box = document.getElementById('andamento-contenuto');
        try {
            _stat = await Store.api('statistiche', 'movimenti', periodo());
        } catch (e) {
            box.innerHTML = `<div class="empty-state"><i class="ph ph-warning-circle"></i><h3>${UI.esc(e.message)}</h3></div>`;
            return;
        }
        const t = _stat.totali;
        const nc = (_stat.entrate.categorie.concat(_stat.uscite.categorie)).filter(c => c.id === null).reduce((s, c) => s + c.numero, 0);
        box.innerHTML = `<div class="kpi-grid">
                <div class="kpi-card kpi-green"><div class="kpi-label">Entrate</div><div class="kpi-value">${UI.esc(UI.formatCurrency(t.entrate))}</div></div>
                <div class="kpi-card kpi-red"><div class="kpi-label">Uscite</div><div class="kpi-value">${UI.esc(UI.formatCurrency(t.uscite))}</div></div>
                <div class="kpi-card kpi-blue"><div class="kpi-label">Saldo netto</div><div class="kpi-value">${UI.esc(UI.formatCurrency(t.netto))}</div></div>
                <div class="kpi-card kpi-yellow" style="cursor:pointer" onclick="ModAndamento.vaiCoda()"><div class="kpi-label">Non classificati</div><div class="kpi-value">${UI.esc(nc)}</div><div class="kpi-sub">nel periodo · da classificare in tutto: ${UI.esc(_stat.da_classificare)}</div></div>
            </div>
            <div class="chart-card"><div class="chart-card-title">Entrate e uscite per mese</div>
                <div style="display:flex;gap:16px;font-size:0.78rem;color:var(--text-muted);margin-bottom:6px;flex-wrap:wrap">
                    <span><span style="display:inline-block;width:10px;height:10px;background:var(--accent-green);border-radius:2px"></span> Entrate</span>
                    <span><span style="display:inline-block;width:10px;height:10px;background:#ef4444;border-radius:2px"></span> Uscite</span>
                    <span><span style="display:inline-block;width:14px;height:2px;background:#60A5FA;vertical-align:middle"></span> Saldo netto</span>
                </div>
                <div class="andamento-scroll">${graficoMesi(_stat.mesi)}</div>
            </div>
            <div class="andamento-grid">
                ${graficoCategorie('Entrate per categoria', 'entrata', _stat.entrate)}
                ${graficoCategorie('Uscite per categoria', 'uscita', _stat.uscite)}
            </div>`;
    }

    /** Barre affiancate entrate/uscite per mese, con la linea del saldo netto. */
    function graficoMesi(mesi) {
        if (!mesi.length) return '<div style="color:var(--text-muted);padding:30px;text-align:center">Nessun dato</div>';
        const W = 720, H = 280, L = 64, R = 12, T = 12, B = 30;
        // Scala con tacche "tonde" (1, 2, 5 × 10^n)
        const alto = Math.max(1, ...mesi.map(m => Math.max(m.entrate, m.uscite, m.netto)));
        const basso = Math.min(0, ...mesi.map(m => m.netto));
        const grezzo = (alto - basso) / 4, pot = Math.pow(10, Math.floor(Math.log10(grezzo)));
        const passo = [1, 2, 5, 10].map(k => k * pot).find(k => k >= grezzo);
        const min = Math.floor(basso / passo) * passo, max = Math.ceil(alto / passo) * passo;
        const y = v => T + (max - v) / (max - min) * (H - T - B);
        const slot = (W - L - R) / mesi.length;
        const bw = Math.max(4, Math.min(22, slot * 0.32));
        let svg = '';
        for (let v = min; v <= max + passo / 2; v += passo) {
            svg += `<line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" stroke="currentColor" stroke-opacity="0.08"/>`
                + `<text x="${L - 6}" y="${y(v) + 4}" text-anchor="end" font-size="11" fill="currentColor" fill-opacity="0.55">${UI.esc(breve(v))}</text>`;
        }
        svg += `<line x1="${L}" x2="${W - R}" y1="${y(0)}" y2="${y(0)}" stroke="currentColor" stroke-opacity="0.3"/>`;
        const punti = [];
        mesi.forEach((m, i) => {
            const cx = L + slot * i + slot / 2;
            const [anno, mm] = m.mese.split('-');
            const tip = `${MESI[+mm - 1]} ${anno} — entrate ${UI.formatCurrency(m.entrate)}, uscite ${UI.formatCurrency(m.uscite)}, netto ${UI.formatCurrency(m.netto)}`;
            svg += `<g><title>${UI.esc(tip)}</title>
                <rect x="${cx - bw - 1}" y="${y(m.entrate)}" width="${bw}" height="${Math.max(0, y(0) - y(m.entrate))}" rx="2" fill="#10B981"/>
                <rect x="${cx + 1}" y="${y(m.uscite)}" width="${bw}" height="${Math.max(0, y(0) - y(m.uscite))}" rx="2" fill="#EF4444"/>
                <rect x="${cx - slot / 2}" y="${T}" width="${slot}" height="${H - T - B}" fill="transparent"/></g>
                <text x="${cx}" y="${H - 10}" text-anchor="middle" font-size="11" fill="currentColor" fill-opacity="0.6">${MESI[+mm - 1]}</text>`;
            punti.push(`${cx},${y(m.netto)}`);
        });
        svg += `<polyline points="${punti.join(' ')}" fill="none" stroke="#60A5FA" stroke-width="2"/>`
            + punti.map((p, i) => `<circle cx="${p.split(',')[0]}" cy="${p.split(',')[1]}" r="3" fill="#60A5FA"><title>${UI.esc('Netto ' + UI.formatCurrency(mesi[i].netto))}</title></circle>`).join('');
        return `<svg viewBox="0 0 ${W} ${H}" width="100%" role="img" aria-label="Entrate e uscite per mese" style="color:var(--text-primary);display:block">${svg}</svg>`;
    }

    /** Barre orizzontali ordinate; clic su una categoria = elenco dei movimenti. */
    function graficoCategorie(titolo, tipo, dati) {
        const max = Math.max(1, ...dati.categorie.map(c => c.totale));
        const righe = dati.categorie.map(c => {
            const pct = Math.max(0.5, c.totale / max * 100);
            const quota = dati.totale > 0 ? Math.round(c.totale / dati.totale * 100) : 0;
            const nc = c.id === null;
            return `<div class="andamento-riga" onclick="ModAndamento.dettaglio('${tipo}', ${nc ? "'nessuna'" : Number(c.id)})" title="Vedi i movimenti">
                <div style="display:flex;justify-content:space-between;gap:8px;font-size:0.82rem">
                    <span>${nc ? '<i class="ph ph-warning-circle" style="color:#f59e0b"></i> ' : ''}${UI.esc(c.nome)} <span style="color:var(--text-muted)">(${UI.esc(c.numero)})</span></span>
                    <span style="white-space:nowrap"><strong>${UI.esc(UI.formatCurrency(c.totale))}</strong> <span style="color:var(--text-muted)">${quota}%</span></span>
                </div>
                <svg width="100%" height="10" viewBox="0 0 100 10" preserveAspectRatio="none" style="display:block;margin-top:3px">
                    <rect width="100" height="10" rx="2" fill="currentColor" fill-opacity="0.06"/>
                    <rect width="${pct.toFixed(2)}" height="10" rx="2" fill="${colore(c.colore)}"${nc ? ' fill-opacity="0.6"' : ''}/>
                </svg>
            </div>`;
        }).join('');
        return `<div class="chart-card"><div class="chart-card-title">${UI.esc(titolo)} · ${UI.esc(UI.formatCurrency(dati.totale))}</div>
            ${righe || '<div style="color:var(--text-muted);padding:20px;text-align:center">Nessun movimento</div>'}</div>`;
    }

    async function dettaglio(tipo, categoriaId) {
        const p = periodo();
        let list;
        try { list = await Store.api('elenco', 'movimenti', { ...p, tipo, categoria_id: categoriaId }) || []; }
        catch (e) { UI.toast(e.message, 'error'); return; }
        const nome = categoriaId === 'nessuna' ? 'Non classificato'
            : ((_stat[tipo === 'entrata' ? 'entrate' : 'uscite'].categorie.find(c => c.id == categoriaId) || {}).nome || '');
        _lista = list;
        const html = `<div style="font-size:0.82rem;color:var(--text-muted);margin-bottom:8px">${UI.formatDate(p.dal)} – ${UI.formatDate(p.al)} · ${list.length} movimenti · clic su un movimento per cambiarne la categoria</div>
            <div style="max-height:460px;overflow-y:auto"><table class="data-table"><tbody>${list.map((m, i) => `<tr style="cursor:pointer" onclick="ModAndamento.cambia(${i})">
                <td>${UI.formatDate(m.data_valuta || m.data_operazione)}</td>
                <td><div class="td-primary">${UI.esc(m.controparte || '')}</div><div style="font-size:0.76rem;color:var(--text-muted);white-space:normal">${UI.esc((m.descrizione || '').slice(0, 160))}</div></td>
                <td class="text-right" style="white-space:nowrap">${UI.esc(UI.formatCurrency(m.importo))}</td></tr>`).join('')}</tbody></table></div>`;
        UI.openModal(nome, html, null, { readOnly: true, wide: true });
    }

    function cambia(i) {
        const m = _lista[i];
        if (m) ModMovimenti.classifica(m, load);
    }

    function vaiCoda() {
        document.querySelector('#contabilita-tabs .tab[data-target="tab-classificare"]')?.click();
    }

    function init() {
        document.getElementById('andamento-periodo')?.addEventListener('change', load);
    }

    return { load, init, dettaglio, cambia, vaiCoda };
})();
window.ModAndamento = ModAndamento;
