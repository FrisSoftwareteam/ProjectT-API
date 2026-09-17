<?php

namespace App\Exceptions;

use RuntimeException;

class CscsImportCancelledException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The CSCS import was cancelled.');
    }
}
