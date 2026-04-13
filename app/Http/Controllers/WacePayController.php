<?php


namespace App\Http\Controllers;


use App\Http\Helpers\Helpers;
use App\Http\Services\WacePay\WaceApiInterface;
use Illuminate\Http\Client\Request;

class WacePayController extends Controller
{
    private  $waceService;

    public function __construct(WaceApiInterface $waceService)
    {
        $this->waceService = $waceService;
    }


    public function getBankList(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'payer_code' => 'required|string',
        ]);

        try {
            $banks = $this->waceService->getBanks(
                $validated['code'],
                $validated['payer_code']
            );

            return Helpers::success([
                'banks' => $banks->data ?? [],
            ]);

        } catch (\Exception $e) {

            return Helpers::error(
                500,
                'Unable to fetch bank list',
                $e->getMessage()
            );
        }
    }
    public function getPayerCode(Request $request)
    {
        $request->validate([
            'code_iso' => 'required|string',
            'currency' => 'required|string',
        ]);

        $currency = $request->currency;
        $code_iso = $request->code_iso;

       $payerCodeService=$this->waceService->postPayoutService($code_iso,$currency)->data;
        $payerCode=$this->waceService->postPayercode($code_iso,$currency)->data;

        return Helpers::success($data);
    }
}
