<?php

namespace App\Update;

/**
 * Version of the running build, as given by `git describe --tags` at build time:
 * "v0.7.0" (a release), "v0.7.0-3-gabc1234" (3 commits after it), or anything else ("dev", a branch name).
 */
final readonly class AppVersion
{
    private function __construct(
        public string $raw,
        public ?string $release,
        public int $ahead = 0,
        public ?string $commit = null,
    ) {
    }

    public static function parse(string $raw): self
    {
        $raw = trim($raw);
        if (preg_match('/^v?(?<release>\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)-(?<ahead>\d+)-g(?<commit>[0-9a-f]{4,40})$/', $raw, $m)) {
            return new self($raw, $m['release'], (int) $m['ahead'], $m['commit']);
        }
        if (preg_match('/^v?(?<release>\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/', $raw, $m)) {
            return new self($raw, $m['release']);
        }

        return new self('' === $raw ? 'dev' : $raw, null);
    }

    /** Release number of a tag ("v1.2.0" → "1.2.0"), null when it is not a version. */
    public static function releaseOf(string $tag): ?string
    {
        return preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)$/', trim($tag), $m) ? $m[1] : null;
    }

    public function label(): string
    {
        if (null === $this->release) {
            return $this->raw;
        }

        return $this->ahead > 0 ? \sprintf('%s+%d (%s)', $this->release, $this->ahead, $this->commit) : $this->release;
    }

    /** Whether $release is newer than this build; null when this build has no version to compare (dev, branch). */
    public function isOlderThan(string $release): ?bool
    {
        return null === $this->release ? null : version_compare($this->release, $release, '<');
    }
}
