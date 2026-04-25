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

---

## Task 3: Modelos Eloquent + Factories

**Files:**
- Delete: `backend/app/Models/User.php`
- Create: `backend/app/Models/Usuario.php`
- Create: `backend/app/Models/TipoUsuario.php`
- Create: `backend/app/Models/Proyecto.php`
- Create: `backend/app/Models/UsuarioProyecto.php`
- Create: `backend/app/Models/Area.php`
- Create: `backend/app/Models/Subarea.php`
- Create: `backend/app/Models/Sistema.php`
- Create: `backend/app/Models/Subsistema.php`
- Create: `backend/database/factories/UsuarioFactory.php`
- Create: `backend/database/factories/ProyectoFactory.php`
- Create: `backend/database/factories/AreaFactory.php`
- Create: `backend/database/factories/SubareaFactory.php`
- Create: `backend/database/factories/SistemaFactory.php`
- Create: `backend/database/factories/SubsistemaFactory.php`

- [ ] **Step 3.1: Eliminar modelo User.php por defecto**

```bash
cd backend
rm app/Models/User.php
```

- [ ] **Step 3.2: Crear modelo `Usuario`**

`backend/app/Models/Usuario.php`:
```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';

    protected $fillable = [
        'nombre',
        'email',
        'password_hash',
        'rol_global',
        'activo',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'activo'        => 'boolean',
        'password_hash' => 'hashed',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function proyectos()
    {
        return $this->belongsToMany(Proyecto::class, 'usuarios_proyectos', 'usuario_id', 'proyecto_id')
            ->withPivot('rol', 'tipo_id')
            ->withTimestamps();
    }

    public function asignaciones()
    {
        return $this->hasMany(UsuarioProyecto::class, 'usuario_id');
    }
}
```

- [ ] **Step 3.3: Crear modelo `TipoUsuario`**

`backend/app/Models/TipoUsuario.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoUsuario extends Model
{
    use HasFactory;

    protected $table = 'tipos_usuario';

    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
```

- [ ] **Step 3.4: Crear modelo `Proyecto`**

`backend/app/Models/Proyecto.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proyecto extends Model
{
    use HasFactory;

    protected $table = 'proyectos';

    protected $fillable = ['codigo', 'nombre', 'estado'];

    public function usuarios()
    {
        return $this->belongsToMany(Usuario::class, 'usuarios_proyectos', 'proyecto_id', 'usuario_id')
            ->withPivot('rol', 'tipo_id')
            ->withTimestamps();
    }

    public function asignaciones()
    {
        return $this->hasMany(UsuarioProyecto::class, 'proyecto_id');
    }

    public function areas()
    {
        return $this->hasMany(Area::class, 'proyecto_id')->orderBy('orden');
    }
}
```

- [ ] **Step 3.5: Crear modelo `UsuarioProyecto`**

`backend/app/Models/UsuarioProyecto.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuarioProyecto extends Model
{
    protected $table = 'usuarios_proyectos';

    protected $fillable = ['usuario_id', 'proyecto_id', 'rol', 'tipo_id'];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id');
    }

    public function tipo()
    {
        return $this->belongsTo(TipoUsuario::class, 'tipo_id');
    }
}
```

- [ ] **Step 3.6: Crear modelo `Area`**

`backend/app/Models/Area.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use HasFactory;

    protected $table = 'areas';

    protected $fillable = ['proyecto_id', 'codigo', 'nombre', 'orden'];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id');
    }

    public function subareas()
    {
        return $this->hasMany(Subarea::class, 'area_id')->orderBy('orden');
    }
}
```

- [ ] **Step 3.7: Crear modelo `Subarea`**

`backend/app/Models/Subarea.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subarea extends Model
{
    use HasFactory;

    protected $table = 'subareas';

    protected $fillable = ['proyecto_id', 'area_id', 'codigo', 'nombre', 'orden'];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id');
    }

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    public function sistemas()
    {
        return $this->hasMany(Sistema::class, 'subarea_id')->orderBy('orden');
    }
}
```

- [ ] **Step 3.8: Crear modelo `Sistema`**

`backend/app/Models/Sistema.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sistema extends Model
{
    use HasFactory;

    protected $table = 'sistemas';

    protected $fillable = ['proyecto_id', 'subarea_id', 'codigo', 'nombre', 'orden'];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id');
    }

    public function subarea()
    {
        return $this->belongsTo(Subarea::class, 'subarea_id');
    }

    public function subsistemas()
    {
        return $this->hasMany(Subsistema::class, 'sistema_id')->orderBy('orden');
    }
}
```

- [ ] **Step 3.9: Crear modelo `Subsistema`**

`backend/app/Models/Subsistema.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subsistema extends Model
{
    use HasFactory;

    protected $table = 'subsistemas';

    protected $fillable = ['proyecto_id', 'sistema_id', 'codigo', 'nombre', 'orden'];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id');
    }

    public function sistema()
    {
        return $this->belongsTo(Sistema::class, 'sistema_id');
    }
}
```

- [ ] **Step 3.10: Crear factories**

`backend/database/factories/UsuarioFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'nombre'       => fake()->name(),
            'email'        => fake()->unique()->safeEmail(),
            'password_hash'=> Hash::make('password'),
            'rol_global'   => 'usuario',
            'activo'       => true,
        ];
    }

    public function superuser(): static
    {
        return $this->state(['rol_global' => 'superuser']);
    }

    public function admin(): static
    {
        return $this->state(['rol_global' => 'admin']);
    }

    public function inactivo(): static
    {
        return $this->state(['activo' => false]);
    }
}
```

`backend/database/factories/ProyectoFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProyectoFactory extends Factory
{
    protected $model = Proyecto::class;

    public function definition(): array
    {
        return [
            'codigo' => strtoupper(fake()->unique()->lexify('???-###')),
            'nombre' => fake()->company() . ' — ' . fake()->catchPhrase(),
            'estado' => 'activo',
        ];
    }

    public function archivado(): static
    {
        return $this->state(['estado' => 'archivado']);
    }
}
```

`backend/database/factories/AreaFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

class AreaFactory extends Factory
{
    protected $model = Area::class;

    public function definition(): array
    {
        return [
            'proyecto_id' => Proyecto::factory(),
            'codigo'      => fake()->unique()->numerify('####'),
            'nombre'      => fake()->words(3, true),
            'orden'       => fake()->numberBetween(0, 100),
        ];
    }
}
```

`backend/database/factories/SubareaFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Subarea;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubareaFactory extends Factory
{
    protected $model = Subarea::class;

    public function definition(): array
    {
        $area = Area::factory()->create();
        return [
            'proyecto_id' => $area->proyecto_id,
            'area_id'     => $area->id,
            'codigo'      => fake()->unique()->numerify('####'),
            'nombre'      => fake()->words(3, true),
            'orden'       => fake()->numberBetween(0, 100),
        ];
    }
}
```

`backend/database/factories/SistemaFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Sistema;
use App\Models\Subarea;
use Illuminate\Database\Eloquent\Factories\Factory;

class SistemaFactory extends Factory
{
    protected $model = Sistema::class;

    public function definition(): array
    {
        $subarea = Subarea::factory()->create();
        return [
            'proyecto_id' => $subarea->proyecto_id,
            'subarea_id'  => $subarea->id,
            'codigo'      => fake()->unique()->lexify('????'),
            'nombre'      => fake()->words(3, true),
            'orden'       => fake()->numberBetween(0, 100),
        ];
    }
}
```

`backend/database/factories/SubsistemaFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Subsistema;
use App\Models\Sistema;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubsistemaFactory extends Factory
{
    protected $model = Subsistema::class;

    public function definition(): array
    {
        $sistema = Sistema::factory()->create();
        return [
            'proyecto_id' => $sistema->proyecto_id,
            'sistema_id'  => $sistema->id,
            'codigo'      => fake()->unique()->lexify('????-#'),
            'nombre'      => fake()->words(3, true),
            'orden'       => fake()->numberBetween(0, 100),
        ];
    }
}
```

- [ ] **Step 3.11: Verificar que no hay errores de sintaxis**

```bash
cd backend
php artisan tinker --execute="App\Models\Usuario::count(); echo 'OK';"
```

Esperado: `OK` (sin errores de clase o autoload).

- [ ] **Step 3.12: Commit**

```bash
cd ..
git add backend/app/Models/ backend/database/factories/
git commit -m "feat: add 8 Eloquent models and 6 factories"
```

---

## Task 4: Middleware (CheckRole + CheckProyectoAccess) + LogService

**Files:**
- Create: `backend/app/Http/Middleware/CheckRole.php`
- Create: `backend/app/Http/Middleware/CheckProyectoAccess.php`
- Create: `backend/app/Services/LogService.php`

- [ ] **Step 4.1: Crear middleware `CheckRole`**

Verifica que el usuario autenticado tenga el rol requerido. Para rutas de proyecto, el SUPERUSER siempre pasa. Para rol `admin`, verifica el rol en la tabla `usuarios_proyectos`.

`backend/app/Http/Middleware/CheckRole.php`:
```php
<?php

namespace App\Http\Middleware;

use App\Models\UsuarioProyecto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $usuario = $request->user();

        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // SUPERUSER siempre pasa
        if ($usuario->rol_global === 'superuser') {
            return $next($request);
        }

        if ($role === 'superuser') {
            return response()->json(['message' => 'Se requiere rol SUPERUSER'], 403);
        }

        if ($role === 'admin') {
            $proyectoId = $request->route('proyecto_id') ?? $request->route('id');

            $asignacion = UsuarioProyecto::where('usuario_id', $usuario->id)
                ->where('proyecto_id', $proyectoId)
                ->first();

            if (!$asignacion || $asignacion->rol !== 'admin') {
                return response()->json(['message' => 'Se requiere rol Admin en este proyecto'], 403);
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 4.2: Crear middleware `CheckProyectoAccess`**

Verifica que el usuario tenga asignación activa en el proyecto solicitado. Se aplica a todas las rutas de jerarquía. El SUPERUSER siempre tiene acceso.

`backend/app/Http/Middleware/CheckProyectoAccess.php`:
```php
<?php

namespace App\Http\Middleware;

