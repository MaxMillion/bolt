<?php

namespace Bolt;

use Bolt\Configuration\LowlevelException;
use Bolt\Library as Lib;
use Bolt\Helpers\Arr;
use Bolt\Helpers\String;
use Bolt\Translation\Translator as Trans;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml;
use Symfony\Component\Yaml\Parser;

/**
 * Class for our config object.
 *
 * @author Bob den Otter, bob@twokings.nl
 */
class Config
{
    protected $app;
    protected $data;
    protected $defaultConfig = array();
    protected $reservedFieldNames = array(
        'id', 'slug', 'datecreated', 'datechanged', 'datepublish', 'datedepublish', 'ownerid', 'username', 'status', 'link'
    );

    protected $cachetimestamp;

    /**
     * Use {@see Config::getFields} instead.
     * Will be made protected in Bolt 3.0.
     * @var Field\Manager
     */
    public $fields;

    protected $yamlParser = false;

    /**
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->fields = new Field\Manager();
        $this->defaultConfig = $this->getDefaults();

        $this->initialize();
    }

    protected function initialize()
    {
        if (!$this->loadCache()) {
            $this->data = $this->getConfig();
            $this->saveCache();

            // if we have to reload the config, we will also want to make sure the DB integrity is checked.
            Database\IntegrityChecker::invalidate($this->app);
        } else {

            // In this case the cache is loaded, but because the path of the theme
            // folder is defined in the config file itself, we still need to check
            // retrospectively if we need to invalidate it.
            $this->checkValidCache();

        }

        $this->setCKPath();
    }

    /**
     * @param  string $filename The name of the YAML file to read
     * @param  string $path     The (optional) path to the YAML file
     * @return array
     */
    protected function parseConfigYaml($filename, $path = null)
    {
        // Initialise parser
        if ($this->yamlParser === false) {
            $this->yamlParser = new Parser();
        }

        // By default we assume that config files are located in app/config/
        $path = $path ?: $this->app['resources']->getPath('config');
        $filename = $path . '/' . $filename;

        if (!is_readable($filename)) {
            return array();
        }

        $yml = $this->yamlParser->parse(file_get_contents($filename) . "\n");

        // Invalid, non-existing, or empty files return NULL
        return $yml ?: array();
    }

    /**
     * Set a config value, using a path. For example:
     *
     * $app['config']->set('general/branding/name', 'Bolt');
     *
     * @param  string $path
     * @param  mixed  $value
     * @return bool
     */
    public function set($path, $value)
    {
        $path = explode('/', $path);

        // Only do something if we get at least one key.
        if (empty($path[0])) {
            $logline = "Config: can't set empty path to '" . (string) $value . "'";
            $this->app['logger.system']->addCritical($logline, array('event' => 'config'));

            return false;
        }

        $part = & $this->data;

        foreach ($path as $key) {
            if (!isset($part[$key])) {
                $part[$key] = array();
            }

            $part = & $part[$key];
        }

        $part = $value;

        return true;
    }

    /**
     * Get a config value, using a path. For example:
     *
     * $var = $config->get('general/wysiwyg/ck/contentsCss');
     *
     * @param  string $path
     * @param  string $default
     * @return mixed
     */
    public function get($path, $default = null)
    {
        $path = explode('/', $path);

        // Only do something if we get at least one key.
        if (empty($path[0]) || !isset($this->data[$path[0]])) {
            return false;
        }

        $part = & $this->data;
        $value = null;

        foreach ($path as $key) {
            if (!isset($part[$key])) {
                $value = null;
                break;
            }

            $value = $part[$key];
            $part = & $part[$key];
        }

        if ($value !== null) {
            return $value;
        }

        return $default;
    }

