<?php

declare(strict_types=1);

namespace QBitFlowWC\Checkout;

use RuntimeException;

defined('ABSPATH') || exit;

/** A checkout QBitFlow can't open; the message is safe to show the customer. */
final class CheckoutException extends RuntimeException
{
}
