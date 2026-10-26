<?php

declare(strict_types=1);

namespace Volucer;

/**
 * Whatever answers a call: a worker's channel, or the `[rpc]` listener.
 *
 * The three methods below are the whole of what [`Kv`], [`Lock`] and [`Jobs`] need. They were written
 * against [`Worker`] directly, which was correct while a worker's channel was the only way to reach a
 * namespace — and stopped being correct once volucer could serve the same namespaces over `[rpc]` to
 * an application that has no channel at all. Extracting the seam means the storage clients did not
 * have to be duplicated or parameterised: `$worker->kv('cache')` and `$rpc->kv('cache')` construct the
 * same [`Kv`], over the same stores, with the same semantics.
 *
 * **The interface is deliberately three methods**, not the whole of [`Worker`]. What a client needs is
 * a way to ask, a way to read why an ask failed, and a way to tell a definite refusal from an
 * undecided one. Anything wider would let a storage client start depending on a worker's request
 * lifecycle, which an `[rpc]` client does not have.
 */
interface CallPlane
{
    /**
     * Ask the server to do something.
     *
     * @param string $name `"<namespace>.<verb>"`, e.g. `kv.get`.
     * @param array<string, mixed> $params method-specific arguments.
     *
     * @return mixed the call's result, or null when the server refused it; see {@see CallPlane::lastCallError()}.
     *
     * @throws ProtocolException if the transport failed, or — on a worker — a call was made outside a request.
     */
    public function call(string $name, array $params = []): mixed;

    /**
     * Why the last {@see CallPlane::call()} did not return a result, or null if it did.
     */
    public function lastCallError(): ?string;

    /**
     * Whether the call that failed may have taken effect.
     */
    public function lastCallWasUnknown(): bool;
}
