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
        'score',
        'message',
        'analysis_details',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'analysis_details' => 'array',
            'score' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
