FROM dunglas/frankenphp:1-php8.4-alpine AS base
WORKDIR /app
# PostgreSQL driver for Supabase; curl for Supabase Storage; the rest are used by the app
RUN install-php-extensions pdo_pgsql curl mbstring fileinfo
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'upload_max_filesize = 3M'; \
      echo 'post_max_size = 8M'; \
      echo 'date.timezone = Asia/Manila'; \
      echo 'expose_php = Off'; \
    } > "$PHP_INI_DIR/conf.d/zz-clinic.ini"

FROM base AS runtime
COPY Caddyfile /etc/caddy/Caddyfile
RUN rm -rf /app/public/*
COPY public ./public
ENV PORT=80
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
