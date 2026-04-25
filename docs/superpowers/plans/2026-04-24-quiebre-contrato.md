# Quiebre del Contrato — Plan de Implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir el módulo Quiebre del Contrato — API REST Laravel 11 + frontend HTML/CSS/JS para gestionar jerarquía de ítems de contrato (Áreas → Subáreas → Sistemas → Subsistemas), con carga Excel, roles y auditoría completa.

**Architecture:** Laravel 11 instalado en `backend/`. API REST con Sanctum token auth. Frontend HTML/JS en `backend/public/app/` haciendo fetch() a `/api`. MySQL en producción, SQLite en memoria para tests.

**Tech Stack:** PHP 8.2+, Laravel 11, laravel/sanctum, maatwebsite/excel 3.1, PHPUnit 11, MySQL 8+

**Spec:** `docs/superpowers/specs/2026-04-24-quiebre-contrato-design.md`

---

## Mapa de archivos

### Backend (`backend/`)
```
backend/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── ProyectoController.php
│   │   │   ├── AreaController.php
│   │   │   ├── SubareaController.php
│   │   │   ├── SistemaController.php
│   │   │   ├── SubsistemaController.php
│   │   │   ├── ImportController.php
│   │   │   └── Admin/
│   │   │       ├── UsuarioController.php
│   │   │       ├── TipoUsuarioController.php
│   │   │       ├── AsignacionController.php
│   │   │       └── LogController.php
│   │   └── Middleware/
│   │       ├── CheckRole.php
│   │       └── CheckProyectoAccess.php
│   ├── Models/
│   │   ├── Usuario.php
│   │   ├── TipoUsuario.php
│   │   ├── Proyecto.php
│   │   ├── UsuarioProyecto.php
│   │   ├── Area.php
│   │   ├── Subarea.php
│   │   ├── Sistema.php
│   │   └── Subsistema.php
│   └── Services/
│       └── LogService.php
├── database/
│   ├── migrations/   (15 archivos)
│   └── seeders/
│       └── SuperuserSeeder.php
├── routes/
│   └── api.php
└── tests/Feature/
    ├── AuthTest.php
    ├── ProyectoTest.php
    ├── JerarquiaTest.php
    ├── ImportTest.php
    └── AdminTest.php
```

### Frontend (`backend/public/`)
```
backend/public/
├── app/
│   ├── login.html
│   ├── selector-proyecto.html
│   ├── selector-app.html
│   ├── quiebre.html
│   └── superuser.html
└── assets/
    ├── css/
    │   └── app.css
    └── js/
        ├── api.js
        ├── login.js
        ├── selector-proyecto.js
        ├── selector-app.js
        ├── quiebre.js
        └── superuser.js
```

---

## Task 1: Instalación Laravel + Configuración base

**Files:**
- Create: `backend/` (proyecto Laravel)
- Modify: `backend/.env`
- Modify: `backend/config/auth.php`
- Modify: `backend/bootstrap/app.php`
- Modify: `backend/phpunit.xml`

- [ ] **Step 1.1: Instalar Laravel en `backend/`**

Ejecutar desde la raíz del repo:
```bash
composer create-project laravel/laravel backend
cd backend
composer require laravel/sanctum
composer require maatwebsite/excel
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
```

Esperado: directorio `backend/` con Laravel y dependencias instaladas.

- [ ] **Step 1.2: Configurar `.env` para MySQL**

Editar `backend/.env`:
```env
APP_NAME=AppHub
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=apphub
DB_USERNAME=root
DB_PASSWORD=

SANCTUM_STATEFUL_DOMAINS=localhost
SESSION_DRIVER=cookie
```

- [ ] **Step 1.3: Cambiar provider de auth a modelo Usuario**

Editar `backend/config/auth.php`, sección `providers`:
```php
'providers' => [
    'users' => [
        'driver' => 'eloquent',
        'model' => App\Models\Usuario::class,
    ],
],
```

- [ ] **Step 1.4: Registrar middleware y rate limiter**

Reemplazar `backend/bootstrap/app.php` completo:
```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        $middleware->alias([
            'check.role'    => \App\Http\Middleware\CheckRole::class,
            'check.project' => \App\Http\Middleware\CheckProyectoAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->booted(function () {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    })
    ->create();
```

