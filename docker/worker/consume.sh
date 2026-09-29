#!/bin/sh
# Démarrage d'un worker Messenger (voir docker-compose.yml).
#
# Le code est monté en direct et l'application tourne en APP_ENV=dev : l'application web reconstruit son cache
# (var/cache/dev) dès qu'un fichier change et supprime l'ancien conteneur de services. Un worker qui partageait ce
# cache plantait alors au chargement du service suivant (« require(...var/cache/dev/Container.../get...Service.php):
# Failed to open stream »), d'où les redémarrages manuels. Chaque worker a donc son propre cache (APP_CACHE_DIR, dans
# le conteneur), reconstruit à chaque démarrage : il repart toujours sur le code à jour. Docker relance le worker
# quand il s'arrête (--time-limit, --limit, --memory-limit ou messenger:stop-workers), toujours entre deux messages.
set -e
rm -rf "${APP_CACHE_DIR:?APP_CACHE_DIR non défini}"
php bin/console cache:warmup --no-interaction >/dev/null
exec php bin/console messenger:consume "$@"