    /**
     * Load the configuration from the various YML files.
     */
    public function getConfig()
    {
        $config = array();

        $config['general']     = $this->parseGeneral();
        $config['taxonomy']    = $this->parseTaxonomy();
        $config['contenttypes'] = $this->parseContentTypes($config['general']['accept_file_types']);
        $config['menu']        = $this->parseConfigYaml('menu.yml');
        $config['routing']     = $this->parseConfigYaml('routing.yml');
        $config['permissions'] = $this->parseConfigYaml('permissions.yml');
        $config['extensions']  = array();

        // fetch the theme config. requires special treatment due to the path being dynamic
        $this->app['resources']->initializeConfig($config);
        $config['theme'] = $this->parseConfigYaml('config.yml', $this->app['resources']->getPath('theme'));

        // @todo: If no config files can be found, get them from bolt.cm/files/default/

        return $config;
    }

    protected function parseGeneral()
    {
        // Read the config and merge it. (note: We use temp variables to prevent
        // "Only variables should be passed by reference")
        $tempconfig = $this->parseConfigYaml('config.yml');
        $tempconfiglocal = $this->parseConfigYaml('config_local.yml');
        $general = Arr::mergeRecursiveDistinct($tempconfig, $tempconfiglocal);

        // Make sure old settings for 'contentsCss' are still picked up correctly
        if (isset($general['wysiwyg']['ck']['contentsCss'])) {
            $general['wysiwyg']['ck']['contentsCss'] = array(
                1 => $general['wysiwyg']['ck']['contentsCss']
            );
        }

        // Make sure old settings for 'accept_file_types' are not still picked up. Before 1.5.4 we used to store them
        // as a regex-like string, and we switched to an array. If we find the old style, fall back to the defaults.
        if (isset($general['accept_file_types']) && !is_array($general['accept_file_types'])) {
            unset($general['accept_file_types']);
        }

        // Merge the array with the defaults. Setting the required values that aren't already set.
        $general = Arr::mergeRecursiveDistinct($this->defaultConfig, $general);

        // Make sure the cookie_domain for the sessions is set properly.
        if (empty($general['cookies_domain'])) {
            if (isset($_SERVER['HTTP_HOST'])) {
                $hostname = $_SERVER['HTTP_HOST'];
            } elseif (isset($_SERVER['SERVER_NAME'])) {
                $hostname = $_SERVER['SERVER_NAME'];
            } else {
                $hostname = '';
            }

            // Don't set the domain for a cookie on a "TLD" - like 'localhost', or if the server_name is an IP-address
            if ((strpos($hostname, '.') > 0) && preg_match("/[a-z0-9]/i", $hostname)) {
                if (preg_match("/^www[0-9]*./", $hostname)) {
                    $general['cookies_domain'] = '.' . preg_replace("/^www[0-9]*./", '', $hostname);
                } else {
                    $general['cookies_domain'] = '.' . $hostname;
                }
                // Make sure we don't have consecutive '.'-s in the cookies_domain..
                $general['cookies_domain'] = str_replace('..', '.', $general['cookies_domain']);
            } else {
                $general['cookies_domain'] = '';
            }
        }

        // Make sure Bolt's mount point is OK:
        $general['branding']['path'] = '/' . String::makeSafe($general['branding']['path']);

        return $general;
    }

    protected function parseTaxonomy()
    {
        $taxonomies = $this->parseConfigYaml('taxonomy.yml');

        foreach ($taxonomies as $key => $taxonomy) {
            if (!isset($taxonomy['name'])) {
                $taxonomy['name'] = ucwords($taxonomy['slug']);
            }
            if (!isset($taxonomy['singular_name'])) {
                if (isset($taxonomy['singular_slug'])) {
                    $taxonomy['singular_name'] = ucwords($taxonomy['singular_slug']);
                } else {
                    $taxonomy['singular_name'] = ucwords($taxonomy['slug']);
                }
            }
            if (!isset($taxonomy['slug'])) {
                $taxonomy['slug'] = strtolower(String::makeSafe($taxonomy['name']));
            }
            if (!isset($taxonomy['singular_slug'])) {
                $taxonomy['singular_slug'] = strtolower(String::makeSafe($taxonomy['singular_name']));
            }
            if (!isset($taxonomy['has_sortorder'])) {
                $taxonomy['has_sortorder'] = false;
            }

            // Make sure the options are $key => $value pairs, and not have implied integers for keys.
            if (!empty($taxonomy['options']) && is_array($taxonomy['options'])) {
                $options = array();
                foreach ($taxonomy['options'] as $optionkey => $optionvalue) {
                    if (is_numeric($optionkey)) {
                        $optionkey = String::slug($optionvalue);
                    }
                    $options[$optionkey] = $optionvalue;
                }
                $taxonomy['options'] = $options;
            }

            // If taxonomy is like tags, set 'tagcloud' to true by default.
            if (($taxonomy['behaves_like'] == 'tags') && (!isset($taxonomy['tagcloud']))) {
                $taxonomy['tagcloud'] = true;
            }

            $taxonomies[$key] = $taxonomy;
        }

        return $taxonomies;
    }