- [ ] **Step 1.5: Configurar PHPUnit con SQLite en memoria**

Editar `backend/phpunit.xml`, agregar dentro de `<php>`:
```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
<env name="BCRYPT_ROUNDS" value="4"/>
```

- [ ] **Step 1.6: Eliminar migraciones por defecto de Laravel**

```bash
cd backend
rm database/migrations/0001_01_01_000000_create_users_table.php
rm database/migrations/0001_01_01_000001_create_cache_table.php
rm database/migrations/0001_01_01_000002_create_jobs_table.php
```

Esperado: directorio `database/migrations/` vacío.

- [ ] **Step 1.7: Crear la base de datos MySQL y generar key**

```bash
mysql -u root -p -e "CREATE DATABASE apphub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
php artisan key:generate
php artisan serve
```

Esperado: servidor corriendo en `http://127.0.0.1:8000`, respuesta JSON `{"message":"Unauthenticated."}` en `http://127.0.0.1:8000/api/user`.

- [ ] **Step 1.8: Commit**

```bash
cd ..
git add backend/
git commit -m "feat: install Laravel 11 with Sanctum and Excel packages"
```

---

## Task 2: Migraciones de base de datos (15 tablas)

**Files:**
- Create: `backend/database/migrations/2026_04_24_000001_create_usuarios_table.php`
- Create: `backend/database/migrations/2026_04_24_000002_create_tipos_usuario_table.php`
- Create: `backend/database/migrations/2026_04_24_000003_create_proyectos_table.php`
- Create: `backend/database/migrations/2026_04_24_000004_create_usuarios_proyectos_table.php`
- Create: `backend/database/migrations/2026_04_24_000005_create_areas_table.php`
- Create: `backend/database/migrations/2026_04_24_000006_create_subareas_table.php`
- Create: `backend/database/migrations/2026_04_24_000007_create_sistemas_table.php`
- Create: `backend/database/migrations/2026_04_24_000008_create_subsistemas_table.php`
- Create: `backend/database/migrations/2026_04_24_000009_create_areas_log_table.php`
- Create: `backend/database/migrations/2026_04_24_000010_create_subareas_log_table.php`
- Create: `backend/database/migrations/2026_04_24_000011_create_sistemas_log_table.php`
- Create: `backend/database/migrations/2026_04_24_000012_create_subsistemas_log_table.php`
- Create: `backend/database/migrations/2026_04_24_000013_create_proyectos_log_table.php`
- Create: `backend/database/migrations/2026_04_24_000014_create_usuarios_log_table.php`
- Create: `backend/database/migrations/2026_04_24_000015_create_usuarios_proyectos_log_table.php`

- [ ] **Step 2.1: Crear migración `usuarios`**

`backend/database/migrations/2026_04_24_000001_create_usuarios_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->enum('rol_global', ['superuser', 'admin', 'usuario'])->default('usuario');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
```

- [ ] **Step 2.2: Crear migración `tipos_usuario`**

`backend/database/migrations/2026_04_24_000002_create_tipos_usuario_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tipos_usuario', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_usuario');
    }
};
```

- [ ] **Step 2.3: Crear migración `proyectos`**

`backend/database/migrations/2026_04_24_000003_create_proyectos_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('proyectos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nombre');
            $table->enum('estado', ['activo', 'archivado'])->default('activo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyectos');
    }
};
```

- [ ] **Step 2.4: Crear migración `usuarios_proyectos`**

`backend/database/migrations/2026_04_24_000004_create_usuarios_proyectos_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios_proyectos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->foreignId('proyecto_id')->constrained('proyectos')->onDelete('cascade');
            $table->enum('rol', ['admin', 'usuario'])->default('usuario');
            $table->foreignId('tipo_id')->nullable()->constrained('tipos_usuario')->nullOnDelete();
            $table->timestamps();
            $table->unique(['usuario_id', 'proyecto_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_proyectos');
    }
};
```

- [ ] **Step 2.5: Crear migración `areas`**

`backend/database/migrations/2026_04_24_000005_create_areas_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->onDelete('cascade');
            $table->string('codigo', 50);
            $table->string('nombre');
            $table->integer('orden')->default(0);
            $table->timestamps();
            $table->unique(['proyecto_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas');
    }
};
```

