<?php

namespace App\Services;

use App\Mail\SmtpBootstrapDiagnosticTransport;

class SmtpBootstrapDiagnosticTransportFactory
{
    /** @param array<string, mixed> $configuration */
    public function make(#[\SensitiveParameter] array $configuration): SmtpBootstrapDiagnosticTransport
    {
        return new SmtpBootstrapDiagnosticTransport($configuration);
    }
}
