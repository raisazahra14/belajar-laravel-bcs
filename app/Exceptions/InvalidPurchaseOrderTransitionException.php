<?php

namespace App\Exceptions;

use App\Enums\PurchaseOrderStatus;
use DomainException;

/**
 * Dilempar ketika perpindahan status PO melanggar aturan bisnis.
 *
 * `httpStatus()` membantu controller memetakan error ke respons yang tepat:
 * 403 untuk masalah hak akses, 422 untuk aturan bisnis/validasi.
 */
class InvalidPurchaseOrderTransitionException extends DomainException
{
    public const NOT_ALLOWED = 'transition_not_allowed';
    public const UNAUTHORIZED = 'unauthorized_role';
    public const REASON_REQUIRED = 'reason_required';
    public const GUARD_FAILED = 'guard_failed';

    public function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function notAllowed(PurchaseOrderStatus $from, PurchaseOrderStatus $to): self
    {
        return new self(
            "Status PO tidak dapat diubah dari \"{$from->label()}\" ke \"{$to->label()}\".",
            self::NOT_ALLOWED,
        );
    }

    public static function unauthorized(PurchaseOrderStatus $from, PurchaseOrderStatus $to): self
    {
        return new self(
            "Anda tidak memiliki hak untuk mengubah status PO dari \"{$from->label()}\" ke \"{$to->label()}\".",
            self::UNAUTHORIZED,
        );
    }

    public static function reasonRequired(PurchaseOrderStatus $to): self
    {
        return new self(
            "Catatan alasan wajib diisi untuk status \"{$to->label()}\".",
            self::REASON_REQUIRED,
        );
    }

    public static function guardFailed(string $message): self
    {
        return new self($message, self::GUARD_FAILED);
    }

    public function httpStatus(): int
    {
        return $this->errorCode === self::UNAUTHORIZED ? 403 : 422;
    }
}
