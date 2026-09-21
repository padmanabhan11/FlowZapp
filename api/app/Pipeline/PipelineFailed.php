<?php

declare(strict_types=1);

namespace App\Pipeline;

use RuntimeException;

/** A stage failure with a human-readable reason for S10 (FR-312). */
final class PipelineFailed extends RuntimeException {}
