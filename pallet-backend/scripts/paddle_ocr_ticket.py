#!/usr/bin/env python3
"""
Run PaddleOCR over a ticket image and return normalized JSON.

Expected stdout:
{
  "ok": true,
  "engine": "paddleocr",
  "img_w": 705,
  "img_h": 765,
  "lines": [
    {
      "text": "7792798003716 CERVEZA ...",
      "confidence": 0.93,
      "bbox": {"left": 10, "top": 20, "right": 300, "bottom": 45},
      "polygon": [[10,20], [300,22], [298,45], [11,43]]
    }
  ]
}
"""

from __future__ import annotations

import argparse
import contextlib
import json
import os
import sys
from pathlib import Path

os.environ.setdefault("FLAGS_use_mkldnn", "0")
os.environ.setdefault("FLAGS_use_onednn", "0")
os.environ.setdefault("FLAGS_enable_pir_api", "0")
os.environ.setdefault("PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK", "True")


def image_size(path: Path) -> tuple[int, int]:
    try:
        from PIL import Image

        with Image.open(path) as img:
            return img.size
    except Exception:
        return (0, 0)


def normalize_point(point) -> list[float]:
    return [float(point[0]), float(point[1])]


def normalize_box(box) -> tuple[dict, list[list[float]]]:
    polygon = [normalize_point(p) for p in box]
    xs = [p[0] for p in polygon]
    ys = [p[1] for p in polygon]

    return (
        {
            "left": int(round(min(xs))),
            "top": int(round(min(ys))),
            "right": int(round(max(xs))),
            "bottom": int(round(max(ys))),
        },
        polygon,
    )


def parse_result(result) -> list[dict]:
    lines: list[dict] = []

    # PaddleOCR 3.x returns one dict per page with parallel arrays.
    if isinstance(result, list) and result and isinstance(result[0], dict):
        for page in result:
            texts = page.get("rec_texts") or []
            scores = page.get("rec_scores") or []
            polys = page.get("rec_polys")
            if polys is None:
                polys = page.get("dt_polys")
            if polys is None:
                polys = []
            boxes = page.get("rec_boxes")
            if boxes is None:
                boxes = []

            for idx, text in enumerate(texts):
                try:
                    confidence = float(scores[idx]) if idx < len(scores) else None

                    if idx < len(polys):
                        bbox, polygon = normalize_box(polys[idx])
                    elif idx < len(boxes):
                        left, top, right, bottom = [float(v) for v in boxes[idx]]
                        bbox = {
                            "left": int(round(left)),
                            "top": int(round(top)),
                            "right": int(round(right)),
                            "bottom": int(round(bottom)),
                        }
                        polygon = [[left, top], [right, top], [right, bottom], [left, bottom]]
                    else:
                        continue
                except Exception:
                    continue

                lines.append(
                    {
                        "text": str(text),
                        "confidence": confidence,
                        "bbox": bbox,
                        "polygon": polygon,
                    }
                )

        return lines

    # PaddleOCR 2.x usually returns: [ [ [box, (text, score)], ... ] ]
    # Some builds return: [ [box, (text, score)], ... ]
    pages = result if isinstance(result, list) else []
    if pages and pages[0] and isinstance(pages[0], list) and len(pages[0]) == 2:
        pages = [pages]

    for page in pages:
        if not page:
            continue

        for entry in page:
            try:
                box = entry[0]
                rec = entry[1]
                text = str(rec[0])
                confidence = float(rec[1])
            except Exception:
                continue

            bbox, polygon = normalize_box(box)
            lines.append(
                {
                    "text": text,
                    "confidence": confidence,
                    "bbox": bbox,
                    "polygon": polygon,
                }
            )

    return lines


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("image", help="Path to the image to OCR")
    parser.add_argument("--lang", default="en")
    parser.add_argument("--no-angle-cls", action="store_true")
    args = parser.parse_args()

    image_path = Path(args.image)
    if not image_path.exists():
        print(json.dumps({"ok": False, "error": f"Image not found: {image_path}"}))
        return 2

    try:
        from paddleocr import PaddleOCR
    except Exception as exc:
        print(
            json.dumps(
                {
                    "ok": False,
                    "error": "PaddleOCR is not installed. Install with: pip install paddleocr",
                    "detail": str(exc),
                }
            )
        )
        return 3

    try:
        with contextlib.redirect_stdout(sys.stderr):
            try:
                ocr = PaddleOCR(
                    use_doc_orientation_classify=False,
                    use_doc_unwarping=False,
                    use_textline_orientation=not args.no_angle_cls,
                    lang=args.lang,
                )
                result = ocr.ocr(str(image_path))
            except TypeError:
                # PaddleOCR 2.x compatibility.
                ocr = PaddleOCR(
                    use_angle_cls=not args.no_angle_cls,
                    lang=args.lang,
                )
                result = ocr.ocr(str(image_path), cls=not args.no_angle_cls)
        width, height = image_size(image_path)

        print(
            json.dumps(
                {
                    "ok": True,
                    "engine": "paddleocr",
                    "img_w": width,
                    "img_h": height,
                    "lines": parse_result(result),
                },
                ensure_ascii=True,
            )
        )
        return 0
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}))
        return 1


if __name__ == "__main__":
    sys.exit(main())
