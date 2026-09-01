<?php
namespace App\Http\Controllers;
use App\Models\StockPredictionNotification;
use Illuminate\Http\RedirectResponse;
class StockPredictionNotificationController extends Controller
{
 public function read(StockPredictionNotification $notification):RedirectResponse{$notification->update(['read_at'=>$notification->read_at??now()]);return back()->with('success','Notifikasi ditandai sudah dibaca.');}
 public function readAll():RedirectResponse{StockPredictionNotification::whereNull('read_at')->update(['read_at'=>now()]);return back()->with('success','Semua notifikasi ditandai sudah dibaca.');}
}
