# 📕 BIBLIA BACKEND — Archivo de contexto para programar con IA

> **Cómo usar este archivo:**
> Poné este archivo en la raíz del proyecto como `CLAUDE.md` (o `.cursorrules`, o pegalo al inicio de la sesión).
> Cada vez que le pidas código a una IA, esta es la vara. Si la IA te tira algo que contradice este documento, **está mal y hay que corregirlo**.
> No es un tutorial. Es el criterio profesional que separa "funciona" de "está bien hecho".

---

## 🧭 ÍNDICE

```
1. AUTENTICACIÓN Y AUTORIZACIÓN
   ├── 1.1 Autenticación vs Autorización
   ├── 1.2 Sesiones (stateful)
   ├── 1.3 JWT (stateless)
   ├── 1.4 Refresh tokens
   ├── 1.5 Hashing de contraseñas
   └── 1.6 Autorización: roles y policies

2. VALIDACIÓN
   ├── 2.1 Por qué el front NO valida
   ├── 2.2 Capas de validación
   └── 2.3 Form Requests / DTOs

3. MANEJO DE ERRORES
   ├── 3.1 Errores esperados vs inesperados
   ├── 3.2 Respuestas de error estandarizadas
   ├── 3.3 Handler global
   └── 3.4 Logging estructurado

4. DISEÑO DE API REST
   ├── 4.1 Recursos y verbos
   ├── 4.2 Códigos HTTP
   ├── 4.3 Paginación, filtros, ordenamiento
   └── 4.4 Versionado

5. BASE DE DATOS
   ├── 5.1 Modelado y normalización
   ├── 5.2 Relaciones
   ├── 5.3 Índices
   ├── 5.4 Migraciones
   ├── 5.5 El problema N+1
   └── 5.6 Transacciones

6. TESTING
   ├── 6.1 Unitarios vs integración vs E2E
   ├── 6.2 Qué testear de verdad
   └── 6.3 Factories y seeders

7. SEGURIDAD (lo mínimo innegociable)

8. PERFORMANCE Y OPTIMIZACIÓN
   ├── 8.1 La regla de oro: medir antes
   ├── 8.2 Dónde se pierde el tiempo (jerarquía de costos)
   ├── 8.3 Cómo encontrar el cuello de botella
   ├── 8.4 Optimizaciones de base de datos
   ├── 8.5 Caching
   ├── 8.6 Trabajo asíncrono (colas)
   ├── 8.7 Herramientas de medición
   └── 8.8 Cómo se escribe esto en el CV

9. CHECKLIST PRODUCTION-READY
```

---

# 1. 🔐 AUTENTICACIÓN Y AUTORIZACIÓN

## 1.1 Autenticación vs Autorización

```
AUTENTICACIÓN (AuthN) → ¿Quién sos?        → Login, tokens, sesiones
AUTORIZACIÓN  (AuthZ) → ¿Qué podés hacer?  → Roles, permisos, policies
```

⚠️ **Error clásico de junior:** implementar login y creer que ya está seguro. Un usuario logueado puede intentar acceder al recurso de OTRO usuario. Eso es autorización, y va aparte.

---

## 1.2 Sesiones (stateful)

**Qué es:** el servidor guarda el estado de la sesión (en memoria, Redis o DB) y le manda al cliente una cookie con el ID de sesión.

**Flujo:**
```
Cliente                     Servidor
  │  POST /login              │
  │─────────────────────────► │ valida credenciales
  │                           │ crea sesión en Redis/DB
  │  ◄─────────────────────── │ Set-Cookie: session_id=abc123
  │                           │
  │  GET /perfil              │
  │  Cookie: session_id=abc123│
  │─────────────────────────► │ busca sesión → identifica usuario
```

**Cuándo usarlo profesionalmente:**
- ✅ App web tradicional donde front y back están en el mismo dominio
- ✅ Cuando necesitás **revocar accesos al instante** (borrás la sesión y listo)
- ✅ Laravel + Blade, o Laravel + React en el mismo dominio (Sanctum SPA mode)

**Configuración obligatoria de la cookie:**
```
HttpOnly  → JS no puede leerla (protege de XSS)
Secure    → sólo viaja por HTTPS
SameSite  → Lax o Strict (protege de CSRF)
```

---

## 1.3 JWT (stateless)

**Qué es:** un token firmado criptográficamente que **contiene** la información del usuario. El servidor no guarda nada — valida la firma y confía en el contenido.

**Estructura:**
```
eyJhbGciOiJIUzI1NiJ9  .  eyJzdWIiOiIxMjMiLCJleHAiOjE3...  .  SflKxwRJSMeKKF2QT4
└─────────────────────┘    └────────────────────────────┘     └─────────────────┘
       HEADER                        PAYLOAD                       SIGNATURE
   algoritmo, tipo          sub (user id), exp, iat, roles      HMAC/RSA de las
                            ⚠️ ESTO NO ESTÁ ENCRIPTADO           dos partes previas
                               es Base64 — cualquiera lo lee
```

**⚠️ CRÍTICO:** el payload es **legible por cualquiera** (Base64, no encriptación). La firma garantiza que no fue *modificado*, no que sea *secreto*.
→ **NUNCA** metas contraseñas, datos sensibles ni información privada en el payload.