    protected function parseContentTypes($acceptableFileTypes)
    {
        $contentTypes = array();
        $tempContentTypes = $this->parseConfigYaml('contenttypes.yml');
        foreach ($tempContentTypes as $key => $contentType) {
            $contentType = $this->parseContentType($key, $contentType, $acceptableFileTypes);
            $contentTypes[$contentType['slug']] = $contentType;
        }
        return $contentTypes;
    }

    protected function parseContentType($key, $contentType, $acceptableFileTypes)
    {
        // If the slug isn't set, and the 'key' isn't numeric, use that as the slug.
        if (!isset($contentType['slug']) && !is_numeric($key)) {
            $contentType['slug'] = String::slug($key);
        }

        // If neither 'name' nor 'slug' is set, we need to warn the user. Same goes for when
        // neither 'singular_name' nor 'singular_slug' is set.
        if (!isset($contentType['name']) && !isset($contentType['slug'])) {
            $error = sprintf("In contenttype <code>%s</code>, neither 'name' nor 'slug' is set. Please edit <code>contenttypes.yml</code>, and correct this.", $key);
            throw new LowlevelException($error);
        }
        if (!isset($contentType['singular_name']) && !isset($contentType['singular_slug'])) {
            $error = sprintf("In contenttype <code>%s</code>, neither 'singular_name' nor 'singular_slug' is set. Please edit <code>contenttypes.yml</code>, and correct this.", $key);
            throw new LowlevelException($error);
        }

        if (!isset($contentType['slug'])) {
            $contentType['slug'] = String::slug($contentType['name']);
        }
        if (!isset($contentType['singular_slug'])) {
            $contentType['singular_slug'] = String::slug($contentType['singular_name']);
        }
        if (!isset($contentType['show_on_dashboard'])) {
            $contentType['show_on_dashboard'] = true;
        }
        if (!isset($contentType['show_in_menu'])) {
            $contentType['show_in_menu'] = true;
        }
        if (!isset($contentType['sort'])) {
            $contentType['sort'] = false;
        }
        if (!isset($contentType['default_status'])) {
            $contentType['default_status'] = 'draft';
        }

        list($fields, $groups) = $this->parseFieldsAndGroups($contentType['fields'], $acceptableFileTypes);
        $contentType['fields'] = $fields;
        if (!empty($groups)) {
            $contentType['groups'] = $groups;
        }

        // Make sure taxonomy is an array.
        if (isset($contentType['taxonomy']) && !is_array($contentType['taxonomy'])) {
            $contentType['taxonomy'] = array($contentType['taxonomy']);
        }

        // when adding relations, make sure they're added by their slug. Not their 'name' or 'singular name'.
        if (!empty($contentType['relations']) && is_array($contentType['relations'])) {
            foreach ($contentType['relations'] as $relkey => $relation) {
                if ($relkey != String::slug($relkey)) {
                    $contentType['relations'][String::slug($relkey)] = $contentType['relations'][$relkey];
                    unset($contentType['relations'][$relkey]);
                }
            }
        }

        return $contentType;
    }

