#!/bin/sh
# Instala o pool PHP-FPM próprio e o site nginx do PDF Tools.
#
#   sudo sh deploy/install.sh
#
# Faz backup do site nginx atual e o restaura se a nova configuração
# não passar no teste.

set -eu

DIR=$(cd "$(dirname "$0")" && pwd)

POOL=/etc/php/8.4/fpm/pool.d/pdf-tools.conf
SITE=/etc/nginx/sites-available/pdf-tools
SOCKET=/run/php/php8.4-fpm-pdf-tools.sock

if [ "$(id -u)" -ne 0 ]; then
    echo "Execute com sudo: sudo sh $0" >&2
    exit 1
fi

# 1. Pool PHP-FPM
install -m 644 "$DIR/php-fpm-pool.conf" "$POOL"

if ! /usr/sbin/php-fpm8.4 -t; then
    rm -f "$POOL"
    echo "Configuração do PHP-FPM inválida; pool removido." >&2
    exit 1
fi

systemctl reload php8.4-fpm

for _ in 1 2 3 4 5; do
    [ -S "$SOCKET" ] && break
    sleep 1
done

if [ ! -S "$SOCKET" ]; then
    echo "O socket $SOCKET não apareceu após o reload do PHP-FPM." >&2
    exit 1
fi

# 2. Site nginx
BACKUP="$SITE.bak-$(date +%Y-%m-%d-%H%M%S)"
cp -a "$SITE" "$BACKUP"
install -m 644 "$DIR/nginx-site.conf" "$SITE"

if ! nginx -t; then
    cp -a "$BACKUP" "$SITE"
    echo "Configuração do nginx inválida; site anterior restaurado." >&2
    exit 1
fi

systemctl reload nginx

echo
echo "Pronto. Pool: $POOL"
echo "Backup do site anterior: $BACKUP"
