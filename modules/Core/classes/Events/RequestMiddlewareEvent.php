<?php

use Symfony\Component\HttpFoundation\Request;

/**
 * Base middleware event for request processing.
 * Allows middleware to intercept and modify requests during initialization.
 *
 * @package NamelessMC\Events
 * @author Aberdeener
 * @version 2.3.0
 * @license MIT
 */
class RequestMiddlewareEvent extends AbstractEvent
{
    public User $user;
    public Request $request;

    public function __construct(User $user, Request $request)
    {
        $this->user = $user;
        $this->request = $request;
    }

    public static function description(): string
    {
        return 'Request middleware processing event';
    }

    public static function internal(): bool
    {
        return true;
    }
}