    protected function parseFieldsAndGroups($fields, $acceptableFileTypes)
    {
        $currentGroup = false;
        $groups = array();

        foreach ($fields as $key => $field) {
            unset($fields[$key]);
            $key = str_replace('-', '_', strtolower(String::makeSafe($key, true)));

            // If field is a "file" type, make sure the 'extensions' are set, and it's an array.
            if ($field['type'] == 'file' || $field['type'] == 'filelist') {
                if (empty($field['extensions'])) {
                    $field['extensions'] = array_intersect(
                        array('doc', 'docx', 'txt', 'md', 'pdf', 'xls', 'xlsx', 'ppt', 'pptx', 'csv'),
                        $acceptableFileTypes
                    );
                }

                if (!is_array($field['extensions'])) {
                    $field['extensions'] = array($field['extensions']);
                }
            }

            // If field is an "image" type, make sure the 'extensions' are set, and it's an array.
            if ($field['type'] == 'image' || $field['type'] == 'imagelist') {
                if (empty($field['extensions'])) {
                    $field['extensions'] = array_intersect(
                        array('gif', 'jpg', 'jpeg', 'png'),
                        $acceptableFileTypes
                    );
                }

                if (!is_array($field['extensions'])) {
                    $field['extensions'] = array($field['extensions']);
                }
            }

            // If field is a "Select" type, make sure the array is a "hash" (as opposed to a "map")
            // For example: [ 'yes', 'no' ] => { 'yes': 'yes', 'no': 'no' }
            // The reason that we do this, is because if you set values to ['blue', 'green'], that is
            // what you'd expect to see in the database. Not '0' and '1', which is what would happen,
            // if we didn't "correct" it here. 
            // @see used hack: http://stackoverflow.com/questions/173400/how-to-check-if-php-array-is-associative-or-sequential
            if ($field['type'] == 'select' && is_array($field['values']) && 
                array_values($field['values']) === $field['values'] ) {
                $field['values'] = array_combine($field['values'], $field['values']);
            }
            
            // If the field has a 'group', make sure it's added to the 'groups' array, so we can turn
            // them into tabs while rendering. This also makes sure that once you started with a group,
            // all others have a group too.
            if (!empty($field['group'])) {
                $currentGroup = $field['group'];
                $groups[] = $currentGroup;
            } else {
                $field['group'] = $currentGroup;
            }

            $fields[$key] = $field;
        }

        // Make sure the 'uses' of the slug is an array.
        if (isset($fields['slug']) && isset($fields['slug']['uses']) &&
            !is_array($fields['slug']['uses'])
        ) {
            $fields['slug']['uses'] = array($fields['slug']['uses']);
        }

        $groups = array_unique($groups);
        return array($fields, $groups);
    }

