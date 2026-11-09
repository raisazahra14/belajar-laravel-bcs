<?php

namespace App\Enums;

use App\Services\PurchaseOrderStateMachine;

/**
 * Siklus hidup status Purchase Order (PO).
 *
 * Nilai string disimpan apa adanya di kolom `purchase_orders.status`.
 * Aturan transisi di enum ini hanya menjawab "apakah perpindahan status
 * valid secara alur bisnis". Aturan peran, alasan wajib, dan guard lain
 * dikelola oleh {@see PurchaseOrderStateMachine}.
 */
enum PurchaseOrderStatus: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case PARTIALLY_RECEIVED = 'partially_received';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    /**
     * Peta transisi yang sah: status asal => daftar status tujuan.
     *
     * @return array<string, list<self>>
     */
    public static function transitionMap(): array
    {
        return [
            self::DRAFT->value => [self::PENDING_APPROVAL, self::CANCELLED],
            self::PENDING_APPROVAL->value => [self::APPROVED, self::REJECTED, self::DRAFT, self::CANCELLED],
            self::REJECTED->value => [self::DRAFT, self::CANCELLED],
            self::APPROVED->value => [self::PARTIALLY_RECEIVED, self::COMPLETED, self::CANCELLED],
            self::PARTIALLY_RECEIVED->value => [self::COMPLETED],
            self::COMPLETED->value => [],
            self::CANCELLED->value => [],
        ];
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return self::transitionMap()[$this->value];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING_APPROVAL => 'Menunggu Persetujuan',
            self::APPROVED => 'Disetujui',
            self::REJECTED => 'Ditolak',
            self::PARTIALLY_RECEIVED => 'Diterima Sebagian',
            self::COMPLETED => 'Selesai',
            self::CANCELLED => 'Dibatalkan',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::DRAFT => 'Pesanan dibuat oleh staf/admin gudang dan masih dapat diubah.',
            self::PENDING_APPROVAL => 'Pesanan diajukan dan menunggu persetujuan Manager.',
            self::APPROVED => 'Pesanan disetujui dan resmi dikirim ke supplier.',
            self::REJECTED => 'Pesanan ditolak Manager dengan catatan alasan.',
            self::PARTIALLY_RECEIVED => 'Sebagian barang telah diterima di gudang fisik.',
            self::COMPLETED => 'Seluruh barang telah diterima dan saldo fisik bertambah.',
            self::CANCELLED => 'Pesanan dibatalkan.',
        };
    }

    /**
     * Varian warna badge untuk komponen <x-ui.badge>.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::DRAFT => 'secondary',
            self::PENDING_APPROVAL => 'warning',
            self::APPROVED => 'info',
            self::REJECTED => 'danger',
            self::PARTIALLY_RECEIVED => 'primary',
            self::COMPLETED => 'success',
            self::CANCELLED => 'dark',
        };
    }

    /**
     * Kelas badge Bootstrap untuk tampilan UI.
     */
    public function badgeClass(): string
    {
        return 'badge-'.$this->badgeVariant();
    }

    /**
     * Status akhir: tidak ada transisi lanjutan.
     */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Header dan item PO hanya boleh diubah saat masih DRAFT.
     */
    public function isEditable(): bool
    {
        return $this === self::DRAFT;
    }

    /**
     * Goods Receipt hanya boleh dicatat untuk PO yang sudah resmi dikirim.
     */
    public function canReceiveGoods(): bool
    {
        return in_array($this, [self::APPROVED, self::PARTIALLY_RECEIVED], true);
    }

    /**
     * Status tujuan yang wajib disertai catatan alasan.
     */
    public function requiresReason(): bool
    {
        return in_array($this, [self::REJECTED, self::CANCELLED], true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
