<?php
// apphub/backend/app/Services/SsoHandoffService.php
namespace App\Services;

/**
 * Firma un payload para el handoff de SSO hacia otra app del ecosistema (ver "Nota de
 * arquitectura" en docs/superpowers/plans/2026-08-09-fase3-gateway-sso.md -- reemplaza la
 * sesión compartida del spec original porque apphub usa Sanctum en modo token, no sesión).
 * El mismo secreto (`SSO_HANDOFF_SECRET`) debe estar configurado en AMBAS apps.
 */
class SsoHandoffService
{
    public function firmar(array $payload): string
    {
        $codificado = base64_encode(json_encode($payload));
        $firma = hash_hmac('sha256', $codificado, (string) config('services.sso_handoff.secret'));

        return "{$codificado}.{$firma}";
    }
}