    /**
     * Sanity checks for doubles in in contenttypes.
     */
    public function checkConfig()
    {
        $slugs = array();

        $wrongctype = false;

        foreach ($this->data['contenttypes'] as $key => $ct) {
            /**
             * Make sure any field that has a 'uses' parameter actually points to a field that exists.
             * For example, this will show a notice:
             * entries:
             *   name: Entries
             *     singular_name: Entry
             *     fields:
             *       title:
             *         type: text
             *         class: large
             *       slug:
             *         type: slug
             *         uses: name
             */
            foreach ($ct['fields'] as $fieldname => $field) {
                // Verify that the contenttype doesn't try to add fields that are reserved.
                if ($fieldname != 'slug' && in_array($fieldname, $this->reservedFieldNames)) {
                    $error = Trans::__(
                        'contenttypes.generic.reserved-name',
                        array('%contenttype%' => $key, '%field%' => $fieldname)
                    );
                    $this->app['session']->getFlashBag()->set('error', $error);

                    return;
                }

                // Check 'uses'. If it's an array, split it up, and check the separate parts. We also need to check
                // for the fields that are always present, like 'id'.
                if (is_array($field) && !empty($field['uses'])) {
                    foreach ($field['uses'] as $useField) {
                        if (!empty($field['uses']) && empty($ct['fields'][$useField]) && !in_array($useField, $this->reservedFieldNames)) {
                            $error = Trans::__(
                                'contenttypes.generic.wrong-use-field',
                                array('%contenttype%' => $key, '%field%' => $fieldname, '%uses%' => $useField)
                            );
                            $this->app['session']->getFlashBag()->set('error', $error);

                            return;
                        }
                    }
                }

                // Make sure we have a 'label', 'class', 'variant' and 'default'.
                if (!isset($field['label'])) {
                    $this->set("contenttypes/{$key}/fields/{$fieldname}/label", '');
                }
                if (!isset($field['class'])) {
                    $this->set("contenttypes/{$key}/fields/{$fieldname}/class", 'form-control');
                } else {
                    $this->set("contenttypes/{$key}/fields/{$fieldname}/class", 'form-control ' . $field['class']);
                }
                if (!isset($field['variant'])) {
                    $this->set("contenttypes/{$key}/fields/{$fieldname}/variant", '');
                }
                if (!isset($field['default'])) {
                    $this->set("contenttypes/{$key}/fields/{$fieldname}/default", '');
                }
                if (!isset($field['pattern'])) {
                    $this->set("contenttypes/{$key}/fields/{$fieldname}/pattern", '');
                }

                // Make sure the 'type' is in the list of allowed types
                if (!isset($field['type']) || !$this->fields->has($field['type'])) {
                    $error = Trans::__(
                        'contenttypes.generic.no-proper-type',
                        array('%contenttype%' => $key, '%field%' => $fieldname, '%type%' =>
                         $field['type'])
                    );
                    $this->app['session']->getFlashBag()->set('error', $error);
                    $wrongctype = true && $this->app['users']->getCurrentUsername();
                }
            }

            // Keep a running score of used slugs..
            if (!isset($slugs[$ct['slug']])) {
                $slugs[$ct['slug']] = 0;
            }
            $slugs[$ct['slug']]++;
            if (!isset($slugs[$ct['singular_slug']])) {
                $slugs[$ct['singular_slug']] = 0;
            }
            if ($ct['singular_slug'] != $ct['slug']) {
                $slugs[$ct['singular_slug']]++;
            }
        }

        // Check DB-tables integrity
        if (!$wrongctype && $this->app['integritychecker']->needsCheck() &&
           (count($this->app['integritychecker']->checkTablesIntegrity()) > 0) &&
            $this->app['users']->getCurrentUsername()) {
            $msg = Trans::__(
                "The database needs to be updated/repaired. Go to 'Settings' > '<a href=\"%link%\">Check Database</a>' to do this now.",
                array('%link%' => Lib::path('dbcheck'))
            );
            $this->app['session']->getFlashBag()->set('error', $msg);

            return;
        }

        // Sanity checks for taxonomy.yml
        foreach ($this->data['taxonomy'] as $key => $taxo) {
            // Show some helpful warnings if slugs or keys are not set correctly.
            if ($taxo['slug'] != $key) {
                $error = Trans::__(
                    "The identifier and slug for '%taxonomytype%' are the not the same ('%slug%' vs. '%taxonomytype%'). Please edit taxonomy.yml, and make them match to prevent inconsistencies between database storage and your templates.",
                    array('%taxonomytype%' => $key, '%slug%' => $taxo['slug'])
                );
                $this->app['session']->getFlashBag()->set('error', $error);

                return;
            }
        }

        // if there aren't any other errors, check for duplicates across contenttypes..
        if (!$this->app['session']->getFlashBag()->has('error')) {
            foreach ($slugs as $slug => $count) {
                if ($count > 1) {
                    $error = Trans::__(
                        "The slug '%slug%' is used in more than one contenttype. Please edit contenttypes.yml, and make them distinct.",
                        array('%slug%' => $slug)
                    );
                    $this->app['session']->getFlashBag()->set('error', $error);

                    return;
                }
            }
        }
    }

