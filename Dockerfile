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

# Définir le dossier de travail
WORKDIR /home/app

# Copier les fichiers du projet
COPY . /home/app/

# ✅ Créer le dossier var
RUN mkdir -p /home/app/var \
    && chown -R www-data:www-data /home/app/var \
    && chmod -R 775 /home/app/var

# # ✅ Lancer le script d'initialisation
# CMD ["bash", "/home/app/docker-entrypoint.sh"]
