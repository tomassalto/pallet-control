# 🚀 BIBLIA DEVOPS E INFRAESTRUCTURA — Mes 2

> **Cómo usar este archivo:** igual que `BACKEND-BIBLIA.md`. Es el criterio profesional de infraestructura.
> Si la IA te tira un `Dockerfile` de tutorial con `FROM ubuntu` y `apt-get install todo`, está mal — acá está por qué.

> **Cuándo arranca esto:** el bloque 1 (Docker) y el bloque 3 (CI) van **en paralelo al Mes 1**, desde la semana 1.
> El resto (VPS, nginx, HTTPS, deploy, monitoreo) es el foco del Mes 2.

---

## 🧭 ÍNDICE

```
1. DOCKER — de "me anda" a imagen profesional
   ├── 1.1 Por qué Docker (el problema real que resuelve)
   ├── 1.2 Imagen vs contenedor vs volumen
   ├── 1.3 Dockerfile profesional (multi-stage)
   ├── 1.4 Capas y caché
   ├── 1.5 docker-compose para desarrollo
   └── 1.6 Errores que gritan "junior"

2. VARIABLES DE ENTORNO Y SECRETOS
   ├── 2.1 Config vs código
   ├── 2.2 Jerarquía de secretos
   └── 2.3 Qué hacer si filtraste un secreto

3. CI/CD
   ├── 3.1 CI vs CD vs Continuous Deployment
   ├── 3.2 Pipeline mínimo profesional
   ├── 3.3 GitHub Actions completo
   ├── 3.4 Branch protection
   └── 3.5 Estrategias de deploy

4. EL SERVIDOR (VPS)
   ├── 4.1 Elegir y crear el VPS
   ├── 4.2 Hardening inicial (obligatorio)
   ├── 4.3 Firewall
   └── 4.4 Usuarios y permisos

5. NGINX Y HTTPS
   ├── 5.1 Reverse proxy: qué es y por qué
   ├── 5.2 Configuración base
   ├── 5.3 HTTPS con Let's Encrypt
   └── 5.4 Headers de seguridad

6. DEPLOY SIN DOWNTIME
   ├── 6.1 El problema
   ├── 6.2 Health checks
   ├── 6.3 Migraciones seguras
   └── 6.4 Rollback

7. OBSERVABILIDAD
   ├── 7.1 Logs, métricas y trazas
   ├── 7.2 Errores en producción (Sentry)
   ├── 7.3 Uptime monitoring
   └── 7.4 Alertas que no ignorás

8. BACKUPS Y RECUPERACIÓN
   └── La regla 3-2-1 y el backup que nunca probaste

9. DOMINIO Y DNS

10. CHECKLIST DE INFRAESTRUCTURA
```

---

# 1. 🐳 DOCKER

## 1.1 Por qué Docker (el problema real)

```
❌ SIN DOCKER: "en mi máquina anda"
   Tu PC: PHP 8.3, MySQL 8, extensión gd instalada
   Servidor: PHP 8.1, MariaDB, sin gd
   → explota en producción y perdés 3 horas

✅ CON DOCKER: la app viaja con su entorno completo
   La misma imagen corre idéntica en tu PC, en CI y en producción
```

**Lo que realmente vendés en una entrevista:** no es "sé usar Docker" (todos lo dicen), es **"mi entorno de desarrollo, mi CI y mi producción corren la misma imagen"**. Eso es reproducibilidad, y es lo que buscan.

## 1.2 Conceptos base

```
IMAGEN        → plantilla inmutable (la receta compilada)
CONTENEDOR    → instancia en ejecución de una imagen (efímero)
VOLUMEN       → almacenamiento persistente (sobrevive al contenedor)
RED           → cómo se hablan los contenedores entre sí
```

🔑 **Los contenedores son ganado, no mascotas.** Se matan y se recrean sin drama. **Todo lo que tenga que sobrevivir va en un volumen o en un servicio externo.** Si guardás archivos subidos dentro del contenedor, en el próximo deploy desaparecen.

## 1.3 Dockerfile profesional (multi-stage)

