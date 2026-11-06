<?php

namespace ItsJustVita\LaravelZnuny\Exceptions;

/**
 * A search without limit() matches more tickets than the connection's search_limit.
 */
class ZnunySearchLimitExceededException extends ZnunyException {}
