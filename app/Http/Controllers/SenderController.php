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
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'country' => 'nullable|string',
            'address' => 'nullable|string',
            'identification_number' => 'nullable|string',
            'identification_type' => 'nullable|string',
            'identification_expired' => 'nullable|date',
        ]);

        $sender = Sender::create([
            'user_id' => $userId,
            ...$request->only([
                'name','phone','email','country','address',
                'identification_number','identification_type','identification_expired'
            ])
        ]);

        return response()->json($sender, 201);
    }

    public function show(Sender $sender)
    {
        return response()->json($sender);
    }

    public function update(Request $request, Sender $sender)
    {
        $sender->update($request->only([
            'name','phone','email','country','address',
            'identification_number','identification_type','identification_expired','status'
        ]));

        return response()->json($sender);
    }

    public function destroy(Sender $sender)
    {
        $sender->delete();
        return response()->json(['message'=>'Deleted']);
    }
}