**Flujo:**
```
  │  POST /login                    │
  │───────────────────────────────► │ valida → firma un JWT con el SECRET
  │  ◄───────────────────────────── │ { access_token: "eyJ..." }
  │                                 │
  │  GET /perfil                    │
  │  Authorization: Bearer eyJ...   │
  │───────────────────────────────► │ verifica la FIRMA con el SECRET
  │                                 │ si es válida y no expiró → confía en el payload
```

**Cuándo usarlo profesionalmente:**
- ✅ API consumida por múltiples clientes (web + mobile + terceros)
- ✅ Arquitectura de microservicios (cada servicio valida sin consultar una DB central)
- ✅ Front y back en dominios distintos

**El gran problema del JWT:** **no se puede revocar**. Si un token es robado, es válido hasta que expire. Por eso:

```
access_token  → vida CORTA (5-15 minutos)
refresh_token → vida LARGA (7-30 días), guardado en DB, SÍ revocable
```

---

## 1.4 Refresh tokens (el patrón profesional)

```
┌──────────────────────────────────────────────────────────┐
│  1. Login → devuelve access_token (15min) + refresh (7d)  │
│  2. El access_token se usa en cada request                │
│  3. Cuando expira → 401                                   │
│  4. El cliente llama a POST /refresh con el refresh_token │
│  5. Servidor valida contra DB → emite nuevo access_token  │
│  6. ROTACIÓN: emite también un refresh NUEVO y            │
│     invalida el anterior                                  │
└──────────────────────────────────────────────────────────┘
```

**Rotación de refresh tokens:** cada vez que se usa un refresh, se invalida y se emite uno nuevo. Si alguien reutiliza un refresh ya usado → señal de robo → se invalidan **todas** las sesiones de ese usuario.

**Dónde guardar los tokens en el cliente:**
```
❌ localStorage        → vulnerable a XSS (cualquier script lo lee)
✅ Cookie HttpOnly     → JS no lo puede leer. Lo más seguro.
🟡 Memoria (variable)  → seguro, pero se pierde al recargar
```

---

## 1.5 Hashing de contraseñas

```
❌ NUNCA: guardar la contraseña en texto plano
❌ NUNCA: MD5 o SHA1 (se rompen en segundos con GPUs)
❌ NUNCA: encriptación reversible
✅ SIEMPRE: bcrypt, Argon2 o scrypt
```

**Por qué estos y no otros:** son **lentos a propósito** y tienen un factor de costo configurable. Eso hace inviable la fuerza bruta.

```php
// Laravel — lo hace bien por defecto
$user->password = Hash::make($request->password);   // bcrypt automático
Hash::check($request->password, $user->password);   // verificación
```

```javascript
// Node
import bcrypt from 'bcrypt';
const hash = await bcrypt.hash(password, 12);  // 12 = cost factor
const ok   = await bcrypt.compare(password, hash);
```

**Salt:** bcrypt/Argon2 lo generan automáticamente y lo incluyen en el hash. No lo manejes a mano.

---

## 1.6 Autorización: roles y policies

**Nivel 1 — Roles (grosero):**
```
usuario tiene rol "admin" → puede entrar a /admin
```

**Nivel 2 — Permisos (fino):**
```
usuario tiene permiso "posts.delete" → puede borrar posts
```

**Nivel 3 — Policies / Ownership (lo profesional):**
```
¿Puede el usuario 5 editar el post 42?
→ SÓLO si post_42.user_id === 5   O   usuario tiene permiso "posts.edit.any"
```

⚠️ **La vulnerabilidad más común en apps junior — IDOR (Insecure Direct Object Reference):**

```php
// ❌ MAL — cualquiera logueado edita CUALQUIER pedido cambiando el ID en la URL
public function update(Request $request, $id) {
    $pedido = Pedido::findOrFail($id);
    $pedido->update($request->all());
}

// ✅ BIEN — verifica ownership
public function update(UpdatePedidoRequest $request, Pedido $pedido) {
    $this->authorize('update', $pedido);   // Policy decide
    $pedido->update($request->validated());
    return new PedidoResource($pedido);
}
```

---

# 2. ✅ VALIDACIÓN

## 2.1 Por qué el front NO valida

> **El cliente es territorio hostil.** Todo lo que llega del front es potencialmente malicioso.

Cualquiera puede:
- Abrir DevTools y borrar el `required` de un input
- Mandar la request directo con Postman/curl, salteando el front por completo
- Modificar el JS

**La validación del front es UX (feedback rápido). La validación del back es SEGURIDAD.** Las dos existen, pero sólo una es obligatoria.

## 2.2 Capas de validación

```
┌─ CAPA 1: Formato ──────────────────────────────────┐
│  ¿Es un email válido? ¿Es un número? ¿Largo mín/máx?│
├─ CAPA 2: Negocio ──────────────────────────────────┤
│  ¿El email ya existe? ¿Hay stock suficiente?       │
│  ¿La fecha de fin es posterior a la de inicio?     │
├─ CAPA 3: Base de datos ────────────────────────────┤
│  Constraints: NOT NULL, UNIQUE, FOREIGN KEY, CHECK │
│  Última línea de defensa. SIEMPRE presente.        │
└────────────────────────────────────────────────────┘
```

## 2.3 Form Requests / DTOs

❌ **Mal — validación mezclada con la lógica del controlador:**
```php
public function store(Request $request) {
    if (!$request->name) return response()->json(['error' => 'falta nombre'], 400);
    if (strlen($request->name) > 100) return response()->json(['error' => 'muy largo'], 400);
    // ... 30 líneas más de ifs
}
```

