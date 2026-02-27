<?php
declare(strict_types=1);

namespace App\Core\Agents;

final class ConversationContext
{
    public function __construct(
        public readonly string $text,
        public readonly string $userId,
        public readonly array $state,
        public readonly string $traceId,
        public readonly string $channel
    ) {
    }

    public function withState(array $state): self
    {
        return new self(
            $this->text,
            $this->userId,
            $state,
            $this->traceId,
            $this->channel
        );
    }
}
