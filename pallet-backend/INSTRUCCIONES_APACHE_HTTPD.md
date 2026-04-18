# Instrucciones para configurar Apache httpd.conf

## ⚠️ IMPORTANTE: La configuración debe estar en el directorio CORRECTO

## Pasos:

1. **Abrir httpd.conf de XAMPP:**
   - Abre el Panel de Control de XAMPP
   - Haz clic en "Config" junto a Apache
   - Selecciona "httpd.conf"

2. **Buscar la sección CORRECTA:**
   Busca esta sección (NO la de cgi-bin):
   ```apache
   <Directory "C:/xampp/htdocs">
       Options Indexes FollowSymLinks
       AllowOverride All
       Require all granted
   </Directory>
   ```

3. **Modificar la sección CORRECTA:**
   Debe quedar así:
   ```apache
   <Directory "C:/xampp/htdocs">
       Options Indexes FollowSymLinks
       AllowOverride All
       Require all granted
       
       # Límite de tamaño del cuerpo de solicitud HTTP (50MB)
       LimitRequestBody 52428800
   </Directory>
   ```

4. **Verificar que AllowOverride esté en "All":**
   Si dice `AllowOverride None`, cámbialo a `AllowOverride All`

5. **Guardar y reiniciar Apache:**
   - Guarda el archivo httpd.conf
   - En XAMPP: Stop → Espera 15 segundos → Start
   - Si no funciona, cierra XAMPP completamente y vuelve a abrirlo

## Verificar:

Después de reiniciar, visita:
- `http://localhost/pallet-backend/public/check-php-config.php`

Debe mostrar:
- `upload_max_filesize: 40M`
- `post_max_size: 45M`
- `memory_limit: 512M`

## Nota importante:

El error que estás viendo (2048 KiB) viene de PHP, no de Apache. Esto significa que:
1. El php.ini de XAMPP debe tener los valores correctos (ya los tienes: 40M, 45M)
2. Apache debe estar usando el PHP de XAMPP, no otra instalación
3. El .htaccess debe estar funcionando (por eso necesitas AllowOverride All)

Si después de hacer estos cambios el error persiste, puede ser que:
- Apache esté usando otra instalación de PHP
- Haya algún problema con la configuración de PHP en XAMPP
- Necesites verificar qué PHP está usando Apache realmente
