<?php

namespace App\Support;

/**
 * What a destination can attach to one message: which kinds of media, and how
 * many. A destination that sends text only declares none.
 */
readonly class DestinationMediaSupport
{
    /**
     * @param  list<string>  $types  MediaList types the destination attaches, e.g. image, video.
     * @param  int  $maxCount  The most entries one message carries.
     */
    private function __construct(
        public array $types,
        public int $maxCount,
    ) {}

    public static function none(): self
    {
        return new self([], 0);
    }

    /**
     * @param  list<string>  $types
     */
    public static function of(array $types, int $maxCount): self
    {
        $types = array_values(array_intersect([MediaList::TYPE_IMAGE, MediaList::TYPE_VIDEO], $types));

        if ($types === [] || $maxCount < 1) {
            return self::none();
        }

        return new self($types, $maxCount);
    }

    public function acceptsAny(): bool
    {
        return $this->types !== [] && $this->maxCount > 0;
    }

    /**
     * Keep the first entries this destination accepts, in the order they came,
     * and count the rest as left off.
     */
    public function fit(mixed $media): MediaFit
    {
        $mapped = is_array($media) ? count($media) : 0;
        $attached = [];

        foreach (MediaList::normalize($media) as $entry) {
            if (count($attached) >= $this->maxCount) {
                break;
            }

            if (in_array($entry['type'], $this->types, true)) {
                $attached[] = $entry;
            }
        }

        return new MediaFit($attached, max(0, $mapped - count($attached)), $this);
    }

    /**
     * What this destination takes, in words: "up to 10 images or videos".
     */
    public function describe(): string
    {
        if (! $this->acceptsAny()) {
            return 'text only';
        }

        $plural = $this->maxCount !== 1;
        $kinds = array_map(
            static fn (string $type): string => $plural ? $type.'s' : $type,
            $this->types,
        );

        return 'up to '.$this->maxCount.' '.implode(' or ', $kinds);
    }

    /**
     * @return array{types: list<string>, max_count: int, label: string}
     */
    public function toArray(): array
    {
        return [
            'types' => $this->types,
            'max_count' => $this->maxCount,
            'label' => $this->describe(),
        ];
    }
}
