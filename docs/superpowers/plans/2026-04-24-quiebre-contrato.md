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

---

## Task 14: Frontend base — app.css + api.js

**Files:**
- Create: `backend/public/assets/css/app.css`
- Create: `backend/public/assets/js/api.js`

Estos dos archivos son compartidos por todas las páginas HTML. `api.js` gestiona el token y abstrae todos los llamados al API. `app.css` define el tema visual oscuro consistente con los mockups aprobados.

- [ ] **Step 14.1: Crear `app.css`**

`backend/public/assets/css/app.css`:
```css
/* ── Reset y base ─────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:        #08090C;
  --bg-card:   #0D0E12;
  --bg-row:    #111318;
  --border:    rgba(255,255,255,0.08);
  --border-lo: rgba(255,255,255,0.04);
  --text:      #F0F2F8;
  --text-muted:#7A7F96;
  --text-dim:  #4A4F66;
  --blue:      #4F7EFF;
  --blue-soft: #8AABFF;
  --red:       #FF6B6B;
  --green:     #2ECC8A;
  --orange:    #FFB347;
}

body {
  background: var(--bg);
  color: var(--text);
  font-family: system-ui, -apple-system, sans-serif;
  font-size: 14px;
  line-height: 1.5;
  min-height: 100vh;
}

/* ── Tipografía ────────────────────────────────────────── */
h1 { font-size: 20px; font-weight: 700; }
h2 { font-size: 16px; font-weight: 700; }
h3 { font-size: 13px; font-weight: 600; }
.label {
  font-size: 11px; font-weight: 600;
  letter-spacing: 0.8px; text-transform: uppercase;
  color: var(--text-dim);
}
.subtitle { font-size: 12px; color: var(--text-muted); }

/* ── Layout ────────────────────────────────────────────── */
.container { max-width: 1200px; margin: 0 auto; padding: 24px; }
.page-header {
  display: flex; align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;
}

/* ── Navbar ────────────────────────────────────────────── */
.navbar {
  background: var(--bg-card);
  border-bottom: 1px solid var(--border);
  padding: 0 24px;
  height: 52px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 100;
}
.navbar-brand { font-size: 15px; font-weight: 700; color: var(--text); text-decoration: none; }
.navbar-user  { font-size: 12px; color: var(--text-muted); display: flex; align-items: center; gap: 12px; }

/* ── Cards ─────────────────────────────────────────────── */
.card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 20px;
}
.cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 16px; }
.card-selectable {
  cursor: pointer; transition: border-color 0.15s, transform 0.1s;
}
.card-selectable:hover {
  border-color: var(--blue);
  transform: translateY(-2px);
}

/* ── Badges ─────────────────────────────────────────────── */
.badge {
  display: inline-block; padding: 2px 8px;
  border-radius: 100px; font-size: 10px; font-weight: 600;
}
.badge-su      { background: rgba(255,107,107,0.1); color: var(--red);   border: 1px solid rgba(255,107,107,0.25); }
.badge-admin   { background: rgba(79,126,255,0.1);  color: var(--blue-soft); border: 1px solid rgba(79,126,255,0.25); }
.badge-usuario { background: rgba(46,204,138,0.08); color: var(--green); border: 1px solid rgba(46,204,138,0.2); }
.badge-activo  { background: rgba(46,204,138,0.08); color: var(--green); }
.badge-archivado { background: rgba(255,255,255,0.05); color: var(--text-dim); }

/* ── Tabla ──────────────────────────────────────────────── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th {
  background: var(--bg-row);
  color: var(--text-dim);
  font-size: 11px; font-weight: 600;
  text-transform: uppercase; letter-spacing: 0.5px;
  padding: 8px 12px; text-align: left;
  border-bottom: 1px solid var(--border);
}
td {
  padding: 9px 12px;
  border-bottom: 1px solid var(--border-lo);
  color: var(--text);
}
tr:hover td { background: rgba(255,255,255,0.02); }
.code { font-family: monospace; font-size: 12px; color: var(--blue-soft); }

/* ── Árbol jerárquico ────────────────────────────────────── */
.tree-row { cursor: pointer; }
.tree-indent-1 td:first-child { padding-left: 28px; }
.tree-indent-2 td:first-child { padding-left: 52px; }
.tree-indent-3 td:first-child { padding-left: 76px; }
.tree-toggle { color: var(--text-dim); margin-right: 6px; display: inline-block; width: 12px; }

/* ── Formularios ─────────────────────────────────────────── */
.form-group { margin-bottom: 16px; }
label { display: block; font-size: 12px; color: var(--text-muted); margin-bottom: 6px; }
input[type="text"], input[type="email"], input[type="password"],
select, textarea {
  width: 100%;
  background: var(--bg-row);
  border: 1px solid var(--border);
  border-radius: 7px;
  color: var(--text);
  font-size: 13px;
  padding: 8px 12px;
  outline: none;
  transition: border-color 0.15s;
}
input:focus, select:focus, textarea:focus { border-color: var(--blue); }
.field-error { font-size: 11px; color: var(--red); margin-top: 4px; }
input.is-error { border-color: var(--red); }

/* ── Botones ─────────────────────────────────────────────── */
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 16px; border-radius: 7px;
  font-size: 13px; font-weight: 500; cursor: pointer;
  border: none; transition: opacity 0.15s;
}
.btn:hover { opacity: 0.85; }
.btn:disabled { opacity: 0.4; cursor: not-allowed; }
.btn-primary  { background: var(--blue); color: #fff; }
.btn-danger   { background: rgba(255,107,107,0.15); color: var(--red); border: 1px solid rgba(255,107,107,0.3); }
.btn-ghost    { background: transparent; color: var(--text-muted); border: 1px solid var(--border); }
.btn-sm       { padding: 4px 10px; font-size: 11px; border-radius: 5px; }

/* ── Modal ──────────────────────────────────────────────── */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.6);
  display: flex; align-items: center; justify-content: center;
  z-index: 200;
}
.modal-overlay.hidden { display: none; }
.modal {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 24px;
  width: 480px; max-width: 95vw;
  max-height: 90vh; overflow-y: auto;
}
.modal-header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 20px;
}
.modal-close {
  background: none; border: none; color: var(--text-muted);
  font-size: 18px; cursor: pointer;
}

/* ── Tabs ───────────────────────────────────────────────── */
.tabs { display: flex; gap: 2px; margin-bottom: 20px; }
.tab {
  padding: 7px 14px; font-size: 12px; border-radius: 6px;
  cursor: pointer; color: var(--text-muted); border: none; background: none;
}
.tab.active { background: rgba(79,126,255,0.1); color: var(--blue-soft); }

/* ── Alerts ─────────────────────────────────────────────── */
.alert { padding: 10px 14px; border-radius: 7px; font-size: 12px; margin-bottom: 14px; }
.alert-error   { background: rgba(255,107,107,0.1); color: var(--red);   border: 1px solid rgba(255,107,107,0.25); }
.alert-success { background: rgba(46,204,138,0.08); color: var(--green); border: 1px solid rgba(46,204,138,0.2); }
.alert.hidden  { display: none; }

/* ── Utilidades ──────────────────────────────────────────── */
.hidden    { display: none !important; }
.text-muted{ color: var(--text-muted); }
.text-red  { color: var(--red); }
.text-green{ color: var(--green); }
.text-blue { color: var(--blue-soft); }
.mt-8  { margin-top: 8px;  }
.mt-16 { margin-top: 16px; }
.mt-24 { margin-top: 24px; }
.flex  { display: flex; }
.flex-between { display: flex; justify-content: space-between; align-items: center; }
.gap-8 { gap: 8px; }
```

- [ ] **Step 14.2: Crear `api.js`**

`backend/public/assets/js/api.js`:
```javascript
const API_BASE = '/api';
const TOKEN_KEY = 'apphub_token';
const USER_KEY  = 'apphub_user';

// ── Token y sesión ──────────────────────────────────────────

export function getToken() {
    return localStorage.getItem(TOKEN_KEY);
}

export function getUser() {
    const raw = localStorage.getItem(USER_KEY);
    return raw ? JSON.parse(raw) : null;
}

export function saveSession(token, usuario) {
    localStorage.setItem(TOKEN_KEY, token);
    localStorage.setItem(USER_KEY, JSON.stringify(usuario));
}

export function clearSession() {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
}

export function isSuperuser() {
    return getUser()?.rol_global === 'superuser';
}

export function requireAuth() {
    if (!getToken()) {
        window.location.href = '/app/login.html';
    }
}

// ── Fetch wrapper ───────────────────────────────────────────

export async function apiFetch(path, options = {}) {
    const token = getToken();

    const headers = {
        'Accept': 'application/json',
        ...(options.body && !(options.body instanceof FormData)
            ? { 'Content-Type': 'application/json' }
            : {}),
        ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
        ...(options.headers || {}),
    };

    const res = await fetch(`${API_BASE}${path}`, {
        ...options,
        headers,
        body: options.body instanceof FormData
            ? options.body
            : options.body ? JSON.stringify(options.body) : undefined,
    });

    if (res.status === 401) {
        clearSession();
        window.location.href = '/app/login.html';
        return;
    }

    return res;
}

// ── Helpers JSON ────────────────────────────────────────────

export async function apiGet(path) {
    const res = await apiFetch(path);
    if (!res.ok) throw await res.json();
    return res.json();
}

export async function apiPost(path, body) {
    const res = await apiFetch(path, { method: 'POST', body });
    if (!res.ok) throw await res.json();
    return res.json();
}

export async function apiPut(path, body) {
    const res = await apiFetch(path, { method: 'PUT', body });
    if (!res.ok) throw await res.json();
    return res.json();
}

export async function apiDelete(path) {
    const res = await apiFetch(path, { method: 'DELETE' });
    if (!res.ok) throw await res.json();
    return res;
}

// ── Manejo de errores de validación ────────────────────────

export function showValidationErrors(errorsObj, formEl) {
    // Limpiar errores previos
    formEl.querySelectorAll('.field-error').forEach(el => el.remove());
    formEl.querySelectorAll('.is-error').forEach(el => el.classList.remove('is-error'));

    if (!errorsObj?.errors) return;

    for (const [field, messages] of Object.entries(errorsObj.errors)) {
        const input = formEl.querySelector(`[name="${field}"]`);
        if (input) {
            input.classList.add('is-error');
            const span = document.createElement('span');
            span.className = 'field-error';
            span.textContent = messages[0];
            input.parentElement.appendChild(span);
        }
    }
}
```

- [ ] **Step 14.3: Crear directorio `app/` en `public/`**

```bash
cd backend
mkdir -p public/app
mkdir -p public/assets/css
mkdir -p public/assets/js
```

Verificar que los archivos estén en sus rutas:
```
backend/public/assets/css/app.css   ✓
backend/public/assets/js/api.js     ✓
backend/public/app/                 ✓ (vacío por ahora)
```

- [ ] **Step 14.4: Verificar que el servidor sirve los assets**

```bash
php artisan serve &
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/assets/css/app.css
```

