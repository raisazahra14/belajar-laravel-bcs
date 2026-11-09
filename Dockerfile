FROM php:8.2-fpm-bookworm

# Set working directory
WORKDIR /var/www

# Install system dependencies, Python 3, Tesseract OCR, and media libraries
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    libgl1 \
    libglib2.0-0 \
    python3 \
    python3-pip \
    python3-venv \
    tesseract-ocr \
    tesseract-ocr-eng \
    tesseract-ocr-ind \
    default-mysql-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions required by Laravel
RUN docker-php-ext-install pdo_mysql mbstring zip exif pcntl bcmath gd opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Setup Python virtual environment
RUN python3 -m venv /var/www/python/.venv \
    && /var/www/python/.venv/bin/pip install --no-cache-dir --upgrade pip

# Copy existing application directory contents
COPY . /var/www

# Install Python dependencies inside virtual environment
RUN if [ -f /var/www/python/requirements.txt ]; then \
        /var/www/python/.venv/bin/pip install --no-cache-dir -r /var/www/python/requirements.txt; \
    fi

# Set permissions for Laravel storage and cache directories
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache \
    && chmod -R 775 /var/www/storage /var/www/bootstrap/cache

# Copy PHP settings
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Expose port 9000 for PHP-FPM
EXPOSE 9000

# Copy entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