```dockerfile
# ─── STAGE 1: dependencias PHP ────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader

# ─── STAGE 2: build del front ─────────────────────────────────
FROM node:22-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci                        # ci, no install: respeta el lock exacto
COPY resources/ resources/
COPY vite.config.js ./
RUN npm run build

# ─── STAGE 3: imagen final (sólo lo necesario para correr) ────
FROM php:8.3-fpm-alpine AS production

RUN apk add --no-cache postgresql-dev \
    && docker-php-ext-install pdo_pgsql opcache bcmath \
    && rm -rf /var/cache/apk/*

# Usuario NO root
RUN addgroup -g 1000 app && adduser -u 1000 -G app -s /bin/sh -D app

WORKDIR /var/www

COPY --from=vendor   /app/vendor      ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY --chown=app:app . .

RUN php artisan config:cache && php artisan route:cache && php artisan view:cache

USER app                          # ← nunca corras como root

EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s \
  CMD php -r "exit(0);" || exit 1

CMD ["php-fpm"]
```

**Por qué multi-stage importa:** la imagen final **no contiene** Composer, ni Node, ni npm, ni el código fuente del front. Pasás de ~1.2 GB a ~180 MB. Menos peso = deploys más rápidos + menos superficie de ataque.

## 1.4 Capas y caché — el orden importa

Docker cachea cada instrucción. Si una capa cambia, **todas las siguientes se reconstruyen**.

```dockerfile
❌ MAL — cualquier cambio en el código reinstala todas las dependencias (3 min)
COPY . .
RUN composer install

✅ BIEN — las dependencias sólo se reinstalan si cambió composer.lock (5 seg)
COPY composer.json composer.lock ./
RUN composer install
COPY . .
```

🔑 **Regla:** de lo que menos cambia (dependencias) a lo que más cambia (tu código).

**`.dockerignore` obligatorio:**
```
.git
node_modules
vendor
.env
storage/logs/*
tests/
*.md
```
Sin esto, mandás cientos de MB innecesarios al build context — y peor, podés **filtrar el `.env` adentro de la imagen**.

## 1.5 docker-compose para desarrollo

```yaml
services:
  app:
    build:
      context: .
      target: development          # stage distinto al de producción
    volumes:
      - .:/var/www                 # hot reload: tu código en vivo
      - /var/www/vendor            # excluye vendor del bind mount
    environment:
      - APP_ENV=local
    depends_on:
      db:
        condition: service_healthy  # espera a que la DB esté LISTA, no sólo levantada

  db:
    image: postgres:16-alpine
    volumes:
      - pgdata:/var/lib/postgresql/data
    environment:
      POSTGRES_PASSWORD: ${DB_PASSWORD}
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U postgres"]
      interval: 5s
      retries: 5

  redis:
    image: redis:7-alpine

volumes:
  pgdata:
```

⚠️ **`depends_on` sin `condition: service_healthy` NO espera a que el servicio esté listo** — sólo a que arranque. Es la causa #1 de "la app no conecta a la DB al levantar".

## 1.6 Errores que gritan "junior"

```
❌ FROM ubuntu:latest              → usá imágenes oficiales y slim/alpine
❌ :latest en cualquier imagen     → no es reproducible; pineá versiones
❌ Correr como root                → riesgo de seguridad real
❌ Secretos en el Dockerfile (ENV) → quedan en las capas de la imagen para siempre
❌ Un solo stage con todo adentro  → imagen gigante
❌ Sin .dockerignore               → build lento y filtración de secretos
❌ Datos persistentes sin volumen  → se pierden en el próximo deploy
❌ apt-get sin limpiar la caché    → capas infladas al pedo
```

---

# 2. 🔑 VARIABLES DE ENTORNO Y SECRETOS

## 2.1 Config vs código

> **La misma imagen debe correr en dev, staging y producción cambiando SÓLO variables de entorno.**

Si tenés que reconstruir la imagen para cambiar de ambiente, está mal.

```
✅ VAN EN ENV:  credenciales de DB, API keys, URLs de servicios, flags de ambiente
❌ NO VAN:      lógica de negocio, reglas que definen cómo funciona la app
```

## 2.2 Jerarquía de secretos

```
NIVEL 0 ❌  Hardcodeado en el código                → catástrofe
NIVEL 1 🟡  .env local (en .gitignore)              → OK para desarrollo
NIVEL 2 ✅  Secrets del CI (GitHub Actions Secrets) → OK para pipelines
NIVEL 3 ✅  Gestor de secretos (Vault, AWS Secrets  → estándar empresarial
            Manager, Doppler)
```

