<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_prediction_notification_reads')) {
            Schema::create('stock_prediction_notification_reads', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('stock_prediction_notification_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->unique(
                    ['stock_prediction_notification_id', 'user_id'],
                    'prediction_notification_user_unique',
                );
                $table->index(['user_id', 'read_at']);
            });
        }

        Schema::table('stock_prediction_notification_reads', function (Blueprint $table): void {
            $table->foreign('stock_prediction_notification_id', 'prediction_read_notification_fk')
                ->references('id')->on('stock_prediction_notifications')->cascadeOnDelete();
            $table->foreign('user_id', 'prediction_read_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
        });

        $users = DB::table('users')->pluck('id');
        if ($users->isEmpty()) {
            return;
        }

        DB::table('stock_prediction_notifications')->orderBy('id')->chunkById(100, function ($notifications) use ($users): void {
            $now = now();
            $rows = [];
            foreach ($notifications as $notification) {
                foreach ($users as $userId) {
                    $rows[] = [
                        'stock_prediction_notification_id' => $notification->id,
                        'user_id' => $userId,
                        'read_at' => $notification->read_at,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            DB::table('stock_prediction_notification_reads')->insertOrIgnore($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_prediction_notification_reads');
    }
};