    /**
     * A getter to access the fields manager
     *
     * @return Field\Manager
     **/
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * Assume sensible defaults for a number of options.
     */
    protected function getDefaults()
    {
        return array(
            'database'                    => array('prefix' => 'bolt_'),
            'sitename'                    => 'Default Bolt site',
            'homepage'                    => 'page/*',
            'homepage_template'           => 'index.twig',
            'locale'                      => \Bolt\Application::DEFAULT_LOCALE,
            'recordsperpage'              => 10,
            'recordsperdashboardwidget'   => 5,
            'systemlog'                   => array(
                'enabled' => true
            ),
            'changelog'                   => array(
                'enabled' => false
            ),
            'debuglog'                    => array(
                'enabled'  => false,
                'level'    => 'DEBUG',
                'filename' => 'bolt-debug.log'
            ),
            'debug'                       => false,
            'debug_show_loggedoff'        => false,
            'debug_error_level'           => 6135,
            // equivalent to E_ALL &~ E_NOTICE &~ E_DEPRECATED &~ E_USER_DEPRECATED
            'debug_enable_whoops'         => true,
            'debug_permission_audit_mode' => false,
            'frontend_permission_checks'  => false,
            'strict_variables'            => false,
            'theme'                       => 'default',
            'debug_compressjs'            => true,
            'debug_compresscss'           => true,
            'listing_template'            => 'listing.twig',
            'listing_records'             => '5',
            'listing_sort'                => 'datepublish DESC',
            'caching'                     => array(
                'config'    => true,
                'rendering' => false,
                'templates' => false,
                'request'   => false
            ),
            'wysiwyg'                     => array(
                'images'      => false,
                'tables'      => false,
                'fontcolor'   => false,
                'align'       => false,
                'subsuper'    => false,
                'embed'       => true,
                'anchor'      => false,
                'underline'   => false,
                'strike'      => false,
                'blockquote'  => true,
                'codesnippet' => false,
                'specialchar' => false,
                'ck'          => array(
                    'allowedContent'          => true,
                    'autoParagraph'           => true,
                    'contentsCss'             => array(
                        $this->app['resources']->getUrl('app') . 'view/lib/ckeditor/contents.css',
                        $this->app['resources']->getUrl('app') . 'view/css/ckeditor.css',
                    ),
                    'filebrowserWindowWidth'  => 640,
                    'filebrowserWindowHeight' => 480
                ),
                'filebrowser' => array(
                    'browseUrl'      => $this->app['resources']->getUrl('async') . 'filebrowser/',
                    'imageBrowseUrl' => $this->app['resources']->getUrl('bolt') . 'files/files'
                ),
            ),
            'canonical'                   => !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '',
            'developer_notices'           => false,
            'cookies_use_remoteaddr'      => true,
            'cookies_use_browseragent'    => false,
            'cookies_use_httphost'        => true,
            'cookies_https_only'          => false,
            'cookies_lifetime'            => 14 * 24 * 3600,
            'thumbnails'                  => array(
                'default_thumbnail' => array(160, 120),
                'default_image'     => array(1000, 750),
                'quality'           => 75,
                'cropping'          => 'crop',
                'notfound_image'    => 'view/img/default_notfound.png',
                'error_image'       => 'view/img/default_error.png'
            ),
            'accept_file_types'           => explode(",", "twig,html,js,css,scss,gif,jpg,jpeg,png,ico,zip,tgz,txt,md,doc,docx,pdf,epub,xls,xlsx,csv,ppt,pptx,mp3,ogg,wav,m4a,mp4,m4v,ogv,wmv,avi,webm,svg"),
            'hash_strength'               => 10,
            'branding'                    => array(
                'name'        => 'Bolt',
                'path'        => '/bolt',
                'provided_by' => array()
            ),
            'maintenance_mode'            => false
        );
    }

    public function getTwigPath()
    {
        $themepath = $this->app['resources']->getPath("theme");
        $end = $this->getWhichEnd($this->get('general/branding/path'));

        if ($end == 'frontend' && file_exists($themepath)) {
            $twigpath = array($themepath);
        } else {
            $twigpath = array(realpath($this->app['resources']->getPath('app') . '/view/twig'));
        }

        // If the template path doesn't exist, flash error on the dashboard.
        if (!file_exists($themepath)) {
            $error = "Template folder 'theme/" . basename($this->get('general/theme')) . "' does not exist, or is not writable.";
            $this->app['session']->getFlashBag()->set('error', $error);
        }

        // We add these later, because the order is important: By having theme/ourtheme first,
        // files in that folder will take precedence. For instance when overriding the menu template.
        $twigpath[] = realpath($this->app['resources']->getPath('app') . '/theme_defaults');

        return $twigpath;
    }

