'use strict';

/**
 * Sportello Cloud (Sistemi, www.sportello.cloud) → ERP con un clic.
 *
 * Il pulsante «Da Sportello» (nella finestra Importa file) si trascina nella barra dei preferiti.
 * Premuto sulla lista «Fatture di acquisto» (o «di vendita») di Sportello, filtrata per date:
 * mostra tutte le righe del filtro su una pagina (fino a 100), le seleziona, preme Scarica → Prosegui
 * come farebbe l'utente e, invece di salvare lo ZIP, lo apre in memoria, ne estrae le FatturaPA e le
 * passa all'ERP, che le mette nella finestra Importa file: anteprima, poi import. Le fatture già
 * presenti si saltano (fornitore + numero + data), quindi ripassare lo stesso periodo non duplica nulla.
 *
 * Sportello è un'applicazione «a stato sul server» (Sistemi builderweb): la selezione arriva al server
 * solo da clic veri sulle caselle, e il file si scarica da un iframe nascosto con `_dlk=` nell'indirizzo.
 * Il pulsante intercetta quell'indirizzo (setter di `iframe.src`) e lo rilegge con fetch nella sessione
 * dell'utente: niente credenziali fuori dal browser (il login ha un reCAPTCHA).
 * Sportello manda `Cross-Origin-Opener-Policy: same-origin` (niente postMessage con la finestra aperta) e
 * la CSP blocca le chiamate verso altri siti: gli XML viaggiano compressi nel frammento dell'indirizzo
 * (`#sportello=…`, mai inviato a nessun server) della scheda ERP che l'utente apre con un clic. All'arrivo
 * passano solo le FatturaPA, e niente si registra senza l'anteprima e la conferma di Importa file.
 * Nel pannello Scarica di Sportello vanno lasciati «Documento principale» e «Originale», senza PDF.
 */
