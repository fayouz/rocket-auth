<?php

namespace App\Update;

final readonly class LatestRelease
{
    public function __construct(
        public string $version,
        /** Git tag of the version ("v0.7.0"). */
        public string $tag,
        public string $name,
        public string $url,
        public ?\DateTimeImmutable $publishedAt = null,
        public ?string $notes = null,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'tag' => $this->tag,
            'name' => $this->name,
            'url' => $this->url,
            'publishedAt' => $this->publishedAt?->format(\DATE_ATOM),
            'notes' => $this->notes,
        ];
    }
}