✅ **Bien — validación aislada, controlador limpio:**
```php
// app/Http/Requests/StorePedidoRequest.php
class StorePedidoRequest extends FormRequest
{
    public function authorize(): bool {
        return $this->user()->can('create', Pedido::class);
    }

    public function rules(): array {
        return [
            'cliente_id'        => ['required', 'exists:clientes,id'],
            'items'             => ['required', 'array', 'min:1'],
            'items.*.producto_id' => ['required', 'exists:productos,id'],
            'items.*.cantidad'  => ['required', 'integer', 'min:1', 'max:9999'],
            'fecha_entrega'     => ['required', 'date', 'after:today'],
        ];
    }
}

// El controlador queda así de limpio:
public function store(StorePedidoRequest $request) {
    $pedido = $this->pedidoService->crear($request->validated());
    return new PedidoResource($pedido);
}
```

🔑 **Regla de oro:** usá **siempre** `$request->validated()`, **nunca** `$request->all()`.
`all()` deja pasar campos que no validaste → *mass assignment vulnerability* (alguien manda `"is_admin": true` y se lo comés).

---

# 3. 💥 MANEJO DE ERRORES

## 3.1 Errores esperados vs inesperados

```
ESPERADOS (el usuario hizo algo inválido)     → 4xx → mensaje claro al usuario
  ├─ Validación fallida                        → 422
  ├─ No autenticado                            → 401
  ├─ Sin permisos                              → 403
  ├─ Recurso no encontrado                     → 404
  └─ Conflicto de negocio (sin stock)          → 409

INESPERADOS (algo se rompió del lado nuestro) → 5xx → mensaje GENÉRICO + log completo
  ├─ DB caída                                  → 503
  ├─ API externa no responde                   → 502/503
  └─ Bug en el código                          → 500
```

⚠️ **NUNCA le muestres el stack trace al usuario en producción.** Eso filtra rutas de archivos, versiones de librerías, queries SQL y estructura interna. Es un regalo para un atacante.

```
❌ "SQLSTATE[42S02]: Base table 'app.pedidos_temp' doesn't exist in /var/www/app/Models/..."
✅ "Ocurrió un error procesando tu pedido. Código de referencia: ERR-8A3F2C"
   (y el detalle completo va al log, asociado a ese código)
```

## 3.2 Respuestas de error estandarizadas

Todas las respuestas de error de tu API deben tener **la misma forma**. El front no puede estar adivinando el formato.

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Los datos enviados no son válidos",
    "details": {
      "fecha_entrega": ["La fecha debe ser posterior a hoy"],
      "items.0.cantidad": ["La cantidad debe ser al menos 1"]
    },
    "reference": "ERR-8A3F2C"
  }
}
```

## 3.3 Handler global

No manejes errores caso por caso con `try/catch` sueltos por todos lados. Definí un **handler central**.

```php
// Laravel — bootstrap/app.php o app/Exceptions/Handler.php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(function (ModelNotFoundException $e, Request $request) {
        if ($request->expectsJson()) {
            return response()->json([
                'error' => [
                    'code'    => 'NOT_FOUND',
                    'message' => 'El recurso solicitado no existe',
                ]
            ], 404);
        }
    });

    $exceptions->render(function (StockInsuficienteException $e, Request $request) {
        return response()->json([
            'error' => [
                'code'    => 'INSUFFICIENT_STOCK',
                'message' => $e->getMessage(),
                'details' => ['producto_id' => $e->productoId],
            ]
        ], 409);
    });
});
```

**Excepciones de dominio propias** — esto es lo que hace un backend profesional:
```php
class StockInsuficienteException extends Exception {
    public function __construct(public int $productoId, public int $disponible) {
        parent::__construct("Stock insuficiente. Disponible: {$disponible}");
    }
}
```
Así el error de negocio se expresa en el lenguaje del negocio, no como un `Exception` genérico.

## 3.4 Logging estructurado

```php
❌  Log::info("error en pedido");

✅  Log::error('Fallo al procesar pedido', [
        'pedido_id'  => $pedido->id,
        'user_id'    => auth()->id(),
        'reference'  => $reference,
        'exception'  => $e->getMessage(),
        'trace'      => $e->getTraceAsString(),
    ]);
```

**Niveles y cuándo usarlos:**
```
DEBUG     → detalle de desarrollo (apagado en producción)
INFO      → eventos normales de negocio ("pedido creado")
WARNING   → algo raro pero recuperable ("API lenta, reintentando")
ERROR     → falló una operación, requiere atención
CRITICAL  → el sistema está comprometido, alguien tiene que despertarse
```

**Qué NUNCA loguear:** contraseñas, tokens, tarjetas, datos personales sensibles.

---

# 4. 🌐 DISEÑO DE API REST

## 4.1 Recursos y verbos

Las URLs nombran **recursos** (sustantivos, en plural). Los **verbos** los pone HTTP.

```
❌ MAL                              ✅ BIEN
GET  /getPedidos                    GET    /pedidos
POST /crearPedido                   POST   /pedidos
POST /actualizarPedido/5            PATCH  /pedidos/5
GET  /borrarPedido/5                DELETE /pedidos/5
GET  /pedidosDelCliente/3           GET    /clientes/3/pedidos
```

**Diferencia PUT vs PATCH:**
```
PUT   → reemplaza el recurso COMPLETO (mandás todos los campos)
PATCH → actualiza SÓLO los campos enviados (lo más usado en la práctica)
```

## 4.2 Códigos HTTP (los que realmente vas a usar)

```
2xx ÉXITO
├─ 200 OK              → GET, PATCH exitoso
├─ 201 Created         → POST que creó algo (+ header Location)
├─ 204 No Content      → DELETE exitoso (sin body)

