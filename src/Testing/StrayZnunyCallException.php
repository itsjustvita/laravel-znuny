<?php

namespace ItsJustVita\LaravelZnuny\Testing;

use RuntimeException;

/**
 * Znuny::fake() was asked for an operation it has no response for. Not a ZnunyException,
 * so application code that handles Znuny errors does not swallow a mistake in the test.
 */
class StrayZnunyCallException extends RuntimeException {}
