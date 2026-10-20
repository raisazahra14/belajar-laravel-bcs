"""Repeatable synthetic OCR latency and accuracy benchmark.

This is a deterministic engineering baseline, not a substitute for a labelled,
production-representative document corpus. No user document is read or stored.
"""

from __future__ import annotations

import argparse
import json
import math
import platform
import re
import statistics
import sys
import tempfile
import time
from pathlib import Path

from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from document_checker import verify_document  # noqa: E402


GROUND_TRUTH = [
    {
        "document_number": "SJ-2026-00001",
        "document_date": "2026-10-01",
        "po_number": "PO-2026-101",
        "do_number": "DO-2026-501",
        "vehicle_number": "B1234XYZ",
        "sender": "PT SUMBER LOGISTIK",
        "recipient": "PT BUANA CENTRA SWAKARSA",
        "gross_weight": 1250,
        "tare_weight": 250,
        "net_weight": 1000,
        "weight_unit": "kg",
    },
    {
        "document_number": "SJ-2026-00002",
        "document_date": "2026-10-02",
        "po_number": "PO-2026-102",
        "do_number": "DO-2026-502",
        "vehicle_number": "D9876AB",
        "sender": "PT NUSANTARA SUPPLY",
        "recipient": "PT BUANA CENTRA SWAKARSA",
        "gross_weight": 850,
        "tare_weight": 150,
        "net_weight": 700,
        "weight_unit": "kg",
    },
    {
        "document_number": "SJ-2026-00003",
        "document_date": "2026-10-03",
        "po_number": "PO-2026-103",
        "do_number": "DO-2026-503",
        "vehicle_number": "F4567CD",
        "sender": "PT MITRA INDUSTRI",
        "recipient": "PT BUANA CENTRA SWAKARSA",
        "gross_weight": 2100,
        "tare_weight": 400,
        "net_weight": 1700,
        "weight_unit": "kg",
    },
]


def font(size: int) -> ImageFont.ImageFont:
    candidates = [
        Path("C:/Windows/Fonts/arial.ttf"),
        Path("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"),
    ]
    match = next((candidate for candidate in candidates if candidate.is_file()), None)
    return ImageFont.truetype(str(match), size) if match else ImageFont.load_default()


def document_text(fields: dict) -> str:
    return "\n".join(
        [
            "SURAT JALAN",
            f"No. Surat Jalan: {fields['document_number']}",
            f"Tanggal: {fields['document_date']}",
            f"No. PO: {fields['po_number']}",
            f"No. DO: {fields['do_number']}",
            f"No. Kendaraan: {fields['vehicle_number']}",
            f"Pengirim: {fields['sender']}",
            f"Penerima: {fields['recipient']}",
            f"Gross Weight: {fields['gross_weight']} kg",
            f"Tare Weight: {fields['tare_weight']} kg",
            f"Net Weight: {fields['net_weight']} kg",
            "Barang diterima dalam kondisi baik",
        ]
    )


def create_document(fields: dict, destination: Path, variant: int) -> str:
    expected = document_text(fields)
    image = Image.new("RGB", (1800, 2400), "white")
    draw = ImageDraw.Draw(image)
    draw.rectangle((90, 90, 1710, 2250), outline="black", width=4)
    draw.multiline_text((150, 150), expected, fill="black", font=font(58), spacing=38)
    if variant == 1:
        image = image.rotate(0.7, resample=Image.Resampling.BICUBIC, expand=False, fillcolor="white")
    elif variant == 2:
        image = ImageEnhance.Contrast(image.filter(ImageFilter.GaussianBlur(0.45))).enhance(0.82)
    image.save(destination, format="PNG", optimize=True)
    return expected


def normalize(value: object) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip().casefold()


def distance(left: list[str] | str, right: list[str] | str) -> int:
    previous = list(range(len(right) + 1))
    for index, lhs in enumerate(left, 1):
        current = [index]
        for other_index, rhs in enumerate(right, 1):
            current.append(min(current[-1] + 1, previous[other_index] + 1, previous[other_index - 1] + (lhs != rhs)))
        previous = current
    return previous[-1]