**Obligatorio en el repo:** un `.env.example` con todas las claves y **valores vacíos o falsos**. Es la documentación de qué necesita la app para correr.

## 2.3 Si filtraste un secreto en git

⚠️ **Borrar el archivo en un commit nuevo NO alcanza — sigue en el historial.**

```
1. ROTAR el secreto INMEDIATAMENTE (cambiá la key, no la escondas)
2. Recién después limpiar el historial (git-filter-repo / BFG)
3. Asumir que fue comprometido: los bots escanean GitHub en segundos
```

---

# 3. ⚙️ CI/CD

## 3.1 Los tres conceptos

```
CI  (Continuous Integration)  → en cada push: build + tests + linters
CD  (Continuous Delivery)     → queda LISTO para deployar, con aprobación manual
CD  (Continuous Deployment)   → deploya AUTOMÁTICO si pasa todo
```

**Para tu portfolio:** CI automático + deploy automático a producción desde `main`. Eso demuestra el ciclo completo.

## 3.2 Pipeline mínimo profesional

```
push / pull request
      │
      ├─ 1. LINT        → estilo de código (Pint, ESLint)
      ├─ 2. STATIC      → análisis estático (PHPStan/Larastan, tsc)
      ├─ 3. TESTS       → unit + feature, con DB real de test
      ├─ 4. SECURITY    → composer audit / npm audit
      ├─ 5. BUILD       → imagen Docker, tag con el SHA del commit
      └─ 6. DEPLOY      → sólo si la rama es main y todo lo anterior pasó
```

🔑 **Si el pipeline falla, no se mergea. Punto.** Un CI que se puede ignorar no sirve para nada.

## 3.3 GitHub Actions completo

```yaml
name: CI/CD

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16
        env:
          POSTGRES_PASSWORD: secret
          POSTGRES_DB: testing
        options: >-
          --health-cmd pg_isready --health-interval 10s --health-retries 5
        ports: ['5432:5432']

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: pdo_pgsql, bcmath
          coverage: xdebug

      - name: Cache de dependencias
        uses: actions/cache@v4
        with:
          path: vendor
          key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}

      - run: composer install --prefer-dist --no-interaction
      - run: cp .env.example .env && php artisan key:generate

      - name: Lint
        run: ./vendor/bin/pint --test

      - name: Análisis estático
        run: ./vendor/bin/phpstan analyse --memory-limit=1G

      - name: Tests
        run: php artisan test --coverage --min=70
        env:
          DB_CONNECTION: pgsql
          DB_HOST: localhost
          DB_PASSWORD: secret

      - name: Auditoría de seguridad
        run: composer audit

  deploy:
    needs: test
    if: github.ref == 'refs/heads/main'
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Build y push de la imagen
        run: |
          echo "${{ secrets.REGISTRY_TOKEN }}" | docker login ghcr.io -u ${{ github.actor }} --password-stdin
          docker build -t ghcr.io/${{ github.repository }}:${{ github.sha }} --target production .
          docker push ghcr.io/${{ github.repository }}:${{ github.sha }}

      - name: Deploy al VPS
        uses: appleboy/ssh-action@v1
        with:
          host: ${{ secrets.SSH_HOST }}
          username: deploy
          key: ${{ secrets.SSH_PRIVATE_KEY }}
          script: |
            cd /srv/app
            echo "IMAGE_TAG=${{ github.sha }}" > .env.deploy
            docker compose pull
            docker compose up -d --no-deps app
            docker compose exec -T app php artisan migrate --force
            docker image prune -f
```

🔑 **Tag con el SHA del commit, no `latest`.** Eso te permite rollback exacto a cualquier versión anterior.

## 3.4 Branch protection

En GitHub → Settings → Branches → proteger `main`:
```
□ Require pull request before merging
□ Require status checks to pass (el job "test")
□ Require branches to be up to date
□ No force push, no delete
```
Esto se ve en tu repo público y **demuestra que trabajás con proceso**, no pusheando a main a lo loco.

## 3.5 Estrategias de deploy

