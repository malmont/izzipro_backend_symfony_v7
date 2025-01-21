# 📦 Utilise PHP 8.3 avec FPM
FROM php:8.3-fpm

# 🔧 Installer les dépendances système nécessaires
RUN apt-get update && apt-get install -y \
    iputils-ping \      
    net-tools \          
    curl \  
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    zip \
    libicu-dev \
    && docker-php-ext-install pdo pdo_pgsql zip intl opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# ✅ Configurer PHP : mémoire illimitée
RUN echo "memory_limit=-1" > /usr/local/etc/php/conf.d/memory-limit.ini

# ✅ Configurer OPCache pour de meilleures performances
RUN echo "opcache.enable=1\n\
opcache.memory_consumption=128\n\
opcache.interned_strings_buffer=8\n\
opcache.max_accelerated_files=10000\n\
opcache.validate_timestamps=0" > /usr/local/etc/php/conf.d/opcache-recommended.ini

# ✅ Modifier la configuration www.conf pour écouter sur toutes les interfaces
RUN sed -i "s|listen = 127.0.0.1:9000|listen = 0.0.0.0:9000|g" /usr/local/etc/php-fpm.d/www.conf

# 📦 Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 📂 Définir le dossier de travail
WORKDIR /var/www

# 📄 Copier uniquement composer.json et composer.lock pour profiter du cache Docker
COPY composer.json composer.lock ./

# 📥 Installer les dépendances avec autoload optimisé
RUN composer install --prefer-dist --no-dev --optimize-autoloader --no-progress --no-scripts

# 📂 Copier les fichiers restants du projet
COPY . .

# 📁 Créer le dossier var avec les bonnes permissions
RUN mkdir -p /var/www/var \
    && chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www

# ✅ Exécuter avec un utilisateur non-root
USER www-data

# 🚀 Lancer PHP-FPM directement
CMD ["php-fpm"]