def error_rate(expected: str, actual: str, words: bool = False) -> float:
    lhs = normalize(expected).split() if words else normalize(expected)
    rhs = normalize(actual).split() if words else normalize(actual)
    return distance(lhs, rhs) / max(1, len(lhs))


def percentile(values: list[float], rank: int) -> float:
    ordered = sorted(values)
    return round(ordered[max(0, math.ceil(rank / 100 * len(ordered)) - 1)], 3)


def run() -> dict:
    samples = []
    with tempfile.TemporaryDirectory(prefix="logistikku-ocr-benchmark-") as directory:
        for index, truth in enumerate(GROUND_TRUTH):
            path = Path(directory) / f"surat-jalan-{index + 1}.png"
            expected_text = create_document(truth, path, index)
            started = time.perf_counter()
            result = verify_document(path, "surat_jalan")
            elapsed_ms = (time.perf_counter() - started) * 1000
            actual_text = result.get("analysis", {}).get("ocr", {}).get("raw_text", "")
            actual_fields = result.get("document_metadata", {})
            fields = {
                key: normalize(actual_fields.get(key)) == normalize(expected)
                for key, expected in truth.items()
            }
            samples.append(
                {
                    "sample": index + 1,
                    "variant": ["clean", "rotated_0_7_degree", "low_contrast_blur"][index],
                    "latency_ms": round(elapsed_ms, 3),
                    "cer": round(error_rate(expected_text, actual_text), 6),
                    "wer": round(error_rate(expected_text, actual_text, words=True), 6),
                    "field_exact_matches": fields,
                    "field_accuracy": round(sum(fields.values()) / len(fields), 6),
                    "process_status": result.get("process_status"),
                }
            )
    latencies = [sample["latency_ms"] for sample in samples]
    return {
        "schema_version": 1,
        "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
        "environment": {"python": platform.python_version(), "platform": platform.platform()},
        "dataset": {"type": "synthetic", "samples": len(samples), "warning": "Tambahkan corpus dokumen riil berlabel dan teranonimisasi sebelum menyimpulkan akurasi produksi."},
        "metrics": {
            "latency_ms": {"mean": round(statistics.mean(latencies), 3), "p50": percentile(latencies, 50), "p95": percentile(latencies, 95), "max": round(max(latencies), 3)},
            "cer_mean": round(statistics.mean(sample["cer"] for sample in samples), 6),
            "wer_mean": round(statistics.mean(sample["wer"] for sample in samples), 6),
            "field_accuracy_mean": round(statistics.mean(sample["field_accuracy"] for sample in samples), 6),
        },
        "samples": samples,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path)
    parser.add_argument("--fixture-output", type=Path, help="Generate one deterministic PNG fixture and exit")
    parser.add_argument("--max-cer", type=float, default=0.20)
    parser.add_argument("--min-field-accuracy", type=float, default=0.75)
    args = parser.parse_args()
    if args.fixture_output:
        args.fixture_output.parent.mkdir(parents=True, exist_ok=True)
        create_document(GROUND_TRUTH[0], args.fixture_output, 0)
        return 0
    result = run()
    violations = []
    if result["metrics"]["cer_mean"] > args.max_cer:
        violations.append(f"CER {result['metrics']['cer_mean']} > {args.max_cer}")
    if result["metrics"]["field_accuracy_mean"] < args.min_field_accuracy:
        violations.append(f"field accuracy {result['metrics']['field_accuracy_mean']} < {args.min_field_accuracy}")
    result.update({"thresholds": {"max_cer": args.max_cer, "min_field_accuracy": args.min_field_accuracy}, "passed": not violations, "violations": violations})
    rendered = json.dumps(result, indent=2, ensure_ascii=False)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(rendered + "\n", encoding="utf-8")
    print(rendered)
    return 0 if not violations else 2


if __name__ == "__main__":
    raise SystemExit(main())
