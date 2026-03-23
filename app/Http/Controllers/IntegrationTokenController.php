<?php

namespace App\Http\Controllers;

use App\Models\IntegrationToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IntegrationTokenController extends Controller
{
    public function index()
    {
        return IntegrationToken::query()
            ->with('creator:id,name,email')
            ->orderByDesc('id')
            ->get()
            ->map(fn (IntegrationToken $token) => $this->serializeToken($token));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $plainTextToken = 'agt_' . Str::random(48);
        $tokenHash = hash('sha256', $plainTextToken);

        $token = IntegrationToken::create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'token_prefix' => substr($plainTextToken, 0, 10),
            'token_hash' => $tokenHash,
            'token_value' => $plainTextToken,
            'estado' => IntegrationToken::STATUS_ACTIVE,
            'expires_at' => $data['expires_at'] ?? null,
            'created_by' => auth('api')->id() ?? auth()->id(),
        ]);

        return response()->json([
            'message' => 'Token de integración generado correctamente.',
            'token' => $this->serializeToken($token->load('creator:id,name,email')),
            'plain_text_token' => $plainTextToken,
        ], 201);
    }

    public function update(Request $request, IntegrationToken $integrationToken)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $integrationToken->update($data);

        return response()->json([
            'message' => 'Token de integración actualizado correctamente.',
            'token' => $this->serializeToken($integrationToken->fresh()->load('creator:id,name,email')),
        ]);
    }

    public function activate(IntegrationToken $integrationToken)
    {
        $integrationToken->update([
            'estado' => IntegrationToken::STATUS_ACTIVE,
        ]);

        return response()->json([
            'message' => 'Token activado correctamente.',
            'token' => $this->serializeToken($integrationToken->fresh()->load('creator:id,name,email')),
        ]);
    }

    public function deactivate(IntegrationToken $integrationToken)
    {
        $integrationToken->update([
            'estado' => IntegrationToken::STATUS_INACTIVE,
        ]);

        return response()->json([
            'message' => 'Token desactivado correctamente.',
            'token' => $this->serializeToken($integrationToken->fresh()->load('creator:id,name,email')),
        ]);
    }

    public function destroy(IntegrationToken $integrationToken)
    {
        $integrationToken->delete();

        return response()->json([
            'message' => 'Token eliminado correctamente.',
        ]);
    }

    public function reveal(IntegrationToken $integrationToken)
    {
        if (blank($integrationToken->token_value)) {
            return response()->json([
                'message' => 'Este token fue creado antes de habilitar la visualización. Debes regenerarlo.',
            ], 422);
        }

        return response()->json([
            'token' => $this->serializeToken($integrationToken->load('creator:id,name,email')),
            'plain_text_token' => $integrationToken->token_value,
        ]);
    }

    private function serializeToken(IntegrationToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'description' => $token->description,
            'token_prefix' => $token->token_prefix,
            'estado' => (int) $token->estado,
            'is_active' => $token->isActive(),
            'last_used_at' => optional($token->last_used_at)?->toDateTimeString(),
            'expires_at' => optional($token->expires_at)?->toDateTimeString(),
            'created_at' => optional($token->created_at)?->toDateTimeString(),
            'updated_at' => optional($token->updated_at)?->toDateTimeString(),
            'creator' => $token->creator ? [
                'id' => $token->creator->id,
                'name' => $token->creator->name,
                'email' => $token->creator->email,
            ] : null,
        ];
    }
}
