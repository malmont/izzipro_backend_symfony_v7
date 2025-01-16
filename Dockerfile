# 📦 Utilise PHP 8.3 avec FPM
FROM php:8.3-fpm

# 🔧 Installer les dépendances système nécessaires
RUN apt-get update && apt-get install -y \
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

# 📦 Installer Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 📂 Définir le dossier de travail
WORKDIR /var/www

# 📄 Copier uniquement composer.json et composer.lock pour profiter du cache Docker
COPY composer.json composer.lock ./

# 📥 Installer les dépendances sans exécuter les scripts
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-progress

# 📂 Copier les fichiers restants du projet
COPY . .

# 🚀 Générer l'autoloader optimisé
RUN composer dump-autoload --optimize

# 🛠️ Copier et rendre exécutables les scripts
COPY generate-env.sh /usr/local/bin/generate-env.sh
RUN chmod +x /usr/local/bin/generate-env.sh

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# 📁 Créer le dossier var avec les bonnes permissions
RUN mkdir -p /var/www/var \
    && chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www

# 🚀 Lancer le script d'initialisation au démarrage
CMD ["/usr/local/bin/docker-entrypoint.sh"]
