<?php

namespace Tests;

use Agz\LaravelGcpSecretInjector\ReloadConfiguration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use PHPUnit\Framework\TestCase;

class ReloadConfigurationTest extends TestCase
{
    public function test_uncached_reload_preserves_providers_for_the_next_cached_boot()
    {
        $this->assertProvidersSurviveReload(false);
    }

    public function test_cached_reload_preserves_providers_for_the_next_cached_boot()
    {
        $this->assertProvidersSurviveReload(true);
    }

    private function assertProvidersSurviveReload(bool $cached)
    {
        $basePath = sys_get_temp_dir().'/gcp-provider-reload-'.bin2hex(random_bytes(8));
        mkdir($basePath.'/bootstrap/cache', 0777, true);
        mkdir($basePath.'/config', 0777, true);

        $env = Env::getRepository();
        $previousSecret = $env->get('GCP_INJECTOR_TEST_SECRET');
        $providers = ServiceProvider::defaultProviders()->merge([
            ReloadTestServiceProvider::class,
        ])->toArray();

        try {
            file_put_contents($basePath.'/config/app.php', '<?php return ["env" => "testing", "timezone" => "UTC"];');
            file_put_contents($basePath.'/config/services.php', '<?php return ["test_secret" => env("GCP_INJECTOR_TEST_SECRET")];');

            if ($cached) {
                $this->writeCache($basePath, [
                    'app' => ['env' => 'testing', 'timezone' => 'UTC', 'providers' => $providers],
                    'services' => ['test_secret' => 'old-secret'],
                ]);
            }

            $app = new Application($basePath);
            (new LoadConfiguration())->bootstrap($app);

            if (!$cached) {
                // Laravel merges bootstrap/providers.php before the injector runs.
                $app['config']->set('app.providers', $providers);
            }

            $env->set('GCP_INJECTOR_TEST_SECRET', 'injected-secret');
            (new ReloadConfiguration())->reload($app);

            $this->assertSame('injected-secret', $app['config']->get('services.test_secret'));

            $this->writeCache($basePath, $app['config']->all());
            $freshApp = new Application($basePath);
            (new LoadConfiguration())->bootstrap($freshApp);
            $freshApp->registerConfiguredProviders();

            // The subsequent cached boot must still run the application's factory.
            $this->assertSame('provider-factory', $freshApp->make(ReloadTestClient::class)->value);
            $this->assertSame($providers, $freshApp['config']->get('app.providers'));
        } finally {
            if ($previousSecret === null) {
                $env->clear('GCP_INJECTOR_TEST_SECRET');
            } else {
                $env->set('GCP_INJECTOR_TEST_SECRET', $previousSecret);
            }

            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($basePath);
        }
    }

    private function writeCache(string $basePath, array $config)
    {
        file_put_contents($basePath.'/bootstrap/cache/config.php', '<?php return '.var_export($config, true).';');
    }
}

class ReloadTestServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(ReloadTestClient::class, function () {
            return new ReloadTestClient('provider-factory');
        });
    }
}

class ReloadTestClient
{
    public $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