    /**
     * Will be made protected in Bolt 3.0
     */
    public function setCKPath()
    {
        $app = $this->app['resources']->getPath('app');

        // Make sure the paths for CKeditor config are always set correctly..
        $this->set(
            'general/wysiwyg/ck/contentsCss',
            array(
                $app . 'view/lib/ckeditor/contents.css',
                $app . 'view/css/ckeditor.css'
            )
        );
        $this->set('general/wysiwyg/filebrowser/browseUrl', $this->app['resources']->getUrl('async') . 'filebrowser/');
        $this->set(
            'general/wysiwyg/filebrowser/imageBrowseUrl',
            $this->app['resources']->getUrl('bolt') . 'files/files/'
        );
    }

    protected function loadCache()
    {
        $dir = $this->app['resources']->getPath('config');
        /* Get the timestamps for the config files. config_local defaults to '0', because if it isn't present,
           it shouldn't trigger an update for the cache, while the others should.
        */
        $timestamps = array(
            file_exists($dir . '/config.yml') ? filemtime($dir . '/config.yml') : 10000000000,
            file_exists($dir . '/taxonomy.yml') ? filemtime($dir . '/taxonomy.yml') : 10000000000,
            file_exists($dir . '/contenttypes.yml') ? filemtime($dir . '/contenttypes.yml') : 10000000000,
            file_exists($dir . '/menu.yml') ? filemtime($dir . '/menu.yml') : 10000000000,
            file_exists($dir . '/routing.yml') ? filemtime($dir . '/routing.yml') : 10000000000,
            file_exists($dir . '/permissions.yml') ? filemtime($dir . '/permissions.yml') : 10000000000,
            file_exists($dir . '/config_local.yml') ? filemtime($dir . '/config_local.yml') : 0,
        );
        if (file_exists($this->app['resources']->getPath('cache') . '/config_cache.php')) {
            $this->cachetimestamp = filemtime($this->app['resources']->getPath('cache') . '/config_cache.php');
        } else {
            $this->cachetimestamp = 0;
        }

        if ($this->cachetimestamp > max($timestamps)) {
            $this->data = Lib::loadSerialize($this->app['resources']->getPath('cache') . '/config_cache.php');

            // Check if we loaded actual data.
            if (count($this->data) < 4 || empty($this->data['general'])) {
                return false;
            }

            // Check to make sure the version is still the same. If not, effectively invalidate the
            // cached config to force a reload.
            if (!isset($this->data['version']) || ($this->data['version'] != $this->app->getVersion())) {
                return false;
            }

            // Trigger the config loaded event on the resource manager
            $this->app['resources']->initializeConfig($this->data);

            // Yup, all seems to be right.
            return true;

        }

        return false;
    }

    protected function saveCache()
    {
        // Store the version number along with the config.
        $this->data['version'] = $this->app->getVersion();

        if ($this->get('general/caching/config')) {
            Lib::saveSerialize($this->app['resources']->getPath('cache') . '/config_cache.php', $this->data);

            return;
        }

        @unlink($this->app['resources']->getPath('cache') . '/config_cache.php');
    }

    protected function checkValidCache()
    {
        // Check the timestamp for the theme's config.yml
        $paths = $this->app['resources']->getPaths();
        $themeConfigFile = $paths['themepath'] . '/config.yml';
        // Note: we need to check if it exists, _and_ it's too old. Not _or_, hence the '0'
        $configTimestamp = file_exists($themeConfigFile) ? filemtime($themeConfigFile) : 0;

        if ($this->cachetimestamp <= $configTimestamp) {
            // Invalidate cache for next request.
            @unlink($paths['cache'] . '/config_cache.php');
        }
    }


    /**
     * Get an associative array with the correct options for the chosen database type.
     *
     * @return array
     */

