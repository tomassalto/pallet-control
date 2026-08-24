# ▶️ EJECUCIÓN — prompts en orden, listos para copiar

> Una **sesión = una tarea**. Contexto limpio en cada sesión (chat nuevo).
> No saltear el orden: cada sesión depende de la anterior.
> Rama nueva por sesión. Al terminar, PR y merge con el CI en verde.

## Entrada obligatoria y fuente teórica

Antes de cada sesión, leer `ROUTER.md` y cargar sólo las secciones que indique.
Toda explicación, plan y patrón técnico debe salir de `BACKEND-BIBLIA.md` o
`DEVOPS-BIBLIA.md`. Si falta teoría, se detiene la sesión y se abre un `GAP-XXX`;
primero se amplía la biblia y recién después se continúa con el código.

---

# 🚨 SESIÓN 0 — Sin código (hacelo vos, ahora)

No es un prompt. Entrá al dashboard de Render y verificá:

```
□ RESET_ON_STARTUP  → ausente o false   (H-028 — si está en true, el próximo deploy borra la DB)
□ FILESYSTEM_DISK   → r2                (H-029 — si no, las fotos se pierden en el redeploy)
□ APP_DEBUG         → false             (H-025)
```

Si alguno está mal, corregilo antes de seguir. Son datos productivos reales.

---

# 🥇 SESIÓN 1 — Quick wins de seguridad (🟢 bajo esfuerzo, alto impacto)

`git checkout -b hardening/quick-wins-seguridad`

```
Leé docs/PLAN.md y docs/AUDITORIA.md.

Trabajamos SOLO en estos hallazgos de bajo esfuerzo y alto impacto.
No toques nada fuera de ellos:

- H-032: pallet-frontend/.env trackeado en git
- H-030: contenedor corre como root
- H-002: webhook de Telegram falla abierto sin secret
- H-025: APP_DEBUG sin safeguard en el deploy
- H-004: rutas de verificación de email rotas (dead code)

Referencias: BACKEND-BIBLIA §1.2, §7 · DEVOPS-BIBLIA §1.6, §2.2

Para H-032, ojo: sacarlo del índice NO alcanza — si tenía secretos reales,
hay que rotarlos. Decime qué encontraste antes de decidir.

Un commit por hallazgo, mensaje referenciando el H-XXX.
Mostrame el plan antes de escribir código.
```

**Cierre de sesión (siempre el mismo):**
```
Cerrá la sesión:
1. Marcá los H-XXX resueltos en docs/AUDITORIA.md con su hash de commit
2. Actualizá docs/PLAN.md (prioridad inmediata y estado general)
3. Si hubo decisiones con trade-offs, agregalas a docs/DECISIONES.md
```

---

# 🥈 SESIÓN 2 — CI y tooling (H-031, H-041)

**Estado actual:** ✅ implementado en `modernizacion/base`; falta validar el primer run remoto y configurar branch protection.

`git checkout -b hardening/ci`

```
Leé docs/PLAN.md y DEVOPS-BIBLIA §3.

Objetivo: gate automático antes de tocar lógica de negocio.
Resuelve H-031 (sin CI) y H-041 (Larastan no instalado).

1. Instalá Larastan/PHPStan nivel 5 con baseline para el código existente
   (queremos que el código NUEVO cumpla, sin frenarnos con los 70 archivos legacy)
2. GitHub Actions: pint --test → phpstan → php artisan test → composer audit
3. Corre en cada push y PR
4. Activá Model::preventLazyLoading() sólo en entorno local

No incluyas deploy todavía. Al final decime exactamente qué configurar
en GitHub para branch protection sobre master.
```

*(+ cierre de sesión)*

---

# 🥉 SESIÓN 3 — Red de seguridad: tests de caracterización

**Estado actual:** ✅ completo en `f114458` + `c44cafd`; 41 tests propios cubren los seis endpoints priorizados.

# 🛡️ SESIÓN 3B — Dependencias vulnerables (H-042)

