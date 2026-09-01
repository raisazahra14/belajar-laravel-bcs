"""Verify Indonesian delivery notes using local OCR.

The command prints one JSON object to stdout so it can be called by Laravel.
User documents are processed in memory and are never written beside the source.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
import re
import shutil
from datetime import datetime
from pathlib import Path
from typing import Any

import pymupdf
import pytesseract
import cv2
import numpy as np
from PIL import ExifTags, Image, ImageChops, ImageFilter, ImageOps, UnidentifiedImageError

ALLOWED_SUFFIXES = {".pdf", ".jpg", ".jpeg", ".png"}
MAX_FILE_SIZE = 10 * 1024 * 1024
DOCUMENT_TERMS = {
    "surat_jalan": ("surat jalan", "pengirim", "penerima"),
    "invoice": ("invoice", "total", "tanggal"),
    "bukti_fisik": ("bukti", "barang", "tanggal"),
}

DOCUMENT_METADATA_FIELDS = (
    "document_number", "document_date", "po_number", "do_number",
    "vehicle_number", "sender", "recipient", "gross_weight", "tare_weight",
    "net_weight", "weight_unit",
)


def _empty_document_metadata() -> dict[str, Any]:
    return {field: None for field in DOCUMENT_METADATA_FIELDS}


def _normalize_document_date(value: str | None) -> str | None:
    if not value:
        return None
    cleaned = re.sub(r"\s+", " ", value).strip()
    aliases = {"agu": "aug", "agustus": "aug", "okt": "oct", "oktober": "oct", "des": "dec", "desember": "dec"}
    for source, target in aliases.items():
        cleaned = re.sub(rf"\b{source}\b", target, cleaned, flags=re.IGNORECASE)
    if re.fullmatch(r"\d{1,2}\s+\d{1,2}\s+\d{4}", cleaned):
        cleaned = re.sub(r"\s+", "/", cleaned)
    for pattern in ("%d %b %Y", "%d %B %Y", "%d/%m/%Y", "%d-%m-%Y", "%Y-%m-%d"):
        try:
            parsed = datetime.strptime(cleaned, pattern)
            if 2000 <= parsed.year <= 2100:
                return parsed.strftime("%Y-%m-%d")
        except ValueError:
            continue
    return None


def extract_document_metadata(text: str, document_type: str = "surat_jalan") -> dict[str, Any]:
    """Extract document content only from nearby, explicit labels."""
    result = _empty_document_metadata()
    if document_type != "surat_jalan":
        return result

    normalized = re.sub(r"[ \t]+", " ", text)

    def labeled_token(labels: str, pattern: str) -> str | None:
        candidates = re.findall(
            rf"(?:^|\n|\b)(?:{labels})[ \t]*[:#=.-]?[ \t]*({pattern})(?=[ \t]|\n|$|[|;,])",
            normalized,
            re.IGNORECASE,
        )
        valid = [candidate.strip(" .,:;|_").upper() for candidate in candidates if candidate.strip(" .,:;|_")]
        return max(valid, key=lambda item: (valid.count(item), len(item)), default=None)

    result["document_number"] = labeled_token(
        r"no\.?\s*form|nomor\s*surat\s*jalan|no\.?\s*surat\s*jalan",
        r"[A-Z0-9][A-Z0-9./-]{3,}",
    )
    # Some printed delivery notes place their number alone in the top-right
    # corner. Tesseract commonly reads a leading "SS" as "$S" and inserts
    # spaces around the separator. Only accept this strict, year-based shape;
    # arbitrary unlabeled numbers must remain rejected.
    if not result["document_number"]:
        header_reference = re.search(
            r"(?<!\w)([S$]{1,2}\s*(?:19|20)\d{2}\s*[-/]\s*\d{5,})\b",
            normalized[:600],
            re.IGNORECASE,
        )
        if header_reference:
            reference = re.sub(r"\s+", "", header_reference.group(1)).upper()
            result["document_number"] = reference.replace("$", "S")
    po_number = labeled_token(r"no\.?\s*po|purchase\s*order(?:\s*no\.?)?", r"[$A-Z0-9][A-Z0-9$./-]{2,}")
    if po_number and po_number.startswith("$") and re.fullmatch(r"\$\d{4,}", po_number):
        po_number = "S" + po_number[1:]
    result["po_number"] = po_number
    result["do_number"] = labeled_token(
        r"dispatch\s*no\.?|no\.?\s*do|delivery\s*order(?:\s*no\.?)?",
        r"[A-Z0-9][A-Z0-9./-]{3,}",
    )
    result["vehicle_number"] = labeled_token(
        r"nomor\s*truk|no\.?\s*truk|no\.?\s*kendaraan|no\.?\s*polisi",
        r"[A-Z]{1,2}\s*\d{1,4}\s*[A-Z]{0,3}",
    )
    if result["vehicle_number"]:
        result["vehicle_number"] = re.sub(r"\s+", "", result["vehicle_number"])

    date_pattern = (
        r"\d{1,2}[./-]\d{1,2}[./-]\d{4}|\d{4}[./-]\d{1,2}[./-]\d{1,2}|"
        r"\d{1,2}\s+\d{1,2}\s+\d{4}|"
        r"\d{1,2}\s+(?:Jan(?:uary|uari)?|Feb(?:ruary|ruari)?|Mar(?:ch|et)?|Apr(?:il)?|Mei|May|"
        r"Jun(?:e|i)?|Jul(?:y|i)?|Agu(?:stus)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|"
        r"Okt(?:ober)?|Nov(?:ember)?|Dec(?:ember)?|Des(?:ember)?)\s+\d{4}"
    )
    for labels in (r"tanggal\s*(?:masuk|keluar)", r"tanggal\s*dokumen|tanggal|date"):
        normalized_date = _normalize_document_date(labeled_token(labels, date_pattern))
        if normalized_date:
            result["document_date"] = normalized_date
            break

    def labeled_name(labels: str) -> str | None:
        matches = re.findall(rf"(?:^|\n)\s*(?:{labels})\s*[:#=-]?\s*([^\n|]{{3,100}})", normalized, re.IGNORECASE)
        values = [re.sub(r"\s+", " ", value).strip(" :;|_") for value in matches]
        rejected = re.compile(r"supplier\s*/\s*pengirim\s*/\s*pbm|petugas\s+di\s+lokasi\s+penerimaan", re.IGNORECASE)
        values = [value for value in values if not rejected.search(value)]
        return max(values, key=lambda item: (values.count(item), len(item)), default=None)

    result["sender"] = labeled_name(r"kode\s*supplier|supplier|pengirim")
    if not result["sender"]:
        header_company = re.search(
            r"^\s*([A-Z][A-Z0-9 .&'-]{3,50}?)(?=\s+(?:[S$]{1,2}(?:19|20)\d{2}|J[il]\.?\s|SURAT\s+JALAN))",
            normalized[:500],
            re.IGNORECASE,
        )
        if header_company:
            company = re.sub(r"\s+", " ", header_company.group(1)).strip(" .,:;|_")
            if company.casefold() != "surat jalan":
                result["sender"] = re.sub(r"\bOOSCO\b", "POSCO", company, flags=re.IGNORECASE)

    result["recipient"] = labeled_name(r"perusahaan\s+penerima|penerima|recipient|consignee|ship\s*to")
    if result["recipient"] and re.search(
        r"^(?:menyediakan|angkutan|resource|barang\s+yang|tanggal)\b|\b(?:surat\s+jalan|no\.?\s*truk)\b",
        result["recipient"],
        re.IGNORECASE,
    ):
        result["recipient"] = None
    if not result["recipient"]:
        companies = re.findall(r"\bPT\.?\s+[A-Z][A-Z0-9 .,&'-]{3,80}?(?:,?\s*TBK\.?)?(?=\n|$|[|])", normalized, re.IGNORECASE)
        companies = [re.sub(r"\s+", " ", company).strip() for company in companies]
        result["recipient"] = max(companies, key=len, default=None)

    weight_unit = None
    for key, labels in (("gross_weight", r"gross(?:\s*weight)?"), ("tare_weight", r"tare(?:\s*weight)?"), ("net_weight", r"nett?o?(?:\s*weight)?|berat\s*bersih")):
        matches = re.findall(rf"(?:^|\n|\b)(?:{labels})\s*[:#=-]?\s*(\d{{1,9}}(?:[.,]\d{{1,3}})?)\s*(kg|kgs|kilogram|ton|tons)\b", normalized, re.IGNORECASE)
        if matches:
            values = [(value.replace(",", "."), unit.lower()) for value, unit in matches]
            value, unit = max(values, key=lambda item: values.count(item))
            numeric = float(re.sub(r"[.,]", "", value)) if re.fullmatch(r"\d{1,3}[.,]\d{3}", value) else float(value)
            result[key] = int(numeric) if numeric.is_integer() else numeric
            weight_unit = "kg" if unit in {"kg", "kgs", "kilogram"} else "ton"
    result["weight_unit"] = weight_unit
    return result


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
    header = sharpened.crop((0, 0, sharpened.width, round(sharpened.height * 0.30)))
    full_page_outputs = [
        pytesseract.image_to_string(source, lang=language, config="--oem 3 --psm 3"),
        pytesseract.image_to_string(sharpened, lang=language, config="--oem 3 --psm 6 -c user_defined_dpi=300"),
        pytesseract.image_to_string(binary, lang=language, config="--oem 3 --psm 11 -c user_defined_dpi=300"),
    ]
    regional_outputs = [
        pytesseract.image_to_string(header, lang=language, config="--oem 3 --psm 6 -c user_defined_dpi=300"),
        pytesseract.image_to_string(header, lang=language, config="--oem 3 --psm 11 -c user_defined_dpi=300"),
        pytesseract.image_to_string(footer, lang=language, config="--oem 3 --psm 11 -c user_defined_dpi=300"),
    ]

    def quality(output: str) -> tuple[int, int]:
        lowered = output.casefold()
        anchors = sum(term in lowered for term in (
            "invoice", "surat jalan", "no. invoice", "npwp", "subtotal",
            "total amount", "pengirim", "penerima", "tanggal",
        ))
        labeled_values = len(re.findall(r"(?:no\.?\s*invoice|npwp|sub\s*total|dpp|ppn|total)\s*[:=]", output, re.IGNORECASE))
        noise = len(re.findall(r"[^\w\s.,:/@&'()+-]", output))
        return anchors * 25 + labeled_values * 8 - noise, len(output)

    # Different preprocessing passes often return the same document with
    # conflicting OCR errors. Extract from the strongest complete pass instead
    # of concatenating every pass and destroying label/value boundaries.
    best_output = max(full_page_outputs, key=quality)
    outputs = [best_output]
    lowered_best = best_output.casefold()
    if not re.search(r"\b(?:19|20)\d{2}\b", best_output):
        outputs.append(regional_outputs[-1])
    if not re.search(r"(?:no\.?\s*invoice|no\.?\s*form|nomor\s*surat)", lowered_best):
        outputs.extend(regional_outputs[:2])
    if "idr" not in lowered_best:
        for candidate_output in full_page_outputs:
            currency_line = next((line for line in candidate_output.splitlines() if re.search(r"\bIDR\b", line, re.IGNORECASE)), None)
            if currency_line:
                outputs.append(currency_line)
                break

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


def extract_specialized_metadata(text: str, document_type: str) -> dict[str, Any]:
    """Extract type-specific fields without inventing values absent from OCR."""
    normalized = re.sub(r"\s+", " ", text).strip()

    def value_after(labels: str, stops: str, limit: int = 120) -> str | None:
        matches = list(re.finditer(
            rf"\b(?:{labels})\s*[:#=+-]?\s*(.{{1,{limit}}}?)(?=\s+(?:{stops})\b|$)",
            normalized,
            re.IGNORECASE,
        ))
        if not matches:
            return None

        def candidate_score(match: re.Match[str]) -> tuple[int, int]:
            candidate = re.sub(r"\s+", " ", match.group(1)).strip(" .,:;|_-+")
            letters = len(re.findall(r"[A-Za-z]", candidate))
            words = len(re.findall(r"[A-Za-z]{2,}", candidate))
            noise = len(re.findall(r"[^A-Za-z0-9 .,&/'()-]", candidate))
            return letters + words * 3 - noise * 8, len(candidate)

        match = max(matches, key=candidate_score)
        value = re.sub(r"\s+", " ", match.group(1)).strip(" .,:;|_-+")
        return value or None

    def money(labels: str) -> int | None:
        values = []
        for label_match in re.finditer(rf"(?:{labels})\s*[:=]?", normalized, re.IGNORECASE):
            nearby = normalized[label_match.end():label_match.end() + 35]
            nearby = re.split(
                r"\b(?:sub\s*total|diskon|discount|b(?:i|l)?aya\s*pengantaran|delivery\s*fee|DPP|PPN|VAT|pajak|uang\s*muka|down\s*payment|total\s*amount|grand\s*total)\b",
                nearby,
                maxsplit=1,
                flags=re.IGNORECASE,
            )[0]
            candidates = re.findall(r"\d+(?:[.,]\d+)+|\d{1,12}", nearby)
            if candidates:
                chosen = candidates[0]
                if re.fullmatch(r"[1-9]", chosen) and len(candidates) > 1 and re.search(r"[.,]", candidates[1]):
                    chosen = candidates[1]
                digits = re.sub(r"\D", "", chosen)
                if digits:
                    values.append(int(digits))
        matches = re.findall(
            rf"(?:{labels})\s*[:=]?\s*[^0-9]{{0,8}}(?:Rp\.?|IDR)?\s*([0-9][0-9.,]*)",
            normalized,
            re.IGNORECASE,
        )
        values.extend(int(digits) for value in matches if (digits := re.sub(r"\D", "", value)))
        return max(values) if values else None

    def line_value(labels: str, stop_labels: str = r"no\.?\s*invoice|npwp|alamat|address") -> str | None:
        candidates = []
        for line in text.splitlines():
            match = re.search(rf"(?:^|\s)(?:{labels})\s*[:=]\s*(.+)$", line, re.IGNORECASE)
            if not match:
                continue
            value = re.split(rf"\s+(?:{stop_labels})\s*[:=]", match.group(1), maxsplit=1, flags=re.IGNORECASE)[0]
            value = re.sub(r"\s+", " ", value).strip(" .,:;|_-")
            if value:
                candidates.append(value)
        return max(candidates, key=lambda value: (candidates.count(value), len(value)), default=None)

    def valid_reference(value: str | None) -> str | None:
        """Reject unlabeled OCR fragments while allowing common reference formats."""
        if not value:
            return None
        candidate = re.sub(r"\s+", "", value).strip(".,:;|_-")
        if len(candidate) < 4 or not re.search(r"\d", candidate):
            return None
        if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9./-]*", candidate):
            return None
        return candidate

    if document_type == "invoice":
        invoice_matches = list(re.finditer(
            r"(?:no\.?\s*invo(?:ice|lee)|invo(?:ice|lee)\s*no\.?)\s*[:#=+-]?\s*([A-Z0-9][A-Z0-9./-]{3,})",
            normalized,
            re.IGNORECASE,
        ))

        def invoice_score(match: re.Match[str]) -> tuple[int, int, int]:
            candidate = match.group(1).upper()
            label_bonus = 12 if "INVOICE" in match.group(0).upper() else 0
            month_bonus = 10 if re.search(
                r"/(?:I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)/(?:19|20)\d{2}$",
                candidate,
            ) else 0
            return (
                len(re.findall(r"[/-]", candidate)) * 5 + label_bonus + month_bonus,
                len(re.findall(r"\d", candidate)),
                len(candidate),
            )

        invoice_match = max(invoice_matches, key=invoice_score) if invoice_matches else None
        invoice_number = invoice_match.group(1) if invoice_match else None
        if invoice_number:
            invoice_number = re.sub(
                r"/([IVXL1]{1,5})/(?=(?:19|20)\d{2}$)",
                lambda match: "/" + match.group(1).upper().replace("1", "I").replace("L", "I") + "/",
                invoice_number,
                flags=re.IGNORECASE,
            )
        customer = line_value(r"kepada|customer|bill\s+to|ditagihkan\s+kepada") or value_after(
            r"kepada|customer|bill\s+to|ditagihkan\s+kepada",
            r"no\.?\s*invo(?:ice|lee)|npwp|alamat|address|telp|phone",
        )
        if customer:
            customer = re.split(r"\s+No[,.]?\s*Invo(?:ice|lee)\b", customer, maxsplit=1, flags=re.IGNORECASE)[0].strip()
        vendor_matches = re.findall(
            r"\b((?:PT|CV|UD)\.?\s+[A-Za-z][A-Za-z .,&'-]{2,60}?)"
            r"(?=\s+(?:F?PPN|BNI|BCA|BRI|MANDIRI|Direktur|Manager|www|E-?mail|Head\s+Office))",
            normalized,
            re.IGNORECASE,
        )
        clean_vendors = [re.sub(r"\s+", " ", value).strip(" .,:;|_-") for value in vendor_matches]
        vendor = max(clean_vendors, key=lambda value: (clean_vendors.count(value), len(value)), default=None)
        if not vendor:
            footer_vendor = re.search(
                r"(?:Cilegon|Jakarta|Bogor|Surabaya)\s*,?\s*\d{1,2}\s+[A-Za-z]+\s+\d{4}\s+"
                r"((?:PT|CV|UD)\.?\s+[A-Z][A-Za-z0-9 .,&'-]{3,80}?)(?=\s+(?:Direktur|Manager|www|E-?mail))",
                normalized,
                re.IGNORECASE,
            )
            vendor = re.sub(r"\s+", " ", footer_vendor.group(1)).strip() if footer_vendor else None

        npwp_candidates = [
            re.sub(r"\D", "", value)
            for value in re.findall(r"\bNPWP\s*[:#=+-]?\s*([0-9 .-]{10,30})", normalized, re.IGNORECASE)
        ]
        valid_npwp = [value for value in npwp_candidates if len(value) in (15, 16)]
        npwp = max(valid_npwp, key=lambda value: (valid_npwp.count(value), len(value)), default=None)
        contract = value_after(
            r"no\.?\s*(?:SPK(?:\s*/\s*kontrak)?|kontrak)|contract\s*(?:no\.?)?",
            r"alamat|address|telp|phone|no\.?\s*PO|kode\s*project|project\s*code",
        )
        if contract and re.search(r"alamat|address|telp|phone", contract, re.IGNORECASE):
            contract = None
        contract = valid_reference(contract)
        po_matches = re.findall(
            r"(?:no\.?\s*PO|purchase\s*order(?:\s*no\.?)?)\s*[:#=+-]?\s*([A-Z0-9][A-Z0-9./-]{2,})",
            normalized,
            re.IGNORECASE,
        )
        po_matches = [candidate for value in po_matches if (candidate := valid_reference(value))]
        purchase_order = max(po_matches, key=lambda value: (po_matches.count(value), len(value)), default=None)
        project_code = value_after(
            r"kode\s*project|project\s*code",
            r"attn|kami|nama\s*barang|description|no\.?\s*PO|sub\s*total|diskon|discount|DPP|PPN|total",
        )

        return {
            "invoice_number": invoice_number,
            "vendor": vendor,
            "customer": customer,
            "npwp": npwp,
            "contract_number": contract,
            "purchase_order_number": purchase_order,
            "project_code": project_code,
            "subtotal": money(r"sub\s*total"),
            "discount": money(r"diskon|discount"),
            "delivery_fee": money(r"b(?:i|l)?aya\s*pengantaran|delivery\s*fee"),
            "dpp": money(r"DPP"),
            "tax": money(r"PPN|VAT|pajak"),
            "down_payment": money(r"uang\s*muka|down\s*payment"),
            "total_amount": money(r"total\s*amount|grand\s*total|total\s*tagihan|(?<!sub\s)total"),
            "currency": "IDR"
            if re.search(r"\b(?:I[D0]R|1DR|RP|RUPIAH)\b", normalized, re.IGNORECASE)
            else None,
        }

    if document_type == "bukti_fisik":
        return {
            "reference_number": value_after(
                r"no\.?\s*referensi|reference\s*(?:no\.?)?|no\.?\s*bukti",
                r"tanggal|date|nama\s*barang|item|lokasi|location",
            ),
            "item_name": value_after(
                r"nama\s*barang|item(?:\s*name)?|barang",
                r"jumlah|quantity|qty|satuan|unit|kondisi|condition|lokasi|location",
            ),
            "quantity": value_after(
                r"jumlah|quantity|qty",
                r"satuan|unit|kondisi|condition|lokasi|location|catatan|notes",
                30,
            ),
            "unit": value_after(
                r"satuan|unit",
                r"kondisi|condition|lokasi|location|catatan|notes",
                30,
            ),
            "condition": value_after(
                r"kondisi|condition",
                r"lokasi|location|catatan|notes|pengirim|penerima",
            ),
            "location": value_after(
                r"lokasi|location",
                r"catatan|notes|pengirim|penerima|diperiksa",
            ),
            "notes": value_after(r"catatan|notes|keterangan", r"pengirim|penerima|diperiksa|diterima"),
        }

    return {}


def analyze_text(text: str, document_type: str = "surat_jalan") -> dict[str, Any]:
    normalized = re.sub(r"\s+", " ", text).strip()
    lowered = normalized.lower()
    required_terms = DOCUMENT_TERMS.get(document_type, DOCUMENT_TERMS["surat_jalan"])
    matched_terms = [term for term in required_terms if term in lowered]
    number_patterns = (
        r"(?:no\.?\s*invo(?:ice|lee)|invo(?:ice|lee)\s*no\.?)\s*[:#=+-]?\s*([A-Z0-9][A-Z0-9./-]{3,})",
        r"(?:surat\s*jalan|delivery\s*(?:note|order)|weighing\s*slip|dispatch)\s*(?:no(?:mor)?\.?)?\s*[:#-]?\s*([A-Z0-9][A-Z0-9./-]{2,})",
        r"(?:no(?:mor)?\.?\s*)?(?:sj|do|dn)\s*[:#-]?\s*([A-Z0-9][A-Z0-9./-]{2,})",
        r"(?:no(?:mor)?\.?|nomor)\s*(?:surat\s*jalan)?\s*[:#-]?\s*([A-Z0-9][A-Z0-9./-]{2,})",
    )
    number_match = next(
        (match for pattern in number_patterns if (match := re.search(pattern, normalized, re.IGNORECASE))),
        None,
    )
    date_pattern = (
        r"\b(\d{1,2}[./-]\d{1,2}[./-](?:\d{2}|\d{4})|"
        r"\d{4}[./-]\d{1,2}[./-]\d{1,2}|"
        r"\d{1,2}\s*(?:jan(?:uari)?|feb(?:ruari)?|mar(?:et)?|apr(?:il)?|mei|may|"
        r"jun(?:i)?|jul(?:i)?|agu(?:stus)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|"
        r"okt(?:ober)?|nov(?:ember)?|dec(?:ember)?|des(?:ember)?)\s+\d{4})\b"
    )
    date_matches = list(re.finditer(date_pattern, normalized, re.IGNORECASE))

    # OCR often sees project codes such as 2-01-002 as a date. Prefer an
    # unambiguous four-digit year and a written month over the first match.
    def date_score(match: re.Match[str]) -> tuple[int, int, int]:
        value = match.group(1)
        has_four_digit_year = bool(re.search(r"(?:19|20)\d{2}", value))
        has_written_month = bool(re.search(r"[A-Za-z]", value))
        return int(has_four_digit_year), int(has_written_month), match.start()

    date_match = max(date_matches, key=date_score) if date_matches else None
    po_match = re.search(
        r"\b(?:purchase\s*order|order\s*po|(?:no\.?\s*)?po)\b\s*(?:no\.?)?\s*[:#-]?\s*[^A-Z0-9\n]{0,3}([A-Z0-9][A-Z0-9./-]{2,})",
        normalized,
        re.IGNORECASE,
    )
    if po_match and not re.search(r"\d", po_match.group(1)):
        po_match = None
    vehicle_match = re.search(
        r"(?:no(?:mor)?\.?\s*)?(?:kendaraan|polisi|plat|truk|vehicle(?:\s+number)?|truck(?:\s+number)?)"
        r"\s*[:#-]?\s*([A-Z]{1,2}[\s-]*\d{1,4}[\s-]*[A-Z]{0,3})",
        normalized,
        re.IGNORECASE,
    )
    total_matches = list(re.finditer(
        r"(?P<label>total\s*(?:barang|item|berat|weight)?|jumlah|berat\s*(?:bersih|kotor)?|weight|gross(?:\s*weight)?|nett?o|nett?\s*weight|tare)"
        r"\s*[:#=-]?\s*[^0-9\n]{0,15}(?P<value>\d+(?:[.,]\d+)?)\s*(?P<unit>kg|kgs|kilogram|ton|tons|pcs|sak|unit)?",
        normalized,
        re.IGNORECASE,
    ))
    def weight_priority(match: re.Match[str]) -> tuple[int, int]:
        label = match.group("label").lower()
        priority = 3 if re.search(r"nett?o?|nett?\s*weight|berat\s*bersih", label) else 2
        if re.search(r"gross|tare|berat\s*kotor", label):
            priority = 1
        return priority, int(re.sub(r"\D", "", match.group("value")) or 0)

    total_match = max(total_matches, key=weight_priority, default=None)

    field_boundary = (
        r"tanggal|date|pengirim|sender|dikirim\s+oleh|penerima|recipient|diterima\s+oleh|"
        r"kepada|customer|consignee|departure\s+name|arrival\s+name|no(?:mor)?\.?\s*(?:po|sj|do|kendaraan|truk)|"
        r"vehicle(?:\s+number)?|weighing|jumlah|total|gross|nett?o?|weight|dump\s*(?:loc|location)|material|tgl|jam"
    )

    def labeled_value(labels: str) -> str | None:
        line_match = re.search(
            rf"(?:^|\n)\s*(?:{labels})\s*[:#=-]?\s*([^\n]{{2,100}})",
            text,
            re.IGNORECASE,
        )
        match = line_match or re.search(
            rf"\b(?:{labels})\s*[:#=-]?\s*(.{{2,100}}?)(?=\s+(?:{field_boundary})\b|$)",
            normalized,
            re.IGNORECASE,
        )
        if not match:
            return None
        value = re.sub(r"\s+", " ", match.group(1)).strip(" .:-|_")
        return value if value and not re.fullmatch(r"[-_=.\s]+", value) else None

    sender = labeled_value(r"pengirim|sender|dikirim\s+oleh|departure\s+name|supplier(?:\s+name)?")
    recipient = labeled_value(
        r"penerima|recipient|diterima\s+oleh|kepada(?:\s+yth\.?)?|arrival\s+name|customer|consignee|ship\s+to"
    )
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
    specialized_metadata = extract_specialized_metadata(text, document_type)
    document_metadata = extract_document_metadata(text, document_type)
    if document_type == "invoice":
        document_metadata.update({
            "document_number": specialized_metadata.get("invoice_number"),
            "document_date": _normalize_document_date(date_match.group(1) if date_match else None),
            "po_number": specialized_metadata.get("purchase_order_number"),
            "sender": specialized_metadata.get("vendor"),
            "recipient": specialized_metadata.get("customer"),
        })
    resolved_number = document_metadata.get("document_number") or (number_match.group(1) if number_match else None)
    resolved_date = document_metadata.get("document_date") or (
        _normalize_document_date(date_match.group(1)) if date_match else None
    )
    resolved_sender = document_metadata.get("sender") or sender
    resolved_recipient = document_metadata.get("recipient") or recipient
    if recipient and resolved_recipient and re.search(r"\b(?:supply\s+dept|rintah\s+muat)\b", resolved_recipient, re.IGNORECASE):
        resolved_recipient = recipient
    if resolved_recipient and re.search(
        r"^(?:angkutan|resource|menyediakan|barang\s+yang|tanggal)\b",
        resolved_recipient,
        re.IGNORECASE,
    ):
        resolved_recipient = None
    signals = len(matched_terms) + bool(resolved_number) + bool(resolved_date) + verification_mark_detected
    readability_score = min(100, round(len(normalized) / 200 * 100))
    if document_type == "invoice":
        invoice_checks = [
            bool(specialized_metadata.get("invoice_number") or number_match),
            bool(date_match),
            bool(specialized_metadata.get("vendor")),
            bool(specialized_metadata.get("customer")),
            specialized_metadata.get("total_amount") is not None,
            bool(specialized_metadata.get("npwp")),
            bool(specialized_metadata.get("project_code") or specialized_metadata.get("purchase_order_number")),
            specialized_metadata.get("subtotal") is not None,
            specialized_metadata.get("dpp") is not None,
            bool(specialized_metadata.get("currency")),
        ]
        completeness_score = round(sum(invoice_checks) / len(invoice_checks) * 100)
    elif document_type == "bukti_fisik":
        physical_checks = [
            bool(specialized_metadata.get("reference_number") or number_match),
            bool(date_match),
            bool(specialized_metadata.get("item_name")),
            bool(specialized_metadata.get("quantity")),
            bool(specialized_metadata.get("condition")),
            bool(specialized_metadata.get("location")),
        ]
        completeness_score = round(sum(physical_checks) / len(physical_checks) * 100)
    else:
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
        status = "ASLI"
        default_note = "Dokumen terbaca dan memenuhi sebagian besar indikator kelengkapan."
    elif completeness_score >= 50:
        status = "MENCURIGAKAN"
        default_note = "Dokumen memerlukan peninjauan lebih lanjut."
    else:
        status = "PALSU"
        default_note = "Indikator dokumen belum cukup dan perlu diperiksa secara manual."

    missing = []
    if not resolved_number:
        missing.append("Nomor dokumen tidak ditemukan")
    if not resolved_date:
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
        "document_metadata": document_metadata,
        "file_metadata": {"analyzed": False, "fields": {}},
        "specialized_metadata": specialized_metadata,
        "analysis": {
            "ocr": {
                "text_detected": bool(normalized),
                "raw_text": normalized[:50000],
                "document_number_detected": bool(resolved_number),
                "date_detected": bool(resolved_date),
                "verification_mark_detected": verification_mark_detected,
                "verification_mark_types": verification_mark_types,
                "document_number": resolved_number,
                "date": date_match.group(1) if date_match else resolved_date,
                "purchase_order_number": po_match.group(1) if po_match else None,
                "sender": resolved_sender,
                "recipient": resolved_recipient,
                "vehicle_number": re.sub(r"\s+", " ", vehicle_match.group(1)).upper()
                if vehicle_match
                else document_metadata.get("vehicle_number"),
                "total_items": total_match.group("value") if total_match else None,
                "total_label": total_match.group("label").lower() if total_match else None,
                "total_unit": total_match.group("unit").lower() if total_match and total_match.group("unit") else None,
                "matched_terms": matched_terms,
            },
            "document_metadata": document_metadata,
            "file_metadata": {"analyzed": False, "fields": {}},
            "metadata": {"analyzed": True, "fields": specialized_metadata},
            "manipulation": {"analyzed": False, "findings": []},
            "barcode": {
                "detected": barcode_detected,
                "decoded": False,
                "value": None,
            },
        },
    }

def _sensitive_ocr_regions(image: Image.Image, page: int) -> list[dict[str, Any]]:
    """Locate date/amount lines. Failure is non-fatal because ELA still works globally."""
    try:
        configure_tesseract()
        data = pytesseract.image_to_data(image, lang="eng", config="--oem 3 --psm 11", output_type=pytesseract.Output.DICT)
    except (pytesseract.TesseractError, pytesseract.TesseractNotFoundError, OSError):
        return []
    lines: dict[tuple[int, int, int], list[int]] = {}
    for index, text in enumerate(data.get("text", [])):
        if str(text).strip():
            key = (data["block_num"][index], data["par_num"][index], data["line_num"][index])
            lines.setdefault(key, []).append(index)
    regions = []
    sensitive = re.compile(
        r"\b(total|amount|subtotal|dpp|ppn|nominal|tanggal|date|invoice|idr|rp)\b|"
        r"\b\d{1,2}[./-]\d{1,2}[./-]\d{2,4}\b|\b\d{1,3}(?:[.,]\d{3}){1,}\b",
        re.IGNORECASE,
    )
    for indexes in lines.values():
        text = " ".join(str(data["text"][index]).strip() for index in indexes)
        if not sensitive.search(text):
            continue
        left = max(0, min(data["left"][index] for index in indexes) - 12)
        top = max(0, min(data["top"][index] for index in indexes) - 8)
        right = min(image.width, max(data["left"][index] + data["width"][index] for index in indexes) + 12)
        bottom = min(image.height, max(data["top"][index] + data["height"][index] for index in indexes) + 8)
        if right > left and bottom > top:
            target = "tanggal" if re.search(r"tanggal|date|\d{1,2}[./-]\d{1,2}", text, re.I) else "nominal"
            regions.append({"page": page, "x": left, "y": top, "width": right-left,
                            "height": bottom-top, "target": target, "text": text[:100]})
    return regions[:20]


def _ela_page(image: Image.Image, page: int) -> dict[str, Any]:
    rgb = image.convert("RGB")
    buffer = io.BytesIO()
    rgb.save(buffer, format="JPEG", quality=90)
    buffer.seek(0)
    with Image.open(buffer) as recompressed:
        difference = ImageChops.difference(rgb, recompressed.convert("RGB"))
    diff = np.asarray(difference.convert("L"), dtype=np.float32)
    mean = float(diff.mean())
    deviation = float(diff.std())
    threshold = max(18.0, mean + deviation * 3.0)
    global_ratio = float(np.mean(diff >= threshold))
    regions = _sensitive_ocr_regions(rgb, page)
    scored = []
    for region in regions:
        x, y, width, height = (region[key] for key in ("x", "y", "width", "height"))
        crop = diff[y:y+height, x:x+width]
        if crop.size == 0:
            continue
        local_ratio = float(np.mean(crop >= threshold))
        local_mean = float(crop.mean())
        score = min(100.0, local_ratio * 900 + max(0.0, local_mean - mean) * 4)
        scored.append({**region, "score": round(score, 1)})
    scored.sort(key=lambda item: item["score"], reverse=True)
    return {"page": page, "mean": round(mean, 3), "high_difference_ratio": round(global_ratio, 6),
            "threshold": round(threshold, 3), "regions": scored[:8]}


def analyze_file_evidence(path: Path) -> tuple[dict[str, Any], dict[str, Any]]:
    file_metadata: dict[str, Any] = {
        "analyzed": True,
        "fields": {
            "extension": path.suffix.lower(),
            "size_bytes": path.stat().st_size,
        },
        "findings": [],
    }
    manipulation: dict[str, Any] = {
        "analyzed": True,
        "method": "ela",
        "suspicious": False,
        "risk_score": 0.0,
        "risk_level": "low",
        "requires_manual_review": False,
        "suspicious_regions": [],
        "findings": [],
        "metrics": {},
        "limitations": ["ELA adalah indikator risiko, bukan bukti tunggal pemalsuan."],
    }

    if path.suffix.lower() == ".pdf":
        with pymupdf.open(path) as document:
            metadata = {key: value for key, value in document.metadata.items() if value}
            file_metadata["fields"].update({"page_count": document.page_count, "pdf": metadata})
            creator = " ".join(str(metadata.get(key, "")) for key in ("creator", "producer")).casefold()
            if any(editor in creator for editor in ("photoshop", "gimp", "illustrator")):
                file_metadata["findings"].append("PDF pernah diproses perangkat lunak pengolah grafis; perlu tinjauan manual.")
        images = []
        with pymupdf.open(path) as document:
            for page in document:
                pixmap = page.get_pixmap(matrix=pymupdf.Matrix(2, 2), alpha=False)
                images.append(Image.frombytes("RGB", (pixmap.width, pixmap.height), pixmap.samples))
        manipulation["limitations"].append("PDF dianalisis dari hasil render; pola kompresi asli kontainer tidak dapat dinilai.")
    else:
        with Image.open(path) as opened:
            image = ImageOps.exif_transpose(opened).convert("RGB")
            images = [image.copy()]
            exif = {
                ExifTags.TAGS.get(key, str(key)): str(value)
                for key, value in opened.getexif().items()
                if value not in (None, "")
            }
            file_metadata["fields"].update({
                "width": image.width, "height": image.height, "format": opened.format, "exif": exif,
            })

    pages = [_ela_page(image, index) for index, image in enumerate(images, start=1)]
    suspicious_regions = [region for page in pages for region in page["regions"] if region["score"] >= 55]
    highest_region = max((region["score"] for page in pages for region in page["regions"]), default=0.0)
    highest_global_ratio = max((page["high_difference_ratio"] for page in pages), default=0.0)
    risk_score = min(100.0, highest_region * 0.8 + min(20.0, highest_global_ratio * 500))
    risk_level = "high" if risk_score >= 75 else ("medium" if risk_score >= 45 else "low")
    manipulation.update({
        "suspicious": risk_level == "high",
        "risk_score": round(risk_score, 1),
        "risk_level": risk_level,
        "requires_manual_review": risk_level in {"medium", "high"},
        "suspicious_regions": suspicious_regions[:10],
        "metrics": {
            "pages": pages,
            "page_count": len(pages),
            # Legacy aggregate fields remain available for stored-result/UI compatibility.
            "ela_mean": round(max((page["mean"] for page in pages), default=0.0), 3),
            "ela_high_difference_ratio": round(highest_global_ratio, 6),
        },
    })
    if risk_level == "high":
        manipulation["findings"].append("Anomali kompresi tinggi ditemukan pada area sensitif; wajib diperiksa manual.")
    elif risk_level == "medium":
        manipulation["findings"].append("Ada ketidakkonsistenan kompresi pada area sensitif yang perlu ditinjau.")
    else:
        manipulation["findings"].append("Tidak ditemukan anomali kompresi kuat pada area tanggal atau nominal.")

    return file_metadata, manipulation


def _document_pages(path: Path) -> list[np.ndarray]:
    if path.suffix.lower() == ".pdf":
        pages = []
        with pymupdf.open(path) as document:
            for page in document:
                pixmap = page.get_pixmap(matrix=pymupdf.Matrix(2, 2), alpha=False)
                rgb = np.frombuffer(pixmap.samples, dtype=np.uint8).reshape(pixmap.height, pixmap.width, 3)
                pages.append(cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR))
        return pages
    with Image.open(path) as opened:
        rgb = np.asarray(ImageOps.exif_transpose(opened).convert("RGB"))
    return [cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR)]


def _box(contour: np.ndarray, page: int) -> dict[str, Any]:
    x, y, width, height = cv2.boundingRect(contour)
    return {"x": int(x), "y": int(y), "width": int(width), "height": int(height), "page": page}


def _best_stamp_candidate(image: np.ndarray, page_number: int) -> dict[str, Any] | None:
    height, width = image.shape[:2]
    page_area = height * width
    hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
    blue = cv2.inRange(hsv, np.array([90, 45, 35]), np.array([140, 255, 255]))
    red = cv2.bitwise_or(
        cv2.inRange(hsv, np.array([0, 55, 40]), np.array([12, 255, 255])),
        cv2.inRange(hsv, np.array([165, 55, 40]), np.array([179, 255, 255])),
    )
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    black = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY_INV, 41, 13)
    candidates = []
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5))
    for color, mask in (("blue", blue), ("red", red), ("black", black)):
        cleaned = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, kernel, iterations=2)
        cleaned = cv2.morphologyEx(cleaned, cv2.MORPH_OPEN, np.ones((2, 2), np.uint8))
        contours, _ = cv2.findContours(cleaned, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        for contour in contours:
            x, y, box_width, box_height = cv2.boundingRect(contour)
            relative_area = box_width * box_height / page_area
            if not (0.0005 <= relative_area <= 0.08) or y < height * 0.22:
                continue
            aspect = box_width / max(1, box_height)
            if not 0.35 <= aspect <= 3.2:
                continue
            density = cv2.countNonZero(mask[y:y + box_height, x:x + box_width]) / max(1, box_width * box_height)
            if not 0.035 <= density <= 0.72:
                continue
            perimeter = cv2.arcLength(contour, True)
            circularity = 4 * np.pi * cv2.contourArea(contour) / max(1.0, perimeter * perimeter)
            if color == "black":
                column_ink = np.any(mask[y:y + box_height, x:x + box_width] > 0, axis=0).astype(np.int8)
                transitions = int(np.count_nonzero(np.diff(column_ink)))
                if aspect > 2.2 or circularity < 0.28 or transitions > box_width * 0.15:
                    continue
            position_score = min(1.0, max(0.0, (y / height - 0.22) / 0.55))
            color_score = 1.0 if color in {"blue", "red"} else 0.55
            confidence = min(0.98, 0.32 + color_score * 0.28 + min(density / 0.25, 1) * 0.18 + min(circularity / 0.55, 1) * 0.1 + position_score * 0.1)
            if confidence >= 0.58:
                candidates.append({"confidence": round(float(confidence), 3), "color": color, "contour": contour, "bounding_box": _box(contour, page_number)})
    return max(candidates, key=lambda item: item["confidence"], default=None)


def _best_signature_candidate(image: np.ndarray, page_number: int) -> dict[str, Any] | None:
    height, width = image.shape[:2]
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    ink = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY_INV, 31, 11)
    horizontal = cv2.morphologyEx(ink, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (max(20, width // 20), 1)))
    vertical = cv2.morphologyEx(ink, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(20, height // 20))))
    strokes = cv2.subtract(ink, cv2.bitwise_or(horizontal, vertical))
    strokes = cv2.morphologyEx(strokes, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (7, 3)), iterations=1)
    contours, _ = cv2.findContours(strokes, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    candidates = []
    for contour in contours:
        x, y, box_width, box_height = cv2.boundingRect(contour)
        relative_width, relative_height = box_width / width, box_height / height
        if (y < height * 0.38 or x < width * 0.02 or x + box_width > width * 0.98
                or y + box_height > height * 0.98
                or not (0.045 <= relative_width <= 0.48 and 0.012 <= relative_height <= 0.16)):
            continue
        density = cv2.countNonZero(strokes[y:y + box_height, x:x + box_width]) / max(1, box_width * box_height)
        aspect = box_width / max(1, box_height)
        if not (1.3 <= aspect <= 12 and 0.025 <= density <= 0.38):
            continue
        perimeter = cv2.arcLength(contour, True)
        irregularity = min(1.0, perimeter / max(1, 2 * (box_width + box_height)))
        confidence = min(0.96, 0.34 + min(relative_width / 0.18, 1) * 0.2 + min(aspect / 4, 1) * 0.14 + irregularity * 0.16 + (1 - min(density / 0.38, 1)) * 0.08)
        if confidence >= 0.6:
            candidates.append({"confidence": round(float(confidence), 3), "contour": contour, "bounding_box": _box(contour, page_number)})
    return max(candidates, key=lambda item: item["confidence"], default=None)


def detect_verification_marks(path: Path) -> dict[str, Any]:
    pages = _document_pages(path)
    qualities = []
    stamps = []
    signatures = []
    for page_number, image in enumerate(pages, start=1):
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        qualities.append({
            "page": page_number,
            "width": image.shape[1],
            "height": image.shape[0],
            "sharpness": round(float(cv2.Laplacian(gray, cv2.CV_64F).var()), 2),
        })
        stamp = _best_stamp_candidate(image, page_number)
        signature = _best_signature_candidate(image, page_number)
        if stamp:
            stamps.append(stamp)
        if signature:
            signatures.append(signature)

    stamp = max(stamps, key=lambda item: item["confidence"], default=None)
    signature = max(signatures, key=lambda item: item["confidence"], default=None)
    if stamp and signature:
        stamp_box, signature_box = stamp["bounding_box"], signature["bounding_box"]
        left, top = max(stamp_box["x"], signature_box["x"]), max(stamp_box["y"], signature_box["y"])
        right = min(stamp_box["x"] + stamp_box["width"], signature_box["x"] + signature_box["width"])
        bottom = min(stamp_box["y"] + stamp_box["height"], signature_box["y"] + signature_box["height"])
        intersection = max(0, right - left) * max(0, bottom - top)
        union = stamp_box["width"] * stamp_box["height"] + signature_box["width"] * signature_box["height"] - intersection
        overlap = intersection / max(1, union)
        if overlap > 0.55 and stamp_box["width"] / max(1, stamp_box["height"]) > 2.2:
            stamp = None
    adequate_quality = bool(qualities) and all(item["width"] >= 500 and item["height"] >= 500 and item["sharpness"] >= 18 for item in qualities)
    types = [kind for kind, candidate in (("stamp", stamp), ("signature", signature)) if candidate]
    detected: bool | None = True if types else (False if adequate_quality else None)
    candidate_confidence = max([item["confidence"] for item in (stamp, signature) if item] or [0.0])
    confidence = candidate_confidence if detected else (0.72 if detected is False else 0.42)
    reason = None
    if detected is None:
        reason = "Kualitas atau resolusi halaman belum cukup untuk memastikan area pengesahan."
    elif detected is False:
        reason = "Area dokumen telah diperiksa dan tidak ada kandidat yang memenuhi ambang minimum."

    if (os.environ.get("DOCUMENT_VERIFICATION_DEBUG") == "1"
            and os.environ.get("APP_ENV", "").lower() in {"local", "testing"}):
        debug_root = Path(__file__).resolve().parent / "debug" / hashlib.sha256(str(path.resolve()).encode()).hexdigest()[:12]
        debug_root.mkdir(parents=True, exist_ok=True)
        for page_number, page_image in enumerate(pages, start=1):
            annotated = page_image.copy()
            for candidate, color in ((stamp, (255, 0, 0)), (signature, (0, 180, 0))):
                if not candidate or candidate["bounding_box"]["page"] != page_number:
                    continue
                box = candidate["bounding_box"]
                cv2.rectangle(annotated, (box["x"], box["y"]), (box["x"] + box["width"], box["y"] + box["height"]), color, 3)
                cv2.putText(annotated, f"{candidate['confidence']:.2f}", (box["x"], max(20, box["y"] - 8)), cv2.FONT_HERSHEY_SIMPLEX, 0.7, color, 2)
            cv2.imwrite(str(debug_root / f"page-{page_number}.jpg"), annotated)

    def public_candidate(candidate: dict[str, Any] | None) -> dict[str, Any]:
        return {
            "detected": candidate is not None,
            "confidence": candidate["confidence"] if candidate else 0.0,
            "page": candidate["bounding_box"]["page"] if candidate else None,
            "bounding_box": {key: candidate["bounding_box"][key] for key in ("x", "y", "width", "height")} if candidate else None,
        }

    return {
        "analyzed": True,
        "detected": detected,
        "confidence": round(float(confidence), 3),
        "types": types,
        "stamp": public_candidate(stamp),
        "signature": public_candidate(signature),
        "requires_manual_review": detected is None,
        "reason": reason,
        "page_quality": qualities,
        "mark_present": detected,
        "mark_authenticity": "not_determined",
    }


def analyze_consistency(result: dict[str, Any], document_type: str) -> dict[str, Any]:
    fields = result.get("specialized_metadata", {})
    metadata = result.get("document_metadata", {})
    checks: dict[str, bool] = {}
    if document_type == "invoice":
        checks = {
            "document_number_present": bool(fields.get("invoice_number")),
            "issuer_and_customer_distinct": bool(fields.get("vendor") and fields.get("customer") and fields.get("vendor") != fields.get("customer")),
            "subtotal_matches_dpp": fields.get("subtotal") is not None and fields.get("subtotal") == fields.get("dpp"),
            "total_matches_dpp_plus_tax": fields.get("total_amount") is not None
                and fields.get("dpp") is not None
                and fields.get("total_amount") == fields.get("dpp") + (fields.get("tax") or 0),
        }
    elif document_type == "surat_jalan":
        checks = {
            "document_number_present": bool(metadata.get("document_number")),
            "document_date_present": bool(metadata.get("document_date")),
            "sender_and_recipient_present": bool(metadata.get("sender") and metadata.get("recipient")),
        }
    else:
        checks = {"document_number_present": bool(result.get("analysis", {}).get("ocr", {}).get("document_number"))}
    passed = sum(checks.values())
    return {
        "analyzed": True,
        "checks": checks,
        "passed": passed,
        "total": len(checks),
        "score": round(passed / max(1, len(checks)) * 100),
    }


def apply_evidence_scores(result: dict[str, Any], file_metadata: dict[str, Any], manipulation: dict[str, Any], consistency: dict[str, Any], verification_mark: dict[str, Any]) -> None:
    authenticity = 50
    if file_metadata.get("analyzed"):
        authenticity += 10
    if consistency.get("score", 0) >= 75:
        authenticity += 15
    elif consistency.get("score", 0) < 50:
        authenticity -= 10
    if manipulation.get("suspicious"):
        authenticity -= 35
    authenticity = max(0, min(100, authenticity))
    result["scores"]["authenticity_score"] = authenticity
    overall = round(
        result["scores"]["readability_score"] * 0.3
        + result["scores"]["completeness_score"] * 0.5
        + authenticity * 0.2
    )
    result["scores"]["overall_score"] = overall
    result["confidence"] = float(overall)

    if manipulation.get("suspicious") and authenticity < 30:
        result["status"] = "PALSU"
    elif manipulation.get("requires_manual_review") or verification_mark["detected"] is not True or authenticity < 65 or result["scores"]["completeness_score"] < 60:
        result["status"] = "MENCURIGAKAN"
    else:
        result["status"] = "ASLI"

    notes = []
    if manipulation.get("suspicious"):
        notes.append("Ditemukan indikasi manipulasi yang perlu diperiksa")
    if consistency.get("score", 0) < 75:
        notes.append("Konsistensi isi dokumen belum cukup")
    types = verification_mark["types"]
    if verification_mark["detected"] is None:
        notes.append("Tanda pengesahan belum dapat dipastikan. Silakan lakukan pemeriksaan manual")
    elif types == ["stamp"]:
        notes.append("Cap/stempel terdeteksi, tetapi tanda tangan belum ditemukan")
    elif types == ["signature"]:
        notes.append("Tanda tangan terdeteksi, tetapi cap/stempel belum ditemukan")
    elif set(types) == {"stamp", "signature"}:
        notes.append("Tanda pengesahan berupa cap dan tanda tangan terdeteksi")
    else:
        notes.append("Tanda pengesahan tidak ditemukan setelah area dokumen diperiksa")
    result["notes"] = "; ".join(notes) + "." if notes else "Dokumen konsisten dan tidak menunjukkan anomali kuat."


def verify_document(path: Path, document_type: str = "surat_jalan") -> dict[str, Any]:
    text = extract_text(path)
    if not text:
        raise DocumentError("Teks tidak terdeteksi pada dokumen.")
    result = analyze_text(text, document_type)
    file_metadata, manipulation = analyze_file_evidence(path)
    consistency = analyze_consistency(result, document_type)
    verification_mark = detect_verification_marks(path)
    result["analysis"]["ocr"]["verification_mark_detected"] = verification_mark["detected"]
    result["analysis"]["ocr"]["verification_mark_types"] = verification_mark["types"]
    result["analysis"]["verification_mark"] = verification_mark
    result["file_metadata"] = file_metadata
    result["analysis"]["file_metadata"] = file_metadata
    result["analysis"]["manipulation"] = manipulation
    result["analysis"]["consistency"] = consistency
    if verification_mark["detected"] is True:
        result["scores"]["completeness_score"] = min(100, result["scores"]["completeness_score"] + 5)
    elif verification_mark["detected"] is False:
        result["scores"]["completeness_score"] = max(0, result["scores"]["completeness_score"] - 10)
    apply_evidence_scores(result, file_metadata, manipulation, consistency, verification_mark)
    return result

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
            "status": "PALSU",
            "confidence": 0.0,
            "notes": str(exc),
            "scores": {
                "readability_score": 0,
                "completeness_score": 0,
                "authenticity_score": 0,
                "overall_score": 0,
            },
            "document_metadata": _empty_document_metadata(),
            "file_metadata": {"analyzed": False, "fields": {}},
            "specialized_metadata": {},
            "analysis": {
                "ocr": {"text_detected": False},
                "document_metadata": _empty_document_metadata(),
                "file_metadata": {"analyzed": False, "fields": {}},
                "metadata": {"analyzed": False, "findings": []},
                "manipulation": {"analyzed": False, "findings": []},
                "barcode": {"detected": False, "decoded": False, "value": None},
            },
        }, ensure_ascii=True))
        return 2

if __name__ == "__main__":
    raise SystemExit(main())
