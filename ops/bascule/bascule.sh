#!/usr/bin/env bash
# Mise en ligne par échange de cache : procédure dans docs/mise-en-ligne.md (à lire avant le premier usage).
#
#   ops/bascule/bascule.sh jeton                    jeton de contrôle (var/swap_token.txt, jamais affiché)
#   ops/bascule/bascule.sh vendor <commit> <n>      paquets du commit (composer.lock changé) préparés dans var/vendor_stage<n>, AVANT build
#   ops/bascule/bascule.sh build <commit> <n>       gardes, puis cache du commit construit dans var/swap_stage<n>
#   ops/bascule/bascule.sh photo <nom>              lectures du site de contrôle + pages publiques de tous les sites
#   ops/bascule/bascule.sh comparer <avant> <après> [motif à écarter]
#   ops/bascule/bascule.sh switch <commit> <n>      avance du code et échange du cache, contrôles d'après
#   ops/bascule/bascule.sh retour <commit d'avant> <n>   retour arrière : code et cache d'avant
#   ops/bascule/bascule.sh logs <depuis, ISO UTC>   erreurs du journal et codes du serveur web depuis cet instant
#   ops/bascule/bascule.sh workers                  le worker des courriels bat-il ? et le contrôle g des 7 workers (lecture seule)
#   ops/bascule/bascule.sh essai-echange            essai à blanc des échanges du vendor et de public/bundles (dossiers d'essai)
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

# L'arbre en ligne est-il propre, et le commit à mettre en ligne ($1) contient-il tout ce qui est en ligne et tout ce qui est poussé ?
# Une autre session a pu pousser entre-temps : le commit doit alors repartir d'origin/dev. Après un retour arrière, l'arbre en ligne est
# en retrait d'origin/dev : c'est permis, on le dit.
clean_tree() {
  [ -z "$(git status --short -uno)" ] || { echo "ARBRE EN LIGNE MODIFIÉ"; exit 1; }
  git fetch -q origin || { echo "FETCH IMPOSSIBLE"; exit 1; }
  git merge-base --is-ancestor origin/dev "$1" || { echo "CE COMMIT NE CONTIENT PAS ORIGIN/DEV ($(git rev-parse --short origin/dev)) : une autre session a poussé, repartir d'origin/dev"; exit 1; }
  git merge-base --is-ancestor HEAD "$1" || { echo "PAS UNE AVANCE RAPIDE depuis l'arbre en ligne ($(git rev-parse --short HEAD))"; exit 1; }
  [ "$(git rev-parse HEAD)" = "$(git rev-parse origin/dev)" ] || echo "note : l'arbre en ligne ($(git rev-parse --short HEAD)) est en retrait d'origin/dev ($(git rev-parse --short origin/dev)), comme après un retour arrière"
}

# Worker des courriels vivant ? Conteneur démarré et dernier battement de moins de 30 s. Le battement est une clé de 24 h réécrite
# toutes les 5 s : son âge = 86400 - ttl. Lecture seule.
# Lot CACHE-ETAT : la clé la PLUS JEUNE (après une montée de version, l'ancien préfixe laisse une vieille clé jusqu'à 24 h) ; $1 = transport
beat_age() {
  local k best=999999 a
  for k in $(docker exec "$REDIS" redis-cli -n 0 --scan --pattern "*worker_heartbeat.${1:-email}"); do
    a=$((86400 - $(docker exec "$REDIS" redis-cli -n 0 ttl "$k" 2>/dev/null || echo -86400)))
    [ "$a" -lt "$best" ] && best=$a
  done
  echo "$best"
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
  # Lot CACHE-ETAT (contrôle g) : CHAQUE conteneur de worker doit avoir démarré APRÈS l'heure de bascule. Un worker en retard qui traite
  # encore un message (lecture IA, chapitre, PDF…) s'arrêtera de lui-même à la fin de ce message (le signal a un préfixe fixe depuis ce
  # lot) : on l'attend, 15 min au plus, sans jamais l'interrompre. Un worker en retard ET au repos n'a pas reçu le signal (montée de
  # version d'avant ce lot) : docker restart, sans risque puisqu'il ne traite rien.
  for _ in $(seq 1 90); do
    late=$(late_workers) || { echo "ATTENTION : heure de bascule illisible, workers non vérifiés"; break; }
    [ -z "$late" ] && break
    # un conteneur à la fois, relancé SANS délai (-t 0) juste après avoir été vu au repos : php en PID 1 ignore SIGTERM, et un délai
    # de 10 s lui laisserait le temps de prendre un message qu'il perdrait
    for c in $late; do
      busy_worker "$c" && continue
      echo "worker démarré AVANT la bascule et au repos : $c : docker restart"
      docker restart -t 0 "$c" >/dev/null
    done
    pause 10
  done
  # les workers reconstruisent leur cache avant de battre (10 à 30 s) : on attend 60 s au plus que tout soit vert, puis on dit l'état
  for _ in $(seq 1 30); do workers_g >/dev/null 2>&1 && break; pause 2; done
  workers_g
  worker_email "après" || echo "ATTENTION : le worker des courriels ne bat pas 60 s après : le relancer (docker restart $WORKER) et prévenir"
}

