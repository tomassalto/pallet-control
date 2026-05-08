import { lazy, Suspense } from "react";
import { Routes, Route, Navigate } from "react-router-dom";
import RequireAuth from "./auth/RequireAuth";
import SidebarLayout from "./ui/SidebarLayout";
import ScrollToTop from "./ui/ScrollToTop";
import { ToastContainer } from "react-toastify";
import "react-toastify/dist/ReactToastify.css";
import { useTheme } from "./context/ThemeContext";
import { PageSpinner } from "./ui/Spinner";

// Lazy-loaded pages — cada ruta carga su chunk solo cuando se navega a ella
const Home             = lazy(() => import("./pages/Home"));
const Login            = lazy(() => import("./pages/Login"));
const Register         = lazy(() => import("./pages/Register"));
const CreatePallet     = lazy(() => import("./pages/CreatePallet"));
const CreateOrder      = lazy(() => import("./pages/CreateOrder"));
const PalletDetail     = lazy(() => import("./pages/PalletDetail"));
const PalletGallery    = lazy(() => import("./pages/PalletGallery"));
const PalletHistory    = lazy(() => import("./pages/PalletHistory"));
const BaseProducts     = lazy(() => import("./pages/BaseProducts"));
const BaseGallery      = lazy(() => import("./pages/BaseGallery"));
const ImportOrder      = lazy(() => import("./pages/ImportOrder"));
const ProductLookup    = lazy(() => import("./pages/ProductLookup"));
const MyPallets        = lazy(() => import("./pages/MyPallets"));
const MyOrders         = lazy(() => import("./pages/MyOrders"));
const MyClients        = lazy(() => import("./pages/MyClients"));
const OrderDetail      = lazy(() => import("./pages/OrderDetail"));
const OrderHistory     = lazy(() => import("./pages/OrderHistory"));
const AllLogs          = lazy(() => import("./pages/AllLogs"));
const AdminUsers       = lazy(() => import("./pages/AdminUsers"));
const AdminStorage     = lazy(() => import("./pages/AdminStorage"));
const PalletPublicView = lazy(() => import("./pages/PalletPublicView"));
const PendingItems     = lazy(() => import("./pages/PendingItems"));

export default function App() {
  const { dark } = useTheme();

  return (
    <>
      <ScrollToTop />
      {/* Toast global — disponible en auth y en app */}
      <ToastContainer
        position="top-center"
        autoClose={3000}
        theme={dark ? "dark" : "light"}
        toastClassName="!rounded-xl !text-sm"
      />
      <Suspense fallback={<PageSpinner />}>
        <Routes>
          {/* ── Sin layout: páginas públicas y auth ────────────────────────── */}
          <Route path="/pallet-view/:code" element={<PalletPublicView />} />
          <Route path="/app/pallet-view/:code" element={<PalletPublicView />} />
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />

          {/* ── Con sidebar: rutas autenticadas ────────────────────────────── */}
          <Route
            path="*"
            element={
              <SidebarLayout title="Pallet Control">
                <Routes>
                  <Route path="/" element={<RequireAuth><Home /></RequireAuth>} />
                  <Route path="/pallets/new" element={<RequireAuth><CreatePallet /></RequireAuth>} />
                  <Route path="/orders/new" element={<RequireAuth><CreateOrder /></RequireAuth>} />
                  <Route path="/pallets" element={<RequireAuth><MyPallets /></RequireAuth>} />
                  <Route path="/orders" element={<RequireAuth><MyOrders /></RequireAuth>} />
                  <Route path="/clients" element={<RequireAuth><MyClients /></RequireAuth>} />
                  <Route path="/pallet/:palletId" element={<RequireAuth><PalletDetail /></RequireAuth>} />
                  <Route path="/pallet/:palletId/gallery" element={<RequireAuth><PalletGallery /></RequireAuth>} />
                  <Route path="/pallet/:palletId/history" element={<RequireAuth><PalletHistory /></RequireAuth>} />
                  <Route path="/pallet/:palletId/base/:baseId/products" element={<RequireAuth><BaseProducts /></RequireAuth>} />
                  <Route path="/pallet/:palletId/base/:baseId/gallery" element={<RequireAuth><BaseGallery /></RequireAuth>} />
                  <Route path="/order/:orderId/import" element={<RequireAuth><ImportOrder /></RequireAuth>} />
                  <Route path="/order/:orderId" element={<RequireAuth><OrderDetail /></RequireAuth>} />
                  <Route path="/order/:orderId/history" element={<RequireAuth><OrderHistory /></RequireAuth>} />
                  <Route path="/productos" element={<RequireAuth><ProductLookup /></RequireAuth>} />
                  <Route path="/logs" element={<RequireAuth><AllLogs /></RequireAuth>} />
                  <Route path="/pending-items" element={<RequireAuth><PendingItems /></RequireAuth>} />
                  <Route path="/admin/users" element={<RequireAuth><AdminUsers /></RequireAuth>} />
                  <Route path="/admin/storage" element={<RequireAuth><AdminStorage /></RequireAuth>} />
                  <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
              </SidebarLayout>
            }
          />
        </Routes>
      </Suspense>
    </>
  );
}
