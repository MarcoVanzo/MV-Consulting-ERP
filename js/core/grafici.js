'use strict';

/**
 * Grafici — piccoli grafici in HTML/SVG, senza librerie, leggibili anche su telefono.
 * Ogni funzione restituisce l'HTML di una scheda `.graf`: titolo, domanda a cui risponde, grafico.
 * Colori dai token del tema; le animazioni stanno in css/grafici.css sotto prefers-reduced-motion.
 */
const Grafici = (() => {
    const esc = s => UI.esc(s);
    const euro = v => UI.formatCurrency(v);
    const PALETTE = ['var(--accent-primary)', 'var(--accent-green)', 'var(--accent-purple)', 'var(--accent-warm)', 'var(--accent-secondary)', 'var(--text-muted)'];

    function scheda(titolo, sotto, corpo, extra = '') {
        return `<section class="graf ${extra}"><header><h3>${esc(titolo)}</h3>${sotto ? `<p>${esc(sotto)}</p>` : ''}</header>${corpo}</section>`;
    }

    /** Barre orizzontali: righe [{etichetta, valore, nota?, colore?, evidenzia?}], valori in euro. */
    function barre(titolo, sotto, righe, { vuoto = 'Nessun dato' } = {}) {
        const max = Math.max(...righe.map(r => Math.abs(r.valore) || 0), 0);
        if (!righe.length || max <= 0) return scheda(titolo, sotto, `<div class="graf-vuoto">${esc(vuoto)}</div>`);
        const tot = righe.reduce((a, r) => a + (r.valore > 0 ? r.valore : 0), 0);
        return scheda(titolo, sotto, `<ul class="graf-barre">${righe.map((r, i) => {
            const pct = Math.max(0, r.valore) / max * 100;
            const quota = tot > 0 && r.valore > 0 ? Math.round(r.valore / tot * 100) : 0;
            return `<li class="${r.evidenzia ? 'evidenzia' : ''}">
                <div class="graf-riga"><span class="graf-etich">${esc(r.etichetta)}</span><span class="graf-val">${euro(r.valore)}${r.nota ? ` · ${esc(r.nota)}` : quota ? ` · ${quota}%` : ''}</span></div>
                <div class="graf-traccia"><div class="graf-barra" style="width:${pct.toFixed(1)}%;background:${r.colore || PALETTE[i % PALETTE.length]}"></div></div></li>`;
        }).join('')}</ul>`);
    }

    /** Una barra divisa in parti [{etichetta, valore, colore}] con legenda: quanto del totale è in ogni stato. */
    function impilata(titolo, sotto, parti) {
        const tot = parti.reduce((a, p) => a + Math.max(0, p.valore), 0);
        if (tot <= 0) return scheda(titolo, sotto, '<div class="graf-vuoto">Nessun dato</div>');
        return scheda(titolo, sotto, `<div class="graf-impilata" role="img" aria-label="${esc(parti.map(p => `${p.etichetta} ${euro(p.valore)}`).join(', '))}">
            ${parti.filter(p => p.valore > 0).map(p => `<div style="flex:${p.valore};background:${p.colore}" title="${esc(p.etichetta)}: ${esc(euro(p.valore))}"></div>`).join('')}</div>
            <ul class="graf-legenda">${parti.map(p => `<li><i style="background:${p.colore}"></i>${esc(p.etichetta)} <b>${euro(p.valore)}</b> <span>${Math.round(Math.max(0, p.valore) / tot * 100)}%</span></li>`).join('')}</ul>`);
    }

    /** Ciambella: quota del primo elemento sul totale, al centro la percentuale; legenda con le altre parti. */
    function ciambella(titolo, sotto, parti, centro) {
        const tot = parti.reduce((a, p) => a + Math.max(0, p.valore), 0);
        if (tot <= 0) return scheda(titolo, sotto, '<div class="graf-vuoto">Nessun dato</div>');
        const r = 42, C = 2 * Math.PI * r;
        let fatto = 0;
        const archi = parti.filter(p => p.valore > 0).map((p, i) => {
            const len = p.valore / tot * C;
            const s = `<circle r="${r}" cx="50" cy="50" fill="none" stroke="${p.colore || PALETTE[i % PALETTE.length]}" stroke-width="14"
                stroke-dasharray="${Math.max(0, len - 1.2).toFixed(2)} ${C.toFixed(2)}" stroke-dashoffset="${(-fatto).toFixed(2)}" transform="rotate(-90 50 50)"><title>${esc(p.etichetta)}: ${esc(euro(p.valore))}</title></circle>`;
            fatto += len;
            return s;
        }).join('');
        return scheda(titolo, sotto, `<div class="graf-ciambella">
            <svg viewBox="0 0 100 100" role="img" aria-label="${esc(centro.testo + ' ' + centro.sotto)}">
                <circle r="${r}" cx="50" cy="50" fill="none" stroke="var(--border-subtle)" stroke-width="14"/>${archi}
                <text x="50" y="50" text-anchor="middle" class="graf-c1">${esc(centro.testo)}</text>
                <text x="50" y="63" text-anchor="middle" class="graf-c2">${esc(centro.sotto)}</text>
            </svg>
            <ul class="graf-legenda">${parti.map((p, i) => `<li><i style="background:${p.colore || PALETTE[i % PALETTE.length]}"></i>${esc(p.etichetta)} <b>${euro(p.valore)}</b> <span>${Math.round(Math.max(0, p.valore) / tot * 100)}%</span></li>`).join('')}</ul></div>`);
    }

    /** Barre divergenti: per ogni riga la quota sul numero (sinistra) e sul valore (destra). */
    function divergenti(titolo, sotto, righe, { sinistra = 'numero', destra = 'valore' } = {}) {
        const totN = righe.reduce((a, r) => a + r.n, 0), totV = righe.reduce((a, r) => a + Math.max(0, r.valore), 0);
        if (!totN || totV <= 0) return scheda(titolo, sotto, '<div class="graf-vuoto">Nessun dato</div>');
        return scheda(titolo, sotto, `<div class="graf-div-testa"><span>% ${esc(sinistra)}</span><span>% ${esc(destra)}</span></div>
            <ul class="graf-div">${righe.map((r, i) => {
                const pn = r.n / totN * 100, pv = Math.max(0, r.valore) / totV * 100;
                return `<li><div class="graf-div-sx"><span>${Math.round(pn)}%</span><div class="graf-barra" style="width:${pn.toFixed(1)}%;background:var(--text-muted)"></div></div>
                    <span class="graf-div-etich" title="${esc(r.etichetta)}: ${r.n} · ${esc(euro(r.valore))}">${esc(r.etichetta)}</span>
                    <div class="graf-div-dx"><div class="graf-barra" style="width:${pv.toFixed(1)}%;background:${r.colore || PALETTE[i % PALETTE.length]}"></div><span>${Math.round(pv)}%</span></div></li>`;
            }).join('')}</ul>`);
    }

    return { barre, impilata, ciambella, divergenti, PALETTE };
})();

window.Grafici = Grafici;
