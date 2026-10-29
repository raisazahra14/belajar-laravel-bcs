<?php

namespace App\Console\Commands;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Notifications\PurchaseOrderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class CheckOverduePurchaseOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'po:check-overdue {--notify : Kirim notifikasi peringatan ke Manager dan Admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Periksa dan beri peringatan untuk Purchase Order yang telah melewati estimasi tanggal tiba.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Memeriksa Purchase Order yang melewati estimasi tanggal tiba...');

        $overdueOrders = PurchaseOrder::query()
            ->overdue()
            ->with(['supplier', 'warehouse'])
            ->orderBy('estimasi_tiba')
            ->get();

        $count = $overdueOrders->count();

        if ($count === 0) {
            $this->info('Tidak ada Purchase Order yang terlambat. Semua pesanan berjalan sesuai jadwal.');

            return self::SUCCESS;
        }

        $this->warn("Ditemukan {$count} Purchase Order yang telah melewati estimasi tanggal tiba:");

        $tableRows = $overdueOrders->map(function (PurchaseOrder $po) {
            $daysOverdue = (int) $po->estimasi_tiba?->diffInDays(now()->startOfDay(), false);

            return [
                $po->id,
                $po->nomor_po,
                $po->supplier?->nama_supplier ?? '-',
                $po->warehouse?->nama_gudang ?? '-',
                $po->status->label(),
                $po->estimasi_tiba?->format('d/m/Y') ?? '-',
                $daysOverdue > 0 ? "{$daysOverdue} hari" : 'Hari ini',
            ];
        })->all();

        $this->table(
            ['ID', 'Nomor PO', 'Supplier', 'Gudang', 'Status', 'Est. Tiba', 'Keterlambatan'],
            $tableRows
        );

        if ($this->option('notify')) {
            $this->sendOverdueNotifications($overdueOrders);
        }

        return self::SUCCESS;
    }

    /**
     * Mengirimkan notifikasi peringatan ke seluruh Manager dan Admin operasional secara idempotent.
     *
     * @param  Collection<int, PurchaseOrder>  $orders
     */
    private function sendOverdueNotifications($orders): void
    {
        $recipients = User::query()
            ->whereIn('role', ['manager', 'admin'])
            ->get();

        if ($recipients->isEmpty()) {
            $this->warn('Tidak ada user dengan peran Manager atau Admin untuk menerima notifikasi.');

            return;
        }

        $today = now()->toDateString();
        $notifiedCount = 0;

        foreach ($orders as $po) {
            $cacheKey = "po_overdue_notified:{$po->id}:{$today}";

            // Cegah pengiriman notifikasi berulang pada hari yang sama
            if (Cache::has($cacheKey)) {
                continue;
            }

            Notification::send($recipients, new PurchaseOrderNotification($po, 'overdue'));
            Cache::put($cacheKey, true, now()->endOfDay());
            $notifiedCount++;
        }

        $this->info("Notifikasi berhasil dikirim untuk {$notifiedCount} Purchase Order terlambat.");
    }
}
