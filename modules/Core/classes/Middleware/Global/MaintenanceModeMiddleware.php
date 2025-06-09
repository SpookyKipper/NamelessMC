<?php

use Symfony\Component\HttpFoundation\Request;

/**
 * Maintenance Mode middleware hook.
 * Redirects non-admin users when maintenance mode is enabled.
 *
 * @package NamelessMC\Hooks
 * @author AI Assistant
 * @version 2.3.0
 * @license MIT
 */
class MaintenanceModeMiddleware extends AbstractMiddleware
{
    private const EXEMPTED_ROUTES = [
        '/maintenance',
        '/login',
        '/forgot_password',
        '/api',
        '/queries',
        '/oauth',
        '/store/listener',
    ];

    public function type(): MiddlewareType
    {
        return MiddlewareType::Global;
    }

    public function execute(User $user, Request $request): void
    {
        // Check if maintenance mode is enabled
        if (!Settings::get('maintenance')) {
            return;
        }

        // Allow admin users to bypass maintenance mode
        if ($user->isLoggedIn() && $user->canViewStaffCP()) {
            // Display notice to admin stating maintenance mode is enabled
            define('BYPASS_MAINTENANCE', true);
            return;
        }

        $route = $request->get('route');
        foreach (self::EXEMPTED_ROUTES as $exempted_route) {
            if (str_starts_with($route, $exempted_route)) {
                return;
            }
        }

        Redirect::to(URL::build('/maintenance'));
    }
}
