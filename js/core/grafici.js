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
    /** Attributi di una voce con chiave: cliccabile, raggiungibile da tastiera (vedi alClic) */
    const clic = chiave => chiave === undefined || chiave === null ? '' : ` data-chiave="${esc(chiave)}" role="button" tabindex="0" title="Vedi il dettaglio"`;

    function scheda(titolo, sotto, corpo, extra = '') {
        return `<section class="graf ${extra}"><header><h3>${esc(titolo)}</h3>${sotto ? `<p>${esc(sotto)}</p>` : ''}</header>${corpo}</section>`;
    }

    /** Barre orizzontali: righe [{etichetta, valore, nota?, colore?, evidenzia?, chiave?}], valori in euro. */
    function barre(titolo, sotto, righe, { vuoto = 'Nessun dato' } = {}) {
        const max = Math.max(...righe.map(r => Math.abs(r.valore) || 0), 0);
        if (!righe.length || max <= 0) return scheda(titolo, sotto, `<div class="graf-vuoto">${esc(vuoto)}</div>`);
        const tot = righe.reduce((a, r) => a + (r.valore > 0 ? r.valore : 0), 0);
        return scheda(titolo, sotto, `<ul class="graf-barre">${righe.map((r, i) => {
            const pct = Math.max(0, r.valore) / max * 100;
            const q = r.valore > 0 ? quota(r.valore, tot) : 0;
            // Valore zero o negativo: niente barra (la traccia vuota dice già «niente»)
            const barra = r.valore > 0 ? `<div class="graf-barra" style="width:${pct.toFixed(1)}%;background:${colore(r.colore, i)}"></div>` : '';
            // Riga con chiave: cliccabile (chi disegna il grafico ascolta il clic con alClic)
            return `<li class="${r.evidenzia ? 'evidenzia' : ''}"${clic(r.chiave)}>
                <div class="graf-riga"><span class="graf-etich">${esc(r.etichetta)}</span><span class="graf-val">${euro(r.valore)}${r.nota ? ` · ${esc(r.nota)}` : q ? ` · ${q}%` : ''}</span></div>
                <div class="graf-traccia">${barra}</div></li>`;
        }).join('')}</ul>`);
    }

    /** Una barra divisa in parti [{etichetta, valore, colore, chiave?}] con legenda: quanto del totale è in ogni stato. */
    function impilata(titolo, sotto, parti) {
        const tot = parti.reduce((a, p) => a + Math.max(0, p.valore), 0);
        if (tot <= 0) return scheda(titolo, sotto, '<div class="graf-vuoto">Nessun dato</div>');
        return scheda(titolo, sotto, `<div class="graf-impilata" role="img" aria-label="${esc(parti.map(p => `${p.etichetta} ${euro(p.valore)}`).join(', '))}">
            ${parti.map((p, i) => p.valore > 0 ? `<div style="flex:${p.valore};background:${colore(p.colore, i)}" title="${esc(p.etichetta)}: ${esc(euro(p.valore))}"></div>` : '').join('')}</div>
            <ul class="graf-legenda">${parti.map((p, i) => `<li${clic(p.chiave)}><i style="background:${colore(p.colore, i)}"></i><em title="${esc(p.etichetta)}">${esc(p.etichetta)}</em> <b>${euro(p.valore)}</b> <span>${quota(p.valore, tot)}%</span></li>`).join('')}</ul>`);
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
            <ul class="graf-legenda">${parti.map((p, i) => `<li${clic(p.chiave)}><i style="background:${colore(p.colore, i)}"></i><em title="${esc(p.etichetta)}">${esc(p.etichetta)}</em> <b>${euro(p.valore)}</b> <span>${quota(p.valore, tot)}%</span></li>`).join('')}</ul></div>`);
    }

    /** Barre divergenti: per ogni riga la quota sul numero (sinistra) e sul valore (destra). */
    function divergenti(titolo, sotto, righe, { sinistra = 'numero', destra = 'valore' } = {}) {
        const totN = righe.reduce((a, r) => a + r.n, 0), totV = righe.reduce((a, r) => a + Math.max(0, r.valore), 0);
        if (!totN || totV <= 0) return scheda(titolo, sotto, '<div class="graf-vuoto">Nessun dato</div>');
        return scheda(titolo, sotto, `<div class="graf-div-testa"><span>% ${esc(sinistra)}</span><span>% ${esc(destra)}</span></div>
            <ul class="graf-div">${righe.map((r, i) => {
                const pn = Math.max(0, r.n) / totN * 100, pv = Math.max(0, r.valore) / totV * 100;
                return `<li${clic(r.chiave)}><div class="graf-div-sx"><span>${Math.round(pn)}%</span>${pn > 0 ? `<div class="graf-barra" style="width:${pn.toFixed(1)}%;background:var(--text-muted)"></div>` : ''}</div>
                    <span class="graf-div-etich" title="${esc(r.etichetta)}: ${r.n} · ${esc(euro(r.valore))}">${esc(r.etichetta)}</span>
                    <div class="graf-div-dx">${pv > 0 ? `<div class="graf-barra" style="width:${pv.toFixed(1)}%;background:${colore(r.colore, i)}"></div>` : ''}<span>${Math.round(pv)}%</span></div></li>`;
            }).join('')}</ul>`);
    }

    /**
     * Clic (o Invio/Spazio) su una voce [data-chiave] dentro box: fn(chiave, voce). Un solo ascoltatore per box,
     * che chiama sempre l'ultima fn passata: si può richiamare a ogni ridisegno.
     */
    function alClic(box, fn) {
        if (!box) return;
        box._grafClic = fn;
        if (box._grafAscolta) return;
        box._grafAscolta = true;
        const via = e => {
            const v = e.target.closest('[data-chiave]');
            if (!v || !box.contains(v) || e.target.closest('a, button:not([data-chiave]), input, select')) return;
            if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            box._grafClic?.(v.dataset.chiave, v);
        };
        box.addEventListener('click', via);
        box.addEventListener('keydown', via);
    }

    /**
     * Finestra con l'elenco dietro una voce: colonne [{titolo, valore(r) → testo, html?(r) → HTML, destra?, somma?}].
     * Le colonne con somma hanno il totale in fondo. o: { nota, apri(r) } — con apri ogni riga si apre al clic.
     */
    function elenco(titolo, colonne, righe, o = {}) {
        if (!righe.length) { UI.toast('Nessun elemento da mostrare'); return; }
        const cella = (c, r) => c.html ? c.html(r) : esc(c.valore(r) ?? '—');
        const somme = colonne.some(c => c.somma);
        const tot = c => righe.reduce((a, r) => a + (parseFloat(c.somma(r)) || 0), 0);
        const html = `<div style="max-height:60vh;overflow:auto"><table class="data-table"><thead><tr>${colonne.map(c => `<th${c.destra ? ' class="text-right"' : ''}>${esc(c.titolo)}</th>`).join('')}</tr></thead>
            <tbody>${righe.map((r, i) => `<tr${o.apri ? ` data-riga="${i}" tabindex="0" title="Apri"` : ''}>${colonne.map(c => `<td${c.destra ? ' class="text-right" style="white-space:nowrap"' : ''}>${cella(c, r)}</td>`).join('')}</tr>`).join('')}</tbody>
            ${somme ? `<tfoot><tr>${colonne.map((c, i) => `<td${c.destra ? ' class="text-right" style="white-space:nowrap"' : ''}><b>${c.somma ? esc(euro(tot(c))) : i === 0 ? esc(righe.length + (righe.length === 1 ? ' elemento' : ' elementi')) : ''}</b></td>`).join('')}</tr></tfoot>` : ''}</table></div>
            ${o.nota ? `<div style="font-size:0.8rem;color:var(--text-muted);margin-top:10px">${esc(o.nota)}</div>` : ''}`;
        UI.openModal(titolo, html, null, { readOnly: true, wide: true });
        // Riga apribile: clic, oppure Invio da tastiera
        if (o.apri) document.querySelectorAll('#modal-body tr[data-riga]').forEach(tr => {
            const apri = () => o.apri(righe[+tr.dataset.riga]);
            tr.addEventListener('click', apri);
            tr.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); apri(); } });
        });
    }

    return { barre, impilata, ciambella, divergenti, alClic, elenco, PALETTE };
})();

window.Grafici = Grafici;
