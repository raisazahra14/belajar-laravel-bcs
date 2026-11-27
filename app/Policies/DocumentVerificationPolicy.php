<?php

namespace App\Policies;

use App\Models\DocumentVerification;
use App\Models\User;

class DocumentVerificationPolicy
{
    /**
     * Menentukan apakah pengguna boleh mengakses halaman daftar verifikasi dokumen.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(User::ROLES_ALL);
    }

    /**
     * Menentukan apakah pengguna boleh melihat daftar seluruh verifikasi dokumen lintas pengguna.
     */
    public function viewAll(User $user): bool
    {
        return $user->canViewAllVerifications();
    }

    /**
     * Menentukan apakah pengguna boleh melihat detail, status, pratinjau, atau mengunduh dokumen spesifik.
     */
    public function view(User $user, DocumentVerification $verification): bool
    {
        return $user->canViewAllVerifications() || (int) $verification->user_id === (int) $user->id;
    }

    /**
     * Menentukan apakah pengguna boleh menjalankan aksi mutasi (proses OCR, retry, reprocess, koreksi metadata).
     */
    public function manage(User $user, DocumentVerification $verification): bool
    {
        return $user->isAdmin() || (int) $verification->user_id === (int) $user->id;
    }
}
