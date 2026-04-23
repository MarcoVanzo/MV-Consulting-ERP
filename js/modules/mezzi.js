'use strict';

/**
 * Modulo Mezzi — Gestione Flotta Aziendale per MV Consulting ERP
 */
const ModMezzi = (() => {
    let _mezzi = [];
    let _currentVehicle = null;

    async function init() {
        const container = document.getElementById('trasferte-mezzi');
        if (!container) return;
        
        container.innerHTML = '<div style="padding:40px; text-align:center; color:var(--text-muted);"><i class="ph ph-spinner ph-spin" style="font-size:2rem;"></i><br>Caricamento mezzi...</div>';
        
        try {
            _mezzi = await Store.api('getAllVehicles', 'mezzi');
            renderDashboard();
        } catch (err) {
            container.innerHTML = `<div class="empty-state"><i class="ph ph-warning"></i><h3>Errore</h3><p>${UI.esc(err.message)}</p></div>`;
            UI.toast('Errore caricamento mezzi', 'error');
        }
    }

    function renderDashboard() {
        const container = document.getElementById('trasferte-mezzi');
        
        const isManager = true; // In MV Consulting ERP assumiamo admin/manager per default
        const countActive = _mezzi.filter(v => v.stato === 'attivo').length;
        const countMaint = _mezzi.filter(v => v.stato === 'manutenzione').length;
        const countAnomalies = _mezzi.reduce((sum, v) => sum + (parseInt(v.open_anomalies) || 0), 0);

        container.innerHTML = `
            <style>
                .vehicles-dashboard {
                    padding: 0 32px 32px 32px;
                    animation: fade-in 0.3s ease-out;
                }
                .dash-top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
                
                .dash-stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 32px; }
                .dash-stat-card {
                    background: var(--surface);
                    border: 1px solid var(--border-subtle); 
                    border-radius: var(--radius-lg);
                    padding: 24px; position: relative; overflow: hidden;
                    transition: transform 0.3s, box-shadow 0.3s;
                }
                .dash-stat-card:hover { transform: translateY(-2px); border-color: var(--border-hover); }
                .dash-stat-card::before {
                    content: ''; position: absolute; top:0; left:0; width: 100%; height: 3px;
                    background: linear-gradient(90deg, var(--accent-primary), transparent);
                }
                .dash-stat-card.orange::before { background: linear-gradient(90deg, #FF9800, transparent); }
                .dash-stat-card.pink::before { background: linear-gradient(90deg, #FF00FF, transparent); }
                
                .dash-stat-title { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; display: flex; justify-content: space-between; align-items: center; }
                .dash-stat-icon { font-size: 1.5rem; color: var(--text-muted); padding: 8px; background: rgba(255,255,255,0.02); border-radius: 8px; }
                .dash-stat-value { font-size: 2.5rem; font-weight: 700; margin-top: 16px; line-height: 1; display: flex; align-items: baseline; gap: 8px; color: var(--text-primary); }

                .vehicle-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 24px; }
                .vehicle-card {
                    background: var(--surface);
                    border: 1px solid var(--border-subtle); 
                    border-radius: var(--radius-lg);
                    padding: 24px; cursor: pointer; transition: all 0.3s;
                    position: relative; overflow: hidden;
                    display: flex; flex-direction: column; gap: 16px;
                }
                .vehicle-card:hover { transform: translateY(-4px); border-color: var(--accent-primary); box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
                .vehicle-card::before { content: ''; position: absolute; left: 0; top: 0; height: 100%; width: 4px; background: var(--accent-primary); opacity: 0; transition: opacity 0.3s; }
                .vehicle-card:hover::before { opacity: 1; }
                
                .vehicle-header { display: flex; justify-content: space-between; align-items: flex-start; }
                .vehicle-name { font-size: 1.2rem; font-weight: 700; color: var(--text-primary); margin-bottom: 4px; }
                .vehicle-plate { display: inline-block; background: rgba(255,255,255,0.05); padding: 4px 8px; border-radius: 6px; font-family: monospace; font-size: 0.85rem; font-weight: 600; border: 1px solid var(--border-subtle); }
                
                .vehicle-status { font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; padding: 4px 10px; border-radius: 20px; }
                .status-active { background: rgba(0,230,118,0.1); color: #00E676; border: 1px solid rgba(0,230,118,0.2); }
                .status-maintenance { background: rgba(255,152,0,0.1); color: #FF9800; border: 1px solid rgba(255,152,0,0.2); }
                .status-out { background: rgba(255,0,0,0.1); color: #FF5252; border: 1px solid rgba(255,0,0,0.2); }

                .vehicle-metrics { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: auto; padding-top: 16px; border-top: 1px solid var(--border-subtle); }
                .v-metric { font-size: 0.85rem; color: var(--text-muted); display: flex; align-items: center; gap: 6px; }
                .v-metric i { color: var(--accent-primary); }
                .v-metric.alert i { color: #FF5252; }
                .v-metric.alert span { color: #FF5252; font-weight: 600; }
            </style>
        
            <div class="vehicles-dashboard">
                <div class="dash-top-bar">
                    <div>
                        <p class="dash-subtitle" style="color:var(--text-muted);">${_mezzi.length} veicoli nel parco mezzi</p>
                    </div>
                    <div style="display:flex; gap:12px;">
                        ${isManager ? '<button class="btn btn-primary" id="btn-new-mezzo"><i class="ph ph-plus"></i> Nuovo Mezzo</button>' : ''}
                    </div>
                </div>

                <div class="dash-stat-grid">
                    <div class="dash-stat-card">
                        <div class="dash-stat-title">Flotta Attiva <div class="dash-stat-icon"><i class="ph ph-check-circle"></i></div></div>
                        <div class="dash-stat-value">${countActive} <span style="font-size:1rem; color:var(--text-muted); font-weight:500;">/ ${_mezzi.length}</span></div>
                    </div>
                    <div class="dash-stat-card orange">
                        <div class="dash-stat-title">In Manutenzione <div class="dash-stat-icon"><i class="ph ph-wrench"></i></div></div>
                        <div class="dash-stat-value" style="color:#FF9800;">${countMaint}</div>
                    </div>
                    <div class="dash-stat-card pink">
                        <div class="dash-stat-title">Anomalie Aperte <div class="dash-stat-icon"><i class="ph ph-warning"></i></div></div>
                        <div class="dash-stat-value" style="color:#FF00FF;">${countAnomalies}</div>
                    </div>
                </div>

                ${_mezzi.length === 0 ? `
                    <div class="empty-state">
                        <i class="ph ph-car-profile"></i>
                        <h3>Nessun mezzo trovato</h3>
                        <p>Aggiungi il primo veicolo al parco mezzi.</p>
                    </div>
                ` : `
                    <div class="vehicle-grid">
                        ${_mezzi.map(v => {
                            let statusText = 'Attivo';
                            let statusClass = 'status-active';
                            if (v.stato === 'manutenzione') { statusText = 'In Manutenzione'; statusClass = 'status-maintenance'; }
                            if (v.stato === 'fuori_servizio') { statusText = 'Fuori Servizio'; statusClass = 'status-out'; }
                            
                            return `
                                <div class="vehicle-card" data-id="${v.id}">
                                    <div class="vehicle-header">
                                        <div>
                                            <div class="vehicle-name">${UI.esc(v.nome)}</div>
                                            <div class="vehicle-plate">${UI.esc(v.targa)}</div>
                                        </div>
                                        <div class="vehicle-status ${statusClass}">${statusText}</div>
                                    </div>
                                    
                                    <div class="vehicle-metrics">
                                        <div class="v-metric" title="Posti a sedere">
                                            <i class="ph ph-users"></i>
                                            <span>${v.capacita || 9} Posti</span>
                                        </div>
                                        <div class="v-metric">
                                            <i class="ph ph-calendar-check"></i>
                                            <span>Bollo: ${v.scadenza_bollo ? UI.formatDate(v.scadenza_bollo) : 'N/D'}</span>
                                        </div>
                                        <div class="v-metric ${v.open_anomalies > 0 ? 'alert' : ''}" style="grid-column: span 2;">
                                            <i class="ph ph-warning-circle"></i>
                                            <span>${v.open_anomalies > 0 ? `${v.open_anomalies} Anomalie Aperte` : 'Nessuna anomalia'}</span>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `}
            </div>
        `;

        const btnNew = document.getElementById('btn-new-mezzo');
        if (btnNew) btnNew.addEventListener('click', () => editMezzo());

        container.querySelectorAll('.vehicle-card').forEach(card => {
            card.addEventListener('click', () => viewMezzo(card.dataset.id));
        });
    }

    function editMezzo(mezzo = null) {
        const bodyHtml = `
            <div class="form-grid">
                <div class="form-group">
                    <label>Nome / Modello *</label>
                    <input type="text" class="form-control" id="m-nome" value="${mezzo ? UI.esc(mezzo.nome) : ''}" placeholder="Es. Pulmino Ducato">
                </div>
                <div class="form-group">
                    <label>Targa *</label>
                    <input type="text" class="form-control" id="m-targa" value="${mezzo ? UI.esc(mezzo.targa) : ''}" style="text-transform:uppercase;">
                </div>
                <div class="form-group">
                    <label>Capacità (Posti)</label>
                    <input type="number" class="form-control" id="m-cap" value="${mezzo ? mezzo.capacita : 9}" min="1">
                </div>
                <div class="form-group">
                    <label>Stato</label>
                    <select class="form-control" id="m-stato">
                        <option value="attivo" ${mezzo?.stato === 'attivo' ? 'selected' : ''}>Attivo</option>
                        <option value="manutenzione" ${mezzo?.stato === 'manutenzione' ? 'selected' : ''}>In Manutenzione</option>
                        <option value="fuori_servizio" ${mezzo?.stato === 'fuori_servizio' ? 'selected' : ''}>Fuori Servizio</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Scadenza Assicurazione</label>
                    <input type="date" class="form-control" id="m-ass" value="${mezzo?.scadenza_assicurazione || ''}">
                </div>
                <div class="form-group">
                    <label>Scadenza Bollo</label>
                    <input type="date" class="form-control" id="m-bollo" value="${mezzo?.scadenza_bollo || ''}">
                </div>
                <div class="form-group full-width">
                    <label>Note</label>
                    <textarea class="form-control" id="m-note">${mezzo ? UI.esc(mezzo.note) : ''}</textarea>
                </div>
            </div>
            ${mezzo ? `
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid var(--border-subtle); display:flex; justify-content:flex-start;">
                    <button type="button" class="btn btn-danger" onclick="ModMezzi.deleteMezzo(${mezzo.id})"><i class="ph ph-trash"></i> Elimina Mezzo</button>
                </div>
            ` : ''}
        `;

        UI.openModal(mezzo ? 'Modifica Mezzo' : 'Nuovo Mezzo', bodyHtml, async () => {
            const nome = document.getElementById('m-nome').value.trim();
            const targa = document.getElementById('m-targa').value.trim().toUpperCase();

            if (!nome || !targa) {
                UI.toast('Nome e targa sono obbligatori', 'error');
                throw new Error('Dati incompleti');
            }

            const payload = {
                id: mezzo?.id,
                nome, targa,
                capacita: document.getElementById('m-cap').value,
                stato: document.getElementById('m-stato').value,
                scadenza_assicurazione: document.getElementById('m-ass').value || null,
                scadenza_bollo: document.getElementById('m-bollo').value || null,
                note: document.getElementById('m-note').value || null
            };

            await Store.api(mezzo ? 'updateVehicle' : 'createVehicle', 'mezzi', payload);
            UI.closeModal();
            UI.toast(mezzo ? 'Mezzo aggiornato' : 'Mezzo aggiunto');
            if (mezzo) {
                // Ricarica la vista di dettaglio
                viewMezzo(mezzo.id);
            } else {
                init();
            }
        });
    }

    async function deleteMezzo(id) {
        if (!confirm('Sei sicuro di eliminare questo mezzo? Tutte le manutenzioni e le anomalie collegate andranno perse.')) return;
        try {
            await Store.api('deleteVehicle', 'mezzi', { id });
            UI.closeModal();
            UI.toast('Mezzo eliminato');
            init();
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    async function viewMezzo(id) {
        const container = document.getElementById('trasferte-mezzi');
        container.innerHTML = '<div style="padding:40px; text-align:center; color:var(--text-muted);"><i class="ph ph-spinner ph-spin" style="font-size:2rem;"></i><br>Caricamento dettagli...</div>';
        
        try {
            _currentVehicle = await Store.api('getVehicleById', 'mezzi', { id });
            renderVehicleDetail();
        } catch (err) {
            UI.toast('Errore caricamento dettagli', 'error');
            init(); // Torna alla dashboard
        }
    }

    let _activeTab = 'anomalies';

    function renderVehicleDetail() {
        const v = _currentVehicle;
        if (!v) return;

        const container = document.getElementById('trasferte-mezzi');
        
        let statusText = 'Attivo';
        let statusColor = '#00E676';
        if (v.stato === 'manutenzione') { statusText = 'In Manutenzione'; statusColor = '#FF9800'; }
        if (v.stato === 'fuori_servizio') { statusText = 'Fuori Servizio'; statusColor = '#FF5252'; }

        const renderTabContent = () => {
            if (_activeTab === 'info') return getInfoHtml();
            if (_activeTab === 'maintenance') return getMaintHtml();
            if (_activeTab === 'anomalies') return getAnomHtml();
        };

        container.innerHTML = `
            <style>
                .detail-header {
                    padding: 32px; background: rgba(0,0,0,0.2); border-bottom: 1px solid var(--border-subtle);
                    display: flex; justify-content: space-between; align-items: flex-end; position: relative; overflow: hidden;
                }
                .v-plate-badge { display: inline-block; background: var(--text-primary); color: var(--surface); padding: 4px 12px; border-radius: 4px; font-family: monospace; font-size: 1rem; font-weight: bold; margin-bottom: 12px; }
                .v-title { font-size: 2rem; font-weight: 800; color: var(--text-primary); margin: 0 0 8px 0; line-height: 1; }
                .v-subtitle { font-size: 0.85rem; color: ${statusColor}; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 6px; }
                
                .v-tabs { display: flex; gap: 32px; padding: 0 32px; border-bottom: 1px solid var(--border-subtle); margin-bottom: 24px; }
                .v-tab { padding: 16px 0; color: var(--text-muted); cursor: pointer; font-weight: 600; border-bottom: 2px solid transparent; transition: all 0.3s; display: flex; align-items: center; gap: 8px; }
                .v-tab:hover { color: var(--text-primary); }
                .v-tab.active { color: var(--accent-primary); border-bottom-color: var(--accent-primary); }
                
                .v-content { padding: 0 32px 32px 32px; }
                
                .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }
                .info-card { background: var(--surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 24px; }
                .info-lbl { font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
                .info-val { font-size: 1rem; font-weight: 600; color: var(--text-primary); margin-bottom: 16px; }
                
                .anomaly-card { background: var(--surface); border: 1px solid var(--border-subtle); border-radius: var(--radius-lg); padding: 20px; display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px; }
            </style>
            
            <div class="detail-header">
                <div>
                    <div style="margin-bottom: 16px;">
                        <button class="btn btn-ghost" id="btn-back-mezzi" style="padding:0;"><i class="ph ph-arrow-left"></i> Torna alla Dashboard Mezzi</button>
                    </div>
                    <div class="v-plate-badge">${UI.esc(v.targa)}</div>
                    <h1 class="v-title">${UI.esc(v.nome)}</h1>
                    <div class="v-subtitle"><span style="width:8px;height:8px;border-radius:50%;background:${statusColor};display:inline-block;"></span> ${statusText}</div>
                </div>
                <div>
                    <button class="btn btn-secondary" id="btn-edit-veh"><i class="ph ph-pencil-simple"></i> Modifica Mezzo</button>
                </div>
            </div>
            
            <div class="v-tabs">
                <div class="v-tab ${_activeTab === 'anomalies' ? 'active' : ''}" data-tab="anomalies"><i class="ph ph-warning"></i> Anomalie (${v.anomalies?.filter(a => a.stato !== 'resolved').length || 0})</div>
                <div class="v-tab ${_activeTab === 'maintenance' ? 'active' : ''}" data-tab="maintenance"><i class="ph ph-wrench"></i> Manutenzione (${v.maintenance?.length || 0})</div>
                <div class="v-tab ${_activeTab === 'info' ? 'active' : ''}" data-tab="info"><i class="ph ph-info"></i> Info e Scadenze</div>
            </div>
            
            <div class="v-content" id="v-tab-content">
                ${renderTabContent()}
            </div>
        `;

        document.getElementById('btn-back-mezzi').addEventListener('click', init);
        document.getElementById('btn-edit-veh').addEventListener('click', () => editMezzo(v));
        
        container.querySelectorAll('.v-tab').forEach(t => {
            t.addEventListener('click', () => {
                _activeTab = t.dataset.tab;
                renderVehicleDetail(); // Rerender full view per aggiornare lo stato del tab e il content
            });
        });

        // Event listener per contenuto tab
        bindTabEvents();
    }

    const isExpired = d => d && new Date(d) < new Date();

    function getInfoHtml() {
        const v = _currentVehicle;
        return `
            <div class="info-grid">
                <div class="info-card">
                    <h3 style="margin-top:0; margin-bottom:24px; color:var(--accent-primary);">Dettagli Tecnici</h3>
                    <div class="info-lbl">Capacità</div>
                    <div class="info-val">${v.capacita} Posti</div>
                    <div class="info-lbl">Note Aggiuntive</div>
                    <div class="info-val" style="color:var(--text-muted); font-weight:normal;">${v.note ? UI.esc(v.note).replace(/\\n/g, '<br>') : '—'}</div>
                </div>
                <div class="info-card">
                    <h3 style="margin-top:0; margin-bottom:24px; color:#FF00FF;">Scadenze</h3>
                    <div class="info-lbl">Assicurazione</div>
                    <div class="info-val" style="${isExpired(v.scadenza_assicurazione) ? 'color:#FF5252' : ''}">${v.scadenza_assicurazione ? UI.formatDate(v.scadenza_assicurazione) : 'N/D'}</div>
                    <div class="info-lbl">Bollo</div>
                    <div class="info-val" style="${isExpired(v.scadenza_bollo) ? 'color:#FF5252' : ''}">${v.scadenza_bollo ? UI.formatDate(v.scadenza_bollo) : 'N/D'}</div>
                </div>
            </div>
        `;
    }

    function getMaintHtml() {
        const v = _currentVehicle;
        const maintTypes = { tagliando: 'Tagliando', gomme_estive: 'Gomme Estive', gomme_invernali: 'Gomme Invernali', riparazione: 'Riparazione', revisione: 'Revisione', altro: 'Altro' };
        
        let rows = v.maintenance?.map(m => `
            <tr>
                <td><strong>${UI.formatDate(m.data_manutenzione)}</strong></td>
                <td>${maintTypes[m.tipo] || m.tipo}</td>
                <td>${m.chilometraggio ? m.chilometraggio + ' km' : '—'}</td>
                <td>${UI.esc(m.descrizione || '—')}</td>
                <td>${parseFloat(m.costo) > 0 ? UI.formatCurrency(m.costo) : '—'}</td>
                <td>
                    ${m.prossima_scadenza_data ? UI.formatDate(m.prossima_scadenza_data) : ''}
                    ${m.prossima_scadenza_data && m.prossima_scadenza_km ? '<br>' : ''}
                    ${m.prossima_scadenza_km ? m.prossima_scadenza_km + ' km' : ''}
                </td>
                <td style="text-align:right; white-space:nowrap;">
                    <button class="btn btn-ghost" style="padding:4px;" onclick="ModMezzi.editMaintenance(${m.id})" title="Modifica"><i class="ph ph-pencil-simple"></i></button>
                    <button class="btn btn-ghost" style="padding:4px; color:#FF5252;" onclick="ModMezzi.deleteMaintenance(${m.id})" title="Elimina"><i class="ph ph-trash"></i></button>
                </td>
            </tr>
        `).join('') || `<tr><td colspan="7"><div class="empty-state">Nessuna manutenzione registrata</div></td></tr>`;

        return `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px;">
                <h2>Storico Manutenzioni</h2>
                <button class="btn btn-primary" id="btn-add-maint"><i class="ph ph-plus"></i> Registra Manutenzione</button>
            </div>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr><th>Data</th><th>Tipo</th><th>Km</th><th>Descrizione</th><th>Costo</th><th>Prossimo Controllo</th><th></th></tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    }

    function getAnomHtml() {
        const v = _currentVehicle;
        const severityColors = { critical: '#FF0000', high: '#FF5252', medium: '#FF9800', low: '#00E676' };
        
        const formatStatusBadge = (status) => {
            if (status === 'open') return '<span class="badge badge-red">Aperto</span>';
            if (status === 'in_progress') return '<span class="badge badge-yellow">In Lavoraz.</span>';
            return '<span class="badge badge-green">Risolto</span>';
        };

        const list = v.anomalies?.map(a => `
            <div class="anomaly-card" style="border-left: 4px solid ${severityColors[a.gravita]};">
                <div>
                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:8px;">
                        ${formatStatusBadge(a.stato)}
                        <span style="font-size:0.75rem; color:var(--text-muted);"><i class="ph ph-clock"></i> ${UI.formatDate(a.data_segnalazione.split(' ')[0])}</span>
                        <span style="font-size:0.75rem; color:var(--text-muted);"><i class="ph ph-user"></i> ${UI.esc(a.reporter_name || 'Utente')}</span>
                    </div>
                    <div style="font-size:1rem; font-weight:600; margin-bottom:8px;">${UI.esc(a.descrizione)}</div>
                    ${a.note_risoluzione ? `<div style="font-size:0.85rem; color:var(--text-muted); margin-top:12px; padding:12px; background:var(--bg-body); border-radius:8px;"><strong>Risoluzione:</strong> ${UI.esc(a.note_risoluzione)}</div>` : ''}
                </div>
                <div>
                    ${a.stato !== 'resolved' ? `
                        <select class="form-control anomaly-status-update" data-id="${a.id}" style="width:140px; padding:6px; margin-bottom:8px;">
                            <option value="open" ${a.stato === 'open' ? 'selected' : ''}>Aperto</option>
                            <option value="in_progress" ${a.stato === 'in_progress' ? 'selected' : ''}>In Lavorazione</option>
                            <option value="resolved">Risolto...</option>
                        </select>
                    ` : `<div style="font-size:0.75rem; color:var(--text-muted); text-align:right; margin-bottom:8px;">Risolto il<br>${UI.formatDate(a.data_risoluzione?.split(' ')[0])}</div>`}
                    <div style="display:flex; gap:4px; justify-content: flex-end;">
                        <button class="btn btn-ghost" style="padding:4px;" onclick="ModMezzi.editAnomaly(${a.id})" title="Modifica"><i class="ph ph-pencil-simple"></i></button>
                        <button class="btn btn-ghost" style="padding:4px; color:#FF5252;" onclick="ModMezzi.deleteAnomaly(${a.id})" title="Elimina"><i class="ph ph-trash"></i></button>
                    </div>
                </div>
            </div>
        `).join('') || `<div class="empty-state">Tutto funziona regolarmente, nessuna anomalia.</div>`;

        return `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px;">
                <h2>Segnalazioni Guasti</h2>
                <button class="btn btn-secondary" id="btn-add-anom"><i class="ph ph-warning"></i> Segnala Guasto</button>
            </div>
            <div>${list}</div>
        `;
    }

    function bindTabEvents() {
        const btnMaint = document.getElementById('btn-add-maint');
        if (btnMaint) btnMaint.addEventListener('click', () => openMaintenanceForm());

        const btnAnom = document.getElementById('btn-add-anom');
        if (btnAnom) btnAnom.addEventListener('click', () => openAnomalyForm());

        document.querySelectorAll('.anomaly-status-update').forEach(sel => {
            sel.addEventListener('change', async (e) => {
                const newStatus = e.target.value;
                const id = e.target.dataset.id;
                
                if (newStatus === 'resolved') {
                    // Prompt per note
                    const notes = prompt("Inserisci note di risoluzione (opzionale):");
                    if (notes !== null) {
                        await updateAnomStatus(id, newStatus, notes);
                    } else {
                        // Revert
                        viewMezzo(_currentVehicle.id); 
                    }
                } else {
                    await updateAnomStatus(id, newStatus);
                }
            });
        });
    }

    async function updateAnomStatus(id, status, notes = null) {
        try {
            await Store.api('updateAnomalyStatus', 'mezzi', { id, status, resolution_notes: notes });
            UI.toast('Stato aggiornato', 'success');
            viewMezzo(_currentVehicle.id);
        } catch (err) {
            UI.toast(err.message, 'error');
            viewMezzo(_currentVehicle.id);
        }
    }

    function openMaintenanceForm(id = null) {
        const m = id ? _currentVehicle.maintenance.find(x => x.id == id) : null;
        const bodyHtml = `
            <div class="form-grid">
                <div class="form-group">
                    <label>Data *</label>
                    <input type="date" class="form-control" id="m-data" value="${m ? m.data_manutenzione : new Date().toISOString().split('T')[0]}">
                </div>
                <div class="form-group">
                    <label>Tipo Intervento *</label>
                    <select class="form-control" id="m-tipo">
                        <option value="tagliando" ${m?.tipo === 'tagliando' ? 'selected' : ''}>Tagliando / Service</option>
                        <option value="gomme_estive" ${m?.tipo === 'gomme_estive' ? 'selected' : ''}>Cambio Gomme (Estive)</option>
                        <option value="gomme_invernali" ${m?.tipo === 'gomme_invernali' ? 'selected' : ''}>Cambio Gomme (Invernali)</option>
                        <option value="riparazione" ${m?.tipo === 'riparazione' ? 'selected' : ''}>Riparazione Straordinaria</option>
                        <option value="revisione" ${m?.tipo === 'revisione' ? 'selected' : ''}>Revisione Ministeriale</option>
                        <option value="altro" ${m?.tipo === 'altro' ? 'selected' : ''}>Altro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Chilometraggio (Km)</label>
                    <input type="number" class="form-control" id="m-km" placeholder="Es. 45000" value="${m?.chilometraggio || ''}">
                </div>
                <div class="form-group">
                    <label>Costo (€)</label>
                    <input type="number" class="form-control" id="m-costo" step="0.01" value="${m ? m.costo : '0.00'}">
                </div>
                <div class="form-group full-width">
                    <label>Descrizione / Lavori Eseguiti</label>
                    <textarea class="form-control" id="m-desc">${m ? UI.esc(m.descrizione || '') : ''}</textarea>
                </div>
                <div class="form-group">
                    <label>Prossima Scadenza (Data)</label>
                    <input type="date" class="form-control" id="m-next-data" value="${m?.prossima_scadenza_data || ''}">
                </div>
                <div class="form-group">
                    <label>Prossima Scadenza (Km)</label>
                    <input type="number" class="form-control" id="m-next-km" value="${m?.prossima_scadenza_km || ''}">
                </div>
            </div>
        `;

        UI.openModal(m ? 'Modifica Manutenzione' : 'Registra Manutenzione', bodyHtml, async () => {
            const date = document.getElementById('m-data').value;
            const type = document.getElementById('m-tipo').value;
            if (!date || !type) {
                UI.toast('Data e tipo sono obbligatori', 'error');
                throw new Error('Incompleto');
            }

            await Store.api(m ? 'updateMaintenance' : 'addMaintenance', 'mezzi', {
                id: m?.id,
                vehicle_id: _currentVehicle.id,
                maintenance_date: date,
                type: type,
                mileage: document.getElementById('m-km').value || null,
                cost: document.getElementById('m-costo').value || 0,
                description: document.getElementById('m-desc').value || null,
                next_maintenance_date: document.getElementById('m-next-data').value || null,
                next_maintenance_mileage: document.getElementById('m-next-km').value || null
            });

            UI.closeModal();
            UI.toast(m ? 'Manutenzione aggiornata' : 'Manutenzione salvata');
            viewMezzo(_currentVehicle.id);
        });
    }

    async function deleteMaintenance(id) {
        if (!confirm('Eliminare questa manutenzione?')) return;
        try {
            await Store.api('deleteMaintenance', 'mezzi', { id });
            UI.toast('Manutenzione eliminata');
            viewMezzo(_currentVehicle.id);
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    function openAnomalyForm(id = null) {
        const a = id ? _currentVehicle.anomalies.find(x => x.id == id) : null;
        const bodyHtml = `
            <div class="form-group full-width" style="margin-bottom:16px;">
                <label>Descrizione Problema / Danno *</label>
                <textarea class="form-control" id="a-desc" rows="4" placeholder="Descrivi il guasto, l'incidente o il problema...">${a ? UI.esc(a.descrizione) : ''}</textarea>
            </div>
            <div class="form-group">
                <label>Priorità / Gravità</label>
                <select class="form-control" id="a-sev">
                    <option value="low" ${a?.gravita === 'low' ? 'selected' : ''}>Bassa (Non pregiudica l'utilizzo)</option>
                    <option value="medium" ${!a || a.gravita === 'medium' ? 'selected' : ''}>Media (Da controllare presto)</option>
                    <option value="high" ${a?.gravita === 'high' ? 'selected' : ''}>Alta (Intervento urgente)</option>
                    <option value="critical" ${a?.gravita === 'critical' ? 'selected' : ''}>Critica (MEZZO FERMO)</option>
                </select>
            </div>
        `;

        UI.openModal(a ? 'Modifica Guasto' : 'Segnala Guasto', bodyHtml, async () => {
            const desc = document.getElementById('a-desc').value.trim();
            if (!desc) {
                UI.toast('La descrizione è obbligatoria', 'error');
                throw new Error('Incompleto');
            }

            await Store.api(a ? 'updateAnomaly' : 'addAnomaly', 'mezzi', {
                id: a?.id,
                vehicle_id: _currentVehicle.id,
                description: desc,
                severity: document.getElementById('a-sev').value
            });

            UI.closeModal();
            UI.toast(a ? 'Anomalia aggiornata' : 'Anomalia segnalata');
            viewMezzo(_currentVehicle.id);
        });
    }

    async function deleteAnomaly(id) {
        if (!confirm('Eliminare questa anomalia?')) return;
        try {
            await Store.api('deleteAnomaly', 'mezzi', { id });
            UI.toast('Anomalia eliminata');
            viewMezzo(_currentVehicle.id);
        } catch (err) {
            UI.toast(err.message, 'error');
        }
    }

    return { init, deleteMezzo, editMaintenance: openMaintenanceForm, deleteMaintenance, editAnomaly: openAnomalyForm, deleteAnomaly };
})();

window.ModMezzi = ModMezzi;
