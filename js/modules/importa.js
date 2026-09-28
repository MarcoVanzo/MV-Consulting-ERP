'use strict';

/**
 * Importa — un solo punto d'ingresso per tutti i file: fatture elettroniche (XML/p7m, emesse o
 * ricevute), estratti conto (XML CBI/camt, CSV, Excel, PDF), estratti della carta, avvisi di
 * pagamento, lista fatture di Sistemi, fatture PDF, lettere d'incarico, offerte.
 *
 * 1. riconosce il tipo di ogni file (si può correggere);
 * 2. anteprima: il server esegue l'import vero e lo annulla (api/Shared/Anteprima.php);
 * 3. importa quello che l'utente conferma.
 */
const ModImporta = (() => {
    const TIPI = {
        fattura: { nome: 'Fattura elettronica', icona: 'ph-file-code' },
        estratto: { nome: 'Estratto conto', icona: 'ph-bank' },
        estratto_carta: { nome: 'Estratto carta di credito', icona: 'ph-credit-card' },
        avviso: { nome: 'Avviso di pagamento', icona: 'ph-money' },
        lista_fatture: { nome: 'Lista fatture di Sistemi', icona: 'ph-file-xls' },
        fattura_pdf: { nome: 'Fattura PDF (lettura approssimativa)', icona: 'ph-file-pdf' },
        incarico: { nome: "Lettera d'incarico", icona: 'ph-file-text' },
        offerta: { nome: 'Offerta o preventivo', icona: 'ph-file-doc' },
    };
    const PER_ESTENSIONE = {
        xml: ['fattura', 'estratto'], p7m: ['fattura'], csv: ['estratto'], xlsx: ['lista_fatture', 'estratto'],
        pdf: ['estratto', 'estratto_carta', 'avviso', 'fattura_pdf', 'incarico', 'offerta'],
        docx: ['offerta'], txt: ['offerta'], md: ['offerta'],
    };
    const METODI = { cbi: 'XML CBI/camt', tabella: 'tabella CSV/Excel', ai: 'PDF con lettura AI', regole: 'PDF a regole', carta: 'estratto carta' };
    const CAMPI_MAPPA = [['data_operazione', 'Data *'], ['data_valuta', 'Valuta'], ['descrizione', 'Descrizione *'],
        ['importo', 'Importo (con segno)'], ['dare', 'Dare / uscite'], ['avere', 'Avere / entrate']];

    let _voci = [];
    let _fase = 'scelta'; // scelta → anteprima → fatto
    let _seq = 0;

    // ── Apertura e file ──────────────────────────────────

    function apri(files = []) {
        if (UI.isModalOpen() && document.getElementById('imp-lista')) { aggiungi(files); return; }
        _voci = []; _fase = 'scelta';
        UI.openModal('Importa file', `
            <label class="imp-drop" id="imp-drop" for="imp-input">
                <i class="ph ph-upload-simple"></i>
                <strong>Trascina qui i file o tocca per sceglierli</strong>
                <span>Fatture XML/p7m, estratti conto (XML, CSV, Excel, PDF), estratti carta, avvisi di pagamento,
                lista fatture di Sistemi, lettere d'incarico, offerte</span>
            </label>
            <input type="file" id="imp-input" multiple class="hidden"
                accept=".xml,.p7m,.csv,.xlsx,.pdf,.docx,.txt,.md">
            <div id="imp-lista" class="imp-lista" aria-live="polite"></div>`, azione, { wide: true });
        const input = document.getElementById('imp-input');
        input.addEventListener('change', e => { aggiungi([...e.target.files]); e.target.value = ''; });
        const drop = document.getElementById('imp-drop');
        ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('attivo'); }));
        ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, () => drop.classList.remove('attivo')));
        drop.addEventListener('drop', e => { e.preventDefault(); aggiungi([...(e.dataTransfer?.files || [])]); });
        aggiorna();
        if (files.length) aggiungi(files);
    }

    async function aggiungi(files) {
        if (_fase === 'fatto') { _voci = []; _fase = 'scelta'; }
        for (const file of files) {
            const ext = (file.name.split('.').pop() || '').toLowerCase();
            const v = { id: ++_seq, file, ext, tipo: null, stato: 'lettura', esito: null, mappa: null, verso: '' };
            _voci.push(v);
            aggiorna();
            try {
                if (!PER_ESTENSIONE[ext]) throw new Error('Formato non gestito');
                v.tipo = await riconosci(v);
                v.stato = v.tipo ? 'pronto' : 'scegli';
            } catch (e) { v.stato = 'errore'; v.errore = e.message; }
            _fase = 'scelta';
            aggiorna();
        }
    }

    /** Tipo dal nome e dal contenuto; null = lo sceglie l'utente. */
    async function riconosci(v) {
        const nome = v.file.name.toLowerCase();
        if (v.ext === 'p7m') return 'fattura';
        if (v.ext === 'csv') return 'estratto';
        if (['docx', 'txt', 'md'].includes(v.ext)) return 'offerta';
        if (v.ext === 'xlsx') return /estratt|moviment|conto|banca/.test(nome) ? 'estratto' : 'lista_fatture';
        if (v.ext === 'xml') {
            v.testo = await v.file.text();
            if (/<(\w+:)?FatturaElettronica\b/.test(v.testo)) return 'fattura';
            if (/BkToCstmrStmt|camt\.05[234]/.test(v.testo)) return 'estratto';
            return null;
        }
        v.pagine = await pagine(v.file);
        const t = v.pagine.join('\n').toLowerCase();
        if (/numia|cartabcc|carta di credito|estratto conto carta/.test(t)) return 'estratto_carta';
        if (/pagamento fornitore|avviso di pagamento|distinta di pagamento/.test(t)) return 'avviso';
        if (/lettera d.incarico|conferimento (dell.)?incarico|incarico professionale/.test(t)) return 'incarico';
        if (/estratto conto|saldo iniziale|saldo finale|elenco movimenti|lista movimenti/.test(t)) return 'estratto';
        if (/offerta|preventivo|proposta economica/.test(t)) return 'offerta';
        if (/fattura/.test(t)) return 'fattura_pdf';
        return null;
    }

    /** Testo delle pagine con gli a capo (serve ai parser degli estratti). */
    async function pagine(file) {
        const pdf = await pdfjsLib.getDocument({ data: await file.arrayBuffer() }).promise;
        const out = [];
        for (let i = 1; i <= pdf.numPages; i++) {
            const tc = await (await pdf.getPage(i)).getTextContent();
            let s = '', lastY = null;
            tc.items.forEach(it => {
                const y = Math.round(it.transform[5]);
                if (lastY !== null && Math.abs(y - lastY) > 2) s += '\n';
                else if (s && !s.endsWith(' ')) s += ' ';
                s += it.str;
                lastY = y;
            });
            out.push(s);
        }
        return out;
    }

    // ── Richieste al server ──────────────────────────────

    async function base64(file) {
        const buf = new Uint8Array(await file.arrayBuffer());
        let bin = '';
        for (let j = 0; j < buf.length; j += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(j, j + 0x8000));
        return btoa(bin);
    }

    async function richiesta(v, anteprima) {
        const piatte = () => (v.pagine || []).map(p => p.replace(/\n/g, ' '));
        let fd = new FormData(), module, action;
        switch (v.tipo) {
            case 'fattura':
                module = 'importa'; action = 'fattura';
                if (v.ext === 'p7m') fd.append('file_b64', await base64(v.file));
                else fd.append('xml', v.testo ?? await v.file.text());
                if (v.verso) fd.append('verso', v.verso);
                break;
            case 'estratto':
            case 'estratto_carta':
                module = 'riconciliazione'; action = 'import_estratto';
                if (v.ext === 'xml') fd.append('xml', v.testo ?? await v.file.text());
                else if (v.ext === 'csv') { fd.append('formato', 'csv'); fd.append('csv', await v.file.text()); }
                else if (v.ext === 'xlsx') fd.append('formato', 'xlsx');
                else fd = Store.formDataFromArray('pages', v.pagine || await pagine(v.file));
                if (v.tipo === 'estratto_carta') fd.append('tipo', 'carta');
                if (v.mappa) fd.append('mappa', JSON.stringify(v.mappa));
                break;
            case 'avviso':
                module = 'contabilita'; action = 'import_payment_pdf';
                fd = Store.formDataFromArray('pages', piatte());
                break;
            case 'fattura_pdf':
                module = 'contabilita'; action = 'import_pdf';
                fd = Store.formDataFromArray('pages', piatte());
                break;
            case 'lista_fatture':
                module = 'contabilita'; action = 'import_lista';
                fd.append('file', v.file);
                break;
            case 'offerta':
                module = 'offerte'; action = 'importa';
                fd.append('file', v.file);
                break;
            default:
                throw new Error('Scegli il tipo di documento');
        }
        if (!fd.has('file')) fd.append('originale', v.file);
        fd.append('file_nome', v.file.name);
        if (anteprima) fd.append('anteprima', '1');
        return Store.upload(action, module, fd);
    }

    // ── Azioni ───────────────────────────────────────────

    async function azione() {
        if (_fase === 'fatto') { UI.closeModal(); ricarica(); return; }
        const pronte = _voci.filter(v => v.tipo && !['errore', 'lettura', 'importato'].includes(v.stato));
        if (!pronte.length) throw new Error('Aggiungi almeno un file e scegline il tipo');
        if (_fase === 'scelta') {
            for (const v of pronte) await anteprima(v);
            _fase = 'anteprima';
        } else {
            const daFare = pronte.filter(v => v.stato === 'ok' && v.tipo !== 'incarico');
            for (const v of daFare) await importa(v);
            _fase = 'fatto';
            const incarico = pronte.find(v => v.tipo === 'incarico' && v.stato === 'ok');
            if (incarico) { UI.closeModal(); ricarica(); ModIncarichi.importPdf(incarico.file); return; }
        }
        aggiorna();
        // La modale rimette l'etichetta del pulsante a fine azione: si ridisegna subito dopo
        setTimeout(aggiorna, 0);
    }

    async function anteprima(v) {
        if (v.tipo === 'incarico') {
            v.stato = 'ok';
            v.riassunto = { righe: ['Si apre la scheda del nuovo incarico con i dati letti dal PDF: controllali e salva.'], avvisi: [] };
            return;
        }
        v.stato = 'analisi'; aggiorna();
        try {
            const r = await richiesta(v, true);
            const e = r?.esito || {};
            v.mappatura = null; v.chiediVerso = false;
            if (e.success) { v.stato = 'ok'; v.riassunto = riassumi(v, e.data || {}); }
            else if (e.data?.serve_mappatura) { v.stato = 'scegli'; v.mappatura = e.data; v.mappa = v.mappa || { ...e.data.proposta }; }
            else if (e.data?.serve_verso) { v.stato = 'scegli'; v.chiediVerso = true; v.errore = e.message; }
            else { v.stato = 'errore'; v.errore = e.message || 'Import non riuscito'; }
        } catch (err) { v.stato = 'errore'; v.errore = err.message; }
        aggiorna();
    }

    async function importa(v) {
        v.stato = 'analisi'; aggiorna();
        try {
            const r = await richiesta(v, false);
            v.stato = 'importato';
            v.riassunto = riassumi(v, r || {});
            if (v.tipo === 'offerta' && r?.id) v.offertaId = r.id;
        } catch (err) { v.stato = 'errore'; v.errore = err.message; }
        aggiorna();
    }

    /** Esito del server → righe leggibili e avvisi, uguali in anteprima e dopo l'import. */
    function riassumi(v, d) {
        const righe = [], avvisi = [...(d.avvisi_sistema || [])];
        const n = (x, uno, molti) => UI.plurale(x, uno, molti);
        switch (v.tipo) {
            case 'fattura':
                righe.push(`${n(d.num_imported, 'fattura nuova', 'fatture nuove')}`);
                avvisi.push(...(d.errors || []), ...(d.messages || []));
                break;
            case 'estratto':
            case 'estratto_carta':
                righe.push(`${n(d.letti, 'movimento letto', 'movimenti letti')}, ${n(d.nuovi, 'nuovo', 'nuovi')}${d.gia_presenti ? `, ${d.gia_presenti} già importati` : ''}`);
                righe.push(`${n(d.abbinati, 'abbinato a una fattura', 'abbinati a fatture')}${d.da_verificare ? `, ${d.da_verificare} da verificare` : ''}`);
                if (d.banca || d.metodo) righe.push(`${d.banca || ''}${d.banca && d.metodo ? ' · ' : ''}${d.metodo ? 'letto come ' + (METODI[d.metodo] || d.metodo) : ''}`);
                avvisi.push(...(d.avvisi || []));
                break;
            case 'avviso':
                righe.push(`${n(d.num_matched, 'fattura segnata pagata', 'fatture segnate pagate')}, ${d.num_already_paid ?? 0} già pagate, ${d.num_not_found ?? 0} non trovate`);
                if (d.totale_pagamento) righe.push(`Totale ${UI.formatCurrency(d.totale_pagamento)}${d.data_pagamento ? ' del ' + UI.formatDate(d.data_pagamento) : ''}`);
                avvisi.push(...(d.messages || []).filter(m => typeof m === 'string'));
                break;
            case 'lista_fatture':
                righe.push(`${n(d.num_imported, 'fattura nuova', 'fatture nuove')}, ${d.num_existing ?? 0} già presenti`);
                if (d.num_different) avvisi.push(`${d.num_different} con totale diverso da quello registrato`);
                if (d.num_without_client) avvisi.push(`${d.num_without_client} senza cliente in anagrafica`);
                avvisi.push(...(d.errors || []));
                break;
            case 'fattura_pdf':
                righe.push(`${n(d.num_imported, 'fattura nuova', 'fatture nuove')} (IVA al 22% se non indicata: controllale)`);
                avvisi.push(...(d.errors || []));
                break;
            case 'offerta':
                righe.push('Offerta in bozza, da controllare');
                avvisi.push(...(d.avvisi || []));
                break;
        }
        return { righe, avvisi: avvisi.filter(Boolean).map(a => typeof a === 'string' ? a : (a.html ? a.html.replace(/<[^>]+>/g, '') : JSON.stringify(a))) };
    }

    // ── Vista ────────────────────────────────────────────

    const STATI = {
        lettura: ['badge-gray', 'Lettura…'], pronto: ['badge-blue', 'Pronto'], scegli: ['badge-yellow', 'Serve una scelta'],
        analisi: ['badge-blue', 'In corso…'], ok: ['badge-green', 'Anteprima pronta'], importato: ['badge-green', 'Importato'],
        errore: ['badge-red', 'Errore'],
    };

    function aggiorna() {
        const box = document.getElementById('imp-lista');
        if (!box) return;
        box.innerHTML = _voci.map(v => {
            const [cls, label] = STATI[v.stato] || STATI.pronto;
            const opzioni = (PER_ESTENSIONE[v.ext] || []).map(t => `<option value="${t}" ${t === v.tipo ? 'selected' : ''}>${UI.esc(TIPI[t].nome)}</option>`).join('');
            const bloccato = ['analisi', 'lettura', 'importato'].includes(v.stato) || _fase === 'fatto';
            return `<div class="imp-voce" data-id="${v.id}">
                <div class="imp-testa">
                    <i class="ph ${TIPI[v.tipo]?.icona || 'ph-file'}" aria-hidden="true"></i>
                    <span class="imp-nome">${UI.esc(v.file.name)}</span>
                    <span class="badge ${cls}">${label}</span>
                    ${bloccato ? '' : `<button type="button" class="btn btn-ghost btn-sm imp-togli" aria-label="Togli ${UI.esc(v.file.name)}"><i class="ph ph-x"></i></button>`}
                </div>
                ${opzioni ? `<label class="imp-tipo"><span>Tipo</span><select class="form-control" ${bloccato ? 'disabled' : ''}>${v.tipo ? '' : '<option value="">— scegli —</option>'}${opzioni}</select></label>` : ''}
                ${v.chiediVerso ? `<label class="imp-tipo"><span>La fattura è</span><select class="form-control imp-verso"><option value="">— scegli —</option><option value="attiva">emessa da noi</option><option value="passiva">ricevuta da un fornitore</option></select></label>` : ''}
                ${v.mappatura ? mappaturaHtml(v) : ''}
                ${v.errore && v.stato !== 'ok' && v.stato !== 'importato' ? `<div class="imp-errore">${UI.esc(v.errore)}</div>` : ''}
                ${v.riassunto && ['ok', 'importato'].includes(v.stato) ? `<ul class="imp-riassunto">${v.riassunto.righe.map(r => `<li>${UI.esc(r)}</li>`).join('')}</ul>
                    ${v.riassunto.avvisi.length ? `<details class="imp-avvisi"><summary>${UI.plurale(v.riassunto.avvisi.length, 'nota', 'note')}</summary><ul>${v.riassunto.avvisi.map(a => `<li>${UI.esc(a)}</li>`).join('')}</ul></details>` : ''}` : ''}
            </div>`;
        }).join('') || '';
        box.querySelectorAll('.imp-voce').forEach(el => {
            const v = _voci.find(x => x.id === +el.dataset.id);
            el.querySelector('.imp-togli')?.addEventListener('click', () => { _voci = _voci.filter(x => x !== v); aggiorna(); });
            el.querySelector('.imp-tipo select:not(.imp-verso)')?.addEventListener('change', e => { v.tipo = e.target.value || null; rimetti(v); });
            el.querySelector('.imp-verso')?.addEventListener('change', e => { v.verso = e.target.value; rimetti(v); });
            el.querySelectorAll('[data-mappa]').forEach(s => s.addEventListener('change', () => {
                const k = s.dataset.mappa;
                v.mappa[k] = s.value === '' ? null : +s.value;
                rimetti(v, false);
            }));
        });
        const btn = document.getElementById('modal-save');
        if (btn) {
            const nOk = _voci.filter(x => x.stato === 'ok').length;
            btn.innerHTML = _fase === 'fatto' ? 'Chiudi' : _fase === 'anteprima' && nOk
                ? `<i class="ph ph-check"></i> Importa ${UI.plurale(nOk, 'file', 'file')}` : '<i class="ph ph-eye"></i> Mostra anteprima';
            if (_fase === 'anteprima' && !nOk) _fase = 'scelta';
        }
    }

    /** Dopo una scelta dell'utente l'anteprima va rifatta. */
    function rimetti(v, ridisegna = true) {
        v.stato = v.tipo ? 'pronto' : 'scegli';
        v.riassunto = null; v.errore = null;
        if (v.tipo !== 'fattura') v.chiediVerso = false;
        if (_fase === 'anteprima') _fase = 'scelta';
        if (ridisegna) aggiorna(); else {
            const btn = document.getElementById('modal-save');
            if (btn) btn.innerHTML = '<i class="ph ph-eye"></i> Mostra anteprima';
        }
    }

    function mappaturaHtml(v) {
        const cols = v.mappatura.intestazione || [];
        const opts = sel => `<option value="">—</option>` + cols.map((c, i) => `<option value="${i}" ${sel === i ? 'selected' : ''}>${UI.esc(c || 'Colonna ' + (i + 1))}</option>`).join('');
        const es = (v.mappatura.esempio || [])[0] || [];
        return `<div class="imp-mappa"><p>Non riconosco le colonne di questo file: indica dove sono data, descrizione e importo.
            La scelta vale anche per i prossimi file con le stesse colonne.</p>
            <div class="imp-mappa-griglia">${CAMPI_MAPPA.map(([k, l]) => `<label><span>${l}</span><select class="form-control" data-mappa="${k}">${opts(v.mappa?.[k] ?? null)}</select></label>`).join('')}</div>
            ${es.length ? `<p class="imp-esempio">Prima riga: ${es.map(UI.esc).join(' · ')}</p>` : ''}</div>`;
    }

    /** Dopo l'import si aggiorna quello che è a schermo. */
    function ricarica() {
        const offerta = _voci.find(v => v.offertaId);
        [window.ModContabilita, window.ModRiconciliazione, window.ModIncarichi, window.ModPartner, window.ModCommerciale, window.ModClienti]
            .forEach(m => { try { m?.load?.(); } catch (e) { /* vista non aperta */ } });
        if (offerta && window.ModOfferte) ModOfferte.edit(offerta.offertaId);
    }

    /** Trascinando file sulla finestra si apre l'importazione. */
    function initTrascina() {
        let timer = null;
        const haFile = e => [...(e.dataTransfer?.types || [])].includes('Files');
        const dentro = () => !document.getElementById('app-shell')?.classList.contains('hidden');
        document.addEventListener('dragover', e => {
            if (!haFile(e) || !dentro()) return;
            e.preventDefault();
            document.body.classList.add('trascina-file');
            clearTimeout(timer);
            timer = setTimeout(() => document.body.classList.remove('trascina-file'), 150);
        });
        document.addEventListener('drop', e => {
            if (!haFile(e) || !dentro()) return;
            if (e.target.closest?.('#imp-drop')) return;
            e.preventDefault();
            document.body.classList.remove('trascina-file');
            apri([...e.dataTransfer.files]);
        });
        document.addEventListener('click', e => {
            if (e.target.closest?.('[data-importa]')) apri();
        });
    }

    return { apri, initTrascina };
})();

window.ModImporta = ModImporta;
