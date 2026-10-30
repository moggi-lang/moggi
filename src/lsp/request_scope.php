<?php declare(strict_types=1);

namespace Moggi\LSP;

/**
 * The request the server loop is serving right now.
 *
 * A handler is dispatched by method name, so it cannot be handed the request it is answering as a
 * parameter; this object is what the loop installs and the handlers' cancellation checkpoints read.
 * Outside a request there is none, which is why every reader answers `null` rather than assuming one
 * (unit tests call the same handlers).
 */
final class RequestScope
{
    private static ?self $current = null;

    /** @param ?int $progressToken the client's `workDoneToken`, when this request carries one */
    public function __construct(
        public readonly int|string|null $id,
        public readonly CancellationRegistry $cancellations,
        public readonly ?int $progressToken,
    ) {
    }

    /** Install the request being served. */
    public static function begin(int|string|null $id, CancellationRegistry $cancellations, ?int $progressToken): void
    {
        self::$current = new self($id, $cancellations, $progressToken);
    }

    /** Forget it: every reader answers `null` again. */
    public static function end(): void
    {
        self::$current = null;
    }

    public static function current(): ?self
    {
        return self::$current;
    }
}
