# Spec: Módulo Quiebre del Contrato — AppHub

**Fecha:** 2026-04-24  
**Estado:** Aprobado (diseño visual y arquitectura validados)  
**Stack:** Laravel + MySQL + HTML/CSS/JS (fetch API REST)

---

## 1. Visión general

AppHub es un sistema multi-proyecto con aislamiento de datos por proyecto y control de roles. El módulo **Quiebre del Contrato** es la primera app del sistema: permite registrar y gestionar la jerarquía de ítems de un contrato de construcción (áreas → subáreas → sistemas → subsistemas), con entrada manual o carga masiva desde Excel.

El foco central de AppHub es la **integración entre apps**: todos los módulos comparten el mismo modelo de proyectos, usuarios y jerarquía, y están diseñados para que sus datos conversen entre sí y permitan detectar desviaciones entre áreas.

---

## 2. Roles y permisos

| Rol | Acceso |
|-----|--------|
| **SUPERUSER** | Gestión global: crear proyectos, crear/editar usuarios y tipos de usuario, asignar usuarios a proyectos, ver log de auditoría cruzado |
| **Admin** | Dentro de sus proyectos asignados: CRUD completo de jerarquía, carga Excel, ver log de su proyecto |
| **Usuario** | Dentro de sus proyectos asignados: lectura de jerarquía, carga Excel (sin edición manual) |
| **Tipos personalizados** | Variantes del rol Usuario creadas por el SUPERUSER (ej. Calidad, Inspector). Heredan los permisos de Usuario como base, pero en el futuro podrán tener acceso restringido a módulos específicos (ej. un tipo "Calidad" solo ve las apps de calidad, y puede ser editor en su módulo pero solo visualizador en otros). En este módulo son etiquetas organizacionales sin lógica diferenciada. |

Un usuario puede tener distinto rol en distintos proyectos (tabla `usuarios_proyectos`). El SUPERUSER es el único rol que no está ligado a un proyecto específico.

Los tipos personalizados se almacenan en la tabla `tipos_usuario` y se asignan en `usuarios_proyectos.tipo_id`. En esta iteración no alteran el modelo de permisos: siempre se evalúa el `rol` (Admin/Usuario). El control de acceso por módulo según tipo se diseñará como spec separado cuando se levante el segundo módulo.

**Nota sobre roles futuros:** Existirán usuarios tipo "Revisor" en módulos posteriores, con capacidad de generar observaciones sobre información entregada. No se consideran en este spec.

---

## 3. Flujo de páginas

```
login.html
  └── selector-proyecto.html     ← elige proyecto (solo proyectos asignados)
        └── selector-app.html    ← elige la app a abrir dentro del proyecto
              └── quiebre.html   ← tabla jerárquica del proyecto
                    ├── (modal/panel) formulario cascada  ← Admin
                    └── (modal/panel) carga Excel         ← Admin/Usuario

superuser.html                   ← acceso directo desde selector-proyecto (rol SUPERUSER)
```

El **selector de app** existe desde el inicio para soportar múltiples módulos futuros. En esta primera iteración muestra únicamente "Quiebre del Contrato". Cada nueva app que se levante aparece como una card adicional en esa pantalla, sin modificar el resto del flujo.

---

## 4. Diseño de pantallas (mockups aprobados)

### 4.1 Selector de Proyecto
- Cards por proyecto con código, nombre y estado (Activo/Archivado)
- Solo muestra proyectos donde el usuario tiene asignación activa
- SUPERUSER ve todos los proyectos + acceso al panel de administración

### 4.2 Selector de App
- Cards por app disponible en el proyecto (actualmente solo "Quiebre del Contrato")
- Muestra nombre, ícono y descripción breve de cada app
- Las apps futuras se agregan aquí sin cambiar el flujo existente

### 4.3 Tabla jerárquica (quiebre.html)
- Árbol colapsable: Área > Subárea > Sistema > Subsistema
- Columnas: Código (definido por el usuario), Nombre, Acciones (editar/eliminar para Admin)
- **El código lo asigna el usuario** — el sistema solo valida que sea único dentro del proyecto
- Filtro de búsqueda por código o nombre
- Botón "+ Agregar" (abre formulario manual, solo Admin)
- Botón "Cargar Excel" con opción de **descargar el formato estándar** desde la misma pantalla

