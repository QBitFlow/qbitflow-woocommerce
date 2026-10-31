<?php

declare(strict_types=1);

namespace QBitFlowWC\Http;

use QBitFlowWC\Vendor\Psr\Http\Client\NetworkExceptionInterface;
use QBitFlowWC\Vendor\Psr\Http\Message\RequestInterface;
use RuntimeException;

defined('ABSPATH') || exit;

/** The WordPress HTTP API could not complete a request (DNS, connection, timeout, TLS). */
final class NetworkException extends RuntimeException implements NetworkExceptionInterface
{
	public function __construct(string $message, private readonly RequestInterface $request)
	{
		parent::__construct($message);
	}

	public function getRequest(): RequestInterface
	{
		return $this->request;
	}
}
