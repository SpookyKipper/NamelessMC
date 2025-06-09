<?php

class EnsureUserIntegrationsLinkedMiddleware extends AbstractMiddleware
{
    public MiddlewareType $type = MiddlewareType::Frontend;

    public function handle(User $user, Language $language): void
    {
        // Check if any integrations is required before user can continue
        if ($user->isLoggedIn() && defined('PAGE') && PAGE != 'cc_connections' && PAGE != 'oauth' && !(PAGE == 'cc_settings' && $_GET['do'] == 'enable_tfa')) {
            foreach (Integrations::getInstance()->getEnabledIntegrations() as $integration) {
                if ($integration->data()->required && $integration->allowLinking()) {
                    $integrationUser = $user->getIntegration($integration->getName());
                    if ($integrationUser === null || !$integrationUser->isVerified()) {
                        Session::flash('connections_error', $language->get('user', 'integration_required_to_continue'));
                        Redirect::to(URL::build('/user/connections'));
                    }
                }
            }
        }
    }
}
