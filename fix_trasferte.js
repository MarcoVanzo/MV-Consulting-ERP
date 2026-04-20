        const grouped = {};
        _trasferte.forEach(t => {
            if (!grouped[t.data_trasferta]) {
                grouped[t.data_trasferta] = {
                    data: t.data_trasferta,
                    mattina: { nome: '—', id: null },
                    pomeriggio: { nome: '—', id: null },
                    km_totali: 0,
                    has_client: false
                };
            }
            const nome = t.sottocliente_nome ? UI.esc(t.sottocliente_nome) : (t.cliente_nome ? UI.esc(t.cliente_nome) : '');
            if (nome) {
                grouped[t.data_trasferta].has_client = true;
            }
            
            // Allow editing even if name is empty
            const displayName = nome || 'Trip (' + t.id + ')';
            if (t.fascia_oraria === 'mattino') {
                grouped[t.data_trasferta].mattina = { nome: displayName, id: t.id };
            } else if (t.fascia_oraria === 'pomeriggio') {
                grouped[t.data_trasferta].pomeriggio = { nome: displayName, id: t.id };
            } else {
                grouped[t.data_trasferta].mattina = { nome: displayName, id: t.id };
                grouped[t.data_trasferta].pomeriggio = { nome: displayName, id: t.id };
            }
            
            grouped[t.data_trasferta].km_totali += (parseFloat(t.km_andata || 0) + parseFloat(t.km_ritorno || 0));
        });
