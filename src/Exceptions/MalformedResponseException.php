<?php

declare(strict_types=1);

namespace Aether\Exceptions;

/**
 * Thrown when a Python script answers with a response the driver cannot use:
 * a missing key, a wrong type, an unknown task status, an identifier in the
 * wrong shape.
 *
 * A subclass of QuantumExecutionException so existing catch blocks keep
 * working, but distinct so callers such as PollQuantumTask can tell this
 * deterministic failure apart from a transient one (a crashed subprocess,
 * a timeout) that is worth retrying. Create it via
 * QuantumExecutionException::malformedResponse().
 */
class MalformedResponseException extends QuantumExecutionException {}
