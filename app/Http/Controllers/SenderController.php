<?php
namespace App\Http\Controllers;

use App\Http\Helpers\Helpers;
use App\Http\Resources\SenderResource;
use App\Models\Sender;
use Illuminate\Http\Request;

class SenderController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->header('X-User-Id');
        $senders = Sender::where('user_id', $userId)->get();
        return Helpers::success(SenderResource::collection($senders));
    }

public function store(Request $request)
{
    $userId = $request->header('X-User-Id');

    $validated = $request->validate([
        // Si l'ID est fourni, il doit exister dans la table senders
        'id' => 'nullable|numeric|exists:senders,id',
        'name' => 'required|string|max:255',
        'account_type' => 'required|in:B,P',
        'phone' => 'nullable|string',
        'email' => 'nullable|email',
        'country' => 'nullable|string|max:3',
        'address' => 'nullable|string',
        'city' => 'nullable|string',
        
        // Champs Spécifiques Entreprise
        'business_name' => 'nullable|string|max:255',
        'business_type' => 'nullable|string',
        'business_register_date' => 'nullable|date_format:Y-m-d',
        
        // Champs Spécifiques Personnel
        'gender' => 'nullable|in:M,F',
        'date_birth' => 'nullable|date_format:Y-m-d',
        
        // Identification
        'identification_number' => 'nullable|string',
        'identification_type' => 'nullable|string',
        'identification_expired' => 'nullable|date_format:Y-m-d',
    ]);

    // 1. Préparation des critères de recherche (pour l'update)
    // On cherche par ID ET par user_id pour éviter qu'un utilisateur modifie le sender d'un autre
    $searchAttributes = [
        'id' => $request->id,
        'user_id' => $userId
    ];

    // Si pas d'ID, on vide les attributs de recherche pour forcer un 'create'
    if (!$request->id) {
        $searchAttributes = ['id' => null]; 
        // Note: Laravel ignorera l'id null et créera une nouvelle entrée
    }

    // 2. Préparation des données à insérer/modifier
    $data = $request->only([
        'name', 'phone', 'email', 'country', 'address', 'city',
        'account_type', 'business_type', 'business_name', 'business_register_date',
        'gender', 'date_birth', 'identification_number', 
        'identification_type', 'identification_expired'
    ]);
    
    // On s'assure que le user_id est bien présent dans les données de mise à jour
    $data['user_id'] = $userId;

    // 3. Exécution (Update ou Create)
    $sender = Sender::updateOrCreate(
        $searchAttributes,
        $data
    );

    // Déterminer le code de succès (201 pour création, 200 pour modification)
    $status = $request->id ? 200 : 201;

    return Helpers::success($sender, $status);
}

    public function show(Sender $sender)
    {
        return response()->json($sender);
    }

 public function update(Request $request, Sender $sender)
{
    // 1. Sécurité : Vérifier que l'expéditeur appartient bien à l'utilisateur
    $userId = $request->header('X-User-Id');
    
    if ($sender->user_id != $userId) {
        return response()->json(['message' => 'Action non autorisée.'], 403);
    }

    // 2. Validation (Indispensable pour les formats de date)
    $request->validate([
        'name' => 'sometimes|required|string|max:255',
        'account_type' => 'nullable|in:B,P',
        'email' => 'nullable|email',
        'date_birth' => 'nullable|date_format:Y-m-d',
        'business_register_date' => 'nullable|date_format:Y-m-d',
        'identification_expired' => 'nullable|date_format:Y-m-d',
        'gender' => 'nullable|in:M,F',
        'country' => 'nullable|string|max:3',
        'status' => 'nullable|string'
    ]);

    // 3. Mise à jour
    // On filtre les champs pour ne mettre à jour que ce qui est autorisé
    $sender->update($request->only([
        'name', 'phone', 'email', 'country', 'address', 'city', 'account_type',
        'business_type', 'business_name', 'date_birth', 'business_register_date',
        'identification_number', 'identification_type', 'identification_expired',
        'gender', 'status'
    ]));

    // 4. Retourner la réponse via votre Helper
    return Helpers::success($sender);
}

    public function destroy(Sender $sender)
    {
        $sender->delete();
        return response()->json(['message'=>'Deleted']);
    }
}
