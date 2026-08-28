<?php

namespace Tests\Unit;

use App\Services\DocumentVerificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class DocumentVerificationContractTest extends TestCase
{
    public function test_complete_python_contract_is_accepted(): void
    {
        $this->assertTrue($this->validateContract($this->validContract()));
    }

    #[DataProvider('invalidContracts')]
    public function test_incomplete_or_invalid_python_contract_is_rejected(array $contract): void
    {
        $this->assertFalse($this->validateContract($contract));
    }

    public static function invalidContracts(): array
    {
        $missingAnalysisSection = self::contractFixture();
        unset($missingAnalysisSection['analysis']['barcode']);

        $integerConfidence = self::contractFixture();
        $integerConfidence['confidence'] = 72;

        $mismatchedConfidence = self::contractFixture();
        $mismatchedConfidence['confidence'] = 70.0;

        return [
            'missing analysis section' => [$missingAnalysisSection],
            'confidence must be a JSON float' => [$integerConfidence],
            'confidence must match overall score' => [$mismatchedConfidence],
        ];
    }

    private function validateContract(array $contract): bool
    {
        $method = new ReflectionMethod(DocumentVerificationService::class, 'hasValidContract');

        return $method->invoke(new DocumentVerificationService(), $contract);
    }

    private function validContract(): array
    {
        return self::contractFixture();
    }

    private static function contractFixture(): array
    {
        return [
            'status' => 'PERLU_DITINJAU',
            'confidence' => 72.0,
            'notes' => 'Tanggal tidak ditemukan.',
            'scores' => [
                'readability_score' => 90,
                'completeness_score' => 64,
                'authenticity_score' => 50,
                'overall_score' => 72,
            ],
            'analysis' => [
                'ocr' => [],
                'metadata' => [],
                'manipulation' => [],
                'barcode' => [],
            ],
        ];
    }
}
