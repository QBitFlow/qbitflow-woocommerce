<?php

declare(strict_types=1);

namespace QBitFlowWC\Orders;

use RuntimeException;

defined('ABSPATH') || exit;

/** Another request is updating the order right now; try again later (a webhook answers 500). */
final class OrderBusyException extends RuntimeException
{
}
