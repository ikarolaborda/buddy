<?php

namespace Tests\Unit;

use App\Services\Edge\EdgeTokenSigner;
use RuntimeException;
use Tests\TestCase;

class EdgeTokenSignerTest extends TestCase
{
    private EdgeTokenSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['buddy.edge.service_key' => 'edge-service-key']);
        $this->signer = new EdgeTokenSigner;
    }

    public function test_upload_tokens_round_trip_with_their_payload(): void
    {
        $token = $this->signer->mintUpload('clients/1/tasks/T/staging/UP1', 5, 'text/plain', 300, 'UP1');

        $this->assertMatchesRegularExpression('/^bup1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);

        $payload = $this->signer->verify($token, EdgeTokenSigner::KIND_UPLOAD);

        $this->assertNotNull($payload);
        $this->assertSame('clients/1/tasks/T/staging/UP1', $payload['key']);
        $this->assertSame(5, $payload['max_bytes']);
        $this->assertSame('text/plain', $payload['content_type']);
        $this->assertSame('UP1', $payload['upload_id']);
        $this->assertSame(now()->addSeconds(300)->getTimestamp(), $payload['exp']);
    }

    public function test_download_tokens_round_trip_with_their_payload(): void
    {
        $token = $this->signer->mintDownload('clients/1/tasks/T/artifacts/A1', 'artifact-7.txt', 'text/plain', 120);

        $this->assertStringStartsWith('bdl1.', $token);

        $payload = $this->signer->verify($token, EdgeTokenSigner::KIND_DOWNLOAD);

        $this->assertNotNull($payload);
        $this->assertSame('clients/1/tasks/T/artifacts/A1', $payload['key']);
        $this->assertSame('artifact-7.txt', $payload['filename']);
        $this->assertSame('text/plain', $payload['content_type']);
        $this->assertSame(now()->addSeconds(120)->getTimestamp(), $payload['exp']);
        $this->assertArrayNotHasKey('upload_id', $payload);
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $token = $this->signer->mintUpload('k', 1, 'text/plain', 60, 'u');
        $flipped = substr($token, -1) === 'A' ? 'B' : 'A';

        $this->assertNull($this->signer->verify(substr($token, 0, -1).$flipped, EdgeTokenSigner::KIND_UPLOAD));
    }

    public function test_a_tampered_payload_is_rejected(): void
    {
        $token = $this->signer->mintUpload('k', 1, 'text/plain', 60, 'u');
        [$kind, , $signature] = explode('.', $token);
        $forged = rtrim(strtr(base64_encode((string) json_encode([
            'key' => 'k',
            'max_bytes' => 999999,
            'content_type' => 'text/plain',
            'exp' => now()->addHour()->getTimestamp(),
            'upload_id' => 'u',
        ])), '+/', '-_'), '=');

        $this->assertNull($this->signer->verify($kind.'.'.$forged.'.'.$signature, EdgeTokenSigner::KIND_UPLOAD));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = $this->signer->mintUpload('k', 1, 'text/plain', 60, 'u');

        $this->travel(59)->seconds();
        $this->assertNotNull($this->signer->verify($token, EdgeTokenSigner::KIND_UPLOAD));

        $this->travel(2)->seconds();
        $this->assertNull($this->signer->verify($token, EdgeTokenSigner::KIND_UPLOAD));
    }

    public function test_the_kind_must_match(): void
    {
        $upload = $this->signer->mintUpload('k', 1, 'text/plain', 60, 'u');
        $download = $this->signer->mintDownload('k', 'f.txt', 'text/plain', 60);

        $this->assertNull($this->signer->verify($upload, EdgeTokenSigner::KIND_DOWNLOAD));
        $this->assertNull($this->signer->verify($download, EdgeTokenSigner::KIND_UPLOAD));
    }

    public function test_malformed_tokens_are_rejected(): void
    {
        $this->assertNull($this->signer->verify('', EdgeTokenSigner::KIND_UPLOAD));
        $this->assertNull($this->signer->verify('bup1.only-two', EdgeTokenSigner::KIND_UPLOAD));
        $this->assertNull($this->signer->verify('bup1.a.b.c', EdgeTokenSigner::KIND_UPLOAD));
    }

    public function test_a_token_signed_with_another_key_is_rejected(): void
    {
        $token = $this->signer->mintUpload('k', 1, 'text/plain', 60, 'u');

        config(['buddy.edge.service_key' => 'a-different-key']);

        $this->assertNull((new EdgeTokenSigner)->verify($token, EdgeTokenSigner::KIND_UPLOAD));
    }

    public function test_an_empty_service_key_refuses_to_mint(): void
    {
        config(['buddy.edge.service_key' => '']);

        $this->expectException(RuntimeException::class);

        (new EdgeTokenSigner)->mintUpload('k', 1, 'text/plain', 60, 'u');
    }

    public function test_an_empty_service_key_refuses_to_verify(): void
    {
        $token = $this->signer->mintDownload('k', 'f.txt', 'text/plain', 60);

        config(['buddy.edge.service_key' => null]);

        $this->expectException(RuntimeException::class);

        (new EdgeTokenSigner)->verify($token, EdgeTokenSigner::KIND_DOWNLOAD);
    }
}
