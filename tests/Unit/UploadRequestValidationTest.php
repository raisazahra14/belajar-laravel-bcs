<?php

namespace Tests\Unit;

use App\Http\Requests\ImportBarangRequest;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UploadRequestValidationTest extends TestCase
{
    public function test_import_size_boundary_is_enforced_by_the_request(): void
    {
        $rules = (new ImportBarangRequest)->rules();

        foreach ([5119, 5120] as $size) {
            $validator = validator(
                ['spreadsheet' => UploadedFile::fake()->create('barang.csv', $size, 'text/csv')],
                $rules,
            );
            $this->assertFalse($validator->fails(), "Ukuran {$size} KiB seharusnya diterima oleh validasi request.");
        }

        $validator = validator(
            ['spreadsheet' => UploadedFile::fake()->create('barang.csv', 5121, 'text/csv')],
            $rules,
        );
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('spreadsheet', $validator->errors()->toArray());
    }
}
