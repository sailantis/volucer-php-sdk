<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Call interface shared by worker channels and the `[rpc]` listener.
 *
 * Used by [`Kv`], [`Lock`], and [`Jobs`] to share the same storage APIs across both transports.
 */
interface CallPlane
{
    /**
     * Call a server method.
     *
     * @param string $name `"<namespace>.<verb>"`, e.g. `kv.get`.
     * @param array<string, mixed> $params Method-specific arguments.
     * @return mixed Result, or null on refusal; see {@see CallPlane::lastCallError()}.
     * @throws ProtocolException On transport failure or, for workers, calls outside a request.
     */
    public function call(string $name, array $params = []): mixed;

    /**
     * Error for the last call, or null if it succeeded.
     */
    public function lastCallError(): ?string;

    /**
     * Whether the last failed call may have taken effect.
     */
    public function lastCallWasUnknown(): bool;
}
