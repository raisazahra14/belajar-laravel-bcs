<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StokTransaction extends Model
{
    protected $fillable = [
        'barang_id',
        'supplier_id',
        'warehouse_stock_id',
        'jenis',
        'mutation_type',
        'transfer_group_uuid',
        'reversal_of_id',
        'reversed_at',
        'reversed_by',
        'jumlah',
        'unit_cost',
        'unit_cost_source',
        'stok_sebelum',
        'stok_sesudah',
        'keterangan',
        'reference_type',
        'reference_number',
        'document_date',
        'document_path',
    ];

    protected function casts(): array
    {
        return [
            'unit_cost' => 'decimal:2',
            'document_date' => 'date',
            'reversed_at' => 'datetime',
        ];
    }

    public function barang()
    {
        return $this->belongsTo(Barang::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id')->withTrashed();
    }

    public function warehouseStock(): BelongsTo
    {
        return $this->belongsTo(WarehouseStock::class, 'warehouse_stock_id');
    }

    public function actor(): HasOne
    {
        return $this->hasOne(StokTransactionActor::class, 'stok_transaction_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
