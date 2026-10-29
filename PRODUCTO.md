# Producto Pallet Control

**Estado:** dirección de producto inicial
**Última revisión:** 2026-10-29

## Propósito

Pallet Control ayuda a equipos operativos a controlar pedidos, productos, pallets, bases, incidencias y evidencia visual con trazabilidad y menor riesgo de errores manuales.

## Usuarios

- Operadores y vendedores que necesitan flujos directos y comprensibles.
- Supervisores que necesitan visibilidad, corrección y auditoría.
- Administradores que gestionan usuarios, almacenamiento y operación.

## Resultado esperado

- Registrar y localizar información con pocos pasos.
- Evitar sobreasignaciones y pérdida de datos.
- Mostrar estados y errores accionables.
- Mantener tiempos de respuesta adecuados a la operación real.
- Recuperarse de fallos sin depender del conocimiento técnico del usuario.

## Límites actuales

- Producto web/PWA con backend Laravel central.
- Telegram es la integración de bot activa.
- `whatsapp-bot/` es legado inactivo y no pertenece al producto operativo.
- El funcionamiento offline completo, escritorio, mobile y SaaS multiempresa todavía no están definidos normativamente.

## Dirección aprobada, no implementada

Después de consolidar el núcleo se evaluará una aplicación Windows offline-first, sincronizada con una autoridad central y validada con una empresa piloto. La expansión futura apunta a SaaS multiempresa. Estas intenciones no sustituyen los GAP técnicos requeridos.

## Criterios de producto

- Confiabilidad y claridad prevalecen sobre cantidad de funciones.
- Seguridad y autorización no pueden depender de que el usuario conozca una URL o flujo.
- Toda optimización debe medirse.
- Toda operación crítica debe dejar evidencia suficiente para explicar qué ocurrió.
- Una función no está terminada si sus estados de carga, vacío, error y recuperación no son utilizables.
