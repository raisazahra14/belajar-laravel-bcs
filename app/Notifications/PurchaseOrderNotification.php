<?php

namespace App\Notifications;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PurchaseOrderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public string $event, // 'submitted', 'approved', 'rejected', 'overdue'
        public ?User $actor = null,
        public ?string $customMessage = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'purchase-order';
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $nomorPo = $this->purchaseOrder->nomor_po;
        $actorName = $this->actor?->name ?? 'Sistem';

        [$title, $message] = match ($this->event) {
            'submitted' => [
                "Persetujuan PO: {$nomorPo}",
                "Purchase Order {$nomorPo} diajukan oleh {$actorName} dan menunggu persetujuan Anda.",
            ],
            'approved' => [
                "PO Disetujui: {$nomorPo}",
                "Purchase Order {$nomorPo} telah disetujui oleh {$actorName}. Barang siap diterima saat tiba di gudang.",
            ],
            'rejected' => [
                "PO Ditolak: {$nomorPo}",
                "Purchase Order {$nomorPo} telah ditolak oleh {$actorName}.".($this->customMessage ? " Alasan: {$this->customMessage}" : ''),
            ],
            'overdue' => [
                "Peringatan PO Terlambat: {$nomorPo}",
                "Purchase Order {$nomorPo} telah melewati estimasi tanggal tiba (".($this->purchaseOrder->estimasi_tiba?->format('d/m/Y') ?? '—').').',
            ],
            default => [
                "Update PO: {$nomorPo}",
                $this->customMessage ?? "Status Purchase Order {$nomorPo} telah diperbarui.",
            ],
        };

        return [
            'type' => 'purchase-order',
            'purchase_order_id' => $this->purchaseOrder->id,
            'nomor_po' => $nomorPo,
            'event' => $this->event,
            'title' => $title,
            'message' => $message,
            'status' => $this->purchaseOrder->status->value,
            'actor_id' => $this->actor?->id,
            'actor_name' => $actorName,
        ];
    }
}