### 4.4 Formulario manual cascada
- El formulario respeta la jerarquía estrictamente: no se puede crear un subsistema sin seleccionar primero el sistema al que pertenece, ni un sistema sin subárea, ni una subárea sin área
- Dropdowns en cascada cargados dinámicamente según el nivel seleccionado
- Campo código (libre, validado como único en el proyecto) + campo nombre
- Los códigos siguen la lógica del proyecto, ej: `3600` Área → `3610` Subárea → `3610B` Sistema → `3610B-1` Subsistema. El sistema no impone un formato fijo; solo exige unicidad
- Validación en frontend antes de enviar; errores mostrados inline

### 4.5 Carga Excel
- Drag & drop o selector de archivo `.xlsx`
- Descarga de plantilla estándar desde la misma pantalla (botón "Descargar formato")
- Preview de filas antes de confirmar importación
- Detección de duplicados: muestra filas en conflicto con opción "omitir / sobreescribir"
- Resultado: resumen con registros importados, omitidos y errores

### 4.6 Panel SUPERUSER (superuser.html)
- Tabs: Proyectos | Usuarios | Tipos de Usuario | Asignaciones | Logs
- **Proyectos:** tabla con código/nombre/estado + botón "+ Nuevo Proyecto" + editar
- **Usuarios:** lista con avatar, email, rol global + crear/editar/eliminar
- **Tipos de Usuario:** CRUD de tipos personalizados (etiquetas del rol Usuario)
- **Asignaciones:** mapeo Usuario → Proyecto + rol + tipo (crear/revocar)
- **Logs:** log consolidado de auditoría global (UNION de todos los `*_log`)

---

## 5. Base de datos (15 tablas)

### 5.1 Tablas principales

```sql
usuarios           (id, nombre, email, password_hash, rol_global, activo, created_at)
tipos_usuario      (id, nombre, descripcion, activo, created_at)   -- tipos personalizados
proyectos          (id, codigo, nombre, estado, created_at)
usuarios_proyectos (id, usuario_id, proyecto_id, rol, tipo_id NULL, created_at)

areas              (id, proyecto_id, codigo, nombre, orden, created_at, updated_at)
subareas           (id, proyecto_id, area_id, codigo, nombre, orden, created_at, updated_at)
sistemas           (id, proyecto_id, subarea_id, codigo, nombre, orden, created_at, updated_at)
subsistemas        (id, proyecto_id, sistema_id, codigo, nombre, orden, created_at, updated_at)
```

`tipos_usuario.id` es referenciado por `usuarios_proyectos.tipo_id` (nullable — solo aplica cuando `rol = 'usuario'`).

Todas las tablas de jerarquía incluyen `proyecto_id` directamente para aislamiento y para facilitar el UNION del log global sin JOINs adicionales.

### 5.2 Tablas de log (7 tablas)

Cada entidad tiene su propia tabla de auditoría. Estructura base:

```sql
*_log (
  id            INT PK AUTO_INCREMENT,
  proyecto_id   INT,
  usuario_id    INT,
  accion        ENUM('CREATE','UPDATE','DELETE','IMPORT','IMPORT_ERROR_DISMISSED','VALIDATION_ERROR'),
  entidad_id    INT NULL,      -- PK del registro afectado (NULL si el registro nunca se creó)
  datos_antes   JSON NULL,     -- estado previo (UPDATE/DELETE)
  datos_despues JSON NULL,     -- estado nuevo (CREATE/UPDATE) o payload intentado (ERROR)
  error_detalle JSON NULL,     -- descripción estructurada del error: {campo, motivo, valor_ingresado}
  ip            VARCHAR(45),
  created_at    TIMESTAMP
)
```

Tablas: `areas_log`, `subareas_log`, `sistemas_log`, `subsistemas_log`, `proyectos_log`, `usuarios_log`, `usuarios_proyectos_log`.

**Regla:** cada entidad registra sus cambios en su propio log. `proyectos_log` solo registra cambios sobre el proyecto como entidad (renombrar, archivar). Cambios en áreas → `areas_log` (con `proyecto_id` para contexto).

