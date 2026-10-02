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
    /** Solo un token del tema o un esadecimale finiscono in style: qualunque altro valore prende il colore della palette. */
    const COLORE_VALIDO = /^(var\(--[\w-]+\)|#[0-9a-f]{3,8})$/i;
    const colore = (c, i) => (typeof c === 'string' && COLORE_VALIDO.test(c.trim())) ? c.trim() : PALETTE[i % PALETTE.length];
    /** Percentuale della parte sul totale, per legende e descrizioni */
    const quota = (v, tot) => tot > 0 ? Math.round(Math.max(0, v) / tot * 100) : 0;

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
            const q = r.valore > 0 ? quota(r.valore, tot) : 0;
            // Valore zero o negativo: niente barra (la traccia vuota dice già «niente»)
            const barra = r.valore > 0 ? `<div class="graf-barra" style="width:${pct.toFixed(1)}%;background:${colore(r.colore, i)}"></div>` : '';
            return `<li class="${r.evidenzia ? 'evidenzia' : ''}">
                <div class="graf-riga"><span class="graf-etich">${esc(r.etichetta)}</span><span class="graf-val">${euro(r.valore)}${r.nota ? ` · ${esc(r.nota)}` : q ? ` · ${q}%` : ''}</span></div>
                <div class="graf-traccia">${barra}</div></li>`;
        }).join('')}</ul>`);
    }

    /** Una barra divisa in parti [{etichetta, valore, colore}] con legenda: quanto del totale è in ogni stato. */
    function impilata(titolo, sotto, parti) {
        const tot = parti.reduce((a, p) => a + Math.max(0, p.valore), 0);
        if (tot <= 0) return scheda(titolo, sotto, '<div class="graf-vuoto">Nessun dato</div>');
        return scheda(titolo, sotto, `<div class="graf-impilata" role="img" aria-label="${esc(parti.map(p => `${p.etichetta} ${euro(p.valore)}`).join(', '))}">
            ${parti.map((p, i) => p.valore > 0 ? `<div style="flex:${p.valore};background:${colore(p.colore, i)}" title="${esc(p.etichetta)}: ${esc(euro(p.valore))}"></div>` : '').join('')}</div>
            <ul class="graf-legenda">${parti.map((p, i) => `<li><i style="background:${colore(p.colore, i)}"></i>${esc(p.etichetta)} <b>${euro(p.valore)}</b> <span>${quota(p.valore, tot)}%</span></li>`).join('')}</ul>`);
    }

    /**
     * Ciambella: quota del primo elemento sul totale, al centro la percentuale; legenda con le altre parti.
     * Ogni fetta positiva ha una lunghezza minima (si vede anche una quota dell'1%); le altre si riducono
     * in proporzione perché il giro resti intero. L'aria-label elenca tutte le parti.
     */
    function ciambella(titolo, sotto, parti, centro) {
        const tot = parti.reduce((a, p) => a + Math.max(0, p.valore), 0);
        if (tot <= 0) return scheda(titolo, sotto, '<div class="graf-vuoto">Nessun dato</div>');
        const r = 42, C = 2 * Math.PI * r, MINIMO = 3.5, STACCO = 1.2;
        const positive = parti.map((p, i) => ({ ...p, col: colore(p.colore, i) })).filter(p => p.valore > 0);
        const grezze = positive.map(p => p.valore / tot * C);
        const piccole = grezze.filter(l => l < MINIMO);
        const resto = grezze.filter(l => l >= MINIMO).reduce((a, l) => a + l, 0);
        const scala = resto > 0 ? Math.max(0, C - piccole.length * MINIMO) / resto : 1;
        const lunghezze = grezze.map(l => l < MINIMO ? MINIMO : l * scala);
        let fatto = 0;
        const archi = positive.map((p, i) => {
            const len = lunghezze[i];
            const tratto = positive.length > 1 ? Math.max(len * 0.5, len - STACCO) : len;
            const s = `<circle r="${r}" cx="50" cy="50" fill="none" stroke="${p.col}" stroke-width="14"
                stroke-dasharray="${tratto.toFixed(2)} ${C.toFixed(2)}" stroke-dashoffset="${(-fatto).toFixed(2)}" transform="rotate(-90 50 50)"><title>${esc(p.etichetta)}: ${esc(euro(p.valore))}</title></circle>`;
            fatto += len;
            return s;
        }).join('');
        const descr = `${centro.testo} ${centro.sotto}. ` + parti.map(p => `${p.etichetta}: ${euro(p.valore)}, ${quota(p.valore, tot)}%`).join('; ');
        return scheda(titolo, sotto, `<div class="graf-ciambella">
            <svg viewBox="0 0 100 100" role="img" aria-label="${esc(descr)}">
                <circle r="${r}" cx="50" cy="50" fill="none" stroke="var(--border-subtle)" stroke-width="14"/>${archi}
                <text x="50" y="51" text-anchor="middle" class="graf-c1">${esc(centro.testo)}</text>
                <text x="50" y="64" text-anchor="middle" class="graf-c2">${esc(centro.sotto)}</text>
            </svg>
            <ul class="graf-legenda">${parti.map((p, i) => `<li><i style="background:${colore(p.colore, i)}"></i>${esc(p.etichetta)} <b>${euro(p.valore)}</b> <span>${quota(p.valore, tot)}%</span></li>`).join('')}</ul></div>`);
    }

    /** Barre divergenti: per ogni riga la quota sul numero (sinistra) e sul valore (destra). */
    function divergenti(titolo, sotto, righe, { sinistra = 'numero', destra = 'valore' } = {}) {
        const totN = righe.reduce((a, r) => a + r.n, 0), totV = righe.reduce((a, r) => a + Math.max(0, r.valore), 0);
        if (!totN || totV <= 0) return scheda(titolo, sotto, '<div class="graf-vuoto">Nessun dato</div>');
        return scheda(titolo, sotto, `<div class="graf-div-testa"><span>% ${esc(sinistra)}</span><span>% ${esc(destra)}</span></div>
            <ul class="graf-div">${righe.map((r, i) => {
                const pn = Math.max(0, r.n) / totN * 100, pv = Math.max(0, r.valore) / totV * 100;
                return `<li><div class="graf-div-sx"><span>${Math.round(pn)}%</span>${pn > 0 ? `<div class="graf-barra" style="width:${pn.toFixed(1)}%;background:var(--text-muted)"></div>` : ''}</div>
                    <span class="graf-div-etich" title="${esc(r.etichetta)}: ${r.n} · ${esc(euro(r.valore))}">${esc(r.etichetta)}</span>
                    <div class="graf-div-dx">${pv > 0 ? `<div class="graf-barra" style="width:${pv.toFixed(1)}%;background:${colore(r.colore, i)}"></div>` : ''}<span>${Math.round(pv)}%</span></div></li>`;
            }).join('')}</ul>`);
    }

    return { barre, impilata, ciambella, divergenti, PALETTE };
})();

window.Grafici = Grafici;
