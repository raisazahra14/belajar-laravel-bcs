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

    public function test_legacy_contract_without_document_metadata_is_still_accepted(): void
    {
        $this->assertTrue($this->validateContract(self::contractFixture()));
    }

    public function test_new_document_metadata_contract_requires_every_field(): void
    {
        $contract = self::contractFixture();
        $contract['document_metadata'] = array_fill_keys([
            'document_number', 'document_date', 'po_number', 'do_number', 'vehicle_number',
            'sender', 'recipient', 'gross_weight', 'tare_weight', 'net_weight', 'weight_unit',
        ], null);
        $this->assertTrue($this->validateContract($contract));

        unset($contract['document_metadata']['do_number']);
        $this->assertFalse($this->validateContract($contract));
    }

    public function test_new_verification_mark_contract_accepts_true_false_and_null(): void
    {
        foreach ([true, false, null] as $detected) {
            $contract = self::contractFixture();
            $contract['analysis']['verification_mark'] = [
                'analyzed' => true,
                'detected' => $detected,
                'confidence' => $detected === null ? 0.42 : 0.82,
                'types' => $detected === true ? ['stamp', 'signature'] : [],
                'requires_manual_review' => $detected === null,
                'reason' => $detected === null ? 'Kualitas belum cukup.' : null,
            ];
            $this->assertTrue($this->validateContract($contract));
        }
    }

    public function test_invalid_verification_mark_confidence_is_rejected(): void
    {
        $contract = self::contractFixture();
        $contract['analysis']['verification_mark'] = [
            'analyzed' => true, 'detected' => null, 'confidence' => 1.2,
            'types' => [], 'requires_manual_review' => true, 'reason' => 'Buram.',
        ];
        $this->assertFalse($this->validateContract($contract));
    }

    public function test_structured_ela_contract_is_validated_without_breaking_legacy_results(): void
    {
        $contract = self::contractFixture();
        $contract['analysis']['manipulation'] = [
            'analyzed' => true, 'method' => 'ela', 'suspicious' => false,
            'risk_score' => 18.5, 'risk_level' => 'low', 'requires_manual_review' => false,
            'suspicious_regions' => [[
                'page' => 1, 'x' => 10, 'y' => 20, 'width' => 100, 'height' => 30,
                'score' => 58.2, 'target' => 'nominal', 'text' => 'TOTAL 100.000',
            ]],
            'findings' => ['Tidak ditemukan anomali kuat.'], 'metrics' => [], 'limitations' => [],
        ];
        $this->assertTrue($this->validateContract($contract));

        $contract['analysis']['manipulation']['risk_score'] = 120;
        $this->assertFalse($this->validateContract($contract));
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

        return $method->invoke(new DocumentVerificationService, $contract);
    }

    private function validContract(): array
    {
        return self::contractFixture();
    }

    private static function contractFixture(): array
    {
        return [
            'status' => 'MENCURIGAKAN',
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