### 5.3 Log global (UNION query, sin tabla extra)

El panel SUPERUSER consulta una vista consolidada:

```sql
SELECT 'areas'        AS origen, id, proyecto_id, usuario_id, accion, created_at FROM areas_log
UNION ALL
SELECT 'subareas',               id, proyecto_id, usuario_id, accion, created_at FROM subareas_log
UNION ALL
SELECT 'sistemas',               id, proyecto_id, usuario_id, accion, created_at FROM sistemas_log
UNION ALL
SELECT 'subsistemas',            id, proyecto_id, usuario_id, accion, created_at FROM subsistemas_log
UNION ALL
SELECT 'proyectos',              id, proyecto_id, usuario_id, accion, created_at FROM proyectos_log
UNION ALL
SELECT 'usuarios',               id, NULL,        usuario_id, accion, created_at FROM usuarios_log
UNION ALL
SELECT 'asignaciones',           id, proyecto_id, usuario_id, accion, created_at FROM usuarios_proyectos_log
ORDER BY created_at DESC
LIMIT 200
```

Cada fila expone `origen` + `id`, lo que permite navegar al registro exacto en su tabla de origen. No hay duplicación de datos.

---

## 6. API REST (Laravel)

### Autenticación
```
POST   /api/auth/login
POST   /api/auth/logout
GET    /api/auth/me
```

### Proyectos
```
GET    /api/proyectos                       ← proyectos del usuario autenticado
GET    /api/proyectos/:id
POST   /api/proyectos                       ← SUPERUSER
PUT    /api/proyectos/:id                   ← SUPERUSER
```

### Jerarquía (aislada por proyecto)
```
GET    /api/proyectos/:id/areas
POST   /api/proyectos/:id/areas
PUT    /api/proyectos/:id/areas/:areaId
DELETE /api/proyectos/:id/areas/:areaId

-- ídem para /subareas, /sistemas, /subsistemas
```

### Carga Excel
```
POST   /api/proyectos/:id/import            ← multipart/form-data
GET    /api/proyectos/:id/import/template   ← descarga plantilla .xlsx
```

### SUPERUSER
```
GET    /api/admin/usuarios
POST   /api/admin/usuarios
PUT    /api/admin/usuarios/:id
DELETE /api/admin/usuarios/:id

GET    /api/admin/tipos-usuario
POST   /api/admin/tipos-usuario
PUT    /api/admin/tipos-usuario/:id
DELETE /api/admin/tipos-usuario/:id

GET    /api/admin/asignaciones
POST   /api/admin/asignaciones
DELETE /api/admin/asignaciones/:id

GET    /api/admin/logs                      ← UNION query
```

---

## 7. Middleware de autorización

- `auth:sanctum` — verifica token en todas las rutas `/api`
- `CheckProyectoAccess` — verifica que el usuario tenga asignación activa en el `proyecto_id` del request
- `CheckRole('admin')` — verifica rol Admin o SUPERUSER para operaciones de escritura
- `CheckRole('superuser')` — solo SUPERUSER para rutas `/api/admin/*`

---

## 8. Códigos jerárquicos

Los códigos son **asignados libremente por el usuario**. El sistema no impone un formato ni los genera automáticamente. Sí garantiza:

- Unicidad del código dentro del mismo proyecto
- Que el registro padre exista antes de crear un hijo (validado en backend)

El esquema de codificación lo define cada proyecto. Ejemplo válido:

| Nivel | Código | Nombre |
|-------|--------|--------|
| Área | `3600` | Estructura |
| Subárea | `3610` | Fundaciones |
| Sistema | `3610B` | Pilotes |
| Subsistema | `3610B-1` | Pilotes hormigón |

Cualquier otro esquema alfanumérico es igualmente válido siempre que sea único en el proyecto.

---

## 9. Carga masiva Excel

### Formato de la plantilla descargable

| codigo | nombre | nivel | codigo_padre_de_quiebre |
|--------|--------|-------|------------------------|
| 3600 | Estructura | area | — |
| 3610 | Fundaciones | subarea | 3600 |
| 3610B | Pilotes | sistema | 3610 |
| 3610B-1 | Pilotes hormigón | subsistema | 3610B |

