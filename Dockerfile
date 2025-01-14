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

# ✅ Augmenter la mémoire PHP (correction de l'erreur Allowed memory size)
RUN echo "memory_limit=-1" > /usr/local/etc/php/conf.d/memory-limit.ini

# Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Définir le dossier de travail
WORKDIR /var/www

# Copier les fichiers du projet
COPY . .

# ✅ Créer le dossier var après la copie du projet
RUN mkdir -p /var/www/var \
    && chown -R www-data:www-data /var/www/var \
    && chmod -R 775 /var/www/var

# Lancer le script d'initialisation au démarrage
CMD ["bash", "/usr/local/bin/docker-entrypoint.sh"]