# File Redis (base 1) d'un conteneur de worker ; un message en cours = une entrée « pending » du groupe symfony
worker_stream() { local t; t=$(docker inspect -f '{{join .Config.Cmd " "}}' "$1" | sed -E 's/.*consume\.sh ([a-z_]+).*/\1/'); [ "$t" = async ] && echo messages || echo "messages_$t"; }
busy_worker() { local n; n=$(docker exec "$REDIS" redis-cli -n 1 XINFO GROUPS "$(worker_stream "$1")" 2>/dev/null | awk 'p == 1 { print; exit } $0 == "pending" { p = 1 }'); [ "${n:-0}" -gt 0 ]; }
# Le transport a-t-il un message en cours ? (file retrouvée par docker inspect : docker ps tronque la commande)
transport_busy() { local c want; want=$([ "$1" = async ] && echo messages || echo "messages_$1"); for c in $(docker ps --format '{{.Names}}' | grep -E '^symfony_messenger_worker.*_v2$'); do [ "$(worker_stream "$c")" = "$want" ] && { busy_worker "$c"; return; }; done; return 1; }

# Heure de bascule (UTC, écrite avec « Z ») ; absente = erreur, jamais minuit
switch_epoch() { [ -s "$L/var/bascule.heure" ] || { echo "HEURE DE BASCULE ABSENTE (var/bascule.heure)" >&2; return 1; }; date -u -d "$(sed 's/Z$//' "$L/var/bascule.heure")Z" +%s; }

# Conteneurs de workers EN MARCHE (hors planificateur, noté à part) démarrés avant l'heure de bascule
late_workers() {
  local c t0 out=""; t0=$(switch_epoch) || return 1
  for c in $(docker ps --format '{{.Names}}' | grep -E '^symfony_messenger_worker.*_v2$' | grep -v scheduler | sort); do
    [ "$(date -u -d "$(docker inspect -f '{{.State.StartedAt}}' "$c")" +%s)" -ge "$t0" ] || out="$out $c"
  done
  echo "${out# }"
}

# Contrôle g : workers démarrés après la bascule, en marche, battement de moins de 60 s (clé la plus jeune) ; le planificateur à part
workers_g() {
  local n=0 bad=0 c t a late
  for c in $(docker ps -a --format '{{.Names}}' | grep -E '^symfony_messenger_worker.*_v2$' | grep -v scheduler | sort); do
    n=$((n + 1)); [ "$(docker inspect -f '{{.State.Status}}' "$c")" = running ] || bad=$((bad + 1))
  done
  late=$(late_workers) || { bad=$((bad + 1)); echo "ATTENTION : heure de bascule illisible"; }
  [ -z "$late" ] || bad=$((bad + 1))
  # un worker ne bat pas pendant qu'il traite un message : battement ancien toléré si sa file a un message en cours
  for t in async esg landing_ai media email pdf gestion_ai; do a=$(beat_age "$t"); [ "$a" -lt 60 ] || transport_busy "$t" || { bad=$((bad + 1)); echo "ATTENTION battement $t : $a s"; }; done
  echo "workers (contrôle g) : $n conteneur(s), $bad anomalie(s)$([ "$bad" = 0 ] && echo ' : OK' || echo ' : RETOUR ARRIÈRE IMMÉDIAT') ; planificateur : $(docker ps --format '{{.Names}}' | grep -q scheduler && echo "battement il y a $(beat_age scheduler_default) s" || echo 'arrêté (conteneur absent)')"
  [ "$bad" = 0 ]
}

