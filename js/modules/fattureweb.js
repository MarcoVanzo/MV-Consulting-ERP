'use strict';

/**
 * FattureWeb (Sistemi) → ERP con un clic.
 *
 * Il pulsante «Da FattureWeb» (nella finestra Importa file) si trascina nella barra dei preferiti.
 * Premuto sulla lista «Fatture di vendita» di FattureWeb, scarica la FatturaPA (XML) di ogni fattura
 * mostrata — con le righe, dove stanno i protocolli e i codici SZ.DPS delle commesse — e apre l'ERP,
 * che le mette nella finestra Importa file: anteprima, poi import (abbinamento alle commesse riga per riga).
 *
 * Il login di FattureWeb ha un reCAPTCHA: l'ERP non ci entra da solo, lavora nella sessione già aperta.
 * Gli XML passano con postMessage: si accettano solo da ORIGINE e solo se sono FatturaPA.
 */
const ModFattureWeb = (() => {
    const ORIGINE = 'https://fattureweb.sistemi.com';
    const MAX_FILE = 500;

    /** Pagina aperta dal pulsante: avvisa FattureWeb che è pronta e aspetta gli XML. */
    function init() {
        if (new URLSearchParams(location.search).get('da') !== 'fattureweb' || !window.opener) return;
        history.replaceState(null, '', location.pathname + location.hash);
        window.addEventListener('message', ricevi);
        window.opener.postMessage({ tipo: 'erp-pronto' }, ORIGINE);
        UI.toast('In attesa delle fatture da FattureWeb…');
    }

    function ricevi(e) {
        if (e.origin !== ORIGINE || e.data?.tipo !== 'fattureweb-xml' || !Array.isArray(e.data.file)) return;
        window.removeEventListener('message', ricevi);
        const files = e.data.file.slice(0, MAX_FILE)
            .filter(f => typeof f?.nome === 'string' && typeof f?.xml === 'string' && /<(\w+:)?FatturaElettronica[\s>]/.test(f.xml))
            .map(f => new File([f.xml], f.nome.replace(/[^\w.-]/g, '_').slice(0, 80) + '.xml', { type: 'text/xml' }));
        if (!files.length) { UI.toast('Da FattureWeb non è arrivata nessuna fattura', 'error'); return; }
        ModImporta.apri(files);
    }

    /**
     * Codice del pulsante, eseguito dentro FattureWeb. Apre subito l'ERP (dopo un'attesa il browser
     * bloccherebbe la finestra), mostra «Tutti» se la lista è su più pagine, scarica gli XML a quattro
     * alla volta e li manda quando l'ERP risponde (anche dopo il login).
     */
    function codice(erp) {
        return `(async () => {
const ERP = ${JSON.stringify(erp)}, O = new URL(ERP).origin;
if (location.hostname !== 'fattureweb.sistemi.com' || !/flight-listafatture/.test(location.search)) {
  alert('Apri FattureWeb su Documenti → Fatture di vendita, filtra quello che ti serve e premi di nuovo il pulsante.'); return; }
const w = window.open(ERP + '?da=fattureweb', 'erp-mv');
if (!w) { alert('Il browser ha bloccato la finestra dell\\'ERP: consenti i popup per FattureWeb.'); return; }
let pronto = false, dati = null;
const manda = () => { if (pronto && dati) w.postMessage({ tipo: 'fattureweb-xml', file: dati }, O); };
addEventListener('message', e => { if (e.origin === O && e.data && e.data.tipo === 'erp-pronto') { pronto = true; manda(); } });
const pag = (document.body.innerText.match(/Pagina \\d+ di (\\d+)/) || [])[1];
if (pag && +pag > 1) {
  const sel = [...document.querySelectorAll('select')].find(s => [...s.options].some(o => o.text.trim() === 'Tutti'));
  if (sel) { sel.value = [...sel.options].find(o => o.text.trim() === 'Tutti').value; sel.dispatchEvent(new Event('change', { bubbles: true }));
    await new Promise(r => setTimeout(r, 4000)); }
}
const ids = [...new Set([...document.querySelectorAll('a[href*="option=saveXML"]')]
  .map(a => (a.getAttribute('href').match(/[?&]id=(\\d+)/) || [])[1]).filter(Boolean))];
if (!ids.length) { alert('Nessuna fattura nella lista.'); w.close(); return; }
const box = document.createElement('div');
box.style.cssText = 'position:fixed;top:12px;right:12px;z-index:99999;background:#1e293b;color:#fff;padding:12px 16px;border-radius:8px;font:14px sans-serif';
document.body.appendChild(box);
const out = [], err = [];
let i = 0;
const uno = async () => { while (i < ids.length) { const id = ids[i++];
  box.textContent = 'ERP: fattura ' + (out.length + err.length + 1) + ' di ' + ids.length + '…';
  try { const u = new URL('index.php', location.href);
    u.search = new URLSearchParams({ section: 'flight-listafatture', option: 'saveXML', tipo: 'fatture', id }).toString();
    const t = await (await fetch(u, { credentials: 'include' })).text();
    if (/<(\\w+:)?FatturaElettronica[\\s>]/.test(t)) out.push({ nome: 'fattureweb-' + id, xml: t }); else err.push(id);
  } catch (e) { err.push(id); } } };
await Promise.all([uno(), uno(), uno(), uno()]);
box.textContent = out.length + ' fatture inviate all\\'ERP' + (err.length ? ' (' + err.length + ' senza XML: bozze o non generate)' : '');
setTimeout(() => box.remove(), 8000);
dati = out; manda();
})();`;
    }

    /** Link da trascinare nei preferiti, con l'indirizzo dell'ERP in cui è stato preso. */
    function link() {
        const erp = location.origin + location.pathname.replace(/[^/]*$/, '');
        const href = 'javascript:' + encodeURIComponent(codice(erp).replace(/\n\s*/g, ' '));
        return `<div class="imp-fattureweb" style="margin-top:10px;font-size:0.85rem;color:var(--text-muted)">
            Da FattureWeb: trascina <a href="${UI.esc(href)}" class="btn btn-sm btn-secondary" draggable="true">
            <i class="ph ph-bookmark-simple"></i> Da FattureWeb</a> nella barra dei preferiti, poi premilo sulla lista
            «Fatture di vendita» (filtrata come ti serve): le fatture arrivano qui con le righe e si abbinano alle commesse.</div>`;
    }

    /** Premuto nell'ERP il pulsante non fa nulla: va trascinato tra i preferiti. */
    function collega(box) {
        box.querySelector('.imp-fattureweb a')?.addEventListener('click', e => {
            e.preventDefault();
            UI.toast('Trascinalo nella barra dei preferiti, poi premilo sulla lista Fatture di vendita di FattureWeb');
        });
    }

    return { init, link, collega, codice };
})();

window.ModFattureWeb = ModFattureWeb;
