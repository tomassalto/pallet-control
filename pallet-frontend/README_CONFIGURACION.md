# 🔧 Configuración para acceder desde otra red

## Problema

Cuando accedes desde tu celular en otra red WiFi, el frontend no puede conectarse al backend porque está usando URLs relativas (`/api/v1`) que solo funcionan cuando el frontend y backend están en el mismo servidor.

## ✅ Solución Rápida

### Paso 1: Exponer el backend con ngrok

1. **Abre una terminal y ejecuta**:

   ```bash
   ngrok http 8000
   ```

2. **Copia la URL HTTPS** que te da ngrok (algo como: `https://xxxx-xxxx-xxxx.ngrok-free.dev`)

### Paso 2: Configurar el frontend

1. **Crea un archivo `.env`** en la carpeta `pallet-frontend/` con este contenido:

   ```
   VITE_API_BASE_URL=https://TU_URL_NGROK.ngrok-free.dev/api/v1
   ```

   (Reemplaza `TU_URL_NGROK` con la URL que te dio ngrok)

2. **Recompila el frontend**:

   ```bash
   cd pallet-frontend
   npm run build
   ```

3. **Si estás usando el servidor de desarrollo de Vite** (`npm run dev`), reinícialo:
   ```bash
   npm run dev
   ```

### Paso 3: Acceder desde el celular

- Si estás usando ngrok para el frontend también, usa esa URL
- Si el frontend está hosteado en otro lugar, asegúrate de que tenga acceso a la URL de ngrok del backend

## 📝 Notas importantes

- **CORS ya está configurado**: El backend permite peticiones desde cualquier origen
- **ngrok es gratuito**: Pero la URL cambia cada vez que lo reinicias (a menos que tengas plan de pago)
- **Desarrollo local**: Si estás en la misma red, el proxy de Vite funciona automáticamente
- **Producción**: Siempre necesitas configurar `VITE_API_BASE_URL` con la URL completa del backend

## 🔍 Verificar que funciona

1. Abre la consola del navegador (F12)
2. Ve a la pestaña "Network" (Red)
3. Intenta subir una foto
4. Verifica que las peticiones vayan a la URL de ngrok, no a `/api/v1`