# Rechargement de PHP-FPM (signal USR2 au maître, PID 1, par le shell : l'image n'a pas d'exécutable kill) et preuves : (a) aucun processus
# de pool repris d'avant, (b) version du Kernel sur disque
reload_fpm() {
  local before after kept
  before=$(fpm_pools); docker exec "$APP" sh -c 'kill -USR2 1' || { echo "RECHARGEMENT FPM IMPOSSIBLE : RETOUR ARRIÈRE"; return 1; }
  # un processus occupé finit sa requête (process_control_timeout = 30 s) avant que le maître n'en crée de nouveaux : 40 s au plus
  for _ in $(seq 1 20); do
    pause 2; after=$(fpm_pools)
    kept=$(comm -12 <(echo "$before" | tr ' ' '\n' | sort) <(echo "$after" | tr ' ' '\n' | sort) | grep -c .)
    [ "$kept" = 0 ] && [ -n "${after// /}" ] && break
  done
  echo "FPM rechargé : $(echo "$after" | wc -w) processus de pool, $kept repris d'avant$([ "$kept" = 0 ] && echo ' : OK' || echo ' : RETOUR ARRIÈRE IMMÉDIAT') ; $(docker exec "$APP" grep -m1 'const VERSION ' /var/www/vendor/symfony/http-kernel/Kernel.php | tr -s ' ' | sed 's/^ //')"
  [ "$kept" = 0 ]
}
fpm_pools() { docker exec "$APP" sh -c 'for p in /proc/[0-9]*; do c=$(tr "\0" " " < $p/cmdline 2>/dev/null); case "$c" in "php-fpm: pool"*) echo "${p#/proc/}";; esac; done' | tr '\n' ' '; }

# Échange atomique de deux dossiers (renameat2, RENAME_EXCHANGE) : aucun instant sans cache
exchange() { docker exec -u www-data "$APP" perl -e 'my $r = syscall(316, -100, $ARGV[0], -100, $ARGV[1], 2); print "échange : rc=$r $!\n"; exit($r == 0 ? 0 : 1)' "/var/www/var/swap_stage$1/dev" /var/www/var/cache/dev; }

# Échange atomique de deux dossiers en root explicite (-u 0 : l'image tourne en www-data, et le vendor appartient à root) : <source> <cible> <nom>
swap_root() { docker exec -u 0 "$APP" perl -e 'my $r = syscall(316, -100, $ARGV[0], -100, $ARGV[1], 2); print "échange $ARGV[2] : rc=$r $!\n"; exit($r == 0 ? 0 : 1)' "$1" "$2" "$3"; }
# Échange du vendor (lot TACHES-0) : aucun instant sans vendor
# Chemins fixes (jamais lus de l'environnement) ; seul « essai-echange » les remplace par des dossiers d'essai
VSTAGE=/var/www/var/vendor_stage; VDST=/var/www/vendor; BSTAGE=/var/www/var/bundles_stage; BDST=/var/www/public/bundles
exchange_vendor() { swap_root "$VSTAGE$1" "$VDST" "du vendor"; }
# Échange des fichiers publics des paquets (public/bundles, ignoré par git, train 123) : EasyAdmin lit son manifeste dans le vendor et
# demande des fichiers à empreinte (app.<empreinte>.js) ; un vendor changé sans eux laisse /admin sans style ni scripts (404)
exchange_bundles() { swap_root "$BSTAGE$1" "$BDST" "des fichiers publics des paquets"; }
# Le vendor ET public/bundles, dans cet ordre ; si le second échange échoue, le premier est défait (rien n'a bougé)
exchange_packages() {
  exchange_vendor "$1" || { echo "ÉCHEC DE L'ÉCHANGE DU VENDOR : rien n'a bougé"; return 1; }
  exchange_bundles "$1" || { echo "ÉCHEC DE L'ÉCHANGE DE public/bundles : vendor remis"; exchange_vendor "$1"; return 1; }
}

