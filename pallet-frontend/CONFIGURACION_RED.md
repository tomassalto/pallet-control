# Configuración para acceder desde otra red

## Problema

Cuando accedes desde tu celular en otra red WiFi, el frontend no puede conectarse al backend porque está usando URLs relativas (`/api/v1`) que solo funcionan cuando el frontend y backend están en el mismo servidor.

## Solución

### Opción 1: Usar ngrok (Recomendado para desarrollo)

1. **Instalar ngrok** (si no lo tienes):

   - Descarga desde: https://ngrok.com/download
   - O instala con: `npm install -g ngrok`

2. **Exponer el backend con ngrok**:

   ```bash
   ngrok http 8000
   ```

   Esto te dará una URL como: `https://xxxx-xxxx-xxxx.ngrok-free.dev`

3. **Configurar el frontend**:

   - Crea un archivo `.env` en `pallet-frontend/` con:

   ```
   VITE_API_BASE_URL=https://xxxx-xxxx-xxxx.ngrok-free.dev/api/v1
   ```

   (Reemplaza `xxxx-xxxx-xxxx` con tu URL de ngrok)

4. **Recompilar el frontend**:

   ```bash
   cd pallet-frontend
   npm run build
   ```

5. **Configurar CORS en Laravel** (si es necesario):
   - El archivo `config/cors.php` ya está configurado para permitir todos los orígenes (`'allowed_origins' => ['*']`)

### Opción 2: Usar IP pública

1. **Obtener tu IP pública**:

   - Visita: https://whatismyipaddress.com/
   - O ejecuta: `curl ifconfig.me`

2. **Configurar el router**:

   - Abre el puerto 8000 en tu router y redirígelo a la IP local de tu computadora

3. **Configurar el frontend**:

   - Crea un archivo `.env` en `pallet-frontend/` con:

   ```
   VITE_API_BASE_URL=http://TU_IP_PUBLICA:8000/api/v1
   ```

   (Reemplaza `TU_IP_PUBLICA` con tu IP pública)

4. **Recompilar el frontend**:
   ```bash
   cd pallet-frontend
   npm run build
   ```

### Opción 3: Usar un túnel alternativo (Cloudflare Tunnel, localtunnel, etc.)

Similar a ngrok, pero con otros servicios.

## Notas importantes

- **Desarrollo**: Si estás usando `npm run dev`, el proxy de Vite funciona automáticamente y no necesitas configurar `VITE_API_BASE_URL`
- **Producción**: Siempre necesitas configurar `VITE_API_BASE_URL` con la URL completa del backend
- **CORS**: Asegúrate de que el backend permita peticiones desde el origen del frontend
- **Seguridad**: En producción, usa HTTPS y configura CORS correctamente
