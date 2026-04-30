# OCR gratis con PaddleOCR

PaddleOCR se usa como alternativa gratuita a Tesseract para fotos de tickets/remitos con inclinacion, bajo contraste o perspectiva.

## Instalacion local

Desde `pallet-backend/`:

```bash
python -m venv .venv-ocr
.venv-ocr\Scripts\activate
pip install -r requirements-ocr.txt
```

En `.env`:

```env
OCR_PROVIDER=paddle
OCR_FALLBACK_TESSERACT=true
OCR_PYTHON_BIN=C:\xampp\htdocs\pallet control\pallet-backend\.venv-ocr\Scripts\python.exe
PADDLE_OCR_LANG=en
```

La primera ejecucion puede tardar porque PaddleOCR descarga sus modelos.

## Prueba manual

```bash
python scripts/paddle_ocr_ticket.py "C:\ruta\a\ticket.png"
```

El script devuelve JSON con `lines`, `bbox` y `polygon`. Laravel busca EAN-13 validos dentro de esas lineas y guarda los resultados en `ocr_data`.

## Nota de deploy

PaddleOCR es mas pesado que Tesseract. En Docker/Render conviene usar una imagen Debian/Ubuntu con Python en vez de Alpine si se quiere activar `OCR_PROVIDER=paddle` en produccion.
