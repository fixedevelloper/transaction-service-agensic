<?php
namespace App\Http\Controllers;

use App\Http\Helpers\Helpers;
use App\Http\Resources\BeneficiaryResource;
use App\Models\Beneficiary;
use Illuminate\Http\Request;

class BeneficiaryController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->header('X-User-Id');
        $beneficiaries = Beneficiary::where('user_id', $userId)->get();
        return Helpers::success( BeneficiaryResource::collection($beneficiaries));
    }

    public function store(Request $request)
    {
        $userId = $request->header('X-User-Id');
        $request->validate([
            'id' => 'nullable|numeric',
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string',
            'bank_account' => 'nullable|string',
            'mobile_wallet' => 'nullable|string',
            'country' => 'nullable|string',
            'city' => 'nullable|string',
            'address' => 'nullable|string',
            'identification_number' => 'nullable|string',
            'identification_type' => 'nullable|string',
            'identification_expired' => 'nullable|date',
        ]);

        $beneficiary = Beneficiary::updateOrCreate(
            [
                'id' => $request->id // condition
            ],
            [
                'user_id' => $userId,
                'code' => $request->id
                    ? $request->code // garder ancien code
                    : strtoupper(uniqid('BEN-')),

                ...$request->only([
                    'name','phone','bank_account','mobile_wallet',
                    'country','city','address','identification_number',
                    'identification_type','identification_expired'
                ])
            ]
        );

        return  Helpers::success($beneficiary, 201);
    }

    public function show(Beneficiary $beneficiary)
    {
        return Helpers::success(new BeneficiaryResource($beneficiary));
    }

    public function update(Request $request, Beneficiary $beneficiary)
    {
        $beneficiary->update($request->only([
            'name','phone','bank_account','mobile_wallet','country',
            'city','address','identification_number','identification_type',
            'identification_expired','status'
        ]));

        return response()->json($beneficiary);
    }

    public function destroy(Beneficiary $beneficiary)
    {
        $beneficiary->delete();
        return response()->json(['message'=>'Deleted']);
    }
}
