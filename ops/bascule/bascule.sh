#!/usr/bin/env bash
# Mise en ligne par échange de cache : procédure dans docs/mise-en-ligne.md (à lire avant le premier usage).
#
#   ops/bascule/bascule.sh jeton                    jeton de contrôle (var/swap_token.txt, jamais affiché)
#   ops/bascule/bascule.sh build <commit> <n>       gardes, puis cache du commit construit dans var/swap_stage<n>
#   ops/bascule/bascule.sh photo <nom>              lectures du site de contrôle + pages publiques de tous les sites
#   ops/bascule/bascule.sh comparer <avant> <après> [motif à écarter]
#   ops/bascule/bascule.sh switch <commit> <n>      avance du code et échange du cache, contrôles d'après
#   ops/bascule/bascule.sh retour <commit d'avant> <n>   retour arrière : code et cache d'avant
#   ops/bascule/bascule.sh logs <depuis, ISO UTC>   erreurs du journal et codes du serveur web depuis cet instant
#   ops/bascule/bascule.sh workers                  le worker des courriels bat-il ?
#   ops/bascule/bascule.sh menage [--oui]           anciens caches de bascule : liste, puis suppression avec --oui (garde les 3 derniers)
#   ops/bascule/bascule.sh fin                      supprime le jeton de contrôle
#
# Réglages : var/bascule.conf (hors dépôt ; modèle : ops/bascule/bascule.conf.exemple). Aucun nom de site, aucune adresse ni aucun
# secret dans ce fichier-ci. L'outil ne pousse rien et n'applique aucune migration : voir la procédure.
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
# L'arbre en ligne est celui où se trouve l'outil ; BASCULE_TREE le désigne quand on essaie l'outil depuis un worktree
L=${BASCULE_TREE:-$(cd "$HERE/../.." && pwd)}
CONF=${BASCULE_CONF:-$L/var/bascule.conf}
[ -r "$CONF" ] || { echo "RÉGLAGES ABSENTS : copier ops/bascule/bascule.conf.exemple vers var/bascule.conf et le remplir"; exit 1; }
# shellcheck disable=SC1090
. "$CONF"
for v in BASE_URL FRONT_HOST CONTROL_HOST CONTROL_DB CONTROL_CODE CONTROL_USER_ID SITE_HOST_PATTERN BUILD_WORKTREE PHOTO_DIR; do
  [ -n "${!v:-}" ] || { echo "RÉGLAGE MANQUANT dans $CONF : $v"; exit 1; }
done
APP=${APP_CONTAINER:-symfony_app_v2}; DB=${DB_CONTAINER:-symfony_db_v2}; REDIS=${REDIS_CONTAINER:-redis_cache_v2}
NGINX=${NGINX_CONTAINER:-symfony_nginx_v2}; WORKER=${EMAIL_WORKER_CONTAINER:-symfony_messenger_worker_email_v2}
NETWORK=${DOCKER_NETWORK:-app_v2_symfony_v2}; IMAGE=${APP_IMAGE:-app_v2-app}; MASTER=${MASTER_DB:-app_v2_db}
READS=${READS_FILE:-$L/var/bascule.lectures}; KEEP=${KEEP_STAGES:-3}
TOKEN=$L/var/swap_token.txt
H=(-H "Host: $FRONT_HOST" -H "X-Tenant-Host: $CONTROL_HOST" -H 'Accept: application/json')
cd "$L" || exit 1
[ "$(git rev-parse --show-toplevel)" = "$L" ] && [ ! -f "$L/.git" ] || { echo "À LANCER DEPUIS L'ARBRE EN LIGNE, pas depuis un worktree"; exit 1; }
pause() { perl -e 'select(undef, undef, undef, $ARGV[0])' "$1"; }
number() { case "$1" in ''|*[!0-9]*) echo "NUMÉRO DE CACHE ATTENDU"; exit 1;; esac; }

