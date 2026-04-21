'use strict';

/**
 * ModAdmin — Gestione Utenti, Backup, Logs
 */
const ModAdmin = (() => {
    let abortCtrl = new AbortController();

    // ─── UTENTI ─────────────────────────────────────────────────────────────
    async function loadUsers() {
        abortCtrl.abort();
        abortCtrl = new AbortController();
        const container = document.getElementById('admin-utenti-content');
        
        container.innerHTML = `
            <div class="page-header">
                <h1 class="page-title"><i class="ph ph-users"></i> Gestione Utenti</h1>
                <div class="page-actions">
                    <button class="btn btn-primary" id="btn-add-user">
                        <i class="ph ph-plus"></i> Nuovo Utente
                    </button>
                </div>
            </div>
            <div class="table-container" id="utenti-table-container">
                <div style="padding: 40px; text-align: center; color: var(--text-muted);">Caricamento utenti...</div>
            </div>
        `;

        try {
            const res = await Store.api('listUsers', 'admin', {}, 'GET');
            const data = (Array.isArray(res) ? res : res.data) || [];
            const tableWrap = document.getElementById('utenti-table-container');

            if (data.length === 0) {
                tableWrap.innerHTML = `
                    <table class="data-table">
                        <tbody>
                            <tr><td class="empty-state"><i class="ph ph-users"></i><h3>Nessun utente trovato</h3></td></tr>
                        </tbody>
                    </table>`;
            } else {
                tableWrap.innerHTML = `
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th></th>
                                <th>Nome</th>
                                <th>Email</th>
                                <th>Ruolo</th>
                                <th>Stato</th>
                                <th>Ultimo accesso</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.map(u => {
                                const status = u.status || (u.is_active == 1 ? 'Attivo' : 'Disattivato');
                                const isActive = status === 'Attivo';
                                const avatar = `<div style="width:32px;height:32px;border-radius:50%;background:rgba(99,102,241,0.2);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:600;color:#6366f1;">${UI.esc((u.full_name||u.name||'U').charAt(0).toUpperCase())}</div>`;
                                const roleColors = { 'admin': 'badge-blue', 'operatore': 'badge-gray' };
                                const roleColor = roleColors[u.role] || 'badge-gray';
                                
                                return `
                                <tr>
                                    <td style="width:40px">${avatar}</td>
                                    <td><strong>${UI.esc(u.full_name || u.name)}</strong></td>
                                    <td style="color:var(--text-muted);">${UI.esc(u.email)}</td>
                                    <td><span class="badge ${roleColor}">${UI.esc(u.role)}</span></td>
                                    <td>${UI.statoBadge(status.toLowerCase())}</td>
                                    <td style="font-size:12px;">${UI.formatDate(u.last_login_at || u.last_login)}</td>
                                    <td>
                                        <div style="display:flex;gap:6px;">
                                            <button class="btn btn-ghost btn-icon user-reset-pwd" data-id="${u.id}" title="Reset password"><i class="ph ph-key"></i></button>
                                            <button class="btn btn-ghost btn-icon user-delete" data-id="${u.id}" style="color:var(--danger);" title="Elimina"><i class="ph ph-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>`;
                            }).join('')}
                        </tbody>
                    </table>
                `;

                // Events per righe
                tableWrap.querySelectorAll('.user-reset-pwd').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        if (!confirm("Generare una nuova password per questo utente?")) return;
                        try {
                            const result = await Store.api('resetPassword', 'admin', { id: btn.dataset.id }, 'POST');
                            if (result.tempPassword) {
                                alert("Password resettata. Nuova password temporanea: " + result.tempPassword);
                            } else {
                                UI.toast("Password resettata con successo");
                            }
                        } catch (err) { UI.toast(err.message, 'error'); }
                    });
                });
                tableWrap.querySelectorAll('.user-delete').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        if (!confirm("Eliminare definitivamente questo utente? L'operazione non è reversibile.")) return;
                        try {
                            await Store.api('deleteUser', 'admin', { id: btn.dataset.id }, 'POST');
                            UI.toast("Utente eliminato");
                            loadUsers();
                        } catch (err) { UI.toast(err.message, 'error'); }
                    });
                });
            }

            document.getElementById('btn-add-user').addEventListener('click', () => {
                UI.openModal('Nuovo Utente', `
                    <div class="form-group">
                        <label>Nome Completo</label>
                        <input type="text" id="nu-name" class="form-control" placeholder="Mario Rossi">
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" id="nu-email" class="form-control" placeholder="mario@mv-consulting.it">
                    </div>
                    <div class="form-group">
                        <label>Ruolo</label>
                        <select id="nu-role" class="form-control">
                            <option value="admin">Admin</option>
                            <option value="operatore">Operatore</option>
                        </select>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);margin-top:10px;">
                        Verrà generata una password temporanea.
                    </div>
                    <div id="nu-error" class="login-error hidden" style="margin-top:10px"></div>
                `, async () => {
                    const name = document.getElementById('nu-name').value.trim();
                    const email = document.getElementById('nu-email').value.trim();
                    const role = document.getElementById('nu-role').value;
                    const errEl = document.getElementById('nu-error');
                    
                    if (!name || !email) {
                        errEl.textContent = "Compila tutti i campi";
                        errEl.classList.remove('hidden');
                        return;
                    }
                    
                    document.getElementById('modal-save').disabled = true;
                    try {
                        const result = await Store.api('createUser', 'admin', {
                            full_name: name, email, role
                        }, 'POST');
                        
                        UI.closeModal();
                        loadUsers();
                        
                        if (result.tempPassword) {
                            alert("Utente creato!\n\nPassword temporanea: " + result.tempPassword);
                        } else {
                            UI.toast("Utente creato con successo");
                        }
                    } catch (e) {
                        errEl.textContent = e.message;
                        errEl.classList.remove('hidden');
                        document.getElementById('modal-save').disabled = false;
                    }
                });
            });

        } catch (e) {
            document.getElementById('utenti-table-container').innerHTML = `
                <div style="padding: 20px; color: var(--danger); text-align: center;">Errore caricamento utenti: ${UI.esc(e.message)}</div>
            `;
        }
    }

    // ─── BACKUP ─────────────────────────────────────────────────────────────
    async function loadBackups() {
        abortCtrl.abort();
        abortCtrl = new AbortController();
        const container = document.getElementById('admin-backup-content');
        
        container.innerHTML = `
            <div class="page-header">
                <h1 class="page-title"><i class="ph ph-database"></i> Backup Sistema</h1>
                <div class="page-actions">
                    <button class="btn btn-primary" id="btn-run-backup">
                        <i class="ph ph-plus"></i> Esegui Backup SQL
                    </button>
                </div>
            </div>
            
            <div class="kpi-grid" id="backup-stats" style="margin-bottom: 20px;"></div>
            
            <div class="table-container" id="backup-table-container">
                <div style="padding: 40px; text-align: center; color: var(--text-muted);">Caricamento backup...</div>
            </div>
        `;

        try {
            const res = await Store.api('listBackups', 'admin', {}, 'GET');
            const data = Array.isArray(res) ? res : (res.backups || res.data?.backups || []);
            const stats = res.db_stats || res.data?.db_stats || {};
            
            // Format KPI
            const formatBytes = (bytes) => {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + ['B', 'KB', 'MB', 'GB'][i];
            };

            if (stats.table_count) {
                document.getElementById('backup-stats').innerHTML = `
                    <div class="kpi-card">
                        <div class="kpi-title">Tabelle DB</div>
                        <div class="kpi-value">${stats.table_count}</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title">Record Totali</div>
                        <div class="kpi-value">${(stats.total_rows || 0).toLocaleString('it-IT')}</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title">Dim. Stimata</div>
                        <div class="kpi-value">${formatBytes(stats.total_bytes || 0)}</div>
                    </div>
                    <div class="kpi-card">
                        <div class="kpi-title">Backup Archiviati</div>
                        <div class="kpi-value" style="color:var(--accent-primary);">${data.length}</div>
                    </div>
                `;
            }

            const tableWrap = document.getElementById('backup-table-container');

            if (data.length === 0) {
                tableWrap.innerHTML = `
                    <table class="data-table">
                        <tbody>
                            <tr><td class="empty-state"><i class="ph ph-database"></i><h3>Nessun backup trovato</h3></td></tr>
                        </tbody>
                    </table>`;
            } else {
                tableWrap.innerHTML = `
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Nome File</th>
                                <th>Dimensione</th>
                                <th>Record</th>
                                <th>Stato</th>
                                <th style="text-align:right;">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${data.map(b => `
                                <tr>
                                    <td>${UI.formatDate(b.created_at)}</td>
                                    <td style="font-family:monospace;font-size:12px;">${UI.esc(b.filename)}</td>
                                    <td style="font-size:12px;">${formatBytes(b.filesize)}</td>
                                    <td>${(b.row_count || 0).toLocaleString('it-IT')}</td>
                                    <td><span class="badge ${b.status === 'ok' ? 'badge-green' : 'badge-red'}">${b.status === 'ok' ? 'OK' : 'Err'}</span></td>
                                    <td style="text-align:right;">
                                        <div style="display:flex;gap:6px;justify-content:flex-end;">
                                            <button class="btn btn-ghost btn-sm bkp-download" data-id="${b.id}" title="Scarica"><i class="ph ph-download-simple"></i> Scarica</button>
                                            <button class="btn btn-ghost btn-icon bkp-delete" data-id="${b.id}" style="color:var(--danger);" title="Elimina"><i class="ph ph-trash"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;

                tableWrap.querySelectorAll('.bkp-download').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        const token = localStorage.getItem('erp_token');
                        try {
                            btn.disabled = true;
                            btn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> Scarica...';
                            const response = await fetch(`api/router.php?module=admin&action=downloadBackup&id=${btn.dataset.id}`, {
                                headers: { 'Authorization': 'Bearer ' + token }
                            });
                            if (!response.ok) throw new Error('Download fallito');
                            const blob = await response.blob();
                            const url = URL.createObjectURL(blob);
                            const a = document.createElement('a');
                            a.href = url;
                            a.download = `backup_${btn.dataset.id}.sql`;
                            document.body.appendChild(a);
                            a.click();
                            a.remove();
                            URL.revokeObjectURL(url);
                        } catch (err) {
                            UI.toast('Errore download: ' + err.message, 'error');
                        } finally {
                            btn.disabled = false;
                            btn.innerHTML = '<i class="ph ph-download-simple"></i> Scarica';
                        }
                    });
                });

                tableWrap.querySelectorAll('.bkp-delete').forEach(btn => {
                    btn.addEventListener('click', async () => {
                        if (!confirm("Eliminare definitivamente questo backup? L'operazione non è reversibile.")) return;
                        try {
                            await Store.api('deleteBackup', 'admin', { id: btn.dataset.id }, 'POST');
                            UI.toast("Backup eliminato");
                            loadBackups();
                        } catch (err) { UI.toast(err.message, 'error'); }
                    });
                });
            }

            document.getElementById('btn-run-backup').addEventListener('click', async () => {
                const btn = document.getElementById('btn-run-backup');
                btn.disabled = true;
                btn.innerHTML = '<i class="ph ph-spinner ph-spin"></i> Generazione in corso...';
                
                try {
                    await Store.api('createBackup', 'admin', {}, 'POST');
                    UI.toast("Backup completato con successo");
                    loadBackups();
                } catch (e) {
                    UI.toast("Errore backup: " + e.message, 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="ph ph-plus"></i> Esegui Backup SQL';
                }
            });

        } catch (e) {
            document.getElementById('backup-table-container').innerHTML = `
                <div style="padding: 20px; color: var(--danger); text-align: center;">Errore caricamento backup: ${UI.esc(e.message)}</div>
            `;
        }
    }

    // ─── LOGS ───────────────────────────────────────────────────────────────
    let logsOffset = 0;
    
    async function loadLogs(append = false) {
        if (!append) logsOffset = 0;
        
        abortCtrl.abort();
        abortCtrl = new AbortController();
        
        const container = document.getElementById('admin-logs-content');
        if (!append) {
            container.innerHTML = `
                <div class="page-header">
                    <h1 class="page-title"><i class="ph ph-clipboard-text"></i> Log di Sistema</h1>
                    <div class="page-actions">
                        <button class="btn btn-ghost" id="btn-refresh-logs">
                            <i class="ph ph-arrows-clockwise"></i> Aggiorna
                        </button>
                    </div>
                </div>
                <div class="table-container" id="logs-table-container">
                    <div style="padding: 40px; text-align: center; color: var(--text-muted);">Caricamento log...</div>
                </div>
            `;
            
            document.getElementById('btn-refresh-logs').addEventListener('click', () => loadLogs(false));
        }

        try {
            const res = await Store.api('listLogs', 'admin', { limit: 100, offset: logsOffset });
            const data = Array.isArray(res) ? res : (res.logs || res.data?.logs || []);
            const tableWrap = document.getElementById('logs-table-container');

            if (data.length === 0 && !append) {
                tableWrap.innerHTML = `
                    <table class="data-table">
                        <tbody>
                            <tr><td class="empty-state"><i class="ph ph-clipboard-text"></i><h3>Nessun log trovato</h3></td></tr>
                        </tbody>
                    </table>`;
                return;
            }

            let tbodyHtml = data.map(log => {
                const actionColors = { 'INSERT': 'badge-green', 'UPDATE': 'badge-blue', 'DELETE': 'badge-red', 'LOGIN': 'badge-blue', 'ERROR': 'badge-red' };
                const color = actionColors[log.action] || 'badge-gray';
                const hasDetails = log.details || log.before_snapshot || log.after_snapshot;
                
                return `
                    <tr class="log-row" style="cursor: ${hasDetails ? 'pointer' : 'default'}">
                        <td style="font-size:12px;color:var(--text-muted);">${UI.formatDate(log.created_at)} ${new Date(log.created_at).toLocaleTimeString('it-IT')}</td>
                        <td><strong>${UI.esc(log.user_name || 'Sistema')}</strong></td>
                        <td><span class="badge ${color}">${UI.esc(log.action)}</span></td>
                        <td style="font-family:monospace;font-size:12px;">${UI.esc(log.table_name || '—')}</td>
                        <td style="font-family:monospace;font-size:12px;">${UI.esc(log.record_id || '—')}</td>
                        <td style="color:var(--text-muted);">${UI.esc(log.ip_address || '—')}</td>
                    </tr>
                    ${hasDetails ? `
                    <tr class="log-details hidden">
                        <td colspan="6" style="padding: 15px; background: rgba(0,0,0,0.2); border-left: 3px solid var(--accent-primary);">
                            <pre style="margin:0; font-size:11px; white-space:pre-wrap; color:var(--text-muted);">${UI.esc(log.details || log.after_snapshot || log.before_snapshot)}</pre>
                        </td>
                    </tr>` : ''}
                `;
            }).join('');

            if (append) {
                document.querySelector('#logs-table-container tbody').insertAdjacentHTML('beforeend', tbodyHtml);
                const moreBtn = document.getElementById('btn-load-more-logs');
                if (data.length < 100 && moreBtn) moreBtn.remove();
            } else {
                tableWrap.innerHTML = `
                    <table class="data-table" id="table-logs">
                        <thead>
                            <tr>
                                <th>Data/Ora</th>
                                <th>Utente</th>
                                <th>Azione</th>
                                <th>Tabella</th>
                                <th>Record ID</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>${tbodyHtml}</tbody>
                    </table>
                    ${data.length === 100 ? `
                        <div style="text-align:center; padding: 20px;">
                            <button class="btn btn-ghost" id="btn-load-more-logs">Carica precedenti...</button>
                        </div>
                    ` : ''}
                `;
            }

            // Expand events
            tableWrap.querySelectorAll('.log-row').forEach(row => {
                row.addEventListener('click', () => {
                    const next = row.nextElementSibling;
                    if (next && next.classList.contains('log-details')) {
                        next.classList.toggle('hidden');
                    }
                });
            });

            if (!append && data.length === 100) {
                document.getElementById('btn-load-more-logs').addEventListener('click', () => {
                    logsOffset += 100;
                    loadLogs(true);
                });
            }

        } catch (e) {
            if (!append) {
                document.getElementById('logs-table-container').innerHTML = `
                    <div style="padding: 20px; color: var(--danger); text-align: center;">Errore caricamento log: ${UI.esc(e.message)}</div>
                `;
            } else {
                UI.toast("Errore caricamento log aggiuntivi", "error");
            }
        }
    }

    return {
        loadUsers,
        loadBackups,
        loadLogs
    };
})();

window.ModAdmin = ModAdmin;
