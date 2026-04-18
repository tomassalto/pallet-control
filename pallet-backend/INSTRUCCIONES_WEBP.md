# Conversión de imágenes a WebP

## Funcionalidad implementada

Las fotos subidas ahora se convierten automáticamente a formato WebP usando **Intervention Image v3** antes de guardarse. **Solo se guardan archivos WebP, no los originales**, lo que reduce significativamente el tamaño de los archivos (generalmente entre 25-35% más pequeños que JPEG).

## Requisitos

- **Intervention Image v3.11** (ya instalado en el proyecto)
- Extensión **GD** de PHP habilitada (viene con XAMPP por defecto)
- Soporte para **WebP** en GD (incluido en PHP 7.2+)

### Verificar si GD está habilitado:

### Verificar si GD está habilitado:

1. Crea un archivo `phpinfo.php` en `pallet-backend/public/`:
```php
<?php
phpinfo();
?>
```

2. Visita `http://localhost/pallet-backend/public/phpinfo.php`
3. Busca "gd" en la página
4. Verifica que aparezca "WebP Support" como "enabled"

### Habilitar GD en XAMPP:

1. Abre el Panel de Control de XAMPP
2. Haz clic en "Config" junto a Apache
3. Selecciona "PHP (php.ini)"
4. Busca la línea `;extension=gd` (puede estar comentada con `;`)
5. Si está comentada, quita el `;` para descomentarla: `extension=gd`
6. Guarda el archivo
7. Reinicia Apache

### Habilitar soporte WebP en GD:

Si GD está habilitado pero WebP no funciona:

1. En el `php.ini`, busca `extension=gd`
2. Asegúrate de que no esté comentada
3. Si usas Windows, puede que necesites descomentar también:
   ```
   extension=gd
   ```

**Nota:** En versiones recientes de PHP (7.2+), el soporte WebP viene incluido con GD si está compilado correctamente.

## Comportamiento

- **Solo se guardan archivos WebP:** Las imágenes originales NO se guardan
- **Redimensionamiento automático:** Si la imagen es mayor a 4000px en cualquier lado, se redimensiona manteniendo la proporción
- **Calidad de compresión:** 85% (balance entre calidad y tamaño)
- **Manejo de errores:** Si la conversión falla, se lanza una excepción (no hay fallback al original)

## Ventajas de WebP

- **Tamaño reducido:** 25-35% más pequeño que JPEG con la misma calidad visual
- **Mejor rendimiento:** Menos ancho de banda y carga más rápida
- **Soporte moderno:** Compatible con todos los navegadores modernos

## Características adicionales

- **Redimensionamiento inteligente:** Las imágenes muy grandes se redimensionan automáticamente a un máximo de 4000px
- **Manejo de múltiples formatos:** Intervention Image puede leer JPEG, PNG, GIF, BMP, y otros formatos
- **Optimización automática:** Las imágenes se optimizan durante la conversión

## Troubleshooting

Si las imágenes no se convierten:

1. Verifica que Intervention Image esté instalado: `composer show intervention/image`
2. Verifica que GD esté habilitado (`phpinfo()`)
3. Verifica que WebP esté soportado (busca "WebP Support" en `phpinfo()`)
4. Revisa los logs de Laravel en `storage/logs/laravel.log` para ver errores
5. Si hay errores, verifica que el directorio de storage tenga permisos de escritura

## Notas técnicas

- **Driver usado:** GD (puede cambiarse a Imagick si está disponible)
- **Formato de salida:** Siempre WebP (`.webp`)
- **Tamaño máximo de entrada:** 20MB (configurado en validación)
- **Tamaño máximo de salida:** 4000px en el lado más largo (se redimensiona automáticamente)

