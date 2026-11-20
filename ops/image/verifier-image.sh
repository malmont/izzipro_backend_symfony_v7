#!/usr/bin/env bash
# Contrôle d'une image de l'application, à lancer après CHAQUE construction et avant toute mise en service ou envoi dans
# un registre (lot PHP84, 20/11/2026). Échoue (code 1) si un secret se trouve dans l'image ou si PHP n'est pas celui
# attendu. Ne lit ni n'affiche le contenu d'aucun fichier : seulement leur présence et leur nom.
#
#   ops/image/verifier-image.sh <image> [version PHP attendue, ex. 8.4.28]
#
# L'image construite avant ce lot contenait une copie de .env et de la clé privée JWT (.dockerignore les laissait passer).
set -euo pipefail

IMAGE="${1:?usage : verifier-image.sh <image> [version PHP]}"
PHP_ATTENDU="${2:-}"

# Limite connue : seul le système de fichiers final et la configuration de l'image sont contrôlés, pas chaque couche
# (`docker history`) : un fichier copié puis effacé dans une couche suivante n'est pas vu. Aucun RUN rm ni ARG de secret
# dans le Dockerfile aujourd'hui ; ne jamais en ajouter.

# Configuration de l'image : aucune variable d'environnement au nom de secret (celles de l'image PHP officielle exceptées)
code=0
if docker image inspect "$IMAGE" --format '{{range .Config.Env}}{{println .}}{{end}}' | cut -d= -f1 \
    | grep -vE '^(PATH|PHPIZE_DEPS|PHP_[A-Z_]+|GPG_KEYS|HOME)$' | grep -iE 'pass|secret|token|key|dsn|database_url|_url$' >/dev/null; then
  echo "VARIABLE À NOM DE SECRET DANS LA CONFIGURATION DE L'IMAGE"; code=1
fi

# Conteneur provisoire sans réseau ni montage, en root (aucun dossier illisible sauté) : l'image seule, jamais un volume.
sortie=$(docker run --rm --network none --user 0 --entrypoint sh "$IMAGE" -c '
  echec=0
  # Familles de secrets (les mêmes que .dockerignore et construire-image.sh), où qu ils soient sous /var/www, /root et
  # /home ; vendor exclu (les paquets livrent des clés pour leurs essais). .env.local.php de composer dump-env --empty
  # est contrôlé à part plus bas.
  find /var/www /root /home -path /var/www/vendor -prune -o \( \
      -name ".env" -o -name ".env.*" -o -name ".envrc" -o -name "*.pem" -o -name "*.key" -o -name "*.p8" -o -name "*.p12" \
      -o -name "*.pfx" -o -name "*.jks" -o -name "id_rsa*" -o -name "id_ed25519*" -o -name "id_ecdsa*" -o -name "auth.json" \
      -o -name "*credentials*.json" -o -name "*service-account*.json" -o -path "*/config/secrets" -o -path "/var/www/jwt" \
      -o -path "/var/www/config/jwt/*" \) -print > /tmp/trouves
  while IFS= read -r f; do
    [ "$f" = /var/www/.env.local.php ] && continue
    echo "SECRET PRÉSENT : $f"; echec=1
  done < /tmp/trouves
  # .env.local.php de composer dump-env --empty : seulement APP_ENV, jamais une valeur de .env
  if [ -f /var/www/.env.local.php ]; then
    n=$(php -r "\$v = include \"/var/www/.env.local.php\"; echo is_array(\$v) ? count(array_diff(array_keys(\$v), [\"APP_ENV\"])) : 99;")
    [ "$n" = 0 ] || { echo ".env.local.php contient $n variable(s) en plus de APP_ENV"; echec=1; }
  fi
  # php.ini de l image : erreurs jamais affichées, journalisées, version de PHP cachée, limites d avant (CLI puis FPM)
  # (pas d apostrophe dans ce bloc : il est entre guillemets simples)
  for pair in "display_errors=Off" "display_startup_errors=Off" "log_errors=On" "expose_php=Off" "memory_limit=-1" "upload_max_filesize=200M" "post_max_size=200M"; do
    k=${pair%%=*}; v=${pair#*=}
    for sapi in php php-fpm; do
      got=$($sapi -i 2>/dev/null | grep -E "^$k => " | head -1 | sed -E "s/^$k => ([^ ]*).*/\1/")
      [ "$got" = "$v" ] || { echo "RÉGLAGE $sapi $k = ${got:-absent} (attendu $v)"; echec=1; }
    done
  done
  got=$(php-fpm -i 2>/dev/null | grep -E "^max_execution_time => " | sed -E "s/^max_execution_time => ([^ ]*).*/\1/")
  [ "$got" = 600 ] || { echo "RÉGLAGE php-fpm max_execution_time = ${got:-absent} (attendu 600)"; echec=1; }
  [ -f /usr/local/etc/php/php.ini ] || { echo "php.ini ABSENT"; echec=1; }
  echo "PHP $(php -r "echo PHP_VERSION;")"
  php -m | grep -qx redis || { echo "EXTENSION redis ABSENTE"; echec=1; }
  echo "redis $(php -r "echo phpversion(\"redis\");")"
  exit $echec
') || code=1

echo "$sortie"
if [ -n "$PHP_ATTENDU" ] && ! grep -qFx "PHP $PHP_ATTENDU" <<<"$sortie"; then
  echo "PHP attendu : $PHP_ATTENDU"; code=1
fi
if [ "$code" = 0 ]; then echo "IMAGE CONFORME : $IMAGE"; else echo "IMAGE REFUSÉE : $IMAGE"; fi
exit "$code"