```
RECREATE      → apagar viejo, prender nuevo. Simple, con downtime.
ROLLING       → reemplazar instancias de a una. Sin downtime. ← lo estándar
BLUE/GREEN    → dos entornos completos, se switchea el tráfico. Rollback instantáneo.
CANARY        → 5% del tráfico a la versión nueva, se monitorea, se escala.
```
Para un VPS con Docker Compose, **rolling simple** ya es más que suficiente y suena profesional.

---

# 4. 🖥️ EL SERVIDOR (VPS)

## 4.1 Elegir y crear

```
Hetzner       → mejor precio/rendimiento (~4-5 EUR/mes, EU)
DigitalOcean  → mejor documentación, panel simple (~6 USD/mes)
Vultr / Linode → alternativas sólidas
```
**Specs mínimas para un proyecto de portfolio:** 2 vCPU / 4 GB RAM / 40 GB SSD. Con 1 GB vas a sufrir compilando assets.

**SO:** Ubuntu LTS (24.04) — es lo más documentado y lo que vas a encontrar en cualquier laburo.

## 4.2 Hardening inicial (OBLIGATORIO, primeros 10 minutos)

> Un VPS nuevo con SSH por contraseña recibe intentos de fuerza bruta **en minutos**. No es exageración.

```bash
# 1. Usuario no-root con sudo
adduser deploy && usermod -aG sudo deploy

# 2. Copiar tu clave SSH
ssh-copy-id deploy@TU_IP

# 3. Endurecer SSH  → /etc/ssh/sshd_config
PermitRootLogin no
PasswordAuthentication no        # ← el cambio más importante de todos
PubkeyAuthentication yes
sudo systemctl restart ssh

# 4. Actualizaciones de seguridad automáticas
sudo apt install unattended-upgrades && sudo dpkg-reconfigure -plow unattended-upgrades

# 5. fail2ban (banea IPs que intentan fuerza bruta)
sudo apt install fail2ban && sudo systemctl enable --now fail2ban
```

⚠️ **Antes de cerrar la terminal, abrí OTRA sesión y verificá que podés entrar.** Si te equivocaste en la config de SSH y cerrás, quedás afuera del servidor.

## 4.3 Firewall

```bash
sudo ufw default deny incoming    # todo cerrado por defecto
sudo ufw default allow outgoing
sudo ufw allow 22/tcp             # SSH
sudo ufw allow 80/tcp             # HTTP (para el redirect y Let's Encrypt)
sudo ufw allow 443/tcp            # HTTPS
sudo ufw enable
```

🔑 **La base de datos NO se expone a internet.** Nada de `allow 5432`. La DB vive en la red interna de Docker y sólo la ve la app.

## 4.4 Usuarios y permisos

```
Principio de menor privilegio:
├─ Usuario deploy → sólo lo necesario para deployar
├─ Contenedores   → corren como usuario no-root
└─ Archivos       → 644 archivos, 755 directorios, 600 el .env
```

---

# 5. 🌐 NGINX Y HTTPS

## 5.1 Reverse proxy: qué es y por qué

```
Internet ──► NGINX (puerto 443) ──► app (puerto 9000, red interna)
              │
              ├─ termina TLS/HTTPS
              ├─ sirve archivos estáticos (mucho más rápido que la app)
              ├─ compresión gzip/brotli
              ├─ rate limiting
              ├─ headers de seguridad
              └─ balanceo de carga (si escalás a varias instancias)
```

**Nunca expongas tu app directo a internet.** El servidor de desarrollo de Laravel/Node no está hecho para eso.

## 5.2 Configuración base

```nginx
server {
    listen 80;
    server_name tuapp.com www.tuapp.com;
    return 301 https://$host$request_uri;      # todo a HTTPS
}

server {
    listen 443 ssl http2;
    server_name tuapp.com;
    root /var/www/public;

    ssl_certificate     /etc/letsencrypt/live/tuapp.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tuapp.com/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;

    # Compresión
    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;

    # Estáticos con caché largo (llevan hash en el nombre)
    location ~* \.(css|js|jpg|png|webp|woff2)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Rate limiting en login
    location /api/v1/login {
        limit_req zone=login burst=5 nodelay;
        try_files $uri /index.php?$query_string;
    }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    client_max_body_size 20M;     # límite de subida
}
```

