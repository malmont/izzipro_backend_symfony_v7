#!/usr/bin/env bash
# Construction de l'image de l'application (lot PHP84, 20/11/2026), à la place de `docker build .` dans l'arbre en ligne.
#
#   ops/image/construire-image.sh <étiquette neuve> [<commit>] [<version PHP attendue>]
#
# - Avec <commit> : les fichiers de ce commit (git archive) ; sans : les fichiers suivis et non ignorés de la copie de
#   travail courante (essais avant commit).
# - Le contexte est posé dans un dossier NEUF, puis construit par `docker build <dossier>` : .dockerignore s'y applique
#   (il ne s'applique PAS à un tar passé sur l'entrée standard). jwt/, config/jwt/ et tout fichier .env* sont retirés dès
#   l'export ; une garde refuse ensuite le contexte s'il reste un fichier d'une famille de secrets (mêmes familles que
#   .dockerignore et verifier-image.sh).
# - L'image n'est gardée que si ops/image/verifier-image.sh la déclare conforme ; sinon elle est supprimée.
# - L'étiquette doit être NEUVE et jamais `latest` : une image refusée est supprimée, ce qui ne doit jamais emporter une
#   étiquette en service. La pose de `latest` est une étape à part de la mise en service (docs/mise-en-ligne.md).
set -euo pipefail

TAG="${1:?usage : construire-image.sh <étiquette neuve> [<commit>] [<version PHP>]}"
COMMIT="${2:-}"
PHP_ATTENDU="${3:-}"
ICI="$(cd "$(dirname "$0")" && pwd)"
RACINE="$(git -C "$ICI" rev-parse --show-toplevel)"

[[ "$TAG" == *:* ]] || { echo "ÉTIQUETTE REFUSÉE : donner un nom et une version (ex. app_v2-app:php84-20261120)"; exit 1; }
[[ "${TAG##*:}" != latest ]] || { echo "ÉTIQUETTE REFUSÉE : jamais « latest » ici"; exit 1; }
if docker image inspect "$TAG" >/dev/null 2>&1; then
  echo "ÉTIQUETTE REFUSÉE : $TAG existe déjà (une image refusée serait supprimée avec elle)"; exit 1
fi

CTX="$(mktemp -d /tmp/image-app_v2.XXXXXX)"
nettoyer() { rm -rf -- "${CTX:?}"; }
trap nettoyer EXIT

if [ -n "$COMMIT" ]; then
  git -C "$RACINE" archive --format=tar "$COMMIT" \
    | tar -x -C "$CTX" --exclude='jwt' --exclude='config/jwt' --exclude='.env' --exclude='.env.*' --exclude='*/.env' --exclude='*/.env.*'
else
  ( cd "$RACINE" && git ls-files -co --exclude-standard -z | grep -zvE '^(jwt|config/jwt)/|(^|/)\.env' | tar --null -T - -cf - ) \
    | tar -x -C "$CTX"
fi

# Garde avant envoi à Docker, même si .dockerignore venait à changer. Résultat lu dans une variable : sous pipefail, un
# `find | grep -q` peut échouer par SIGPIPE et laisser passer (relecture du lot).
secrets=$(find "$CTX" -path "$CTX/vendor" -prune -o \( \
    -name '.env' -o -name '.env.*' -o -name '.envrc' -o -name '*.pem' -o -name '*.key' -o -name '*.p8' -o -name '*.p12' \
    -o -name '*.pfx' -o -name '*.jks' -o -name 'id_rsa*' -o -name 'id_ed25519*' -o -name 'id_ecdsa*' -o -name 'auth.json' \
    -o -name '*credentials*.json' -o -name '*service-account*.json' -o -path '*/config/secrets' -o -path "$CTX/jwt" \
    -o -path "$CTX/config/jwt" \) -print)
if [ -n "$secrets" ]; then
  echo "CONTEXTE REFUSÉ : fichier(s) d'une famille de secrets dans le contexte de construction :"
  sed "s#^$CTX/#  #" <<<"$secrets"
  exit 1
fi

docker build --pull -t "$TAG" "$CTX"

if ! "$ICI/verifier-image.sh" "$TAG" "$PHP_ATTENDU"; then
  docker image rm "$TAG" >/dev/null
  echo "Image $TAG supprimée : non conforme"; exit 1
fi
