<?php

namespace ORM\Exception;

use RuntimeException;

class NotFoundException extends RuntimeException
{
    /** @var string */
    protected $message = 'No data found.';
}