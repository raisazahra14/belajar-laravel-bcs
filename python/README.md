# Verifikasi Dokumen Python

Program ini membaca surat jalan PDF/JPG/PNG dengan OCR lokal dan mengirim hasil
terstruktur sebagai JSON ke stdout. File pengguna tidak disimpan sebagai output.

## Persiapan

1. Pasang Python 3.10+ dan Tesseract OCR dengan data bahasa Indonesia/Inggris.
2. Buat virtual environment: `python -m venv .venv`.
3. Aktifkan environment lalu jalankan `pip install -r requirements.txt`.
4. Atur `PYTHON_EXECUTABLE` di `.env` Laravel ke lokasi executable environment jika
   perintah `python` bukan interpreter yang diinginkan.

## Menjalankan

```text
python document_checker.py path/to/surat-jalan.pdf
python -m unittest discover -s tests -v
```

Program keluar dengan kode `2` dan pesan di stderr untuk file tidak valid, rusak,
terlalu besar, OCR kosong, atau ketika Tesseract belum tersedia.
