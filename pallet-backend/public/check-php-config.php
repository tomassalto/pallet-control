<?php
/**
 * Script de verificación de configuración PHP
 * Visita: http://localhost/pallet-backend/public/check-php-config.php
 * 
 * IMPORTANTE: Elimina este archivo después de verificar por seguridad
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Verificación de Configuración PHP</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .ok { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #f8f9fa; font-weight: bold; }
        .value { font-family: monospace; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Verificación de Configuración PHP</h1>
        
        <?php
        $uploadMax = ini_get('upload_max_filesize');
        $postMax = ini_get('post_max_size');
        $memoryLimit = ini_get('memory_limit');
        $maxExecutionTime = ini_get('max_execution_time');
        $maxInputTime = ini_get('max_input_time');
        
        // Convertir a bytes para comparación
        function convertToBytes($val) {
            $val = trim($val);
            $last = strtolower($val[strlen($val)-1]);
            $val = (int)$val;
            switch($last) {
                case 'g': $val *= 1024;
                case 'm': $val *= 1024;
                case 'k': $val *= 1024;
            }
            return $val;
        }
        
        $uploadMaxBytes = convertToBytes($uploadMax);
        $postMaxBytes = convertToBytes($postMax);
        $requiredUpload = 40 * 1024 * 1024; // 40MB
        $requiredPost = 45 * 1024 * 1024; // 45MB
        $requiredMemory = 512 * 1024 * 1024; // 512MB
        
        $uploadOk = $uploadMaxBytes >= $requiredUpload;
        $postOk = $postMaxBytes >= $requiredPost;
        $memoryOk = convertToBytes($memoryLimit) >= $requiredMemory;
        ?>
        
        <h2>Valores Actuales:</h2>
        <table>
            <tr>
                <th>Configuración</th>
                <th>Valor Actual</th>
                <th>Valor Requerido</th>
                <th>Estado</th>
            </tr>
            <tr>
                <td><strong>upload_max_filesize</strong></td>
                <td class="value"><?php echo $uploadMax; ?></td>
                <td>40M</td>
                <td>
                    <?php if ($uploadOk): ?>
                        <span class="status ok">✅ OK</span>
                    <?php else: ?>
                        <span class="status error">❌ INSUFICIENTE</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>post_max_size</strong></td>
                <td class="value"><?php echo $postMax; ?></td>
                <td>45M</td>
                <td>
                    <?php if ($postOk): ?>
                        <span class="status ok">✅ OK</span>
                    <?php else: ?>
                        <span class="status error">❌ INSUFICIENTE</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>memory_limit</strong></td>
                <td class="value"><?php echo $memoryLimit; ?></td>
                <td>512M</td>
                <td>
                    <?php if ($memoryOk): ?>
                        <span class="status ok">✅ OK</span>
                    <?php else: ?>
                        <span class="status warning">⚠️ RECOMENDADO</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td><strong>max_execution_time</strong></td>
                <td class="value"><?php echo $maxExecutionTime; ?>s</td>
                <td>300s</td>
                <td>-</td>
            </tr>
            <tr>
                <td><strong>max_input_time</strong></td>
                <td class="value"><?php echo $maxInputTime; ?>s</td>
                <td>300s</td>
                <td>-</td>
            </tr>
        </table>
        
        <?php if (!$uploadOk || !$postOk): ?>
            <div class="status error">
                <h3>⚠️ Acción Requerida</h3>
                <p>Los valores de PHP no están configurados correctamente. Necesitas:</p>
                <ol>
                    <li>Abrir el Panel de Control de XAMPP</li>
                    <li>Hacer clic en "Config" junto a Apache</li>
                    <li>Seleccionar "PHP (php.ini)"</li>
                    <li>Buscar y modificar estas líneas:
                        <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; margin: 10px 0;">
upload_max_filesize = 40M
post_max_size = 45M
memory_limit = 512M
max_execution_time = 300
max_input_time = 300</pre>
                    </li>
                    <li><strong>Guardar el archivo</strong></li>
                    <li><strong>Reiniciar Apache</strong> (Stop → Start)</li>
                    <li>Recargar esta página para verificar</li>
                </ol>
            </div>
        <?php else: ?>
            <div class="status ok">
                <h3>✅ Configuración Correcta</h3>
                <p>Los valores de PHP están configurados correctamente. Puedes subir fotos de hasta 40MB.</p>
            </div>
        <?php endif; ?>
        
        <h2>Información Adicional:</h2>
        <table>
            <tr>
                <td><strong>Archivo php.ini usado por Apache:</strong></td>
                <td class="value"><?php echo php_ini_loaded_file(); ?></td>
            </tr>
            <tr>
                <td><strong>Archivos php.ini adicionales:</strong></td>
                <td class="value"><?php echo php_ini_scanned_files() ?: 'Ninguno'; ?></td>
            </tr>
            <tr>
                <td><strong>Versión PHP:</strong></td>
                <td class="value"><?php echo phpversion(); ?></td>
            </tr>
            <tr>
                <td><strong>Valores reales en tiempo de ejecución:</strong></td>
                <td>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li>upload_max_filesize: <strong><?php echo ini_get('upload_max_filesize'); ?></strong></li>
                        <li>post_max_size: <strong><?php echo ini_get('post_max_size'); ?></strong></li>
                        <li>memory_limit: <strong><?php echo ini_get('memory_limit'); ?></strong></li>
                    </ul>
                </td>
            </tr>
        </table>
        
        <div class="status warning" style="margin-top: 20px;">
            <h3>⚠️ Si los valores no coinciden con lo que modificaste:</h3>
            <p>Es posible que hayas modificado el <code>php.ini</code> incorrecto. Apache usa el archivo mostrado arriba.</p>
            <p><strong>Pasos:</strong></p>
            <ol>
                <li>Copia la ruta del archivo php.ini mostrado arriba</li>
                <li>Ábrelo en un editor de texto</li>
                <li>Busca y modifica los valores como se indica arriba</li>
                <li><strong>Reinicia Apache completamente</strong> (cierra y abre XAMPP si es necesario)</li>
            </ol>
        </div>
        
        <div class="status warning" style="margin-top: 20px;">
            <h3>🔧 Solución si los valores no coinciden:</h3>
            <p>Si modificaste el php.ini pero los valores siguen siendo incorrectos:</p>
            <ol>
                <li><strong>Verifica la sintaxis:</strong> Asegúrate de que las líneas no tengan espacios extra o caracteres raros:
                    <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px; margin: 10px 0;">
upload_max_filesize = 40M
post_max_size = 45M
memory_limit = 512M</pre>
                </li>
                <li><strong>Busca duplicados:</strong> Busca en el archivo si hay otras líneas con los mismos nombres y elimínalas o coméntalas con `;`</li>
                <li><strong>Reinicia Apache completamente:</strong>
                    <ul>
                        <li>En XAMPP: Stop → Espera 10 segundos → Start</li>
                        <li>Si no funciona: Cierra XAMPP completamente y vuelve a abrirlo</li>
                        <li>Si aún no funciona: Reinicia Windows</li>
                    </ul>
                </li>
                <li><strong>Verifica que no haya otros php.ini:</strong> Busca en el archivo si hay secciones `[PATH=...]` o `[HOST=...]` que puedan estar sobrescribiendo los valores</li>
            </ol>
        </div>
        
        <div class="status warning" style="margin-top: 20px;">
            <strong>⚠️ IMPORTANTE:</strong> Elimina este archivo después de verificar por seguridad.
        </div>
    </div>
</body>
</html>