const ModSportello = (() => {
    const CHIAVE = 'erp_sportello';
    const PREFISSO = '#sportello=';
    const MAX_FILE = 500;

    /**
     * All'avvio, prima del router: il frammento #sportello=… (JSON compresso con gzip, base64url) passa in
     * sessionStorage e sparisce dall'indirizzo, così resta anche se prima serve il login.
     */
    function catturaHash() {
        if (!location.hash.startsWith(PREFISSO)) return;
        try { sessionStorage.setItem(CHIAVE, location.hash.slice(PREFISSO.length)); } catch { /* troppo grande: si perde */ }
        history.replaceState(null, '', location.pathname + '#oggi');
    }

    /** Dopo il login: se sono arrivate fatture da Sportello, le apre in Importa file. */
    async function init() {
        let dati = null;
        try { dati = sessionStorage.getItem(CHIAVE); sessionStorage.removeItem(CHIAVE); } catch { /* niente */ }
        if (!dati) return;
        try {
            const files = fileDaMessaggio(await decodifica(dati));
            if (!files.length) { UI.toast('Da Sportello non è arrivata nessuna fattura', 'error'); return; }
            ModImporta.apri(files);
        } catch {
            UI.toast('Le fatture arrivate da Sportello non sono leggibili: ripremi il pulsante', 'error');
        }
    }

    /** base64url → gzip → JSON {verso: 'passiva'|'attiva', file: [{nome, xml}]} */
    async function decodifica(testo) {
        const b64 = testo.replace(/-/g, '+').replace(/_/g, '/');
        const bin = Uint8Array.from(atob(b64 + '='.repeat((4 - b64.length % 4) % 4)), c => c.charCodeAt(0));
        const json = await new Response(new Blob([bin]).stream().pipeThrough(new DecompressionStream('gzip'))).text();
        return JSON.parse(json);
    }

    /** File da passare a Importa file: solo FatturaPA, nomi ripuliti, al massimo MAX_FILE. */
    function fileDaMessaggio(pacco) {
        const dati = pacco?.file, verso = ['attiva', 'passiva'].includes(pacco?.verso) ? pacco.verso : '';
        if (!Array.isArray(dati)) return [];
        if (dati.length > MAX_FILE) UI.toast(`Arrivano le prime ${MAX_FILE} fatture: restringi le date su Sportello per le altre.`, 'error');
        return dati.slice(0, MAX_FILE)
            .filter(f => typeof f?.nome === 'string' && typeof f?.xml === 'string' && /<(\w+:)?FatturaElettronica[\s>]/.test(f.xml))
            .map(f => {
                const file = new File([f.xml], 'sportello-' + f.nome.replace(/\.xml$/i, '').replace(/[^\w.-]/g, '_').slice(0, 80) + '.xml', { type: 'text/xml' });
                if (verso) file.versoImport = verso;
                return file;
            });
    }

    /**
     * Codice del pulsante, eseguito dentro Sportello. Porta la griglia a 100 righe per pagina, seleziona
     * tutto con la casella dell'intestazione, preme Scarica → Prosegui → Ok intercettando il file, decomprime
     * lo ZIP con DecompressionStream (un solo documento arriva come XML semplice), decodifica ogni XML con
     * l'encoding della dichiarazione e mostra «Apri nell'ERP»: un link vero, perché la finestra si apre solo
     * da un clic (a fine scarico il clic sul preferito è troppo lontano e il browser la bloccherebbe).
     */
    function codice(erp) {
        return `(async () => {
const ERP = ${JSON.stringify(erp)};
const sleep = ms => new Promise(r => setTimeout(r, ms));
const grid = document.querySelector('.e-grid'), g = grid && grid.ej2_instances && grid.ej2_instances[0];
if (!/(^|\\.)sportello\\.cloud$/.test(location.hostname) || !g || !/Fatture di (acquisto|vendita)/.test(document.title + ' ' + document.body.innerText.slice(0, 3000))) {
  alert('Apri Sportello su Fatture → Fatture di acquisto, filtra le date che ti servono e premi di nuovo il pulsante.'); return; }
const tot = +(g.pageSettings.totalRecordsCount || 0);
if (!tot) { alert('Nessuna fattura nel filtro di Sportello.'); return; }
if (tot > 100 && !confirm('Nel filtro ci sono ' + tot + ' fatture: arrivano le prime 100. Per le altre restringi le date e ripeti. Continuare?')) return;
const box = document.createElement('div');
box.style.cssText = 'position:fixed;top:12px;left:50%;transform:translateX(-50%);z-index:99999;background:#1e293b;color:#fff;padding:12px 16px;border-radius:8px;font:14px sans-serif';
document.body.appendChild(box);
const msg = t => { box.textContent = 'ERP: ' + t; };
const bottone = t => [...document.querySelectorAll('button')].find(b => b.offsetParent && b.innerText.trim().toLowerCase() === t);
const attendi = async (f, ms) => { const fine = Date.now() + ms; let v; while (!(v = f()) && Date.now() < fine) await sleep(300); return v; };
const src = Object.getOwnPropertyDescriptor(HTMLIFrameElement.prototype, 'src');
let preso = null;
const esci = t => { Object.defineProperty(HTMLIFrameElement.prototype, 'src', src); if (t) alert(t); box.remove(); };
try {
  /* 1. Tutte le righe del filtro su una pagina: si aspetta che la griglia si ricarichi */
  const attese = Math.min(tot, 100);
  if (g.pageSettings.pageSize < attese) { msg('preparo la lista…'); g.pageSettings.pageSize = 100;
    if (!await attendi(() => g.getCurrentViewRecords().length >= attese, 30000)) return esci('La lista di Sportello non si è ricaricata: riprova.'); await sleep(800); }
  /* 2. Selezione con un clic vero sulla casella dell'intestazione: solo così arriva al server */
  g.clearSelection(); await sleep(500);
  const tutte = grid.querySelector('.e-checkselectall');
  if (!tutte) return esci('Non trovo la casella «seleziona tutto» di Sportello.');
  (tutte.closest('.e-checkbox-wrapper') || tutte).click();
  const righe = g.getCurrentViewRecords().length;
  if (!await attendi(() => g.getSelectedRecords().length === righe, 10000)) return esci('Sportello non ha selezionato le fatture: riprova.');
  await sleep(1500);
  /* 3. Scarica → Prosegui → Ok: il file non va su disco, se ne tiene solo l'indirizzo */
  Object.defineProperty(HTMLIFrameElement.prototype, 'src', { configurable: true, get() { return src.get.call(this); },
    set(v) { if (/[?&]_dlk=/.test(String(v))) { preso = String(v); return src.set.call(this, 'about:blank'); } return src.set.call(this, v); } });
  const sc = bottone('scarica'); if (!sc) return esci('Non trovo il pulsante Scarica di Sportello.');
  msg('Sportello prepara ' + righe + ' fatture…'); sc.click();
  const pr = await attendi(() => bottone('prosegui'), 15000); if (!pr) return esci('Il pannello Scarica di Sportello non si è aperto.');
  pr.click();
  const ok = await attendi(() => bottone('ok'), 180000); if (ok) ok.click();
  if (!await attendi(() => preso, 60000)) return esci('Sportello non ha consegnato il file: riprova o scarica a mano.');
  Object.defineProperty(HTMLIFrameElement.prototype, 'src', src);
  /* 4. ZIP (più fatture) o XML (una sola) */
  msg('leggo le fatture…');
  const b = new Uint8Array(await (await fetch(preso, { credentials: 'include' })).arrayBuffer());
  const pezzi = [];
  if (b[0] === 0x50 && b[1] === 0x4b) {
    const dv = new DataView(b.buffer); let e = b.length - 22; while (e >= 0 && dv.getUint32(e, true) !== 0x06054b50) e--;
    if (e < 0) return esci('Lo ZIP di Sportello non è leggibile.');
    let p = dv.getUint32(e + 16, true);
    for (let k = dv.getUint16(e + 10, true); k > 0; k--) {
      const m = dv.getUint16(p + 10, true), cs = dv.getUint32(p + 20, true), nl = dv.getUint16(p + 28, true), off = dv.getUint32(p + 42, true);
      const nome = new TextDecoder().decode(b.subarray(p + 46, p + 46 + nl));
      p += 46 + nl + dv.getUint16(p + 30, true) + dv.getUint16(p + 32, true);
      const d0 = off + 30 + dv.getUint16(off + 26, true) + dv.getUint16(off + 28, true);
      let d = b.subarray(d0, d0 + cs);
      if (m === 8) d = new Uint8Array(await new Response(new Blob([d]).stream().pipeThrough(new DecompressionStream('deflate-raw'))).arrayBuffer());
      else if (m !== 0) d = null;
      pezzi.push({ nome: nome.split('/').pop(), d });
    }
  } else pezzi.push({ nome: 'fattura', d: b });
  /* La FatturaPA può essere in ISO-8859-1 o windows-1252: si decodifica con l'encoding della dichiarazione e la si riscrive in UTF-8 */
  const out = []; let altri = 0;
  for (const x of pezzi) {
    if (!x.d) { altri++; continue; }
    const enc = (new TextDecoder('windows-1252').decode(x.d.slice(0, 200)).match(/<\\?xml[^>]*encoding=["']([\\w.:-]+)["']/i) || [])[1] || 'utf-8';
    let t; try { t = new TextDecoder(enc).decode(x.d); } catch (z) { t = new TextDecoder('utf-8').decode(x.d); }
    t = t.replace(/^(\\s*<\\?xml[^>]*encoding=["'])[\\w.:-]+/i, '$1UTF-8');
    if (/<(\\w+:)?FatturaElettronica[\\s>]/.test(t)) out.push({ nome: x.nome, xml: t }); else altri++;
  }
  g.clearSelection();
  /* La lista dice il verso: le fatture di acquisto sono ricevute anche se il cessionario non è la nostra P.IVA */
  const verso = /Fatture di vendita/.test(document.title) ? 'attiva' : /Fatture di acquisto/.test(document.title) ? 'passiva' : '';
  if (!out.length) return esci('Nel file di Sportello non ci sono XML di fatture: in Scarica lascia «Documento principale» e «Originale», senza PDF.');
  /* JSON → gzip → base64url nel frammento: resta nel browser. Chrome regge indirizzi fino a 2 MB e gli XML con il
     PDF allegato si comprimono poco: oltre ~1,2 MB compressi si fanno più lotti, ognuno col suo link */
  const gzip = async t => new Uint8Array(await new Response(new Blob([t]).stream().pipeThrough(new CompressionStream('gzip'))).arrayBuffer());
  const LIM = 1200000, lotti = [[]]; let peso = 0, grandi = 0;
  for (const o of out) { const n = (await gzip(JSON.stringify(o))).length;
    if (n > LIM) { grandi++; continue; }
    if (lotti[lotti.length - 1].length && peso + n > LIM) { lotti.push([]); peso = 0; }
    lotti[lotti.length - 1].push(o); peso += n; }
  if (!lotti[0].length) return esci('Le fatture sono troppo grandi per passare all\\'ERP: scaricale da Sportello e trascinale in Importa file.');
  const link = [];
  for (const l of lotti) { const gz = await gzip(JSON.stringify({ verso, file: l }));
    let s = ''; for (let i = 0; i < gz.length; i += 32768) s += String.fromCharCode.apply(null, gz.subarray(i, i + 32768));
    link.push({ n: l.length, url: ERP + '#sportello=' + btoa(s).replace(/\\+/g, '-').replace(/\\//g, '_').replace(/=+$/, '') }); }
  msg((out.length - grandi) + ' fatture pronte' + (altri ? ' · ' + altri + ' file non XML saltati' : '') + (grandi ? ' · ' + grandi + ' troppo grandi, da trascinare a mano' : '')
    + (tot > 100 ? ' · ' + (tot - 100) + ' restano fuori' : '') + ' ');
  let aperti = 0;
  link.forEach((k, i) => { const a = document.createElement('a'); a.href = k.url; a.target = '_blank'; a.rel = 'noopener';
    a.textContent = link.length > 1 ? 'Apri nell\\'ERP ' + (i + 1) + '/' + link.length + ' (' + k.n + ')' : 'Apri nell\\'ERP';
    a.style.cssText = 'display:inline-block;color:#fff;background:#2563eb;padding:6px 12px;border-radius:6px;margin:4px 0 0 8px;text-decoration:none;font-weight:600';
    a.addEventListener('click', () => setTimeout(() => { a.remove(); if (++aperti === link.length) box.remove(); }, 300)); box.append(a); });
  const x = document.createElement('button'); x.textContent = '✕'; x.title = 'Chiudi';
  x.style.cssText = 'margin-left:8px;background:none;border:0;color:#fff;cursor:pointer;font-size:14px';
  x.addEventListener('click', () => box.remove()); box.append(x);
} catch (e) { esci('Errore durante lo scarico da Sportello: ' + e.message); }
})();`;
    }

    /** Link da trascinare nei preferiti, con l'indirizzo dell'ERP in cui è stato preso. */
    function link() {
        const erp = location.origin + location.pathname.replace(/[^/]*$/, '');
        const href = 'javascript:' + encodeURIComponent(codice(erp).replace(/\n\s*/g, ' '));
        return `<div class="imp-sportello" style="margin-top:10px;font-size:0.85rem;color:var(--text-muted)">
            Da Sportello: trascina <a href="${UI.esc(href)}" class="btn btn-sm btn-secondary" draggable="true">
            <i class="ph ph-bookmark-simple"></i> Da Sportello</a> nella barra dei preferiti, poi premilo su Sportello
            in «Fatture di acquisto» (filtrate per date): le fatture arrivano qui, quelle già presenti si saltano.</div>`;
    }

    /** Premuto nell'ERP il pulsante non fa nulla: va trascinato tra i preferiti. */
    function collega(box) {
        box.querySelector('.imp-sportello a')?.addEventListener('click', e => {
            e.preventDefault();
            UI.toast('Trascinalo nella barra dei preferiti, poi premilo su Sportello in Fatture di acquisto');
        });
    }

    return { catturaHash, init, link, collega, codice, decodifica, fileDaMessaggio };
})();

window.ModSportello = ModSportello;
