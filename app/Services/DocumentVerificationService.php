<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DocumentVerificationService
{
    private const STATUSES = ['valid', 'review', 'suspicious', 'failed'];

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
            $message = is_array($result) ? ($result['message'] ?? null) : null;
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
            && is_int($result['score'] ?? null)
            && $result['score'] >= 0
            && $result['score'] <= 100
            && is_string($result['message'] ?? null)
            && is_array($result['details'] ?? null);
    }
}
