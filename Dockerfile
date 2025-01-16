# Utilise PHP 8.3 avec FPM
FROM php:8.3-fpm

# Installer les dépendances système nécessaires
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    zip \
    libicu-dev \
    && docker-php-ext-install pdo pdo_pgsql zip intl

# ✅ Augmenter la mémoire PHP
RUN echo "memory_limit=-1" > /usr/local/etc/php/conf.d/memory-limit.ini

# Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ✅ Définir le dossier de travail cohérent avec docker-compose
WORKDIR /var/www

# ✅ Copier le projet Symfony dans /var/www
COPY . .

# ✅ Copier le fichier .env avant l'installation de composer
COPY .env.test /var/www/.env

# ✅ Installer les dépendances Symfony
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# ✅ Fixer les permissions sur var et vendor
RUN mkdir -p /var/www/var \
    && chown -R www-data:www-data /var/www/var /var/www/vendor \
    && chmod -R 775 /var/www/var /var/www/vendor

# ✅ Lancer le script d'initialisation si besoin
CMD ["php-fpm"]