Esperado: `200`

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:8000/assets/js/api.js
```

Esperado: `200`

- [ ] **Step 14.5: Commit**

```bash
cd ..
git add backend/public/assets/
git commit -m "feat: add base CSS (dark theme) and api.js fetch wrapper"
```

---

## Task 15: login.html

**Files:**
- Create: `backend/public/app/login.html`

- [ ] **Step 15.1: Crear `login.html`**

`backend/public/app/login.html`:
```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>AppHub — Iniciar sesión</title>
  <link rel="stylesheet" href="/assets/css/app.css"/>
  <style>
    .login-wrap {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .login-box {
      width: 360px;
    }
    .login-logo {
      text-align: center;
      margin-bottom: 32px;
    }
    .login-logo h1 {
      font-size: 24px;
      letter-spacing: -0.5px;
    }
    .login-logo .subtitle {
      margin-top: 4px;
    }
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="login-box">

      <div class="login-logo">
        <h1>AppHub</h1>
        <p class="subtitle">Plataforma de gestión de proyectos</p>
      </div>

      <div class="card">
        <h2 style="margin-bottom:20px">Iniciar sesión</h2>

        <div id="alert-error" class="alert alert-error hidden"></div>

        <form id="login-form" novalidate>
          <div class="form-group">
            <label for="email">Correo electrónico</label>
            <input type="email" id="email" name="email"
                   placeholder="usuario@empresa.cl" autocomplete="email"/>
          </div>

          <div class="form-group">
            <label for="password">Contraseña</label>
            <input type="password" id="password" name="password"
                   placeholder="••••••••" autocomplete="current-password"/>
          </div>

          <button type="submit" class="btn btn-primary" style="width:100%;margin-top:8px"
                  id="btn-submit">
            Ingresar
          </button>
        </form>
      </div>

    </div>
  </div>

  <script type="module">
    import { saveSession, getToken } from '/assets/js/api.js';

    // Si ya hay sesión activa, redirigir directamente
    if (getToken()) {
      window.location.href = '/app/selector-proyecto.html';
    }

    const form      = document.getElementById('login-form');
    const alertEl   = document.getElementById('alert-error');
    const btnSubmit = document.getElementById('btn-submit');

    function showError(msg) {
      alertEl.textContent = msg;
      alertEl.classList.remove('hidden');
    }

    function clearError() {
      alertEl.classList.add('hidden');
      alertEl.textContent = '';
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearError();

      const email    = form.email.value.trim();
      const password = form.password.value;

      // Validación frontend mínima
      if (!email || !password) {
        showError('Ingresa tu correo y contraseña.');
        return;
      }

      btnSubmit.disabled = true;
      btnSubmit.textContent = 'Ingresando...';

      try {
        const res = await fetch('/api/auth/login', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body:    JSON.stringify({ email, password }),
        });

        const data = await res.json();

        if (!res.ok) {
          // 401 credenciales inválidas / 403 inactivo / 422 validación
          showError(data.message ?? 'Error al iniciar sesión.');
          return;
        }

        saveSession(data.token, data.usuario);
        window.location.href = '/app/selector-proyecto.html';

      } catch {
        showError('No se pudo conectar al servidor. Verifica tu conexión.');
      } finally {
        btnSubmit.disabled = false;
        btnSubmit.textContent = 'Ingresar';
      }
    });
  </script>
</body>
</html>
```

- [ ] **Step 15.2: Verificar manualmente en el browser**

```bash
cd backend && php artisan serve
```

Abrir `http://localhost:8000/app/login.html`

Pruebas manuales:
1. Enviar formulario vacío → debe mostrar alerta "Ingresa tu correo y contraseña."
2. Ingresar credenciales incorrectas → debe mostrar "Credenciales inválidas"
3. Ingresar credenciales del SUPERUSER (del seeder) → debe redirigir a `selector-proyecto.html` (página no existe aún, da 404 — es esperado)
4. Volver a `login.html` con sesión activa → debe redirigir automáticamente

- [ ] **Step 15.3: Commit**

```bash
cd ..
git add backend/public/app/login.html
git commit -m "feat: add login.html with session management and error handling"
```

---

## Task 16: selector-proyecto.html + selector-app.html

**Files:**
- Create: `backend/public/app/selector-proyecto.html`
- Create: `backend/public/app/selector-app.html`

- [ ] **Step 16.1: Crear `selector-proyecto.html`**

`backend/public/app/selector-proyecto.html`:
```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>AppHub — Proyectos</title>
  <link rel="stylesheet" href="/assets/css/app.css"/>
</head>
<body>

  <nav class="navbar">
    <a class="navbar-brand" href="/app/selector-proyecto.html">AppHub</a>
    <div class="navbar-user">
      <span id="user-name" class="text-muted"></span>
      <button class="btn btn-ghost btn-sm" id="btn-logout">Cerrar sesión</button>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <div>
        <h1>Proyectos</h1>
        <p class="subtitle mt-8">Selecciona el proyecto en el que deseas trabajar</p>
      </div>
      <button id="btn-admin" class="btn btn-danger hidden">Panel SUPERUSER</button>
    </div>

    <div id="alert-error" class="alert alert-error hidden"></div>
    <div id="loading" class="text-muted">Cargando proyectos...</div>

    <div id="projects-grid" class="cards-grid hidden"></div>
    <div id="empty-msg" class="text-muted hidden">
      No tienes proyectos asignados. Contacta al administrador.
    </div>
  </div>

  <script type="module">
    import { requireAuth, getUser, clearSession, apiGet, isSuperuser } from '/assets/js/api.js';

    requireAuth();

    const user      = getUser();
    const loadingEl = document.getElementById('loading');
    const gridEl    = document.getElementById('projects-grid');
    const emptyEl   = document.getElementById('empty-msg');
    const alertEl   = document.getElementById('alert-error');
    const btnAdmin  = document.getElementById('btn-admin');

    document.getElementById('user-name').textContent = user?.nombre ?? '';

    // Mostrar botón admin si es SUPERUSER
    if (isSuperuser()) {
      btnAdmin.classList.remove('hidden');
      btnAdmin.addEventListener('click', () => {
        window.location.href = '/app/superuser.html';
      });
    }

    // Logout
    document.getElementById('btn-logout').addEventListener('click', async () => {
      try {
        await fetch('/api/auth/logout', {
          method:  'POST',
          headers: {
            'Authorization': `Bearer ${localStorage.getItem('apphub_token')}`,
            'Accept': 'application/json',
          },
        });
      } finally {
        clearSession();
        window.location.href = '/app/login.html';
      }
    });

    // Cargar proyectos
    function estadoBadge(estado) {
      return estado === 'activo'
        ? '<span class="badge badge-activo">Activo</span>'
        : '<span class="badge badge-archivado">Archivado</span>';
    }

    function renderProyecto(p) {
      const card = document.createElement('div');
      card.className = 'card card-selectable';
      card.innerHTML = `
        <div class="flex-between" style="margin-bottom:10px">
          <span class="code">${p.codigo}</span>
          ${estadoBadge(p.estado)}
        </div>
        <h3>${p.nombre}</h3>
      `;
      card.addEventListener('click', () => {
        sessionStorage.setItem('proyecto_id',     p.id);
        sessionStorage.setItem('proyecto_codigo', p.codigo);
        sessionStorage.setItem('proyecto_nombre', p.nombre);
        window.location.href = '/app/selector-app.html';
      });
      return card;
    }

    try {
      const { data } = await apiGet('/proyectos');

      loadingEl.classList.add('hidden');

      if (!data.length) {
        emptyEl.classList.remove('hidden');
        return;
      }

      gridEl.classList.remove('hidden');
      data.forEach(p => gridEl.appendChild(renderProyecto(p)));

    } catch {
      loadingEl.classList.add('hidden');
      alertEl.textContent = 'Error al cargar los proyectos.';
      alertEl.classList.remove('hidden');
    }
  </script>
</body>
</html>
```

- [ ] **Step 16.2: Crear `selector-app.html`**

`backend/public/app/selector-app.html`:
```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>AppHub — Aplicaciones</title>
  <link rel="stylesheet" href="/assets/css/app.css"/>
  <style>
    .app-card {
      display: flex;
      align-items: flex-start;
      gap: 16px;
      cursor: pointer;
    }
    .app-icon {
      width: 48px; height: 48px; border-radius: 10px;
      background: rgba(79,126,255,0.1);
      border: 1px solid rgba(79,126,255,0.25);
      display: flex; align-items: center; justify-content: center;
      font-size: 22px; flex-shrink: 0;
    }
    .app-info h3 { margin-bottom: 4px; }
  </style>
</head>
<body>

  <nav class="navbar">
    <div style="display:flex;align-items:center;gap:12px">
      <a class="navbar-brand" href="/app/selector-proyecto.html">AppHub</a>
      <span class="text-muted" style="font-size:12px">›</span>
      <span id="proyecto-nombre" style="font-size:13px;color:var(--text-muted)"></span>
    </div>
    <div class="navbar-user">
      <span id="user-name" class="text-muted"></span>
      <a href="/app/selector-proyecto.html" class="btn btn-ghost btn-sm">Cambiar proyecto</a>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <div>
        <h1>Aplicaciones</h1>
        <p class="subtitle mt-8">Elige la app con la que deseas trabajar</p>
      </div>
    </div>

    <div class="cards-grid" style="max-width:700px">

      <!-- Quiebre del Contrato -->
      <div class="card card-selectable app-card" id="app-quiebre">
        <div class="app-icon">📋</div>
        <div class="app-info">
          <h3>Quiebre del Contrato</h3>
          <p class="subtitle">Gestión de la jerarquía de ítems del contrato: áreas, subáreas, sistemas y subsistemas.</p>
        </div>
      </div>

      <!-- Placeholder para apps futuras -->
      <div class="card" style="opacity:0.35;cursor:not-allowed">
        <div class="app-card">
          <div class="app-icon" style="background:rgba(255,255,255,0.04);border-color:var(--border)">🔜</div>
          <div class="app-info">
            <h3>Próximamente</h3>
            <p class="subtitle">Nuevos módulos en desarrollo.</p>
          </div>
        </div>
      </div>

    </div>
  </div>

  <script type="module">
    import { requireAuth, getUser } from '/assets/js/api.js';

    requireAuth();

    const proyectoId     = sessionStorage.getItem('proyecto_id');
    const proyectoNombre = sessionStorage.getItem('proyecto_nombre');

    // Si no hay proyecto seleccionado, volver al selector
    if (!proyectoId) {
      window.location.href = '/app/selector-proyecto.html';
    }

    document.getElementById('proyecto-nombre').textContent = proyectoNombre ?? '';
    document.getElementById('user-name').textContent       = getUser()?.nombre ?? '';

    document.getElementById('app-quiebre').addEventListener('click', () => {
      window.location.href = '/app/quiebre.html';
    });
  </script>
</body>
</html>
```

- [ ] **Step 16.3: Verificar flujo completo en el browser**

```bash
cd backend && php artisan serve
```

Flujo a verificar:
1. `http://localhost:8000/app/login.html` → login con SUPERUSER
2. Redirige a `selector-proyecto.html` → muestra proyectos (vacío si no hay ninguno) + botón "Panel SUPERUSER" visible
3. Si no hay proyectos, crear uno desde el panel SUPERUSER (o via curl):
```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"luisgarnica@hotmail.cl","password":"TuPasswordSeguro123"}' | jq -r .token)

curl -s -X POST http://localhost:8000/api/proyectos \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codigo":"AUT-001","nombre":"Autopista Norte Tramo 1","estado":"activo"}'
```
4. Recargar `selector-proyecto.html` → aparece la card del proyecto
5. Click en proyecto → va a `selector-app.html` con el nombre del proyecto en el navbar
6. Click en "Quiebre del Contrato" → va a `quiebre.html` (404 por ahora — esperado)
7. Click "Cambiar proyecto" → vuelve al selector de proyectos

- [ ] **Step 16.4: Commit**

```bash
cd ..
git add backend/public/app/selector-proyecto.html \
        backend/public/app/selector-app.html
git commit -m "feat: add selector-proyecto and selector-app pages"
```

---

### Task 17: quiebre.html — tabla jerárquica, formulario cascada y carga Excel

**Files:**
- Create: `backend/public/app/quiebre.html`

Esta es la pantalla principal del módulo: árbol colapsable 4 niveles, modal formulario manual con dropdowns en cascada, y modal de carga Excel con preview/confirm.