# Une seule mise en ligne à la fois sur la machine : verrou tenu le temps de la commande (build, switch, retour, menage)
lock() { exec 9> "$L/var/bascule.lock"; flock -n 9 || { echo "UNE AUTRE BASCULE EST EN COURS (var/bascule.lock)"; exit 1; }; }

# L'arbre en ligne est-il propre et égal à origin/dev ? (une autre session a pu pousser ou mettre en ligne)
clean_tree() {
  [ -z "$(git status --short -uno)" ] || { echo "ARBRE EN LIGNE MODIFIÉ"; exit 1; }
  git fetch -q origin || { echo "FETCH IMPOSSIBLE"; exit 1; }
  [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/dev)" ] || { echo "ARBRE EN LIGNE ($(git rev-parse --short HEAD)) DIFFÉRENT D'ORIGIN/DEV ($(git rev-parse --short origin/dev)) : repartir d'origin/dev"; exit 1; }
}

# Worker des courriels vivant ? Conteneur démarré et dernier battement de moins de 30 s. Le battement est une clé de 24 h réécrite
# toutes les 5 s : son âge = 86400 - ttl. Lecture seule.
beat_age() {
  local k; k=$(docker exec "$REDIS" redis-cli -n 0 --scan --pattern '*worker_heartbeat.email' | head -1)
  echo $((86400 - $(docker exec "$REDIS" redis-cli -n 0 ttl "$k" 2>/dev/null || echo -86400)))
}
worker_email() {
  local age st; age=$(beat_age); st=$(docker ps --filter "name=$WORKER" --format '{{.Status}}')
  echo "worker des courriels $1 : conteneur « ${st:-ABSENT} », dernier battement il y a ${age} s$([ "$age" -lt 30 ] && [ -n "$st" ] && echo ' : VIVANT' || echo ' : À VÉRIFIER')"
  [ "$age" -lt 30 ] && [ -n "$st" ]
}
started() { docker inspect -f '{{.State.StartedAt}}' "$WORKER" 2>/dev/null; }
# Un battement écrit APRÈS le redémarrage : son âge est inférieur au temps écoulé depuis le démarrage du conteneur
beat_after_restart() { [ "$(beat_age)" -lt "$(( $(date +%s) - $(date -d "$(started)" +%s) ))" ]; }

# Les workers s'arrêtent sur ce signal et leur conteneur les relance avec le nouveau code : on attend (60 s au plus) que celui des
# courriels ait redémarré et batte de nouveau
restart_workers() {
  local w0; w0=$(started)
  docker exec -w /var/www "$APP" php bin/console messenger:stop-workers >/dev/null 2>&1
  for _ in $(seq 1 30); do pause 2; [ "$(started)" != "$w0" ] && beat_after_restart && break; done
  [ "$(started)" != "$w0" ] || echo "ATTENTION : le conteneur du worker des courriels n'a pas redémarré"
  worker_email "après" || echo "ATTENTION : le worker des courriels ne bat pas 60 s après : le relancer (docker restart $WORKER) et prévenir"
}

# Échange atomique de deux dossiers (renameat2, RENAME_EXCHANGE) : aucun instant sans cache
exchange() { docker exec -u www-data "$APP" perl -e 'my $r = syscall(316, -100, $ARGV[0], -100, $ARGV[1], 2); print "échange : rc=$r $!\n"; exit($r == 0 ? 0 : 1)' "/var/www/var/swap_stage$1/dev" /var/www/var/cache/dev; }

# Ancien cache lisible à son ancien chemin après l'échange : une requête ou un worker démarrés sur l'ancien conteneur de services
# chargent encore ses fichiers par var/cache/dev/Container<ancien>/… Un lien de ce nom est posé AVANT l'échange dans le cache qui va
# entrer, vers l'endroit où l'autre se trouvera après (var/swap_stage<n>/dev) : aucun instant sans fichier.
link_old_container() {
  local d n
  for d in "$L"/var/cache/dev/Container*/; do
    n=$(basename "$d")
    [ -e "$L/var/swap_stage$1/dev/$n" ] || [ -L "$L/var/swap_stage$1/dev/$n" ] || docker exec -u www-data "$APP" ln -s "/var/www/var/swap_stage$1/dev/$n" "/var/www/var/swap_stage$1/dev/$n"
  done
  echo "liens vers le conteneur de services sortant : $(find "$L/var/swap_stage$1/dev" -maxdepth 1 -type l -name 'Container*' | wc -l)"
}