La columna se llama `codigo_padre_de_quiebre` y la plantilla incluye una nota en la cabecera: *"Indique el código del ítem padre del cual se desprende este registro en la jerarquía del contrato. Dejar vacío solo para ítems de nivel Área."*

### Proceso de importación
1. Parseo y validación de columnas obligatorias
2. Validación de integridad referencial (`codigo_padre_de_quiebre` debe existir en el proyecto)
3. Detección de duplicados (por `codigo` dentro del proyecto)
4. Preview con filas válidas, duplicadas y con error — cada fila problemática muestra el motivo exacto (ver §10)
5. Confirmación del usuario con opción omitir/sobreescribir por fila
6. Inserción en transacción + registro en `*_log` con `accion = 'IMPORT'`
7. Errores de validación que el usuario omite quedan registrados en log con `accion = 'IMPORT_ERROR_DISMISSED'`

---

## 10. Manejo de errores y validación

### Principio general
Cuando el sistema detecta un error — ya sea en formulario manual, carga Excel o cualquier operación — debe:
1. **Indicar exactamente dónde y por qué:** campo afectado, motivo del error y valor ingresado
2. **Dar control al usuario:** puede corregir o, en casos permitidos (ej. duplicados en Excel), elegir omitir o sobreescribir
3. **Registrar la decisión en el log:** tanto si el usuario corrige como si valida/descarta el error

### En formulario manual
- Errores mostrados inline bajo el campo afectado, no solo al final del form
- Mensajes en lenguaje claro: *"El código '3610B' ya existe en este proyecto"*, *"Debe seleccionar una subárea antes de crear un sistema"*
- Si el usuario intenta enviar con errores presentes, se bloquea el envío y se resaltan todos los campos con problema

### En carga Excel
- La tabla de preview marca cada fila con estado: ✓ válida / ⚠ duplicado / ✕ error
- Por cada fila con problema se muestra: número de fila, campo problemático y motivo
- El usuario puede: corregir el archivo y subir de nuevo, o decidir fila a fila (omitir / sobreescribir)
- Las filas que el usuario decide omitir a pesar del error se registran en log con `accion = 'IMPORT_ERROR_DISMISSED'` incluyendo `error_detalle`

### En el log
Estructura del campo `error_detalle`:
```json
{
  "campo": "codigo",
  "motivo": "duplicado",
  "valor_ingresado": "3610B",
  "fila_excel": 5,
  "decision_usuario": "omitir"
}
```

Esto garantiza trazabilidad completa: qué se intentó, qué falló, y qué eligió hacer el usuario.

---

## 11. Consideraciones de seguridad

- Passwords con `bcrypt` (Laravel default)
- Tokens Sanctum con expiración configurable
- Aislamiento estricto por `proyecto_id` en todas las queries (nunca confiar en el frontend para filtrar)
- Validación de tipos MIME del archivo Excel en backend (no solo extensión)
- Rate limiting en `/api/auth/login`

---

## 12. Integración con otros módulos (principio de diseño central)

La integración entre apps es el eje central de AppHub. Cada módulo futuro (presupuesto, avance de obra, calidad, etc.) referenciará la misma jerarquía de áreas/subáreas/sistemas/subsistemas de este módulo, usando `proyecto_id` + los IDs de jerarquía como llaves de cruce.

El contrato de datos que este módulo expone hacia el resto de la plataforma es:

```
proyecto_id → area_id → subarea_id → sistema_id → subsistema_id
```

Cualquier módulo que quiera analizar desviaciones entre áreas lo hará cruzando sus propios registros contra esta jerarquía. No se duplican datos — cada app lee la jerarquía via API.

El diseño de las APIs de jerarquía (`/api/proyectos/:id/areas`, etc.) debe mantenerse estable para no romper integraciones futuras.

---

## 13. Fuera de alcance en este spec

- Notificaciones / emails (fase posterior)
- Exportar PDF/Excel desde la vista de tabla (fase posterior)
- Definición del contrato de integración con apps específicas futuras (spec separado por app)
