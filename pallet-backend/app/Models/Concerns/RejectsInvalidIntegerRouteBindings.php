<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * H-050 — guardián común del route binding implícito sobre PKs bigint.
 *
 * El segmento de ruta llega como string sin tipo; compararlo directo
 * contra una columna bigint hace que PostgreSQL aborte con
 * SQLSTATE[22P02] (o 22003 en overflow) y el handler global responda
 * 500. Este trait reproduce el contrato ya acreditado en PendingItem:
 * cuando el binding apunta a la PK numérica del modelo, un segmento que
 * no es un entero no negativo dentro del rango bigint se rechaza
 * devolviendo null — el framework emite el 404 estándar
 * (ModelNotFoundException) en el mismo punto de la precedencia vigente
 * (auth:sanctum → binding → user.active → rol/admin → Form Request),
 * sin consultar la base.
 *
 * La guarda sólo aplica cuando el campo de binding es la PK entera del
 * modelo: campos alternativos ({modelo:slug}, scoped bindings) y PKs de
 * tipo string conservan la resolución por defecto del framework. Los
 * modelos con SoftDeletes quedan cubiertos también en
 * resolveSoftDeletableRouteBinding.
 *
 * El mismo contrato se expone para resoluciones manuales de segmentos
 * escalares (parámetros que llegan al controlador sin type-hint y se
 * resuelven con find/findOrFail dentro de la acción):
 * normalizeRouteId() devuelve el id normalizado o null — el caller
 * responde 404 sin consultar — y findOrFailRouteId() reproduce el
 * findOrFail del framework lanzando ModelNotFoundException. La
 * normalización ocurre en el punto exacto donde antes corría la
 * consulta, así que la precedencia de cada ruta (middleware → padre →
 * segment → validación → dominio) queda intacta.
 */
trait RejectsInvalidIntegerRouteBindings
{
    public function resolveRouteBinding($value, $field = null)
    {
        $value = $this->integerRouteBindingValue($value, $field);

        if ($value === false) {
            return null;
        }

        return $this->resolveRouteBindingQuery($this, $value, $field)->first();
    }

    public function resolveSoftDeletableRouteBinding($value, $field = null)
    {
        $value = $this->integerRouteBindingValue($value, $field);

        if ($value === false) {
            return null;
        }

        // Equivale al withTrashed() del framework — el macro retira el
        // SoftDeletingScope—, pero expresado con la API real del Builder:
        // withTrashed es un macro que el análisis estático no resuelve en
        // modelos sin SoftDeletes. Este método sólo se invoca cuando la
        // ruta declara withTrashed y el modelo usa SoftDeletes.
        return $this->resolveRouteBindingQuery($this, $value, $field)
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->first();
    }

    /**
     * Normaliza un segmento escalar de ruta destinado a la PK entera
     * del modelo con el contrato de D-033, para resoluciones manuales
     * que no pasan por el binding implícito: devuelve el id como int,
     * o null cuando el segmento no es un entero no negativo dentro del
     * rango bigint. null exige que el caller responda 404 sin consultar
     * la tabla — el mismo resultado que produce el binding al rechazar.
     */
    public static function normalizeRouteId($value): ?int
    {
        $normalizado = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($normalizado) ? $normalizado : null;
    }

    /**
     * Equivalente manual de findOrFail sobre la PK entera para
     * parámetros de ruta escalares: un segmento inválido lanza
     * ModelNotFoundException sin consultar — la misma respuesta que un
     * id numérico inexistente — y un válido delega en findOrFail.
     */
    public static function findOrFailRouteId($value)
    {
        $id = static::normalizeRouteId($value);

        if ($id === null) {
            throw (new ModelNotFoundException)->setModel(static::class, $value);
        }

        return static::findOrFail($id);
    }

    /**
     * Devuelve el segmento normalizado a int cuando el binding apunta a
     * la PK entera del modelo (false si no es un entero no negativo
     * dentro del rango), o el valor intacto para bindings sobre otros
     * campos o PKs no enteras.
     *
     * @return mixed
     */
    private function integerRouteBindingValue($value, $field)
    {
        if (! $this->routeBindingTargetsIntegerKey($field)) {
            return $value;
        }

        return static::normalizeRouteId($value) ?? false;
    }

    private function routeBindingTargetsIntegerKey($field): bool
    {
        return ($field ?? $this->getRouteKeyName()) === $this->getKeyName()
            && $this->getKeyType() === 'int';
    }
}