# Après un échange : une page publique, puis une lecture AUTHENTIFIÉE (une route publique à 200 ne dit rien des clés des jetons) et le
# nombre de clés visibles dans le conteneur
after_checks() {
  local keys auth
  echo "après : $(git log -1 --format=%h), page publique $(curl -s -o /dev/null -w '%{http_code}' "${H[@]}" "$BASE_URL/api/landingpage-settings")"
  keys=$(docker exec "$APP" sh -c 'ls /var/www/config/jwt 2>/dev/null | wc -l')
  if [ -s "$TOKEN" ]; then
    auth=$(curl -s -o /dev/null -w '%{http_code}' "${H[@]}" -H "Authorization: Bearer $(docker exec "$APP" cat /var/www/var/swap_token.txt)" "$BASE_URL${AUTH_CHECK_PATH:-/api/gestion/statistiques}")
    echo "authentification : lecture authentifiée $auth, $keys clé(s) visibles$([ "$auth" = 200 ] && [ "$keys" = 2 ] && echo ' : OK' || echo ' : RETOUR ARRIÈRE IMMÉDIAT')"
  else
    echo "authentification : pas de jeton de contrôle, $keys clé(s) visibles$([ "$keys" = 2 ] || echo ' : RETOUR ARRIÈRE IMMÉDIAT')"
  fi
}

# Pages publiques de TOUS les sites (lecture seule) : réglages et composants de la page d'accueil en 200, route protégée en 401
sites() {
  local ok=0 bad=0 code h a b c
  for code in $(docker exec "$DB" psql -U postgres -d "$MASTER" -Atc "SELECT code FROM tenants ORDER BY code"); do
    # shellcheck disable=SC2059
    h=(-H "Host: $FRONT_HOST" -H "X-Tenant-Host: $(printf "$SITE_HOST_PATTERN" "$code")" -H 'Accept: application/json')
    a=$(curl -s -o /dev/null -w '%{http_code}' "${h[@]}" "$BASE_URL/api/landingpage-settings")
    b=$(curl -s -o /dev/null -w '%{http_code}' "${h[@]}" "$BASE_URL/api/components-config")
    c=$(curl -s -o /dev/null -w '%{http_code}' "${h[@]}" "$BASE_URL${AUTH_CHECK_PATH:-/api/gestion/statistiques}")
    if [ "$a/$b/$c" = "200/200/401" ]; then ok=$((ok + 1)); else bad=$((bad + 1)); echo "ATTENTION $code : $a / $b / $c"; fi
  done
  echo "sites : $ok en 200 / 200 / 401, $bad autre(s)"
  [ "$bad" = 0 ]
}

case "${1:-}" in
jeton)
  # Jeton d'un compte du site de contrôle, écrit dans var/swap_token.txt (lisible du seul compte du serveur web), jamais affiché
  docker exec -i -u www-data -w /var/www -e B_DB="$CONTROL_DB" -e B_CODE="$CONTROL_CODE" -e B_USER="$CONTROL_USER_ID" "$APP" php <<'PHP'
<?php
require '/var/www/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/var/www/.env');
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$container = $kernel->getContainer();
$provider = $container->get(App\Services\TenantEntityManagerProvider::class);
$provider->switchTenant(getenv('B_DB'), getenv('B_CODE'));
$user = $provider->getEntityManager()->find(App\Entity\User::class, (int) getenv('B_USER'));
if ($user === null) { fwrite(STDERR, "compte de contrôle absent\n"); exit(1); }
file_put_contents('/var/www/var/swap_token.txt', $container->get('lexik_jwt_authentication.jwt_manager')->create($user));
chmod('/var/www/var/swap_token.txt', 0600);
echo "jeton écrit (", strlen(file_get_contents('/var/www/var/swap_token.txt')), " caractères)\n";
PHP
  ;;
