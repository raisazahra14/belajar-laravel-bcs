<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidPurchaseOrderTransitionException;
use InvalidArgumentException;

/**
 * Aturan bisnis perpindahan status Purchase Order.
 *
 * Kelas ini sengaja tidak bergantung pada model/database agar logika inti
 * bisa diuji terpisah dan dipakai ulang oleh controller, job, maupun command.
 *
 * Peran yang dikenal: admin, manager, staff (Staff Gudang).
 */
class PurchaseOrderStateMachine
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_STAFF = 'staff';

    /**
     * Aturan per transisi, dengan kunci "asal->tujuan".
     *
     * - roles      : peran yang selalu boleh menjalankan transisi.
     * - creator    : pembuat PO juga boleh menjalankan transisi apa pun perannya.
     * - not_creator: pembuat PO dilarang menjalankan transisi (pemisahan tugas).
     * - action     : nama aksi bisnis untuk audit log dan label tombol UI.
     *
     * Kewajiban alasan diambil dari PurchaseOrderStatus::requiresReason().
     *
     * @var array<string, array{roles: list<string>, creator: bool, not_creator: bool, action: string}>
     */
    private const RULES = [
        'draft->pending_approval' => [
            'roles' => [self::ROLE_ADMIN], 'creator' => true, 'not_creator' => false, 'action' => 'submit',
        ],
        'draft->cancelled' => [
            'roles' => [self::ROLE_ADMIN, self::ROLE_MANAGER], 'creator' => true, 'not_creator' => false, 'action' => 'cancel',
        ],
        'pending_approval->approved' => [
            'roles' => [self::ROLE_MANAGER], 'creator' => false, 'not_creator' => true, 'action' => 'approve',
        ],
        'pending_approval->rejected' => [
            'roles' => [self::ROLE_MANAGER], 'creator' => false, 'not_creator' => true, 'action' => 'reject',
        ],
        'pending_approval->draft' => [
            'roles' => [self::ROLE_ADMIN], 'creator' => true, 'not_creator' => false, 'action' => 'withdraw',
        ],
        'pending_approval->cancelled' => [
            'roles' => [self::ROLE_ADMIN, self::ROLE_MANAGER], 'creator' => false, 'not_creator' => false, 'action' => 'cancel',
        ],
        'rejected->draft' => [
            'roles' => [self::ROLE_ADMIN], 'creator' => true, 'not_creator' => false, 'action' => 'revise',
        ],
        'rejected->cancelled' => [
            'roles' => [self::ROLE_ADMIN, self::ROLE_MANAGER], 'creator' => true, 'not_creator' => false, 'action' => 'cancel',
        ],
        'approved->partially_received' => [
            'roles' => [self::ROLE_ADMIN, self::ROLE_STAFF], 'creator' => false, 'not_creator' => false, 'action' => 'receive',
        ],
        'approved->completed' => [
            'roles' => [self::ROLE_ADMIN, self::ROLE_STAFF], 'creator' => false, 'not_creator' => false, 'action' => 'receive',
        ],
        'approved->cancelled' => [
            'roles' => [self::ROLE_ADMIN, self::ROLE_MANAGER], 'creator' => false, 'not_creator' => false, 'action' => 'cancel',
        ],
        'partially_received->completed' => [
            'roles' => [self::ROLE_ADMIN, self::ROLE_STAFF], 'creator' => false, 'not_creator' => false, 'action' => 'receive',
        ],
    ];

    /**
     * Memvalidasi transisi dan mengembalikan status tujuan bila sah.
     *
     * @param  int  $itemCount  Jumlah baris item PO (dipakai saat submit).
     *
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function transition(
        PurchaseOrderStatus $from,
        PurchaseOrderStatus $to,
        string $role,
        bool $actorIsCreator = false,
        ?string $reason = null,
        int $itemCount = 0,
    ): PurchaseOrderStatus {
        if (! $from->canTransitionTo($to)) {
            throw InvalidPurchaseOrderTransitionException::notAllowed($from, $to);
        }

        $rule = self::RULES[$this->key($from, $to)];

        if ($rule['not_creator'] && $actorIsCreator) {
            throw InvalidPurchaseOrderTransitionException::guardFailed(
                'Pembuat PO tidak boleh menyetujui atau menolak PO miliknya sendiri.'
            );
        }

        $roleAllowed = in_array($role, $rule['roles'], true);
        $creatorAllowed = $rule['creator'] && $actorIsCreator;

        if (! $roleAllowed && ! $creatorAllowed) {
            throw InvalidPurchaseOrderTransitionException::unauthorized($from, $to);
        }

        if ($to->requiresReason() && trim((string) $reason) === '') {
            throw InvalidPurchaseOrderTransitionException::reasonRequired($to);
        }

        if ($to === PurchaseOrderStatus::PENDING_APPROVAL && $itemCount < 1) {
            throw InvalidPurchaseOrderTransitionException::guardFailed(
                'PO minimal harus memiliki satu item sebelum diajukan.'
            );
        }

        return $to;
    }

    /**
     * Versi boolean dari transition(), cocok untuk menampilkan/menyembunyikan tombol UI.
     */
    public function can(
        PurchaseOrderStatus $from,
        PurchaseOrderStatus $to,
        string $role,
        bool $actorIsCreator = false,
    ): bool {
        if (! $from->canTransitionTo($to)) {
            return false;
        }

        $rule = self::RULES[$this->key($from, $to)];

        if ($rule['not_creator'] && $actorIsCreator) {
            return false;
        }

        return in_array($role, $rule['roles'], true) || ($rule['creator'] && $actorIsCreator);
    }

    /**
     * Daftar status tujuan yang boleh dipilih aktor dari status saat ini.
     *
     * @return list<PurchaseOrderStatus>
     */
    public function availableTransitions(PurchaseOrderStatus $from, string $role, bool $actorIsCreator = false): array
    {
        return array_values(array_filter(
            $from->allowedTransitions(),
            fn (PurchaseOrderStatus $to): bool => $this->can($from, $to, $role, $actorIsCreator),
        ));
    }

    public function actionName(PurchaseOrderStatus $from, PurchaseOrderStatus $to): ?string
    {
        return self::RULES[$this->key($from, $to)]['action'] ?? null;
    }

    /**
     * Menentukan status PO setelah Goods Receipt berdasarkan akumulasi qty per item.
     *
     * Setiap baris berbentuk ['ordered' => int, 'received' => int], di mana `received`
     * adalah total kumulatif yang sudah diterima (termasuk penerimaan saat ini).
     *
     * @param  list<array{ordered: int, received: int}>  $lines
     *
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function resolveReceivingStatus(PurchaseOrderStatus $current, array $lines): PurchaseOrderStatus
    {
        if (! $current->canReceiveGoods()) {
            throw InvalidPurchaseOrderTransitionException::guardFailed(
                "Penerimaan barang tidak dapat dicatat untuk PO berstatus \"{$current->label()}\"."
            );
        }

        if ($lines === []) {
            throw new InvalidArgumentException('Data item PO tidak boleh kosong.');
        }

        $totalReceived = 0;
        $allComplete = true;

        foreach ($lines as $line) {
            $ordered = (int) $line['ordered'];
            $received = (int) $line['received'];

            if ($ordered < 1 || $received < 0) {
                throw new InvalidArgumentException('Qty pesanan harus positif dan qty diterima tidak boleh negatif.');
            }

            if ($received > $ordered) {
                throw InvalidPurchaseOrderTransitionException::guardFailed(
                    'Qty diterima melebihi qty yang dipesan (over-receipt tidak diizinkan).'
                );
            }

            $totalReceived += $received;
            $allComplete = $allComplete && $received === $ordered;
        }

        if ($totalReceived === 0) {
            throw InvalidPurchaseOrderTransitionException::guardFailed(
                'Penerimaan barang harus memuat minimal satu qty yang diterima.'
            );
        }

        return $allComplete ? PurchaseOrderStatus::COMPLETED : PurchaseOrderStatus::PARTIALLY_RECEIVED;
    }

    private function key(PurchaseOrderStatus $from, PurchaseOrderStatus $to): string
    {
        return "{$from->value}->{$to->value}";
    }
}
