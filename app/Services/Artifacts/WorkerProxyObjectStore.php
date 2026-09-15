<?php

namespace App\Services\Artifacts;

use App\Contracts\ArtifactObjectStore;
use App\Services\Edge\EdgeTokenSigner;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SensitiveParameter;

/*
 * Reaches R2 only through the buddy-edge Worker, authenticated with the
 * service key Azure already shares with it. Signed URLs are HMAC tokens the
 * Worker verifies itself, so no Cloudflare API token or R2 key ever lives
 * on Azure. Tokens grant access to bytes and must never reach a log line.
 */
final class WorkerProxyObjectStore implements ArtifactObjectStore
{
    public const CONNECT_TIMEOUT_SECONDS = 3;

    public function __construct(
        private string $workerUrl,
        #[SensitiveParameter] private string $serviceKey,
        private EdgeTokenSigner $signer,
        private int $timeoutSeconds = 30,
    ) {}

    public function presignUpload(string $key, int $expiresSeconds, string $contentType, int $maxBytes): array
    {
        // Staging keys end in the reservation id, which is the only upload
        // identity the contract carries.
        $token = $this->signer->mintUpload($key, $maxBytes, $contentType, $expiresSeconds, basename($key));

        return [
            'url' => $this->base().'/uploads/'.$token,
            'headers' => ['Content-Type' => $contentType],
        ];
    }

    public function head(string $key): ?array
    {
        $response = $this->request()->get($this->base().'/internal/objects/meta', ['key' => $key]);

        if ($response->status() === 404) {
            return null;
        }

        $this->ensureSuccessful($response, 'head');

        $type = $response->json('content_type');

        return [
            'size' => (int) $response->json('size'),
            'content_type' => is_string($type) && $type !== '' ? $type : null,
        ];
    }

    public function readStream(string $key)
    {
        $response = $this->request()
            ->withOptions(['stream' => true])
            ->get($this->base().'/internal/objects/content', ['key' => $key]);

        if ($response->status() === 404) {
            return null;
        }

        $this->ensureSuccessful($response, 'read');

        return $response->toPsrResponse()->getBody()->detach();
    }

    public function copy(string $from, string $to): bool
    {
        $response = $this->request()->post($this->base().'/internal/objects/copy', ['from' => $from, 'to' => $to]);

        if ($response->status() === 404) {
            return false;
        }

        $this->ensureSuccessful($response, 'copy');

        return true;
    }

    public function delete(string $key): bool
    {
        $response = $this->request()->delete($this->base().'/internal/objects?'.http_build_query(['key' => $key]));

        return $response->successful() || $response->status() === 404;
    }

    public function presignDownload(string $key, int $expiresSeconds, string $filename, string $contentType): string
    {
        return $this->base().'/downloads/'.$this->signer->mintDownload($key, $filename, $contentType, $expiresSeconds);
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders(['X-Buddy-Edge-Key' => $this->serviceKey])
            ->acceptJson()
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout($this->timeoutSeconds);
    }

    private function ensureSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException('Worker object '.$operation.' failed with HTTP '.$response->status().'.');
    }

    private function base(): string
    {
        return rtrim($this->workerUrl, '/');
    }
}
