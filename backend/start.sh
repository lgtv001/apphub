#!/bin/bash

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link 2>/dev/null || true

exec php -S 0.0.0.0:${PORT:-8000} server.php
