/**
 * HighlightOverlay — mapa de distribución sobre la foto del ticket.
 *
 * Desktop: tooltip inline al hover/click sobre el highlight.
 * Mobile:  al tocar un highlight, abre un modal centrado con el desglose;
 *          mientras el modal está abierto el zoom/pan queda bloqueado.
 * Zoom: pinch (móvil) + scroll (desktop) + drag pan cuando scale > 1.
 *
 * Soporte dark/light: el área de foto siempre oscura (lightbox), pero
 * header, leyenda y modal se adaptan con dark: classes.
 */

import { useCallback, useEffect, useRef, useState } from "react";
import { apiGet } from "../../api/client";

const PALLET_COLORS = ["#3B82F6", "#F97316", "#A855F7", "#10B981", "#EC4899"];
const SPLIT_COLOR   = "#6B7280";

function palletColor(ci) {
  return PALLET_COLORS[ci % PALLET_COLORS.length] ?? SPLIT_COLOR;
}
function highlightColor(h) {
  if (h.is_split) return SPLIT_COLOR;
  return palletColor(h.pallet_color_index);
}

// ── Modal centrado — solo mobile ───────────────────────────────────────────────

function MobileDetailModal({ selected, onClose }) {
  if (!selected) return null;
  const h     = selected;
  const color = highlightColor(h);
  const totalDist = h.pallet_breakdown.reduce((s, b) => s + b.qty, 0);

  return (
    <div
      className="fixed inset-0 z-[70] flex items-center justify-center px-5"
      style={{ backgroundColor: "rgba(0,0,0,0.72)" }}
      onClick={onClose}
    >
      <div
        className="bg-white dark:bg-gray-900 rounded-2xl w-full max-w-sm border border-gray-200 dark:border-white/10 shadow-2xl overflow-hidden"
        onClick={(e) => e.stopPropagation()}
      >
        {/* ── Header ── */}
        <div className="flex items-start gap-3 px-5 pt-5 pb-4 border-b border-gray-100 dark:border-white/10">
          <div
            className="mt-1 w-3 h-3 rounded-full shrink-0"
            style={{ backgroundColor: color }}
          />
          <div className="flex-1 min-w-0">
            <p className="text-gray-900 dark:text-white font-bold text-base leading-snug">
              {h.description}
            </p>
            <p className="text-gray-400 dark:text-white/40 text-xs font-mono mt-0.5">
              {h.ean}
            </p>
          </div>
          <button
            onClick={onClose}
            className="shrink-0 w-8 h-8 rounded-full bg-gray-100 dark:bg-white/10 flex items-center justify-center text-gray-400 dark:text-white/50 hover:text-gray-600 dark:hover:text-white/80 text-sm transition-colors"
          >
            ✕
          </button>
        </div>

        {/* ── Desglose ── */}
        <div className="px-5 py-4 space-y-2">
          {h.is_split ? (
            <>
              {h.pallet_breakdown.map((b, i) => {
                const c = palletColor(b.color_index);
                return (
                  <div
                    key={i}
                    className="flex items-center gap-3 bg-gray-50 dark:bg-white/5 rounded-xl px-4 py-3"
                  >
                    <div
                      className="w-2.5 h-2.5 rounded-full shrink-0"
                      style={{ backgroundColor: c }}
                    />
                    <span className="text-gray-600 dark:text-white/60 text-sm flex-1 truncate">
                      {b.pallet_code}
                    </span>
                    <span className="text-gray-900 dark:text-white font-bold text-sm shrink-0">
                      {b.qty} u.
                    </span>
                  </div>
                );
              })}
              {totalDist < h.qty_order && (
                <p className="text-center text-gray-400 dark:text-white/30 text-xs pt-0.5">
                  Total en pedido: {h.qty_order} u.
                </p>
              )}
            </>
          ) : (
            <div className="flex items-center gap-3 bg-gray-50 dark:bg-white/5 rounded-xl px-4 py-3">
              <div
                className="w-2.5 h-2.5 rounded-full shrink-0"
                style={{ backgroundColor: color }}
              />
              <div className="flex-1 min-w-0">
                <p className="text-gray-600 dark:text-white/60 text-sm truncate">
                  {h.pallet_breakdown[0]?.pallet_code}
                </p>
                {h.pallet_breakdown[0]?.base_name && (
                  <p className="text-gray-400 dark:text-white/30 text-xs mt-0.5 truncate">
                    {h.pallet_breakdown[0].base_name}
                  </p>
                )}
              </div>
              <div className="text-right shrink-0">
                <p className="text-gray-900 dark:text-white font-bold text-sm">
                  {h.pallet_breakdown[0]?.qty ?? h.qty_order} u.
                </p>
                {(h.pallet_breakdown[0]?.qty ?? h.qty_order) < h.qty_order && (
                  <p className="text-gray-400 dark:text-white/40 text-xs mt-0.5">
                    de {h.qty_order} en pedido
                  </p>
                )}
              </div>
            </div>
          )}
        </div>

        {/* ── Botón cerrar ── */}
        <div className="px-5 pb-5">
          <button
            onClick={onClose}
            className="w-full py-3 rounded-xl bg-gray-100 hover:bg-gray-200 dark:bg-white/10 dark:hover:bg-white/15 active:scale-[0.99] text-gray-700 dark:text-white text-sm font-semibold transition-colors"
          >
            Cerrar
          </button>
        </div>
      </div>
    </div>
  );
}

