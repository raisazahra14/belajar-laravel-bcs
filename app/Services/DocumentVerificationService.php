<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DocumentVerificationService
{
    private const STATUSES = ['LENGKAP', 'PERLU_DITINJAU', 'TERINDIKASI_MANIPULASI', 'TIDAK_TERBACA'];

    private const SCORES = [
        'readability_score',
        'completeness_score',
        'authenticity_score',
        'overall_score',
    ];

    private const ANALYSIS_SECTIONS = ['ocr', 'metadata', 'manipulation', 'barcode'];

    public function verify(string $documentPath, string $documentType = 'surat_jalan'): array
    {
        $script = base_path('python/document_checker.py');
        if (! is_file($script)) {
            Log::error('Document checker script was not found.', ['script' => $script]);
            throw new RuntimeException('Program verifikasi dokumen tidak ditemukan.');
        }

        $process = new Process([
            (string) config('services.document_checker.python_executable'),
            $script,
            $documentPath,
            '--document-type',
            $documentType,
        ], null, [
            // Some Windows web-server accounts cannot access the OS entropy
            // source during Python startup. A fixed seed is safe here because
            // this short-lived local OCR process does not use hashing for
            // security-sensitive operations.
            'PYTHONHASHSEED' => '0',
        ]);
        $process->setTimeout((float) config('services.document_checker.timeout', 60));

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            Log::warning('Document verification timed out.', [
                'document_type' => $documentType,
                'timeout' => $process->getTimeout(),
            ]);
            throw new RuntimeException('Verifikasi melewati batas waktu. Silakan coba lagi.', 0, $exception);
        }

        $result = json_decode(trim($process->getOutput()), true);

        if (! $process->isSuccessful()) {
            Log::error('Python document verification failed.', [
                'exit_code' => $process->getExitCode(),
                'stderr' => trim($process->getErrorOutput()),
            ]);
            $message = is_array($result) ? ($result['notes'] ?? null) : null;
            throw new RuntimeException($message ?: 'Dokumen tidak dapat diverifikasi. Pastikan file dapat dibaca.');
        }

        if (! $this->hasValidContract($result)) {
            Log::error('Python document verification returned invalid JSON.', [
                'output' => mb_substr($process->getOutput(), 0, 1000),
            ]);
            throw new RuntimeException('Respons program verifikasi dokumen tidak valid.');
        }

        return $result;
    }

    private function hasValidContract(mixed $result): bool
    {
        return is_array($result)
            && in_array($result['status'] ?? null, self::STATUSES, true)
            && is_float($result['confidence'] ?? null)
            && $result['confidence'] >= 0
            && $result['confidence'] <= 100
            && is_string($result['notes'] ?? null)
            && is_array($result['scores'] ?? null)
            && collect(self::SCORES)->every(fn (string $score): bool =>
                is_int($result['scores'][$score] ?? null)
                && $result['scores'][$score] >= 0
                && $result['scores'][$score] <= 100
            )
            && $result['confidence'] === (float) $result['scores']['overall_score']
            && is_array($result['analysis'] ?? null)
            && collect(self::ANALYSIS_SECTIONS)->every(
                fn (string $section): bool => is_array($result['analysis'][$section] ?? null)
            );
    }
}
