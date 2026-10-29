# 🎬 PLAYBOOK DE PROMPTS — Pallet Control

> Secuencia de trabajo para llevar el proyecto a production-ready sin romper nada.
> **Regla general:** nunca arrancar tocando código. Primero se mapea, después se decide, después se toca.

---

## ⚙️ ANTES DE EMPEZAR

**Sobre repetir teoría en cada prompt:** no hace falta. La sesión entra por `AGENTS.md` y `ROUTER.md`, que cargan sólo las secciones normativas necesarias.

**Lo que SÍ hay que indicar en cada prompt:** qué capítulo de las biblias leer, porque esas NO se cargan solas.

```
❌ "Leé todas las biblias"                 → el router selecciona el contexto mínimo
✅ "Leé sección 1 de BACKEND-BIBLIA.md"    → necesario, no está cargado
```

**Seguro antes de arrancar:**
```bash
git checkout -b hardening/auditoria
git tag pre-hardening        # punto de retorno garantizado
```

---

# FASE 0 — RECONOCIMIENTO (solo lectura, cero cambios)

> Objetivo: saber qué tenemos antes de tocar. Ningún prompt de esta fase modifica archivos.

### Prompt 0.1 — Mapa general

```
Modo SOLO LECTURA: no modifiques ningún archivo en esta tarea.

Analizá la estructura del proyecto y devolveme:
1. Stack real y versiones (framework, PHP/Node, DB, front)
2. Estructura de carpetas y dónde vive cada capa
3. Listado de TODOS los endpoints (método, ruta, controlador)
4. Listado de modelos y sus relaciones
5. Qué hay de tests hoy (si hay algo)
6. Cómo está configurado el deploy actual

Formato: tablas. Sin recomendaciones todavía, solo el mapa.
```

### Prompt 0.2 — Auditoría de autenticación y autorización

```
Modo SOLO LECTURA.
Leé la sección 1 de BACKEND-BIBLIA.md.

Auditá cómo está resuelta hoy la autenticación y autorización:
1. ¿Qué mecanismo usa? (sesión, Sanctum, JWT, nada)
2. ¿Cómo se hashean las contraseñas?
3. ¿Hay expiración de tokens? ¿refresh?
4. Endpoint por endpoint: ¿verifica autenticación? ¿verifica OWNERSHIP?
5. Marcá específicamente los endpoints vulnerables a IDOR
   (que reciben un ID y no chequean que el recurso sea del usuario)

Devolveme una tabla: endpoint | auth | ownership | riesgo (alto/medio/bajo).
Sin arreglar nada todavía.
```

### Prompt 0.3 — Auditoría de datos y modelo

```
Modo SOLO LECTURA.
Leé la sección 5 de BACKEND-BIBLIA.md.

Auditá la capa de datos:
1. Esquema real: tablas, relaciones, constraints existentes
2. ¿Qué foreign keys NO tienen índice?
3. ¿Hay constraints en DB o toda la integridad depende de PHP?
4. ¿Dónde hay escrituras múltiples SIN transacción?
5. ¿Dónde hay riesgo de race condition en movimientos de stock?
6. ¿Hay soft deletes? ¿hay audit log?

Devolveme los hallazgos priorizados por riesgo de corrupción de datos.
```

### Prompt 0.4 — Auditoría de validación y errores

```
Modo SOLO LECTURA.
Leé las secciones 2 y 3 de BACKEND-BIBLIA.md.

Auditá:
1. ¿Dónde se usa $request->all() en vez de validated()?
2. ¿Qué controladores validan inline en vez de con Form Request?
3. ¿Hay $fillable/$guarded bien definidos? (riesgo de mass assignment)
4. ¿Cómo se manejan los errores? ¿hay handler global?
5. ¿Los códigos HTTP son correctos? (401 vs 403, 422 en validación)
6. ¿APP_DEBUG puede quedar en true en producción?

Tabla con archivo, línea y severidad.
```

### Prompt 0.5 — Baseline de performance

```
Leé la sección 8 de BACKEND-BIBLIA.md.

1. Instalá Laravel Debugbar (dev) y activá Model::preventLazyLoading() en local
2. Identificá los 5 endpoints con más probabilidad de N+1 y decime por qué
3. Proponeme un script de benchmark (k6 o ab) para medir esos endpoints
4. Creá la carpeta /benchmarks con el script y un README de cómo correrlo

NO optimices nada todavía. Esto es solo para tener el baseline medido.
```

### Prompt 0.6 — Auditoría de infraestructura

