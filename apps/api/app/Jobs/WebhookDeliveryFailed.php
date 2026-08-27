<?php

declare(strict_types=1);

namespace App\Jobs;

use RuntimeException;

/** A delivery the endpoint refused. Thrown so the queue retries it. */
final class WebhookDeliveryFailed extends RuntimeException {}
