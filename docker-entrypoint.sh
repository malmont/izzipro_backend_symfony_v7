#!/bin/bash
set -e

APP_DIR="/home/app"

# ✅ Génération du fichier .env si nécessaire
if [ ! -f "$APP_DIR/.env" ]; then
  echo "📄 Génération du fichier .env..."
  bash "$APP_DIR/generate-env.sh"
fi

# ✅ Installation des dépendances
if [ ! -d "$APP_DIR/vendor" ]; then
  echo "📦 Installation des dépendances..."
  composer install --no-interaction --optimize-autoloader
fi

# ✅ Donner les permissions
chown -R www-data:www-data "$APP_DIR/var"
chmod -R 775 "$APP_DIR/var"

# ✅ Exécuter les migrations
php bin/console doctrine:migrations:migrate --no-interaction

# ✅ Installer les assets
php bin/console assets:install --symlink

# ✅ Nettoyer le cache Symfony
php -d memory_limit=-1 bin/console cache:clear

# ✅ Lancer PHP-FPM
php-fpm
