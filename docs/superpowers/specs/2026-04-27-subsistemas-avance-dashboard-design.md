# Subsistemas: Avance Constructivo + Dashboard de Proyecto

**Fecha:** 2026-04-27  
**Estado:** Aprobado

---

## Contexto

El módulo de Quiebre del Contrato muestra una estructura jerárquica: Área → Subárea → Sistema → Subsistema. Actualmente los subsistemas solo tienen código, nombre y orden. Se requiere agregar fechas planificadas/reales y avance constructivo, más un dashboard visual por proyecto.

---

## Alcance

1. Nuevos campos en `subsistemas` (DB + backend)
2. Actualización del modal de edición en `quiebre.html`
3. Nueva página `dashboard.html` con Gantt y barras de progreso

---

## 1. Modelo de Datos

### Migración nueva — `add_avance_fields_to_subsistemas`

Agrega las siguientes columnas a la tabla `subsistemas`:

| Columna | Tipo | Nullable | Notas |
|---|---|---|---|
| `fecha_inicio_plan` | `date` | sí | Fecha planificada de inicio (fija en UI tras primer guardado) |
| `fecha_termino_plan` | `date` | sí | Fecha planificada de término (fija en UI tras primer guardado) |
| `fecha_inicio_real` | `date` | sí | Fecha real de inicio (siempre editable) |
| `fecha_termino_real` | `date` | sí | Fecha real de término (siempre editable) |
| `avance_constructivo` | `tinyInteger` unsigned | sí | 0–100, ingreso manual |

La protección de fechas plan (solo mostrarse como texto si ya tienen valor) es responsabilidad del frontend, no de la DB.

---

## 2. Backend

### Model `Subsistema`

Agregar los 5 campos nuevos a `$fillable`:
```
'fecha_inicio_plan', 'fecha_termino_plan',
'fecha_inicio_real', 'fecha_termino_real',
'avance_constructivo'
```

Agregar cast de fechas:
```php
protected $casts = [
    'fecha_inicio_plan'   => 'date:Y-m-d',
    'fecha_termino_plan'  => 'date:Y-m-d',
    'fecha_inicio_real'   => 'date:Y-m-d',
    'fecha_termino_real'  => 'date:Y-m-d',
];
```

### Controller `SubsistemaController`

- `store()` y `update()`: aceptar los 5 campos nuevos, validar `avance_constructivo` entre 0–100
- Los 5 campos son opcionales (nullable)

### Nuevo endpoint: `GET /proyectos/{id}/dashboard`

Devuelve para el proyecto:
```json
{
  "proyecto": { "id", "codigo", "nombre" },
  "resumen": {
    "total_subsistemas": 12,
    "con_avance": 8,
    "avance_promedio": 45,
    "con_retraso": 3
  },
  "subsistemas": [
    {
      "id", "codigo", "nombre",
      "sistema_nombre",
      "fecha_inicio_plan", "fecha_termino_plan",
      "fecha_inicio_real", "fecha_termino_real",
      "avance_constructivo"
    }
  ]
}
```

Un subsistema está "con retraso" si `fecha_termino_real > fecha_termino_plan` (ambas presentes).

---

## 3. Cambios en `quiebre.html`

### Árbol — filas de subsistema

Agregar columna **"Avance"** al `<thead>` y a las filas de nivel subsistema (nivel-3). Muestra:
- `45%` con una mini barra de progreso visual si tiene valor
- `—` si `avance_constructivo` es null

### Modal de edición de subsistema

Cuando el nivel seleccionado es `subsistema`, mostrar bloque adicional con:

1. **Fechas planificadas** (grupo):
   - `fecha_inicio_plan` — input date; si ya tiene valor: mostrar como texto (no editable)
   - `fecha_termino_plan` — input date; si ya tiene valor: mostrar como texto (no editable)

2. **Fechas reales** (grupo):
   - `fecha_inicio_real` — input date, siempre editable
   - `fecha_termino_real` — input date, siempre editable

3. **Avance constructivo**:
   - Input number (0–100) con label "Avance constructivo (%)"

Estos campos no aplican a Área, Subárea ni Sistema — se ocultan para esos niveles.

---

## 4. Nueva página `dashboard.html`

### Acceso

Link en el navbar de `quiebre.html`: botón "Dashboard" que lleva a `/app/dashboard.html?proyecto_id=X`.

### Estructura de la página

```
Navbar (igual que quiebre.html)

── Resumen del proyecto ──────────────────────
  [Total subsistemas]  [Avance promedio %]  [Con retraso]
  Barra de progreso general del proyecto

── Gantt por subsistema ──────────────────────
  Tabla con barra horizontal por subsistema:
  | Código | Nombre | [████░░░░] plan | [██████░░] real |
  Eje X: escala de fechas (mínimo/máximo del proyecto)
  Color plan: gris  |  Color real: azul  |  Retraso: rojo

── Avance constructivo por subsistema ────────
  Lista con barra de progreso:
  | Código | Nombre | [████████░░] 80% |
  Ordenado de menor a mayor avance
```

### Comportamiento

- Si un subsistema no tiene fechas, no aparece en el Gantt
- Si no tiene `avance_constructivo`, la barra muestra 0%
- El avance general del proyecto = promedio de `avance_constructivo` de todos los subsistemas del proyecto
- La página usa `apiGet('/proyectos/{id}/dashboard')` del `api.js` existente

---

## Archivos a crear/modificar

| Archivo | Acción |
|---|---|
| `database/migrations/XXXX_add_avance_fields_to_subsistemas.php` | Crear |
| `app/Models/Subsistema.php` | Modificar |
| `app/Http/Controllers/SubsistemaController.php` | Modificar |
| `app/Http/Controllers/DashboardController.php` | Crear |
| `routes/api.php` | Modificar (agregar ruta dashboard) |
| `public/app/quiebre.html` | Modificar |
| `public/app/dashboard.html` | Crear |

---

## Fuera de alcance

- Avance constructivo automático (calculado desde partidas) — pendiente para fase futura
- Exportar dashboard a PDF/Excel
- Notificaciones de retraso
