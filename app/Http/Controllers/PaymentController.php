<?php

namespace App\Http\Controllers;

use App\Http\Helpers\Helpers;
use App\Http\Resources\PaymentLinkResource;
use App\Models\PaymentLink;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->header('X-User-Id');

        $payments = PaymentLink::where('user_id', $userId)
            ->latest()
            ->paginate(50);

        return Helpers::success(PaymentLinkResource::collection($payments));
    }

    public function create(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric',
            'country_code' => 'required',
            'name' => 'nullable',
            'description' => 'nullable',
        ]);

        $userId = $request->header('X-User-Id');
        $payment = PaymentLink::create([
            'code' => Str::uuid(),
            'user_id' => $userId,
            'amount' => $data['amount'],
            'country_code' => $data['country_code'],
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'expires_at' => now()->addHours(2),
        ]);

        return response()->json([
            'success' => true,
            'data' => $payment,
            'payment_url' => $payment->payment_url
        ]);
    }

    public function show($code)
    {
        $payment = PaymentLink::where('code', $code)->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $payment
        ]);
    }

    public function status($code)
    {
        $payment = PaymentLink::where('code', $code)->firstOrFail();

        return response()->json([
            'success' => true,
            'status' => $payment->status
        ]);
    }
    public function pay(Request $request)
    {
        $payment = PaymentLink::where('code', $request->code)->firstOrFail();

        if ($payment->isExpired()) {
            return response()->json([
                'success' => false,
                'message' => 'Lien expiré'
            ]);
        }

        $service = app(PaymentService::class);

        $response = $service->payIn([
            'amount' => $payment->amount,
            'user_id' => $payment->user_id,
            'order_id' => $payment->code,
            'phone' => $request->phone,
            'name' => $request->name ?? 'Client',
            'return_url' => route('payment.return'),
            'webhook_url' => route('payment.webhook')
        ]);

        if (!$response['success']) {
            return response()->json($response);
        }

        $data = $response['data'];

        $payment->update([
            'provider' => 'moneyfusion',
            'provider_token' => $data['token'] ?? null,
            'status' => 'pending',
            'customer' => [
                'name' => $request->name,
                'phone' => $request->phone
            ]
        ]);

        return response()->json([
            'success' => true,
            'payment_url' => $data['url']
        ]);
    }
}
