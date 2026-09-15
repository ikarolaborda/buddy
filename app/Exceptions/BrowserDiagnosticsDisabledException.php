<?php

namespace App\Exceptions;

class BrowserDiagnosticsDisabledException extends \RuntimeException
{
    public const ERROR_CODE = 'browser_diagnostics_disabled';
}
