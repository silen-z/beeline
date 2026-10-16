<?php

declare(strict_types=1);

namespace SilenZ\Beeline\Internal;

use LogicException;

use function sprintf;

/**
 * @internal
 */
enum SegmentType
{
    /** Literal text, consumes exactly one segment. */
    case Static;

    /** `{name}`, consumes exactly one non-empty segment. */
    case Param;

    /** `{name*}`, consumes the remaining zero or more segments. */
    case CatchAllZero;

    /** `{name+}`, consumes the remaining one or more segments (non-empty remainder). */
    case CatchAllOne;

    /** `{name?}`, consumes the one trailing segment if present, or nothing. */
    case Optional;

    /**
     * Whether this kind is terminal: stored on the node it hangs off instead of getting a child
     * node of its own, and so must be the last segment of a route.
     */
    public function isCatchAll(): bool
    {
        return $this === self::CatchAllZero || $this === self::CatchAllOne || $this === self::Optional;
    }

    /**
     * The tag stored as a compiled `catch` entry's leading element, see {@see \SilenZ\Beeline\Compiler}.
     * Only meaningful for a kind {@see isCatchAll()} is true for.
     */
    public function catchTag(): int
    {
        return match ($this) {
            self::CatchAllZero => 0,
            self::CatchAllOne => 1,
            self::Optional => 2,
            self::Static, self::Param => throw new LogicException(sprintf('%s is not a catch-all kind.', $this->name)),
        };
    }
}
