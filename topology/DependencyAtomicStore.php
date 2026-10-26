<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types = 1);

namespace Modules\NetworkTopology\Topology;

/**
 * Storage-independent contract for an atomic dependency document replacement.
 * This interface deliberately does NOT define an implementation using
 * module.config: that storage cannot guarantee compare-and-swap.
 *
 * replaceIfVersion MUST atomically test the supplied version and replace
 * the complete document, across workers and frontend nodes.
 */
interface DependencyAtomicStore {
    /** @return array{version: string, dependencies: array} */
    public function snapshot(): array;

    /**
     * Returns false on version conflict; throws on storage failures.
     * Callers must calculate the scope merge from the latest snapshot and
     * validate it before invoking this method.
     */
    public function replaceIfVersion(string $expectedVersion, array $dependencies): bool;
}