fin)
  docker exec -u www-data "$APP" rm -f /var/www/var/swap_token.txt && echo "jeton de contrôle supprimé"
  ;;
workers) worker_email maintenant ;;
build)
  number "${3:-}"; lock; clean_tree
  git merge-base --is-ancestor HEAD "$2" || { echo "PAS UNE AVANCE RAPIDE depuis l'arbre en ligne"; exit 1; }
  # Garde des montages (incident du 13/11/2026) : la livraison ne touche aucun point de montage du conteneur ni aucun fichier
  # d'environnement, et n'apporte aucun lien symbolique
  SENS=$(git diff --name-only HEAD "$2" | grep -E '^(config/jwt|jwt/|docker/|\.env)' | tr '\n' ' ')
  [ -z "$SENS" ] || { echo "ARRÊT : la livraison touche un point de montage ou un fichier d'environnement : $SENS"; exit 1; }
  LIENS=$(git ls-tree -r "$2" | awk '$1 == "120000" {print $4}' | tr '\n' ' ')
  [ -z "$LIENS" ] || { echo "ARRÊT : la livraison contient un lien symbolique : $LIENS"; exit 1; }
  echo "garde des montages : aucun point de montage touché, aucun lien ; $(git diff --name-only HEAD "$2" | wc -l) fichier(s) changé(s) ; migrations : $(git diff --name-only HEAD "$2" -- migrations | wc -l)"
  [ -e "$L/var/swap_stage$3" ] && { echo "CACHE swap_stage$3 DÉJÀ LÀ : prendre un numéro neuf (aucune suppression ici)"; exit 1; }
  git -C "$BUILD_WORKTREE" checkout -q --detach "$2" || { echo "WORKTREE DE CONSTRUCTION INUTILISABLE : $BUILD_WORKTREE"; exit 1; }
  echo "worktree de construction : $(git -C "$BUILD_WORKTREE" log -1 --format=%h), $(git -C "$BUILD_WORKTREE" status --short | wc -l) fichier(s) modifié(s)"
  # Le cache est construit par un conteneur provisoire qui a l'environnement de la production (lu du conteneur, gardé dans un fichier
  # du seul utilisateur, supprimé aussitôt) ; le code y est monté en lecture seule
  ENVF=$(mktemp); chmod 600 "$ENVF"; trap 'rm -f "$ENVF"' EXIT
  docker inspect "$APP" --format '{{range .Config.Env}}{{println .}}{{end}}' | grep -v '^$' > "$ENVF"
  mkdir -p "$L/var/swap_stage$3" && chmod 777 "$L/var/swap_stage$3"
  docker run --rm -u 33:33 --network "$NETWORK" --env-file "$ENVF" -v "$BUILD_WORKTREE":/var/www:ro -v "$L/.env":/var/www/.env:ro \
    -v "$L/vendor":/var/www/vendor:ro -v "$L/var/swap_stage$3":/var/www/var/cache -v "$L/jwt":/var/www/config/jwt:ro \
    -v "$L/docker/php/uploads.ini":/usr/local/etc/php/conf.d/uploads.ini:ro -v "$L/docker/php/zz-opcache-perf.ini":/usr/local/etc/php/conf.d/zz-opcache-perf.ini:ro \
    --tmpfs /var/www/var/log:uid=33,gid=33 -w /var/www "$IMAGE" sh -c 'php bin/console cache:warmup --no-interaction 2>&1 | grep -i error | head -3; php bin/console lint:container 2>&1 | grep -E "OK|ERROR"; php bin/console doctrine:schema:validate --skip-sync 2>&1 | grep -E "OK|ERROR"; php bin/console debug:router --format=json 2>/dev/null | php -r "echo count(json_decode(stream_get_contents(STDIN), true)), \" routes\n\";"'
  echo "cache construit : $(ls "$L/var/swap_stage$3/dev" | grep -c Container) conteneur(s) de services ; $(stat -c '%U:%G' "$L/var/swap_stage$3/dev")"
  ;;