4xx CULPA DEL CLIENTE
├─ 400 Bad Request     → JSON malformado, request incoherente
├─ 401 Unauthorized    → NO estás autenticado (mal nombrado, debería ser "Unauthenticated")
├─ 403 Forbidden       → Estás autenticado pero NO tenés permiso
├─ 404 Not Found       → No existe
├─ 409 Conflict        → Conflicto de estado (sin stock, email duplicado)
├─ 422 Unprocessable   → Validación fallida (el estándar en Laravel)
└─ 429 Too Many Req.   → Rate limit excedido

5xx CULPA NUESTRA
├─ 500 Internal Error  → Bug
├─ 502 Bad Gateway     → Un servicio del que dependemos falló
└─ 503 Unavailable     → Estamos caídos / en mantenimiento
```

🔑 **401 vs 403 es la confusión más común:**
`401` = "no sé quién sos, logueate". `403` = "sé quién sos, y no podés".

## 4.3 Paginación, filtros, ordenamiento

**NUNCA devuelvas una lista completa sin paginar.** Con 100 registros anda; con 500.000 tirás el servidor.

```
GET /pedidos?page=2&per_page=25&estado=pendiente&sort=-fecha_creacion
                                 └── filtro ──┘  └── "-" = descendente
```

```json
{
  "data": [ ... ],
  "meta": {
    "current_page": 2,
    "per_page": 25,
    "total": 1543,
    "last_page": 62
  }
}
```

⚠️ **Poné siempre un `per_page` máximo** (ej. 100). Si no, alguien manda `?per_page=999999` y te voltea la app.

## 4.4 Versionado

```
/api/v1/pedidos
/api/v2/pedidos
```
Una vez que un cliente externo (o tu app mobile) consume tu API, **no podés romperla**. El versionado te deja evolucionar sin romper a quien ya la usa.

---

# 5. 🗄️ BASE DE DATOS

## 5.1 Modelado y normalización

**Las 3 formas normales, en criollo:**
```
1FN → Cada celda tiene UN valor. Nada de "tel: 299123, 299456" en un campo.
2FN → Todo campo depende de la clave PRIMARIA COMPLETA (importa en claves compuestas).
3FN → Ningún campo depende de OTRO campo no-clave.
      ❌ tabla pedidos con: cliente_id, cliente_nombre, cliente_email
         (nombre y email dependen de cliente_id, no del pedido → van en la tabla clientes)
```

**Cuándo desnormalizar a propósito:** cuando una consulta crítica es demasiado lenta y medís que el JOIN es el cuello de botella. **Primero normalizá, después optimizá con datos.** Desnormalizar "por las dudas" es deuda técnica.

## 5.2 Relaciones

```
1:1   Usuario ──── Perfil
      FK en cualquiera de las dos (usualmente en la "débil")

1:N   Cliente ──< Pedidos
      FK del lado "muchos" → pedidos.cliente_id

N:M   Productos >──< Pedidos
      Requiere TABLA PIVOT: pedido_producto
      ⚠️ Si la relación tiene datos propios (cantidad, precio_unitario),
         la pivot deja de ser pivot y pasa a ser una ENTIDAD REAL
         (PedidoItem) con su propio modelo.
```

**Foreign keys y `ON DELETE`:**
```sql
ON DELETE CASCADE     → borra los hijos (pedido borrado → borra sus items)
ON DELETE RESTRICT    → impide borrar el padre si tiene hijos (default seguro)
ON DELETE SET NULL    → deja los hijos huérfanos con NULL
```

**Soft deletes:** en vez de borrar, marcás `deleted_at`. Profesional para datos de negocio (facturas, pedidos) porque preserva historial y auditoría. ⚠️ Ojo: rompe los `UNIQUE` (un email "borrado" sigue ocupando el índice) — se resuelve con índices parciales o compuestos.

## 5.3 Índices

**Qué es:** una estructura (B-tree) que permite encontrar filas sin recorrer la tabla entera.

```
SIN índice → Full Table Scan → recorre 1.000.000 de filas
CON índice → Index Seek      → ~20 saltos
```

**Dónde poner índices:**
```
✅ Foreign keys (SIEMPRE — Laravel no las indexa solo en todos los casos)
✅ Columnas usadas en WHERE frecuentes
✅ Columnas usadas en ORDER BY frecuentes
✅ Columnas de UNIQUE (se indexan automáticamente)
```

**El costo (por qué no indexar todo):**
```
Índice = lecturas MÁS RÁPIDAS  +  escrituras MÁS LENTAS  +  más espacio en disco
```
Cada INSERT/UPDATE tiene que actualizar todos los índices de la tabla.

**Índices compuestos y la regla del prefijo izquierdo:**
```sql
INDEX (estado, fecha_creacion)

