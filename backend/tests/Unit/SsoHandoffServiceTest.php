<?php
// apphub/backend/tests/Unit/SsoHandoffServiceTest.php
namespace Tests\Unit;

use App\Services\SsoHandoffService;
use Tests\TestCase;

class SsoHandoffServiceTest extends TestCase
{
    public function test_firma_produce_dos_partes_separadas_por_punto(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $handoff = (new SsoHandoffService())->firmar(['sub' => 'a@b.com']);

        $this->assertStringContainsString('.', $handoff);
        [$payload, $firma] = explode('.', $handoff, 2);
        $this->assertSame('a@b.com', json_decode(base64_decode($payload), true)['sub']);
        $this->assertSame(64, strlen($firma)); // hex de sha256
    }

    public function test_firmas_distintas_con_secretos_distintos(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-1']);
        $handoffA = (new SsoHandoffService())->firmar(['sub' => 'a@b.com']);

        config(['services.sso_handoff.secret' => 'secreto-2']);
        $handoffB = (new SsoHandoffService())->firmar(['sub' => 'a@b.com']);

        $this->assertNotSame($handoffA, $handoffB);
    }
}
