FROM php:8.2-cli-alpine

# Instalação de dependências do sistema e utilitários
RUN apk add --no-cache \
    bash \
    curl \
    git \
    ffmpeg \
    openssl \
    libpng-dev \
    libzip-dev

# Extensões essenciais do PHP
RUN docker-php-ext-install pcntl bcmath

WORKDIR /app

# Estrutura inicial de storage
RUN mkdir -p /app/storage/tmp && chmod 0700 /app/storage /app/storage/tmp

COPY . /app

CMD ["php", "daemon_telegram.php"]
