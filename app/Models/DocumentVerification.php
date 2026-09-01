<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVerification extends Model
{
    protected $fillable = [
        'user_id',
        'document_type',
        'original_filename',
        'file_path',
        'status',
        'readability_score',
        'completeness_score',
        'authenticity_score',
        'overall_score',
        'document_number',
        'document_date',
        'purchase_order_number',
        'sender',
        'recipient',
        'vehicle_number',
        'total_items',
        'ocr_raw_text',
        'ocr_corrected_at',
        'ocr_corrected_by',
        'message',
        'analysis_details',
        'extracted_metadata',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'analysis_details' => 'array',
            'extracted_metadata' => 'array',
            'readability_score' => 'integer',
            'completeness_score' => 'integer',
            'authenticity_score' => 'integer',
            'overall_score' => 'integer',
            'document_date' => 'date',
            'total_items' => 'integer',
            'ocr_corrected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ocrCorrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ocr_corrected_by');
    }
}