// ── Panel inferior: leyenda compacta ───────────────────────────────────────────

function LegendPanel({ pallets, hasSplit }) {
  return (
    <div
      className="flex items-center gap-3 px-4 py-3 overflow-x-auto"
      style={{ scrollbarWidth: "none" }}
    >
      {pallets.map((p) => {
        const c = palletColor(p.color_index);
        return (
          <div key={p.id} className="flex items-center gap-1.5 shrink-0">
            <div className="w-2 h-2 rounded-full" style={{ backgroundColor: c }} />
            <span className="text-gray-600 dark:text-white/60 text-[11px] whitespace-nowrap">
              {p.code}
            </span>
            <span className="text-gray-400 dark:text-white/25 text-[10px]">
              {p.total_qty} u.
            </span>
          </div>
        );
      })}
      {hasSplit && (
        <div className="flex items-center gap-1.5 shrink-0">
          <div className="w-2 h-2 rounded-full" style={{ backgroundColor: SPLIT_COLOR }} />
          <span className="text-gray-400 dark:text-white/40 text-[11px]">Dividido</span>
        </div>
      )}
      <span className="text-gray-300 dark:text-white/20 text-[9px] ml-auto shrink-0">
        Tocá para ver detalle
      </span>
    </div>
  );
}

// ── Panel inferior desktop: leyenda | detalle del highlight seleccionado ───────

function DesktopBottomPanel({ selected, pallets, hasSplit, onClear }) {
  if (selected) {
    const h     = selected;
    const color = highlightColor(h);
    const totalDist = h.pallet_breakdown.reduce((s, b) => s + b.qty, 0);
    return (
      <div className="px-4 py-3 space-y-1.5">
        <div className="flex items-start gap-2">
          <div className="mt-1 w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: color }} />
          <div className="flex-1 min-w-0">
            <p className="text-gray-900 dark:text-white text-[13px] font-semibold leading-tight">
              {h.description}
            </p>
            <p className="text-gray-400 dark:text-white/40 text-[10px] font-mono">{h.ean}</p>
          </div>
          <button
            onClick={onClear}
            className="shrink-0 text-gray-400 dark:text-white/30 hover:text-gray-600 dark:hover:text-white/70 text-lg leading-none transition-colors"
          >
            ✕
          </button>
        </div>
        <div className="flex flex-wrap gap-x-4 gap-y-1 pl-4">
          {h.pallet_breakdown.map((b, i) => {
            const c = palletColor(b.color_index);
            return (
              <div key={i} className="flex items-center gap-1.5">
                <div className="w-2 h-2 rounded-full shrink-0" style={{ backgroundColor: c }} />
                <span className="text-gray-500 dark:text-white/60 text-xs">{b.pallet_code}</span>
                <span className="text-gray-900 dark:text-white text-xs font-bold">{b.qty} u.</span>
              </div>
            );
          })}
        </div>
        {totalDist < h.qty_order && (
          <p className="pl-4 text-gray-400 dark:text-white/30 text-[10px]">
            {totalDist} de {h.qty_order} u. en pedido
          </p>
        )}
      </div>
    );
  }
  return <LegendPanel pallets={pallets} hasSplit={hasSplit} />;
}

// ── Viewer principal ───────────────────────────────────────────────────────────

