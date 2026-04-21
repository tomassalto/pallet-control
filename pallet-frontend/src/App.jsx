import { Routes, Route, Navigate } from "react-router-dom";
import RequireAuth from "./auth/RequireAuth";
import SidebarLayout from "./ui/SidebarLayout";
import { ToastContainer } from "react-toastify";
import "react-toastify/dist/ReactToastify.css";
import Home from "./pages/Home";
import Login from "./pages/Login";
import Register from "./pages/Register";
import CreatePallet from "./pages/CreatePallet";
import CreateOrder from "./pages/CreateOrder";
import PalletDetail from "./pages/PalletDetail";
import PalletGallery from "./pages/PalletGallery";
import PalletHistory from "./pages/PalletHistory";
import BaseProducts from "./pages/BaseProducts";
import BaseGallery from "./pages/BaseGallery";
import ImportOrder from "./pages/ImportOrder";
import ProductLookup from "./pages/ProductLookup"; // si existe
import MyPallets from "./pages/MyPallets";
import MyOrders from "./pages/MyOrders";
import MyClients from "./pages/MyClients";
import OrderDetail from "./pages/OrderDetail";
import OrderHistory from "./pages/OrderHistory";
import AllLogs from "./pages/AllLogs";
import AdminUsers from "./pages/AdminUsers";

export default function App() {
  return (
    <>
      <SidebarLayout title="Pallet Control">
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route
            path="/"
            element={
              <RequireAuth>
                <Home />
              </RequireAuth>
            }
          />
          <Route
            path="/pallets/new"
            element={
              <RequireAuth>
                <CreatePallet />
              </RequireAuth>
            }
          />

          <Route
            path="/orders/new"
            element={
              <RequireAuth>
                <CreateOrder />
              </RequireAuth>
            }
          />

          <Route
            path="/pallets"
            element={
              <RequireAuth>
                <MyPallets />
              </RequireAuth>
            }
          />

          <Route
            path="/orders"
            element={
              <RequireAuth>
                <MyOrders />
              </RequireAuth>
            }
          />

          <Route
            path="/clients"
            element={
              <RequireAuth>
                <MyClients />
              </RequireAuth>
            }
          />

          <Route
            path="/pallet/:palletId"
            element={
              <RequireAuth>
                <PalletDetail />
              </RequireAuth>
            }
          />
          <Route
            path="/pallet/:palletId/gallery"
            element={
              <RequireAuth>
                <PalletGallery />
              </RequireAuth>
            }
          />
          <Route
            path="/pallet/:palletId/history"
            element={
              <RequireAuth>
                <PalletHistory />
              </RequireAuth>
            }
          />
          <Route
            path="/pallet/:palletId/base/:baseId/products"
            element={
              <RequireAuth>
                <BaseProducts />
              </RequireAuth>
            }
          />
          <Route
            path="/pallet/:palletId/base/:baseId/gallery"
            element={
              <RequireAuth>
                <BaseGallery />
              </RequireAuth>
            }
          />
          <Route
            path="/order/:orderId/import"
            element={
              <RequireAuth>
                <ImportOrder />
              </RequireAuth>
            }
          />

          <Route
            path="/order/:orderId"
            element={
              <RequireAuth>
                <OrderDetail />
              </RequireAuth>
            }
          />
          <Route
            path="/order/:orderId/history"
            element={
              <RequireAuth>
                <OrderHistory />
              </RequireAuth>
            }
          />

          <Route
            path="/productos"
            element={
              <RequireAuth>
                <ProductLookup />
              </RequireAuth>
            }
          />

          <Route
            path="/logs"
            element={
              <RequireAuth>
                <AllLogs />
              </RequireAuth>
            }
          />

          <Route
            path="/admin/users"
            element={
              <RequireAuth>
                <AdminUsers />
              </RequireAuth>
            }
          />

          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
        <ToastContainer />
      </SidebarLayout>
    </>
  );
}