```
Modo SOLO LECTURA.
Leé las secciones 1, 2 y 3 de DEVOPS-BIBLIA.md.

Auditá Dockerfile, docker-compose, .dockerignore, .env.example y config de deploy:
1. ¿Es multi-stage? ¿corre como root? ¿versiones pineadas?
2. ¿Hay secretos expuestos en algún archivo versionado?
3. ¿Hay .dockerignore? ¿qué le falta?
4. ¿Hay CI de algún tipo hoy?
5. ¿Qué datos persistentes podrían perderse en un redeploy?

Priorizá por riesgo de seguridad.
```

### Prompt 0.7 — Plan consolidado

```
Con todos los hallazgos de la Fase 0, armá un PLAN.md con:

1. Todos los problemas encontrados, priorizados por:
   riesgo de seguridad > corrupción de datos > performance > calidad
2. Para cada uno: esfuerzo estimado (bajo/medio/alto) e impacto
3. Un orden de trabajo por REBANADAS VERTICALES (un endpoint completo por vez),
   empezando por los endpoints de escritura de stock
4. Qué se puede automatizar con tooling en vez de arreglar a mano

No ejecutes nada. Quiero revisar el plan antes.
```

---

# FASE 1 — RED DE SEGURIDAD (antes de tocar lógica)

> Objetivo: poder cambiar cosas sabiendo si rompiste algo. Esto va PRIMERO, siempre.

### Prompt 1.1 — Tests de caracterización

```
Leé la sección 6 de BACKEND-BIBLIA.md.

Antes de refactorizar nada, necesito una red de seguridad.
Escribí feature tests que documenten el comportamiento ACTUAL de los endpoints
principales (aunque ese comportamiento esté mal).

Objetivo: si un refactor cambia el comportamiento, el test lo detecta.

Empezá por los endpoints de creación y movimiento de pallets.
No corrijas bugs todavía — solo capturá cómo se comporta hoy.
```

### Prompt 1.2 — Tooling y CI

```
Leé la sección 3 de DEVOPS-BIBLIA.md.

Configurá:
1. Pint con config del proyecto
2. PHPStan/Larastan nivel 5 (después subimos)
3. GitHub Actions: lint → phpstan → tests → composer audit
4. El workflow debe correr en cada push y PR

No incluyas deploy todavía, solo CI.
Después decime qué configurar en GitHub para branch protection.
```

---

# FASE 2 — REBANADAS VERTICALES

> Una por vez. No se avanza a la siguiente hasta cerrar los 8 puntos del protocolo.

### Prompt 2.x — Plantilla (repetir por endpoint)

```
Rebanada vertical: endpoint [MÉTODO /ruta]
Leé secciones 1, 2, 3 y 5 de BACKEND-BIBLIA.md.

Aplicá el protocolo de cierre de `QA-GATE` y las rutas de `ROUTER.md` a este endpoint:
auth → validación → negocio → datos → respuesta → audit → tests → medición

Requisitos:
- Un commit por punto, con mensaje descriptivo
- Los tests de caracterización existentes deben seguir pasando
- Si algún test tiene que cambiar, avisame y explicá por qué antes de tocarlo
- Al final: resumen de qué cambió y qué quedó pendiente

Empezá mostrándome el estado actual del endpoint y el plan, antes de escribir código.
```

### Orden sugerido de rebanadas

```
1. POST   /pallets/{id}/movimientos    ← el más crítico: concurrencia + stock
2. POST   /pedidos                     ← transacción multi-tabla
3. PATCH  /pallets/{id}                ← ownership + audit
4. GET    /pedidos/{id}/pallets        ← N+1 seguro acá
5. GET    /pedidos                     ← paginación + índices
6. DELETE /...                         ← soft delete + audit
7. Auth   (login/refresh)              ← al final, cuando el resto ya está firme
```

---

# FASE 3 — DOMINIO ESPECÍFICO

### Prompt 3.1 — Concurrencia en stock

```
Leé la sección 5.6 de BACKEND-BIBLIA.md.

Problema real: dos operarios pueden mover el mismo pallet al mismo tiempo.

1. Identificá TODOS los puntos del código con riesgo de race condition
2. Proponeme la solución con lockForUpdate() y transacciones
3. Escribí un test que REPRODUZCA la race condition antes de arreglarla
4. Arreglala y demostrá que el test ahora pasa

Explicame el trade-off de performance del lock antes de aplicarlo.
```

### Prompt 3.2 — Audit log

