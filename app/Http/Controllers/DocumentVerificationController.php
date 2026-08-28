<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyDocumentRequest;
use App\Http\Requests\UpdateDocumentMetadataRequest;
use App\Models\DocumentVerification;
use App\Services\DocumentVerificationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
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
                ...$this->metadataFromOcr($result['analysis']['ocr']),
            ]));
        } catch (RuntimeException $exception) {
            $verification = DocumentVerification::create([
                'user_id' => auth()->id(),
                'document_type' => $request->string('document_type')->toString(),
                'original_filename' => $file->getClientOriginalName(),
                'file_path' => $path,
                'status' => 'tidak_terbaca',
                'readability_score' => 0,
                'completeness_score' => 0,
                'authenticity_score' => 0,
                'overall_score' => 0,
                'message' => 'Verifikasi dokumen gagal.',
                'analysis_details' => [],
                'error_message' => $exception->getMessage(),
            ]);

            return redirect()->route('verifications.show', $verification)
                ->withErrors(['document' => $exception->getMessage()]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return redirect()->route('verifications.show', $verification)
            ->with('success', 'Dokumen berhasil diverifikasi.');
    }

    public function show(DocumentVerification $documentVerification): View
    {
        abort_unless(
            auth()->user()->role === 'admin' || $documentVerification->user_id === auth()->id(),
            403,
        );

        return view('verifications.show', ['verification' => $documentVerification->load('ocrCorrector')]);
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

    private function metadataFromOcr(array $ocr): array
    {
        $totalItems = preg_replace('/\D+/', '', (string) ($ocr['total_items'] ?? ''));

        return [
            'document_number' => $ocr['document_number'] ?? null,
            'document_date' => $this->normalizeDocumentDate($ocr['date'] ?? null),
            'purchase_order_number' => $ocr['purchase_order_number'] ?? null,
            'sender' => $ocr['sender'] ?? null,
            'recipient' => $ocr['recipient'] ?? null,
            'vehicle_number' => $ocr['vehicle_number'] ?? null,
            'total_items' => $totalItems !== '' ? (int) $totalItems : null,
            'ocr_raw_text' => $ocr['raw_text'] ?? null,
        ];
    }

    private function normalizeDocumentDate(mixed $date): ?string
    {
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        foreach ([
            'd/m/Y', 'd-m-Y', 'd.m.Y',
            'Y-m-d', 'Y/m/d', 'Y.m.d',
            'd/m/y', 'd-m-y',
            'dM Y', 'd M Y',
        ] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, trim($date));
                if ($parsed !== false && $parsed->format($format) === trim($date)) {
                    return $parsed->format('Y-m-d');
                }
            } catch (Throwable) {
                // Try the next supported OCR date format.
            }
        }

        return null;
    }
}
