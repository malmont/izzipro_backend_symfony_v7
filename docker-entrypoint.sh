#!/bin/bash
set -e

# ✅ Génération du fichier .env si nécessaire
if [ ! -f /var/www/.env ]; then
  echo "📄 Génération du fichier .env..."
  /usr/local/bin/generate-env.sh
fi

# ✅ Installation des dépendances
if [ ! -d "vendor" ]; then
  echo "📦 Installation des dépendances..."
  composer install --no-interaction --optimize-autoloader
fi

# ✅ Donner les permissions
chown -R www-data:www-data /var/www/var
chmod -R 775 /var/www/var

# ✅ Exécuter les migrations
php bin/console doctrine:migrations:migrate --no-interaction

# ✅ Installer les assets
php bin/console assets:install --symlink

# ✅ Nettoyer le cache Symfony
php -d memory_limit=-1 bin/console cache:clear

# ✅ Lancer PHP-FPM
php-fpm