✅ WHERE estado = 'x'                          → usa el índice
✅ WHERE estado = 'x' AND fecha_creacion > ...  → usa el índice completo
❌ WHERE fecha_creacion > ...                   → NO lo usa (no arranca por la izquierda)
```

**Cómo verificar que un índice se usa:**
```sql
EXPLAIN SELECT * FROM pedidos WHERE estado = 'pendiente';
-- Buscá "type: ref/range" (bien) vs "type: ALL" (full scan → mal)
```

## 5.4 Migraciones

**Reglas profesionales:**
```
✅ Toda la estructura de la DB vive en migraciones versionadas en git
✅ Cada migración es ATÓMICA (un cambio conceptual)
✅ Siempre escribí el método down() funcional
❌ NUNCA edites una migración que ya corrió en producción → creá una NUEVA
❌ NUNCA toques la estructura a mano por phpMyAdmin/DBeaver
```

**Migraciones destructivas en producción — el patrón seguro (expand/contract):**
```
Para renombrar una columna sin downtime:
1. EXPAND    → agregar la columna nueva
2. MIGRAR    → copiar datos, la app escribe en las dos
3. CAMBIAR   → la app lee de la nueva
4. CONTRACT  → borrar la vieja (en un deploy posterior)
```

## 5.5 El problema N+1

**El bug de performance más común de todos.**

```php
// ❌ 1 query para pedidos + 1 query POR CADA pedido = 101 queries con 100 pedidos
$pedidos = Pedido::all();
foreach ($pedidos as $pedido) {
    echo $pedido->cliente->nombre;   // ← query oculta acá
}

// ✅ 2 queries totales — eager loading
$pedidos = Pedido::with('cliente')->get();

// ✅ Anidado y selectivo
$pedidos = Pedido::with(['cliente:id,nombre', 'items.producto:id,nombre'])->get();
```

🔧 **Cómo detectarlo:** Laravel Debugbar, Telescope, o `DB::listen()` contando queries. En Laravel moderno: `Model::preventLazyLoading()` en desarrollo → tira excepción si hay un N+1.

## 5.6 Transacciones

**Cuándo:** cuando varias escrituras tienen que pasar **todas o ninguna**.

```php
// ❌ MAL — si falla el descuento de stock, el pedido ya quedó creado. Datos corruptos.
$pedido = Pedido::create($data);
$producto->decrement('stock', $cantidad);
Pago::create($pagoData);

// ✅ BIEN — atómico
DB::transaction(function () use ($data, $cantidad) {
    $pedido = Pedido::create($data);

    $producto = Producto::lockForUpdate()->find($data['producto_id']);  // ← lock
    if ($producto->stock < $cantidad) {
        throw new StockInsuficienteException($producto->id, $producto->stock);
    }
    $producto->decrement('stock', $cantidad);

    Pago::create([...]);
});   // commit automático; rollback automático si hay excepción
```

**`lockForUpdate()`** evita la *race condition*: dos usuarios comprando la última unidad al mismo tiempo. Sin el lock, los dos leen `stock = 1` y los dos compran.

**ACID en criollo:**
```
Atomicity   → todo o nada
Consistency → la DB queda siempre en estado válido
Isolation   → las transacciones concurrentes no se pisan
Durability  → una vez confirmado, sobrevive a un corte de luz
```

---

# 6. 🧪 TESTING

## 6.1 Tipos

```
        ╱╲          E2E (pocos, lentos, frágiles)
       ╱  ╲         → Playwright/Cypress: el flujo completo en un browser
      ╱────╲
     ╱      ╲       INTEGRACIÓN (los más valiosos en backend)
    ╱        ╲      → Llamás al endpoint real, con DB real de test
   ╱──────────╲
  ╱            ╲    UNITARIOS (muchos, rápidos)
 ╱______________╲   → Una función/clase aislada, sin DB ni red
```

🔑 **En backend, los tests de integración (Feature Tests en Laravel) son los que más valor dan por esfuerzo.** Testean el request completo: ruta → middleware → validación → controlador → DB → respuesta.

## 6.2 Qué testear de verdad

```
✅ El camino feliz (funciona lo que tiene que funcionar)
✅ CASOS LÍMITE ← acá está la diferencia entre junior y profesional
   ├─ Campos vacíos, nulos, strings gigantes
   ├─ Números negativos, cero, decimales donde se espera entero
   ├─ Cantidad 0 o mayor al stock disponible
   ├─ IDs que no existen
   └─ Caracteres raros / inyección
✅ AUTORIZACIÓN ← el test que casi nadie escribe y es el más importante
   └─ ¿El usuario A puede acceder al recurso del usuario B? DEBE dar 403
✅ Errores esperados (la API responde el código correcto)