# Fichiers d'EasyAdmin servis ? Chaque fichier du manifeste que le vendor EN LIGNE déclare (celui que lisent les pages /admin) est demandé
# au serveur web : tous en 200, sinon retour arrière. Une page /admin anonyme renvoie à la connexion, qui ne charge pas ces fichiers.
ea_assets() {
  local f n=0 bad=0 c
  for f in $(docker exec "$APP" php -r '$m = json_decode((string) @file_get_contents("/var/www/vendor/easycorp/easyadmin-bundle/public/manifest.json"), true); foreach ((array) $m as $v) { if (is_string($v) && preg_match("#^[A-Za-z0-9._/-]+$#", $v) && !str_contains($v, "..")) echo ltrim($v, "/"), "\n"; }'); do
    n=$((n + 1)); c=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $FRONT_HOST" "$BASE_URL/bundles/easyadmin/$f")
    [ "$c" = 200 ] || { bad=$((bad + 1)); echo "ATTENTION fichier d'EasyAdmin $f : $c"; }
  done
  # « $1 » = avant : simple mesure (compte rendu), sans consigne de retour arrière
  echo "fichiers d'EasyAdmin ${1:-après} (manifeste du vendor en ligne) : $((n - bad)) / $n en 200$([ "$n" -gt 0 ] && [ "$bad" = 0 ] && echo ' : OK' || { [ "${1:-}" = avant ] && echo ' : À VOIR AVANT DE BASCULER' || echo ' : RETOUR ARRIÈRE IMMÉDIAT'; })"
  [ "$n" -gt 0 ] && [ "$bad" = 0 ]
}

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
  ea_assets
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
workers) worker_email maintenant; workers_g ;;
essai-echange)
  # Essai à blanc des échanges du vendor et de public/bundles, dans le conteneur en ligne, en root, sur des dossiers d'essai placés comme
  # les vrais (var/ ↔ racine, var/ ↔ public/ : même montage) : bascule, retour, puis second échange en échec (le vendor doit revenir).
  # Rien d'autre n'est touché ; les dossiers d'essai sont supprimés à la fin. Puis le contrôle des fichiers d'EasyAdmin, en lecture.
  lock; E=essai$$
  VSTAGE=/var/www/var/.vstage_; VDST=/var/www/.essai_vendor_$E; BSTAGE=/var/www/var/.bstage_; BDST=/var/www/public/.essai_bundles_$E
  docker exec -u 0 "$APP" sh -c "mkdir $VSTAGE$E $VDST $BSTAGE$E $BDST && echo nouveau > $VSTAGE$E/m && echo ancien > $VDST/m && echo nouveau > $BSTAGE$E/m && echo ancien > $BDST/m" || { echo "DOSSIERS D'ESSAI IMPOSSIBLES"; exit 1; }
  st() { docker exec "$APP" sh -c "echo vendor=\$(cat $VDST/m) bundles=\$(cat $BDST/m 2>/dev/null || echo absent) / de côté : vendor=\$(cat $VSTAGE$E/m) bundles=\$(cat $BSTAGE$E/m 2>/dev/null || echo absent)"; }
  ok=0
  exchange_packages "$E"; r=$(st); echo "après la bascule : $r"; [ "$r" = "vendor=nouveau bundles=nouveau / de côté : vendor=ancien bundles=ancien" ] && ok=$((ok + 1))
  exchange_packages "$E"; r=$(st); echo "après le retour : $r"; [ "$r" = "vendor=ancien bundles=ancien / de côté : vendor=nouveau bundles=nouveau" ] && ok=$((ok + 1))
  docker exec -u 0 "$APP" rm -rf "$BSTAGE$E"
  exchange_packages "$E" && echo "ANOMALIE : l'échange aurait dû échouer"; r=$(st); echo "après un second échange en échec : $r"; [ "$r" = "vendor=ancien bundles=ancien / de côté : vendor=nouveau bundles=absent" ] && ok=$((ok + 1))
  docker exec -u 0 "$APP" rm -rf "$VSTAGE$E" "$VDST" "$BSTAGE$E" "$BDST"
  echo "essai à blanc des échanges : $ok / 3 conformes ; dossiers d'essai restants : $(docker exec "$APP" sh -c "ls -d $VSTAGE$E $VDST $BSTAGE$E $BDST 2>/dev/null | wc -l")"
  ea_assets
  [ "$ok" = 3 ]
  ;;