- [ ] **Step 2.6: Crear migración `subareas`**

`backend/database/migrations/2026_04_24_000006_create_subareas_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subareas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->onDelete('cascade');
            $table->foreignId('area_id')->constrained('areas')->onDelete('cascade');
            $table->string('codigo', 50);
            $table->string('nombre');
            $table->integer('orden')->default(0);
            $table->timestamps();
            $table->unique(['proyecto_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subareas');
    }
};
```

- [ ] **Step 2.7: Crear migración `sistemas`**

`backend/database/migrations/2026_04_24_000007_create_sistemas_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sistemas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->onDelete('cascade');
            $table->foreignId('subarea_id')->constrained('subareas')->onDelete('cascade');
            $table->string('codigo', 50);
            $table->string('nombre');
            $table->integer('orden')->default(0);
            $table->timestamps();
            $table->unique(['proyecto_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistemas');
    }
};
```

- [ ] **Step 2.8: Crear migración `subsistemas`**

`backend/database/migrations/2026_04_24_000008_create_subsistemas_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsistemas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->onDelete('cascade');
            $table->foreignId('sistema_id')->constrained('sistemas')->onDelete('cascade');
            $table->string('codigo', 50);
            $table->string('nombre');
            $table->integer('orden')->default(0);
            $table->timestamps();
            $table->unique(['proyecto_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subsistemas');
    }
};
```

- [ ] **Step 2.9: Crear las 7 tablas de log**

Las tablas de log no tienen FK constraints (no queremos que borrar un registro borre su historial de auditoría).

`backend/database/migrations/2026_04_24_000009_create_areas_log_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('areas_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proyecto_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->enum('accion', ['CREATE','UPDATE','DELETE','IMPORT','IMPORT_ERROR_DISMISSED','VALIDATION_ERROR']);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->json('error_detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('areas_log');
    }
};
```

`backend/database/migrations/2026_04_24_000010_create_subareas_log_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subareas_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proyecto_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->enum('accion', ['CREATE','UPDATE','DELETE','IMPORT','IMPORT_ERROR_DISMISSED','VALIDATION_ERROR']);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->json('error_detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subareas_log');
    }
};
```

`backend/database/migrations/2026_04_24_000011_create_sistemas_log_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sistemas_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proyecto_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->enum('accion', ['CREATE','UPDATE','DELETE','IMPORT','IMPORT_ERROR_DISMISSED','VALIDATION_ERROR']);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->json('error_detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sistemas_log');
    }
};
```

`backend/database/migrations/2026_04_24_000012_create_subsistemas_log_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subsistemas_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proyecto_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->enum('accion', ['CREATE','UPDATE','DELETE','IMPORT','IMPORT_ERROR_DISMISSED','VALIDATION_ERROR']);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->json('error_detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subsistemas_log');
    }
};
```

`backend/database/migrations/2026_04_24_000013_create_proyectos_log_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('proyectos_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proyecto_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->enum('accion', ['CREATE','UPDATE','DELETE','IMPORT','IMPORT_ERROR_DISMISSED','VALIDATION_ERROR']);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->json('error_detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proyectos_log');
    }
};
```

`backend/database/migrations/2026_04_24_000014_create_usuarios_log_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proyecto_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->enum('accion', ['CREATE','UPDATE','DELETE','IMPORT','IMPORT_ERROR_DISMISSED','VALIDATION_ERROR']);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->json('error_detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_log');
    }
};
```

`backend/database/migrations/2026_04_24_000015_create_usuarios_proyectos_log_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios_proyectos_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proyecto_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->enum('accion', ['CREATE','UPDATE','DELETE','IMPORT','IMPORT_ERROR_DISMISSED','VALIDATION_ERROR']);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->json('error_detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_proyectos_log');
    }
};
```

- [ ] **Step 2.10: Ejecutar migraciones y verificar**

```bash
cd backend
php artisan migrate
php artisan migrate:status
```

Esperado: las 15 tablas aparecen como `Ran` en el status. En MySQL:
```bash
mysql -u root -p apphub -e "SHOW TABLES;"
```
Debe mostrar las 15 tablas.

- [ ] **Step 2.11: Commit**

```bash
cd ..
git add backend/database/migrations/
git commit -m "feat: add 15 database migrations (8 main tables + 7 audit logs)"
```
