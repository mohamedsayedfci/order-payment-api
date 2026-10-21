<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Auth\RequestGuard;
use Illuminate\Http\Request;

final class JwtRequestGuard extends RequestGuard
{
    public function setRequest(Request $request): static
    {
        // RequestGuard caches its user; clear it when a worker receives a new request.
        $this->forgetUser();
        parent::setRequest($request);

        return $this;
    }
}