❌ NO testees: getters/setters triviales, código de framework, mocks de todo
```

**Ejemplo del test que separa niveles:**
```php
public function test_usuario_no_puede_editar_pedido_ajeno(): void
{
    $usuarioA = User::factory()->create();
    $usuarioB = User::factory()->create();
    $pedido   = Pedido::factory()->for($usuarioB)->create();

    $response = $this->actingAs($usuarioA)
                     ->patchJson("/api/v1/pedidos/{$pedido->id}", ['estado' => 'cancelado']);

    $response->assertForbidden();                                    // 403
    $this->assertDatabaseHas('pedidos', ['id' => $pedido->id, 'estado' => 'pendiente']);
}
```

## 6.3 Factories y seeders

```
FACTORY → genera datos falsos para TESTS (efímeros, aleatorios)
SEEDER  → carga datos iniciales reales (roles, categorías, config)
```

⚠️ **Los tests deben ser independientes entre sí y correr en cualquier orden.** Usá `RefreshDatabase` y **nunca** dependas del estado que dejó otro test.

---

# 7. 🛡️ SEGURIDAD — mínimo innegociable

```
┌─────────────────────────────────────────────────────────────────┐
│ SQL INJECTION                                                    │
│ ✅ SIEMPRE queries parametrizadas / ORM                          │
│ ❌ NUNCA concatenar input del usuario en un string SQL           │
│    DB::select("... WHERE id = " . $request->id)  ← catástrofe    │
├─────────────────────────────────────────────────────────────────┤
│ XSS (Cross-Site Scripting)                                       │
│ ✅ Escapar toda salida ({{ }} en Blade, React escapa por defecto)│
│ ❌ NUNCA dangerouslySetInnerHTML ni {!! !!} con input de usuario │
├─────────────────────────────────────────────────────────────────┤
│ CSRF                                                             │
│ ✅ Token CSRF en formularios con sesión + SameSite en cookies    │
│    (APIs con Bearer token no lo necesitan)                       │
├─────────────────────────────────────────────────────────────────┤
│ MASS ASSIGNMENT                                                  │
│ ✅ $fillable explícito + siempre validated(), nunca all()        │
├─────────────────────────────────────────────────────────────────┤
│ SECRETOS                                                         │
│ ✅ Todo en variables de entorno (.env), .env en .gitignore       │
│ ❌ NUNCA credenciales hardcodeadas ni commiteadas                │
├─────────────────────────────────────────────────────────────────┤
│ RATE LIMITING                                                    │
│ ✅ Obligatorio en /login, /register, /password-reset             │
│    Sin esto, fuerza bruta libre.                                 │
├─────────────────────────────────────────────────────────────────┤
│ CORS                                                             │
│ ✅ Whitelist de orígenes específicos                             │
│ ❌ NUNCA Access-Control-Allow-Origin: * en una API autenticada   │
├─────────────────────────────────────────────────────────────────┤
│ HTTPS                                                            │
│ ✅ Obligatorio en producción. HSTS activado.                     │
└─────────────────────────────────────────────────────────────────┘
```

---

# 8. ⚡ PERFORMANCE Y OPTIMIZACIÓN

## 8.1 La regla de oro: MEDIR ANTES

> **No podés poner "reduje de 3s a 0.5s" en el CV si nunca mediste los 3s.**

Este es el error conceptual más grande. La optimización **no empieza escribiendo código más rápido** — empieza con un **baseline medido**.

```
┌──────────────────────────────────────────────────────────────┐
│  EL CICLO PROFESIONAL DE OPTIMIZACIÓN                        │
│                                                              │
│  1. MEDIR      → baseline con números reales (guardalos)     │
│  2. PERFILAR   → ¿DÓNDE se va el tiempo exactamente?         │
│  3. HIPÓTESIS  → "creo que es el N+1 en la relación cliente" │
│  4. ARREGLAR   → UN cambio por vez                           │
│  5. RE-MEDIR   → ¿mejoró? ¿cuánto? ¿rompí algo?              │
│  6. DOCUMENTAR → antes/después en el README o el commit      │
│  └─ repetir mientras el costo/beneficio valga la pena        │
└──────────────────────────────────────────────────────────────┘
```

⚠️ **Optimización prematura:** no optimices lo que no medís. El 80% del tiempo suele estar en el 20% del código — y casi nunca es donde creías. Adivinar y "optimizar" a ciegas es perder tiempo y ensuciar el código.

**Qué métricas importan (y cómo se nombran):**
```
p50 (mediana)  → la experiencia típica
p95            → los usuarios que la pasan mal ← EL NÚMERO QUE IMPORTA
p99            → los peores casos
promedio       → 🚫 mentiroso: un pico de 30s se diluye y no lo ves
```
🔑 En entrevistas, hablar de **p95** en vez de "promedio" ya te marca como alguien que midió en serio.

---

## 8.2 Dónde se pierde el tiempo (jerarquía de costos)

Órdenes de magnitud aproximados. Memorizá la escala, no los números exactos:

```
Operación en memoria (CPU)          ~0.0001 ms   ▏
Lectura de caché en memoria (Redis)     ~1 ms    ▎
Query simple con índice                ~1-5 ms   ▍
Query sin índice (full scan, 1M filas) ~500 ms   ████████
Llamada a API externa                ~100-2000ms ████████████████
Lectura de disco / archivo grande     ~10-100 ms ██
```

**Conclusión práctica:** el tiempo casi nunca está en tu código PHP/JS. Está en **la base de datos**, en **llamadas de red** y en **cosas que hacés muchas veces sin darte cuenta** (N+1).

---

## 8.3 Cómo encontrar el cuello de botella

**Orden de investigación (de mayor a menor retorno):**

```
1️⃣  ¿CUÁNTAS QUERIES corre este endpoint?
    → Si son más de ~10 para una operación simple, tenés un N+1. Empezá acá.
    → Es el problema #1 y el de arreglo más barato.

2️⃣  ¿Alguna query tarda mucho SOLA?
    → EXPLAIN. Si ves "type: ALL" → falta un índice.

3️⃣  ¿Estás trayendo datos que no usás?
    → SELECT * con 40 columnas cuando necesitás 3
    → Listar 10.000 registros para mostrar 20