## 5.3 HTTPS con Let's Encrypt

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d tuapp.com -d www.tuapp.com
# La renovación automática queda configurada sola (timer de systemd)
sudo certbot renew --dry-run     # verificá que funcione
```
Gratis, automático, sin excusa para no tener HTTPS.

## 5.4 Headers de seguridad

```nginx
add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "SAMEORIGIN" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:;" always;
```
🔧 Verificá el resultado en **securityheaders.com** y **ssllabs.com/ssltest**. Sacar una A+ y ponerlo en el README es un detalle que se nota.

---

# 6. 🔄 DEPLOY SIN DOWNTIME

## 6.1 El problema

```
❌ DEPLOY INGENUO
docker compose down     ← la app está CAÍDA
git pull
docker compose up -d
php artisan migrate     ← si falla, quedaste roto y caído
```

## 6.2 Health checks

Tu app debe exponer un endpoint que diga si está **realmente** lista:

```php
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'checks' => [
            'database' => DB::connection()->getPdo() ? 'ok' : 'fail',
            'cache'    => Cache::store()->getStore() ? 'ok' : 'fail',
        ],
        'version' => config('app.version'),
    ]);
});
```

```
/health  → ¿estoy vivo?  (liveness)
/ready   → ¿puedo recibir tráfico? (readiness — DB conectada, migraciones al día)
```
El orquestador no manda tráfico al contenedor nuevo hasta que `/ready` responde OK. **Ahí está el "sin downtime".**

## 6.3 Migraciones seguras (expand / contract)

⚠️ **Durante un deploy rolling, la versión vieja y la nueva conviven unos segundos.** La migración tiene que ser compatible con las dos.

```
Renombrar una columna SIN romper:

DEPLOY 1 (expand)   → agregar columna nueva, la app escribe en AMBAS
DEPLOY 2 (migrate)  → backfill de datos, la app lee de la NUEVA
DEPLOY 3 (contract) → borrar la columna vieja
```

```
❌ NUNCA en un solo deploy: DROP COLUMN, RENAME COLUMN, cambiar tipo de dato
✅ SIEMPRE: agregar columnas nullable, índices con CONCURRENTLY en Postgres
```

## 6.4 Rollback

```bash
# Por eso tageamos con el SHA del commit
IMAGE_TAG=<sha_anterior> docker compose up -d --no-deps app
```

🔑 **Regla:** el código se rollbackea fácil, **la base de datos NO**. Por eso las migraciones destructivas van en deploys separados y siempre después de confirmar que la versión nueva es estable.

---

# 7. 👁️ OBSERVABILIDAD

## 7.1 Los tres pilares

```
LOGS     → qué pasó, en detalle   → "el pedido 42 falló porque X"
MÉTRICAS → cuánto/cuántos         → "el p95 subió a 2s hace 10 minutos"
TRAZAS   → dónde se fue el tiempo → "1.8s de los 2s fueron en la API de pagos"
```

**Logs en contenedores:** van a `stdout`/`stderr`, **no a archivos dentro del contenedor** (se pierden al recrearlo). Docker los captura y los podés mandar a donde quieras.

```php
// Laravel: config/logging.php
'default' => env('LOG_CHANNEL', 'stderr'),
```

## 7.2 Errores en producción (Sentry)

```
Sin Sentry → te enterás de los errores porque un usuario te escribe (o no te escribe y se va)
Con Sentry → notificación con stack trace, request, usuario afectado y frecuencia
```
Tiene plan gratuito generoso. **No hay excusa para no tenerlo.** Además captura performance (p95 por endpoint), que es justo lo que necesitás para los números del CV.

## 7.3 Uptime monitoring

**UptimeRobot** (gratis): pinguea tu `/health` cada 5 minutos y te avisa por mail/Telegram si se cae. Cinco minutos de configuración.

## 7.4 Alertas que no ignorás

```
✅ ALERTA:  la app está caída, tasa de error > 5%, disco > 85%, p95 > 3s
❌ NO ALERTA: cada error 404, cada log de INFO, cosas que no requieren acción
```
🔑 **Si una alerta no requiere que alguien haga algo, no es una alerta — es ruido.** Y el ruido hace que ignores las alertas que sí importan.

---

# 8. 💾 BACKUPS Y RECUPERACIÓN

> **Un backup que nunca restauraste no es un backup. Es una carpeta.**

**Regla 3-2-1:**
```
3 copias de los datos
2 medios distintos
1 fuera del sitio (otro proveedor / otra región)
```

```bash
# Backup diario automático a almacenamiento externo
0 3 * * * docker compose exec -T db pg_dump -U postgres app \
          | gzip > /backups/app_$(date +\%F).sql.gz \
          && rclone copy /backups remote:backups \
          && find /backups -mtime +7 -delete
