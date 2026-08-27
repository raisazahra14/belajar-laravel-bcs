"""Verify Indonesian delivery notes using local OCR.

The command prints one JSON object to stdout so it can be called by Laravel.
User documents are processed in memory and are never written beside the source.
"""

from __future__ import annotations

import argparse
import json
import re
from pathlib import Path
from typing import Any

import pymupdf
import pytesseract
from PIL import Image, UnidentifiedImageError

ALLOWED_SUFFIXES = {".pdf", ".jpg", ".jpeg", ".png"}
MAX_FILE_SIZE = 10 * 1024 * 1024
DOCUMENT_TERMS = {
    "surat_jalan": ("surat jalan", "pengirim", "penerima"),
    "invoice": ("invoice", "total", "tanggal"),
    "bukti_fisik": ("bukti", "barang", "tanggal"),
}


class DocumentError(ValueError):
    """Raised when a document cannot be safely processed."""


def validate_file(path: Path) -> None:
    if not path.is_file():
        raise DocumentError("File dokumen tidak ditemukan.")
    if path.suffix.lower() not in ALLOWED_SUFFIXES:
        raise DocumentError("Format dokumen harus PDF, JPG, JPEG, atau PNG.")
    if path.stat().st_size == 0:
        raise DocumentError("File dokumen kosong.")
    if path.stat().st_size > MAX_FILE_SIZE:
        raise DocumentError("Ukuran dokumen melebihi 10 MB.")


def extract_text(path: Path) -> str:
    validate_file(path)
    try:
        if path.suffix.lower() == ".pdf":
            chunks: list[str] = []
            with pymupdf.open(path) as document:
                if document.page_count == 0:
                    raise DocumentError("PDF tidak memiliki halaman.")
                for page in document:
                    embedded = page.get_text("text").strip()
                    if embedded:
                        chunks.append(embedded)
                        continue
                    pixmap = page.get_pixmap(matrix=pymupdf.Matrix(2, 2), alpha=False)
                    image = Image.frombytes("RGB", (pixmap.width, pixmap.height), pixmap.samples)
                    chunks.append(pytesseract.image_to_string(image, lang="ind+eng"))
            return "\n".join(chunks).strip()

        with Image.open(path) as image:
            image.verify()
        with Image.open(path) as image:
            return pytesseract.image_to_string(image.convert("RGB"), lang="ind+eng").strip()
    except DocumentError:
        raise
    except (pymupdf.FileDataError, UnidentifiedImageError, OSError) as exc:
        raise DocumentError("File rusak atau tidak dapat dibaca.") from exc
    except pytesseract.TesseractNotFoundError as exc:
        raise DocumentError("Tesseract OCR belum terpasang atau tidak ditemukan.") from exc


def analyze_text(text: str, document_type: str = "surat_jalan") -> dict[str, Any]:
    normalized = re.sub(r"\s+", " ", text).strip()
    lowered = normalized.lower()
    required_terms = DOCUMENT_TERMS.get(document_type, DOCUMENT_TERMS["surat_jalan"])
    matched_terms = [term for term in required_terms if term in lowered]
    number_match = re.search(
        r"(?:no(?:mor)?\.?|nomor)\s*(?:surat\s*jalan)?\s*[:#-]?\s*([A-Z0-9][A-Z0-9./-]{2,})",
        normalized,
        re.IGNORECASE,
    )
    date_match = re.search(
        r"\b(\d{1,2}[/-]\d{1,2}[/-]\d{2,4}|\d{1,2}\s+(?:jan(?:uari)?|feb(?:ruari)?|mar(?:et)?|apr(?:il)?|mei|jun(?:i)?|jul(?:i)?|agu(?:stus)?|sep(?:tember)?|okt(?:ober)?|nov(?:ember)?|des(?:ember)?)\s+\d{4})\b",
        normalized,
        re.IGNORECASE,
    )
    signature_detected = bool(re.search(r"tanda\s*tangan|signature|ttd", lowered))
    signals = len(matched_terms) + bool(number_match) + bool(date_match) + signature_detected
    score = round(signals / (len(required_terms) + 3) * 100)
    if score >= 80:
        status = "valid"
        message = "Dokumen memenuhi sebagian besar indikator validitas."
    elif score >= 50:
        status = "review"
        message = "Dokumen memerlukan peninjauan lebih lanjut."
    else:
        status = "suspicious"
        message = "Indikator dokumen belum cukup untuk dinyatakan valid."

    return {
        "status": status,
        "score": score,
        "message": message,
        "details": {
            "text_detected": bool(normalized),
            "document_number_detected": bool(number_match),
            "date_detected": bool(date_match),
            "signature_detected": signature_detected,
            "document_number": number_match.group(1) if number_match else None,
            "date": date_match.group(1) if date_match else None,
            "matched_terms": matched_terms,
        },
    }


def verify_document(path: Path, document_type: str = "surat_jalan") -> dict[str, Any]:
    text = extract_text(path)
    if not text:
        raise DocumentError("Teks tidak terdeteksi pada dokumen.")
    return analyze_text(text, document_type)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Verifikasi surat jalan dengan OCR lokal.")
    parser.add_argument("document", type=Path)
    parser.add_argument("--document-type", choices=DOCUMENT_TERMS, default="surat_jalan")
    args = parser.parse_args(argv)
    try:
        print(json.dumps(verify_document(args.document, args.document_type), ensure_ascii=False))
        return 0
    except DocumentError as exc:
        print(json.dumps({
            "status": "failed",
            "score": 0,
            "message": str(exc),
            "details": {"text_detected": False},
        }, ensure_ascii=False))
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
