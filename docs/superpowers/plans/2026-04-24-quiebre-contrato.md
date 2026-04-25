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
