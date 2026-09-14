#!/usr/bin/env bash
# Run as root from a reviewed deploy/ directory on a dedicated Ubuntu 24.04 VPS.
set -Eeuo pipefail
export DEBIAN_FRONTEND=noninteractive
[[ $EUID == 0 ]] || exit 1
# shellcheck source=/dev/null
source /etc/os-release
[[ $ID == ubuntu && $VERSION_ID == 24.04 ]] || { echo 'Requires Ubuntu 24.04.' >&2; exit 1; }
here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
apt-get update
apt-get install -y ca-certificates curl gnupg nginx php8.3-fpm php8.3-cli php8.3-pgsql php8.3-xml php8.3-mbstring php8.3-intl php8.3-curl php8.3-zip certbot python3-certbot-nginx sudo
install -d /usr/share/postgresql-common/pgdg
curl --fail --silent --show-error https://www.postgresql.org/media/keys/ACCC4CF8.asc -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
cat > /etc/apt/sources.list.d/pgdg.sources <<'EOF'
Types: deb
URIs: https://apt.postgresql.org/pub/repos/apt
Suites: noble-pgdg
Architectures: amd64
Components: main
Signed-By: /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
EOF
apt-get update
apt-get install -y postgresql-17
id muse >/dev/null 2>&1 || useradd --create-home --shell /bin/bash muse
install -d -m 755 /srv/muse /srv/muse/releases /srv/muse/incoming /srv/muse/shared
chown muse:muse /srv/muse/releases /srv/muse/incoming
install -d -o muse -g muse -m 700 /srv/muse/shared/uploads /srv/muse/shared/sessions /srv/muse/shared/log
install -d -m 700 /var/backups/muse
if [[ -f /opt/autobackup/config.yml ]]; then
    python3 - <<'PY'
from pathlib import Path
import shutil
path = Path('/opt/autobackup/config.yml')
text = path.read_text()
if '/var/backups/muse' not in text:
    if 'backup_paths:\n' not in text:
        raise SystemExit('Unknown FirstVDS backup configuration; review it manually.')
    original = Path('/root/autobackup-config.before-muse.yml')
    if not original.exists():
        shutil.copy2(path, original)
        original.chmod(0o600)
    path.write_text(text.replace('backup_paths:\n', 'backup_paths:\n    - /var/backups/muse\n', 1))
print('FirstVDS backup includes /var/backups/muse')
PY
fi
install -d -m 755 /var/www/letsencrypt/.well-known/acme-challenge
if [[ ! -f /srv/muse/shared/.env.local ]]; then
    python3 - <<'PY'
import os, pwd, secrets, subprocess
from pathlib import Path
exists = subprocess.check_output(['runuser', '-u', 'postgres', '--', 'psql', '-Atc', "SELECT 1 FROM pg_database WHERE datname='muse'"]).strip()
if exists:
    raise SystemExit('Database muse exists without its environment file; refusing to replace credentials.')
password = secrets.token_hex(32)
sql = f"CREATE ROLE muse LOGIN PASSWORD '{password}';\nCREATE DATABASE muse OWNER muse;\n"
subprocess.run(['runuser', '-u', 'postgres', '--', 'psql', '-v', 'ON_ERROR_STOP=1'], input=sql, text=True, check=True, stdout=subprocess.DEVNULL)
path = Path('/srv/muse/shared/.env.local')
fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
with os.fdopen(fd, 'w') as f:
    f.write('APP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET='+secrets.token_hex(32)+'\n')
    f.write('DATABASE_URL="postgresql://muse:'+password+'@127.0.0.1:5432/muse?serverVersion=17&charset=utf8"\n')
    f.write('UPLOAD_DIR=var/uploads\n')
user = pwd.getpwnam('muse')
os.chown(path, user.pw_uid, user.pw_gid)
PY
fi
install -d /etc/postgresql/17/main/conf.d
cat > /etc/postgresql/17/main/conf.d/muse.conf <<'EOF'
listen_addresses = '127.0.0.1'
max_connections = 20
shared_buffers = 128MB
effective_cache_size = 384MB
work_mem = 4MB
maintenance_work_mem = 64MB
EOF
systemctl restart postgresql@17-main
install -m 644 "$here/php-fpm.conf" /etc/php/8.3/fpm/pool.d/muse.conf
if [[ -f /etc/php/8.3/fpm/pool.d/www.conf ]]; then
    mv /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/pool.d/www.conf.disabled
fi
printf '%s\n' 'opcache.memory_consumption=64' 'expose_php=Off' > /etc/php/8.3/fpm/conf.d/99-muse.ini
if [[ ! -f /etc/nginx/sites-available/muse ]]; then
    install -m 644 "$here/nginx.conf" /etc/nginx/sites-available/muse
fi
ln -sfn /etc/nginx/sites-available/muse /etc/nginx/sites-enabled/muse
rm -f /etc/nginx/sites-enabled/default
install -o root -g root -m 755 "$here/muse-ops" /usr/local/sbin/muse-ops
printf '%s\n' 'muse ALL=(root) NOPASSWD: /usr/local/sbin/muse-ops' > /etc/sudoers.d/muse
chmod 440 /etc/sudoers.d/muse
visudo -cf /etc/sudoers.d/muse
for unit in muse-backup.service muse-backup.timer muse-cleanup.service muse-cleanup.timer; do
    install -m 644 "$here/$unit" "/etc/systemd/system/$unit"
done
cat > /etc/logrotate.d/muse <<'EOF'
/srv/muse/shared/log/*.log {
    daily
    rotate 7
    compress
    missingok
    notifempty
    copytruncate
    su muse muse
}
EOF
nginx -t
php-fpm8.3 -t
systemctl daemon-reload
systemctl enable --now nginx php8.3-fpm postgresql muse-backup.timer muse-cleanup.timer
systemctl reload nginx
systemctl restart php8.3-fpm
echo 'Bootstrap complete. Initial access is HTTP by IP; configure TLS after DNS is ready.'
