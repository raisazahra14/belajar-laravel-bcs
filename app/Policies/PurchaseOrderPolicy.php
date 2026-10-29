<?php

namespace App\Policies;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;

class PurchaseOrderPolicy
{
    /**
     * Menentukan apakah pengguna boleh melihat daftar Purchase Order.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'manager', 'staff'], true);
    }

    /**
     * Menentukan apakah pengguna boleh melihat detail Purchase Order spesifik.
     */
    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return in_array($user->role, ['admin', 'manager', 'staff'], true);
    }

    /**
     * Menentukan apakah pengguna boleh membuat Purchase Order baru.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'manager', 'staff'], true);
    }

    /**
     * Menentukan apakah pengguna boleh mengubah Purchase Order yang masih Draft.
     */
    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if (! $purchaseOrder->status->isEditable()) {
            return false;
        }

        return in_array($user->role, ['admin', 'manager'], true) || $purchaseOrder->created_by === $user->id;
    }

    /**
     * Menentukan apakah pengguna boleh mengajukan PO untuk persetujuan (Draft -> Pending Approval).
     */
    public function submit(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::DRAFT) {
            return false;
        }

        return in_array($user->role, ['admin', 'manager'], true) || $purchaseOrder->created_by === $user->id;
    }

    /**
     * Menentukan apakah pengguna berhak menyetujui PO.
     * Menerapkan prinsip Segregation of Duties (Maker-Checker): Pembuat PO tidak boleh menyetujui PO buatannya sendiri.
     */
    public function approve(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::PENDING_APPROVAL) {
            return false;
        }

        if ($purchaseOrder->created_by === $user->id) {
            return false;
        }

        return in_array($user->role, ['admin', 'manager'], true);
    }

    /**
     * Menentukan apakah pengguna berhak menolak PO.
     * Menerapkan prinsip Segregation of Duties (Maker-Checker): Pembuat PO tidak boleh menolak PO buatannya sendiri.
     */
    public function reject(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if ($purchaseOrder->status !== PurchaseOrderStatus::PENDING_APPROVAL) {
            return false;
        }

        if ($purchaseOrder->created_by === $user->id) {
            return false;
        }

        return in_array($user->role, ['admin', 'manager'], true);
    }

    /**
     * Menentukan apakah pengguna berhak membatalkan PO.
     */
    public function cancel(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if (! $purchaseOrder->status->isCancellable()) {
            return false;
        }

        return in_array($user->role, ['admin', 'manager'], true)
            || ($purchaseOrder->created_by === $user->id && in_array($purchaseOrder->status, [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::REJECTED], true));
    }

    /**
     * Menentukan apakah pengguna berhak mencatat penerimaan barang fisik di gudang.
     * Penerimaan fisik hanya diperuntukkan bagi Staff Gudang (staff) dan Admin operasional.
     */
    public function receive(User $user, PurchaseOrder $purchaseOrder): bool
    {
        if (! $purchaseOrder->status->canReceiveGoods()) {
            return false;
        }

        return in_array($user->role, ['admin', 'staff'], true);
    }

    /**
     * Menentukan apakah pengguna berhak mengunduh atau mencetak dokumen resmi PDF Purchase Order.
     */
    public function viewPdf(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return in_array($user->role, ['admin', 'manager', 'staff'], true);
    }
}