vendor)
  # Lot TACHES-0 : un composer.lock changé. Le vendor en ligne est copié (dans un conteneur provisoire de la MÊME image, en root comme lui : -u 0,
  # l'image tourne en www-data)
  # dans var/vendor_stage<n>, puis « composer install » y pose les paquets du commit, avec les options d'aujourd'hui (paquets de dev
  # compris : APP_ENV=dev ; classmap optimisée du composer.json) ; le code du commit est monté en lecture seule (classmap de son src/).
  # Le vendor en ligne n'est pas touché ; « build » montera ce vendor, « switch » l'échangera, « retour » remettra l'ancien.
  number "${3:-}"; lock; clean_tree "$2"
  [ -n "$(git diff --name-only HEAD "$2" -- composer.lock)" ] || { echo "COMPOSER.LOCK INCHANGÉ : aucune étape vendor pour ce commit"; exit 1; }
  [ -e "$L/var/vendor_stage$3" ] && { echo "VENDOR vendor_stage$3 DÉJÀ LÀ : prendre un numéro neuf (aucune suppression ici)"; exit 1; }
  [ -e "$L/var/swap_stage$3" ] && { echo "CACHE swap_stage$3 DÉJÀ LÀ : prendre le numéro du train à venir"; exit 1; }
  git -C "$BUILD_WORKTREE" checkout -q --detach "$2" || { echo "WORKTREE DE CONSTRUCTION INUTILISABLE : $BUILD_WORKTREE"; exit 1; }
  docker run --rm -u 0 -e COMPOSER_HOME=/tmp/composer -e COMPOSER_ALLOW_SUPERUSER=1 -v "$L/vendor":/src-vendor:ro -v "$L/var":/l-var \
    -v "$BUILD_WORKTREE":/w:ro -w /w "$IMAGE" sh -c "cp -a /src-vendor /l-var/vendor_stage$3" || { echo "COPIE DU VENDOR IMPOSSIBLE"; exit 1; }
  docker run --rm -u 0 -e COMPOSER_HOME=/tmp/composer -e COMPOSER_ALLOW_SUPERUSER=1 -v "$BUILD_WORKTREE":/w:ro -v "$L/var/vendor_stage$3":/w/vendor \
    -w /w "$IMAGE" composer install --no-interaction --no-scripts --no-plugins --no-progress 2>&1 | grep -E 'Installing|Updating|Removing|Package operations|Nothing to install|rror' | head -20
  # le vendor préparé correspond-il au composer.lock du commit, paquet par paquet ?
  docker run --rm -u 0 -v "$BUILD_WORKTREE":/w:ro -v "$L/var/vendor_stage$3":/w/vendor:ro -w /w "$IMAGE" php -r '
    $lock = json_decode(file_get_contents("composer.lock"), true); $want = [];
    foreach (array_merge($lock["packages"], $lock["packages-dev"] ?? []) as $p) { $want[$p["name"]] = $p["version"]; }
    $inst = json_decode(file_get_contents("vendor/composer/installed.json"), true); $have = [];
    foreach ($inst["packages"] ?? $inst as $p) { $have[$p["name"]] = $p["version"]; }
    $diff = array_diff_assoc($want, $have) + array_diff_assoc($have, $want);
    echo "vendor préparé : " . count($have) . " paquet(s), " . count($want) . " au composer.lock, " . count($diff) . " écart(s)" . ($diff ? " : " . implode(", ", array_keys($diff)) : "") . "\n";
    foreach (["autoload.php", "autoload_runtime.php", "composer/autoload_classmap.php"] as $f) { if (!is_file("vendor/$f")) { echo "FICHIER ABSENT : vendor/$f\n"; exit(1); } }
    exit($diff ? 1 : 0);' || { echo "VENDOR PRÉPARÉ INCOMPLET : ne pas lancer build"; exit 1; }
  ;;
