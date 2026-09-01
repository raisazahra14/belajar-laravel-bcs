<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDocumentMetadataRequest;
use App\Http\Requests\VerifyDocumentRequest;
use App\Models\DocumentVerification;
use App\Services\DocumentVerificationService;
use App\Services\DocumentMetadataMapper;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentVerificationController extends Controller
{
    public function index(): View
    {
        $query = DocumentVerification::query()->with('user')->latest();
        if (auth()->user()->role !== 'admin') {
            $query->where('user_id', auth()->id());
        }

        return view('verifications.index', ['verifications' => $query->paginate(10)]);
    }

    public function store(VerifyDocumentRequest $request, DocumentVerificationService $service): RedirectResponse
    {
        $file = $request->file('document');
        $path = $file->store('document-verifications/'.auth()->id(), 'local');

        try {
            $result = $service->verify(
                Storage::disk('local')->path($path),
                $request->string('document_type')->toString(),
            );

            $verification = DB::transaction(fn () => DocumentVerification::create([
                'user_id' => auth()->id(),
                'document_type' => $request->string('document_type')->toString(),
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'status' => strtolower($result['status']),
                'readability_score' => $result['scores']['readability_score'],
                'completeness_score' => $result['scores']['completeness_score'],
                'authenticity_score' => $result['scores']['authenticity_score'],
                'overall_score' => $result['scores']['overall_score'],
                'message' => $result['notes'],
                'analysis_details' => $result['analysis'],
                'extracted_metadata' => $this->specializedMetadata(
                    $result,
                    $request->string('document_type')->toString(),
                ),
                ...$this->metadataFromResult($result, $request->string('document_type')->toString()),
            ]));
        } catch (RuntimeException $exception) {
            $safeMessage = 'Dokumen tidak dapat diproses. Pastikan file dapat dibaca, lalu coba lagi.';
            $verification = DocumentVerification::create([
                'user_id' => auth()->id(),
                'document_type' => $request->string('document_type')->toString(),
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'status' => 'gagal_diproses',
                'readability_score' => 0,
                'completeness_score' => 0,
                'authenticity_score' => 0,
                'overall_score' => 0,
                'message' => 'Verifikasi dokumen gagal.',
                'analysis_details' => [],
                'error_message' => $safeMessage,
            ]);

            return redirect()->route('verifications.show', $verification)
                ->withErrors(['document' => $safeMessage]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return redirect()->route('verifications.show', $verification)
            ->with('success', 'Dokumen berhasil diverifikasi.');
    }

    public function show(DocumentVerification $documentVerification, DocumentMetadataMapper $mapper): View
    {
        $this->authorizeAccess($documentVerification);

        $documentVerification->load('ocrCorrector');

        return view('verifications.show', [
            'verification' => $documentVerification,
            'mappedMetadata' => $mapper->forVerification($documentVerification),
            'metadataFieldStates' => $this->metadataFieldStates($documentVerification, $mapper),
        ]);
    }

    public function download(DocumentVerification $documentVerification): StreamedResponse
    {
        $this->authorizeAccess($documentVerification);
        abort_unless(Storage::disk('local')->exists($documentVerification->file_path), 404);

        return Storage::disk('local')->download(
            $documentVerification->file_path,
            $documentVerification->original_filename,
        );
    }

    public function reprocess(
        DocumentVerification $documentVerification,
        DocumentVerificationService $service,
    ): RedirectResponse {
        $this->authorizeAccess($documentVerification);
        abort_unless(Storage::disk('local')->exists($documentVerification->file_path), 404);

        try {
            $result = $service->verify(
                Storage::disk('local')->path($documentVerification->file_path),
                $documentVerification->document_type,
            );

            DB::transaction(function () use ($documentVerification, $result): void {
                $ocrMetadata = $this->metadataFromResult($result, $documentVerification->document_type);
                $documentVerification->update([
                    'status' => strtolower($result['status']),
                    'readability_score' => $result['scores']['readability_score'],
                    'completeness_score' => $result['scores']['completeness_score'],
                    'authenticity_score' => $result['scores']['authenticity_score'],
                    'overall_score' => $result['scores']['overall_score'],
                    'message' => $result['notes'],
                    'analysis_details' => $result['analysis'],
                    'extracted_metadata' => $this->specializedMetadata($result, $documentVerification->document_type),
                    'ocr_corrected_at' => null,
                    'ocr_corrected_by' => null,
                    'error_message' => null,
                    ...$ocrMetadata,
                ]);
            });
            $documentVerification->refresh();
        } catch (RuntimeException $exception) {
            return redirect()->route('verifications.show', $documentVerification)
                ->withErrors([
                    'document' => 'Proses ulang gagal. Pastikan dokumen dapat dibaca, lalu coba lagi.',
                ]);
        }

        return redirect()->route('verifications.show', $documentVerification)
            ->with('success', 'Dokumen berhasil diproses ulang dengan engine OCR terbaru.');
    }

    public function updateMetadata(
        UpdateDocumentMetadataRequest $request,
        DocumentVerification $documentVerification,
    ): RedirectResponse {
        $documentVerification->update([
            ...$request->validated(),
            'ocr_corrected_at' => now(),
            'ocr_corrected_by' => $request->user()->id,
        ]);

        return redirect()->route('verifications.show', $documentVerification)
            ->with('success', 'Metadata dokumen berhasil diperbarui.');
    }

    private function metadataFromResult(array $result, string $documentType): array
    {
        $ocr = $result['analysis']['ocr'] ?? [];
        $documentMetadata = $result['document_metadata']
            ?? $result['analysis']['document_metadata']
            ?? [];
        if ($documentType === 'surat_jalan' && $documentMetadata === []) {
            // Backward-compatible responses used to expose delivery fields
            // only inside analysis.ocr. Route them through the same mapper so
            // legacy data remains usable without trusting raw noise directly.
            $documentMetadata = [
                'document_number' => $ocr['document_number'] ?? null,
                'document_date' => $this->normalizeDocumentDate($ocr['date'] ?? null),
                'po_number' => $ocr['purchase_order_number'] ?? null,
                'do_number' => null,
                'vehicle_number' => $ocr['vehicle_number'] ?? null,
                'sender' => $ocr['sender'] ?? null,
                'recipient' => $ocr['recipient'] ?? null,
                'gross_weight' => $ocr['total_items'] ?? null,
                'tare_weight' => null,
                'net_weight' => null,
                'weight_unit' => $ocr['total_unit'] ?? null,
            ];
        }
        $specialized = $this->specializedMetadata($result, $documentType);
        $mapped = (new DocumentMetadataMapper)->map($documentType, $documentMetadata, $specialized);
        $weight = $documentType === 'surat_jalan' && $documentMetadata !== []
            ? ($mapped['total_items'] ?? null)
            : ($mapped['total_items'] ?? $ocr['total_items'] ?? null);

        // Delivery notes come from external issuers with varying layouts.
        // The centralized mapper has already rejected labels and table noise;
        // falling back to raw OCR here would reintroduce those invalid values.
        if ($documentType === 'surat_jalan') {
            return [
                'document_number' => $mapped['document_number'] ?? null,
                'document_date' => $this->normalizeDocumentDate($mapped['document_date'] ?? null),
                'purchase_order_number' => $mapped['purchase_order_number'] ?? null,
                'sender' => $mapped['sender'] ?? null,
                'recipient' => $mapped['recipient'] ?? null,
                'vehicle_number' => $mapped['vehicle_number'] ?? null,
                'total_items' => $this->normalizeWholeNumber($weight),
                'ocr_raw_text' => $ocr['raw_text'] ?? null,
            ];
        }

        return [
            'document_number' => $mapped['invoice_number'] ?? $mapped['document_number'] ?? $ocr['document_number'] ?? null,
            'document_date' => $this->normalizeDocumentDate($mapped['invoice_date'] ?? $mapped['document_date'] ?? $ocr['date'] ?? null),
            'purchase_order_number' => $mapped['purchase_order_number'] ?? $ocr['purchase_order_number'] ?? null,
            'sender' => $mapped['vendor'] ?? $mapped['sender'] ?? $ocr['sender'] ?? null,
            'recipient' => $mapped['customer'] ?? $mapped['recipient'] ?? $ocr['recipient'] ?? null,
            'vehicle_number' => $mapped['vehicle_number'] ?? $ocr['vehicle_number'] ?? null,
            'total_items' => $this->normalizeWholeNumber($weight),
            'ocr_raw_text' => $ocr['raw_text'] ?? null,
        ];
    }

    private function normalizeWholeNumber(mixed $value): ?int
    {
        if (is_int($value) || (is_float($value) && floor($value) === $value)) {
            return $value >= 0 ? (int) $value : null;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (preg_match('/^\d{1,3}(?:[.,]\d{3})+$/', $value) === 1) {
            return (int) str_replace([',', '.'], '', $value);
        }

        return null;
    }

    private function specializedMetadata(array $result, string $documentType): array
    {
        // New responses keep document content separate from technical file
        // metadata. Fall back to the legacy location for saved/older engines.
        $fields = $result['specialized_metadata']
            ?? $result['analysis']['metadata']['fields']
            ?? [];
        if (! is_array($fields)) {
            return [];
        }

        $rawText = (string) ($result['analysis']['ocr']['raw_text'] ?? '');
        if (
            $documentType === 'invoice'
            && empty($fields['currency'])
            && preg_match('/\b(?:I[D0]R|1DR|RP|RUPIAH)\b/i', $rawText) === 1
        ) {
            $fields['currency'] = 'IDR';
        }

        return array_filter(
            $fields,
            fn (mixed $value): bool => $value !== null && $value !== '',
        );
    }

    private function authorizeAccess(DocumentVerification $documentVerification): void
    {
        abort_unless(
            auth()->user()->role === 'admin' || $documentVerification->user_id === auth()->id(),
            403,
        );
    }

    private function normalizeDocumentDate(mixed $date): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        $date = str_ireplace(
            ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'],
            ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            trim($date),
        );

        foreach ([
            'd/m/Y', 'd-m-Y', 'd.m.Y',
            'Y-m-d', 'Y/m/d', 'Y.m.d',
            'd/m/y', 'd-m-y',
            'dM Y', 'd M Y', 'd F Y',
        ] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $date);
                if ($parsed !== false && $parsed->format($format) === $date) {
                    return $parsed->format('Y-m-d');
                }
            } catch (Throwable) {
                // Try the next supported OCR date format.
            }
        }

        return null;
    }

    /** @return array<string, array{status: string, label: string, help: string}> */
    private function metadataFieldStates(
        DocumentVerification $verification,
        DocumentMetadataMapper $mapper,
    ): array {
        $mapped = $mapper->forVerification($verification);
        $raw = (string) $verification->ocr_raw_text;
        $labels = [
            'document_number' => '/\b(?:surat\s*jalan|delivery\s*(?:note|order)|no\.?\s*(?:sj|form))\b/i',
            'document_date' => '/\b(?:tanggal|date|tgl)\b/i',
            'purchase_order_number' => '/\b(?:no\.?\s*(?:po|do)|purchase\s*order|delivery\s*order)\b/i',
            'vehicle_number' => '/\b(?:no\.?\s*truk|kendaraan|vehicle|truck|plat|polisi)\b/i',
            'sender' => '/\b(?:pengirim|supplier|sender|departure)\b/i',
            'recipient' => '/\b(?:penerima|recipient|consignee|ship\s*to)\b/i',
            'total_items' => '/\b(?:jumlah|berat|weight|gross|netto|nett|ton|kg)\b/i',
        ];

        $states = [];
        foreach ($labels as $field => $pattern) {
            $value = $mapped[$field] ?? null;
            if ($value !== null && $value !== '') {
                $states[$field] = [
                    'status' => $verification->ocr_corrected_at ? 'corrected' : 'automatic',
                    'label' => $verification->ocr_corrected_at ? 'Dikoreksi pengguna' : 'Terbaca otomatis',
                    'help' => $verification->ocr_corrected_at
                        ? 'Nilai ini berasal dari koreksi pengguna yang terakhir disimpan.'
                        : 'Nilai ini berhasil dikenali dan lolos validasi OCR.',
                ];
            } elseif (preg_match($pattern, $raw) === 1) {
                $states[$field] = [
                    'status' => 'review',
                    'label' => 'Perlu diperiksa',
                    'help' => 'Label atau bagian terkait ditemukan, tetapi nilainya tidak cukup jelas untuk diisi otomatis.',
                ];
            } else {
                $states[$field] = [
                    'status' => 'absent',
                    'label' => 'Tidak tercantum',
                    'help' => 'Label dan nilai untuk kolom ini tidak ditemukan pada hasil OCR.',
                ];
            }
        }

        return $states;
    }
}