use App\Models\UsuarioProyecto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckProyectoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // SUPERUSER tiene acceso a todos los proyectos
        if ($usuario->rol_global === 'superuser') {
            return $next($request);
        }

        $proyectoId = $request->route('proyecto_id') ?? $request->route('id');

        if (!$proyectoId) {
            return $next($request);
        }

        $tieneAcceso = UsuarioProyecto::where('usuario_id', $usuario->id)
            ->where('proyecto_id', $proyectoId)
            ->exists();

        if (!$tieneAcceso) {
            return response()->json(['message' => 'Sin acceso a este proyecto'], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4.3: Crear `LogService`**

Helper centralizado para escribir en cualquier tabla `*_log`. Todos los controllers lo usarán para registrar CREATE, UPDATE, DELETE, IMPORT y errores.

`backend/app/Services/LogService.php`:
```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LogService
{
    /**
     * Registra una acción en la tabla de log correspondiente.
     *
     * @param string      $tabla         Nombre de la entidad (ej: 'areas', 'usuarios')
     * @param int|null    $proyectoId    ID del proyecto de contexto
     * @param int         $usuarioId     ID del usuario que realizó la acción
     * @param string      $accion        CREATE | UPDATE | DELETE | IMPORT | IMPORT_ERROR_DISMISSED | VALIDATION_ERROR
     * @param int|null    $entidadId     PK del registro afectado (null si nunca se creó)
     * @param array|null  $datosAntes    Estado previo (UPDATE / DELETE)
     * @param array|null  $datosDespues  Estado nuevo (CREATE / UPDATE) o payload intentado (ERROR)
     * @param array|null  $errorDetalle  {campo, motivo, valor_ingresado, fila_excel?, decision_usuario?}
     * @param string|null $ip            IP del cliente
     */
    public static function log(
        string $tabla,
        ?int $proyectoId,
        int $usuarioId,
        string $accion,
        ?int $entidadId,
        ?array $datosAntes = null,
        ?array $datosDespues = null,
        ?array $errorDetalle = null,
        ?string $ip = null
    ): void {
        DB::table("{$tabla}_log")->insert([
            'proyecto_id'   => $proyectoId,
            'usuario_id'    => $usuarioId,
            'accion'        => $accion,
            'entidad_id'    => $entidadId,
            'datos_antes'   => $datosAntes   ? json_encode($datosAntes)   : null,
            'datos_despues' => $datosDespues ? json_encode($datosDespues) : null,
            'error_detalle' => $errorDetalle ? json_encode($errorDetalle) : null,
            'ip'            => $ip,
            'created_at'    => now(),
        ]);
    }
}
```

- [ ] **Step 4.4: Verificar que los alias están registrados**

Los alias `check.role` y `check.project` ya fueron declarados en `bootstrap/app.php` en Task 1. Verificar que apuntan a las clases correctas:

```bash
cd backend
php artisan route:list 2>&1 | head -5
```

Si da error de clase no encontrada, revisar que los namespaces en `bootstrap/app.php` sean exactamente:
```
\App\Http\Middleware\CheckRole::class
\App\Http\Middleware\CheckProyectoAccess::class
```

- [ ] **Step 4.5: Commit**

```bash
cd ..
git add backend/app/Http/Middleware/ backend/app/Services/
git commit -m "feat: add CheckRole, CheckProyectoAccess middleware and LogService"
```

---

## Task 5: Auth API + Tests

**Files:**
- Create: `backend/app/Http/Controllers/AuthController.php`
- Create: `backend/routes/api.php`
- Create: `backend/tests/Feature/AuthTest.php`

- [ ] **Step 5.1: Escribir el test primero (TDD)**

`backend/tests/Feature/AuthTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_con_credenciales_validas(): void
    {
        $usuario = Usuario::factory()->create([
            'email'         => 'test@example.com',
            'password_hash' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'test@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'usuario' => ['id', 'nombre', 'email', 'rol_global'],
            ]);
    }

    public function test_login_con_password_incorrecto(): void
    {
        Usuario::factory()->create(['email' => 'test@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'test@example.com',
            'password' => 'wrong',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Credenciales inválidas']);
    }

    public function test_login_con_email_inexistente(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'noexiste@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_con_usuario_inactivo(): void
    {
        Usuario::factory()->inactivo()->create([
            'email'         => 'inactivo@example.com',
            'password_hash' => bcrypt('secret123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'inactivo@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(403)
            ->assertJson(['message' => 'Usuario inactivo']);
    }

    public function test_login_sin_campos_requeridos(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_retorna_usuario_autenticado(): void
    {
        $usuario = Usuario::factory()->create();
        $token   = $usuario->createToken('test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJson([
                'id'        => $usuario->id,
                'email'     => $usuario->email,
                'rol_global'=> $usuario->rol_global,
            ]);
    }

    public function test_me_sin_token_retorna_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_logout_revoca_token(): void
    {
        $usuario = Usuario::factory()->create();
        $token   = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/auth/logout')
            ->assertStatus(204);

        // El token ya no funciona
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertStatus(401);
    }
}
```

- [ ] **Step 5.2: Ejecutar tests — deben fallar**

```bash
cd backend
php artisan test tests/Feature/AuthTest.php
```

Esperado: todos fallan con `RouteNotFoundException` o similar (rutas y controller no existen aún).

- [ ] **Step 5.3: Crear `AuthController`**

`backend/app/Http/Controllers/AuthController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $usuario = Usuario::where('email', $data['email'])->first();

        if (!$usuario || !Hash::check($data['password'], $usuario->password_hash)) {
            return response()->json(['message' => 'Credenciales inválidas'], 401);
        }

        if (!$usuario->activo) {
            return response()->json(['message' => 'Usuario inactivo'], 403);
        }

        $token = $usuario->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token'   => $token,
            'usuario' => $usuario->only(['id', 'nombre', 'email', 'rol_global']),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(
            $request->user()->only(['id', 'nombre', 'email', 'rol_global'])
        );
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 5.4: Crear `routes/api.php` con rutas de auth**

`backend/routes/api.php`:
```php
<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Auth (rate limit login: 10/min por IP — configurado en bootstrap/app.php)
Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',     [AuthController::class, 'me']);

    // El resto de rutas se agregan en tasks posteriores

});
```

- [ ] **Step 5.5: Ejecutar tests — deben pasar**

```bash
php artisan test tests/Feature/AuthTest.php
```

Esperado:
```
PASS  Tests\Feature\AuthTest
✓ login con credenciales validas
✓ login con password incorrecto
✓ login con email inexistente
✓ login con usuario inactivo
✓ login sin campos requeridos
✓ me retorna usuario autenticado
✓ me sin token retorna 401
✓ logout revoca token

Tests: 8 passed
```

- [ ] **Step 5.6: Verificar manualmente con curl**

```bash
php artisan serve &

curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"noexiste@test.com","password":"x"}' | jq .
```

Esperado: `{"message":"Credenciales inválidas"}`

- [ ] **Step 5.7: Commit**

```bash
cd ..
git add backend/app/Http/Controllers/AuthController.php \
        backend/routes/api.php \
        backend/tests/Feature/AuthTest.php
git commit -m "feat: add Auth API (login/logout/me) with passing tests"
```

---

## Task 6: Proyectos API + Tests

**Files:**
- Create: `backend/app/Http/Controllers/ProyectoController.php`
- Create: `backend/tests/Feature/ProyectoTest.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 6.1: Escribir los tests primero (TDD)**

`backend/tests/Feature/ProyectoTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProyectoTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperuser(): array
    {
        $su    = Usuario::factory()->superuser()->create();
        $token = $su->createToken('test')->plainTextToken;
        return [$su, $token];
    }

    private function actingAsUsuario(): array
    {
        $usuario = Usuario::factory()->create();
        $token   = $usuario->createToken('test')->plainTextToken;
        return [$usuario, $token];
    }

    public function test_usuario_solo_ve_proyectos_asignados(): void
    {
        [$usuario, $token] = $this->actingAsUsuario();

        $asignado    = Proyecto::factory()->create();
        $no_asignado = Proyecto::factory()->create();

        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $asignado->id,
            'rol'         => 'usuario',
        ]);

        $response = $this->withToken($token)->getJson('/api/proyectos');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $asignado->id);
    }

    public function test_superuser_ve_todos_los_proyectos(): void
    {
        [, $token] = $this->actingAsSuperuser();

        Proyecto::factory()->count(3)->create();

        $response = $this->withToken($token)->getJson('/api/proyectos');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_superuser_puede_crear_proyecto(): void
    {
        [, $token] = $this->actingAsSuperuser();

        $response = $this->withToken($token)->postJson('/api/proyectos', [
            'codigo' => 'AUT-001',
            'nombre' => 'Autopista Norte Tramo 1',
            'estado' => 'activo',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('codigo', 'AUT-001');

        $this->assertDatabaseHas('proyectos', ['codigo' => 'AUT-001']);
    }

    public function test_usuario_no_puede_crear_proyecto(): void
    {
        [, $token] = $this->actingAsUsuario();

        $response = $this->withToken($token)->postJson('/api/proyectos', [
            'codigo' => 'AUT-001',
            'nombre' => 'Autopista Norte Tramo 1',
        ]);

        $response->assertStatus(403);
    }

    public function test_codigo_duplicado_falla_al_crear(): void
    {
        [, $token] = $this->actingAsSuperuser();

        Proyecto::factory()->create(['codigo' => 'AUT-001']);

        $response = $this->withToken($token)->postJson('/api/proyectos', [
            'codigo' => 'AUT-001',
            'nombre' => 'Otro proyecto',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_superuser_puede_editar_proyecto(): void
    {
        [, $token] = $this->actingAsSuperuser();
        $proyecto  = Proyecto::factory()->create();

        $response = $this->withToken($token)->putJson("/api/proyectos/{$proyecto->id}", [
            'nombre' => 'Nombre actualizado',
            'estado' => 'archivado',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('nombre', 'Nombre actualizado')
            ->assertJsonPath('estado', 'archivado');
    }

    public function test_usuario_no_puede_editar_proyecto(): void
    {
        [, $token] = $this->actingAsUsuario();
        $proyecto  = Proyecto::factory()->create();

        $response = $this->withToken($token)->putJson("/api/proyectos/{$proyecto->id}", [
            'nombre' => 'Hack',
        ]);

        $response->assertStatus(403);
    }

    public function test_sin_token_retorna_401(): void
    {
        $this->getJson('/api/proyectos')->assertStatus(401);
    }
}
```

- [ ] **Step 6.2: Ejecutar tests — deben fallar**

```bash
cd backend
php artisan test tests/Feature/ProyectoTest.php
```

Esperado: todos fallan con error de ruta no encontrada.

- [ ] **Step 6.3: Crear `ProyectoController`**

`backend/app/Http/Controllers/ProyectoController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Proyecto;
use App\Services\LogService;
use Illuminate\Http\Request;

class ProyectoController extends Controller
{
    public function index(Request $request)
    {
        $usuario = $request->user();

        if ($usuario->rol_global === 'superuser') {
            $proyectos = Proyecto::orderBy('codigo')->get();
        } else {
            $proyectos = $usuario->proyectos()->orderBy('codigo')->get();
        }

        return response()->json(['data' => $proyectos]);
    }

    public function show(Request $request, int $id)
    {
        $usuario  = $request->user();
        $proyecto = Proyecto::findOrFail($id);

        if ($usuario->rol_global !== 'superuser') {
            $tieneAcceso = $usuario->proyectos()->where('proyecto_id', $id)->exists();
            if (!$tieneAcceso) {
                return response()->json(['message' => 'Sin acceso a este proyecto'], 403);
            }
        }

        return response()->json($proyecto);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:20|unique:proyectos,codigo',
            'nombre' => 'required|string|max:255',
            'estado' => 'in:activo,archivado',
        ]);

        $proyecto = Proyecto::create($data);

        LogService::log(
            tabla:        'proyectos',
            proyectoId:   $proyecto->id,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $proyecto->id,
            datosDespues: $proyecto->toArray(),
            ip:           $request->ip()
        );

        return response()->json($proyecto, 201);
    }

    public function update(Request $request, int $id)
    {
        $proyecto = Proyecto::findOrFail($id);

        $data = $request->validate([
            'codigo' => "string|max:20|unique:proyectos,codigo,{$id}",
            'nombre' => 'string|max:255',
            'estado' => 'in:activo,archivado',
        ]);

        $antes = $proyecto->toArray();
        $proyecto->update($data);

        LogService::log(
            tabla:        'proyectos',
            proyectoId:   $proyecto->id,
            usuarioId:    $request->user()->id,
            accion:       'UPDATE',
            entidadId:    $proyecto->id,
            datosAntes:   $antes,
            datosDespues: $proyecto->fresh()->toArray(),
            ip:           $request->ip()
        );

        return response()->json($proyecto->fresh());
    }
}
```

- [ ] **Step 6.4: Agregar rutas de proyectos en `api.php`**

Reemplazar el bloque `auth:sanctum` en `backend/routes/api.php`:
```php
<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProyectoController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',     [AuthController::class, 'me']);

    // Proyectos
    Route::get('/proyectos',        [ProyectoController::class, 'index']);
    Route::get('/proyectos/{id}',   [ProyectoController::class, 'show']);
    Route::post('/proyectos',       [ProyectoController::class, 'store'])
        ->middleware('check.role:superuser');
    Route::put('/proyectos/{id}',   [ProyectoController::class, 'update'])
        ->middleware('check.role:superuser');

    // El resto de rutas se agregan en tasks posteriores

});
```

- [ ] **Step 6.5: Ejecutar tests — deben pasar**

```bash
php artisan test tests/Feature/ProyectoTest.php
```

Esperado:
```
PASS  Tests\Feature\ProyectoTest
✓ usuario solo ve proyectos asignados
✓ superuser ve todos los proyectos
✓ superuser puede crear proyecto
✓ usuario no puede crear proyecto
✓ codigo duplicado falla al crear
✓ superuser puede editar proyecto
✓ usuario no puede editar proyecto
✓ sin token retorna 401

Tests: 8 passed
```

- [ ] **Step 6.6: Ejecutar toda la suite para verificar no hay regresiones**

```bash
php artisan test
```

Esperado: los 16 tests anteriores (AuthTest + ProyectoTest) pasan.

- [ ] **Step 6.7: Commit**

```bash
cd ..
git add backend/app/Http/Controllers/ProyectoController.php \
        backend/routes/api.php \
        backend/tests/Feature/ProyectoTest.php
git commit -m "feat: add Proyectos API with role-based access and audit log"
```

---

## Task 7: Jerarquía API — Areas + Tests

