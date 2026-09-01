<?php

namespace App\Http\Controllers;

use App\Exceptions\StockPredictionException;
use App\Models\Barang;
use App\Models\StockPrediction;
use App\Services\StockPredictionService;
use Illuminate\Http\Request;

class StockPredictionController extends Controller
{
    public function index(Request $request)
    {
        $ids = StockPrediction::query()->selectRaw('MAX(id)')->groupBy('barang_id');
        $predictions = StockPrediction::with('barang')->whereIn('id', $ids)->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))->latest('analyzed_at')->paginate(15)->withQueryString();

        return view('stock-predictions.index', compact('predictions'));
    }

    public function analyze(Barang $barang, StockPredictionService $service)
    {
        $this->authorize('run-stock-prediction');
        try {
            $service->analyze($barang, request()->user());
        } catch (StockPredictionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Prediksi {$barang->nama_barang} berhasil disimpan.");
    }

    public function analyzeAll(StockPredictionService $service)
    {
        $this->authorize('run-stock-prediction');
        try {
            $result = $service->analyzeAll(request()->user());
        } catch (StockPredictionException $e) {
            return back()->with('error', $e->getMessage());
        }

        $success = $result['success'];
        $failed = $result['failed'];

        return back()->with($failed ? 'warning' : 'success', "Analisis selesai: {$success} berhasil".($failed ? ", {$failed} gagal. Data lama tetap aman." : '.'));
    }

    public function approve(StockPrediction $stockPrediction)
    {
        $this->authorize('approve-restock');
        abort_unless($stockPrediction->recommended_restock > 0, 422, 'Prediksi ini tidak memiliki rekomendasi restock.');

        return redirect()->route('barang.stok', ['id' => $stockPrediction->barang_id, 'jenis' => 'masuk', 'jumlah' => $stockPrediction->recommended_restock, 'prediction_id' => $stockPrediction->id]);
    }
}
