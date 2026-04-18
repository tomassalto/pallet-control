# Instrucciones para aumentar el límite de subida de fotos a 40MB

## Cambios realizados automáticamente:
1. ✅ Límite en controladores actualizado a 20MB (20480 KB) - validación del lado del servidor
2. ✅ Configuración agregada en `.htaccess` (40M upload, 45M post)
3. ✅ Archivo `.user.ini` creado en `public/.user.ini` como respaldo

## Si aún tienes problemas, modifica el php.ini de XAMPP:

### Paso 1: Encontrar el php.ini de XAMPP
1. Abre el Panel de Control de XAMPP
2. Haz clic en "Config" junto a Apache
3. Selecciona "PHP (php.ini)"
4. Se abrirá el archivo `php.ini` en el editor

### Paso 2: Modificar los valores
Busca y modifica las siguientes líneas (o agrégalas si no existen):

```ini
upload_max_filesize = 40M
post_max_size = 45M
max_execution_time = 300
max_input_time = 300
memory_limit = 512M
max_file_uploads = 20
```

**Importante:**
- `post_max_size` debe ser mayor que `upload_max_filesize` (recomendado: 45M para 40M de upload)
- `memory_limit` debe ser suficiente para procesar las imágenes (512M recomendado)
- El error "POST Content-Length exceeds limit" indica que `post_max_size` es muy bajo

### Paso 3: Reiniciar Apache
1. En el Panel de Control de XAMPP
2. Detén Apache (Stop)
3. Inicia Apache nuevamente (Start)

### Paso 4: Verificar los cambios

**Opción 1: Script de verificación (recomendado)**
Ya existe un script de verificación en `pallet-backend/public/check-php-config.php`

Visita: `http://localhost/pallet-backend/public/check-php-config.php`

Este script mostrará:
- Los valores actuales de PHP
- Si están configurados correctamente
- Instrucciones si necesitas hacer cambios

**Opción 2: phpinfo()**
Crea un archivo `info.php` en `pallet-backend/public/` con este contenido:

```php
<?php
phpinfo();
?>
```

Luego visita: `http://localhost/pallet-backend/public/info.php`

Busca:
- `upload_max_filesize` → debe mostrar 40M
- `post_max_size` → debe mostrar 45M
- `memory_limit` → debe mostrar 512M

**IMPORTANTE:** Elimina los archivos `check-php-config.php` e `info.php` después de verificar por seguridad.

## Solución de problemas:

### Error: "POST Content-Length exceeds the limit"
- Este error indica que `post_max_size` es menor que el tamaño del archivo que intentas subir
- **Solución:** Aumenta `post_max_size` en `php.ini` a 45M o más y reinicia Apache

### El .htaccess no funciona
- Algunas configuraciones de XAMPP no permiten modificar PHP desde `.htaccess`
- **Solución:** Modifica directamente el `php.ini` de XAMPP (ver Paso 1-2)

### El .user.ini no funciona
- `.user.ini` solo funciona en algunos entornos (PHP-FPM, algunos servidores compartidos)
- **Solución:** Modifica directamente el `php.ini` de XAMPP

### Error 413: "Payload Too Large" o "POST data is too large"
- Este error puede venir de Apache, no solo de PHP
- **Solución 1:** Ya agregamos `LimitRequestBody 52428800` (50MB) en `.htaccess`
- **Solución 2:** Si el `.htaccess` no funciona, modifica `httpd.conf` de Apache:
  1. Abre el Panel de Control de XAMPP
  2. Haz clic en "Config" junto a Apache
  3. Selecciona "httpd.conf"
  4. Busca la línea que dice `LimitRequestBody` (puede estar comentada o no existir)
  5. Agrega o descomenta: `LimitRequestBody 52428800` (50MB en bytes)
  6. Guarda y reinicia Apache

### Si usas ngrok o túnel
- ngrok tiene un límite por defecto de 10MB para el cuerpo de la solicitud
- **Solución:** Usa el flag `--request-header-add "Content-Length: 52428800"` o actualiza a ngrok Pro
- O mejor: comprime las imágenes antes de subirlas (ver solución alternativa abajo)

## Solución alternativa: Comprimir imágenes antes de subir
Si los límites del servidor no se pueden modificar, podemos implementar compresión de imágenes en el frontend antes de subirlas. Esto reduciría el tamaño del archivo antes de enviarlo al servidor.

## Notas adicionales:
- Si usas ngrok o un servidor remoto, también necesitarás modificar el php.ini en ese servidor
- Algunos servidores no permiten modificar valores PHP desde `.htaccess` por seguridad
- Si el problema persiste, puede ser un límite de red o del servidor web (nginx, Apache, etc.)
- Las fotos se convierten automáticamente a WebP en el servidor, lo que reduce el tamaño final del archivo
- El error 413 generalmente viene de Apache (`LimitRequestBody`) o del servidor web, no de PHP