    public function getDBOptions()
    {
        $configdb = $this->data['general']['database'];

        if (isset($configdb['driver']) && in_array($configdb['driver'], array('pdo_sqlite', 'sqlite'))) {
            $basename = isset($configdb['databasename']) ? basename($configdb['databasename']) : 'bolt';
            if (Lib::getExtension($basename) != 'db') {
                $basename .= '.db';
            }

            if (isset($configdb["path"])) {
                $configpaths = $this->app['resources']->getPaths();
                if (substr($configdb['path'], 0, 1) !== "/") {
                    $configdb['path'] = $configpaths["rootpath"] . '/' . $configdb['path'];
                }
            }

            $dboptions = array(
                'driver' => 'pdo_sqlite',
                'path' => isset($configdb['path']) ? realpath($configdb['path']) . '/' . $basename : $this->app['resources']->getPath('database') . '/' . $basename,
                'randomfunction' => 'RANDOM()',
                'memory' => isset($configdb['memory']) ? true : false
            );
        } else {
            // Assume we configured it correctly. Yeehaa!

            if (empty($configdb['password'])) {
                $configdb['password'] = '';
            }

            $driver = (isset($configdb['driver']) ? $configdb['driver'] : 'pdo_mysql');
            $randomfunction = '';
            if (in_array($driver, array('mysql', 'mysqli'))) {
                $driver = 'pdo_mysql';
                $randomfunction = 'RAND()';
            }
            if (in_array($driver, array('postgres', 'postgresql'))) {
                $driver = 'pdo_pgsql';
                $randomfunction = 'RANDOM()';
            }

            $dboptions = array(
                'driver'         => $driver,
                'host'           => (isset($configdb['host']) ? $configdb['host'] : 'localhost'),
                'dbname'         => $configdb['databasename'],
                'user'           => $configdb['username'],
                'password'       => $configdb['password'],
                'randomfunction' => $randomfunction
            );

            $dboptions['charset'] = isset($configdb['charset']) ? $configdb['charset'] : 'utf8';
        }

        switch ($dboptions['driver']) {
            case 'pdo_mysql':
                $dboptions['port'] = isset($configdb['port']) ? $configdb['port'] : '3306';
                break;
            case 'pdo_pgsql':
                $dboptions['port'] = isset($configdb['port']) ? $configdb['port'] : '5432';
        }

        return $dboptions;
    }

    /**
     * Utility function to determine which 'end' we're using right now. Can be either "frontend", "backend", "async" or "cli".
     *
     * NOTE: If the Request object has not been intialized by Silex yet,
     * we create a local version based on the request globals.
     *
     * @param  string $mountpoint
     * @return string
     */
    public function getWhichEnd($mountpoint = '')
    {
        // Get a request object, if not initialized by Silex yet, we'll create our own
        try {
            $request = $this->app['request'];
        } catch (\RuntimeException $e) {
            // Return CLI if request not already exist and we're on the CLI
            if (php_sapi_name() == 'cli') {
                $this->app['end'] = 'cli';
                return 'cli';
            }

            $request = Request::createFromGlobals();
        }

        // Ensure the request path always includes a left slash
        $reqPath = '/' . ltrim($request->getPathInfo(), '/');

        // Default mountpoint is branding path (defaults to 'bolt' unless changed in config)
        if (empty($mountpoint)) {
            $mountpoint = $this->get('general/branding/path');
        }

        // Ensure left slash on mountpoint
        $mountpoint = '/' . ltrim($mountpoint, '/');

        if (strpos($request->getPathInfo(), '/async') === 0 || $request->isXmlHttpRequest()) {
            // If path begins with '/async' or is AJAX request, is 'async'
            $end = 'async';
        } elseif (strpos($reqPath, $mountpoint) === 0) {
            // If request path starts with mountpoint, is backend
            $end = 'backend';
        } else { 
            // Else assume frontend
            $end = 'frontend';
        }

        $this->app['end'] = $end;
        return $end;
    }

    /**
     * Get a timestamp, corrected to the timezone.
     *
     * @return string timestamp
     */
    public function getTimestamp($when)
    {
        $timezone = $this->get('general/timezone');
        $now = date_format(new \DateTime($when, new \DateTimeZone($timezone)), 'Y-m-d H:i:s');

        return $now;
    }

    /**
     * Get the current timestamp, corrected to the timezone.
     *
     * @return string current timestamp
     */
    public function getCurrentTimestamp()
    {
        $timezone = $this->get('general/timezone');
        $now = date_format(new \DateTime($timezone), 'Y-m-d H:i:s');

        return $now;
    }
}