build)
  number "${3:-}"; lock; clean_tree "$2"
  # Lot TACHES-0 : un composer.lock changé exige l'étape « vendor » d'abord ; le cache se construit alors avec le vendor préparé
  VENDOR_SRC="$L/vendor"
  if [ -n "$(git diff --name-only HEAD "$2" -- composer.lock)" ]; then
    [ -d "$L/var/vendor_stage$3" ] || { echo "COMPOSER.LOCK CHANGÉ : lancer d'abord « vendor $2 $3 »"; exit 1; }
    VENDOR_SRC="$L/var/vendor_stage$3"; echo "vendor préparé : var/vendor_stage$3"
  fi
  # Garde des montages (incident du 13/11/2026) : la livraison ne touche aucun point de montage du conteneur ni aucun fichier
  # d'environnement, et n'apporte aucun lien symbolique
  # Exception étroite (lot PHP84, 20/11/2026) : un fichier AJOUTÉ sous docker/ qui n'est la source d'aucun montage des conteneurs en
  # service passe (il ne peut remplacer aucun point de montage ; ex. docker/php/php.ini, lu par l'image seulement) ; tout autre changement
  # sous docker/ (modifié, supprimé, renommé, ou fichier monté) reste refusé
  MOUNTED=$(for c in $(docker ps --format '{{.Names}}'); do docker inspect "$c" --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}'; done | sort -u)
  ADDED_OK=$(git diff --name-status --no-renames HEAD "$2" -- docker/ | awk '$1 == "A" {print $2}' | while read -r f; do printf '%s\n' "$MOUNTED" | grep -qxF "$L/$f" || echo "$f"; done)
  SENS=$(git diff --name-only --no-renames HEAD "$2" | grep -E '^(config/jwt|jwt/|docker/|\.env)' | grep -vxF -f <(printf '%s\n' "$ADDED_OK" | grep -v '^$') | tr '\n' ' ')
  [ -z "$ADDED_OK" ] || echo "garde des montages : fichier(s) ajouté(s) sous docker/, source d'aucun montage : $(echo $ADDED_OK)"
  [ -z "$SENS" ] || { echo "ARRÊT : la livraison touche un point de montage ou un fichier d'environnement : $SENS"; exit 1; }
  LIENS=$(git ls-tree -r "$2" | awk '$1 == "120000" {print $4}' | tr '\n' ' ')
  [ -z "$LIENS" ] || { echo "ARRÊT : la livraison contient un lien symbolique : $LIENS"; exit 1; }
  # Garde du planning (20/11/2026) : la recette Flex de symfony/scheduler, appliquée par une commande composer, crée src/Schedule.php
  # (#[AsSchedule], second planning « default » à côté de PlatformSchedule) ; jamais livré, jamais laissé dans l'arbre en ligne
  if git cat-file -e "$2:src/Schedule.php" 2>/dev/null || [ -e "$L/src/Schedule.php" ]; then
    echo "ARRÊT : src/Schedule.php (recette Flex de symfony/scheduler) dans la livraison ou dans l'arbre en ligne : à supprimer, jamais à livrer"; exit 1
  fi
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
    -v "$VENDOR_SRC":/var/www/vendor:ro -v "$L/var/swap_stage$3":/var/www/var/cache -v "$L/jwt":/var/www/config/jwt:ro \
    -v "$L/docker/php/uploads.ini":/usr/local/etc/php/conf.d/uploads.ini:ro -v "$L/docker/php/zz-opcache-perf.ini":/usr/local/etc/php/conf.d/zz-opcache-perf.ini:ro \
    --tmpfs /var/www/var/log:uid=33,gid=33 -w /var/www "$IMAGE" sh -c 'php bin/console cache:warmup --no-interaction 2>&1 | grep -i error | head -3; php bin/console lint:container 2>&1 | grep -E "OK|ERROR"; php bin/console doctrine:schema:validate --skip-sync 2>&1 | grep -E "OK|ERROR"; php bin/console debug:router --format=json 2>/dev/null | php -r "echo count(json_decode(stream_get_contents(STDIN), true)), \" routes\n\";"'
  echo "cache construit : $(ls "$L/var/swap_stage$3/dev" | grep -c Container) conteneur(s) de services ; $(stat -c '%U:%G' "$L/var/swap_stage$3/dev")"
  if [ "$VENDOR_SRC" != "$L/vendor" ]; then
    # Train 123 : les fichiers publics des paquets du vendor préparé (assets:install en COPIE, pas en liens), dans l'environnement du site,
    # posés dans var/bundles_stage<n> ; « switch » les échangera avec public/bundles en même temps que le vendor
    [ -e "$L/var/bundles_stage$3" ] && { echo "bundles_stage$3 DÉJÀ LÀ : prendre un numéro neuf (aucune suppression ici)"; exit 1; }
    mkdir -p "$L/var/bundles_stage$3" && chmod 777 "$L/var/bundles_stage$3"
    docker run --rm -u 33:33 --network "$NETWORK" --env-file "$ENVF" -v "$BUILD_WORKTREE":/var/www:ro -v "$L/.env":/var/www/.env:ro \
      -v "$VENDOR_SRC":/var/www/vendor:ro -v "$L/var/swap_stage$3":/var/www/var/cache -v "$L/jwt":/var/www/config/jwt:ro \
      -v "$L/var/bundles_stage$3":/out/bundles --tmpfs /var/www/var/log:uid=33,gid=33 -w /var/www "$IMAGE" \
      php bin/console assets:install /out --no-interaction 2>&1 | grep -iE 'error|copy|symlink|installed' | head -5
    # chaque fichier du manifeste d'EasyAdmin du vendor préparé est-il là ?
    MISS=$(docker run --rm -u 33:33 -v "$VENDOR_SRC":/v:ro -v "$L/var/bundles_stage$3":/b:ro "$IMAGE" php -r '$m = json_decode((string) @file_get_contents("/v/easycorp/easyadmin-bundle/public/manifest.json"), true); if (!$m) { echo "MANIFESTE ILLISIBLE"; exit; } foreach ($m as $v) { if (is_string($v) && !is_file("/b/easyadmin/" . ltrim($v, "/"))) echo $v, " "; }' 2>&1)
    LINKS=$(find "$L/var/bundles_stage$3" -type l | wc -l)
    echo "public/bundles préparé : $(ls "$L/var/bundles_stage$3" | tr '\n' ' '); $(find "$L/var/bundles_stage$3" -type f | wc -l) fichier(s), $LINKS lien(s) ; manquants au manifeste d'EasyAdmin : ${MISS:-aucun}"
    [ -z "$MISS" ] && [ "$LINKS" = 0 ] || { echo "public/bundles PRÉPARÉ INCOMPLET : ne pas lancer switch"; exit 1; }
  fi
  ;;
