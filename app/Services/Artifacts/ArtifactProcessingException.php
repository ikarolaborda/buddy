<?php

namespace App\Services\Artifacts;

use RuntimeException;

/*
 * A bounded, visible parser outcome. The reason is a short stable code that
 * lands in processing_status = failed; it is never a raw parser message.
 */
final class ArtifactProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct($reason);
    }
}
