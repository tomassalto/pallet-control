// benchmarks/k6-hot-endpoints.js
//
// Baseline de carga para los 5 endpoints con mayor probabilidad de N+1
// del backend de Pallet Control. Ver benchmarks/README.md para el "por qué"
// de cada uno.
//
// Esto SOLO mide. No optimiza nada. Correr esto ANTES de tocar código y
// guardar la salida en benchmarks/results/ es el baseline del que habla
// BACKEND-BIBLIA §8.1 — sin eso, no hay "antes" que comparar con el "después".
//
// Uso:
//   k6 run --vus 20 --duration 30s benchmarks/k6-hot-endpoints.js
//
// Variables de entorno (ver README para cómo obtenerlas):
//   BASE_URL      (default: http://localhost:8000)
//   AUTH_EMAIL    usuario con rol asignado (necesario para /orders, /orders/{id}, /pallets/{id})
//   AUTH_PASSWORD
//   ORDER_ID      id numérico de un pedido con varios pallets/bases/items cargados
//   ORDER_CODE    code público de ese mismo pedido (para /public/orders/{code})
//   PALLET_ID     id numérico de un pallet con varias bases y fotos

import http from 'k6/http';
import { check, group } from 'k6';
import { Trend } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8000';
const AUTH_EMAIL = __ENV.AUTH_EMAIL || '';
const AUTH_PASSWORD = __ENV.AUTH_PASSWORD || '';
const ORDER_ID = __ENV.ORDER_ID || '1';
const ORDER_CODE = __ENV.ORDER_CODE || '1';
const PALLET_ID = __ENV.PALLET_ID || '1';

// Un Trend por endpoint — un p95 agregado de endpoints distintos miente,
// cada uno necesita su propia serie (BACKEND-BIBLIA §8.1: "p95 es el número
// que importa", pero tiene que ser el p95 DE CADA endpoint).
const trendOrdersList = new Trend('endpoint_orders_list', true);
const trendOrderShow = new Trend('endpoint_order_show', true);
const trendPublicOrderShow = new Trend('endpoint_public_order_show', true);
const trendPalletShow = new Trend('endpoint_pallet_show', true);

export const options = {
  vus: Number(__ENV.VUS) || 20,
  duration: __ENV.DURATION || '30s',
  thresholds: {
    // No fallan el build — son referencia visual en el resumen final.
    // Ajustar una vez que exista un primer baseline real.
    endpoint_orders_list: ['p(95)<2000'],
    endpoint_order_show: ['p(95)<2000'],
    endpoint_public_order_show: ['p(95)<2000'],
    endpoint_pallet_show: ['p(95)<2000'],
  },
};

export function setup() {
  if (!AUTH_EMAIL || !AUTH_PASSWORD) {
    throw new Error(
      'Faltan AUTH_EMAIL / AUTH_PASSWORD. Ver benchmarks/README.md — se necesita ' +
      'un usuario con rol asignado para /orders, /orders/{id} y /pallets/{id}.'
    );
  }

  const loginRes = http.post(
    `${BASE_URL}/api/v1/auth/login`,
    JSON.stringify({ email: AUTH_EMAIL, password: AUTH_PASSWORD }),
    { headers: { 'Content-Type': 'application/json' } }
  );

  check(loginRes, {
    'login devuelve 200': (r) => r.status === 200,
    'login devuelve token': (r) => !!r.json('token'),
  });

  if (loginRes.status !== 200) {
    throw new Error(`No se pudo autenticar (status ${loginRes.status}): ${loginRes.body}`);
  }

  return { token: loginRes.json('token') };
}

export default function (data) {
  const authHeaders = {
    headers: {
      Authorization: `Bearer ${data.token}`,
      Accept: 'application/json',
    },
  };

  // 1) GET /orders — listado paginado, el que más rows × relaciones combina.
  group('GET /api/v1/orders', () => {
    const res = http.get(`${BASE_URL}/api/v1/orders?limit=20`, authHeaders);
    trendOrdersList.add(res.timings.duration);
    check(res, { 'orders list 200': (r) => r.status === 200 });
  });

  // 2) GET /orders/{id} — el endpoint con más profundidad de eager loading
  //    del backend (pallets.bases.photos, items.bases.pallet, tickets.photos).
  group('GET /api/v1/orders/{id}', () => {
    const res = http.get(`${BASE_URL}/api/v1/orders/${ORDER_ID}`, authHeaders);
    trendOrderShow.add(res.timings.duration);
    check(res, { 'order show 200': (r) => r.status === 200 });
  });

  // 3) GET /public/orders/{code} — misma profundidad que (2) + más, sin auth,
  //    sin throttle. El más expuesto a volumen real/no controlado.
  group('GET /api/v1/public/orders/{code}', () => {
    const res = http.get(`${BASE_URL}/api/v1/public/orders/${ORDER_CODE}`, {
      headers: { Accept: 'application/json' },
    });
    trendPublicOrderShow.add(res.timings.duration);
    check(res, { 'public order show 200': (r) => r.status === 200 });
  });

  // 4) GET /pallets/{id} — misma familia de riesgo que (2), del lado del pallet.
  group('GET /api/v1/pallets/{id}', () => {
    const res = http.get(`${BASE_URL}/api/v1/pallets/${PALLET_ID}`, authHeaders);
    trendPalletShow.add(res.timings.duration);
    check(res, { 'pallet show 200': (r) => r.status === 200 });
  });
}