photo)
  # Aucune suppression : un nom neuf à chaque photo. Lectures : une par ligne de var/bascule.lectures, sur le site de contrôle :
  # « nom chemin [sans-jeton] » (un GET) ou « nom chemin [sans-jeton] POST <corps JSON> » (une écriture dont on attend le refus).
  # Une route qui écrit à la lecture (compteur, journal) n'a rien à faire dans ce fichier.
  case "${2:-}" in ''|*[!a-z0-9-]*) echo "NOM DE PHOTO ATTENDU (minuscules, chiffres, tirets)"; exit 1;; esac
  OUT=$PHOTO_DIR/$2; [ -e "$OUT" ] && { echo "PHOTO $2 DÉJÀ LÀ : prendre un nom neuf"; exit 1; }
  [ -r "$READS" ] || { echo "LECTURES ABSENTES : $READS (modèle : ops/bascule/bascule.lectures.exemple)"; exit 1; }
  [ -s "$TOKEN" ] || { echo "PAS DE JETON DE CONTRÔLE : lancer d'abord « jeton »"; exit 1; }
  mkdir -p "$OUT"; TOK=$(docker exec "$APP" cat /var/www/var/swap_token.txt)
  while read -r name path rest; do
    case "$name" in ''|\#*) continue;; esac
    AUTH=(-H "Authorization: Bearer $TOK"); BODY=()
    case "$rest" in sans-jeton*) AUTH=(); rest=${rest#sans-jeton}; rest=${rest# };; esac
    # « POST <corps JSON> » : une requête d'écriture dont on attend le REFUS (401, 400, 403…), jamais une écriture réussie.
    # « {{unique}} » dans le corps : une valeur neuve à chaque passage (16/11/2026 : une adresse inconnue toujours la même butait sur la
    # limite de 3 demandes par heure et par adresse de « mot de passe oublié » quand les photos se suivaient)
    case "$rest" in POST\ *) rest=${rest//\{\{unique\}\}/$(date +%s%N)}; BODY=(-X POST -H 'Content-Type: application/json' -d "${rest#POST }");; esac
    curl -s -o "$OUT/$name.json" -w '%{http_code}\n' "${H[@]}" "${AUTH[@]}" "${BODY[@]}" "$BASE_URL$path" > "$OUT/$name.code"
  done < "$READS"
  echo "photo $2 : $(ls "$OUT" | grep -c '\.code$') lecture(s), codes : $(cat "$OUT"/*.code | sort | uniq -c | tr -s ' ' | tr '\n' ';') 5xx : $(cat "$OUT"/*.code | grep -c '^5')"
  sites
  ;;
comparer)
  python3 "$HERE/comparer.py" "$PHOTO_DIR/$2" "$PHOTO_DIR/$3" ${4:+"$4"}
  ;;
