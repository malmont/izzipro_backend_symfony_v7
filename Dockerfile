# =========================================================================
# ÉTAGE 1: BUILDER - Installation des dépendances Composer
# =========================================================================
FROM composer:2 as builder

WORKDIR /app

# Copier uniquement les fichiers de dépendances
COPY composer.json composer.lock ./

# Installer les dépendances en mode production optimisé
RUN composer install --prefer-dist --no-dev --no-scripts --optimize-autoloader


# =========================================================================
# ÉTAGE 2: FINAL - Construction de l'image de production
# =========================================================================
FROM php:8.3-fpm

# 🔧 Installer les dépendances système nécessaires
RUN apt-get update && apt-get install -y \
    iputils-ping \
    net-tools \
    curl \
    libfcgi-bin \
    git \
    unzip \
    libpq-dev \
    postgresql-client \
    libzip-dev \
    zip \
    libicu-dev \
 && docker-php-ext-install pdo pdo_pgsql zip intl opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# ✅ Configurer PHP pour la production
RUN echo "memory_limit=-1" > /usr/local/etc/php/conf.d/memory-limit.ini \
 && printf "opcache.enable=1\nopcache.memory_consumption=128\nopcache.interned_strings_buffer=8\nopcache.max_accelerated_files=10000\nopcache.validate_timestamps=0\n" > /usr/local/etc/php/conf.d/opcache-recommended.ini \
 && echo "variables_order=EGPCS" > /usr/local/etc/php/conf.d/zzz-env.ini

# ✅ PHP-FPM: écoute local + garder les env
RUN sed -i "s|listen = 127.0.0.1:9000|listen = 127.0.0.1:9000|g" /usr/local/etc/php-fpm.d/www.conf \
 && sed -i 's|^;*clear_env = .*|clear_env = no|' /usr/local/etc/php-fpm.d/www.conf

# 📂 Définir le dossier de travail
WORKDIR /var/www

# 📄 Copier les dépendances depuis l'étage "builder"
COPY --from=builder /app/vendor/ ./vendor/

# 📂 Copier TOUT le code de l'application (incluant /public/assets/master_files)
COPY . .

# 🔧 Configurer les permissions
RUN mkdir -p /var/www/config/jwt \
    && mkdir -p /var/www/var \
    && chown -R www-data:www-data /var/www

# ✅ Préparer l'environnement de production
RUN composer dump-env prod --empty \
 && composer dump-autoload --optimize --classmap-authoritative

# ✅ Exécuter avec un utilisateur non-root
USER www-data

# 🚀 Lancer PHP-FPM directement
CMD ["php-fpm"]