```

**Lo que casi nadie hace y hay que hacer:**
```
□ Backup automático diario
□ Backup fuera del servidor (si se muere el VPS, se muere el backup local)
□ Retención definida (diarios 7 días, semanales 4, mensuales 6)
□ 🔴 PRUEBA DE RESTAURACIÓN documentada, al menos una vez
□ RTO/RPO definidos: ¿cuánto tardo en volver? ¿cuántos datos puedo perder?
```

**Ponelo en el README.** "Estrategia de backups con prueba de restauración documentada" en un proyecto de portfolio es algo que casi ningún junior tiene.

---

# 9. 🌍 DOMINIO Y DNS

```
Registrar: Namecheap, Porkbun, Cloudflare (~10-12 USD/año)
```

**Registros que vas a usar:**
```
A       tuapp.com        → 203.0.113.10     (IP del VPS)
CNAME   www              → tuapp.com
MX      @                → servidor de mail
TXT     @                → verificaciones (SPF, dominio)
```

**Cloudflare como DNS (gratis) te da de regalo:** CDN, protección DDoS, y ocultar la IP real de tu VPS. Recomendado.

⚠️ **TTL y propagación:** antes de migrar un dominio, bajá el TTL a 300s con días de anticipación. Si lo dejás en 86400, los cambios tardan hasta 24hs en verse.

---

# 📎 ANEXO — KUBERNETES: cuándo SÍ y cuándo es sobreingeniería

## Qué problema resuelve realmente

```
Docker         → "empaqueto UNA app con su entorno"
Docker Compose → "corro VARIOS contenedores en UN servidor"
Kubernetes     → "orquesto CIENTOS de contenedores en MUCHOS servidores,
                  con autoescalado, self-healing y despliegues progresivos"
```

## La regla de decisión

```
¿Cuántos servidores tenés?
├─ 1        → Docker Compose. K8s es sobreingeniería. ✅
├─ 2-3      → Compose + un balanceador suele alcanzar
└─ 5+       → Ahí Kubernetes empieza a justificarse

¿Necesitás autoescalado por demanda variable?     ¿Alta disponibilidad real (99.9%+)?
¿Varios equipos deployando en el mismo cluster?   ¿Miles de req/seg?
└─ Si todo es NO → no lo necesitás.
```

## Por qué NO en un proyecto de portfolio

```
❌ Complejidad enorme para cero beneficio en 1 servidor
❌ Costo: un cluster gestionado arranca en ~70-100 USD/mes vs 5 del VPS
❌ Te desvía meses de lo que sí te va a conseguir trabajo
❌ En la entrevista, K8s a medias es PEOR que no tenerlo:
   la primera repregunta (probes, CrashLoopBackOff, secrets, ingress) te desarma
```

🔑 **Lo que un tech lead valora no es que sepas K8s. Es que sepas CUÁNDO no usarlo.**
Decir *"lo evalué y no se justificaba para un servicio en un VPS; usé Compose y documenté la decisión"* demuestra criterio de arquitectura. Eso puntúa más que un cluster mal armado.

## Los conceptos que sí conviene manejar (para conversar, no para implementar)

```
Pod          → la unidad mínima: uno o más contenedores que comparten red
Deployment   → declara cuántas réplicas de un pod querés; K8s las mantiene
Service      → IP estable + balanceo hacia los pods
Ingress      → el reverse proxy de entrada (equivalente a tu nginx)
ConfigMap    → configuración no sensible
Secret       → configuración sensible
Probes       → liveness (¿está vivo? si no, lo reinicia)
               readiness (¿puede recibir tráfico? si no, lo saca del balanceo)