Leer `ROUTER.md` y `DEVOPS-BIBLIA §3.6`. Resolver un ecosistema por vez:
Composer → frontend → WhatsApp bot → npm auxiliar del backend. Antes de aplicar cambios,
usar dry-run; no usar `--force`. Después de cada lockfile, repetir lint/static/tests/build/audit.
No iniciar R-01 hasta que todos los audits terminen con exit code 0.

**Estado actual:** 🟡 Composer, frontend y npm backend en 0. WhatsApp bot conserva
3 vulnerabilidades por Baileys 6/libsignal/protobufjs. Continuar con una sesión
aislada de migración a Baileys 7 según D-001; requiere prueba con una sesión real.

`git checkout -b hardening/tests-caracterizacion`

> ⚠️ **Esta sesión NO se saltea.** Con 2 tests de ejemplo, tocar stock es a ciegas.

```
Leé docs/PLAN.md y BACKEND-BIBLIA §6.

Necesito una red de seguridad ANTES de refactorizar stock (H-039).

Escribí feature tests que documenten el comportamiento ACTUAL —
aunque ese comportamiento esté mal— de los 6 endpoints de escritura de stock:

- PATCH /pallets/{pallet}/bases/{base}/adjust-item
- POST  /pallets/{pallet}/bases
- PATCH /pallets/{pallet}/bases/{base}
- POST  /pallets/{pallet}/bases/{base}/migrate
- POST  /orders/{order}/items
- PATCH /order-items/{item}

Reglas:
- NO corrijas ningún bug en esta sesión. Sólo capturá cómo se comporta hoy.
- Si un test expone un comportamiento incorrecto, marcalo con un comentario
  // COMPORTAMIENTO ACTUAL INCORRECTO — ver H-XXX
- Armá las factories necesarias (Order, OrderItem, Pallet, PalletBase, User con roles)
- Los tests deben correr con SQLite en memoria y ser independientes entre sí

Al final: cuántos tests quedaron y qué cubren.
```

*(+ cierre de sesión)*

---

# 🏗️ SESIÓN 4 — R-01: `adjust-item` ← LA MÁS IMPORTANTE

`git checkout -b hardening/r01-adjust-item`

> Esta rebanada **fija el patrón** que R-02 a R-06 van a reusar. Tomate el tiempo.

```
Leé docs/PLAN.md (sección R-01) y BACKEND-BIBLIA §5.6, §3.1, §6.2.

Rebanada vertical R-01: PATCH /pallets/{pallet}/bases/{base}/adjust-item
Trabajamos SOLO en este endpoint. No toques los otros.

Aplicá el protocolo de 8 puntos del CLAUDE.md. Puntos clave:
- Punto 3: hoy escribe qty sin ningún techo (H-006b). Agregar fetchAllocations()
  + lockForUpdate() sobre los order_items + DB::transaction()
- Punto 4: evaluar CHECK constraint o trigger que respalde "asignado ≤ pedido" en DB (H-006)
- Punto 5: "sin stock" hoy devuelve 422, debería ser 409 (H-023)
- Punto 6: el audit log existe pero está fuera de la transacción (H-017)
- Punto 7: test clave = 2 requests concurrentes al límite exacto

Requisitos:
- Los tests de caracterización deben seguir pasando. Si alguno cambia de resultado,
  PARÁ y explicame por qué antes de tocarlo.
- Escribí PRIMERO el test que reproduce la race condition, demostrá que falla,
  después arreglalo.
- Un commit por punto del protocolo.

ANTES de escribir código: mostrame el estado actual del endpoint y tu plan
para los 8 puntos, incluyendo qué patrón de locking proponés y sus trade-offs.
```

**Cierre especial de R-01 (importante):**
```
Cerrá R-01 y además:

Documentá en docs/DECISIONES.md el PATRÓN que establecimos acá, para que
R-02 a R-06 lo reusen sin volver a decidir. Como mínimo:
- D-00X: estrategia de locking (pesimista vs optimista) y por qué
- D-00X: dónde vive la validación de invariante (servicio / request / constraint DB)
- D-00X: código HTTP para conflictos de stock
- D-00X: posición del audit log respecto de la transacción

Después: actualizá docs/PLAN.md y marcá los H-XXX resueltos en docs/AUDITORIA.md.
```