photo)
  # Aucune suppression : un nom neuf à chaque photo. Lectures : une par ligne de var/bascule.lectures, « nom chemin [sans-jeton] » ;
  # des GET seulement, sur le site de contrôle. Une route qui écrit à la lecture (compteur, journal) n'a rien à faire dans ce fichier.
  case "${2:-}" in ''|*[!a-z0-9-]*) echo "NOM DE PHOTO ATTENDU (minuscules, chiffres, tirets)"; exit 1;; esac
  OUT=$PHOTO_DIR/$2; [ -e "$OUT" ] && { echo "PHOTO $2 DÉJÀ LÀ : prendre un nom neuf"; exit 1; }
  [ -r "$READS" ] || { echo "LECTURES ABSENTES : $READS (modèle : ops/bascule/bascule.lectures.exemple)"; exit 1; }
  [ -s "$TOKEN" ] || { echo "PAS DE JETON DE CONTRÔLE : lancer d'abord « jeton »"; exit 1; }
  mkdir -p "$OUT"; TOK=$(docker exec "$APP" cat /var/www/var/swap_token.txt)
  while read -r name path mode; do
    case "$name" in ''|\#*) continue;; esac
    if [ "${mode:-}" = sans-jeton ]; then curl -s -o "$OUT/$name.json" -w '%{http_code}\n' "${H[@]}" "$BASE_URL$path" > "$OUT/$name.code"
    else curl -s -o "$OUT/$name.json" -w '%{http_code}\n' "${H[@]}" -H "Authorization: Bearer $TOK" "$BASE_URL$path" > "$OUT/$name.code"; fi
  done < "$READS"
  echo "photo $2 : $(ls "$OUT" | grep -c '\.code$') lecture(s), codes : $(cat "$OUT"/*.code | sort | uniq -c | tr -s ' ' | tr '\n' ';') 5xx : $(cat "$OUT"/*.code | grep -c '^5')"
  sites
  ;;
comparer)
  python3 "$HERE/comparer.py" "$PHOTO_DIR/$2" "$PHOTO_DIR/$3" ${4:+"$4"}
  ;;
switch)
  number "${3:-}"; lock; clean_tree
  [ -d "$L/var/swap_stage$3/dev" ] || { echo "CACHE ABSENT : lancer d'abord « build »"; exit 1; }
  git merge-base --is-ancestor HEAD "$2" || { echo "PAS UNE AVANCE RAPIDE depuis l'arbre en ligne"; exit 1; }
  worker_email "avant"
  echo "avant : $(git log -1 --format=%h), page publique $(curl -s -o /dev/null -w '%{http_code}' "${H[@]}" "$BASE_URL/api/landingpage-settings")"
  # Laisser finir la requête de contrôle ci-dessus AVANT l'échange : sa fin de traitement charge encore des fichiers du cache sortant
  pause 2
  link_old_container "$3"
  date -u +%FT%T > "$L/var/bascule.heure"; T0=$(date +%s.%N)
  git merge -q --ff-only "$2" && exchange "$3" || { echo "ÉCHEC DE LA BASCULE : vérifier l'état (git log -1, var/cache/dev) avant toute autre commande"; exit 1; }
  echo "bascule en $(awk -v a="$T0" -v b="$(date +%s.%N)" 'BEGIN{printf "%.2f", b-a}') s à $(cat "$L/var/bascule.heure") UTC ; retour arrière : « retour $(git rev-parse --short "HEAD@{1}") $3 »"
  restart_workers
  after_checks
  ;;