4️⃣  ¿Hay llamadas a APIs externas en el request?
    → ¿Se pueden cachear? ¿Se pueden mandar a una cola?

5️⃣  ¿Estás procesando en memoria lo que la DB haría mejor?
    → Traer todo y filtrar con foreach ❌
    → Filtrar con WHERE en la query ✅

6️⃣  Recién acá: optimizar algoritmos de tu código.
```

---

## 8.4 Optimizaciones de base de datos

**A) Eliminar N+1 (el mayor retorno de todos)**
```php
// ANTES: 1 + 100 + 100 = 201 queries → 3.2s
$pedidos = Pedido::all();
foreach ($pedidos as $p) { $p->cliente->nombre; $p->items->count(); }

// DESPUÉS: 3 queries → 180ms
$pedidos = Pedido::with('cliente:id,nombre')->withCount('items')->paginate(25);
```

**B) Traer sólo lo necesario**
```php
❌  User::all();                                   // 40 columnas × 50.000 filas
✅  User::select('id','nombre','email')->paginate(25);

❌  $productos->filter(fn($p) => $p->stock > 0);   // filtra en PHP, ya trajo todo
✅  Producto::where('stock','>',0)->get();         // filtra en la DB
```

**C) Agregar en la DB, no en el código**
```php
❌  $total = Pedido::all()->sum('monto');          // trae 100.000 filas a memoria
✅  $total = Pedido::sum('monto');                 // la DB devuelve un número
```

**D) Índices** (ver sección 5.3) — el arreglo más dramático cuando falta uno:
```
Query sin índice sobre 1M filas:  ~1200 ms
La misma con índice:              ~3 ms
```

**E) `chunk` / `cursor` para procesar volumen**
```php
❌  foreach (Pedido::all() as $p) { ... }        // 500.000 modelos en RAM → muere
✅  Pedido::chunkById(1000, function ($lote) { ... });
✅  foreach (Pedido::lazy() as $p) { ... }        // cursor, memoria constante
```

---

## 8.5 Caching

**Qué cachear:** datos **caros de calcular** y que **cambian poco**.
```
✅ Reportes agregados, dashboards, listados de categorías, respuestas de APIs externas
❌ Datos que cambian cada segundo, datos por usuario que no se reutilizan
```

**Capas de caché (de más cerca del usuario a más lejos):**
```
CDN / Browser  →  cachea assets estáticos (imágenes, JS, CSS)
HTTP cache     →  ETag / Cache-Control en respuestas de API
App cache      →  Redis / Memcached ← acá vive el grueso del backend
Query cache    →  resultados de consultas pesadas
```

```php
// Patrón cache-aside (el más usado)
$stats = Cache::remember("dashboard:stats:{$userId}", 300, function () use ($userId) {
    return $this->calcularEstadisticasPesadas($userId);   // sólo corre si no está en caché
});
```

**El problema difícil: invalidación.**
```
Estrategias:
├─ TTL (tiempo de vida)     → simple, acepta datos algo viejos
├─ Invalidación por evento  → al guardar un Pedido, borrás cache "pedidos:*"
└─ Cache tags               → Cache::tags(['pedidos'])->flush()
```
⚠️ Un caché mal invalidado muestra datos viejos y es **peor** que no tener caché. Si no sabés cuándo invalidar, usá TTL corto.

---

## 8.6 Trabajo asíncrono (colas)

> **Nada que el usuario no necesite ver ahora debería bloquear su request.**

```
❌ SÍNCRONO — el usuario espera 4.5s
POST /pedidos → crear pedido (100ms)
              → generar PDF de factura (2s)
              → enviar email (1.5s)
              → notificar a la API de logística (900ms)
              → responder ✅

✅ ASÍNCRONO — el usuario espera 120ms
POST /pedidos → crear pedido (100ms)
              → despachar 3 jobs a la cola (20ms)
              → responder ✅
              ... los workers procesan en background
```

```php
GenerarFacturaJob::dispatch($pedido);
EnviarEmailConfirmacionJob::dispatch($pedido)->delay(now()->addSeconds(5));
```

**Qué mandar a cola:** emails, PDFs, procesamiento de imágenes, llamadas a APIs externas, exports grandes, webhooks.

**Lo profesional:** reintentos (`$tries`), backoff exponencial, y una cola de fallidos (`failed_jobs`) que se monitorea.

---

## 8.7 Herramientas de medición

```
┌─ DESARROLLO ────────────────────────────────────────────────┐
│ Laravel Debugbar    → queries, tiempo, memoria, N+1 visible │
│ Laravel Telescope   → requests, jobs, queries lentas, logs  │
│ EXPLAIN / ANALYZE   → plan de ejecución de una query        │
│ Clockwork           → alternativa a Debugbar                │
│ Model::preventLazyLoading() → tira excepción ante N+1       │
├─ CARGA / BENCHMARK ─────────────────────────────────────────┤
│ k6, Apache Bench (ab), wrk, Artillery                       │
│ → simulan N usuarios concurrentes y te dan p50/p95/p99      │
│ ⚠️ Medir con 1 request no sirve. Medí bajo carga.           │
├─ PRODUCCIÓN (APM) ──────────────────────────────────────────┤
│ Sentry (errores + performance), New Relic, Datadog          │
│ → te dicen QUÉ endpoint es lento con usuarios reales        │
├─ FRONTEND ──────────────────────────────────────────────────┤
│ Lighthouse / PageSpeed → LCP, CLS, INP (Core Web Vitals)    │
│ Chrome DevTools → Network waterfall, Performance profiler   │
└─────────────────────────────────────────────────────────────┘
```

**Benchmark mínimo reproducible para tu proyecto:**
```bash
# 200 requests, 10 concurrentes
ab -n 200 -c 10 -H "Authorization: Bearer $TOKEN" https://tuapp.com/api/v1/pedidos

