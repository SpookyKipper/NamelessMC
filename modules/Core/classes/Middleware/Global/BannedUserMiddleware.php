<?php

use Symfony\Component\HttpFoundation\Request;

/**
 * Banned User middleware hook.
 * Handles user bans and IP bans enforcement.
 *
 * @package NamelessMC\Hooks
 * @author AI Assistant
 * @version 2.3.0
 * @license MIT
 */
class BannedUserMiddleware extends AbstractMiddleware
{
    public function type(): MiddlewareType
    {
        return MiddlewareType::Global;
    }

    public function execute(User $user, Language $language): void
    {
        if (!$user->isLoggedIn() || !$user->data()->isbanned) {
            return;
        }

        if (!DB::getInstance()->get('ip_bans', ['ip', HttpUtils::getRemoteAddress()])->exists()) {
            return;
        }

        $user->logout();

        Session::flash('home_error', $language->get('user', 'you_have_been_banned'));
        Redirect::to(URL::build('/'));
    }
}
