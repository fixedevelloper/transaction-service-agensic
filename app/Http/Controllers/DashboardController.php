<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->header('X-User-Id');

        // 🔥 Stats globales
        $totalPayments = DB::table('transactions')
            ->where('initiated_by', $userId)
            ->count();

        $lastPayment = DB::table('transactions')
                ->where('initiated_by', $userId)
                ->latest('created_at')
                ->value('amount') ?? 0;

        $commissions = DB::table('transactions')
                ->where('initiated_by', $userId)
                ->sum('amount') ?? 0;

        $successRate = $this->calculateSuccessRate($userId);

        // 🔥 Recent payments
        $recentPayments = DB::table('transactions')
            ->where('initiated_by', $userId)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get([
                'id',
                'type',
                'amount',
                'status',
                'created_at'
            ])
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'service' => $item->service,
                    'amount' => $item->amount,
                    'status' => $item->status,
                    'date' => Carbon::parse($item->created_at)->format('d/m H:i'),
                ];
            });

        return response()->json([
            'stats' => [
                'totalPayments' => $totalPayments,
                'lastPayment' => $lastPayment,
                'commissions' => $commissions,
                'successRate' => $successRate,
            ],
            'recent' => $recentPayments,
        ]);
    }

    /**
     * 📊 Calcul taux de succès
     */
    private function calculateSuccessRate($userId)
    {
        $total = DB::table('transactions')
            ->where('initiated_by', $userId)
            ->count();

        if ($total === 0) {
            return 0;
        }

        $success = DB::table('transactions')
            ->where('initiated_by', $userId)
            ->where('status', 'success')
            ->count();

        return round(($success / $total) * 100);
    }
}