---

# 🔁 SESIONES 5 a 9 — R-02 a R-06 (plantilla reusable)

`git checkout -b hardening/r0X-<nombre>`

```
Leé docs/PLAN.md (sección R-0X) y docs/DECISIONES.md.

Rebanada vertical R-0X: [endpoint]
Trabajamos SOLO en este endpoint.

Aplicá el MISMO patrón que R-01 — ver D-00X en docs/DECISIONES.md.
Si algo de este endpoint justifica desviarse del patrón, avisame y explicá
por qué ANTES de hacerlo. No lo decidas por tu cuenta.

Protocolo de 8 puntos del CLAUDE.md.
Los tests existentes deben seguir pasando.
Mostrame el plan antes de escribir código.
```

*(+ cierre de sesión estándar)*

---

# 🧱 SESIÓN 10 — Integridad transversal

`git checkout -b hardening/integridad`

```
Leé docs/PLAN.md y BACKEND-BIBLIA §5.1, §5.3.

Hallazgos de integridad que NO se resolvieron dentro de las rebanadas:
- H-012: FKs sin índice (Postgres)
- H-013: activity_logs.pallet_id no es FK real
- H-015: sin CHECK constraints generales
- H-008: race duplicando EAN en order_items

⚠️ ANTES de agregar cualquier constraint: verificá si hay datos existentes
que hoy la violan. Si los hay, decime cuántos y proponeme cómo limpiarlos
ANTES de aplicar la migración. No apliques una migración que pueda fallar en producción.

Migraciones separadas, cada una reversible con down() funcional.
```

*(+ cierre de sesión)*

---

# 🧩 SESIÓN 11 — Errores y respuestas

`git checkout -b hardening/errores`

```
Leé docs/PLAN.md y BACKEND-BIBLIA §3.

- H-018: sin handler global de excepciones
- H-024: formato de error no estandarizado
- H-019: $e->getMessage() filtrado al cliente en 4 controladores
- H-021 / H-022 / H-027: códigos HTTP inconsistentes

Orden: primero el handler global + formato estándar, después migrar los
controladores a ese formato.

⚠️ El frontend consume estos errores. Decime qué respuestas cambian de forma
para que yo revise el impacto en pallet-frontend antes de mergear.
```

*(+ cierre de sesión)*

---

# ⚡ SESIÓN 12 — Performance

`git checkout -b hardening/performance`

```
Leé docs/PLAN.md y BACKEND-BIBLIA §8.

1. Corré el baseline de benchmarks/ y volcá los números (p50, p95) en
   docs/AUDITORIA.md sección 4 (H-040 en el plan)
2. H-040: paginar PendingItemController::index
3. Con preventLazyLoading() activo, identificá los N+1 reales
4. UNA optimización por vez, re-midiendo entre cada una
5. Documentá en benchmarks/RESULTADOS.md: qué, antes, después, cómo se midió

No apliques más de una optimización sin medir en el medio.
```

*(+ cierre de sesión)*

---

# 🚀 SESIONES 13+ — Infraestructura y presentación

Seguir Fase 5 y 6 del `PROMPTS-PLAYBOOK.md` (Docker profesional, migración a VPS,
observabilidad, backups, README).

---

## 📌 RECORDATORIOS PERMANENTES

```
✅ Una tarea por sesión. Chat nuevo cada vez.
✅ Rama por sesión. PR con CI en verde.
✅ Siempre cerrar la sesión actualizando docs/.
✅ "Mostrame el plan antes de escribir código" — en todas.

🚩 Frená si: toca archivos fuera del alcance · arregla tests cambiando el test ·
   propone reescribir de cero · da números de performance sin haber medido.
```
