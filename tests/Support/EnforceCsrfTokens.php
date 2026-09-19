<?php

namespace Tests\Support;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/** Exercise real CSRF checks that Laravel normally bypasses in feature tests. */
class EnforceCsrfTokens extends ValidateCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
