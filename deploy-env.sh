#!/bin/sh

# La configuración productiva nunca puede habilitar detalles de debug.
if [ "${APP_ENV:-production}" = "production" ]; then
    if [ -n "${APP_DEBUG:-}" ] && [ "${APP_DEBUG}" != "false" ] && [ "${APP_DEBUG}" != "0" ]; then
        echo ">>> APP_DEBUG fue ignorado: producción fuerza APP_DEBUG=false."
    fi

    export APP_DEBUG=false
fi
