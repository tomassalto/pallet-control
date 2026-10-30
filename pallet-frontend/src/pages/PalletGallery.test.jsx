import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { ToastContainer } from "react-toastify";
import PalletGallery from "./PalletGallery";

function json(body, status = 200) {
  return Promise.resolve(
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    }),
  );
}

function renderGallery() {
  const { container } = render(
    <MemoryRouter initialEntries={["/pallets/5/gallery"]}>
      <Routes>
        <Route path="/pallets/:palletId/gallery" element={<PalletGallery />} />
      </Routes>
      <ToastContainer />
    </MemoryRouter>,
  );
  return container;
}

beforeEach(() => {
  localStorage.setItem("token", "tok-test");
  vi.stubGlobal("fetch", vi.fn());
});

describe("PalletGallery — subida de foto", () => {
  it("sube el archivo como FormData en el campo 'photo' y recarga", async () => {
    const user = userEvent.setup();
    fetch.mockImplementation((url, options) => {
      if (url === "/api/v1/pallets/5") {
        return json({ pallet: { id: 5, code: "PAL-X", status: "open" }, photos: [] });
      }
      if (url === "/api/v1/pallets/5/photos" && options.method === "POST") {
        return json({ photo: { id: 9 }, url: "/u/9.webp" }, 201);
      }
      return json({ message: "nf" }, 404);
    });

    const container = renderGallery();
    await screen.findByText("Agregar foto");

    const input = container.querySelector('input[type="file"]');
    await user.upload(input, new File(["img"], "foto.jpg", { type: "image/jpeg" }));

    await waitFor(() => {
      const call = fetch.mock.calls.find(
        ([url]) => url === "/api/v1/pallets/5/photos",
      );
      expect(call[1].method).toBe("POST");
      expect(call[1].body).toBeInstanceOf(FormData);
      expect(call[1].body.get("photo")).toBeInstanceOf(File);
      expect(call[1].body.get("photo").name).toBe("foto.jpg");
    });
    expect(await screen.findByText("Foto subida")).toBeTruthy();
  });

  it("un 422 del backend muestra su mensaje al usuario", async () => {
    const user = userEvent.setup();
    fetch.mockImplementation((url, options) => {
      if (url === "/api/v1/pallets/5") {
        return json({ pallet: { id: 5, code: "PAL-X", status: "open" }, photos: [] });
      }
      if (url === "/api/v1/pallets/5/photos" && options.method === "POST") {
        return json(
          { message: "Formato no permitido. Usá JPG, PNG o WEBP.", errors: { photo: ["Formato no permitido. Usá JPG, PNG o WEBP."] } },
          422,
        );
      }
      return json({ message: "nf" }, 404);
    });

    const container = renderGallery();
    await screen.findByText("Agregar foto");

    const input = container.querySelector('input[type="file"]');
    await user.upload(input, new File(["x"], "foto.gif", { type: "image/gif" }));

    expect(
      await screen.findByText("Formato no permitido. Usá JPG, PNG o WEBP."),
    ).toBeTruthy();
  });
});