# o con k6 (más moderno, te da p95 directo)
k6 run --vus 20 --duration 30s script.js
```
🔑 **Guardá la salida de antes y después en el repo** (carpeta `/benchmarks`). Eso es la evidencia que respalda el número del CV.

---

## 8.8 Cómo se escribe esto en el CV

**La fórmula:** `[Acción técnica específica] → [métrica antes] a [métrica después] → [impacto]`

```
❌ "Optimicé el rendimiento del backend"
❌ "Mejoré la velocidad de la aplicación"
   (no dice qué hiciste, no es verificable, no lo puede repreguntar nadie)

✅ "Reduje el tiempo de respuesta del endpoint de listado de pedidos de 3.2s a
    480ms (p95) eliminando consultas N+1 con eager loading e indexando las
    foreign keys, medido con k6 sobre 20 usuarios concurrentes."

✅ "Bajé el tiempo de carga del dashboard de 6s a 900ms moviendo la generación
    de reportes a jobs asíncronos y cacheando agregados en Redis con TTL de 5min."

✅ "Reduje el consumo de memoria del export de 1.2GB a 80MB reemplazando
    Model::all() por chunkById(), permitiendo exportar 500k registros sin OOM."
```

⚠️ **Sólo poné números que puedas defender.** En la entrevista te van a preguntar *cómo lo mediste* y *por qué era lento*. Si inventaste el número, se nota en 30 segundos. Por eso el paso 1 (medir y guardar el baseline) no es opcional.

**Dónde documentarlo en el proyecto:** una sección `## Performance` en el README con la tabla antes/después, el comando de benchmark usado, y el link al commit que lo arregló. Eso es lo que un tech lead abre y dice "este tipo sabe".

---

# 9. ✅ CHECKLIST PRODUCTION-READY

Antes de decir "este proyecto está terminado", todo esto tiene que estar en verde:

```
AUTENTICACIÓN Y PERMISOS
□ Contraseñas con bcrypt/Argon2
□ Tokens con expiración corta + refresh rotativo
□ Policies verificando ownership en TODOS los endpoints de recursos
□ Rate limiting en endpoints de auth

DATOS
□ Toda entrada validada del lado del servidor
□ validated() en vez de all() en todos los controladores
□ Constraints en DB (NOT NULL, UNIQUE, FK)
□ Índices en foreign keys y columnas de búsqueda frecuente
□ Sin N+1 (verificado con Debugbar/Telescope)
□ Transacciones en toda operación multi-escritura
□ Migraciones versionadas con down() funcional

ERRORES
□ Handler global con respuestas estandarizadas
□ Excepciones de dominio propias
□ Sin stack traces expuestos en producción (APP_DEBUG=false)
□ Logging estructurado con contexto
□ Códigos HTTP correctos (401 vs 403, 422 para validación)

API
□ Rutas RESTful con sustantivos en plural
□ Paginación obligatoria en listados + tope de per_page
□ Versionado (/api/v1)
□ Resources/DTOs para la respuesta (no devolver modelos crudos)

TESTING
□ Feature tests del camino feliz
□ Tests de casos límite
□ Tests de autorización (usuario A vs recurso de usuario B)
□ Suite corriendo en CI

PERFORMANCE
□ Baseline medido y guardado ANTES de optimizar (/benchmarks en el repo)
□ Sin N+1 en ningún endpoint (verificado con Debugbar/Telescope)
□ EXPLAIN corrido sobre las queries de los endpoints principales
□ Trabajo pesado (emails, PDFs, APIs externas) movido a colas
□ Caché en lo caro y estable, con estrategia de invalidación definida
□ Sección "Performance" en el README con tabla antes/después

INFRA
□ Secretos en .env, nunca en git
□ HTTPS + HSTS
□ CORS con whitelist
□ README explicando decisiones de arquitectura, no sólo "npm install"
```

---

## 🎯 REGLAS PARA LA IA (pegá esto al inicio de cada sesión)

```
1. No me des código de tutorial. Todo tiene que ser production-ready.
2. Toda entrada se valida en el servidor con Form Request / DTO.
3. Todo endpoint de recurso verifica ownership, no sólo autenticación.
4. Nunca uses $request->all(). Siempre validated().
5. Toda operación multi-escritura va en transacción.
6. Si generás una consulta con relaciones, usá eager loading.
7. Los errores se manejan con excepciones de dominio + handler global.
8. Escribí el test junto con el código, incluyendo el caso de autorización.
9. Si una decisión tiene trade-offs, explicámelos antes de elegir por mí.
10. Si algo de este documento no aplica a este caso, decímelo y por qué.
```

---

*Documento vivo — Mes 1 (Backend). Pendiente: Mes 2 (DevOps/Infra), Mes 3 (Node/TS + fundamentos), Mes 4 (Portfolio y búsqueda).*