```
Necesito trazabilidad completa: en un sistema de stock, "quién movió esto"
es la primera pregunta cuando falta mercadería.

1. Proponeme 2 o 3 enfoques (tabla propia, paquete tipo spatie/activitylog,
   event listeners) con sus trade-offs
2. Recomendame uno y justificalo
3. Implementalo para: creación de pedidos, movimientos de pallet,
   modificación de cantidades y eliminaciones
4. Debe registrar: usuario, acción, entidad, valor anterior, valor nuevo, timestamp

Mostrame el diseño antes de implementar.
```

### Prompt 3.3 — Integridad del modelo

```
Reglas de negocio que hoy probablemente sólo viven en PHP:
- La suma de unidades en pallets no puede superar lo pedido
- No puede haber cantidades negativas
- "Pendiente" debe ser derivado, no almacenado

1. Verificá cómo está resuelto hoy
2. Proponeme qué llevar a constraints de DB (CHECK, UNIQUE, FK)
3. Migración segura para agregarlos sobre datos existentes
   (¿hay datos que hoy violan estas reglas? verificalo ANTES)
```

---

# FASE 4 — PERFORMANCE

### Prompt 4.1

```
Leé la sección 8 de BACKEND-BIBLIA.md.

Con el baseline ya medido en /benchmarks:
1. Corré los benchmarks y mostrame los números actuales (p50, p95)
2. Identificá el peor endpoint y perfilá DÓNDE se va el tiempo
3. Aplicá UNA optimización por vez
4. Re-medí después de cada una
5. Documentá en /benchmarks/RESULTADOS.md: qué, antes, después, cómo se midió

No apliques más de una optimización sin medir en el medio.
```

---

# FASE 5 — INFRAESTRUCTURA

### Prompt 5.1 — Docker profesional

```
Leé la sección 1 de DEVOPS-BIBLIA.md.

Reescribí el Dockerfile como multi-stage, imagen final mínima, usuario no-root,
versiones pineadas, healthcheck. Actualizá .dockerignore.

Requisito: docker compose up tiene que seguir funcionando igual en local.
Mostrame el tamaño de la imagen antes y después.
```

### Prompt 5.2 — Migración de Render a VPS

```
Leé las secciones 4, 5 y 6 de DEVOPS-BIBLIA.md.

Armame el plan de migración paso a paso, incluyendo:
1. Hardening del servidor
2. Docker compose de producción
3. Nginx + Let's Encrypt
4. Deploy desde GitHub Actions por SSH
5. Migración de datos desde Render sin pérdida
6. Plan de rollback si algo sale mal

Decime el costo mensual estimado.
No ejecutes nada: quiero el plan primero.
```

### Prompt 5.3 — Observabilidad y backups

```
Leé las secciones 7 y 8 de DEVOPS-BIBLIA.md.

1. Endpoints /health y /ready
2. Sentry configurado (errores + performance)
3. UptimeRobot sobre /health
4. Script de backup diario con retención y copia fuera del servidor
5. 🔴 Procedimiento de RESTAURACIÓN documentado — y probémoslo de verdad
```

---

# FASE 6 — PRESENTACIÓN

### Prompt 6.1 — README

```
Escribí el README del proyecto orientado a que lo lea un tech lead evaluándome:

□ Qué problema real resuelve (contexto de depósito, armado de pedidos)
□ Decisiones de arquitectura y POR QUÉ se tomaron (no sólo el listado del stack)
□ Diagrama del modelo de datos (mermaid)
□ Sección Performance: tabla antes/después + comando de benchmark
□ Estrategia de backups con restauración probada
□ Cómo levantarlo local (docker compose up y listo)
□ Cómo correr los tests

Tono profesional, sin exagerar. Nada que no pueda defender en una entrevista.
```

---

## 🚨 FRASES ÚTILES PARA CUALQUIER MOMENTO

```
"Modo solo lectura: no modifiques nada, solo analizá."
"Mostrame el plan antes de escribir código."
"Un commit por cambio lógico, no un commit gigante."
"Los tests existentes tienen que seguir pasando. Si alguno falla, pará y avisame."
"¿Qué trade-offs tiene esta decisión?"
"Esto que proponés, ¿contradice alguna sección normativa o decisión vigente?"
"Estás tocando más de lo que pedí. Volvé al alcance del endpoint X."
```

## ⚠️ SEÑALES DE QUE HAY QUE FRENAR

```
- La IA modifica archivos que no tienen nada que ver con la tarea
- Un cambio toca 20 archivos a la vez
- Los tests empiezan a fallar y "los arregla" cambiando el test en vez del código
- Propone reescribir todo desde cero
- Te da números de performance sin haber corrido un benchmark
```

---

*Playbook vivo. Ajustar según lo que aparezca en la Fase 0.*