**Files:**
- Create: `backend/app/Http/Controllers/AreaController.php`
- Create: `backend/tests/Feature/AreaTest.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 7.1: Escribir los tests primero (TDD)**

`backend/tests/Feature/AreaTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaTest extends TestCase
{
    use RefreshDatabase;

    private function proyectoConAdmin(): array
    {
        $proyecto = Proyecto::factory()->create();
        $admin    = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $admin, $token];
    }

    private function proyectoConUsuario(): array
    {
        $proyecto = Proyecto::factory()->create();
        $usuario  = Usuario::factory()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);
        $token = $usuario->createToken('test')->plainTextToken;
        return [$proyecto, $usuario, $token];
    }

    public function test_usuario_puede_listar_areas_de_su_proyecto(): void
    {
        [$proyecto, , $token] = $this->proyectoConUsuario();
        Area::factory()->count(3)->create(['proyecto_id' => $proyecto->id]);

        $response = $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/areas");

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_usuario_no_puede_listar_areas_de_proyecto_ajeno(): void
    {
        [, , $token] = $this->proyectoConUsuario();
        $otro         = Proyecto::factory()->create();

        $this->withToken($token)
            ->getJson("/api/proyectos/{$otro->id}/areas")
            ->assertStatus(403);
    }

    public function test_admin_puede_crear_area(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/areas", [
                'codigo' => '3600',
                'nombre' => 'Estructura',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('codigo', '3600');

        $this->assertDatabaseHas('areas', [
            'proyecto_id' => $proyecto->id,
            'codigo'      => '3600',
        ]);
    }

    public function test_usuario_no_puede_crear_area(): void
    {
        [$proyecto, , $token] = $this->proyectoConUsuario();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/areas", [
                'codigo' => '3600',
                'nombre' => 'Estructura',
            ])
            ->assertStatus(403);
    }

    public function test_codigo_duplicado_en_mismo_proyecto_falla(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();
        Area::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3600']);

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/areas", [
                'codigo' => '3600',
                'nombre' => 'Duplicado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_mismo_codigo_en_distinto_proyecto_es_valido(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();
        $otro = Proyecto::factory()->create();
        Area::factory()->create(['proyecto_id' => $otro->id, 'codigo' => '3600']);

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/areas", [
                'codigo' => '3600',
                'nombre' => 'Estructura',
            ])
            ->assertStatus(201);
    }

    public function test_admin_puede_editar_area(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();
        $area = Area::factory()->create(['proyecto_id' => $proyecto->id]);

        $this->withToken($token)
            ->putJson("/api/proyectos/{$proyecto->id}/areas/{$area->id}", [
                'nombre' => 'Nombre editado',
            ])
            ->assertStatus(200)
            ->assertJsonPath('nombre', 'Nombre editado');
    }

    public function test_admin_puede_eliminar_area(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();
        $area = Area::factory()->create(['proyecto_id' => $proyecto->id]);

        $this->withToken($token)
            ->deleteJson("/api/proyectos/{$proyecto->id}/areas/{$area->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('areas', ['id' => $area->id]);
    }

    public function test_log_se_registra_al_crear_area(): void
    {
        [$proyecto, $admin, $token] = $this->proyectoConAdmin();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/areas", [
                'codigo' => '3600',
                'nombre' => 'Estructura',
            ]);

        $this->assertDatabaseHas('areas_log', [
            'proyecto_id' => $proyecto->id,
            'usuario_id'  => $admin->id,
            'accion'      => 'CREATE',
        ]);
    }
}
```

- [ ] **Step 7.2: Ejecutar tests — deben fallar**

```bash
cd backend
php artisan test tests/Feature/AreaTest.php
```

Esperado: todos fallan con error de ruta no encontrada.

- [ ] **Step 7.3: Crear `AreaController`**

`backend/app/Http/Controllers/AreaController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AreaController extends Controller
{
    public function index(Request $request, int $proyecto_id)
    {
        $areas = Area::where('proyecto_id', $proyecto_id)
            ->orderBy('orden')
            ->orderBy('codigo')
            ->get();

        return response()->json(['data' => $areas]);
    }

    public function store(Request $request, int $proyecto_id)
    {
        $data = $request->validate([
            'codigo' => [
                'required', 'string', 'max:50',
                Rule::unique('areas')->where('proyecto_id', $proyecto_id),
            ],
            'nombre' => 'required|string|max:255',
            'orden'  => 'integer|min:0',
        ]);

        $area = Area::create(array_merge($data, ['proyecto_id' => $proyecto_id]));

        LogService::log(
            tabla:        'areas',
            proyectoId:   $proyecto_id,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $area->id,
            datosDespues: $area->toArray(),
            ip:           $request->ip()
        );

        return response()->json($area, 201);
    }

    public function update(Request $request, int $proyecto_id, int $id)
    {
        $area = Area::where('proyecto_id', $proyecto_id)->findOrFail($id);

        $data = $request->validate([
            'codigo' => [
                'string', 'max:50',
                Rule::unique('areas')->where('proyecto_id', $proyecto_id)->ignore($id),
            ],
            'nombre' => 'string|max:255',
            'orden'  => 'integer|min:0',
        ]);

        $antes = $area->toArray();
        $area->update($data);

        LogService::log(
            tabla:        'areas',
            proyectoId:   $proyecto_id,
            usuarioId:    $request->user()->id,
            accion:       'UPDATE',
            entidadId:    $area->id,
            datosAntes:   $antes,
            datosDespues: $area->fresh()->toArray(),
            ip:           $request->ip()
        );

        return response()->json($area->fresh());
    }

    public function destroy(Request $request, int $proyecto_id, int $id)
    {
        $area = Area::where('proyecto_id', $proyecto_id)->findOrFail($id);

        LogService::log(
            tabla:      'areas',
            proyectoId: $proyecto_id,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $area->id,
            datosAntes: $area->toArray(),
            ip:         $request->ip()
        );

        $area->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 7.4: Agregar rutas de áreas en `api.php`**

Agregar dentro del grupo `auth:sanctum` en `backend/routes/api.php`:
```php
use App\Http\Controllers\AreaController;

// Dentro del grupo auth:sanctum, después de las rutas de proyectos:

// Jerarquía — Areas
Route::prefix('proyectos/{proyecto_id}')->middleware('check.project')->group(function () {

    Route::get('/areas',              [AreaController::class, 'index']);
    Route::post('/areas',             [AreaController::class, 'store'])
        ->middleware('check.role:admin');
    Route::put('/areas/{id}',         [AreaController::class, 'update'])
        ->middleware('check.role:admin');
    Route::delete('/areas/{id}',      [AreaController::class, 'destroy'])
        ->middleware('check.role:admin');

    // Subareas, Sistemas, Subsistemas se agregan en tasks siguientes

});
```

El archivo `api.php` completo hasta este punto:
```php
<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProyectoController;
use App\Http\Controllers\AreaController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',     [AuthController::class, 'me']);

    Route::get('/proyectos',       [ProyectoController::class, 'index']);
    Route::get('/proyectos/{id}',  [ProyectoController::class, 'show']);
    Route::post('/proyectos',      [ProyectoController::class, 'store'])
        ->middleware('check.role:superuser');
    Route::put('/proyectos/{id}',  [ProyectoController::class, 'update'])
        ->middleware('check.role:superuser');

    Route::prefix('proyectos/{proyecto_id}')->middleware('check.project')->group(function () {
        Route::get('/areas',         [AreaController::class, 'index']);
        Route::post('/areas',        [AreaController::class, 'store'])->middleware('check.role:admin');
        Route::put('/areas/{id}',    [AreaController::class, 'update'])->middleware('check.role:admin');
        Route::delete('/areas/{id}', [AreaController::class, 'destroy'])->middleware('check.role:admin');
    });

});
```

- [ ] **Step 7.5: Ejecutar tests — deben pasar**

```bash
php artisan test tests/Feature/AreaTest.php
```

Esperado:
```
PASS  Tests\Feature\AreaTest
✓ usuario puede listar areas de su proyecto
✓ usuario no puede listar areas de proyecto ajeno
✓ admin puede crear area
✓ usuario no puede crear area
✓ codigo duplicado en mismo proyecto falla
✓ mismo codigo en distinto proyecto es valido
✓ admin puede editar area
✓ admin puede eliminar area
✓ log se registra al crear area

Tests: 9 passed
```

- [ ] **Step 7.6: Ejecutar suite completa — sin regresiones**

```bash
php artisan test
```

Esperado: 25 tests pasan (16 anteriores + 9 nuevos).

- [ ] **Step 7.7: Commit**

```bash
cd ..
git add backend/app/Http/Controllers/AreaController.php \
        backend/routes/api.php \
        backend/tests/Feature/AreaTest.php
git commit -m "feat: add Areas API with CRUD, project isolation and audit log"
```

---

## Task 8: Jerarquía API — Subareas + Tests

**Files:**
- Create: `backend/app/Http/Controllers/SubareaController.php`
- Create: `backend/tests/Feature/SubareaTest.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 8.1: Escribir los tests primero (TDD)**

`backend/tests/Feature/SubareaTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Subarea;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubareaTest extends TestCase
{
    use RefreshDatabase;

    private function setup(): array
    {
        $proyecto = Proyecto::factory()->create();
        $area     = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $admin    = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $area, $admin, $token];
    }

    public function test_puede_listar_subareas_de_proyecto(): void
    {
        [$proyecto, $area, , $token] = $this->setup();
        Subarea::factory()->count(2)->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/subareas")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_puede_filtrar_subareas_por_area(): void
    {
        [$proyecto, $area, , $token] = $this->setup();
        $otra_area = Area::factory()->create(['proyecto_id' => $proyecto->id]);

        Subarea::factory()->count(2)->create(['proyecto_id' => $proyecto->id, 'area_id' => $area->id]);
        Subarea::factory()->create(['proyecto_id' => $proyecto->id, 'area_id' => $otra_area->id]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/subareas?area_id={$area->id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_puede_crear_subarea(): void
    {
        [$proyecto, $area, , $token] = $this->setup();

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subareas", [
                'area_id' => $area->id,
                'codigo'  => '3610',
                'nombre'  => 'Fundaciones',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('codigo', '3610')
            ->assertJsonPath('area_id', $area->id);

        $this->assertDatabaseHas('subareas', [
            'proyecto_id' => $proyecto->id,
            'codigo'      => '3610',
        ]);
    }

    public function test_area_padre_debe_pertenecer_al_proyecto(): void
    {
        [$proyecto, , , $token] = $this->setup();
        $area_ajena = Area::factory()->create(); // area de otro proyecto

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subareas", [
                'area_id' => $area_ajena->id,
                'codigo'  => '3610',
                'nombre'  => 'Fundaciones',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area_id']);
    }

    public function test_usuario_no_puede_crear_subarea(): void
    {
        [$proyecto, $area] = $this->setup();
        $usuario = Usuario::factory()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);
        $token = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subareas", [
                'area_id' => $area->id,
                'codigo'  => '3610',
                'nombre'  => 'Fundaciones',
            ])
            ->assertStatus(403);
    }

    public function test_codigo_duplicado_en_mismo_proyecto_falla(): void
    {
        [$proyecto, $area, , $token] = $this->setup();
        Subarea::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3610']);

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subareas", [
                'area_id' => $area->id,
                'codigo'  => '3610',
                'nombre'  => 'Duplicado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_admin_puede_editar_subarea(): void
    {
        [$proyecto, $area, , $token] = $this->setup();
        $subarea = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);

        $this->withToken($token)
            ->putJson("/api/proyectos/{$proyecto->id}/subareas/{$subarea->id}", [
                'nombre' => 'Nombre editado',
            ])
            ->assertStatus(200)
            ->assertJsonPath('nombre', 'Nombre editado');
    }

    public function test_admin_puede_eliminar_subarea(): void
    {
        [$proyecto, $area, , $token] = $this->setup();
        $subarea = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/proyectos/{$proyecto->id}/subareas/{$subarea->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('subareas', ['id' => $subarea->id]);
    }
}
```

- [ ] **Step 8.2: Ejecutar tests — deben fallar**

```bash
cd backend
php artisan test tests/Feature/SubareaTest.php
```

Esperado: todos fallan con error de ruta no encontrada.

- [ ] **Step 8.3: Crear `SubareaController`**

`backend/app/Http/Controllers/SubareaController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Subarea;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubareaController extends Controller
{
    public function index(Request $request, int $proyecto_id)
    {
        $query = Subarea::where('proyecto_id', $proyecto_id)
            ->orderBy('orden')
            ->orderBy('codigo');

        if ($request->has('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, int $proyecto_id)
    {
        $data = $request->validate([
            'area_id' => [
                'required', 'integer',
                Rule::exists('areas', 'id')->where('proyecto_id', $proyecto_id),
            ],
            'codigo'  => [
                'required', 'string', 'max:50',
                Rule::unique('subareas')->where('proyecto_id', $proyecto_id),
            ],
            'nombre'  => 'required|string|max:255',
            'orden'   => 'integer|min:0',
        ]);

        $subarea = Subarea::create(array_merge($data, ['proyecto_id' => $proyecto_id]));

        LogService::log(
            tabla:        'subareas',
            proyectoId:   $proyecto_id,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $subarea->id,
            datosDespues: $subarea->toArray(),
            ip:           $request->ip()
        );

        return response()->json($subarea, 201);
    }

    public function update(Request $request, int $proyecto_id, int $id)
    {
        $subarea = Subarea::where('proyecto_id', $proyecto_id)->findOrFail($id);

        $data = $request->validate([
            'area_id' => [
                'integer',
                Rule::exists('areas', 'id')->where('proyecto_id', $proyecto_id),
            ],
            'codigo'  => [
                'string', 'max:50',
                Rule::unique('subareas')->where('proyecto_id', $proyecto_id)->ignore($id),
            ],
            'nombre'  => 'string|max:255',
            'orden'   => 'integer|min:0',
        ]);

        $antes = $subarea->toArray();
        $subarea->update($data);

        LogService::log(
            tabla:        'subareas',
            proyectoId:   $proyecto_id,
            usuarioId:    $request->user()->id,
            accion:       'UPDATE',
            entidadId:    $subarea->id,
            datosAntes:   $antes,
            datosDespues: $subarea->fresh()->toArray(),
            ip:           $request->ip()
        );

        return response()->json($subarea->fresh());
    }

    public function destroy(Request $request, int $proyecto_id, int $id)
    {
        $subarea = Subarea::where('proyecto_id', $proyecto_id)->findOrFail($id);

        LogService::log(
            tabla:      'subareas',
            proyectoId: $proyecto_id,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $subarea->id,
            datosAntes: $subarea->toArray(),
            ip:         $request->ip()
        );

        $subarea->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 8.4: Agregar rutas de subareas en `api.php`**

Agregar dentro del grupo `proyectos/{proyecto_id}` en `backend/routes/api.php`:
```php
use App\Http\Controllers\SubareaController;

// Dentro del grupo prefix('proyectos/{proyecto_id}'):
Route::get('/subareas',           [SubareaController::class, 'index']);
Route::post('/subareas',          [SubareaController::class, 'store'])->middleware('check.role:admin');
Route::put('/subareas/{id}',      [SubareaController::class, 'update'])->middleware('check.role:admin');
Route::delete('/subareas/{id}',   [SubareaController::class, 'destroy'])->middleware('check.role:admin');
```

- [ ] **Step 8.5: Ejecutar tests — deben pasar**

```bash
php artisan test tests/Feature/SubareaTest.php
```

Esperado:
```
PASS  Tests\Feature\SubareaTest
✓ puede listar subareas de proyecto
✓ puede filtrar subareas por area
✓ admin puede crear subarea
✓ area padre debe pertenecer al proyecto
✓ usuario no puede crear subarea
✓ codigo duplicado en mismo proyecto falla
✓ admin puede editar subarea
✓ admin puede eliminar subarea

Tests: 8 passed
```

- [ ] **Step 8.6: Suite completa — sin regresiones**

```bash
php artisan test
```

Esperado: 33 tests pasan.

- [ ] **Step 8.7: Commit**

```bash
cd ..
git add backend/app/Http/Controllers/SubareaController.php \
        backend/routes/api.php \
        backend/tests/Feature/SubareaTest.php
git commit -m "feat: add Subareas API with parent validation and audit log"
```

---

## Task 9: Jerarquía API — Sistemas + Tests

**Files:**
- Create: `backend/app/Http/Controllers/SistemaController.php`
- Create: `backend/tests/Feature/SistemaTest.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 9.1: Escribir los tests primero (TDD)**

`backend/tests/Feature/SistemaTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Sistema;
use App\Models\Subarea;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SistemaTest extends TestCase
{
    use RefreshDatabase;

    private function setup(): array
    {
        $proyecto = Proyecto::factory()->create();
        $area     = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $subarea  = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);
        $admin = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $subarea, $admin, $token];
    }

    public function test_puede_listar_sistemas_de_proyecto(): void
    {
        [$proyecto, $subarea, , $token] = $this->setup();
        Sistema::factory()->count(2)->create([
            'proyecto_id' => $proyecto->id,
            'subarea_id'  => $subarea->id,
        ]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/sistemas")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_puede_filtrar_sistemas_por_subarea(): void
    {
        [$proyecto, $subarea, , $token] = $this->setup();
        $area2    = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $subarea2 = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area2->id,
        ]);

        Sistema::factory()->count(3)->create(['proyecto_id' => $proyecto->id, 'subarea_id' => $subarea->id]);
        Sistema::factory()->create(['proyecto_id' => $proyecto->id, 'subarea_id' => $subarea2->id]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/sistemas?subarea_id={$subarea->id}")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_puede_crear_sistema(): void
    {
        [$proyecto, $subarea, , $token] = $this->setup();

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/sistemas", [
                'subarea_id' => $subarea->id,
                'codigo'     => '3610B',
                'nombre'     => 'Pilotes',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('codigo', '3610B')
            ->assertJsonPath('subarea_id', $subarea->id);

        $this->assertDatabaseHas('sistemas', [
            'proyecto_id' => $proyecto->id,
            'codigo'      => '3610B',
        ]);
    }

    public function test_subarea_padre_debe_pertenecer_al_proyecto(): void
    {
        [$proyecto, , , $token] = $this->setup();
        $subarea_ajena = Subarea::factory()->create(); // subarea de otro proyecto

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/sistemas", [
                'subarea_id' => $subarea_ajena->id,
                'codigo'     => '3610B',
                'nombre'     => 'Pilotes',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subarea_id']);
    }

    public function test_usuario_no_puede_crear_sistema(): void
    {
        [$proyecto, $subarea] = $this->setup();
        $usuario = Usuario::factory()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);
        $token = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/sistemas", [
                'subarea_id' => $subarea->id,
                'codigo'     => '3610B',
                'nombre'     => 'Pilotes',
            ])
            ->assertStatus(403);
    }

    public function test_codigo_duplicado_en_mismo_proyecto_falla(): void
    {
        [$proyecto, $subarea, , $token] = $this->setup();
        Sistema::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3610B']);

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/sistemas", [
                'subarea_id' => $subarea->id,
                'codigo'     => '3610B',
                'nombre'     => 'Duplicado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_admin_puede_editar_sistema(): void
    {
        [$proyecto, $subarea, , $token] = $this->setup();
        $sistema = Sistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'subarea_id'  => $subarea->id,
        ]);

        $this->withToken($token)
            ->putJson("/api/proyectos/{$proyecto->id}/sistemas/{$sistema->id}", [
                'nombre' => 'Nombre editado',
            ])
            ->assertStatus(200)
            ->assertJsonPath('nombre', 'Nombre editado');
    }

    public function test_admin_puede_eliminar_sistema(): void
    {
        [$proyecto, $subarea, , $token] = $this->setup();
        $sistema = Sistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'subarea_id'  => $subarea->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/proyectos/{$proyecto->id}/sistemas/{$sistema->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('sistemas', ['id' => $sistema->id]);
    }
}
```

- [ ] **Step 9.2: Ejecutar tests — deben fallar**

```bash
cd backend
php artisan test tests/Feature/SistemaTest.php
```

Esperado: todos fallan con error de ruta no encontrada.

- [ ] **Step 9.3: Crear `SistemaController`**

`backend/app/Http/Controllers/SistemaController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SistemaController extends Controller
{
    public function index(Request $request, int $proyecto_id)
    {
        $query = Sistema::where('proyecto_id', $proyecto_id)
            ->orderBy('orden')
            ->orderBy('codigo');

        if ($request->has('subarea_id')) {
            $query->where('subarea_id', $request->subarea_id);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, int $proyecto_id)
    {
        $data = $request->validate([
            'subarea_id' => [
                'required', 'integer',
                Rule::exists('subareas', 'id')->where('proyecto_id', $proyecto_id),
            ],
            'codigo' => [
                'required', 'string', 'max:50',
                Rule::unique('sistemas')->where('proyecto_id', $proyecto_id),
            ],
            'nombre' => 'required|string|max:255',
            'orden'  => 'integer|min:0',
        ]);

        $sistema = Sistema::create(array_merge($data, ['proyecto_id' => $proyecto_id]));

        LogService::log(
            tabla:        'sistemas',
            proyectoId:   $proyecto_id,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $sistema->id,
            datosDespues: $sistema->toArray(),
            ip:           $request->ip()
        );

        return response()->json($sistema, 201);
    }

    public function update(Request $request, int $proyecto_id, int $id)
    {
        $sistema = Sistema::where('proyecto_id', $proyecto_id)->findOrFail($id);

        $data = $request->validate([
            'subarea_id' => [
                'integer',
                Rule::exists('subareas', 'id')->where('proyecto_id', $proyecto_id),
            ],
            'codigo' => [
                'string', 'max:50',
                Rule::unique('sistemas')->where('proyecto_id', $proyecto_id)->ignore($id),
            ],
            'nombre' => 'string|max:255',
            'orden'  => 'integer|min:0',
        ]);

        $antes = $sistema->toArray();
        $sistema->update($data);

        LogService::log(
            tabla:        'sistemas',
            proyectoId:   $proyecto_id,
            usuarioId:    $request->user()->id,
            accion:       'UPDATE',
            entidadId:    $sistema->id,
            datosAntes:   $antes,
            datosDespues: $sistema->fresh()->toArray(),
            ip:           $request->ip()
        );

        return response()->json($sistema->fresh());
    }

    public function destroy(Request $request, int $proyecto_id, int $id)
    {
        $sistema = Sistema::where('proyecto_id', $proyecto_id)->findOrFail($id);

        LogService::log(
            tabla:      'sistemas',
            proyectoId: $proyecto_id,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $sistema->id,
            datosAntes: $sistema->toArray(),
            ip:         $request->ip()
        );

        $sistema->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 9.4: Agregar rutas de sistemas en `api.php`**

Agregar dentro del grupo `proyectos/{proyecto_id}` en `backend/routes/api.php`:
```php
use App\Http\Controllers\SistemaController;

Route::get('/sistemas',          [SistemaController::class, 'index']);
Route::post('/sistemas',         [SistemaController::class, 'store'])->middleware('check.role:admin');
Route::put('/sistemas/{id}',     [SistemaController::class, 'update'])->middleware('check.role:admin');
Route::delete('/sistemas/{id}',  [SistemaController::class, 'destroy'])->middleware('check.role:admin');
```

- [ ] **Step 9.5: Ejecutar tests — deben pasar**

```bash
php artisan test tests/Feature/SistemaTest.php
```

Esperado:
```
PASS  Tests\Feature\SistemaTest
✓ puede listar sistemas de proyecto
✓ puede filtrar sistemas por subarea
✓ admin puede crear sistema
✓ subarea padre debe pertenecer al proyecto
✓ usuario no puede crear sistema
✓ codigo duplicado en mismo proyecto falla
✓ admin puede editar sistema
✓ admin puede eliminar sistema

Tests: 8 passed
```

- [ ] **Step 9.6: Suite completa — sin regresiones**

```bash
php artisan test
```

Esperado: 41 tests pasan.

- [ ] **Step 9.7: Commit**

```bash
cd ..
git add backend/app/Http/Controllers/SistemaController.php \
        backend/routes/api.php \
        backend/tests/Feature/SistemaTest.php
git commit -m "feat: add Sistemas API with subarea parent validation and audit log"
```

---

## Task 10: Jerarquía API — Subsistemas + Tests

**Files:**
- Create: `backend/app/Http/Controllers/SubsistemaController.php`
- Create: `backend/tests/Feature/SubsistemaTest.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 10.1: Escribir los tests primero (TDD)**

`backend/tests/Feature/SubsistemaTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Sistema;
use App\Models\Subarea;
use App\Models\Subsistema;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsistemaTest extends TestCase
{
    use RefreshDatabase;

    private function setup(): array
    {
        $proyecto = Proyecto::factory()->create();
        $area     = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $subarea  = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);
        $sistema  = Sistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'subarea_id'  => $subarea->id,
        ]);
        $admin = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $sistema, $admin, $token];
    }

    public function test_puede_listar_subsistemas_de_proyecto(): void
    {
        [$proyecto, $sistema, , $token] = $this->setup();
        Subsistema::factory()->count(3)->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
        ]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/subsistemas")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_puede_filtrar_subsistemas_por_sistema(): void
    {
        [$proyecto, $sistema, , $token] = $this->setup();
        $area2    = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $subarea2 = Subarea::factory()->create(['proyecto_id' => $proyecto->id, 'area_id' => $area2->id]);
        $sistema2 = Sistema::factory()->create(['proyecto_id' => $proyecto->id, 'subarea_id' => $subarea2->id]);

        Subsistema::factory()->count(2)->create(['proyecto_id' => $proyecto->id, 'sistema_id' => $sistema->id]);
        Subsistema::factory()->count(4)->create(['proyecto_id' => $proyecto->id, 'sistema_id' => $sistema2->id]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/subsistemas?sistema_id={$sistema->id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_puede_crear_subsistema(): void
    {
        [$proyecto, $sistema, , $token] = $this->setup();

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id' => $sistema->id,
                'codigo'     => '3610B-1',
                'nombre'     => 'Pilotes hormigón',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('codigo', '3610B-1')
            ->assertJsonPath('sistema_id', $sistema->id);

        $this->assertDatabaseHas('subsistemas', [
            'proyecto_id' => $proyecto->id,
            'codigo'      => '3610B-1',
        ]);
    }

    public function test_sistema_padre_debe_pertenecer_al_proyecto(): void
    {
        [$proyecto, , , $token] = $this->setup();
        $sistema_ajeno = Sistema::factory()->create(); // sistema de otro proyecto

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id' => $sistema_ajeno->id,
                'codigo'     => '3610B-1',
                'nombre'     => 'Pilotes hormigón',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sistema_id']);
    }

    public function test_usuario_no_puede_crear_subsistema(): void
    {
        [$proyecto, $sistema] = $this->setup();
        $usuario = Usuario::factory()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);
        $token = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id' => $sistema->id,
                'codigo'     => '3610B-1',
                'nombre'     => 'Pilotes hormigón',
            ])
            ->assertStatus(403);
    }

    public function test_codigo_duplicado_en_mismo_proyecto_falla(): void
    {
        [$proyecto, $sistema, , $token] = $this->setup();
        Subsistema::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3610B-1']);

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id' => $sistema->id,
                'codigo'     => '3610B-1',
                'nombre'     => 'Duplicado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_admin_puede_editar_subsistema(): void
    {
        [$proyecto, $sistema, , $token] = $this->setup();
        $subsistema = Subsistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
        ]);

        $this->withToken($token)
            ->putJson("/api/proyectos/{$proyecto->id}/subsistemas/{$subsistema->id}", [
                'nombre' => 'Nombre editado',
            ])
            ->assertStatus(200)
            ->assertJsonPath('nombre', 'Nombre editado');
    }

    public function test_admin_puede_eliminar_subsistema(): void
    {
        [$proyecto, $sistema, , $token] = $this->setup();
        $subsistema = Subsistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/proyectos/{$proyecto->id}/subsistemas/{$subsistema->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('subsistemas', ['id' => $subsistema->id]);
    }

    public function test_log_se_registra_al_eliminar_subsistema(): void
    {
        [$proyecto, $sistema, $admin, $token] = $this->setup();
        $subsistema = Subsistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/proyectos/{$proyecto->id}/subsistemas/{$subsistema->id}");

        $this->assertDatabaseHas('subsistemas_log', [
            'proyecto_id' => $proyecto->id,
            'usuario_id'  => $admin->id,
            'accion'      => 'DELETE',
            'entidad_id'  => $subsistema->id,
        ]);
    }
}
```

- [ ] **Step 10.2: Ejecutar tests — deben fallar**

```bash
cd backend
php artisan test tests/Feature/SubsistemaTest.php
```

Esperado: todos fallan con error de ruta no encontrada.

- [ ] **Step 10.3: Crear `SubsistemaController`**

`backend/app/Http/Controllers/SubsistemaController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Models\Subsistema;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubsistemaController extends Controller
{
    public function index(Request $request, int $proyecto_id)
    {
        $query = Subsistema::where('proyecto_id', $proyecto_id)
            ->orderBy('orden')
            ->orderBy('codigo');

        if ($request->has('sistema_id')) {
            $query->where('sistema_id', $request->sistema_id);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request, int $proyecto_id)
    {
        $data = $request->validate([
            'sistema_id' => [
                'required', 'integer',
                Rule::exists('sistemas', 'id')->where('proyecto_id', $proyecto_id),
            ],
            'codigo' => [
                'required', 'string', 'max:50',
                Rule::unique('subsistemas')->where('proyecto_id', $proyecto_id),
            ],
            'nombre' => 'required|string|max:255',
            'orden'  => 'integer|min:0',
        ]);

        $subsistema = Subsistema::create(array_merge($data, ['proyecto_id' => $proyecto_id]));

        LogService::log(
            tabla:        'subsistemas',
            proyectoId:   $proyecto_id,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $subsistema->id,
            datosDespues: $subsistema->toArray(),
            ip:           $request->ip()
        );

        return response()->json($subsistema, 201);
    }

    public function update(Request $request, int $proyecto_id, int $id)
    {
        $subsistema = Subsistema::where('proyecto_id', $proyecto_id)->findOrFail($id);

        $data = $request->validate([
            'sistema_id' => [
                'integer',
                Rule::exists('sistemas', 'id')->where('proyecto_id', $proyecto_id),
            ],
            'codigo' => [
                'string', 'max:50',
                Rule::unique('subsistemas')->where('proyecto_id', $proyecto_id)->ignore($id),
            ],
            'nombre' => 'string|max:255',
            'orden'  => 'integer|min:0',
        ]);

        $antes = $subsistema->toArray();
        $subsistema->update($data);

        LogService::log(
            tabla:        'subsistemas',
            proyectoId:   $proyecto_id,
            usuarioId:    $request->user()->id,
            accion:       'UPDATE',
            entidadId:    $subsistema->id,
            datosAntes:   $antes,
            datosDespues: $subsistema->fresh()->toArray(),
            ip:           $request->ip()
        );

        return response()->json($subsistema->fresh());
    }

    public function destroy(Request $request, int $proyecto_id, int $id)
    {
        $subsistema = Subsistema::where('proyecto_id', $proyecto_id)->findOrFail($id);

        LogService::log(
            tabla:      'subsistemas',
            proyectoId: $proyecto_id,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $subsistema->id,
            datosAntes: $subsistema->toArray(),
            ip:         $request->ip()
        );

        $subsistema->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 10.4: Agregar rutas de subsistemas en `api.php`**

Agregar dentro del grupo `proyectos/{proyecto_id}` en `backend/routes/api.php`:
```php
use App\Http\Controllers\SubsistemaController;

Route::get('/subsistemas',          [SubsistemaController::class, 'index']);
Route::post('/subsistemas',         [SubsistemaController::class, 'store'])->middleware('check.role:admin');
Route::put('/subsistemas/{id}',     [SubsistemaController::class, 'update'])->middleware('check.role:admin');
Route::delete('/subsistemas/{id}',  [SubsistemaController::class, 'destroy'])->middleware('check.role:admin');
```

El grupo `proyectos/{proyecto_id}` completo queda así:
```php
Route::prefix('proyectos/{proyecto_id}')->middleware('check.project')->group(function () {
    Route::get('/areas',              [AreaController::class, 'index']);
    Route::post('/areas',             [AreaController::class, 'store'])->middleware('check.role:admin');
    Route::put('/areas/{id}',         [AreaController::class, 'update'])->middleware('check.role:admin');
    Route::delete('/areas/{id}',      [AreaController::class, 'destroy'])->middleware('check.role:admin');

    Route::get('/subareas',           [SubareaController::class, 'index']);
    Route::post('/subareas',          [SubareaController::class, 'store'])->middleware('check.role:admin');
    Route::put('/subareas/{id}',      [SubareaController::class, 'update'])->middleware('check.role:admin');
    Route::delete('/subareas/{id}',   [SubareaController::class, 'destroy'])->middleware('check.role:admin');

    Route::get('/sistemas',           [SistemaController::class, 'index']);
    Route::post('/sistemas',          [SistemaController::class, 'store'])->middleware('check.role:admin');
    Route::put('/sistemas/{id}',      [SistemaController::class, 'update'])->middleware('check.role:admin');
    Route::delete('/sistemas/{id}',   [SistemaController::class, 'destroy'])->middleware('check.role:admin');

    Route::get('/subsistemas',        [SubsistemaController::class, 'index']);
    Route::post('/subsistemas',       [SubsistemaController::class, 'store'])->middleware('check.role:admin');
    Route::put('/subsistemas/{id}',   [SubsistemaController::class, 'update'])->middleware('check.role:admin');
    Route::delete('/subsistemas/{id}',[SubsistemaController::class, 'destroy'])->middleware('check.role:admin');
});
```

- [ ] **Step 10.5: Ejecutar tests — deben pasar**

```bash
php artisan test tests/Feature/SubsistemaTest.php
```

Esperado:
```
PASS  Tests\Feature\SubsistemaTest
✓ puede listar subsistemas de proyecto
✓ puede filtrar subsistemas por sistema
✓ admin puede crear subsistema
✓ sistema padre debe pertenecer al proyecto
✓ usuario no puede crear subsistema
✓ codigo duplicado en mismo proyecto falla
✓ admin puede editar subsistema
✓ admin puede eliminar subsistema
✓ log se registra al eliminar subsistema

Tests: 9 passed
```

- [ ] **Step 10.6: Suite completa — sin regresiones**

```bash
php artisan test
```

Esperado: 50 tests pasan (milestone: jerarquía completa).

- [ ] **Step 10.7: Commit**

```bash
cd ..
git add backend/app/Http/Controllers/SubsistemaController.php \
        backend/routes/api.php \
        backend/tests/Feature/SubsistemaTest.php
git commit -m "feat: add Subsistemas API — hierarchy CRUD complete with 50 passing tests"
```

---

## Task 11: Import Excel — Plantilla + Preview + Confirm + Tests

**Files:**
- Create: `backend/app/Exports/QuiebreTemplateExport.php`
- Create: `backend/app/Services/ImportService.php`
- Create: `backend/app/Http/Controllers/ImportController.php`
- Create: `backend/tests/Feature/ImportTest.php`
- Modify: `backend/routes/api.php`

El import es un flujo de dos pasos: **preview** (parsear archivo y devolver análisis por fila) y **confirm** (el usuario envía las decisiones y se insertan los datos).

- [ ] **Step 11.1: Crear la clase Export para la plantilla**

`backend/app/Exports/QuiebreTemplateExport.php`:
```php
<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class QuiebreTemplateExport implements FromArray, WithHeadings, WithColumnWidths, WithTitle
{
    public function title(): string
    {
        return 'Quiebre del Contrato';
    }

    public function headings(): array
    {
        return ['codigo', 'nombre', 'nivel', 'codigo_padre_de_quiebre'];
    }

    public function array(): array
    {
        return [
            ['3600',   'Estructura',       'area',        ''],
            ['3610',   'Fundaciones',      'subarea',     '3600'],
            ['3610B',  'Pilotes',          'sistema',     '3610'],
            ['3610B-1','Pilotes hormigón', 'subsistema',  '3610B'],
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 15, 'B' => 45, 'C' => 15, 'D' => 25];
    }
}
```

- [ ] **Step 11.2: Crear `ImportService`**

Contiene la lógica de validación/inserción, separada del controller para facilitar tests.

`backend/app/Services/ImportService.php`:
```php
<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Subarea;
use App\Models\Sistema;
use App\Models\Subsistema;
use Illuminate\Support\Facades\DB;

class ImportService
{
    private const NIVELES = ['area', 'subarea', 'sistema', 'subsistema'];

    /**
     * Analiza un array de filas y devuelve su estado sin insertar nada.
     * Cada fila retorna: status (valid|duplicate|error), datos originales y detalle.
     */
    public function preview(array $rows, int $proyectoId): array
    {
        $result    = [];
        $codigos_en_archivo = array_column($rows, 'codigo');

        foreach ($rows as $index => $fila) {
            $fila_num = $index + 2; // +2 porque fila 1 es cabecera

            // Validar columnas requeridas
            if (empty($fila['codigo']) || empty($fila['nombre']) || empty($fila['nivel'])) {
                $result[] = $this->filaError($fila, $fila_num, 'codigo o nombre o nivel está vacío');
                continue;
            }

            // Validar nivel
            if (!in_array($fila['nivel'], self::NIVELES)) {
                $result[] = $this->filaError($fila, $fila_num,
                    "nivel '{$fila['nivel']}' no válido. Valores: area, subarea, sistema, subsistema");
                continue;
            }

            // Validar padre para niveles que lo requieren
            if ($fila['nivel'] !== 'area') {
                $padre = $fila['codigo_padre_de_quiebre'] ?? null;
                if (empty($padre)) {
                    $result[] = $this->filaError($fila, $fila_num,
                        "codigo_padre_de_quiebre es obligatorio para nivel '{$fila['nivel']}'");
                    continue;
                }

                if (!$this->padreExiste($padre, $fila['nivel'], $proyectoId)) {
                    $result[] = $this->filaError($fila, $fila_num,
                        "El padre '{$padre}' no existe en el proyecto para el nivel anterior");
                    continue;
                }
            }

            // Verificar duplicado en DB
            if ($this->codigoExisteEnDB($fila['codigo'], $fila['nivel'], $proyectoId)) {
                $result[] = array_merge($fila, [
                    'fila'   => $fila_num,
                    'status' => 'duplicate',
                    'motivo' => "El código '{$fila['codigo']}' ya existe en el proyecto",
                ]);
                continue;
            }

            $result[] = array_merge($fila, ['fila' => $fila_num, 'status' => 'valid']);
        }

        return $result;
    }

    /**
     * Inserta las filas confirmadas por el usuario en una transacción.
     * Cada fila debe tener 'decision': 'import' | 'skip'.
     * Retorna resumen: importadas, omitidas, errores.
     */
    public function confirm(array $filas, int $proyectoId, int $usuarioId, string $ip): array
    {
        $importadas = 0;
        $omitidas   = 0;
        $errores    = 0;

        DB::transaction(function () use ($filas, $proyectoId, $usuarioId, $ip, &$importadas, &$omitidas, &$errores) {
            foreach ($filas as $fila) {
                if (($fila['decision'] ?? 'skip') === 'skip') {
                    $omitidas++;

                    // Registrar en log si el usuario eligió omitir una fila con error/duplicado
                    if (in_array($fila['status'] ?? '', ['duplicate', 'error'])) {
                        LogService::log(
                            tabla:        $this->tablaDeNivel($fila['nivel']),
                            proyectoId:   $proyectoId,
                            usuarioId:    $usuarioId,
                            accion:       'IMPORT_ERROR_DISMISSED',
                            entidadId:    null,
                            errorDetalle: [
                                'campo'            => 'codigo',
                                'motivo'           => $fila['motivo'] ?? 'omitido por usuario',
                                'valor_ingresado'  => $fila['codigo'],
                                'fila_excel'       => $fila['fila'],
                                'decision_usuario' => 'omitir',
                            ],
                            ip: $ip
                        );
                    }
                    continue;
                }

                try {
                    $registro = $this->insertarFila($fila, $proyectoId);

                    LogService::log(
                        tabla:        $this->tablaDeNivel($fila['nivel']),
                        proyectoId:   $proyectoId,
                        usuarioId:    $usuarioId,
                        accion:       'IMPORT',
                        entidadId:    $registro->id,
                        datosDespues: $registro->toArray(),
                        ip:           $ip
                    );

                    $importadas++;
                } catch (\Exception $e) {
                    $errores++;
                }
            }
        });

        return compact('importadas', 'omitidas', 'errores');
    }

    private function insertarFila(array $fila, int $proyectoId): object
    {
        return match ($fila['nivel']) {
            'area' => Area::create([
                'proyecto_id' => $proyectoId,
                'codigo'      => $fila['codigo'],
                'nombre'      => $fila['nombre'],
            ]),
            'subarea' => Subarea::create([
                'proyecto_id' => $proyectoId,
                'area_id'     => Area::where('proyecto_id', $proyectoId)
                                     ->where('codigo', $fila['codigo_padre_de_quiebre'])->value('id'),
                'codigo'      => $fila['codigo'],
                'nombre'      => $fila['nombre'],
            ]),
            'sistema' => Sistema::create([
                'proyecto_id' => $proyectoId,
                'subarea_id'  => Subarea::where('proyecto_id', $proyectoId)
                                        ->where('codigo', $fila['codigo_padre_de_quiebre'])->value('id'),
                'codigo'      => $fila['codigo'],
                'nombre'      => $fila['nombre'],
            ]),
            'subsistema' => Subsistema::create([
                'proyecto_id' => $proyectoId,
                'sistema_id'  => Sistema::where('proyecto_id', $proyectoId)
                                        ->where('codigo', $fila['codigo_padre_de_quiebre'])->value('id'),
                'codigo'      => $fila['codigo'],
                'nombre'      => $fila['nombre'],
            ]),
        };
    }

    private function padreExiste(string $codigoPadre, string $nivelHijo, int $proyectoId): bool
    {
        $tabla = match ($nivelHijo) {
            'subarea'     => 'areas',
            'sistema'     => 'subareas',
            'subsistema'  => 'sistemas',
            default       => null,
        };

        if (!$tabla) return false;

        return DB::table($tabla)
            ->where('proyecto_id', $proyectoId)
            ->where('codigo', $codigoPadre)
            ->exists();
    }

    private function codigoExisteEnDB(string $codigo, string $nivel, int $proyectoId): bool
    {
        return DB::table($this->tablaDeNivel($nivel))
            ->where('proyecto_id', $proyectoId)
            ->where('codigo', $codigo)
            ->exists();
    }

    private function tablaDeNivel(string $nivel): string
    {
        return match ($nivel) {
            'area'        => 'areas',
            'subarea'     => 'subareas',
            'sistema'     => 'sistemas',
            'subsistema'  => 'subsistemas',
            default       => throw new \InvalidArgumentException("Nivel inválido: {$nivel}"),
        };
    }

    private function filaError(array $fila, int $filaNum, string $motivo): array
    {
        return array_merge($fila, [
            'fila'   => $filaNum,
            'status' => 'error',
            'motivo' => $motivo,
        ]);
    }
}
```

- [ ] **Step 11.3: Crear `ImportController`**

`backend/app/Http/Controllers/ImportController.php`:
```php
<?php

namespace App\Http\Controllers;

use App\Exports\QuiebreTemplateExport;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportController extends Controller
{
    public function __construct(private ImportService $service) {}

    public function template(int $proyecto_id)
    {
        return Excel::download(new QuiebreTemplateExport(), 'plantilla-quiebre.xlsx');
    }

    public function preview(Request $request, int $proyecto_id)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $coleccion = Excel::toCollection(null, $request->file('archivo'))->first();

        if ($coleccion->isEmpty()) {
            return response()->json(['message' => 'El archivo está vacío'], 422);
        }

        // Convertir a array asociativo usando la primera fila como cabecera
        $cabeceras = $coleccion->first()->keys()->toArray();
        $columnas_requeridas = ['codigo', 'nombre', 'nivel', 'codigo_padre_de_quiebre'];
        $faltantes = array_diff($columnas_requeridas, $cabeceras);

        if (!empty($faltantes)) {
            return response()->json([
                'message'  => 'Columnas faltantes en el archivo',
                'faltantes' => array_values($faltantes),
            ], 422);
        }

        $filas = $coleccion->map(fn($row) => $row->toArray())->toArray();
        $resultado = $this->service->preview($filas, $proyecto_id);

        return response()->json([
            'total'      => count($resultado),
            'validas'    => array_values(array_filter($resultado, fn($r) => $r['status'] === 'valid')),
            'duplicados' => array_values(array_filter($resultado, fn($r) => $r['status'] === 'duplicate')),
            'errores'    => array_values(array_filter($resultado, fn($r) => $r['status'] === 'error')),
        ]);
    }

    public function confirm(Request $request, int $proyecto_id)
    {
        $request->validate([
            'filas'              => 'required|array|min:1',
            'filas.*.codigo'     => 'required|string',
            'filas.*.nombre'     => 'required|string',
            'filas.*.nivel'      => 'required|in:area,subarea,sistema,subsistema',
            'filas.*.decision'   => 'required|in:import,skip',
        ]);

        $resumen = $this->service->confirm(
            filas:      $request->filas,
            proyectoId: $proyecto_id,
            usuarioId:  $request->user()->id,
            ip:         $request->ip()
        );

        return response()->json($resumen);
    }
}
```

- [ ] **Step 11.4: Escribir los tests (TDD post-implementación para este task)**

`backend/tests/Feature/ImportTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function setup(): array
    {
        $proyecto = Proyecto::factory()->create();
        $admin    = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $admin, $token];
    }

    private function crearExcel(array $cabeceras, array $filas): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([$cabeceras], $filas));

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_test_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return new UploadedFile(
            $tmpPath,
            'import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_descarga_plantilla(): void
    {
        [$proyecto, , $token] = $this->setup();

        $response = $this->withToken($token)
            ->get("/api/proyectos/{$proyecto->id}/import/template");

        $response->assertStatus(200)
            ->assertHeader('Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_preview_clasifica_filas_validas_y_duplicadas(): void
    {
        [$proyecto, , $token] = $this->setup();
        Area::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3600']);

        $archivo = $this->crearExcel(
            ['codigo', 'nombre', 'nivel', 'codigo_padre_de_quiebre'],
            [
                ['3600', 'Estructura',  'area', ''],   // duplicado
                ['3700', 'Arquitectura','area', ''],   // válida
            ]
        );

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/preview", ['archivo' => $archivo]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'validas')
            ->assertJsonCount(1, 'duplicados')
            ->assertJsonCount(0, 'errores');
    }

    public function test_preview_detecta_padre_inexistente(): void
    {
        [$proyecto, , $token] = $this->setup();

        $archivo = $this->crearExcel(
            ['codigo', 'nombre', 'nivel', 'codigo_padre_de_quiebre'],
            [
                ['3610', 'Fundaciones', 'subarea', '9999'], // padre no existe
            ]
        );

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/preview", ['archivo' => $archivo]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'errores')
            ->assertJsonCount(0, 'validas');
    }

    public function test_preview_falla_si_faltan_columnas(): void
    {
        [$proyecto, , $token] = $this->setup();

        $archivo = $this->crearExcel(
            ['codigo', 'nombre'],  // faltan nivel y codigo_padre_de_quiebre
            [['3600', 'Estructura']]
        );

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/preview", ['archivo' => $archivo])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Columnas faltantes en el archivo');
    }

    public function test_confirm_importa_filas_validas(): void
    {
        [$proyecto, , $token] = $this->setup();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/confirm", [
                'filas' => [
                    ['codigo' => '3600', 'nombre' => 'Estructura', 'nivel' => 'area',
                     'codigo_padre_de_quiebre' => null, 'status' => 'valid', 'decision' => 'import'],
                    ['codigo' => '3700', 'nombre' => 'Arquitectura', 'nivel' => 'area',
                     'codigo_padre_de_quiebre' => null, 'status' => 'valid', 'decision' => 'import'],
                ],
            ])
            ->assertStatus(200)
            ->assertJson(['importadas' => 2, 'omitidas' => 0, 'errores' => 0]);

        $this->assertDatabaseHas('areas', ['proyecto_id' => $proyecto->id, 'codigo' => '3600']);
        $this->assertDatabaseHas('areas', ['proyecto_id' => $proyecto->id, 'codigo' => '3700']);
    }

    public function test_confirm_registra_log_por_fila_importada(): void
    {
        [$proyecto, $admin, $token] = $this->setup();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/confirm", [
                'filas' => [
                    ['codigo' => '3600', 'nombre' => 'Estructura', 'nivel' => 'area',
                     'codigo_padre_de_quiebre' => null, 'status' => 'valid', 'decision' => 'import'],
                ],
            ]);

        $this->assertDatabaseHas('areas_log', [
            'proyecto_id' => $proyecto->id,
            'usuario_id'  => $admin->id,
            'accion'      => 'IMPORT',
        ]);
    }

    public function test_confirm_registra_log_dismiss_al_omitir_duplicado(): void
    {
        [$proyecto, $admin, $token] = $this->setup();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/confirm", [
                'filas' => [
                    ['codigo' => '3600', 'nombre' => 'Estructura', 'nivel' => 'area',
                     'codigo_padre_de_quiebre' => null, 'status' => 'duplicate',
                     'motivo' => 'duplicado', 'fila' => 2, 'decision' => 'skip'],
                ],
            ]);

        $this->assertDatabaseHas('areas_log', [
            'proyecto_id' => $proyecto->id,
            'usuario_id'  => $admin->id,
            'accion'      => 'IMPORT_ERROR_DISMISSED',
        ]);
    }

    public function test_usuario_sin_rol_admin_no_puede_importar(): void
    {
        [$proyecto] = $this->setup();
        $usuario = Usuario::factory()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);
        $token = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/confirm", ['filas' => []])
            ->assertStatus(403);
    }
}
```

- [ ] **Step 11.5: Agregar rutas de import en `api.php`**

Agregar dentro del grupo `proyectos/{proyecto_id}` en `backend/routes/api.php`:
```php
use App\Http\Controllers\ImportController;

Route::get('/import/template', [ImportController::class, 'template']);
Route::post('/import/preview', [ImportController::class, 'preview']);
Route::post('/import/confirm', [ImportController::class, 'confirm'])->middleware('check.role:admin');
```

- [ ] **Step 11.6: Ejecutar tests**

```bash
cd backend
php artisan test tests/Feature/ImportTest.php
```

Esperado:
```
PASS  Tests\Feature\ImportTest
✓ descarga plantilla
✓ preview clasifica filas validas y duplicadas
✓ preview detecta padre inexistente
✓ preview falla si faltan columnas
✓ confirm importa filas validas
✓ confirm registra log por fila importada
✓ confirm registra log dismiss al omitir duplicado
✓ usuario sin rol admin no puede importar

Tests: 8 passed
```

- [ ] **Step 11.7: Suite completa — sin regresiones**

```bash
php artisan test
```

Esperado: 58 tests pasan.

- [ ] **Step 11.8: Commit**

```bash
cd ..
git add backend/app/Exports/ \
        backend/app/Services/ImportService.php \
        backend/app/Http/Controllers/ImportController.php \
        backend/routes/api.php \
        backend/tests/Feature/ImportTest.php
git commit -m "feat: add Excel import (preview + confirm) with template download and audit log"
```

---

## Task 12: Admin API — Usuarios, TiposUsuario, Asignaciones + Tests

**Files:**
- Create: `backend/app/Http/Controllers/Admin/UsuarioController.php`
- Create: `backend/app/Http/Controllers/Admin/TipoUsuarioController.php`
- Create: `backend/app/Http/Controllers/Admin/AsignacionController.php`
- Create: `backend/tests/Feature/AdminTest.php`
- Modify: `backend/routes/api.php`

- [ ] **Step 12.1: Escribir los tests primero (TDD)**

`backend/tests/Feature/AdminTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Proyecto;
use App\Models\TipoUsuario;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): array
    {
        $su    = Usuario::factory()->superuser()->create();
        $token = $su->createToken('test')->plainTextToken;
        return [$su, $token];
    }

    private function usuarioToken(): string
    {
        $u = Usuario::factory()->create();
        return $u->createToken('test')->plainTextToken;
    }

    // ─── Usuarios ────────────────────────────────────────────────

    public function test_superuser_puede_listar_usuarios(): void
    {
        [, $token] = $this->superuserToken();
        Usuario::factory()->count(3)->create();

        $this->withToken($token)->getJson('/api/admin/usuarios')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id','nombre','email','rol_global','activo']]]);
    }

    public function test_usuario_no_puede_acceder_a_admin(): void
    {
        $token = $this->usuarioToken();

        $this->withToken($token)->getJson('/api/admin/usuarios')->assertStatus(403);
    }

    public function test_superuser_puede_crear_usuario(): void
    {
        [, $token] = $this->superuserToken();

        $response = $this->withToken($token)->postJson('/api/admin/usuarios', [
            'nombre'    => 'Nuevo Usuario',
            'email'     => 'nuevo@test.com',
            'password'  => 'secret1234',
            'rol_global'=> 'usuario',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('email', 'nuevo@test.com');

        $this->assertDatabaseHas('usuarios', ['email' => 'nuevo@test.com']);
    }

    public function test_email_duplicado_falla_al_crear_usuario(): void
    {
        [, $token] = $this->superuserToken();
        Usuario::factory()->create(['email' => 'existe@test.com']);

        $this->withToken($token)->postJson('/api/admin/usuarios', [
            'nombre'   => 'Otro',
            'email'    => 'existe@test.com',
            'password' => 'secret1234',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_superuser_puede_editar_usuario(): void
    {
        [, $token] = $this->superuserToken();
        $usuario = Usuario::factory()->create();

        $this->withToken($token)->putJson("/api/admin/usuarios/{$usuario->id}", [
            'nombre' => 'Nombre cambiado',
            'activo' => false,
        ])->assertStatus(200)->assertJsonPath('nombre', 'Nombre cambiado');
    }

    public function test_superuser_puede_eliminar_usuario(): void
    {
        [, $token] = $this->superuserToken();
        $usuario = Usuario::factory()->create();

        $this->withToken($token)->deleteJson("/api/admin/usuarios/{$usuario->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('usuarios', ['id' => $usuario->id]);
    }

    // ─── Tipos de usuario ────────────────────────────────────────

    public function test_superuser_puede_crear_tipo_usuario(): void
    {
        [, $token] = $this->superuserToken();

        $this->withToken($token)->postJson('/api/admin/tipos-usuario', [
            'nombre'      => 'Calidad',
            'descripcion' => 'Inspector de calidad',
        ])->assertStatus(201)->assertJsonPath('nombre', 'Calidad');

        $this->assertDatabaseHas('tipos_usuario', ['nombre' => 'Calidad']);
    }

    public function test_superuser_puede_listar_tipos_usuario(): void
    {
        [, $token] = $this->superuserToken();
        TipoUsuario::factory()->count(2)->create();

        $this->withToken($token)->getJson('/api/admin/tipos-usuario')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_superuser_puede_editar_tipo_usuario(): void
    {
        [, $token] = $this->superuserToken();
        $tipo = TipoUsuario::factory()->create(['nombre' => 'Original']);

        $this->withToken($token)->putJson("/api/admin/tipos-usuario/{$tipo->id}", [
            'nombre' => 'Actualizado',
        ])->assertStatus(200)->assertJsonPath('nombre', 'Actualizado');
    }

    // ─── Asignaciones ────────────────────────────────────────────

    public function test_superuser_puede_asignar_usuario_a_proyecto(): void
    {
        [, $token] = $this->superuserToken();
        $usuario  = Usuario::factory()->create();
        $proyecto = Proyecto::factory()->create();

        $this->withToken($token)->postJson('/api/admin/asignaciones', [
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ])->assertStatus(201);

        $this->assertDatabaseHas('usuarios_proyectos', [
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
    }

    public function test_asignacion_duplicada_falla(): void
    {
        [, $token] = $this->superuserToken();
        $usuario  = Usuario::factory()->create();
        $proyecto = Proyecto::factory()->create();

        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);

        $this->withToken($token)->postJson('/api/admin/asignaciones', [
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ])->assertStatus(422);
    }

    public function test_superuser_puede_revocar_asignacion(): void
    {
        [, $token] = $this->superuserToken();
        $asignacion = UsuarioProyecto::factory()->create();

        $this->withToken($token)->deleteJson("/api/admin/asignaciones/{$asignacion->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('usuarios_proyectos', ['id' => $asignacion->id]);
    }

    public function test_superuser_puede_listar_asignaciones(): void
    {
        [, $token] = $this->superuserToken();
        UsuarioProyecto::factory()->count(3)->create();

        $this->withToken($token)->getJson('/api/admin/asignaciones')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }
}
```

- [ ] **Step 12.2: Agregar factory de `TipoUsuario` y `UsuarioProyecto`**

`backend/database/factories/TipoUsuarioFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\TipoUsuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class TipoUsuarioFactory extends Factory
{
    protected $model = TipoUsuario::class;

    public function definition(): array
    {
        return [
            'nombre'      => fake()->unique()->word(),
            'descripcion' => fake()->sentence(),
            'activo'      => true,
        ];
    }
}
```

`backend/database/factories/UsuarioProyectoFactory.php`:
```php
<?php

namespace Database\Factories;

use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsuarioProyectoFactory extends Factory
{
    protected $model = UsuarioProyecto::class;

    public function definition(): array
    {
        return [
            'usuario_id'  => Usuario::factory(),
            'proyecto_id' => Proyecto::factory(),
            'rol'         => 'usuario',
            'tipo_id'     => null,
        ];
    }
}
```

- [ ] **Step 12.3: Ejecutar tests — deben fallar**

```bash
cd backend
php artisan test tests/Feature/AdminTest.php
```

Esperado: todos fallan con error de ruta no encontrada.

- [ ] **Step 12.4: Crear `Admin/UsuarioController`**

`backend/app/Http/Controllers/Admin/UsuarioController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Usuario::orderBy('nombre')
                ->get(['id','nombre','email','rol_global','activo','created_at']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'     => 'required|string|max:255',
            'email'      => 'required|email|unique:usuarios,email',
            'password'   => 'required|string|min:8',
            'rol_global' => 'in:admin,usuario',
        ]);

        $usuario = Usuario::create([
            'nombre'        => $data['nombre'],
            'email'         => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'rol_global'    => $data['rol_global'] ?? 'usuario',
        ]);

        LogService::log(
            tabla:        'usuarios',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $usuario->id,
            datosDespues: $usuario->only(['id','nombre','email','rol_global']),
            ip:           $request->ip()
        );

        return response()->json(
            $usuario->only(['id','nombre','email','rol_global','activo']), 201
        );
    }

    public function update(Request $request, int $id)
    {
        $usuario = Usuario::findOrFail($id);

        $data = $request->validate([
            'nombre'     => 'string|max:255',
            'email'      => "email|unique:usuarios,email,{$id}",
            'password'   => 'string|min:8',
            'rol_global' => 'in:admin,usuario',
            'activo'     => 'boolean',
        ]);

        $antes = $usuario->only(['id','nombre','email','rol_global','activo']);

        if (isset($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        $usuario->update($data);

        LogService::log(
            tabla:        'usuarios',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'UPDATE',
            entidadId:    $usuario->id,
            datosAntes:   $antes,
            datosDespues: $usuario->fresh()->only(['id','nombre','email','rol_global','activo']),
            ip:           $request->ip()
        );

        return response()->json(
            $usuario->fresh()->only(['id','nombre','email','rol_global','activo'])
        );
    }

    public function destroy(Request $request, int $id)
    {
        $usuario = Usuario::findOrFail($id);

        LogService::log(
            tabla:      'usuarios',
            proyectoId: null,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $usuario->id,
            datosAntes: $usuario->only(['id','nombre','email','rol_global']),
            ip:         $request->ip()
        );

        $usuario->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 12.5: Crear `Admin/TipoUsuarioController`**

`backend/app/Http/Controllers/Admin/TipoUsuarioController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoUsuario;
use Illuminate\Http\Request;

class TipoUsuarioController extends Controller
{
    public function index()
    {
        return response()->json(['data' => TipoUsuario::orderBy('nombre')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:100|unique:tipos_usuario,nombre',
            'descripcion' => 'nullable|string|max:255',
        ]);

        $tipo = TipoUsuario::create($data);

        return response()->json($tipo, 201);
    }

    public function update(Request $request, int $id)
    {
        $tipo = TipoUsuario::findOrFail($id);

        $data = $request->validate([
            'nombre'      => "string|max:100|unique:tipos_usuario,nombre,{$id}",
            'descripcion' => 'nullable|string|max:255',
            'activo'      => 'boolean',
        ]);

        $tipo->update($data);

        return response()->json($tipo->fresh());
    }

    public function destroy(int $id)
    {
        TipoUsuario::findOrFail($id)->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 12.6: Crear `Admin/AsignacionController`**

`backend/app/Http/Controllers/Admin/AsignacionController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsuarioProyecto;
use App\Services\LogService;
use Illuminate\Http\Request;

class AsignacionController extends Controller
{
    public function index()
    {
        $asignaciones = UsuarioProyecto::with(['usuario:id,nombre,email', 'proyecto:id,codigo,nombre'])
            ->orderBy('proyecto_id')
            ->get();

        return response()->json(['data' => $asignaciones]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id'  => 'required|exists:usuarios,id',
            'proyecto_id' => 'required|exists:proyectos,id',
            'rol'         => 'required|in:admin,usuario',
            'tipo_id'     => 'nullable|exists:tipos_usuario,id',
        ]);

        // Verificar que no exista ya la asignación
        $existe = UsuarioProyecto::where('usuario_id', $data['usuario_id'])
            ->where('proyecto_id', $data['proyecto_id'])
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'El usuario ya tiene asignación en este proyecto',
                'errors'  => ['usuario_id' => ['Ya existe una asignación para este usuario y proyecto']],
            ], 422);
        }

        $asignacion = UsuarioProyecto::create($data);

        LogService::log(
            tabla:        'usuarios_proyectos',
            proyectoId:   $data['proyecto_id'],
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $asignacion->id,
            datosDespues: $asignacion->toArray(),
            ip:           $request->ip()
        );

        return response()->json($asignacion, 201);
    }

    public function destroy(Request $request, int $id)
    {
        $asignacion = UsuarioProyecto::findOrFail($id);

        LogService::log(
            tabla:      'usuarios_proyectos',
            proyectoId: $asignacion->proyecto_id,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $asignacion->id,
            datosAntes: $asignacion->toArray(),
            ip:         $request->ip()
        );

        $asignacion->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 12.7: Agregar rutas admin en `api.php`**

Agregar dentro del grupo `auth:sanctum` en `backend/routes/api.php`:
```php
use App\Http\Controllers\Admin\AsignacionController;
use App\Http\Controllers\Admin\TipoUsuarioController;
use App\Http\Controllers\Admin\UsuarioController as AdminUsuarioController;

Route::prefix('admin')->middleware('check.role:superuser')->group(function () {

    Route::get('/usuarios',              [AdminUsuarioController::class, 'index']);
    Route::post('/usuarios',             [AdminUsuarioController::class, 'store']);
    Route::put('/usuarios/{id}',         [AdminUsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}',      [AdminUsuarioController::class, 'destroy']);

    Route::get('/tipos-usuario',         [TipoUsuarioController::class, 'index']);
    Route::post('/tipos-usuario',        [TipoUsuarioController::class, 'store']);
    Route::put('/tipos-usuario/{id}',    [TipoUsuarioController::class, 'update']);
    Route::delete('/tipos-usuario/{id}', [TipoUsuarioController::class, 'destroy']);

    Route::get('/asignaciones',          [AsignacionController::class, 'index']);
    Route::post('/asignaciones',         [AsignacionController::class, 'store']);
    Route::delete('/asignaciones/{id}',  [AsignacionController::class, 'destroy']);

});
```

- [ ] **Step 12.8: Ejecutar tests — deben pasar**

```bash
php artisan test tests/Feature/AdminTest.php
```

Esperado:
```
PASS  Tests\Feature\AdminTest
✓ superuser puede listar usuarios
✓ usuario no puede acceder a admin
✓ superuser puede crear usuario
✓ email duplicado falla al crear usuario
✓ superuser puede editar usuario
✓ superuser puede eliminar usuario
✓ superuser puede crear tipo usuario
✓ superuser puede listar tipos usuario
✓ superuser puede editar tipo usuario
✓ superuser puede asignar usuario a proyecto
✓ asignacion duplicada falla
✓ superuser puede revocar asignacion
✓ superuser puede listar asignaciones

Tests: 13 passed
```

- [ ] **Step 12.9: Suite completa — sin regresiones**

```bash
php artisan test
```

Esperado: 71 tests pasan.

- [ ] **Step 12.10: Commit**

```bash
cd ..
git add backend/app/Http/Controllers/Admin/ \
        backend/database/factories/TipoUsuarioFactory.php \
        backend/database/factories/UsuarioProyectoFactory.php \
        backend/routes/api.php \
        backend/tests/Feature/AdminTest.php
git commit -m "feat: add Admin API (usuarios, tipos, asignaciones) with 71 passing tests"
```

---

## Task 13: Admin Logs API + Seeder SUPERUSER

**Files:**
- Create: `backend/app/Http/Controllers/Admin/LogController.php`
- Create: `backend/database/seeders/SuperuserSeeder.php`
- Create: `backend/tests/Feature/LogTest.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/database/seeders/DatabaseSeeder.php`

- [ ] **Step 13.1: Escribir los tests primero (TDD)**

`backend/tests/Feature/LogTest.php`:
```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use App\Services\LogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): string
    {
        $su = Usuario::factory()->superuser()->create();
        return $su->createToken('test')->plainTextToken;
    }

    public function test_superuser_puede_ver_log_global(): void
    {
        $token    = $this->superuserToken();
        $proyecto = Proyecto::factory()->create();
        $su       = Usuario::where('rol_global', 'superuser')->first();

        // Generar entradas en distintas tablas de log
        LogService::log('areas',     $proyecto->id, $su->id, 'CREATE', 1);
        LogService::log('subareas',  $proyecto->id, $su->id, 'CREATE', 2);
        LogService::log('proyectos', $proyecto->id, $su->id, 'UPDATE', $proyecto->id);

        $response = $this->withToken($token)->getJson('/api/admin/logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['origen', 'id', 'proyecto_id', 'usuario_id', 'accion', 'created_at']],
                'total',
            ]);

        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    public function test_log_se_puede_filtrar_por_proyecto(): void
    {
        $token     = $this->superuserToken();
        $su        = Usuario::where('rol_global', 'superuser')->first();
        $proyecto1 = Proyecto::factory()->create();
        $proyecto2 = Proyecto::factory()->create();

        LogService::log('areas', $proyecto1->id, $su->id, 'CREATE', 1);
        LogService::log('areas', $proyecto1->id, $su->id, 'CREATE', 2);
        LogService::log('areas', $proyecto2->id, $su->id, 'CREATE', 3);

        $response = $this->withToken($token)
            ->getJson("/api/admin/logs?proyecto_id={$proyecto1->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        foreach ($data as $row) {
            $this->assertEquals($proyecto1->id, $row['proyecto_id']);
        }
    }

    public function test_log_ordena_por_fecha_descendente(): void
    {
        $token    = $this->superuserToken();
        $su       = Usuario::where('rol_global', 'superuser')->first();
        $proyecto = Proyecto::factory()->create();

        LogService::log('proyectos', $proyecto->id, $su->id, 'CREATE', $proyecto->id);
        LogService::log('areas',     $proyecto->id, $su->id, 'CREATE', 1);

        $response = $this->withToken($token)->getJson('/api/admin/logs');
        $data     = $response->json('data');

        $this->assertGreaterThanOrEqual(
            $data[1]['created_at'],
            $data[0]['created_at']
        );
    }

    public function test_usuario_no_puede_ver_log_global(): void
    {
        $usuario = Usuario::factory()->create();
        $token   = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/logs')->assertStatus(403);
    }

    public function test_log_respeta_limite_de_200_entradas(): void
    {
        $token    = $this->superuserToken();
        $su       = Usuario::where('rol_global', 'superuser')->first();
        $proyecto = Proyecto::factory()->create();

        // Insertar 250 entradas
        for ($i = 1; $i <= 250; $i++) {
            LogService::log('areas', $proyecto->id, $su->id, 'CREATE', $i);
        }

        $response = $this->withToken($token)->getJson('/api/admin/logs');

        $this->assertLessThanOrEqual(200, count($response->json('data')));
    }
}
```

- [ ] **Step 13.2: Ejecutar tests — deben fallar**

```bash
cd backend
php artisan test tests/Feature/LogTest.php
```

Esperado: todos fallan con error de ruta no encontrada.

- [ ] **Step 13.3: Crear `Admin/LogController`**

`backend/app/Http/Controllers/Admin/LogController.php`:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogController extends Controller
{
    private const TABLAS = [
        'areas', 'subareas', 'sistemas', 'subsistemas',
        'proyectos', 'usuarios', 'usuarios_proyectos',
    ];

    public function index(Request $request)
    {
        $proyectoId = $request->query('proyecto_id');

        $queries = array_map(function (string $tabla) use ($proyectoId) {
            $query = DB::table("{$tabla}_log")
                ->select(
                    DB::raw("'{$tabla}' as origen"),
                    'id',
                    'proyecto_id',
                    'usuario_id',
                    'accion',
                    'entidad_id',
                    'created_at'
                );

            if ($proyectoId) {
                $query->where('proyecto_id', $proyectoId);
            }

            return $query;
        }, self::TABLAS);

        // UNION ALL de las 7 tablas
        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        $resultados = DB::query()
            ->fromSub($union, 'logs_union')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data'  => $resultados,
            'total' => $resultados->count(),
        ]);
    }
}
```

- [ ] **Step 13.4: Agregar ruta de logs en `api.php`**

Dentro del grupo `admin` existente en `backend/routes/api.php`, agregar:
```php
use App\Http\Controllers\Admin\LogController;

Route::get('/logs', [LogController::class, 'index']);
```

El grupo admin completo queda:
```php
Route::prefix('admin')->middleware('check.role:superuser')->group(function () {
    Route::get('/usuarios',              [AdminUsuarioController::class, 'index']);
    Route::post('/usuarios',             [AdminUsuarioController::class, 'store']);
    Route::put('/usuarios/{id}',         [AdminUsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}',      [AdminUsuarioController::class, 'destroy']);

    Route::get('/tipos-usuario',         [TipoUsuarioController::class, 'index']);
    Route::post('/tipos-usuario',        [TipoUsuarioController::class, 'store']);
    Route::put('/tipos-usuario/{id}',    [TipoUsuarioController::class, 'update']);
    Route::delete('/tipos-usuario/{id}', [TipoUsuarioController::class, 'destroy']);

    Route::get('/asignaciones',          [AsignacionController::class, 'index']);
    Route::post('/asignaciones',         [AsignacionController::class, 'store']);
    Route::delete('/asignaciones/{id}',  [AsignacionController::class, 'destroy']);

    Route::get('/logs',                  [LogController::class, 'index']);
});
```

- [ ] **Step 13.5: Ejecutar tests — deben pasar**

```bash
php artisan test tests/Feature/LogTest.php
```

Esperado:
```
PASS  Tests\Feature\LogTest
✓ superuser puede ver log global
✓ log se puede filtrar por proyecto
✓ log ordena por fecha descendente
✓ usuario no puede ver log global
✓ log respeta limite de 200 entradas

Tests: 5 passed
```

- [ ] **Step 13.6: Crear el seeder del SUPERUSER inicial**

`backend/database/seeders/SuperuserSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperuserSeeder extends Seeder
{
    public function run(): void
    {
        if (Usuario::where('rol_global', 'superuser')->exists()) {
            $this->command->info('SUPERUSER ya existe, omitiendo.');
            return;
        }

        $email    = env('SUPERUSER_EMAIL', 'admin@apphub.cl');
        $password = env('SUPERUSER_PASSWORD', 'changeme123');

        Usuario::create([
            'nombre'        => 'Administrador',
            'email'         => $email,
            'password_hash' => Hash::make($password),
            'rol_global'    => 'superuser',
            'activo'        => true,
        ]);

        $this->command->info("SUPERUSER creado: {$email}");
    }
}
```

- [ ] **Step 13.7: Registrar seeder en `DatabaseSeeder`**

`backend/database/seeders/DatabaseSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SuperuserSeeder::class);
    }
}
```

- [ ] **Step 13.8: Ejecutar seeder y verificar**

Agregar en `backend/.env`:
```env
SUPERUSER_EMAIL=luisgarnica@hotmail.cl
SUPERUSER_PASSWORD=TuPasswordSeguro123
```

```bash
cd backend
php artisan db:seed
```

Esperado: `SUPERUSER creado: luisgarnica@hotmail.cl`

Verificar login con curl:
```bash
curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"luisgarnica@hotmail.cl","password":"TuPasswordSeguro123"}' | jq .
```

Esperado: respuesta con `token` y `rol_global: "superuser"`.

- [ ] **Step 13.9: Suite completa — sin regresiones**

```bash
php artisan test
```

Esperado: **76 tests pasan** — backend completo.

- [ ] **Step 13.10: Commit**

```bash
cd ..
git add backend/app/Http/Controllers/Admin/LogController.php \
        backend/routes/api.php \
        backend/database/seeders/ \
        backend/tests/Feature/LogTest.php
git commit -m "feat: add Logs API + SuperuserSeeder — backend complete with 76 passing tests"
```
