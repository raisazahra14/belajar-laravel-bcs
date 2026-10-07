<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BarangImportValidationException extends ValidationException
{
    public readonly array $summary;

    public function __construct(array $messages, int $total, int $invalid)
    {
        $validator = Validator::make([], []);
        foreach ($messages as $message) {
            $validator->errors()->add('spreadsheet', $message);
        }
        parent::__construct($validator);

        // An invalid batch is atomic: even its valid rows are not saved.
        $this->summary = ['total' => $total, 'created' => 0, 'updated' => 0, 'failed' => $total, 'invalid' => $invalid];
    }
}