- [ ] **Step 17.1: Crear `backend/public/app/quiebre.html`**

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Quiebre del Contrato</title>
  <link rel="stylesheet" href="/assets/css/app.css"/>
  <style>
    /* ── Árbol jerárquico ── */
    .tree { width: 100%; border-collapse: collapse; }
    .tree th {
      background: var(--surface);
      color: var(--text-muted);
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 8px 12px;
      text-align: left;
      border-bottom: 1px solid var(--border);
    }
    .tree td { padding: 7px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); }
    .tree tr:hover td { background: rgba(255,255,255,0.02); }

    /* niveles de indentación */
    .node-toggle { cursor: pointer; user-select: none; margin-right: 6px; color: var(--text-muted); font-size: 10px; }
    .node-code   { font-family: monospace; font-size: 11px; background: rgba(79,126,255,0.08); color: #8AABFF; padding: 2px 7px; border-radius: 4px; }
    .node-name   { font-size: 13px; }
    .level-0 .node-name { font-weight: 700; color: var(--text); }
    .level-1 .node-name { color: var(--text); padding-left: 20px; }
    .level-2 .node-name { color: var(--text-secondary); padding-left: 40px; }
    .level-3 .node-name { color: var(--text-muted); padding-left: 60px; }

    .hidden { display: none; }

    /* ── Modal ── */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.7); z-index: 100;
      align-items: center; justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .modal {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 28px;
      width: 480px;
      max-width: 95vw;
      max-height: 90vh;
      overflow-y: auto;
    }
    .modal-title { font-size: 16px; font-weight: 700; margin-bottom: 20px; }
    .modal-close {
      float: right; background: none; border: none;
      color: var(--text-muted); font-size: 18px; cursor: pointer; margin-top: -4px;
    }

    /* ── Form ── */
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 12px; color: var(--text-secondary); margin-bottom: 5px; }
    .form-group select,
    .form-group input[type="text"] {
      width: 100%;
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 6px;
      color: var(--text);
      padding: 8px 12px;
      font-size: 13px;
    }
    .form-group select:focus,
    .form-group input[type="text"]:focus {
      outline: none; border-color: var(--primary);
    }
    .form-group select:disabled { opacity: 0.4; }
    .field-error { color: #FF6B6B; font-size: 11px; margin-top: 4px; display: none; }
    .field-error.visible { display: block; }
    input.has-error, select.has-error { border-color: #FF6B6B !important; }

    /* ── Tabla preview Excel ── */
    .preview-table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 12px; }
    .preview-table th { background: var(--bg); color: var(--text-muted); padding: 6px 8px; text-align: left; border-bottom: 1px solid var(--border); }
    .preview-table td { padding: 5px 8px; border-bottom: 1px solid rgba(255,255,255,0.04); }
    .row-valid   td { color: var(--text); }
    .row-dup     td { color: #FFB347; }
    .row-error   td { color: #FF6B6B; }
    .row-status  { font-size: 13px; }

    /* ── Drag & drop zone ── */
    .drop-zone {
      border: 2px dashed var(--border);
      border-radius: 10px;
      padding: 32px;
      text-align: center;
      color: var(--text-muted);
      font-size: 13px;
      cursor: pointer;
      transition: border-color 0.2s;
    }
    .drop-zone.over { border-color: var(--primary); color: var(--primary); }
    .drop-zone input[type="file"] { display: none; }
  </style>
</head>
<body>
  <!-- Navbar -->
  <nav class="navbar">
    <div class="nav-left">
      <span class="nav-brand">AppHub</span>
      <span class="nav-sep">/</span>
      <span id="proyecto-codigo" class="nav-project-code"></span>
      <span id="proyecto-nombre" class="nav-project-name"></span>
      <span class="nav-sep">/</span>
      <span class="nav-current">Quiebre del Contrato</span>
    </div>
    <div class="nav-right">
      <span id="user-name" class="nav-user"></span>
      <a href="/app/selector-proyecto.html" class="btn btn-ghost btn-sm">Cambiar proyecto</a>
    </div>
  </nav>

  <div class="container">
    <!-- Encabezado + acciones -->
    <div class="page-header">
      <div>
        <h1>Quiebre del Contrato</h1>
        <p id="total-count" class="subtitle mt-8"></p>
      </div>
      <div class="flex gap-8">
        <input id="search-input" type="text" placeholder="Buscar código o nombre…" class="input-search"/>
        <button id="btn-excel" class="btn btn-secondary">Cargar Excel</button>
        <button id="btn-add"   class="btn btn-primary" style="display:none">+ Agregar</button>
      </div>
    </div>

    <!-- Tabla del árbol -->
    <div class="card" style="padding:0;overflow:hidden">
      <table class="tree" id="tree-table">
        <thead>
          <tr>
            <th>Código</th>
            <th>Nombre</th>
            <th id="th-acciones" style="display:none;width:80px">Acciones</th>
          </tr>
        </thead>
        <tbody id="tree-body">
          <tr><td colspan="3" style="padding:24px;text-align:center;color:var(--text-muted)">Cargando…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══════════════════════════════════════
       Modal: Formulario manual
  ══════════════════════════════════════ -->
  <div class="modal-overlay" id="modal-form">
    <div class="modal">
      <button class="modal-close" id="close-modal-form">✕</button>
      <h2 class="modal-title" id="form-title">Agregar ítem</h2>

      <form id="item-form" novalidate>
        <!-- Nivel -->
        <div class="form-group">
          <label>Nivel *</label>
          <select id="f-nivel">
            <option value="">— Seleccionar nivel —</option>
            <option value="area">Área</option>
            <option value="subarea">Subárea</option>
            <option value="sistema">Sistema</option>
            <option value="subsistema">Subsistema</option>
          </select>
          <div class="field-error" id="err-nivel">Selecciona un nivel.</div>
        </div>

        <!-- Área padre (visible para subarea/sistema/subsistema) -->
        <div class="form-group" id="group-area" style="display:none">
          <label>Área *</label>
          <select id="f-area">
            <option value="">— Seleccionar área —</option>
          </select>
          <div class="field-error" id="err-area">Selecciona un área.</div>
        </div>

        <!-- Subárea padre (visible para sistema/subsistema) -->
        <div class="form-group" id="group-subarea" style="display:none">
          <label>Subárea *</label>
          <select id="f-subarea" disabled>
            <option value="">— Seleccionar subárea —</option>
          </select>
          <div class="field-error" id="err-subarea">Selecciona una subárea.</div>
        </div>

        <!-- Sistema padre (visible para subsistema) -->
        <div class="form-group" id="group-sistema" style="display:none">
          <label>Sistema *</label>
          <select id="f-sistema" disabled>
            <option value="">— Seleccionar sistema —</option>
          </select>
          <div class="field-error" id="err-sistema">Selecciona un sistema.</div>
        </div>

        <!-- Código -->
        <div class="form-group">
          <label>Código *</label>
          <input type="text" id="f-codigo" placeholder="Ej: 3610B"/>
          <div class="field-error" id="err-codigo">El código es obligatorio.</div>
        </div>

        <!-- Nombre -->
        <div class="form-group">
          <label>Nombre *</label>
          <input type="text" id="f-nombre" placeholder="Ej: Pilotes de hormigón"/>
          <div class="field-error" id="err-nombre">El nombre es obligatorio.</div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
          <button type="button" class="btn btn-ghost" id="cancel-form">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="submit-form">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══════════════════════════════════════
       Modal: Carga Excel
  ══════════════════════════════════════ -->
  <div class="modal-overlay" id="modal-excel">
    <div class="modal" style="width:700px">
      <button class="modal-close" id="close-modal-excel">✕</button>
      <h2 class="modal-title">Cargar Excel</h2>

      <!-- Paso 1: subir archivo -->
      <div id="excel-step-upload">
        <div class="drop-zone" id="drop-zone">
          <input type="file" id="file-input" accept=".xlsx"/>
          <div>📂 Arrastra tu archivo <strong>.xlsx</strong> aquí<br/>
            <span style="font-size:11px;color:var(--text-muted)">o haz clic para elegir</span>
          </div>
        </div>
        <div style="text-align:center;margin-top:12px">
          <a id="btn-template" href="#" class="btn btn-ghost btn-sm">⬇ Descargar formato</a>
        </div>
        <p id="upload-error" style="color:#FF6B6B;font-size:12px;margin-top:8px;display:none"></p>
      </div>

      <!-- Paso 2: preview -->
      <div id="excel-step-preview" style="display:none">
        <p style="font-size:12px;color:var(--text-secondary);margin-bottom:8px">
          Revisa los resultados antes de confirmar. Puedes omitir o sobreescribir filas con conflictos.
        </p>
        <div style="overflow-x:auto">
          <table class="preview-table" id="preview-table">
            <thead>
              <tr>
                <th>Fila</th>
                <th>Código</th>
                <th>Nombre</th>
                <th>Nivel</th>
                <th>Estado</th>
                <th>Decisión</th>
              </tr>
            </thead>
            <tbody id="preview-body"></tbody>
          </table>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
          <button class="btn btn-ghost" id="back-to-upload">← Otro archivo</button>
          <button class="btn btn-primary" id="confirm-import">Confirmar importación</button>
        </div>
      </div>

      <!-- Paso 3: resultado -->
      <div id="excel-step-result" style="display:none">
        <div id="import-summary" style="font-size:13px;line-height:1.8"></div>
        <div style="text-align:right;margin-top:16px">
          <button class="btn btn-primary" id="close-result">Cerrar</button>
        </div>
      </div>
    </div>
  </div>

  <script type="module">
    import { requireAuth, getUser, apiGet, apiPost, apiPut, apiDelete, apiFetch, showValidationErrors } from '/assets/js/api.js';

    requireAuth();

    // ── Contexto ──────────────────────────────────────────────────────
    const proyectoId = sessionStorage.getItem('proyecto_id');
    if (!proyectoId) { window.location.href = '/app/selector-proyecto.html'; }

    const user = getUser();
    document.getElementById('proyecto-codigo').textContent = sessionStorage.getItem('proyecto_codigo') ?? '';
    document.getElementById('proyecto-nombre').textContent = sessionStorage.getItem('proyecto_nombre') ?? '';
    document.getElementById('user-name').textContent       = user?.nombre ?? '';

    const isAdmin = user?.rol_global === 'superuser' || false;
    // rol en el proyecto viene del token — si no está en el token, la API lo enforcea

    // ── Árbol de datos (estado local) ─────────────────────────────────
    let areasData    = [];
    let subAreasData = [];
    let sistemasData = [];
    let subsistemasData = [];

    // Nodos colapsados: Set de keys "level:id"
    const collapsed = new Set();

    // ── Carga inicial ─────────────────────────────────────────────────
    async function loadAll() {
      const [areas, subareas, sistemas, subsistemas] = await Promise.all([
        apiGet(`/proyectos/${proyectoId}/areas`),
        apiGet(`/proyectos/${proyectoId}/subareas`),
        apiGet(`/proyectos/${proyectoId}/sistemas`),
        apiGet(`/proyectos/${proyectoId}/subsistemas`),
      ]);
      areasData        = areas        ?? [];
      subAreasData     = subareas     ?? [];
      sistemasData     = sistemas     ?? [];
      subsistemasData  = subsistemas  ?? [];
      renderTree();
    }

    // ── Render árbol ──────────────────────────────────────────────────
    function renderTree() {
      const search   = document.getElementById('search-input').value.toLowerCase().trim();
      const tbody    = document.getElementById('tree-body');
      const totalEl  = document.getElementById('total-count');
      tbody.innerHTML = '';

      let count = 0;

      for (const area of areasData) {
        if (!matches(area, search)) continue;
        count++;
        const areaKey = `area:${area.id}`;
        const areaOpen = !collapsed.has(areaKey);

        // Hijos del área
        const saOfArea = subAreasData.filter(sa => sa.area_id === area.id);

        const toggleIcon = saOfArea.length ? (areaOpen ? '▾' : '▸') : '·';
        const tr = makeRow(0, toggleIcon, areaKey, area.codigo, area.nombre, area, 'area');
        tbody.appendChild(tr);

        if (!areaOpen) continue;

        for (const sa of saOfArea) {
          const saKey  = `subarea:${sa.id}`;
          const saOpen = !collapsed.has(saKey);
          const siOfSa = sistemasData.filter(s => s.subarea_id === sa.id);
          const saToggle = siOfSa.length ? (saOpen ? '▾' : '▸') : '·';
          tbody.appendChild(makeRow(1, saToggle, saKey, sa.codigo, sa.nombre, sa, 'subarea'));
          if (!saOpen) continue;

          for (const si of siOfSa) {
            const siKey  = `sistema:${si.id}`;
            const siOpen = !collapsed.has(siKey);
            const ssOfSi = subsistemasData.filter(ss => ss.sistema_id === si.id);
            const siToggle = ssOfSi.length ? (siOpen ? '▾' : '▸') : '·';
            tbody.appendChild(makeRow(2, siToggle, siKey, si.codigo, si.nombre, si, 'sistema'));
            if (!siOpen) continue;

            for (const ss of ssOfSi) {
              tbody.appendChild(makeRow(3, '·', null, ss.codigo, ss.nombre, ss, 'subsistema'));
              count++;
            }
          }
        }
      }

      totalEl.textContent = `${areasData.length} área(s) · ${subAreasData.length} subárea(s) · ${sistemasData.length} sistema(s) · ${subsistemasData.length} subsistema(s)`;

      if (tbody.innerHTML === '') {
        tbody.innerHTML = `<tr><td colspan="3" style="padding:24px;text-align:center;color:var(--text-muted)">Sin resultados.</td></tr>`;
      }
    }

    function matches(item, search) {
      if (!search) return true;
      return item.codigo.toLowerCase().includes(search) || item.nombre.toLowerCase().includes(search);
    }

    function makeRow(level, toggleIcon, toggleKey, codigo, nombre, item, tipo) {
      const tr = document.createElement('tr');
      tr.className = `level-${level}`;
      tr.dataset.tipo = tipo;
      tr.dataset.id   = item.id;

      const tdCodigo = document.createElement('td');
      tdCodigo.innerHTML = `<span class="node-code">${escHtml(codigo)}</span>`;

      const tdNombre = document.createElement('td');
      const toggleSpan = document.createElement('span');
      toggleSpan.className = 'node-toggle';
      toggleSpan.textContent = toggleIcon;
      if (toggleKey) {
        toggleSpan.addEventListener('click', () => {
          if (collapsed.has(toggleKey)) collapsed.delete(toggleKey);
          else collapsed.add(toggleKey);
          renderTree();
        });
      }
      const nameSpan = document.createElement('span');
      nameSpan.className = 'node-name';
      nameSpan.textContent = nombre;
      tdNombre.appendChild(toggleSpan);
      tdNombre.appendChild(nameSpan);

      tr.appendChild(tdCodigo);
      tr.appendChild(tdNombre);

      // Columna acciones (Admin)
      if (canEdit) {
        const tdActions = document.createElement('td');
        const btnEdit = document.createElement('button');
        btnEdit.className = 'btn-icon-sm';
        btnEdit.textContent = '✏';
        btnEdit.title = 'Editar';
        btnEdit.addEventListener('click', () => openEditModal(tipo, item));

        const btnDel = document.createElement('button');
        btnDel.className = 'btn-icon-sm danger';
        btnDel.textContent = '✕';
        btnDel.title = 'Eliminar';
        btnDel.addEventListener('click', () => deleteItem(tipo, item));

        tdActions.appendChild(btnEdit);
        tdActions.appendChild(btnDel);
        tr.appendChild(tdActions);
      }

      return tr;
    }

    function escHtml(s) {
      return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    // ── Permisos de UI ────────────────────────────────────────────────
    // La API enforcea el rol; aquí solo mostramos/ocultamos botones
    let canEdit = false;

    async function checkRoleUI() {
      // El token tiene rol_global; para saber rol en proyecto consultamos /api/proyectos/:id
      const proyecto = await apiGet(`/proyectos/${proyectoId}`);
      // El endpoint devuelve el proyecto; si el usuario es admin o superuser, mostramos controles
      canEdit = proyecto?.user_rol === 'admin' || user?.rol_global === 'superuser';
      if (canEdit) {
        document.getElementById('btn-add').style.display = '';
        document.getElementById('th-acciones').style.display = '';
      }
    }

    // ── Búsqueda ──────────────────────────────────────────────────────
    document.getElementById('search-input').addEventListener('input', renderTree);

    // ── Modal Formulario ──────────────────────────────────────────────
    const modalForm = document.getElementById('modal-form');

    document.getElementById('btn-add').addEventListener('click', () => openAddModal());
    document.getElementById('close-modal-form').addEventListener('click', closeFormModal);
    document.getElementById('cancel-form').addEventListener('click', closeFormModal);

    let editMode = false;
    let editTipo = null;
    let editId   = null;

    function openAddModal() {
      editMode = false; editTipo = null; editId = null;
      document.getElementById('form-title').textContent = 'Agregar ítem';
      document.getElementById('item-form').reset();
      clearFormErrors();
      resetCascades();
      modalForm.classList.add('open');
    }

    function openEditModal(tipo, item) {
      editMode = true; editTipo = tipo; editId = item.id;
      document.getElementById('form-title').textContent = 'Editar ítem';
      clearFormErrors();

      // Pre-llenar nivel
      const nivelMap = { area: 'area', subarea: 'subarea', sistema: 'sistema', subsistema: 'subsistema' };
      document.getElementById('f-nivel').value = nivelMap[tipo];
      handleNivelChange(nivelMap[tipo]);

      // Pre-llenar código y nombre
      document.getElementById('f-codigo').value = item.codigo;
      document.getElementById('f-nombre').value = item.nombre;

      // Pre-llenar parents
      if (tipo === 'subarea' || tipo === 'sistema' || tipo === 'subsistema') {
        if (tipo === 'subarea') {
          document.getElementById('f-area').value = item.area_id;
        } else if (tipo === 'sistema') {
          document.getElementById('f-area').value = item.area_id;
          loadSubareasByArea(item.area_id).then(() => { document.getElementById('f-subarea').value = item.subarea_id; });
        } else {
          document.getElementById('f-area').value = item.area_id;
          loadSubareasByArea(item.area_id).then(() => {
            document.getElementById('f-subarea').value = item.subarea_id;
            loadSistemasBySubarea(item.subarea_id).then(() => {
              document.getElementById('f-sistema').value = item.sistema_id;
            });
          });
        }
      }

      modalForm.classList.add('open');
    }

    function closeFormModal() {
      modalForm.classList.remove('open');
    }

    // ── Cascadas ──────────────────────────────────────────────────────
    document.getElementById('f-nivel').addEventListener('change', e => handleNivelChange(e.target.value));

    function handleNivelChange(nivel) {
      resetCascades();
      document.getElementById('group-area').style.display    = ['subarea','sistema','subsistema'].includes(nivel) ? '' : 'none';
      document.getElementById('group-subarea').style.display = ['sistema','subsistema'].includes(nivel) ? '' : 'none';
      document.getElementById('group-sistema').style.display = nivel === 'subsistema' ? '' : 'none';

      if (['subarea','sistema','subsistema'].includes(nivel)) {
        populateSelect('f-area', areasData, 'id', 'nombre', true);
      }
    }

    function resetCascades() {
      ['group-area','group-subarea','group-sistema'].forEach(id => {
        document.getElementById(id).style.display = 'none';
      });
      populateSelect('f-area',    [], 'id', 'nombre', true);
      populateSelect('f-subarea', [], 'id', 'nombre', true);
      populateSelect('f-sistema', [], 'id', 'nombre', true);
      document.getElementById('f-subarea').disabled = true;
      document.getElementById('f-sistema').disabled  = true;
    }

    document.getElementById('f-area').addEventListener('change', e => {
      const areaId = e.target.value;
      document.getElementById('f-subarea').disabled = !areaId;
      document.getElementById('f-sistema').disabled  = true;
      populateSelect('f-subarea', [], 'id', 'nombre', true);
      populateSelect('f-sistema',  [], 'id', 'nombre', true);
      if (areaId) loadSubareasByArea(areaId);
    });

    document.getElementById('f-subarea').addEventListener('change', e => {
      const saId = e.target.value;
      document.getElementById('f-sistema').disabled = !saId;
      populateSelect('f-sistema', [], 'id', 'nombre', true);
      if (saId) loadSistemasBySubarea(saId);
    });

    async function loadSubareasByArea(areaId) {
      const data = await apiGet(`/proyectos/${proyectoId}/subareas?area_id=${areaId}`);
      populateSelect('f-subarea', data ?? [], 'id', 'nombre');
      document.getElementById('f-subarea').disabled = false;
    }

    async function loadSistemasBySubarea(subareaId) {
      const data = await apiGet(`/proyectos/${proyectoId}/sistemas?subarea_id=${subareaId}`);
      populateSelect('f-sistema', data ?? [], 'id', 'nombre');
      document.getElementById('f-sistema').disabled = false;
    }

    function populateSelect(selectId, items, valKey, labelKey, reset = false) {
      const sel = document.getElementById(selectId);
      const prev = sel.value;
      sel.innerHTML = `<option value="">— Seleccionar —</option>`;
      items.forEach(item => {
        const opt = document.createElement('option');
        opt.value       = item[valKey];
        opt.textContent = `${item.codigo} — ${item[labelKey]}`;
        sel.appendChild(opt);
      });
      if (!reset) sel.value = prev; // restaurar selección si aún existe
    }

    // ── Submit formulario ─────────────────────────────────────────────
    document.getElementById('item-form').addEventListener('submit', async e => {
      e.preventDefault();
      clearFormErrors();

      const nivel = document.getElementById('f-nivel').value;
      const codigo = document.getElementById('f-codigo').value.trim();
      const nombre = document.getElementById('f-nombre').value.trim();
      const areaId    = document.getElementById('f-area').value    || null;
      const subareaId = document.getElementById('f-subarea').value || null;
      const sistemaId = document.getElementById('f-sistema').value || null;

      // Validación frontend
      let valid = true;
      if (!nivel)   { showFieldError('err-nivel',  'f-nivel');  valid = false; }
      if (!codigo)  { showFieldError('err-codigo', 'f-codigo'); valid = false; }
      if (!nombre)  { showFieldError('err-nombre', 'f-nombre'); valid = false; }
      if (['subarea','sistema','subsistema'].includes(nivel) && !areaId)    { showFieldError('err-area',    'f-area');    valid = false; }
      if (['sistema','subsistema'].includes(nivel) && !subareaId)           { showFieldError('err-subarea', 'f-subarea'); valid = false; }
      if (nivel === 'subsistema' && !sistemaId)                             { showFieldError('err-sistema', 'f-sistema'); valid = false; }

      if (!valid) return;

      const body = buildBody(nivel, codigo, nombre, areaId, subareaId, sistemaId);
      const endpoint = endpointForNivel(nivel);

      const btn = document.getElementById('submit-form');
      btn.disabled = true;

      try {
        if (editMode) {
          await apiPut(`/proyectos/${proyectoId}/${endpoint}/${editId}`, body);
        } else {
          await apiPost(`/proyectos/${proyectoId}/${endpoint}`, body);
        }
        closeFormModal();
        await loadAll();
      } catch (err) {
        if (err.status === 422) {
          showValidationErrors(err.errors, {
            codigo: 'f-codigo',
            nombre: 'f-nombre',
          });
        } else {
          alert(err.message ?? 'Error al guardar.');
        }
      } finally {
        btn.disabled = false;
      }
    });

    function buildBody(nivel, codigo, nombre, areaId, subareaId, sistemaId) {
      const b = { codigo, nombre };
      if (nivel === 'subarea')    b.area_id    = areaId;
      if (nivel === 'sistema')    { b.area_id = areaId; b.subarea_id = subareaId; }
      if (nivel === 'subsistema') { b.area_id = areaId; b.subarea_id = subareaId; b.sistema_id = sistemaId; }
      return b;
    }

    function endpointForNivel(nivel) {
      return { area: 'areas', subarea: 'subareas', sistema: 'sistemas', subsistema: 'subsistemas' }[nivel];
    }

    function showFieldError(errId, fieldId) {
      document.getElementById(errId).classList.add('visible');
      document.getElementById(fieldId).classList.add('has-error');
    }

    function clearFormErrors() {
      document.querySelectorAll('.field-error').forEach(el => el.classList.remove('visible'));
      document.querySelectorAll('.has-error').forEach(el => el.classList.remove('has-error'));
    }

    // ── Eliminar ítem ─────────────────────────────────────────────────
    async function deleteItem(tipo, item) {
      if (!confirm(`¿Eliminar "${item.nombre}" (${item.codigo})? Esta acción no se puede deshacer.`)) return;
      const endpoint = endpointForNivel(tipo);
      try {
        await apiDelete(`/proyectos/${proyectoId}/${endpoint}/${item.id}`);
        await loadAll();
      } catch (err) {
        alert(err.message ?? 'Error al eliminar.');
      }
    }

    // ── Modal Excel ───────────────────────────────────────────────────
    const modalExcel = document.getElementById('modal-excel');
    document.getElementById('btn-excel').addEventListener('click', () => { resetExcelModal(); modalExcel.classList.add('open'); });
    document.getElementById('close-modal-excel').addEventListener('click', () => modalExcel.classList.remove('open'));
    document.getElementById('close-result').addEventListener('click', () => { modalExcel.classList.remove('open'); loadAll(); });
    document.getElementById('back-to-upload').addEventListener('click', resetExcelModal);

    // Template download
    document.getElementById('btn-template').href = `/api/proyectos/${proyectoId}/import/template`;

    // Drag & drop
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');

    dropZone.addEventListener('click', () => fileInput.click());
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('over'));
    dropZone.addEventListener('drop', e => {
      e.preventDefault();
      dropZone.classList.remove('over');
      const file = e.dataTransfer.files[0];
      if (file) handleFile(file);
    });
    fileInput.addEventListener('change', () => {
      if (fileInput.files[0]) handleFile(fileInput.files[0]);
    });

    let previewRows = [];

    async function handleFile(file) {
      if (!file.name.endsWith('.xlsx')) {
        showUploadError('Solo se aceptan archivos .xlsx');
        return;
      }
      const formData = new FormData();
      formData.append('file', file);

      try {
        const data = await apiFetch(`/api/proyectos/${proyectoId}/import/preview`, {
          method: 'POST',
          body: formData,
        });
        previewRows = data.rows ?? [];
        renderPreview(previewRows);
        document.getElementById('excel-step-upload').style.display  = 'none';
        document.getElementById('excel-step-preview').style.display = '';
      } catch (err) {
        showUploadError(err.message ?? 'Error al procesar el archivo.');
      }
    }

    function renderPreview(rows) {
      const tbody = document.getElementById('preview-body');
      tbody.innerHTML = '';
      rows.forEach((row, i) => {
        const tr = document.createElement('tr');
        tr.className = row.estado === 'valido' ? 'row-valid' : row.estado === 'duplicado' ? 'row-dup' : 'row-error';
        tr.innerHTML = `
          <td>${row.fila}</td>
          <td>${escHtml(row.codigo ?? '')}</td>
          <td>${escHtml(row.nombre ?? '')}</td>
          <td>${escHtml(row.nivel ?? '')}</td>
          <td class="row-status">${statusIcon(row.estado)} ${escHtml(row.motivo ?? '')}</td>
          <td>${decisionSelect(i, row.estado)}</td>
        `;
        tbody.appendChild(tr);
      });
    }

    function statusIcon(estado) {
      return { valido: '✓', duplicado: '⚠', error: '✕' }[estado] ?? '?';
    }

    function decisionSelect(idx, estado) {
      if (estado === 'valido') return '<span style="color:var(--text-muted)">—</span>';
      return `<select data-idx="${idx}" class="decision-sel" style="font-size:11px;background:var(--bg);border:1px solid var(--border);border-radius:4px;color:var(--text);padding:2px 6px">
        <option value="omitir">Omitir</option>
        ${estado === 'duplicado' ? '<option value="sobreescribir">Sobreescribir</option>' : ''}
      </select>`;
    }

    document.getElementById('confirm-import').addEventListener('click', async () => {
      // Leer decisiones del usuario
      const decisiones = [];
      document.querySelectorAll('.decision-sel').forEach(sel => {
        decisiones[parseInt(sel.dataset.idx)] = sel.value;
      });

      const payload = previewRows.map((row, i) => ({
        ...row,
        decision: row.estado === 'valido' ? 'importar' : (decisiones[i] ?? 'omitir'),
      }));

      try {
        const result = await apiPost(`/proyectos/${proyectoId}/import/confirm`, { rows: payload });
        document.getElementById('excel-step-preview').style.display = 'none';
        document.getElementById('excel-step-result').style.display  = '';
        document.getElementById('import-summary').innerHTML = `
          <p>✓ Importados: <strong>${result.importados}</strong></p>
          <p>⚠ Omitidos: <strong>${result.omitidos}</strong></p>
          <p>✕ Errores: <strong>${result.errores}</strong></p>
        `;
      } catch (err) {
        alert(err.message ?? 'Error al confirmar importación.');
      }
    });

    function showUploadError(msg) {
      const el = document.getElementById('upload-error');
      el.textContent = msg;
      el.style.display = '';
    }

    function resetExcelModal() {
      previewRows = [];
      document.getElementById('excel-step-upload').style.display  = '';
      document.getElementById('excel-step-preview').style.display = 'none';
      document.getElementById('excel-step-result').style.display  = 'none';
      document.getElementById('upload-error').style.display = 'none';
      document.getElementById('file-input').value = '';
    }

    // ── Init ──────────────────────────────────────────────────────────
    await checkRoleUI();
    await loadAll();
  </script>
</body>
</html>
```

- [ ] **Step 17.2: Verificar en browser**

```bash
cd backend && php artisan serve
```

Flujo a verificar:
1. Login → seleccionar proyecto → seleccionar app → llega a `quiebre.html`
2. Con un proyecto vacío: muestra "0 área(s) · 0 subárea(s)…"
3. Como Admin: aparece botón "+ Agregar" y columna "Acciones"
4. Formulario → Nivel: Área → código "3600" + nombre "Estructura" → Guardar → aparece en árbol
5. Formulario → Nivel: Subárea → selecciona Área → código "3610" + nombre "Fundaciones" → Guardar → aparece bajo el área
6. Click en toggle ▾/▸ del área → colapsa/expande subáreas
7. Buscar "3610" → filtra solo las filas que coinciden
8. Botón "Cargar Excel" → modal drag-drop → Descargar formato → descarga el .xlsx
9. Subir archivo .xlsx con datos → preview muestra filas → confirmar → resultado con conteo

- [ ] **Step 17.3: Commit**

```bash
git add backend/public/app/quiebre.html
git commit -m "feat: add quiebre.html - hierarchy table with cascading form and Excel import"
```

---

### Task 18: superuser.html — panel de administración global

**Files:**
- Create: `backend/public/app/superuser.html`

Panel exclusivo del SUPERUSER con 5 tabs: Proyectos, Usuarios, Tipos de Usuario, Asignaciones y Logs.

- [ ] **Step 18.1: Crear `backend/public/app/superuser.html`**

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Panel SUPERUSER — AppHub</title>
  <link rel="stylesheet" href="/assets/css/app.css"/>
  <style>
    /* ── Tabs ── */
    .tabs { display: flex; gap: 2px; margin-bottom: 20px; border-bottom: 1px solid var(--border); }
    .tab {
      padding: 8px 16px; font-size: 12px; font-weight: 600;
      color: var(--text-muted); cursor: pointer; border-bottom: 2px solid transparent;
      margin-bottom: -1px;
    }
    .tab.active { color: var(--primary); border-bottom-color: var(--primary); }

    /* ── Tablas admin ── */
    .admin-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .admin-table th {
      background: var(--surface); color: var(--text-muted);
      font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;
      padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--border);
    }
    .admin-table td { padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); color: var(--text); }
    .admin-table tr:hover td { background: rgba(255,255,255,0.02); }

    .code-badge { font-family: monospace; font-size: 11px; background: rgba(79,126,255,0.08); color: #8AABFF; padding: 2px 7px; border-radius: 4px; }
    .status-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; margin-right: 5px; vertical-align: middle; }
    .dot-active   { background: #2ECC8A; }
    .dot-archived { background: #4A4F66; }

    .role-badge { padding: 2px 9px; border-radius: 100px; font-size: 10px; font-weight: 700; }
    .role-su      { background: rgba(255,107,107,0.1);  color: #FF6B6B; border: 1px solid rgba(255,107,107,0.25); }
    .role-admin   { background: rgba(79,126,255,0.1);   color: #8AABFF; border: 1px solid rgba(79,126,255,0.25); }
    .role-usuario { background: rgba(46,204,138,0.08);  color: #2ECC8A; border: 1px solid rgba(46,204,138,0.2); }

    /* ── User avatar ── */
    .user-avatar {
      width: 30px; height: 30px; border-radius: 50%;
      background: linear-gradient(135deg,#4F7EFF,#7C5DFF);
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 11px; font-weight: 700; vertical-align: middle; margin-right: 8px;
    }

    /* ── Botones de acción en tabla ── */
    .btn-row { display: flex; gap: 4px; }
    .btn-tbl {
      background: none; border: 1px solid var(--border); border-radius: 5px;
      color: var(--text-muted); font-size: 11px; padding: 3px 8px; cursor: pointer;
    }
    .btn-tbl:hover { border-color: var(--primary); color: var(--primary); }
    .btn-tbl.danger:hover { border-color: #FF6B6B; color: #FF6B6B; }

    /* ── Modal ── */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.7); z-index: 100;
      align-items: center; justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .modal {
      background: var(--surface); border: 1px solid var(--border);
      border-radius: 12px; padding: 28px; width: 460px; max-width: 95vw;
    }
    .modal-title { font-size: 16px; font-weight: 700; margin-bottom: 20px; }
    .modal-close { float: right; background: none; border: none; color: var(--text-muted); font-size: 18px; cursor: pointer; }
    .form-group { margin-bottom: 14px; }
    .form-group label { display: block; font-size: 12px; color: var(--text-secondary); margin-bottom: 5px; }
    .form-group input,
    .form-group select {
      width: 100%; background: var(--bg); border: 1px solid var(--border);
      border-radius: 6px; color: var(--text); padding: 8px 12px; font-size: 13px;
    }
    .form-group input:focus,
    .form-group select:focus { outline: none; border-color: var(--primary); }
    .field-error { color: #FF6B6B; font-size: 11px; margin-top: 4px; display: none; }
    .field-error.visible { display: block; }
    .input.has-error, select.has-error { border-color: #FF6B6B !important; }

    /* ── Log ── */
    .log-origin { font-size: 10px; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.06); color: var(--text-secondary); }
    .action-badge { font-size: 10px; font-weight: 600; }
    .action-CREATE  { color: #2ECC8A; }
    .action-UPDATE  { color: #8AABFF; }
    .action-DELETE  { color: #FF6B6B; }
    .action-IMPORT  { color: #FFB347; }
    .action-IMPORT_ERROR_DISMISSED { color: #4A4F66; }
    .action-VALIDATION_ERROR       { color: #FF6B6B; }
  </style>
</head>
<body>
  <!-- Navbar -->
  <nav class="navbar">
    <div class="nav-left">
      <span class="nav-brand">AppHub</span>
      <span class="nav-sep">/</span>
      <span class="nav-current" style="color:#FF6B6B">Panel SUPERUSER</span>
    </div>
    <div class="nav-right">
      <span id="user-name" class="nav-user"></span>
      <a href="/app/selector-proyecto.html" class="btn btn-ghost btn-sm">Proyectos</a>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <div>
        <h1>Administración Global <span style="font-size:14px;vertical-align:middle;margin-left:8px;background:rgba(255,107,107,0.1);color:#FF6B6B;border:1px solid rgba(255,107,107,0.3);border-radius:100px;padding:2px 10px;font-weight:700">SUPERUSER</span></h1>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <div class="tab active" data-tab="proyectos">Proyectos</div>
      <div class="tab"        data-tab="usuarios">Usuarios</div>
      <div class="tab"        data-tab="tipos">Tipos de Usuario</div>
      <div class="tab"        data-tab="asignaciones">Asignaciones</div>
      <div class="tab"        data-tab="logs">Logs</div>
    </div>

    <!-- ══ TAB: Proyectos ══ -->
    <div id="tab-proyectos" class="tab-panel">
      <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
        <button class="btn btn-primary btn-sm" id="btn-new-proyecto">+ Nuevo Proyecto</button>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table" id="table-proyectos">
          <thead><tr><th>Código</th><th>Nombre</th><th>Estado</th><th style="width:80px"></th></tr></thead>
          <tbody id="tbody-proyectos"><tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-muted)">Cargando…</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ══ TAB: Usuarios ══ -->
    <div id="tab-usuarios" class="tab-panel hidden">
      <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
        <button class="btn btn-primary btn-sm" id="btn-new-usuario">+ Nuevo Usuario</button>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table" id="table-usuarios">
          <thead><tr><th>Usuario</th><th>Email</th><th>Rol Global</th><th>Estado</th><th style="width:80px"></th></tr></thead>
          <tbody id="tbody-usuarios"><tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Cargando…</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ══ TAB: Tipos de Usuario ══ -->
    <div id="tab-tipos" class="tab-panel hidden">
      <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
        <button class="btn btn-primary btn-sm" id="btn-new-tipo">+ Nuevo Tipo</button>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table">
          <thead><tr><th>Nombre</th><th>Descripción</th><th>Activo</th><th style="width:80px"></th></tr></thead>
          <tbody id="tbody-tipos"><tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-muted)">Cargando…</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ══ TAB: Asignaciones ══ -->
    <div id="tab-asignaciones" class="tab-panel hidden">
      <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
        <button class="btn btn-primary btn-sm" id="btn-new-asignacion">+ Nueva Asignación</button>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table">
          <thead><tr><th>Usuario</th><th>Proyecto</th><th>Rol</th><th>Tipo</th><th style="width:60px"></th></tr></thead>
          <tbody id="tbody-asignaciones"><tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Cargando…</td></tr></tbody>
        </table>
      </div>
    </div>

    <!-- ══ TAB: Logs ══ -->
    <div id="tab-logs" class="tab-panel hidden">
      <div style="display:flex;gap:8px;margin-bottom:12px;align-items:center">
        <select id="log-proyecto-filter" style="background:var(--surface);border:1px solid var(--border);border-radius:6px;color:var(--text);padding:6px 10px;font-size:12px">
          <option value="">Todos los proyectos</option>
        </select>
        <button class="btn btn-ghost btn-sm" id="btn-reload-logs">↺ Actualizar</button>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table">
          <thead><tr><th>Fecha</th><th>Origen</th><th>Acción</th><th>Usuario</th><th>Proyecto</th></tr></thead>
          <tbody id="tbody-logs"><tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Cargando…</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ══ Modal: Proyecto ══ -->
  <div class="modal-overlay" id="modal-proyecto">
    <div class="modal">
      <button class="modal-close" id="close-modal-proyecto">✕</button>
      <h2 class="modal-title" id="title-modal-proyecto">Nuevo Proyecto</h2>
      <form id="form-proyecto" novalidate>
        <div class="form-group">
          <label>Código *</label>
          <input type="text" id="p-codigo" placeholder="Ej: AUT-002"/>
          <div class="field-error" id="err-p-codigo">Campo obligatorio.</div>
        </div>
        <div class="form-group">
          <label>Nombre *</label>
          <input type="text" id="p-nombre" placeholder="Ej: Autopista Norte Tramo 2"/>
          <div class="field-error" id="err-p-nombre">Campo obligatorio.</div>
        </div>
        <div class="form-group">
          <label>Estado</label>
          <select id="p-estado">
            <option value="activo">Activo</option>
            <option value="archivado">Archivado</option>
          </select>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
          <button type="button" class="btn btn-ghost" id="cancel-proyecto">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="submit-proyecto">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══ Modal: Usuario ══ -->
  <div class="modal-overlay" id="modal-usuario">
    <div class="modal">
      <button class="modal-close" id="close-modal-usuario">✕</button>
      <h2 class="modal-title" id="title-modal-usuario">Nuevo Usuario</h2>
      <form id="form-usuario" novalidate>
        <div class="form-group">
          <label>Nombre *</label>
          <input type="text" id="u-nombre" placeholder="Ej: Juan Pérez"/>
          <div class="field-error" id="err-u-nombre">Campo obligatorio.</div>
        </div>
        <div class="form-group">
          <label>Email *</label>
          <input type="email" id="u-email" placeholder="juan@empresa.cl"/>
          <div class="field-error" id="err-u-email">Email obligatorio.</div>
        </div>
        <div class="form-group" id="group-u-password">
          <label id="label-u-password">Contraseña *</label>
          <input type="password" id="u-password" placeholder="Mínimo 8 caracteres"/>
          <div class="field-error" id="err-u-password">Contraseña obligatoria.</div>
        </div>
        <div class="form-group">
          <label>Rol Global</label>
          <select id="u-rol">
            <option value="usuario">Usuario</option>
            <option value="admin">Admin</option>
            <option value="superuser">SUPERUSER</option>
          </select>
        </div>
        <div class="form-group">
          <label>Activo</label>
          <select id="u-activo">
            <option value="1">Sí</option>
            <option value="0">No</option>
          </select>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
          <button type="button" class="btn btn-ghost" id="cancel-usuario">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="submit-usuario">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══ Modal: Tipo de Usuario ══ -->
  <div class="modal-overlay" id="modal-tipo">
    <div class="modal">
      <button class="modal-close" id="close-modal-tipo">✕</button>
      <h2 class="modal-title" id="title-modal-tipo">Nuevo Tipo de Usuario</h2>
      <form id="form-tipo" novalidate>
        <div class="form-group">
          <label>Nombre *</label>
          <input type="text" id="t-nombre" placeholder="Ej: Inspector de Calidad"/>
          <div class="field-error" id="err-t-nombre">Campo obligatorio.</div>
        </div>
        <div class="form-group">
          <label>Descripción</label>
          <input type="text" id="t-descripcion" placeholder="Descripción breve…"/>
        </div>
        <div class="form-group">
          <label>Activo</label>
          <select id="t-activo">
            <option value="1">Sí</option>
            <option value="0">No</option>
          </select>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
          <button type="button" class="btn btn-ghost" id="cancel-tipo">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="submit-tipo">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══ Modal: Asignación ══ -->
  <div class="modal-overlay" id="modal-asignacion">
    <div class="modal">
      <button class="modal-close" id="close-modal-asignacion">✕</button>
      <h2 class="modal-title">Nueva Asignación</h2>
      <form id="form-asignacion" novalidate>
        <div class="form-group">
          <label>Usuario *</label>
          <select id="a-usuario">
            <option value="">— Seleccionar usuario —</option>
          </select>
          <div class="field-error" id="err-a-usuario">Campo obligatorio.</div>
        </div>
        <div class="form-group">
          <label>Proyecto *</label>
          <select id="a-proyecto">
            <option value="">— Seleccionar proyecto —</option>
          </select>
          <div class="field-error" id="err-a-proyecto">Campo obligatorio.</div>
        </div>
        <div class="form-group">
          <label>Rol en el proyecto</label>
          <select id="a-rol">
            <option value="usuario">Usuario</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="form-group">
          <label>Tipo (opcional)</label>
          <select id="a-tipo">
            <option value="">— Sin tipo especial —</option>
          </select>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
          <button type="button" class="btn btn-ghost" id="cancel-asignacion">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="submit-asignacion">Guardar</button>
        </div>
      </form>
    </div>
  </div>

  <script type="module">
    import { requireAuth, getUser, apiGet, apiPost, apiPut, apiDelete } from '/assets/js/api.js';

    requireAuth();

    const user = getUser();
    if (user?.rol_global !== 'superuser') {
      window.location.href = '/app/selector-proyecto.html';
    }
    document.getElementById('user-name').textContent = user?.nombre ?? '';

    // ── Tabs ──────────────────────────────────────────────────────────
    const tabButtons = document.querySelectorAll('.tab');
    const tabPanels  = document.querySelectorAll('.tab-panel');

    tabButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        tabButtons.forEach(b => b.classList.remove('active'));
        tabPanels.forEach(p => p.classList.add('hidden'));
        btn.classList.add('active');
        document.getElementById(`tab-${btn.dataset.tab}`).classList.remove('hidden');
        loadTab(btn.dataset.tab);
      });
    });

    function loadTab(tab) {
      if (tab === 'proyectos')    loadProyectos();
      if (tab === 'usuarios')     loadUsuarios();
      if (tab === 'tipos')        loadTipos();
      if (tab === 'asignaciones') loadAsignaciones();
      if (tab === 'logs')         loadLogs();
    }

    // Caches
    let proyectosCache = [];
    let usuariosCache  = [];
    let tiposCache     = [];

    // ── TAB: Proyectos ────────────────────────────────────────────────
    async function loadProyectos() {
      const data = await apiGet('/proyectos?all=1');
      proyectosCache = data ?? [];
      const tbody = document.getElementById('tbody-proyectos');
      if (!proyectosCache.length) {
        tbody.innerHTML = `<tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-muted)">Sin proyectos.</td></tr>`;
        return;
      }
      tbody.innerHTML = proyectosCache.map(p => `
        <tr>
          <td><span class="code-badge">${escHtml(p.codigo)}</span></td>
          <td>${escHtml(p.nombre)}</td>
          <td><span class="status-dot ${p.estado === 'activo' ? 'dot-active' : 'dot-archived'}"></span>${escHtml(p.estado)}</td>
          <td><div class="btn-row">
            <button class="btn-tbl" onclick="openEditProyecto(${p.id})">✏</button>
          </div></td>
        </tr>
      `).join('');

      // Actualizar filtro de logs
      const sel = document.getElementById('log-proyecto-filter');
      proyectosCache.forEach(p => {
        if (!sel.querySelector(`option[value="${p.id}"]`)) {
          const opt = document.createElement('option');
          opt.value = p.id;
          opt.textContent = `${p.codigo} — ${p.nombre}`;
          sel.appendChild(opt);
        }
      });
    }

    // Nueva / editar proyecto
    let editProyectoId = null;

    document.getElementById('btn-new-proyecto').addEventListener('click', () => {
      editProyectoId = null;
      document.getElementById('title-modal-proyecto').textContent = 'Nuevo Proyecto';
      document.getElementById('form-proyecto').reset();
      clearErrors('form-proyecto');
      document.getElementById('modal-proyecto').classList.add('open');
    });

    window.openEditProyecto = (id) => {
      editProyectoId = id;
      const p = proyectosCache.find(x => x.id === id);
      document.getElementById('title-modal-proyecto').textContent = 'Editar Proyecto';
      document.getElementById('p-codigo').value  = p.codigo;
      document.getElementById('p-nombre').value  = p.nombre;
      document.getElementById('p-estado').value  = p.estado;
      clearErrors('form-proyecto');
      document.getElementById('modal-proyecto').classList.add('open');
    };

    document.getElementById('close-modal-proyecto').addEventListener('click', () => document.getElementById('modal-proyecto').classList.remove('open'));
    document.getElementById('cancel-proyecto').addEventListener('click', () => document.getElementById('modal-proyecto').classList.remove('open'));

    document.getElementById('form-proyecto').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors('form-proyecto');
      const codigo = document.getElementById('p-codigo').value.trim();
      const nombre = document.getElementById('p-nombre').value.trim();
      const estado = document.getElementById('p-estado').value;

      let valid = true;
      if (!codigo) { showErr('err-p-codigo'); valid = false; }
      if (!nombre) { showErr('err-p-nombre'); valid = false; }
      if (!valid) return;

      const btn = document.getElementById('submit-proyecto');
      btn.disabled = true;
      try {
        if (editProyectoId) {
          await apiPut(`/proyectos/${editProyectoId}`, { codigo, nombre, estado });
        } else {
          await apiPost('/proyectos', { codigo, nombre, estado });
        }
        document.getElementById('modal-proyecto').classList.remove('open');
        loadProyectos();
      } catch (err) {
        handleFormError(err, { codigo: 'err-p-codigo', nombre: 'err-p-nombre' });
      } finally { btn.disabled = false; }
    });

    // ── TAB: Usuarios ─────────────────────────────────────────────────
    async function loadUsuarios() {
      const data = await apiGet('/admin/usuarios');
      usuariosCache = data ?? [];
      const tbody = document.getElementById('tbody-usuarios');
      if (!usuariosCache.length) {
        tbody.innerHTML = `<tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Sin usuarios.</td></tr>`;
        return;
      }
      tbody.innerHTML = usuariosCache.map(u => `
        <tr>
          <td>
            <span class="user-avatar">${initials(u.nombre)}</span>
            ${escHtml(u.nombre)}
          </td>
          <td style="color:var(--text-secondary)">${escHtml(u.email)}</td>
          <td><span class="role-badge role-${u.rol_global === 'superuser' ? 'su' : u.rol_global === 'admin' ? 'admin' : 'usuario'}">${escHtml(u.rol_global)}</span></td>
          <td>${u.activo ? '<span style="color:#2ECC8A">Activo</span>' : '<span style="color:#4A4F66">Inactivo</span>'}</td>
          <td><div class="btn-row">
            <button class="btn-tbl" onclick="openEditUsuario(${u.id})">✏</button>
            ${u.rol_global !== 'superuser' ? `<button class="btn-tbl danger" onclick="deleteUsuario(${u.id})">✕</button>` : ''}
          </div></td>
        </tr>
      `).join('');
    }

    function initials(nombre) {
      return nombre.split(' ').slice(0,2).map(w => w[0]?.toUpperCase()).join('');
    }

    let editUsuarioId = null;

    document.getElementById('btn-new-usuario').addEventListener('click', () => {
      editUsuarioId = null;
      document.getElementById('title-modal-usuario').textContent = 'Nuevo Usuario';
      document.getElementById('form-usuario').reset();
      document.getElementById('label-u-password').textContent = 'Contraseña *';
      document.getElementById('u-password').placeholder = 'Mínimo 8 caracteres';
      clearErrors('form-usuario');
      document.getElementById('modal-usuario').classList.add('open');
    });

    window.openEditUsuario = (id) => {
      editUsuarioId = id;
      const u = usuariosCache.find(x => x.id === id);
      document.getElementById('title-modal-usuario').textContent = 'Editar Usuario';
      document.getElementById('u-nombre').value  = u.nombre;
      document.getElementById('u-email').value   = u.email;
      document.getElementById('u-rol').value     = u.rol_global;
      document.getElementById('u-activo').value  = u.activo ? '1' : '0';
      document.getElementById('u-password').value = '';
      document.getElementById('label-u-password').textContent = 'Contraseña (dejar vacío para no cambiar)';
      document.getElementById('u-password').placeholder = 'Dejar vacío para mantener';
      clearErrors('form-usuario');
      document.getElementById('modal-usuario').classList.add('open');
    };

    window.deleteUsuario = async (id) => {
      const u = usuariosCache.find(x => x.id === id);
      if (!confirm(`¿Eliminar a ${u.nombre}? Esta acción no se puede deshacer.`)) return;
      try {
        await apiDelete(`/admin/usuarios/${id}`);
        loadUsuarios();
      } catch (err) { alert(err.message ?? 'Error al eliminar.'); }
    };

    document.getElementById('close-modal-usuario').addEventListener('click', () => document.getElementById('modal-usuario').classList.remove('open'));
    document.getElementById('cancel-usuario').addEventListener('click', () => document.getElementById('modal-usuario').classList.remove('open'));

    document.getElementById('form-usuario').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors('form-usuario');
      const nombre   = document.getElementById('u-nombre').value.trim();
      const email    = document.getElementById('u-email').value.trim();
      const password = document.getElementById('u-password').value;
      const rol      = document.getElementById('u-rol').value;
      const activo   = document.getElementById('u-activo').value === '1';

      let valid = true;
      if (!nombre) { showErr('err-u-nombre'); valid = false; }
      if (!email)  { showErr('err-u-email');  valid = false; }
      if (!editUsuarioId && !password) { showErr('err-u-password'); valid = false; }
      if (!valid) return;

      const body = { nombre, email, rol_global: rol, activo };
      if (password) body.password = password;

      const btn = document.getElementById('submit-usuario');
      btn.disabled = true;
      try {
        if (editUsuarioId) {
          await apiPut(`/admin/usuarios/${editUsuarioId}`, body);
        } else {
          await apiPost('/admin/usuarios', body);
        }
        document.getElementById('modal-usuario').classList.remove('open');
        loadUsuarios();
      } catch (err) {
        handleFormError(err, { nombre: 'err-u-nombre', email: 'err-u-email', password: 'err-u-password' });
      } finally { btn.disabled = false; }
    });

    // ── TAB: Tipos de Usuario ─────────────────────────────────────────
    async function loadTipos() {
      const data = await apiGet('/admin/tipos-usuario');
      tiposCache = data ?? [];
      const tbody = document.getElementById('tbody-tipos');
      if (!tiposCache.length) {
        tbody.innerHTML = `<tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-muted)">Sin tipos definidos.</td></tr>`;
        return;
      }
      tbody.innerHTML = tiposCache.map(t => `
        <tr>
          <td>${escHtml(t.nombre)}</td>
          <td style="color:var(--text-secondary)">${escHtml(t.descripcion ?? '—')}</td>
          <td>${t.activo ? '<span style="color:#2ECC8A">Sí</span>' : '<span style="color:#4A4F66">No</span>'}</td>
          <td><div class="btn-row">
            <button class="btn-tbl" onclick="openEditTipo(${t.id})">✏</button>
            <button class="btn-tbl danger" onclick="deleteTipo(${t.id})">✕</button>
          </div></td>
        </tr>
      `).join('');

      // Actualizar selector de tipos en modal asignación
      const sel = document.getElementById('a-tipo');
      sel.innerHTML = '<option value="">— Sin tipo especial —</option>';
      tiposCache.filter(t => t.activo).forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.id; opt.textContent = t.nombre;
        sel.appendChild(opt);
      });
    }

    let editTipoId = null;

    document.getElementById('btn-new-tipo').addEventListener('click', () => {
      editTipoId = null;
      document.getElementById('title-modal-tipo').textContent = 'Nuevo Tipo de Usuario';
      document.getElementById('form-tipo').reset();
      clearErrors('form-tipo');
      document.getElementById('modal-tipo').classList.add('open');
    });

    window.openEditTipo = (id) => {
      editTipoId = id;
      const t = tiposCache.find(x => x.id === id);
      document.getElementById('title-modal-tipo').textContent = 'Editar Tipo';
      document.getElementById('t-nombre').value      = t.nombre;
      document.getElementById('t-descripcion').value = t.descripcion ?? '';
      document.getElementById('t-activo').value      = t.activo ? '1' : '0';
      clearErrors('form-tipo');
      document.getElementById('modal-tipo').classList.add('open');
    };

    window.deleteTipo = async (id) => {
      const t = tiposCache.find(x => x.id === id);
      if (!confirm(`¿Eliminar tipo "${t.nombre}"?`)) return;
      try {
        await apiDelete(`/admin/tipos-usuario/${id}`);
        loadTipos();
      } catch (err) { alert(err.message ?? 'Error.'); }
    };

    document.getElementById('close-modal-tipo').addEventListener('click', () => document.getElementById('modal-tipo').classList.remove('open'));
    document.getElementById('cancel-tipo').addEventListener('click', () => document.getElementById('modal-tipo').classList.remove('open'));

    document.getElementById('form-tipo').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors('form-tipo');
      const nombre      = document.getElementById('t-nombre').value.trim();
      const descripcion = document.getElementById('t-descripcion').value.trim();
      const activo      = document.getElementById('t-activo').value === '1';

      if (!nombre) { showErr('err-t-nombre'); return; }

      const btn = document.getElementById('submit-tipo');
      btn.disabled = true;
      try {
        if (editTipoId) {
          await apiPut(`/admin/tipos-usuario/${editTipoId}`, { nombre, descripcion, activo });
        } else {
          await apiPost('/admin/tipos-usuario', { nombre, descripcion, activo });
        }
        document.getElementById('modal-tipo').classList.remove('open');
        loadTipos();
      } catch (err) {
        handleFormError(err, { nombre: 'err-t-nombre' });
      } finally { btn.disabled = false; }
    });

    // ── TAB: Asignaciones ─────────────────────────────────────────────
    async function loadAsignaciones() {
      // Cargar caches necesarios para los selects
      if (!proyectosCache.length) proyectosCache = (await apiGet('/proyectos?all=1')) ?? [];
      if (!usuariosCache.length)  usuariosCache  = (await apiGet('/admin/usuarios'))  ?? [];
      if (!tiposCache.length)     tiposCache     = (await apiGet('/admin/tipos-usuario')) ?? [];

      // Actualizar selects
      populateSelectFromCache('a-usuario', usuariosCache,  'id', 'nombre');
      populateSelectFromCache('a-proyecto', proyectosCache, 'id', 'nombre');

      const data = await apiGet('/admin/asignaciones');
      const rows = data ?? [];
      const tbody = document.getElementById('tbody-asignaciones');
      if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Sin asignaciones.</td></tr>`;
        return;
      }
      tbody.innerHTML = rows.map(a => {
        const u = usuariosCache.find(x => x.id === a.usuario_id);
        const p = proyectosCache.find(x => x.id === a.proyecto_id);
        const t = tiposCache.find(x => x.id === a.tipo_id);
        return `<tr>
          <td>${escHtml(u?.nombre ?? `#${a.usuario_id}`)}</td>
          <td><span class="code-badge">${escHtml(p?.codigo ?? `#${a.proyecto_id}`)}</span> ${escHtml(p?.nombre ?? '')}</td>
          <td><span class="role-badge role-${a.rol === 'admin' ? 'admin' : 'usuario'}">${escHtml(a.rol)}</span></td>
          <td style="color:var(--text-muted)">${escHtml(t?.nombre ?? '—')}</td>
          <td><button class="btn-tbl danger" onclick="deleteAsignacion(${a.id})">✕</button></td>
        </tr>`;
      }).join('');
    }

    window.deleteAsignacion = async (id) => {
      if (!confirm('¿Revocar esta asignación?')) return;
      try {
        await apiDelete(`/admin/asignaciones/${id}`);
        loadAsignaciones();
      } catch (err) { alert(err.message ?? 'Error.'); }
    };

    document.getElementById('btn-new-asignacion').addEventListener('click', () => {
      document.getElementById('form-asignacion').reset();
      clearErrors('form-asignacion');
      document.getElementById('modal-asignacion').classList.add('open');
    });
    document.getElementById('close-modal-asignacion').addEventListener('click', () => document.getElementById('modal-asignacion').classList.remove('open'));
    document.getElementById('cancel-asignacion').addEventListener('click', () => document.getElementById('modal-asignacion').classList.remove('open'));

    document.getElementById('form-asignacion').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors('form-asignacion');
      const usuarioId = document.getElementById('a-usuario').value;
      const proyectoId = document.getElementById('a-proyecto').value;
      const rol       = document.getElementById('a-rol').value;
      const tipoId    = document.getElementById('a-tipo').value || null;

      let valid = true;
      if (!usuarioId)  { showErr('err-a-usuario');  valid = false; }
      if (!proyectoId) { showErr('err-a-proyecto'); valid = false; }
      if (!valid) return;

      const btn = document.getElementById('submit-asignacion');
      btn.disabled = true;
      try {
        await apiPost('/admin/asignaciones', { usuario_id: usuarioId, proyecto_id: proyectoId, rol, tipo_id: tipoId });
        document.getElementById('modal-asignacion').classList.remove('open');
        loadAsignaciones();
      } catch (err) {
        handleFormError(err, { usuario_id: 'err-a-usuario', proyecto_id: 'err-a-proyecto' });
      } finally { btn.disabled = false; }
    });

    // ── TAB: Logs ─────────────────────────────────────────────────────
    async function loadLogs() {
      const proyectoFiltro = document.getElementById('log-proyecto-filter').value;
      const url = proyectoFiltro ? `/admin/logs?proyecto_id=${proyectoFiltro}` : '/admin/logs';
      const data = await apiGet(url);
      const rows = data ?? [];
      const tbody = document.getElementById('tbody-logs');
      if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Sin registros.</td></tr>`;
        return;
      }
      tbody.innerHTML = rows.map(r => {
        const u = usuariosCache.find(x => x.id === r.usuario_id);
        const p = proyectosCache.find(x => x.id === r.proyecto_id);
        const fecha = new Date(r.created_at).toLocaleString('es-CL', { dateStyle: 'short', timeStyle: 'short' });
        return `<tr>
          <td style="color:var(--text-muted);font-size:11px">${fecha}</td>
          <td><span class="log-origin">${escHtml(r.origen)}</span></td>
          <td><span class="action-badge action-${r.accion}">${escHtml(r.accion)}</span></td>
          <td>${escHtml(u?.nombre ?? `#${r.usuario_id}`)}</td>
          <td>${p ? `<span class="code-badge">${escHtml(p.codigo)}</span>` : '<span style="color:var(--text-muted)">—</span>'}</td>
        </tr>`;
      }).join('');
    }

    document.getElementById('btn-reload-logs').addEventListener('click', loadLogs);
    document.getElementById('log-proyecto-filter').addEventListener('change', loadLogs);

    // ── Helpers ───────────────────────────────────────────────────────
    function escHtml(s) {
      if (s == null) return '';
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function showErr(id) {
      const el = document.getElementById(id);
      if (el) el.classList.add('visible');
    }

    function clearErrors(formId) {
      document.getElementById(formId)?.querySelectorAll('.field-error').forEach(el => el.classList.remove('visible'));
    }

    function handleFormError(err, fieldMap) {
      if (err.status === 422 && err.errors) {
        Object.entries(fieldMap).forEach(([apiField, errId]) => {
          if (err.errors[apiField]) {
            const el = document.getElementById(errId);
            if (el) { el.textContent = err.errors[apiField][0]; el.classList.add('visible'); }
          }
        });
      } else {
        alert(err.message ?? 'Error al guardar.');
      }
    }

    function populateSelectFromCache(selectId, items, valKey, labelKey) {
      const sel = document.getElementById(selectId);
      const prev = sel.value;
      sel.innerHTML = '<option value="">— Seleccionar —</option>';
      items.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item[valKey];
        opt.textContent = item.codigo ? `${item.codigo} — ${item[labelKey]}` : item[labelKey];
        sel.appendChild(opt);
      });
      sel.value = prev;
    }

    // ── Init ──────────────────────────────────────────────────────────
    loadProyectos();
  </script>
</body>
</html>
```

- [ ] **Step 18.2: Verificar en browser**

```bash
cd backend && php artisan serve
```

Flujo a verificar como SUPERUSER:
1. Login → `selector-proyecto.html` → click "Panel SUPERUSER" → llega a `superuser.html`
2. Tab Proyectos → tabla con proyectos existentes → "+ Nuevo Proyecto" → modal → guardar → aparece en tabla
3. Tab Usuarios → lista de usuarios → "+ Nuevo Usuario" → modal → crear → aparece en lista
4. Tab Tipos → "+ Nuevo Tipo" → crear "Inspector de Calidad" → aparece en tabla
5. Tab Asignaciones → selects populados → crear asignación usuario→proyecto → aparece en tabla → revocar
6. Tab Logs → tabla con todas las acciones registradas → filtrar por proyecto

- [ ] **Step 18.3: Commit**

```bash
git add backend/public/app/superuser.html
git commit -m "feat: add superuser.html - admin panel with projects, users, types, assignments, logs"
```

---

### Task 19: Smoke test end-to-end y puesta en marcha

**Files:**
- Modify: `backend/.env.example` — documentar variables obligatorias
- Run: `php artisan db:seed --class=SuperuserSeeder` — crear primer usuario SUPERUSER

Esta task no produce archivos nuevos. Valida que todo el sistema funciona integrado desde cero.

- [ ] **Step 19.1: Configurar `.env` y base de datos**

En `backend/.env`, verificar que estas variables estén completas:

```ini
APP_NAME=AppHub
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=apphub
DB_USERNAME=root
DB_PASSWORD=tu_password_aqui

SANCTUM_STATELESS_DOMAINS=localhost:8000

# SUPERUSER inicial — usado por SuperuserSeeder
SUPERUSER_NOMBRE="Luis Garnica"
SUPERUSER_EMAIL=luisgarnica@hotmail.cl
SUPERUSER_PASSWORD=TuPasswordSeguro123
```

Crear la base de datos si no existe:

```sql
CREATE DATABASE IF NOT EXISTS apphub CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

- [ ] **Step 19.2: Documentar variables en `.env.example`**

Abrir `backend/.env.example` y agregar al final:

```ini
# ── AppHub ──────────────────────────────────────────────────────────
# SUPERUSER inicial — ejecutar: php artisan db:seed --class=SuperuserSeeder
SUPERUSER_NOMBRE="Nombre Apellido"
SUPERUSER_EMAIL=admin@empresa.cl
SUPERUSER_PASSWORD=CambiarEstaPassword123
```

- [ ] **Step 19.3: Ejecutar migraciones y seeder**

```bash
cd backend

# Crear tablas
php artisan migrate

# Crear SUPERUSER (idempotente — no duplica si ya existe)
php artisan db:seed --class=SuperuserSeeder
```

Salida esperada de migrate:
```
INFO  Running migrations.
  2024_01_01_000001_create_usuarios_table .............. 12ms DONE
  2024_01_01_000002_create_tipos_usuario_table ......... 8ms DONE
  ... (15 migraciones en total)
```

Salida esperada del seeder:
```
SUPERUSER creado: luisgarnica@hotmail.cl
```
O si ya existe:
```
SUPERUSER ya existe: luisgarnica@hotmail.cl
```

- [ ] **Step 19.4: Smoke test completo del sistema**

Con el servidor corriendo (`php artisan serve`):

**A. Auth**
```bash
# Login
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"luisgarnica@hotmail.cl","password":"TuPasswordSeguro123"}' | jq -r .token)

echo "Token: $TOKEN"

# Verificar /me
curl -s http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer $TOKEN" | jq .
```
Salida esperada: `{"id":1,"nombre":"Luis Garnica","email":"...","rol_global":"superuser"}`

**B. Crear proyecto y jerarquía completa**
```bash
# Proyecto
curl -s -X POST http://localhost:8000/api/proyectos \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codigo":"AUT-001","nombre":"Autopista Norte Tramo 1","estado":"activo"}' | jq .

PID=1  # ajustar al id devuelto

# Área
curl -s -X POST http://localhost:8000/api/proyectos/$PID/areas \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codigo":"3600","nombre":"Estructura"}' | jq .

AID=1  # ajustar al id devuelto

# Subárea
curl -s -X POST http://localhost:8000/api/proyectos/$PID/subareas \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codigo":"3610","nombre":"Fundaciones","area_id":'$AID'}' | jq .

SAID=1  # ajustar

# Sistema
curl -s -X POST http://localhost:8000/api/proyectos/$PID/sistemas \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codigo":"3610B","nombre":"Pilotes","subarea_id":'$SAID'}' | jq .

SIID=1  # ajustar

# Subsistema
curl -s -X POST http://localhost:8000/api/proyectos/$PID/subsistemas \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codigo":"3610B-1","nombre":"Pilotes hormigón","sistema_id":'$SIID'}' | jq .
```

**C. Verificar jerarquía**
```bash
curl -s http://localhost:8000/api/proyectos/$PID/areas       -H "Authorization: Bearer $TOKEN" | jq '.[].codigo'
curl -s http://localhost:8000/api/proyectos/$PID/subareas    -H "Authorization: Bearer $TOKEN" | jq '.[].codigo'
curl -s http://localhost:8000/api/proyectos/$PID/sistemas    -H "Authorization: Bearer $TOKEN" | jq '.[].codigo'
curl -s http://localhost:8000/api/proyectos/$PID/subsistemas -H "Authorization: Bearer $TOKEN" | jq '.[].codigo'
```
Salida esperada: `"3600"` / `"3610"` / `"3610B"` / `"3610B-1"`

**D. Descargar plantilla Excel**
```bash
curl -s -o template.xlsx \
  http://localhost:8000/api/proyectos/$PID/import/template \
  -H "Authorization: Bearer $TOKEN"

file template.xlsx
# Esperado: template.xlsx: Microsoft Excel 2007+
```

**E. Test de aislamiento (debe fallar con 403)**
```bash
# Crear segundo usuario sin asignación al proyecto
USER2_TOKEN=$(curl -s -X POST http://localhost:8000/api/admin/usuarios \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"nombre":"Test User","email":"test@test.cl","password":"Password123","rol_global":"usuario","activo":true}' | jq -r .token)

# Sin token de autenticación propio, simular con un token inválido
curl -s http://localhost:8000/api/proyectos/$PID/areas \
  -H "Authorization: Bearer token_invalido" | jq .status
# Esperado: 401
```

**F. Validación duplicado (debe fallar con 422)**
```bash
curl -s -X POST http://localhost:8000/api/proyectos/$PID/areas \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"codigo":"3600","nombre":"Duplicado"}' | jq .errors
# Esperado: {"codigo": ["El código ya existe en este proyecto."]}
```

**G. Suite de tests PHPUnit (76 tests)**
```bash
cd backend
php artisan test
```
Salida esperada:
```
   PASS  Tests\Feature\AuthTest (8 tests)
   PASS  Tests\Feature\ProyectoTest (8 tests)
   PASS  Tests\Feature\AreaTest (9 tests)
   PASS  Tests\Feature\SubareaTest (8 tests)
   PASS  Tests\Feature\SistemaTest (8 tests)
   PASS  Tests\Feature\SubsistemaTest (9 tests)
   PASS  Tests\Feature\ImportTest (8 tests)
   PASS  Tests\Feature\Admin\UsuarioTest (...)
   PASS  Tests\Feature\Admin\TipoUsuarioTest (...)
   PASS  Tests\Feature\Admin\AsignacionTest (...)
   PASS  Tests\Feature\Admin\LogTest (5 tests)

  Tests:    76 passed
  Duration: ~8s
```

**H. Flujo UI completo en browser**

Abrir `http://localhost:8000/app/login.html` y verificar:

1. Login con SUPERUSER → redirige a `selector-proyecto.html`
2. Botón "Panel SUPERUSER" visible → lleva a `superuser.html`
3. En `superuser.html`: crear proyecto → crear usuario → asignar usuario al proyecto como Admin
4. Logout → login con el usuario Admin
5. Seleccionar el proyecto → selector de app → Quiebre del Contrato
6. Árbol vacío → "+ Agregar" → crear área, subárea, sistema, subsistema
7. Árbol muestra los 4 niveles con toggles funcionales
8. Botón "Cargar Excel" → descargar formato → abrir en Excel → completar datos → subir
9. Preview muestra filas → confirmar → árbol se actualiza

- [ ] **Step 19.5: Commit final**

```bash
git add backend/.env.example
git commit -m "chore: document env variables for superuser seeder"
```

---

## Resumen del sistema

| # | Task | Archivos clave | Tests |
|---|------|----------------|-------|
| 1 | Laravel setup | `bootstrap/app.php`, `.env`, `phpunit.xml` | — |
| 2 | Migraciones | 15 archivos en `database/migrations/` | — |
| 3 | Modelos + Factories | 8 modelos, 6 factories | — |
| 4 | Middleware + LogService | `CheckRole`, `CheckProyectoAccess`, `LogService` | — |
| 5 | Auth API | `AuthController`, `routes/api.php` | 8 |
| 6 | Proyectos API | `ProyectoController` | 8 (acum. 16) |
| 7 | Áreas API | `AreaController` | 9 (acum. 25) |
| 8 | Subáreas API | `SubareaController` | 8 (acum. 33) |
| 9 | Sistemas API | `SistemaController` | 8 (acum. 41) |
| 10 | Subsistemas API | `SubsistemaController` | 9 (acum. 50) |
| 11 | Import Excel | `ImportService`, `ImportController` | 8 (acum. 58) |
| 12 | Admin CRUD | `UsuarioController`, `TipoUsuarioController`, `AsignacionController` | 13 (acum. 71) |
| 13 | Admin Logs + Seeder | `LogController`, `SuperuserSeeder` | 5 (acum. **76**) |
| 14 | CSS + api.js | `app.css`, `api.js` | — |
| 15 | login.html | `login.html` | — |
| 16 | Selectores | `selector-proyecto.html`, `selector-app.html` | — |
| 17 | quiebre.html | `quiebre.html` | — |
| 18 | superuser.html | `superuser.html` | — |
| 19 | Smoke test | `.env.example`, verificación manual | — |

**Total: 76 tests PHPUnit + verificación manual UI completa**
