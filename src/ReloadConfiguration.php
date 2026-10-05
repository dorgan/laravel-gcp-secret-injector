<?php

namespace Agz\LaravelGcpSecretInjector;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;

class ReloadConfiguration extends LoadConfiguration
{
    public function reload(Application $app)
    {
        $config = $app->make('config');
        $providers = $config->get('app.providers');

        $this->loadConfigurationFiles($app, $config);

        // Laravel merges bootstrap/providers.php before providers register.
        // Keep that list when reloading files so config:cache retains app bindings.
        if ($providers !== null) {
            $config->set('app.providers', $providers);
        }
    }
}
