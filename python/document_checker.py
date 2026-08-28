"""Verify Indonesian delivery notes using local OCR.

The command prints one JSON object to stdout so it can be called by Laravel.
User documents are processed in memory and are never written beside the source.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
from pathlib import Path
from typing import Any

import pymupdf
import pytesseract
from PIL import Image, ImageFilter, ImageOps, UnidentifiedImageError

ALLOWED_SUFFIXES = {".pdf", ".jpg", ".jpeg", ".png"}
MAX_FILE_SIZE = 10 * 1024 * 1024
DOCUMENT_TERMS = {
    "surat_jalan": ("surat jalan", "pengirim", "penerima"),
    "invoice": ("invoice", "total", "tanggal"),
    "bukti_fisik": ("bukti", "barang", "tanggal"),
}


def configure_tesseract() -> None:
    """Locate Tesseract when it is installed but missing from PATH."""
    configured = os.environ.get("TESSERACT_CMD")
    candidates = [
        configured,
        shutil.which("tesseract"),
        r"C:\Program Files\Tesseract-OCR\tesseract.exe",
        r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe",
    ]
    executable = next((item for item in candidates if item and Path(item).is_file()), None)
    if executable:
        pytesseract.pytesseract.tesseract_cmd = executable


def ocr_image(image: Image.Image) -> str:
    configure_tesseract()
    languages = set(pytesseract.get_languages(config=""))
    if "eng" not in languages and "ind" not in languages:
        raise DocumentError("Data bahasa Tesseract OCR tidak tersedia.")
    language = "ind+eng" if {"ind", "eng"}.issubset(languages) else ("ind" if "ind" in languages else "eng")
    source = ImageOps.exif_transpose(image).convert("RGB")
    grayscale = ImageOps.autocontrast(source.convert("L"), cutoff=1)
    if grayscale.width < 3000:
        scale = min(2.5, 3000 / grayscale.width)
        grayscale = grayscale.resize(
            (round(grayscale.width * scale), round(grayscale.height * scale)),
            Image.Resampling.LANCZOS,
        )

    # Preserve faint handwriting in one variant and isolate printed text in
    # another. The sparse mode helps with dates/labels away from the main table.
    sharpened = grayscale.filter(ImageFilter.UnsharpMask(radius=2, percent=180, threshold=2))
    threshold = max(145, min(210, round(sum(grayscale.histogram()[128:]) / max(1, sum(grayscale.histogram())) * 255)))
    binary = sharpened.point(lambda pixel: 255 if pixel > threshold else 0)
    footer = sharpened.crop((0, round(sharpened.height * 0.68), sharpened.width, sharpened.height))
    outputs = [
        pytesseract.image_to_string(source, lang=language, config="--oem 3 --psm 3"),
        pytesseract.image_to_string(sharpened, lang=language, config="--oem 3 --psm 6 -c user_defined_dpi=300"),
        pytesseract.image_to_string(binary, lang=language, config="--oem 3 --psm 11 -c user_defined_dpi=300"),
        pytesseract.image_to_string(footer, lang=language, config="--oem 3 --psm 11 -c user_defined_dpi=300"),
    ]

    # Avoid repeating identical lines produced by the different OCR passes.
    unique_lines: list[str] = []
    seen: set[str] = set()
    for output in outputs:
        for line in output.splitlines():
            cleaned = re.sub(r"\s+", " ", line).strip()
            key = cleaned.casefold()
            if cleaned and key not in seen:
                seen.add(key)
                unique_lines.append(cleaned)
    return "\n".join(unique_lines)


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
                    chunks.append(ocr_image(image))
            return "\n".join(chunks).strip()

        with Image.open(path) as image:
            image.verify()
        with Image.open(path) as image:
            return ocr_image(image.convert("RGB")).strip()
    except DocumentError:
        raise
    except pytesseract.TesseractNotFoundError as exc:
        raise DocumentError("Tesseract OCR belum terpasang atau tidak ditemukan.") from exc
    except (pymupdf.FileDataError, UnidentifiedImageError, OSError) as exc:
        raise DocumentError("File rusak atau tidak dapat dibaca.") from exc


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
        r"\b(\d{1,2}[./-]\d{1,2}[./-]\d{2,4}|\d{4}[./-]\d{1,2}[./-]\d{1,2}|\d{1,2}\s*(?:jan(?:uari)?|feb(?:ruari)?|mar(?:et)?|apr(?:il)?|mei|may|jun(?:i)?|jul(?:i)?|agu(?:stus)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|okt(?:ober)?|nov(?:ember)?|dec(?:ember)?|des(?:ember)?)\s+\d{4})\b",
        normalized,
        re.IGNORECASE,
    )
    po_match = re.search(
        r"\b(?:purchase\s*order|order\s*po|(?:no\.?\s*)?po)\b\s*(?:no\.?)?\s*[:#-]?\s*[^A-Z0-9\n]{0,3}([A-Z0-9][A-Z0-9./-]{2,})",
        normalized,
        re.IGNORECASE,
    )
    vehicle_match = re.search(
        r"(?:no(?:mor)?\.?\s*)?(?:kendaraan|polisi|plat|truk)\s*[:#-]?\s*([A-Z]{1,2}\s*\d{1,4}\s*[A-Z]{0,3})",
        normalized,
        re.IGNORECASE,
    )
    total_matches = list(re.finditer(
        r"(?P<label>total\s*(?:barang|item|berat|weight)?|jumlah|berat\s*(?:bersih|kotor)?|weight|gross(?:\s*weight)?|netto|net\s*weight|tare)"
        r"\s*[:#=-]?\s*[^0-9\n]{0,15}(?P<value>\d+(?:[.,]\d+)?)\s*(?P<unit>kg|kgs|kilogram|ton|tons|pcs|sak|unit)?",
        normalized,
        re.IGNORECASE,
    ))
    total_match = max(
        total_matches,
        key=lambda match: int(re.sub(r"\D", "", match.group("value")) or 0),
        default=None,
    )

    def labeled_value(labels: str) -> str | None:
        match = re.search(
            rf"(?:^|\n)\s*(?:{labels})\s*[:#-]?\s*([^\n]{{2,100}})",
            text,
            re.IGNORECASE,
        )
        return re.sub(r"\s+", " ", match.group(1)).strip(" .:-") if match else None

    sender = labeled_value(r"pengirim|dikirim\s+oleh")
    recipient = labeled_value(r"penerima|diterima\s+oleh|kepada(?:\s+yth\.?)?")
    supplier_match = re.search(
        r"kode\s*supplier\s*[:#-]?\s*([^\n|]{3,80}?)(?=\s+(?:dump|material|tgl|tanggal|jam)\b|$)",
        normalized,
        re.IGNORECASE,
    )
    if not sender and supplier_match:
        sender = re.sub(r"\s+", " ", supplier_match.group(1)).strip(" .:-")

    company_matches = re.findall(
        r"\bPT\.?\s+([A-Z][A-Z0-9.,&' -]{3,80}?(?:TBK|PRAKARSA))\b",
        normalized,
        re.IGNORECASE,
    )
    if not recipient and re.search(
        r"(?:i?ndo).{0,15}(?:cement|yent|aent).{0,160}(?:tunggal|inggal).{0,20}prakarsa",
        lowered,
    ):
        # Frequent OCR fragmentation of the company name on the internal
        # weighbridge/loading form used by this application.
        recipient = "PT INDOCEMENT TUNGGAL PRAKARSA Tbk"
    if not recipient and company_matches:
        company = min(company_matches, key=len)
        recipient = "PT "+re.sub(r"\s+", " ", company).strip(" .:-")
    explicit_signature = bool(re.search(r"tanda\s*tangan|signature|ttd|paraf", lowered))
    stamp_detected = bool(re.search(r"\b(?:cap|stempel|stamp|seal)\b", lowered))
    barcode_detected = bool(re.search(r"\b(?:bar\s*code|barcode|qr\s*code|qrcode)\b", lowered))
    approval_area = bool(re.search(
        r"(?:diketahui|dikirim|diperiksa|diterima)\s+oleh|distributor|ekspedisi",
        lowered,
    )) and bool(re.search(r"nama\s+jelas", lowered))
    signature_detected = explicit_signature or approval_area
    verification_mark_types = []
    if signature_detected:
        verification_mark_types.append("tanda tangan/cap pada area pengesahan")
    if stamp_detected:
        verification_mark_types.append("cap/stempel")
    if barcode_detected:
        verification_mark_types.append("barcode/QR")
    verification_mark_detected = bool(verification_mark_types)
    signals = len(matched_terms) + bool(number_match) + bool(date_match) + verification_mark_detected
    readability_score = min(100, round(len(normalized) / 200 * 100))
    completeness_score = round(signals / (len(required_terms) + 3) * 100)
    # Neutral until metadata/ELA/database consistency analysis is implemented.
    # A readability check alone must not claim that a document is authentic.
    authenticity_score = 50
    overall_score = round(
        readability_score * 0.3
        + completeness_score * 0.5
        + authenticity_score * 0.2
    )
    if completeness_score >= 80:
        status = "LENGKAP"
        default_note = "Dokumen terbaca dan memenuhi sebagian besar indikator kelengkapan."
    elif completeness_score >= 50:
        status = "PERLU_DITINJAU"
        default_note = "Dokumen memerlukan peninjauan lebih lanjut."
    else:
        status = "TERINDIKASI_MANIPULASI"
        default_note = "Indikator dokumen belum cukup dan perlu diperiksa secara manual."

    missing = []
    if not number_match:
        missing.append("Nomor dokumen tidak ditemukan")
    if not date_match:
        missing.append("Tanggal tidak ditemukan")
    if not verification_mark_detected:
        missing.append("Tanda pengesahan tidak ditemukan")
    notes = "; ".join(missing) + "." if missing else default_note

    return {
        "status": status,
        "confidence": float(overall_score),
        "notes": notes,
        "scores": {
            "readability_score": readability_score,
            "completeness_score": completeness_score,
            "authenticity_score": authenticity_score,
            "overall_score": overall_score,
        },
        "analysis": {
            "ocr": {
                "text_detected": bool(normalized),
                "raw_text": normalized[:50000],
                "document_number_detected": bool(number_match),
                "date_detected": bool(date_match),
                "verification_mark_detected": verification_mark_detected,
                "verification_mark_types": verification_mark_types,
                "document_number": number_match.group(1) if number_match else None,
                "date": date_match.group(1) if date_match else None,
                "purchase_order_number": po_match.group(1) if po_match else None,
                "sender": sender,
                "recipient": recipient,
                "vehicle_number": re.sub(r"\s+", " ", vehicle_match.group(1)).upper() if vehicle_match else None,
                "total_items": total_match.group("value") if total_match else None,
                "total_label": total_match.group("label").lower() if total_match else None,
                "total_unit": total_match.group("unit").lower() if total_match and total_match.group("unit") else None,
                "matched_terms": matched_terms,
            },
            "metadata": {"analyzed": False, "findings": []},
            "manipulation": {"analyzed": False, "findings": []},
            "barcode": {
                "detected": barcode_detected,
                "decoded": False,
                "value": None,
            },
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
        # ASCII-safe JSON prevents Windows console encodings from corrupting
        # noisy OCR characters before Laravel passes the output to json_decode.
        print(json.dumps(verify_document(args.document, args.document_type), ensure_ascii=True))
        return 0
    except DocumentError as exc:
        print(json.dumps({
            "status": "TIDAK_TERBACA",
            "confidence": 0.0,
            "notes": str(exc),
            "scores": {
                "readability_score": 0,
                "completeness_score": 0,
                "authenticity_score": 0,
                "overall_score": 0,
            },
            "analysis": {
                "ocr": {"text_detected": False},
                "metadata": {"analyzed": False, "findings": []},
                "manipulation": {"analyzed": False, "findings": []},
                "barcode": {"detected": False, "decoded": False, "value": None},
            },
        }, ensure_ascii=True))
        return 2

if __name__ == "__main__":
    raise SystemExit(main())
