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
        scontrino: { nome: 'Scontrino o ricevuta di una spesa', icona: 'ph-receipt' },
    };
    const PER_ESTENSIONE = {
        xml: ['fattura', 'estratto'], p7m: ['fattura'], csv: ['estratto'], xlsx: ['lista_fatture', 'estratto'],
        pdf: ['estratto', 'estratto_carta', 'avviso', 'fattura_pdf', 'incarico', 'offerta', 'scontrino'],
        jpg: ['scontrino'], jpeg: ['scontrino'], png: ['scontrino'], webp: ['scontrino'],
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
            <label class="imp-drop" id="imp-drop" for="imp-input" tabindex="0" role="button">
                <i class="ph ph-upload-simple"></i>
                <strong>Trascina qui i file o tocca per sceglierli</strong>
                <span>Fatture XML/p7m, estratti conto (XML, CSV, Excel, PDF), estratti carta, avvisi di pagamento,
                lista fatture di Sistemi, lettere d'incarico, offerte, foto degli scontrini</span>
            </label>
            <input type="file" id="imp-input" multiple class="visually-hidden"
                accept=".xml,.p7m,.csv,.xlsx,.pdf,.docx,.txt,.md,.jpg,.jpeg,.png,.webp">
            <div id="imp-lista" class="imp-lista" aria-live="polite"></div>`, azione, { wide: true });
        const input = document.getElementById('imp-input');
        // Da tastiera: Invio o spazio sulla zona aprono la scelta dei file
        document.getElementById('imp-drop').addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
        });
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
        if (['jpg', 'jpeg', 'png', 'webp'].includes(v.ext)) return 'scontrino';
        if (['docx', 'txt', 'md'].includes(v.ext)) return 'offerta';
        if (v.ext === 'xlsx') return /estratt|moviment|conto|banca/.test(nome) ? 'estratto' : 'lista_fatture';
        if (v.ext === 'xml') {
            v.testo = await v.file.text();
            if (/<(\w+:)?FatturaElettronica\b/.test(v.testo)) return 'fattura';
            if (/BkToCstmrStmt|camt\.05[234]/.test(v.testo)) return 'estratto';
            return null;
        }
        v.pagine = await pagine(v.file);
        // Criteri prudenti, dal più specifico: nel dubbio il tipo lo sceglie l'utente
        const t = v.pagine.join('\n').toLowerCase();
        if (/estratto conto carta|cartabcc|numia/.test(t) && /totale operazioni/.test(t)) return 'estratto_carta';
        if (/pagamento fornitore|avviso di pagamento|distinta di pagamento/.test(t)) return 'avviso';
        if (/lettera d.incarico|conferimento (dell.|d.)?incarico|incarico professionale/.test(t)) return 'incarico';
        if (/estratto conto|saldo iniziale|saldo finale|elenco movimenti|lista movimenti/.test(t)) return 'estratto';
        if (/fattura (n\.?|nr\.?|numero)|numero fattura|fattura elettronica|tipo documento/.test(t)) return 'fattura_pdf';
        if (/documento commerciale|scontrino|ricevuta fiscale|ricevuta di pagamento|pedaggio|biglietto/.test(t)) return 'scontrino';
        if (/preventivo|proposta economica|offerta economica|offerta n/.test(t)) return 'offerta';
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
            case 'scontrino':
                module = 'spese'; action = 'importa_scontrino';
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
        if (_fase === 'fatto') { UI.closeModal(); apriOfferta(); return; }
        const pronte = _voci.filter(v => v.tipo && !['errore', 'lettura', 'importato'].includes(v.stato));
        if (!pronte.length) throw new Error('Aggiungi almeno un file e scegline il tipo');
        if (_fase === 'scelta') {
            for (const v of pronte.filter(x => x.tipo !== 'incarico')) await anteprima(v);
            // Le lettere d'incarico si leggono (con l'AI) quattro alla volta
            await aGruppi(pronte.filter(x => x.tipo === 'incarico'), 4, anteprima);
            segnaDoppioni();
            _fase = 'anteprima';
        } else {
            const daFare = pronte.filter(daSalvare);
            for (const v of daFare) await importa(v);
            _fase = 'fatto';
            // La vista sotto si aggiorna subito, anche se poi si chiude la finestra con la X
            ricarica();
            // Una lettera non salvata (cliente mancante, errore) si corregge in tabella e si riprova
            if (_voci.some(v => v.inc && !v.inc.salta && ['ok', 'errore'].includes(v.stato))) _fase = 'anteprima';
        }
        aggiorna();
        // La modale rimette l'etichetta del pulsante a fine azione: si ridisegna subito dopo
        setTimeout(aggiorna, 0);
    }

    /** Voce pronta per l'import: le lettere senza cliente aspettano che lo si scelga in tabella. */
    const daSalvare = v => v.stato === 'ok' && !(v.inc && (v.inc.salta || !v.inc.cliente_id));

    async function aGruppi(voci, n, fn) {
        const coda = [...voci];
        await Promise.all(Array.from({ length: Math.min(n, coda.length) }, async () => {
            while (coda.length) await fn(coda.shift());
        }));
    }

    async function anteprima(v) {
        if (v.tipo === 'incarico') return leggiIncarico(v);
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

    // ── Lettere d'incarico: lette tutte, riviste in tabella, salvate insieme ──

    /** Legge la lettera (AI o regole) senza salvare: i dati finiscono in v.inc, modificabili in tabella. */
    async function leggiIncarico(v) {
        if (v.inc) { v.stato = 'ok'; return; }
        v.stato = 'analisi'; aggiorna();
        try {
            const fd = Store.formDataFromArray('pages', (v.pagine || await pagine(v.file)).map(p => p.replace(/\n/g, ' ')));
            fd.append('file', v.file);
            fd.append('file_nome', v.file.name);
            const r = await Store.upload('import_pdf', 'incarichi', fd);
            v.inc = {
                ...r,
                importo_totale: r.importo_totale || 0,
                giorni_pagamento: r.giorni_pagamento ?? 30,
                // Un doppione non si salva, salvo che l'utente lo rimetta
                salta: !!r.duplicato,
            };
            v.stato = 'ok';
        } catch (err) { v.stato = 'errore'; v.errore = err.message; }
        aggiorna();
    }

    /** Due file dello stesso import con lo stesso protocollo: si tiene il primo. */
    function segnaDoppioni() {
        const visti = new Map();
        _voci.filter(v => v.inc && !v.inc.duplicato).forEach(v => {
            const k = chiaveProtocollo(v.inc.numero_protocollo);
            if (!k) return;
            if (visti.has(k)) { v.inc.doppioneDi = visti.get(k).file.name; v.inc.salta = true; }
            else visti.set(k, v);
        });
    }
    const chiaveProtocollo = s => ((String(s || '').match(/\d{1,6}\s*\/\s*\d{4}/) || [String(s || '')])[0]).replace(/[^0-9A-Za-z]/g, '').toUpperCase();

    async function salvaIncarico(v) {
        const d = v.inc;
        if (!d.cliente_id) throw new Error('Scegli il cliente');
        if (!(parseFloat(d.importo_totale) > 0)) throw new Error('Importo mancante');
        // Cliente cambiato a mano: il sottocliente letto si cerca (o si crea) per nome sotto il nuovo cliente
        const sottoId = d.cliente_id == d.cliente_id_letto ? d.sottocliente_id : null;
        const r = await Store.api('save', 'incarichi', {
            cliente_id: d.cliente_id, sottocliente_id: sottoId || '',
            sottocliente_nuovo: sottoId ? '' : (d.sottocliente_nome || d.sottocliente_nuovo || ''),
            data_incarico: d.data_incarico || '', tipo_commessa: d.tipo_commessa || '', numero_protocollo: d.numero_protocollo || '',
            descrizione: d.descrizione || '', num_giornate: d.num_giornate || 0, importo_totale: d.importo_totale,
            giorni_pagamento: d.giorni_pagamento, condizioni_pagamento: d.condizioni_pagamento || '', pdf_path: d.pdf_path || '',
        });
        return r;
    }

    function tabellaIncarichi(voci) {
        const clienti = window.ModClienti ? ModClienti.getClienti() : [];
        const bloccato = _fase === 'fatto';
        const righe = voci.map(v => {
            const d = v.inc;
            if (d.cliente_id_letto === undefined) d.cliente_id_letto = d.cliente_id;
            const cOpts = `<option value="">— scegli —</option>` + clienti.map(c => `<option value="${UI.esc(c.id)}" ${c.id == d.cliente_id ? 'selected' : ''}>${UI.esc(c.ragione_sociale)}</option>`).join('');
            const stato = v.stato === 'importato' ? ['badge-green', 'Salvata']
                : v.stato === 'errore' ? ['badge-red', 'Errore']
                : d.duplicato ? ['badge-gray', 'Già presente']
                : d.doppioneDi ? ['badge-gray', 'Doppione']
                : !d.cliente_id ? ['badge-yellow', 'Manca il cliente'] : ['badge-blue', 'Nuova'];
            const nota = v.stato === 'errore' ? v.errore
                : d.duplicato ? `Già registrata: protocollo ${d.duplicato.numero_protocollo}${d.duplicato.sottocliente ? ' · ' + d.duplicato.sottocliente : ''}`
                : d.doppioneDi ? `Stesso protocollo di ${d.doppioneDi}`
                : !d.cliente_id && d.cliente_nome ? `Letto: ${d.cliente_nome}` : (d.avvisi || []).join(' · ');
            const off = bloccato || v.stato === 'importato';
            return `<tr data-id="${v.id}" class="${d.salta ? 'imp-inc-saltata' : ''}">
                <td><input type="checkbox" class="imp-inc-si" ${d.salta ? '' : 'checked'} ${off ? 'disabled' : ''} aria-label="Importa ${UI.esc(v.file.name)}"></td>
                <td><div class="imp-inc-nome">${UI.esc(d.sottocliente_nome || d.sottocliente_nuovo || v.file.name)}</div>
                    <div class="imp-inc-file">${UI.esc(v.file.name)}</div></td>
                <td><select class="form-control imp-inc-cliente" ${off ? 'disabled' : ''}>${cOpts}</select></td>
                <td><input class="form-control imp-inc-prot" value="${UI.esc(d.numero_protocollo || '')}" ${off ? 'disabled' : ''}></td>
                <td><input type="number" step="0.01" class="form-control imp-inc-importo" value="${UI.esc(d.importo_totale)}" ${off ? 'disabled' : ''}></td>
                <td>${UI.esc(d.data_incarico ? UI.formatDate(d.data_incarico) : '—')}<div class="imp-inc-file">${UI.esc(UI.tipoCommessa(d.tipo_commessa || ''))}</div></td>
                <td><span class="badge ${stato[0]}">${stato[1]}</span>${nota ? `<div class="imp-inc-nota">${UI.esc(nota)}</div>` : ''}</td>
            </tr>`;
        }).join('');
        const conta = f => voci.filter(f).length;
        const nuove = conta(v => !v.inc.salta && v.inc.cliente_id && v.stato !== 'importato');
        const senza = conta(v => !v.inc.salta && !v.inc.cliente_id);
        const salvate = conta(v => v.stato === 'importato');
        return `<div class="imp-inc">
            <div class="imp-inc-titolo"><strong>${UI.plurale(voci.length, "lettera d'incarico", "lettere d'incarico")}</strong>
                <span>${[salvate && UI.plurale(salvate, 'salvata', 'salvate'), nuove && `${nuove} da salvare`, senza && `${senza} senza cliente`,
                    conta(v => v.inc.duplicato) && UI.plurale(conta(v => v.inc.duplicato), 'già presente', 'già presenti'),
                    conta(v => v.inc.doppioneDi) && UI.plurale(conta(v => v.inc.doppioneDi), 'doppione', 'doppioni')].filter(Boolean).join(' · ')}</span></div>
            <div class="table-container"><table class="data-table imp-inc-tab"><thead><tr><th></th><th>Azienda</th><th>Cliente</th><th>Protocollo</th><th>Importo €</th><th>Data</th><th>Stato</th></tr></thead>
            <tbody>${righe}</tbody></table></div></div>`;
    }

    function collegaTabella(box) {
        box.querySelectorAll('.imp-inc-tab tr[data-id]').forEach(tr => {
            const v = _voci.find(x => x.id === +tr.dataset.id);
            if (!v?.inc) return;
            const riprova = () => { if (v.stato === 'errore') { v.stato = 'ok'; v.errore = null; } };
            tr.querySelector('.imp-inc-si')?.addEventListener('change', e => { riprova(); v.inc.salta = !e.target.checked; aggiorna(); });
            tr.querySelector('.imp-inc-cliente')?.addEventListener('change', e => { riprova(); v.inc.cliente_id = e.target.value || null; aggiorna(); });
            tr.querySelector('.imp-inc-prot')?.addEventListener('change', e => { riprova(); v.inc.numero_protocollo = e.target.value.trim(); });
            tr.querySelector('.imp-inc-importo')?.addEventListener('change', e => { riprova(); v.inc.importo_totale = e.target.value; });
        });
    }

    async function importa(v) {
        if (v.tipo === 'incarico') {
            v.stato = 'analisi';
            try { await salvaIncarico(v); v.stato = 'importato'; }
            catch (err) { v.stato = 'errore'; v.errore = err.message; }
            aggiorna();
            return;
        }
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
            case 'scontrino': {
                const sp = d.spesa || {};
                righe.push(`${sp.categoria || 'Spesa'} di ${UI.formatCurrency(sp.importo)} del ${UI.formatDate(sp.data)}${sp.esercente ? ' · ' + sp.esercente : ''}`);
                righe.push(`Pagata con ${({ carta: 'carta aziendale', carta_personale: 'carta personale' })[sp.metodo] || sp.metodo || '—'}${sp.abbinata_carta ? ' · abbinata al movimento della carta' : ''}`);
                avvisi.push(...(d.avvisi || []));
                break;
            }
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
        // Le lettere già lette stanno in una tabella sola, le altre voci restano schede
        const inTabella = _voci.filter(v => v.tipo === 'incarico' && v.inc);
        box.innerHTML = (inTabella.length ? tabellaIncarichi(inTabella) : '') + _voci.filter(v => !inTabella.includes(v)).map(v => {
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
        collegaTabella(box);
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
            const nOk = _voci.filter(daSalvare).length;
            // Tutto già in anteprima (es. scelto in tabella il cliente mancante): si importa senza rileggere
            const attive = _voci.filter(x => x.tipo && !['errore', 'lettura', 'importato'].includes(x.stato));
            if (_fase === 'scelta' && nOk && attive.every(x => x.stato === 'ok')) _fase = 'anteprima';
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
        const vista = document.querySelector('.view-section.active')?.id.replace('view-', '');
        if (vista && window.apriVista) apriVista(vista, undefined, { storia: false });
        if (window.ModClienti) ModClienti.load(); // gli import creano clienti e prospect
    }

    /** Offerta importata: si apre da controllare quando si chiude l'import. */
    function apriOfferta() {
        const offerta = _voci.find(v => v.offertaId);
        if (offerta && window.ModOfferte) ModOfferte.edit(offerta.offertaId);
    }

    /** Trascinando file sulla finestra si apre l'importazione. */
    function initTrascina() {
        let timer = null;
        const haFile = e => [...(e.dataTransfer?.types || [])].includes('Files');
        const dentro = () => !document.getElementById('app-shell')?.classList.contains('hidden');
        // Un file trascinato su un campo file (allegati di offerte, spese, mezzi) o su un'altra finestra aperta
        // va a quel campo, non all'importazione
        const perAltri = e => e.target.closest?.('input[type=file]') || (UI.isModalOpen() && !document.getElementById('imp-lista'));
        document.addEventListener('dragover', e => {
            if (!haFile(e) || !dentro() || perAltri(e)) return;
            e.preventDefault();
            document.body.classList.add('trascina-file');
            clearTimeout(timer);
            timer = setTimeout(() => document.body.classList.remove('trascina-file'), 150);
        });
        document.addEventListener('drop', e => {
            if (!haFile(e) || !dentro() || perAltri(e)) return;
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
