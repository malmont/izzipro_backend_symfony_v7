#!/bin/bash

set -e

# ✅ Générer le .env si nécessaire
if [ ! -f .env ]; then
  echo "⚙️ Génération automatique du .env..."
  /usr/local/bin/generate-env.sh
fi

# ✅ Installer les dépendances si nécessaires
if [ ! -d "vendor" ]; then
  echo "📦 Installation des dépendances..."
  composer install --no-interaction --optimize-autoloader
fi

# ✅ Donner les permissions au dossier var
echo "🔑 Correction des permissions sur var/"
chown -R www-data:www-data /var/www/var
chmod -R 775 /var/www/var

# ✅ Exécuter les migrations
php bin/console doctrine:migrations:migrate --no-interaction

# ✅ Installer les assets
php bin/console assets:install --symlink

# ✅ Correction des permissions sur le dossier public
chmod -R 775 public/

# ✅ Nettoyer le cache Symfony
php -d memory_limit=-1 bin/console cache:clear

# ✅ Démarrer PHP-FPM
php-fpm