export default function HighlightOverlay({ highlightsUrl, photoUrl, onClose }) {
  const [loading,   setLoading]   = useState(true);
  const [error,     setError]     = useState(null);
  const [data,      setData]      = useState(null);
  const [imgLoaded, setImgLoaded] = useState(false);
  const [selected,  setSelected]  = useState(null);

  // ── Detección mobile (se actualiza en resize) ──────────────────
  const [isMobile, setIsMobile] = useState(() => window.innerWidth < 768);
  useEffect(() => {
    const handler = () => setIsMobile(window.innerWidth < 768);
    window.addEventListener("resize", handler);
    return () => window.removeEventListener("resize", handler);
  }, []);

  // Refs para closures en useEffect (evita stale values)
  const isMobileRef = useRef(isMobile);
  const selectedRef = useRef(selected);
  useEffect(() => { isMobileRef.current = isMobile; }, [isMobile]);
  useEffect(() => { selectedRef.current = selected; }, [selected]);

  // ── Zoom / pan ─────────────────────────────────────────────────
  const [scale,      setScale]     = useState(1);
  const [position,   setPosition]  = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging]= useState(false);
  const dragStart  = useRef({ x: 0, y: 0 });
  const pinchStart = useRef({ dist: 0, scale: 1 });
  const didDrag    = useRef(false);
  const areaRef    = useRef(null);

  // Callback ref: si la imagen ya estaba cacheada, onLoad no dispara.
  // Al montar el <img> (cuando data.ready llega), verificar .complete directamente.
  const imgCallbackRef = useCallback((node) => {
    if (node?.complete) setImgLoaded(true);
  }, []);

  // ── Cargar datos ───────────────────────────────────────────────
  useEffect(() => {
    setLoading(true); setError(null); setData(null);
    setImgLoaded(false);
    setScale(1); setPosition({ x: 0, y: 0 }); setSelected(null);
    apiGet(highlightsUrl)
      .then(setData)
      .catch((e) => setError(e?.response?.data?.message || e.message || "Error"))
      .finally(() => setLoading(false));
  }, [highlightsUrl]);

  // ── Listeners passive:false (touchmove + wheel) ────────────────
  useEffect(() => {
    const el = areaRef.current;
    if (!el) return;

    function onTouchMove(e) {
      if (isMobileRef.current && selectedRef.current) return;
      if (e.touches.length >= 2 || (e.touches.length === 1 && scale > 1)) {
        e.preventDefault();
      }
    }
    function onWheel(e) {
      e.preventDefault();
      const factor = e.deltaY > 0 ? 0.9 : 1.1;
      setScale((s) => {
        const ns = Math.max(1, Math.min(5, s * factor));
        if (ns === 1) setPosition({ x: 0, y: 0 });
        return ns;
      });
    }

    el.addEventListener("touchmove", onTouchMove, { passive: false });
    el.addEventListener("wheel",     onWheel,     { passive: false });
    return () => {
      el.removeEventListener("touchmove", onTouchMove);
      el.removeEventListener("wheel",     onWheel);
    };
  }, [scale]);

  // ── Touch handlers ─────────────────────────────────────────────
  function onTouchStart(e) {
    if (isMobile && selected) return;
    if (e.touches.length === 2) {
      const d = Math.hypot(
        e.touches[1].clientX - e.touches[0].clientX,
        e.touches[1].clientY - e.touches[0].clientY,
      );
      pinchStart.current = { dist: d, scale };
    } else if (e.touches.length === 1) {
      didDrag.current = false;
      dragStart.current = {
        x: e.touches[0].clientX - position.x,
        y: e.touches[0].clientY - position.y,
      };
      if (scale > 1) setIsDragging(true);
    }
  }

  function onTouchMoveHandler(e) {
    if (isMobile && selected) return;
    if (e.touches.length === 2) {
      const d = Math.hypot(
        e.touches[1].clientX - e.touches[0].clientX,
        e.touches[1].clientY - e.touches[0].clientY,
      );
      const ns = Math.max(
        1, Math.min(5, (d / pinchStart.current.dist) * pinchStart.current.scale),
      );
      setScale(ns);
      if (ns === 1) setPosition({ x: 0, y: 0 });
    } else if (e.touches.length === 1 && scale > 1) {
      didDrag.current = true;
      setPosition({
        x: e.touches[0].clientX - dragStart.current.x,
        y: e.touches[0].clientY - dragStart.current.y,
      });
    }
  }

  function onTouchEnd() { setIsDragging(false); }

  // ── Mouse handlers (desktop pan) ───────────────────────────────
  function onMouseDown(e) {
    if (scale <= 1) return;
    didDrag.current = false;
    setIsDragging(true);
    dragStart.current = { x: e.clientX - position.x, y: e.clientY - position.y };
  }
  function onMouseMove(e) {
    if (!isDragging) return;
    didDrag.current = true;
    setPosition({ x: e.clientX - dragStart.current.x, y: e.clientY - dragStart.current.y });
  }
  function onMouseUp() { setIsDragging(false); }

  // ── Doble click: zoom ↔ reset ───────────────────────────────────
  function onDoubleClick() {
    if (scale > 1) { setScale(1); setPosition({ x: 0, y: 0 }); }
    else setScale(2.5);
  }

  // ── Highlights filtrados ────────────────────────────────────────
  const highlights = data?.highlights?.filter((h) => !h.not_in_order) ?? [];
  const hasSplit   = highlights.some((h) => h.is_split);

  return (
    <div
      className="fixed inset-0 z-50 bg-black flex flex-col"
      style={{ touchAction: "none" }}
    >
      {/* ── Header ─────────────────────────────────────────────── */}
      <div className="shrink-0 h-12 flex items-center gap-2 px-4 bg-white dark:bg-transparent border-b border-gray-200 dark:border-white/5">
        <p className="flex-1 text-gray-800 dark:text-white/80 text-sm font-semibold">
          🗺 Mapa de distribución
        </p>
        {scale > 1 && (
          <button
            onClick={() => { setScale(1); setPosition({ x: 0, y: 0 }); }}
            className="text-[11px] text-gray-500 dark:text-white/50 hover:text-gray-700 dark:hover:text-white/80 border border-gray-300 dark:border-white/15 rounded-lg px-2 py-0.5 transition-colors"
          >
            {Math.round(scale * 100)}% · Resetear
          </button>
        )}
        <button
          onClick={onClose}
          className="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 dark:bg-white/10 dark:hover:bg-white/20 flex items-center justify-center text-gray-500 dark:text-white/70 text-base leading-none transition-colors"
        >
          ✕
        </button>
      </div>

      {/* ── Área de imagen — siempre oscura (lightbox) ─────────── */}
      <div
        ref={areaRef}
        className="flex-1 flex items-center justify-center overflow-hidden relative bg-black"
        style={{
          touchAction: "none",
          cursor: scale > 1 ? (isDragging ? "grabbing" : "grab") : "default",
        }}
        onTouchStart={onTouchStart}
        onTouchMove={onTouchMoveHandler}
        onTouchEnd={onTouchEnd}
        onMouseDown={onMouseDown}
        onMouseMove={onMouseMove}
        onMouseUp={onMouseUp}
        onMouseLeave={onMouseUp}
        onDoubleClick={onDoubleClick}
      >
        {loading && (
          <div className="flex flex-col items-center gap-2">
            <div className="w-6 h-6 rounded-full border-2 border-white/20 border-t-white/70 animate-spin" />
            <span className="text-white/40 text-xs">Cargando mapa…</span>
          </div>
        )}

        {error && (
          <div className="text-center p-8 max-w-xs">
            <p className="text-red-400 text-sm font-semibold mb-1">Error</p>
            <p className="text-white/40 text-xs">{error}</p>
          </div>
        )}

        {!loading && data?.ready === false && (
          <div className="text-center p-8 max-w-xs">
            <p className="text-amber-400 text-sm font-semibold mb-1">No disponible</p>
            <p className="text-white/40 text-xs leading-relaxed">{data.reason}</p>
          </div>
        )}

        {/* Imagen + highlights — wrapper inline para que escalen juntos */}
        {!loading && data?.ready && (
          <div
            style={{
              position: "relative",
              display: "inline-block",
              transform: `scale(${scale}) translate(${position.x / scale}px, ${position.y / scale}px)`,
              transformOrigin: "center center",
              transition: isDragging ? "none" : "transform 0.1s ease-out",
            }}
          >
            <img
              ref={imgCallbackRef}
              src={photoUrl}
              alt="Ticket"
              style={{
                display: "block",
                maxWidth: "100vw",
                maxHeight: "calc(100vh - 120px)",
              }}
              draggable={false}
              onLoad={() => setImgLoaded(true)}
            />

            {imgLoaded &&
              highlights.map((h, i) => {
                if (!h.bbox || !h.img_w || !h.img_h) return null;

                const left   = (h.bbox.left  / h.img_w) * 100;
                const top    = (h.bbox.top   / h.img_h) * 100;
                const width  = ((h.bbox.right  - h.bbox.left) / h.img_w) * 100;
                const height = ((h.bbox.bottom - h.bbox.top)  / h.img_h) * 100;
                const color  = highlightColor(h);
                const isOpen = !isMobile && selected?.ean === h.ean;

                return (
                  <div key={i}>
                    {/* Caja de highlight */}
                    <div
                      style={{
                        position: "absolute",
                        left:            `${left}%`,
                        top:             `${top}%`,
                        width:           `${width}%`,
                        height:          `${height}%`,
                        border:          `2px solid ${color}`,
                        backgroundColor: (isOpen || (isMobile && selected?.ean === h.ean))
                          ? `${color}45`
                          : `${color}28`,
                        borderRadius:    4,
                        cursor:          "pointer",
                        boxSizing:       "border-box",
                        transition:      "background-color 0.15s",
                      }}
                      onMouseEnter={() => {
                        if (isMobile) return;
                        if (!didDrag.current) setSelected(h);
                      }}
                      onMouseLeave={() => {
                        if (isMobile) return;
                        setSelected((s) => (s?.ean === h.ean ? null : s));
                      }}
                      onClick={(e) => {
                        e.stopPropagation();
                        if (didDrag.current) return;
                        setSelected((s) => (s?.ean === h.ean ? null : h));
                      }}
                    />

                    {/* Tooltip inline — solo desktop, siempre oscuro (sobre foto) */}
                    {isOpen && (
                      <div
                        style={{
                          position:      "absolute",
                          left:          `${Math.min(left + width + 1, 52)}%`,
                          top:           `${top}%`,
                          zIndex:        20,
                          maxWidth:      190,
                          pointerEvents: "none",
                        }}
                        className="bg-gray-900 text-white text-xs rounded-xl px-3 py-2 shadow-2xl border border-white/10"
                      >
                        <p className="font-semibold leading-snug mb-1.5">
                          {h.description}
                        </p>
                        {h.is_split ? (
                          <div className="space-y-1">
                            {h.pallet_breakdown.map((b, bi) => {
                              const c = palletColor(b.color_index);
                              return (
                                <div key={bi} className="flex items-center gap-1.5">
                                  <div
                                    className="w-1.5 h-1.5 rounded-full shrink-0"
                                    style={{ backgroundColor: c }}
                                  />
                                  <span style={{ color: c }} className="font-bold">
                                    {b.qty} u.
                                  </span>
                                  <span className="text-gray-400 text-[10px] truncate">
                                    {b.pallet_code}
                                  </span>
                                </div>
                              );
                            })}
                            <p className="text-gray-500 text-[10px] border-t border-white/10 pt-1">
                              Total: {h.qty_order} u. en pedido
                            </p>
                          </div>
                        ) : (
                          <>
                            <p style={{ color }} className="font-bold">
                              {h.pallet_breakdown[0]?.qty ?? h.qty_order} u.
                              {(h.pallet_breakdown[0]?.qty ?? h.qty_order) < h.qty_order && (
                                <span className="text-gray-400 font-normal">
                                  {" "}de {h.qty_order}
                                </span>
                              )}
                            </p>
                            <p className="text-gray-400 text-[10px] mt-0.5 truncate">
                              {h.pallet_breakdown[0]?.pallet_code}
                              {h.pallet_breakdown[0]?.base_name && (
                                <span className="text-gray-600">
                                  {" "}/ {h.pallet_breakdown[0].base_name}
                                </span>
                              )}
                            </p>
                          </>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
          </div>
        )}
      </div>

      {/* ── Panel inferior ─────────────────────────────────────── */}
      {!loading && data?.ready && (
        <div className="shrink-0 border-t border-gray-200 dark:border-white/5 bg-white/95 dark:bg-black/80 backdrop-blur-sm">
          {isMobile ? (
            <LegendPanel pallets={data.pallets} hasSplit={hasSplit} />
          ) : (
            <DesktopBottomPanel
              selected={selected}
              pallets={data.pallets}
              hasSplit={hasSplit}
              onClear={() => setSelected(null)}
            />
          )}
        </div>
      )}

      {/* ── Modal centrado (mobile only) ───────────────────────── */}
      {isMobile && (
        <MobileDetailModal
          selected={selected}
          onClose={() => setSelected(null)}
        />
      )}
    </div>
  );
}
