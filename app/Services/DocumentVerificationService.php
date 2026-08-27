<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\Process\Process;

class DocumentVerificationService
{
    public function verify(UploadedFile $document): array
    {
        $script = base_path('python/document_checker.py');
        if (! is_file($script)) {
            throw new RuntimeException('Program verifikasi dokumen tidak ditemukan.');
        }

        $process = new Process([
            (string) config('services.document_checker.python_binary'),
            $script,
            $document->getRealPath(),
        ]);
        $process->setTimeout((float) config('services.document_checker.timeout', 60));
        $process->run();

        if (! $process->isSuccessful()) {
            $message = trim($process->getErrorOutput()) ?: 'Verifikasi dokumen gagal dijalankan.';
            throw new RuntimeException($message);
        }

        $result = json_decode($process->getOutput(), true);
        if (! is_array($result)) {
            throw new RuntimeException('Respons program verifikasi dokumen tidak valid.');
        }

        return $result;
    }
}
