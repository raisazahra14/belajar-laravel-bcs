<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DocumentVerificationService
{
    private const STATUSES = ['ASLI', 'MENCURIGAKAN', 'PALSU'];

    private const SCORES = [
        'readability_score',
        'completeness_score',
        'authenticity_score',
        'overall_score',
    ];

    private const ANALYSIS_SECTIONS = ['ocr', 'metadata', 'manipulation', 'barcode'];

    private const DOCUMENT_METADATA_FIELDS = [
        'document_number', 'document_date', 'po_number', 'do_number', 'vehicle_number',
        'sender', 'recipient', 'gross_weight', 'tare_weight', 'net_weight', 'weight_unit',
    ];

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
        $process->setWorkingDirectory(base_path('python'));

        // Apache on Windows may start PHP without these variables. Python and
        // NumPy then cannot initialise the Windows crypto provider, even though
        // the exact same OCR command works from PowerShell.
        $windowsDirectory = getenv('SystemRoot') ?: 'C:\\Windows';
        $process->setEnv([
            'SYSTEMROOT' => $windowsDirectory,
            'WINDIR' => $windowsDirectory,
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

        // Keep compatibility with results produced by the previous checker build.
        if (is_array($result) && ($result['status'] ?? null) === 'PERLU_DITINJAU') {
            $result['status'] = 'MENCURIGAKAN';
        }

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
            && collect(self::SCORES)->every(fn (string $score): bool => is_int($result['scores'][$score] ?? null)
                && $result['scores'][$score] >= 0
                && $result['scores'][$score] <= 100
            )
            && $result['confidence'] === (float) $result['scores']['overall_score']
            && is_array($result['analysis'] ?? null)
            && collect(self::ANALYSIS_SECTIONS)->every(
                fn (string $section): bool => is_array($result['analysis'][$section] ?? null)
            )
            && (! array_key_exists('document_metadata', $result)
                || $this->hasValidDocumentMetadata($result['document_metadata']))
            && (! array_key_exists('verification_mark', $result['analysis'])
                || $this->hasValidVerificationMark($result['analysis']['verification_mark']))
            && (! isset($result['analysis']['manipulation']['method'])
                || $result['analysis']['manipulation']['method'] !== 'ela'
                || $this->hasValidEla($result['analysis']['manipulation']));
    }

    private function hasValidDocumentMetadata(mixed $metadata): bool
    {
        return is_array($metadata)
            && collect(self::DOCUMENT_METADATA_FIELDS)->every(fn (string $field): bool => array_key_exists($field, $metadata))
            && collect(['document_number', 'document_date', 'po_number', 'do_number', 'vehicle_number', 'sender', 'recipient', 'weight_unit'])
                ->every(fn (string $field): bool => $metadata[$field] === null || is_string($metadata[$field]))
            && collect(['gross_weight', 'tare_weight', 'net_weight'])
                ->every(fn (string $field): bool => $metadata[$field] === null || (is_numeric($metadata[$field]) && $metadata[$field] >= 0));
    }

    private function hasValidVerificationMark(mixed $mark): bool
    {
        return is_array($mark)
            && is_bool($mark['analyzed'] ?? null)
            && array_key_exists('detected', $mark)
            && (($mark['detected'] ?? null) === null || is_bool($mark['detected']))
            && is_numeric($mark['confidence'] ?? null)
            && $mark['confidence'] >= 0
            && $mark['confidence'] <= 1
            && is_array($mark['types'] ?? null)
            && collect($mark['types'])->every(fn (mixed $type): bool => in_array($type, ['stamp', 'signature'], true))
            && is_bool($mark['requires_manual_review'] ?? null)
            && array_key_exists('reason', $mark);
    }

    private function hasValidEla(mixed $ela): bool
    {
        return is_array($ela)
            && is_bool($ela['analyzed'] ?? null)
            && is_bool($ela['suspicious'] ?? null)
            && is_numeric($ela['risk_score'] ?? null)
            && $ela['risk_score'] >= 0 && $ela['risk_score'] <= 100
            && in_array($ela['risk_level'] ?? null, ['low', 'medium', 'high', 'not_available'], true)
            && is_bool($ela['requires_manual_review'] ?? null)
            && is_array($ela['suspicious_regions'] ?? null)
            && collect($ela['suspicious_regions'])->every(fn (mixed $region): bool => is_array($region)
                && collect(['page', 'x', 'y', 'width', 'height'])->every(fn (string $key): bool => is_int($region[$key] ?? null) && $region[$key] >= 0)
                && is_numeric($region['score'] ?? null) && $region['score'] >= 0 && $region['score'] <= 100
                && in_array($region['target'] ?? null, ['tanggal', 'nominal'], true))
            && is_array($ela['findings'] ?? null)
            && is_array($ela['metrics'] ?? null)
            && is_array($ela['limitations'] ?? null);
    }
}