HPA          → autoescalado horizontal según CPU/memoria/métricas
```

🔑 **Todo lo que aprendiste en esta biblia se transfiere:** los healthchecks del §6.2 son las probes, el nginx del §5 es el Ingress, las variables del §2 son ConfigMaps y Secrets, el deploy rolling del §3.5 es la estrategia por defecto de un Deployment. **Kubernetes no es un mundo nuevo: es la misma teoría a otra escala.** Por eso conviene aprenderlo *después*, cuando los fundamentos ya estén firmes.

## Cuándo aprenderlo en serio

```
✅ Cuando un trabajo concreto lo requiera (ahí lo aprendés pago y con contexto real)
✅ Cuando tengas un problema real de escala que Compose no resuelva
❌ "Por si acaso", antes de dominar Docker, CI/CD y un deploy en VPS
```

**Alternativas intermedias si algún día necesitás más que Compose:** Docker Swarm (mucho más simple), Nomad, o servicios gestionados tipo ECS/Cloud Run que te dan orquestación sin administrar un cluster.

---

# 10. ✅ CHECKLIST DE INFRAESTRUCTURA

```
DOCKER
□ Dockerfile multi-stage, imagen final slim
□ Corre como usuario no-root
□ Versiones pineadas (nada de :latest)
□ .dockerignore presente y completo
□ Healthcheck definido
□ Datos persistentes en volúmenes, no en el contenedor
□ La misma imagen corre en dev, CI y producción

SECRETOS
□ .env en .gitignore, .env.example en el repo
□ Secretos del CI en GitHub Secrets
□ Ningún secreto en el historial de git
□ Permisos 600 en el .env del servidor

CI/CD
□ Pipeline: lint → static analysis → tests → security audit → build → deploy
□ Branch protection en main, no se mergea con el CI en rojo
□ Imágenes tageadas con el SHA del commit
□ Deploy automático desde main

SERVIDOR
□ PasswordAuthentication no, PermitRootLogin no
□ Usuario deploy no-root con clave SSH
□ UFW activo: sólo 22, 80, 443
□ fail2ban corriendo
□ Actualizaciones de seguridad automáticas
□ Base de datos NO expuesta a internet

NGINX / HTTPS
□ Reverse proxy, la app nunca expuesta directo
□ HTTPS con Let's Encrypt y renovación automática verificada
□ Redirect 80 → 443
□ Headers de seguridad (HSTS, CSP, X-Frame-Options)
□ A+ en ssllabs y securityheaders
□ Gzip/brotli + caché largo en estáticos
□ Rate limiting en endpoints de auth

DEPLOY
□ Endpoints /health y /ready
□ Deploy sin downtime (rolling)
□ Migraciones compatibles hacia atrás (expand/contract)
□ Procedimiento de rollback probado

OBSERVABILIDAD
□ Logs a stdout, estructurados
□ Sentry (o equivalente) capturando errores y performance
□ Uptime monitor sobre /health
□ Alertas accionables definidas

BACKUPS
□ Automáticos, diarios
□ Almacenados FUERA del servidor
□ Retención definida
□ 🔴 Restauración probada y documentada al menos una vez

DOMINIO
□ Dominio propio (no un .vercel.app en el proyecto principal)
□ www y raíz apuntando bien
□ TTL razonable
```

---

## 🎯 REGLAS PARA LA IA — infraestructura

```
1. Dockerfiles siempre multi-stage, imagen final mínima, usuario no-root.
2. Nunca :latest. Versiones pineadas siempre.
3. Ningún secreto en el Dockerfile, en el compose ni en el repo.
4. Toda config por variable de entorno; la misma imagen para todos los ambientes.
5. Si generás un pipeline, incluí lint, análisis estático, tests y audit — no sólo tests.
6. Tagueá imágenes con el SHA del commit, nunca latest.
7. Toda app expuesta va detrás de un reverse proxy con HTTPS.
8. Toda migración debe ser compatible con la versión anterior de la app.
9. Si proponés infraestructura, decime el costo mensual estimado.
10. Si algo genera estado (uploads, DB, logs), decime explícitamente dónde persiste.
```

---

*Documento vivo — Mes 2 (DevOps/Infra). Pendiente: Mes 3 (Node/TS + fundamentos), Mes 4 (Portfolio y búsqueda).*
