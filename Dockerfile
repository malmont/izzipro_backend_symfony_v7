# =================================================================
# ÉTAPE 1 : Le "Builder" - pour construire les dépendances
# =================================================================
FROM php:8.3-fpm AS builder

# Installer les dépendances système nécessaires UNIQUEMENT pour la construction
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install pdo pdo_pgsql zip intl \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Définir le dossier de travail
WORKDIR /var/www

# 1. Copier les fichiers composer
COPY composer.json composer.lock ./

# 2. Installer les dépendances SANS exécuter les scripts pour profiter du cache Docker
RUN composer install --prefer-dist --no-dev --no-autoloader --no-scripts

# 3. Copier tout le code de l'application
COPY . .

# 4. Générer l'autoloader (nécessaire pour dump-env)
RUN composer dump-autoload --optimize --no-dev

# 5. Compiler l'environnement prod (génère .env.local.php)
RUN composer dump-env prod

# 6. Exécuter les scripts Composer avec Dotenv désactivé et env explicite
RUN APP_NO_DOTENV=1 APP_ENV=prod APP_DEBUG=0 composer run-script post-install-cmd


# =================================================================
# ÉTAPE 2 : L'Image Finale - optimisée pour la production
# =================================================================
FROM php:8.3-fpm

# Installer UNIQUEMENT les extensions PHP nécessaires à l'exécution
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    && docker-php-ext-install pdo pdo_pgsql zip intl opcache \
    && pecl install redis && docker-php-ext-enable redis \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Configurer PHP : LIMITE DE MÉMOIRE SÉCURISÉE
RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/memory-limit.ini

# Configurer OPCache pour de meilleures performances
RUN echo "opcache.enable=1\n\
opcache.memory_consumption=128\n\
opcache.interned_strings_buffer=8\n\
opcache.max_accelerated_files=10000\n\
opcache.validate_timestamps=0" > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Modifier la configuration www.conf pour écouter sur toutes les interfaces
RUN sed -i "s|listen = 127.0.0.1:9000|listen = 0.0.0.0:9000|g" /usr/local/etc/php-fpm.d/www.conf

# Optimiser la configuration PHP-FPM
RUN echo "\n; Optimisation du pool PHP-FPM\n\
pm = dynamic\n\
pm.max_children = 50\n\
pm.start_servers = 10\n\
pm.min_spare_servers = 5\n\
pm.max_spare_servers = 15\n" >> /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www

# Copier le code et les dépendances depuis l'étape "builder"
COPY --from=builder /var/www .

# Configurer les permissions
RUN mkdir -p /var/www/var /var/www/config/jwt \
    && chown -R www-data:www-data /var/www/var /var/www/config/jwt \
    && chmod -R 755 /var/www/var /var/www/config/jwt

# Exécuter avec un utilisateur non-root
USER www-data

# Lancer PHP-FPM
CMD ["php-fpm"]
