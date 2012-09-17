<?php

if (!file_exists(__DIR__.'/../vendor/autoload.php')) {
    echo "<p>The file <tt>vendor/autoload.php</tt> doesn't exist. Make sure you've installed the Silex/Pilex components with Composer. See the README.md file.</p>";
    die();
}

$pilex_version = "0.5";
$pilex_name = "Almost alpha";

error_reporting(E_ALL ^ E_NOTICE);

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/classes/lib.php';
require_once __DIR__.'/classes/storage.php';
require_once __DIR__.'/classes/content.php';
require_once __DIR__.'/classes/users.php';
require_once __DIR__.'/classes/util.php';

$config = getConfig();
$dboptions = getDBOptions($config);



// Start the timer:
$starttime=getMicrotime();

$app = new Silex\Application();

$app['debug'] = (!empty($config['general']['debug'])) ? $config['general']['debug'] : false;

$app['config'] = $config;

$app->register(new Silex\Provider\SessionServiceProvider());

$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__.'/cache/debug.log',
    'monolog.name' => "Pilex"
));


$app->register(new Silex\Provider\TwigServiceProvider(), array(
    'twig.path' => $config['twigpath'],
    'twig.options' => array(
        'debug'=>true, 
        'cache' => __DIR__.'/cache/', 
        'strict_variables' => $config['general']['strict_variables'],
        'autoescape' => false )
));


$app->register(new Silex\Provider\DoctrineServiceProvider(), array(
    'db.options' => $dboptions
));



$app->register(new Silex\Provider\HttpCacheServiceProvider(), array(
    'http_cache.cache_dir' => __DIR__.'/cache/',
));

$app->register(new Silex\Provider\UrlGeneratorServiceProvider());


use Silex\Provider\FormServiceProvider;
$app->register(new FormServiceProvider());

$app->register(new Silex\Provider\ValidatorServiceProvider());
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
    'translator.messages' => array(),
));

// Loading stub functions for when intl / IntlDateFormatter isn't available.
if (!function_exists('intl_get_error_code')) {
    require_once __DIR__.'/../vendor/symfony/Locale/Symfony/Component/Locale/Resources/stubs/functions.php';
    require_once __DIR__.'/../vendor/symfony/Locale/Symfony/Component/Locale/Resources/stubs/IntlDateFormatter.php';
}




$app['storage'] = new Storage($app);

$app['users'] = new Users($app);
$app['paths'] = getPaths($config);

$app['editlink'] = "";

// Add the Pilex Twig functions, filters and tags.
require_once __DIR__.'/classes/twig_pilex.php';
$app['twig']->addExtension(new Pilex_Twig_Extension());

require_once __DIR__.'/classes/twig_setcontent.php';
$app['twig']->addTokenParser(new Pilex_Setcontent_TokenParser());






require_once __DIR__.'/app_backend.php';
require_once __DIR__.'/app_frontend.php';
