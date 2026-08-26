<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Laravel 12 no longer includes this by default. NutriLens controllers use
     * $this->authorize(...) to run policies on user-owned records, so it is
     * pulled in here rather than repeated in every controller.
     */
    use AuthorizesRequests;
}