switch)
  number "${3:-}"; lock; clean_tree "$2"
  [ -d "$L/var/swap_stage$3/dev" ] || { echo "CACHE ABSENT : lancer d'abord « build »"; exit 1; }
  worker_email "avant"
  echo "avant : $(git log -1 --format=%h), page publique $(curl -s -o /dev/null -w '%{http_code}' "${H[@]}" "$BASE_URL/api/landingpage-settings")"
  ea_assets avant
  # Laisser finir la requête de contrôle ci-dessus AVANT l'échange : sa fin de traitement charge encore des fichiers du cache sortant
  pause 2
  link_old_container "$3"
  date -u +%FT%TZ > "$L/var/bascule.heure"; T0=$(date +%s.%N)
  # lot TACHES-0 : le vendor et public/bundles d'abord (composer.lock changé), puis le code et le cache ; l'ancien vendor reste dans
  # var/vendor_stage<n>, l'ancien public/bundles dans var/bundles_stage<n>
  if [ -n "$(git diff --name-only HEAD "$2" -- composer.lock)" ]; then
    [ -f "$L/var/vendor_stage$3/autoload.php" ] || { echo "COMPOSER.LOCK CHANGÉ ET VENDOR PRÉPARÉ ABSENT : lancer « vendor » puis « build »"; exit 1; }
    [ -d "$L/var/bundles_stage$3/easyadmin" ] || { echo "COMPOSER.LOCK CHANGÉ ET public/bundles PRÉPARÉ ABSENT : relancer « build » (rien n'a bougé)"; exit 1; }
    exchange_packages "$3" || exit 1
  fi
  git merge -q --ff-only "$2" && exchange "$3" || { echo "ÉCHEC DE LA BASCULE : vérifier l'état (git log -1, var/cache/dev) avant toute autre commande"; exit 1; }
  echo "bascule en $(awk -v a="$T0" -v b="$(date +%s.%N)" 'BEGIN{printf "%.2f", b-a}') s à $(cat "$L/var/bascule.heure") UTC ; retour arrière : « retour $(git rev-parse --short "HEAD@{1}") $3 »"
  reload_fpm
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
  ea_assets avant
  link_old_container "$3"
  date -u +%FT%TZ > "$L/var/bascule.heure"; T0=$(date +%s.%N)
  # lot TACHES-0 : le composer.lock revient en arrière ? l'ancien vendor attend dans var/vendor_stage<n> et l'ancien public/bundles dans
  # var/bundles_stage<n> depuis l'échange
  if [ -n "$(git diff --name-only "$2" HEAD -- composer.lock)" ]; then
    [ -f "$L/var/vendor_stage$3/autoload.php" ] || { echo "VENDOR D'AVANT ABSENT : var/vendor_stage$3 (rien n'a bougé)"; exit 1; }
    [ -d "$L/var/bundles_stage$3/easyadmin" ] || { echo "public/bundles D'AVANT ABSENT : var/bundles_stage$3 (rien n'a bougé)"; exit 1; }
    exchange_packages "$3" || exit 1
  fi
  git reset -q --hard "$2" && exchange "$3" || { echo "ÉCHEC DU RETOUR ARRIÈRE : vérifier l'état (git log -1, var/cache/dev)"; exit 1; }
  echo "retour arrière en $(awk -v a="$T0" -v b="$(date +%s.%N)" 'BEGIN{printf "%.2f", b-a}') s à $(cat "$L/var/bascule.heure") UTC : $(git log -1 --format=%h) ; le cache remis en avant reste dans var/swap_stage$3 (pour revenir : « switch <commit> $3 »)"
  reload_fpm
  restart_workers
  after_checks
  echo "si l'authentification reste en échec : regarder les montages du conteneur, puis docker restart $APP (docs/architecture.md, « Points de montage »)"
  ;;
logs)
  [ -n "${2:-}" ] || set -- logs "$(cat "$L/var/bascule.heure" 2>/dev/null)"
  [ -n "${2:-}" ] || { echo "HEURE ATTENDUE : logs <ISO UTC>, ou var/bascule.heure écrit par switch ou retour"; exit 1; }
  set -- logs "${2%Z}" # heure écrite avec « Z » depuis le lot CACHE-ETAT : le journal se compare sans lui
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
  sed -n '2,18p' "$HERE/$(basename "$0")"; exit 1
  ;;
esac
