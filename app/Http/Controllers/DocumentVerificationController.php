<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDocumentMetadataRequest;
use App\Http\Requests\VerifyDocumentRequest;
use App\Jobs\ProcessDocumentVerification;
use App\Models\DocumentVerification;
use App\Services\DocumentMetadataMapper;
use App\Services\DocumentVerificationAuditService;
use App\Services\DocumentVerificationResultWriter;
use App\Services\DocumentVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function store(VerifyDocumentRequest $request, DocumentVerificationAuditService $audit): RedirectResponse
    {
        $file = $request->file('document');
        $documentType = $request->string('document_type')->toString();
        $fingerprint = 'document-verification:upload:'.hash_file('sha256', $file->getRealPath()).':'.auth()->id().':'.$documentType;
        $existingId = Cache::get($fingerprint);
        if ($existingId && ($existing = DocumentVerification::whereKey($existingId)->where('user_id', auth()->id())->first())) {
            return redirect()->route('verifications.processing', $existing)
                ->with('success', 'Dokumen ini sudah masuk antrean.');
        }

        $path = $file->store('document-verifications/'.auth()->id(), 'local');
        $verification = DocumentVerification::create([
            'user_id' => auth()->id(), 'document_type' => $documentType,
            'original_filename' => $file->getClientOriginalName(), 'file_path' => $path,
            'status' => 'menunggu', 'readability_score' => 0, 'completeness_score' => 0,
            'authenticity_score' => 0, 'overall_score' => 0,
            'message' => 'Dokumen menunggu antrean.', 'analysis_details' => [],
        ]);
        $requestId = (string) Str::uuid();
        $audit->record($verification, 'document_uploaded', 'user', "verification:{$verification->id}:uploaded", $request->user(), after: ['status' => 'menunggu'], technicalMetadata: ['request_id' => $requestId]);
        $audit->record($verification, 'ocr_queued', 'system', "verification:{$verification->id}:queued:initial", technicalMetadata: ['request_id' => $requestId, 'queue' => 'default']);
        Cache::put($fingerprint, $verification->id, now()->addMinute());
        ProcessDocumentVerification::dispatch($verification->id);

        return redirect()->route('verifications.processing', $verification)
            ->with('success', 'Dokumen berhasil diunggah dan masuk antrean.');
    }

    public function show(DocumentVerification $documentVerification, DocumentMetadataMapper $mapper): View
    {
        $this->authorizeAccess($documentVerification);

        $documentVerification->load(['ocrCorrector', 'audits.user']);

        return view('verifications.show', [
            'verification' => $documentVerification,
            'mappedMetadata' => $mapper->forVerification($documentVerification),
            'metadataFieldStates' => $this->metadataFieldStates($documentVerification, $mapper),
        ]);
    }

    public function processing(DocumentVerification $documentVerification): View
    {
        $this->authorizeAccess($documentVerification);

        return view('verifications.processing', ['verification' => $documentVerification]);
    }

    public function status(DocumentVerification $documentVerification): JsonResponse
    {
        $this->authorizeAccess($documentVerification);

        return response()->json($this->statusPayload($documentVerification->fresh()));
    }

    public function retry(DocumentVerification $documentVerification, DocumentVerificationAuditService $audit): RedirectResponse
    {
        $this->authorizeAccess($documentVerification);
        abort_unless($documentVerification->status === 'gagal_diproses', 422);
        abort_unless(Storage::disk('local')->exists($documentVerification->file_path), 404);

        $requestId = (string) Str::uuid();
        DB::transaction(function () use ($documentVerification, $audit, $requestId): void {
            $before = $audit->values($documentVerification);
            $documentVerification->update(['status' => 'menunggu', 'message' => 'Dokumen menunggu antrean.', 'error_message' => null]);
            $audit->record($documentVerification, 'ocr_retried', 'user', "verification:{$documentVerification->id}:retry:{$requestId}", request()->user(), before: $before, after: $audit->values($documentVerification), technicalMetadata: ['request_id' => $requestId]);
            $audit->record($documentVerification, 'ocr_queued', 'system', "verification:{$documentVerification->id}:queued:{$requestId}", technicalMetadata: ['request_id' => $requestId, 'queue' => 'default']);
        });
        ProcessDocumentVerification::dispatch($documentVerification->id);

        return redirect()->route('verifications.processing', $documentVerification)
            ->with('success', 'Dokumen kembali dimasukkan ke antrean.');
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
        Request $request,
        DocumentVerification $documentVerification,
        DocumentVerificationService $service,
        DocumentVerificationResultWriter $writer,
        DocumentVerificationAuditService $audit,
    ): RedirectResponse {
        $this->authorizeAccess($documentVerification);
        abort_unless(Storage::disk('local')->exists($documentVerification->file_path), 404);
        $requestId = (string) Str::uuid();
        $replaceManual = $request->boolean('replace_manual') || ! $documentVerification->ocr_corrected_at;
        $audit->record(
            $documentVerification,
            'ocr_reprocess_requested',
            'reprocess',
            "verification:{$documentVerification->id}:reprocess_requested:{$requestId}",
            $request->user(),
            technicalMetadata: ['request_id' => $requestId, 'choice' => $replaceManual ? 'replace' : 'preserve'],
        );

        try {
            $result = $service->verify(
                Storage::disk('local')->path($documentVerification->file_path),
                $documentVerification->document_type,
            );

            $writer->complete(
                $documentVerification,
                $result,
                $replaceManual,
                'reprocess',
                "verification:{$documentVerification->id}:reprocess_completed:{$requestId}",
                ['request_id' => $requestId, 'choice' => $replaceManual ? 'replace' : 'preserve'],
            );
            $audit->record(
                $documentVerification->fresh(),
                $replaceManual ? 'manual_corrections_replaced' : 'manual_corrections_preserved',
                'reprocess',
                "verification:{$documentVerification->id}:reprocess_choice:{$requestId}",
                $request->user(),
                technicalMetadata: ['request_id' => $requestId, 'choice' => $replaceManual ? 'replace' : 'preserve'],
            );
            $documentVerification->refresh();
        } catch (RuntimeException $exception) {
            $audit->record(
                $documentVerification->fresh(),
                'ocr_reprocess_failed',
                'reprocess',
                "verification:{$documentVerification->id}:reprocess_failed:{$requestId}",
                $request->user(),
                technicalMetadata: ['request_id' => $requestId, 'choice' => $replaceManual ? 'replace' : 'preserve'],
            );

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
        DocumentVerificationAuditService $audit,
    ): RedirectResponse {
        $requestId = (string) Str::uuid();
        DB::transaction(function () use ($request, $documentVerification, $audit, $requestId): void {
            $before = $audit->values($documentVerification);
            $documentVerification->update([...$request->validated(), 'ocr_corrected_at' => now(), 'ocr_corrected_by' => $request->user()->id]);
            $documentVerification->refresh();
            $audit->record($documentVerification, 'manual_correction_saved', 'user', "verification:{$documentVerification->id}:manual_correction:{$requestId}", $request->user(), before: $before, after: $audit->values($documentVerification), technicalMetadata: ['request_id' => $requestId]);
        });

        return redirect()->route('verifications.show', $documentVerification)
            ->with('success', 'Metadata dokumen berhasil diperbarui.');
    }

    private function statusPayload(DocumentVerification $verification): array
    {
        $state = match ($verification->status) {
            'menunggu' => 'waiting',
            'sedang_dianalisis' => 'processing',
            'gagal_diproses' => 'failed',
            default => 'completed',
        };

        return [
            'state' => $state,
            'label' => match ($state) {
                'waiting' => 'Dokumen menunggu antrean',
                'processing' => 'Engine OCR sedang membaca dokumen',
                'failed' => 'Dokumen belum berhasil dianalisis',
                default => 'Analisis dokumen selesai',
            },
            'message' => $state === 'failed' ? $verification->error_message : $verification->message,
            'result_url' => $state === 'completed' ? route('verifications.show', $verification) : null,
            'retry_url' => $state === 'failed' ? route('verifications.retry', $verification) : null,
        ];
    }

    private function authorizeAccess(DocumentVerification $documentVerification): void
    {
        abort_unless(
            auth()->user()->role === 'admin' || $documentVerification->user_id === auth()->id(),
            403,
        );
    }

    /** @return array<string, array{status: string, label: string, help: string, message: string, confidence: ?string, source_text: ?string}> */
    private function metadataFieldStates(
        DocumentVerification $verification,
        DocumentMetadataMapper $mapper,
    ): array {
        $mapped = $mapper->forVerification($verification);
        $structured = data_get($verification->analysis_details, 'ocr_fields', []);
        $fieldAliases = match ($verification->document_type) {
            'invoice' => ['invoice_number' => 'invoice_number', 'invoice_date' => 'invoice_date'],
            default => [],
        };

        $states = [];
        foreach ($mapped as $field => $value) {
            $ocrField = $fieldAliases[$field] ?? $field;
            $details = is_array($structured) && is_array($structured[$ocrField] ?? null)
                ? $structured[$ocrField]
                : [];
            $value = $mapped[$field] ?? null;
            if ($value !== null && $value !== '') {
                $isCorrected = $verification->ocr_corrected_at !== null;
                $confidence = is_numeric($details['confidence'] ?? null)
                    ? number_format((float) $details['confidence'] * 100, 0).'%' : null;
                $states[$field] = [
                    'status' => $isCorrected ? 'corrected' : (($details['status'] ?? null) === 'review' ? 'review' : 'automatic'),
                    'label' => $isCorrected ? 'Dikoreksi pengguna' : (($details['status'] ?? null) === 'review' ? 'Perlu diperiksa' : 'Terbaca otomatis'),
                    'help' => $isCorrected
                        ? 'Nilai ini berasal dari koreksi pengguna yang terakhir disimpan.'
                        : trim(($details['message'] ?? 'Nilai dikenali dari OCR.').' '.($confidence ? "Confidence {$confidence}." : '').' '.(! empty($details['source_text']) ? "Sumber: {$details['source_text']}" : '')),
                    'message' => $isCorrected ? 'Nilai ini berasal dari koreksi pengguna yang terakhir disimpan.' : ($details['message'] ?? 'Nilai dikenali dari OCR.'),
                    'confidence' => $confidence,
                    'source_text' => is_string($details['source_text'] ?? null) ? $details['source_text'] : null,
                ];
            } elseif (($details['status'] ?? null) === 'review') {
                $states[$field] = [
                    'status' => 'review',
                    'label' => 'Perlu diperiksa',
                    'help' => $details['message'] ?? 'Nilai OCR perlu diperiksa.',
                    'message' => $details['message'] ?? 'Nilai OCR perlu diperiksa.',
                    'confidence' => is_numeric($details['confidence'] ?? null) ? number_format((float) $details['confidence'] * 100, 0).'%' : null,
                    'source_text' => is_string($details['source_text'] ?? null) ? $details['source_text'] : null,
                ];
            } else {
                $states[$field] = [
                    'status' => 'absent',
                    'label' => 'Tidak tercantum / Input manual',
                    'help' => $details['message'] ?? 'Nilai tidak ditemukan atau confidence terlalu rendah.',
                    'message' => $details['message'] ?? 'Nilai tidak ditemukan atau keyakinan OCR terlalu rendah.',
                    'confidence' => is_numeric($details['confidence'] ?? null) ? number_format((float) $details['confidence'] * 100, 0).'%' : null,
                    'source_text' => is_string($details['source_text'] ?? null) ? $details['source_text'] : null,
                ];
            }
        }

        return $states;
    }
}
