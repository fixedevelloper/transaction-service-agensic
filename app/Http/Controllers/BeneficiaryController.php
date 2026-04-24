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

    $validated = $request->validate([
        'id' => 'nullable|numeric|exists:beneficiaries,id', // Vérifie si l'ID existe si fourni
        'name' => 'required|string|max:255',
        'account_type' => 'nullable|in:B,P', // Validation stricte du mappage
        'date_birth' => 'nullable|date_format:Y-m-d',
        'business_name' => 'nullable|string|max:255',
        'business_type' => 'nullable|string',
        'business_register_date' => 'nullable|date_format:Y-m-d',
        'phone' => 'nullable|string',
        'bank_account' => 'nullable|string',
        'mobile_wallet' => 'nullable|string',
        'country' => 'nullable|string|size:2', // Si vous utilisez les codes ISO (ex: FR, CM)
        'city' => 'nullable|string',
        'address' => 'nullable|string',
        'identification_number' => 'nullable|string',
        'identification_type' => 'nullable|string',
        'identification_expired' => 'nullable|date',
    ]);

    // Préparation des données (gestion des valeurs vides)
    $data = $request->only([
        'name','phone','bank_account','mobile_wallet','account_type',
        'business_type','business_name','date_birth','country',
        'city','address','identification_number','identification_type',
        'identification_expired','business_register_date'
    ]);

    // On s'assure que le user_id est toujours présent
    $data['user_id'] = $userId;

    // Sécurité : Si on modifie (ID présent), on vérifie la propriété
    $matchAttributes = ['id' => $request->id];
    if ($request->id) {
        $exists = Beneficiary::where('id', $request->id)
                             ->where('user_id', $userId)
                             ->exists();
        if (!$exists) {
            return Helpers::error("Ce bénéficiaire ne vous appartient pas.", 403);
        }
    } else {
        // Pour une création, on laisse Laravel gérer l'auto-incrément
        unset($matchAttributes['id']);
    }

    $beneficiary = Beneficiary::updateOrCreate(
        $matchAttributes,
        $data
    );

    return Helpers::success($beneficiary, $request->id ? 200 : 201);
}

    public function show(Beneficiary $beneficiary)
    {
        return Helpers::success(new BeneficiaryResource($beneficiary));
    }

public function update(Request $request, Beneficiary $beneficiary)
{
    // 1. Sécurité : Vérifier que le bénéficiaire appartient bien à l'utilisateur
    // On récupère l'ID utilisateur depuis le header comme dans votre méthode store
    $userId = $request->header('X-User-Id');
    
    if ($beneficiary->user_id != $userId) {
        return response()->json(['message' => 'Action non autorisée.'], 403);
    }

    // 2. Validation (Optionnel mais recommandé pour la cohérence des données)
    $request->validate([
        'account_type' => 'nullable|in:B,P',
        'identification_expired' => 'nullable|date',
        'date_birth' => 'nullable|date_format:Y-m-d',
        'business_register_date' => 'nullable|date_format:Y-m-d',
    ]);

    // 3. Mise à jour
    // On utilise $request->all() filtré par $request->only()
    $beneficiary->update($request->only([
        'name', 'phone', 'bank_account', 'mobile_wallet', 'country', 'account_type',
        'city', 'address', 'identification_number', 'identification_type',
        'identification_expired', 'status',
        'business_type', 'business_name', 'date_birth', 'business_register_date'
    ]));

    // 4. Retourner une réponse consistante
    return Helpers::success($beneficiary); 
}

    public function destroy(Beneficiary $beneficiary)
    {
        $beneficiary->delete();
        return response()->json(['message'=>'Deleted']);
    }
}
