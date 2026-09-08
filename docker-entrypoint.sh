#!/bin/bash
set -e

# # ✅ Génération du fichier .env si nécessaire
# if [ ! -f ".env" ]; then
#   echo "📄 Génération du fichier .env..."
#   bash generate-env.sh
# fi

# ✅ Installation des dépendances
if [ ! -d "vendor" ]; then
  echo "📦 Installation des dépendances..."
  composer install --no-interaction --optimize-autoloader
fi

# ✅ Donner les permissions
chown -R www-data:www-data var
chmod -R 775 var
mkdir -p public/uploads/memoires public/uploads/audio public/uploads/covers public/uploads/documents public/uploads/customization var/uploads
mkdir -p public/assets/uploads/{slider,products,Carrier,customization,options,icons,email-logos,team,explore,categories} public/assets/images
chown -R www-data:www-data public/uploads var/uploads public/assets/uploads public/assets/images public/bundles
chmod -R 777 public/uploads var/uploads public/assets/uploads public/assets/images public/bundles

# ✅ Exécuter les migrations
php bin/console doctrine:migrations:migrate --no-interaction

# ✅ Installer les assets
php bin/console assets:install --symlink

# ✅ Nettoyer le cache Symfony
php -d memory_limit=-1 bin/console cache:clear

# ✅ Lancer PHP-FPM
php-fpm
