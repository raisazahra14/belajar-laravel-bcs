<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class DocumentVerification extends Model
{
    public const PROCESS_WAITING = 'menunggu';

    public const PROCESSING = 'diproses';

    public const PROCESS_COMPLETED = 'selesai';

    public const PROCESS_FAILED = 'gagal';

    public const AUTHENTIC = 'asli';

    public const SUSPICIOUS = 'mencurigakan';

    public const FAKE = 'palsu';

    public const PROCESS_STATUSES = [self::PROCESS_WAITING, self::PROCESSING, self::PROCESS_COMPLETED, self::PROCESS_FAILED];

    public const AUTHENTICITY_STATUSES = [self::AUTHENTIC, self::SUSPICIOUS, self::FAKE];

    protected $fillable = [
        'user_id',
        'document_type',
        'original_filename',
        'file_path',
        'process_status',
        'authenticity_status',
        'readability_score',
        'completeness_score',
        'authenticity_score',
        'overall_score',
        'document_number',
        'document_date',
        'purchase_order_number',
        'do_number',
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

    protected static function booted(): void
    {
        static::saving(function (DocumentVerification $verification): void {
            if (! in_array($verification->process_status, self::PROCESS_STATUSES, true)) {
                throw new InvalidArgumentException('Status proses OCR tidak valid.');
            }

            if ($verification->process_status === self::PROCESS_COMPLETED) {
                if (! in_array($verification->authenticity_status, self::AUTHENTICITY_STATUSES, true)) {
                    throw new InvalidArgumentException('Hasil keaslian dokumen tidak valid.');
                }
            } elseif ($verification->authenticity_status !== null) {
                throw new InvalidArgumentException('Hasil keaslian hanya boleh disimpan setelah proses OCR selesai.');
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ocrCorrector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ocr_corrected_by');
    }

    public function audits(): HasMany
    {
        return $this->hasMany(DocumentVerificationAudit::class)->oldest();
    }
}
