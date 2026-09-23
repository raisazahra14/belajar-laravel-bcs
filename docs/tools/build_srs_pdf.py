"""Build the LogistikKu SRS PDF without downloading project dependencies.

Requirements already present on the build machine:
- Python 3 with Mistune 2.x and PyMuPDF
- Microsoft Edge or Google Chrome with headless PDF support

The command writes only the requested PDF. Temporary HTML and browser-profile
files are kept under storage/pdf-build and removed after the browser exits.
"""

from __future__ import annotations

import argparse
import hashlib
import re
import shutil
import subprocess
import sys
import time
from pathlib import Path

import mistune
import pymupdf


ROOT = Path(__file__).resolve().parents[2]
DOCS = ROOT / "docs"
SOURCE = DOCS / "srs.md"
BUILD_DIR = ROOT / "storage" / "pdf-build"

BROWSERS = (
    Path(r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"),
    Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe"),
    Path(r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"),
    Path(r"C:\Program Files\Microsoft\Edge\Application\msedge.exe"),
)

DIAGRAMS = (
    ("images/tugas-1-2-use-case.svg", "Use Case Diagram Sistem LogistikKu"),
    ("images/tugas-1-2-activity-stok.svg", "Activity Diagram Stok Masuk/Keluar"),
    ("images/tugas-1-2-activity-verifikasi.svg", "Activity Diagram Verifikasi Dokumen"),
)

CSS = r"""
@page {
    size: A4;
    margin: 15mm 13mm 17mm;
}

* { box-sizing: border-box; }
html { color: #172033; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
body {
    margin: 0;
    font-family: "Arial", "Segoe UI", sans-serif;
    font-size: 9.2pt;
    line-height: 1.4;
}
h1, h2, h3, h4 { color: #123968; break-after: avoid-page; }
h1 { margin: 0 0 4mm; font-size: 24pt; text-align: center; }
h2 { margin: 5mm 0 3mm; font-size: 16pt; border-bottom: 1.5pt solid #2f669f; padding-bottom: 1.5mm; }
h3 { margin: 4mm 0 2mm; font-size: 12.5pt; }
h4 { margin: 3mm 0 1.5mm; font-size: 10.5pt; }
p { margin: 1.5mm 0 2.2mm; orphans: 3; widows: 3; }
ul, ol { margin: 1.5mm 0 2.5mm 5mm; padding-left: 4mm; }
li { margin-bottom: 0.8mm; }
a { color: #174f87; text-decoration: none; }
code {
    font-family: "Cascadia Mono", Consolas, monospace;
    font-size: 8.1pt;
    overflow-wrap: anywhere;
    color: #7b2d26;
}
pre {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    padding: 2.5mm;
    background: #f4f7fa;
    border: 0.5pt solid #d6dee8;
    border-radius: 2mm;
    break-inside: avoid-page;
}
table {
    width: 100%;
    margin: 2.5mm 0 4mm;
    border-collapse: collapse;
    table-layout: auto;
    font-size: 7.65pt;
}
thead { display: table-header-group; }
tr { break-inside: avoid-page; }
th, td {
    border: 0.45pt solid #9eacbd;
    padding: 1.25mm 1.5mm;
    text-align: left;
    vertical-align: top;
    overflow-wrap: anywhere;
}
th { color: #102f52; background: #dceaf7; font-weight: 700; }
tbody tr:nth-child(even) td { background: #f8fafc; }
blockquote {
    margin: 2mm 0;
    padding: 1.5mm 3mm;
    border-left: 2.5pt solid #5c91c7;
    background: #f4f8fc;
}
hr { border: 0; border-top: 0.6pt solid #b9c4d0; margin: 4mm 0; }
.page-break { break-before: page; height: 0; }
.cover-note {
    margin: 4mm 0;
    padding: 3mm;
    border: 0.8pt solid #b7791f;
    background: #fff9e8;
    color: #573b0c;
}
figure.diagram {
    margin: 4mm 0;
    break-inside: avoid-page;
    text-align: center;
}
figure.diagram img {
    display: block;
    width: 100%;
    max-height: 235mm;
    margin: 0 auto;
    object-fit: contain;
}
figure.diagram figcaption {
    margin-top: 2mm;
    font-size: 8pt;
    color: #4c5c70;
}
"""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Render docs/srs.md as an A4 PDF.")
    parser.add_argument("--output", type=Path, required=True, help="Candidate PDF output path")
    return parser.parse_args()


def browser_path() -> Path:
    for candidate in BROWSERS:
        if candidate.is_file():
            return candidate
    raise RuntimeError("Microsoft Edge atau Google Chrome tidak ditemukan.")


def prepare_markdown(source: str) -> str:
    required = (
        "SRS-LOGISTIKKU-1.3",
        "| Versi | 1.3 |",
        "| Tanggal pembaruan | 21 September 2026 |",
        "## 12. Matriks Ketertelusuran Kebutuhan",
        "### 12.3 Bukti eksekusi audit 21 September 2026",
        "Perlu Uji Produksi",
    )
    missing = [marker for marker in required if marker not in source]
    if missing:
        raise RuntimeError("SRS bukan baseline 1.3 yang diharapkan: " + ", ".join(missing))

    # A page break already separates major sections in the PDF. Keeping the
    # preceding Markdown horizontal rule can create a page containing only a
    # rule when the prior diagram fills the page.
    source = re.sub(r"(?m)^---\s*\n(?=\s*<a id=)", "", source)
    source = re.sub(
        r"(?m)^(## (?:[1-9]|1[0-3])\. )",
        r'<div class="page-break"></div>\n\n\1',
        source,
    )
    source = re.sub(
        r"(?m)^(### 10\.[23] )",
        r'<div class="page-break"></div>\n\n\1',
        source,
    )

    diagram_index = 0

    def replace_diagram(_: re.Match[str]) -> str:
        nonlocal diagram_index
        if diagram_index >= len(DIAGRAMS):
            raise RuntimeError("Jumlah diagram Mermaid pada SRS melebihi kontrak builder.")
        path, caption = DIAGRAMS[diagram_index]
        diagram_index += 1
        return (
            f'\n\n<figure class="diagram"><img src="{path}" alt="{caption}">'
            f"<figcaption>{caption}</figcaption></figure>\n\n"
        )

    source = re.sub(r"(?ms)^```mermaid\s*\n.*?^```\s*$", replace_diagram, source)
    source = re.sub(r"(?m)^\[Buka render SVG[^\n]*$", "", source)
    if diagram_index != len(DIAGRAMS):
        raise RuntimeError(
            f"SRS memuat {diagram_index} diagram, diharapkan {len(DIAGRAMS)} diagram."
        )
    return source


def render_html(markdown_source: str) -> str:
    renderer = mistune.HTMLRenderer(escape=False)
    markdown = mistune.create_markdown(renderer=renderer, plugins=["table"])
    body = markdown(markdown_source)
    base_uri = DOCS.resolve().as_uri() + "/"
    return f"""<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <base href="{base_uri}">
  <title>SRS LogistikKu 1.3</title>
  <style>{CSS}</style>
</head>
<body>
{body}
</body>
</html>
"""


def attach_source_metadata(output: Path, source: str) -> str:
    source_hash = hashlib.sha256(source.encode("utf-8")).hexdigest().upper()
    document = pymupdf.open(output)
    try:
        metadata = document.metadata
        metadata.update(
            {
                "title": "Software Requirements Specification (SRS) Sistem Inventaris LogistikKu",
                "author": "Tim LogistikKu",
                "subject": f"SRS versi 1.3; source docs/srs.md SHA-256 {source_hash}",
                "keywords": "LogistikKu, SRS, inventaris, OCR, prediksi stok, versi 1.3",
            }
        )
        document.set_metadata(metadata)
        document.saveIncr()
    finally:
        document.close()
    return source_hash


def safe_clean_build_dir(*, strict: bool = True) -> None:
    resolved = BUILD_DIR.resolve()
    expected_parent = (ROOT / "storage").resolve()
    if resolved.parent != expected_parent or resolved.name != "pdf-build":
        raise RuntimeError(f"Direktori build tidak aman: {resolved}")
    if not resolved.exists():
        return
    for attempt in range(20):
        try:
            shutil.rmtree(resolved)
            return
        except PermissionError:
            if attempt == 19:
                if strict:
                    raise
                print(f"WARNING: direktori sementara belum dapat dihapus: {resolved}", file=sys.stderr)
                return
            time.sleep(0.25)


def main() -> int:
    args = parse_args()
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    if output.exists():
        raise RuntimeError(
            "Output sudah ada; gunakan path kandidat baru agar PDF terbitan tidak tertimpa sebelum divalidasi."
        )

    source = SOURCE.read_text(encoding="utf-8")
    prepared = prepare_markdown(source)
    html = render_html(prepared)

    safe_clean_build_dir()
    BUILD_DIR.mkdir(parents=True)
    html_path = BUILD_DIR / "srs-v1.3.html"
    profile_path = BUILD_DIR / "browser-profile"
    html_path.write_text(html, encoding="utf-8")

    command = [
        str(browser_path()),
        "--headless=new",
        "--disable-gpu",
        "--disable-extensions",
        "--disable-default-apps",
        "--disable-breakpad",
        "--disable-crash-reporter",
        "--no-first-run",
        "--no-pdf-header-footer",
        f"--user-data-dir={profile_path}",
        f"--print-to-pdf={output}",
        html_path.resolve().as_uri(),
    ]

    try:
        completed = subprocess.run(command, check=False, timeout=120)
        for _ in range(60):
            if output.is_file() and output.stat().st_size > 0:
                break
            time.sleep(0.25)
        if completed.returncode != 0 or not output.is_file() or output.stat().st_size == 0:
            raise RuntimeError(
                f"Browser gagal membuat PDF (exit code {completed.returncode})."
            )
        source_hash = attach_source_metadata(output, source)
        print(f"PDF_CANDIDATE={output}")
        print(f"PDF_BYTES={output.stat().st_size}")
        print(f"SOURCE_SHA256={source_hash}")
        return 0
    finally:
        safe_clean_build_dir(strict=False)


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)
