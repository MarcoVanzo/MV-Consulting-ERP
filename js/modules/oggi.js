'use strict';

/**
 * Oggi — la dashboard: numeri di sintesi (endpoint indicatori/oggi, definizioni in docs/indicatori.md),
 * cosa c'è da fare, incassi attesi nelle prossime settimane, trasferte del mese.
 * Ogni numero porta alla vista dove il dato vive; sotto, le scadenze nel dettaglio (ModCommerciale).
 */
const ModOggi = (() => {
    const euro = v => UI.formatCurrency(v);
    const euroBreve = v => {
        const n = Math.abs(v || 0);
        return n >= 1000 ? `${(v / 1000).toLocaleString('it-IT', { maximumFractionDigits: 1 })}k` : `${Math.round(v || 0)}`;
    };

    async function load() {
        const box = document.getElementById('oggi-contenuto');
        if (!box) return;
        if (!box.innerHTML) box.innerHTML = '<div class="oggi-attesa"><i class="ph ph-spinner ph-spin"></i> Carico la situazione…</div>';
        try {
            const d = await Store.api('oggi', 'indicatori');
            render(box, d);
        } catch (e) {
            box.innerHTML = `<div class="empty-state"><h3>Situazione non disponibile</h3><p>${UI.esc(e.message)}</p></div>`;
        }
        ModCommerciale.loadScadenzario();
    }

    function render(box, d) {
        const f = d.fatture || {}, o = d.offerte || {}, r = d.rate_da_fatturare || {}, p = d.partner || {};
        const df = d.da_fare || {}, t = d.trasferte || {};
        const banca = df.movimenti_da_sistemare?.num || 0;
        const oggi = new Date(d.oggi || Date.now()).toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });

        box.innerHTML = `
            <p class="oggi-data">${UI.esc(oggi)}</p>
            <div class="oggi-kpi">
                ${kpi('Pipeline pesata', euro(o.pipeline_pesata), `${UI.plurale(o.num_lead, 'lead', 'lead')} · ${o.num_bozze || 0} bozze · ${o.num_inviate || 0} inviate`, 'vendite', 'comm-offerte')}
                ${kpi(`Da fatturare · ${r.giorni || 30} gg`, euro(r.importo), `${UI.plurale(r.num_rate, 'rata', 'rate')} da comunicare`, 'vendite', 'tab-incarichi')}
                ${kpi('Da incassare', euro(f.da_incassare), f.scaduto > 0 ? `di cui <b class="oggi-rosso">${euro(f.scaduto)} scaduti</b>` : 'niente di scaduto', 'incassi', 'tab-fatture', true)}
                ${kpi('Partner da pagare', euro(p.da_pagare), p.num_scaduti ? `<b class="oggi-rosso">${UI.plurale(p.num_scaduti, 'fattura scaduta', 'fatture scadute')}</b>` : UI.plurale(p.num_da_pagare, 'fattura', 'fatture'), 'incassi', 'inc-ricevute', true)}
                ${kpi('Banca da sistemare', UI.plurale(banca, 'movimento', 'movimenti'), `${df.movimenti_da_abbinare?.num || 0} da abbinare · ${df.movimenti_da_classificare?.num || 0} da classificare`, 'banca', 'tab-riconciliazione')}
                ${kpi(`Trasferte di ${meseNome(t.dal)}`, `${(t.km || 0).toLocaleString('it-IT')} km`, `${euro(t.da_rimborsare)} da rimborsare`, 'trasferte', 'trasferte-viaggi')}
            </div>
            <div class="oggi-colonne">
                <section class="oggi-pannello" aria-labelledby="oggi-dafare-t">
                    <h2 id="oggi-dafare-t">Da fare</h2>
                    ${daFare(df, t)}
                </section>
                <section class="oggi-pannello" aria-labelledby="oggi-pipe-t">
                    <h2 id="oggi-pipe-t">Offerte</h2>
                    ${pipeline(o)}
                </section>
            </div>
            <div class="oggi-colonne">
                <section class="oggi-pannello" aria-labelledby="oggi-inc-t">
                    <h2 id="oggi-inc-t">Incassi attesi · prossime 12 settimane</h2>
                    ${grafico(d.incassi_attesi)}
                </section>
                <section class="oggi-pannello" aria-labelledby="oggi-tra-t">
                    <h2 id="oggi-tra-t">Trasferte di ${UI.esc(meseNome(t.dal))}</h2>
                    ${trasferte(t)}
                </section>
            </div>`;

        box.querySelectorAll('[data-vai]').forEach(b => b.addEventListener('click', () => {
            const [v, pane] = b.dataset.vai.split('/');
            if (v === 'dettaglio') { document.querySelector('.oggi-dettaglio')?.scrollIntoView({ behavior: 'smooth' }); return; }
            window.apriVista(v, pane);
        }));
    }

    function kpi(label, valore, sub, vista, pane, subHtml = false) {
        return `<button type="button" class="oggi-card" data-vai="${vista}/${pane}">
            <span class="oggi-label">${UI.esc(label)}</span>
            <span class="oggi-valore">${valore}</span>
            <span class="oggi-sub">${subHtml ? sub : UI.esc(sub)}</span>
        </button>`;
    }

    function daFare(df, t) {
        const voci = [
            ['rosso', df.incassi_scaduti?.num, n => `Sollecitare ${UI.plurale(n, 'fattura scaduta', 'fatture scadute')}`, df.incassi_scaduti?.importo, 'dettaglio'],
            ['giallo', df.rate_da_fatturare?.num, n => `Comunicare ${UI.plurale(n, 'rata', 'rate')} da fatturare entro ${df.giorni} giorni`, df.rate_da_fatturare?.importo, 'dettaglio'],
            ['giallo', df.partner_da_pagare?.num, n => `Pagare ${UI.plurale(n, 'fattura', 'fatture')} di partner entro ${df.giorni} giorni`, df.partner_da_pagare?.importo, 'dettaglio'],
            ['blu', df.offerte_da_ricontattare?.num, n => `Ricontattare ${UI.plurale(n, 'offerta', 'offerte')}`, df.offerte_da_ricontattare?.importo, 'dettaglio'],
            ['giallo', df.movimenti_da_abbinare?.num, n => `Abbinare ${UI.plurale(n, 'movimento bancario', 'movimenti bancari')}`, null, 'banca/tab-riconciliazione'],
            ['blu', df.movimenti_da_classificare?.num, n => `Classificare ${UI.plurale(n, 'movimento', 'movimenti')}`, null, 'banca/tab-classificare'],
            ['grigio', t.num_senza_cliente, n => `Assegnare ${UI.plurale(n, 'giornata', 'giornate')} di trasferta a un cliente`, null, 'trasferte/trasferte-viaggi'],
        ].filter(v => (v[1] || 0) > 0);
        if (!voci.length) return '<p class="oggi-vuoto"><i class="ph ph-check-circle"></i> Niente in sospeso</p>';
        return `<ul class="oggi-dafare">${voci.map(([col, n, testo, imp, vai]) => `
            <li><button type="button" class="oggi-voce v-${col}" data-vai="${vai}">
                <span>${UI.esc(testo(n))}</span>${imp ? `<em>${euro(imp)}</em>` : '<i class="ph ph-caret-right" aria-hidden="true"></i>'}
            </button></li>`).join('')}</ul>`;
    }

    function pipeline(o) {
        const fasi = [['Lead', o.num_lead, o.valore_lead], ['In bozza', o.num_bozze, o.bozze], ['Inviate', o.num_inviate, o.pipeline], ['Accettate', o.num_accettate, o.accettato], ['Perse', o.num_perse, null]];
        const max = Math.max(1, ...fasi.map(f => f[2] || 0));
        return `<div class="oggi-fasi">${fasi.map(([nome, n, v]) => `
            <div class="oggi-fase"><span>${nome}</span>
                <span class="oggi-barra"><span style="width:${v ? Math.max(2, Math.round(v / max * 100)) : 0}%"></span></span>
                <span class="oggi-num">${n || 0}${v ? ' · ' + euroBreve(v) : ''}</span></div>`).join('')}
            </div>
            <p class="oggi-nota">${o.tasso_conversione !== null && o.tasso_conversione !== undefined ? `Conversione ${o.tasso_conversione}% (accettate su chiuse)` : 'Nessuna offerta chiusa'} · tutti gli anni</p>
            <button type="button" class="btn btn-ghost btn-sm" data-vai="vendite/comm-offerte">Vai alle offerte <i class="ph ph-caret-right"></i></button>`;
    }

    function grafico(ia) {
        if (!ia) return '';
        const col = [{ et: 'scad.', fatt: ia.scaduto.fatturate, daf: ia.scaduto.da_fatturare, scaduto: true },
            ...ia.settimane.map((s, i) => ({ et: i === 0 ? 'ora' : `S${i}`, dal: s.dal, fatt: s.fatturate, daf: s.da_fatturare }))];
        const max = Math.max(...col.map(c => c.fatt + c.daf));
        if (max <= 0) return '<p class="oggi-vuoto">Nessun incasso atteso nelle prossime settimane</p>';
        // Scala a un numero tondo: le etichette dell'asse sono valori che il grafico raggiunge davvero
        const passo = [1, 2, 5].map(m => m * Math.pow(10, Math.floor(Math.log10(max)))).find(p => p * 2 >= max) || Math.pow(10, Math.ceil(Math.log10(max)));
        const top = passo * 2;
        const W = 320, H = 150, x0 = 30, y0 = 122, h = 104, bw = 14, step = (W - x0 - 4) / col.length;
        let svg = '';
        [0, passo, top].forEach(v => {
            const y = y0 - v / top * h;
            svg += `<line x1="${x0}" x2="${W - 2}" y1="${y}" y2="${y}" class="g-griglia"/><text x="${x0 - 5}" y="${y + 3}" text-anchor="end" class="g-testo">${euroBreve(v)}</text>`;
        });
        col.forEach((c, i) => {
            const x = x0 + i * step + (step - bw) / 2;
            const ha = c.fatt / top * h, hb = c.daf / top * h;
            const tit = `${c.scaduto ? 'Già scaduto' : 'Settimana dal ' + UI.formatDate(c.dal)}: ${euro(c.fatt)} fatturato${c.daf ? ', ' + euro(c.daf) + ' ancora da fatturare' : ''}`;
            svg += `<g><title>${UI.esc(tit)}</title>`;
            if (ha > 0) svg += `<rect x="${x}" y="${y0 - ha}" width="${bw}" height="${ha}" rx="2" class="${c.scaduto ? 'g-scaduto' : 'g-fatt'}"/>`;
            if (hb > 0) svg += `<rect x="${x}" y="${y0 - ha - hb}" width="${bw}" height="${hb}" rx="2" class="g-daf"/>`;
            svg += `<text x="${x + bw / 2}" y="${y0 + 14}" text-anchor="middle" class="g-testo">${c.et}</text></g>`;
        });
        const totale = col.slice(1).reduce((a, c) => a + c.fatt + c.daf, 0);
        return `<svg viewBox="0 0 ${W} ${H}" class="oggi-grafico" role="img" aria-label="Incassi attesi: ${euro(totale)} nelle prossime 12 settimane, ${euro(col[0].fatt)} già scaduti">${svg}</svg>
            <div class="oggi-legenda"><span><i class="g-scaduto"></i>scaduto</span><span><i class="g-fatt"></i>fatture in scadenza</span><span><i class="g-daf"></i>rate ancora da fatturare (IVA inclusa)</span></div>
            <p class="oggi-nota">${euro(totale)} attesi in 12 settimane</p>`;
    }

    function trasferte(t) {
        return `<div class="oggi-tre">
                <div><b>${t.num_giornate || 0}</b><span>${(t.num_giornate || 0) === 1 ? 'giornata' : 'giornate'}</span></div>
                <div><b>${(t.km || 0).toLocaleString('it-IT')}</b><span>km${t.costo_km ? ' · ' + euro(t.costo_km) + '/km' : ''}</span></div>
                <div><b>${euro(t.da_rimborsare)}</b><span>da rimborsare</span></div>
            </div>
            <p class="oggi-nota">${t.rimborso_km === null ? 'Imposta il costo al km nelle trasferte per calcolare il rimborso chilometrico. ' : `Km ${euro(t.rimborso_km)} · `}indennità ${euro(t.indennita)} · spese ${euro(t.spese)}</p>
            <button type="button" class="btn btn-ghost btn-sm" data-vai="trasferte/trasferte-viaggi">Vai alle trasferte <i class="ph ph-caret-right"></i></button>`;
    }

    function meseNome(data) {
        return data ? new Date(data).toLocaleDateString('it-IT', { month: 'long' }) : 'questo mese';
    }

    return { load };
})();

window.ModOggi = ModOggi;