retour)
  # Retour arrière : le cache d'avant est dans var/swap_stage<n>/dev depuis l'échange ; le code revient au commit d'avant. Rien n'a été
  # poussé à ce stade (on ne pousse qu'après la photo « après »). Une migration appliquée reste en place : elle est additive.
  number "${3:-}"; lock
  [ -z "$(git status --short -uno)" ] || { echo "ARBRE EN LIGNE MODIFIÉ"; exit 1; }
  [ -d "$L/var/swap_stage$3/dev" ] || { echo "CACHE D'AVANT ABSENT : var/swap_stage$3/dev"; exit 1; }
  git merge-base --is-ancestor "$2" HEAD || { echo "CE COMMIT N'EST PAS UN ANCÊTRE DE L'ARBRE EN LIGNE : $2"; exit 1; }
  link_old_container "$3"
  git reset -q --hard "$2" && exchange "$3" || { echo "ÉCHEC DU RETOUR ARRIÈRE : vérifier l'état (git log -1, var/cache/dev)"; exit 1; }
  echo "retour arrière fait à $(date -u +%FT%T) UTC : $(git log -1 --format=%h)"
  restart_workers
  after_checks
  echo "si l'authentification reste en échec : regarder les montages du conteneur, puis docker restart $APP (docs/architecture.md, « Points de montage »)"
  ;;
logs)
  [ -n "${2:-}" ] || set -- logs "$(cat "$L/var/bascule.heure" 2>/dev/null)"
  echo "erreurs du journal depuis $2 : $(docker exec "$APP" sh -c "awk -v t=$2 'substr(\$1,2,19) >= t' /var/www/var/log/dev.log | grep -c 'CRITICAL\|\.ERROR'")"
  docker exec "$APP" sh -c "awk -v t=$2 'substr(\$1,2,19) >= t' /var/www/var/log/dev.log | grep 'CRITICAL\|\.ERROR' | cut -c1-300 | head -5"
  echo "codes du serveur web : $(docker logs --since "${2}Z" "$NGINX" 2>&1 | grep -oE '" [1-5][0-9]{2} ' | sort | uniq -c | tr -s ' ' | tr '\n' ';')"
  ;;
menage)
  # Anciens caches de bascule : on garde les $KEEP derniers numéros et tout dossier vers lequel le cache en ligne a encore un lien
  lock
  mapfile -t ALL < <(ls -d "$L"/var/swap_stage* 2>/dev/null | sed 's/.*swap_stage//' | grep -E '^[0-9]+$' | sort -n)
  LINKED=$(find "$L/var/cache/dev" -maxdepth 1 -type l -name 'Container*' -exec readlink {} \; | sed -n 's#.*/swap_stage\([0-9]*\)/.*#\1#p' | sort -u | tr '\n' ' ')
  TOTAL=${#ALL[@]}; DEL=()
  for i in "${!ALL[@]}"; do
    n=${ALL[$i]}
    [ "$i" -ge $((TOTAL - KEEP)) ] && continue
    case " $LINKED " in *" $n "*) continue;; esac
    DEL+=("$n")
  done
  echo "caches de bascule : $TOTAL ; gardés : les $KEEP derniers ($(printf '%s ' "${ALL[@]: -$KEEP}")) et ceux liés au cache en ligne (${LINKED:-aucun}) ; à supprimer : ${#DEL[@]} (${DEL[*]:-aucun})"
  [ "${2:-}" = --oui ] || { echo "rien supprimé : relancer avec --oui"; exit 0; }
  BEFORE=$(df --output=avail -k "$L/var" | tail -1)
  for n in "${DEL[@]}"; do docker exec -u www-data "$APP" rm -rf "/var/www/var/swap_stage$n/dev"; rmdir "$L/var/swap_stage$n" 2>/dev/null || docker exec -u www-data "$APP" rm -rf "/var/www/var/swap_stage$n"; done
  echo "supprimés : ${#DEL[@]} ; espace libéré : $(( ($(df --output=avail -k "$L/var" | tail -1) - BEFORE) / 1024 )) Mo ; restants : $(ls -d "$L"/var/swap_stage* 2>/dev/null | wc -l)"
  ;;
*)
  sed -n '2,17p' "$HERE/$(basename "$0")"; exit 1
  ;;
esac
