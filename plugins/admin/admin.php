<?php
namespace Grav\Plugin;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\GPM\GPM;
use Grav\Common\Grav;
use Grav\Common\Inflector;
use Grav\Common\Language\Language;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\User\User;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\Session\Session;

class AdminPlugin extends Plugin
{
    /**
     * @var bool
     */
    protected $active = false;

    /**
     * @var string
     */
    protected $template;

    /**
     * @var  string
     */
    protected $theme;

    /**
     * @var string
     */
    protected $route;

    /**
     * @var Uri
     */
    protected $uri;

    /**
     * @var Admin
     */
    protected $admin;

    /**
     * @var Session
     */
    protected $session;

    /**
     * @var Popularity
     */
    protected $popularity;

    /**
     * @var string
     */
    protected $base;

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        if (!Grav::instance()['config']->get('plugins.admin-pro.enabled')) {
            return [
                'onPluginsInitialized'  => [['setup', 100000], ['onPluginsInitialized', 1000]],
                'onShutdown'            => ['onShutdown', 1000],
                'onFormProcessed'       => ['onFormProcessed', 0]
            ];
        }

        return [];
    }

    /**
     * If the admin path matches, initialize the Login plugin configuration and set the admin
     * as active.
     */
    public function setup()
    {
        $route = $this->config->get('plugins.admin.route');
        if (!$route) {
            return;
        }

        $this->base = '/' . trim($route, '/');
        $this->uri = $this->grav['uri'];

        // check for existence of a user account
        $account_dir = $file_path = $this->grav['locator']->findResource('account://');
        $user_check = (array) glob($account_dir . '/*.yaml');

        // If no users found, go to register
        if (!count($user_check) > 0) {
            if (!$this->isAdminPath()) {
                $this->grav->redirect($this->base);
            }
            $this->template = 'register';
        }

        // Only activate admin if we're inside the admin path.
        if ($this->isAdminPath()) {
            $this->active = true;
        }
    }

    /**
     * Validate a value. Currently validates
     *
     * - 'user' for username format and username availability.
     * - 'password1' for password format
     * - 'password2' for equality to password1
     *
     * @param object $form      The form
     * @param string $type      The field type
     * @param string $value     The field value
     * @param string $extra     Any extra value required
     *
     * @return mixed
     */
    protected function validate($type, $value, $extra = '')
    {
        switch ($type) {
            case 'username_format':
                if (!preg_match('/^[a-z0-9_-]{3,16}$/', $value)) {
                    return false;
                }
                return true;
                break;

            case 'password1':
                if (!preg_match('/(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}/', $value)) {
                    return false;
                }
                return true;
                break;

            case 'password2':
                if (strcmp($value, $extra)) {
                    return false;
                }
                return true;
                break;
        }
    }

    /**
     * Process the admin registration form.
     *
     * @param Event $event
     */
    public function onFormProcessed(Event $event)
    {
        $form = $event['form'];
        $action = $event['action'];

        switch ($action) {

            case 'register_admin_user':

                if (!$this->config->get('plugins.login.enabled')) {
                    throw new \RuntimeException($this->grav['language']->translate('PLUGIN_LOGIN.PLUGIN_LOGIN_DISABLED'));
                }

                $data = [];
                $username = $form->value('username');

                if ($form->value('password1') != $form->value('password2')) {
                    $this->grav->fireEvent('onFormValidationError',
                        new Event([
                            'form' => $form,
                            'message' => $this->grav['language']->translate('PLUGIN_LOGIN.PASSWORDS_DO_NOT_MATCH')
                        ]));
                    $event->stopPropagation();
                    return;
                }

                $data['password'] = $form->value('password1');

                $fields = [
                    'email',
                    'fullname',
                    'title'
                ];

                foreach($fields as $field) {
                    // Process value of field if set in the page process.register_user
                    if (!isset($data[$field]) && $form->value($field)) {
                        $data[$field] = $form->value($field);
                    }
                }

                unset($data['password1']);
                unset($data['password2']);

                // Don't store the username: that is part of the filename
                unset($data['username']);

                // Extra lowercase to ensure file is saved lowercase
                $username = strtolower($username);

                $inflector = new Inflector();

                $data['fullname'] = isset($data['fullname']) ? $data['fullname'] : $inflector->titleize($username);
                $data['title'] = isset($data['title']) ? $data['title'] : 'Administrator';
                $data['state'] = 'enabled';
                $data['access'] = ['admin' => ['login' => true, 'super' => true], 'site' => ['login' => true]];

                 // Create user object and save it
                $user = new User($data);
                $file = CompiledYamlFile::instance($this->grav['locator']->findResource('user://accounts/' . $username . YAML_EXT, true, true));
                $user->file($file);
                $user->save();
                $user = User::load($username);

                //Login user
                $this->grav['session']->user = $user;
                unset($this->grav['user']);
                $this->grav['user'] = $user;
                $user->authenticated = $user->authorize('site.login');

                $messages = $this->grav['messages'];
                $messages->add($this->grav['language']->translate('PLUGIN_ADMIN.LOGIN_LOGGED_IN'), 'info');
                $this->grav->redirect($this->base);

                break;
        }
    }

    /**
     * If the admin plugin is set as active, initialize the admin
     */
    public function onPluginsInitialized()
    {
        // Only activate admin if we're inside the admin path.
        if ($this->active) {
            if (php_sapi_name() == 'cli-server') {
                throw new \RuntimeException('The Admin Plugin cannot run on the PHP built-in webserver. It needs Apache, Nginx or another full-featured web server.', 500);
            }
            $this->grav['debugger']->addMessage("Admin Basic");
            $this->initializeAdmin();

            // Disable Asset pipelining (old method - remove this after Grav is updated)
            if (!method_exists($this->grav['assets'],'setJsPipeline')) {
                $this->config->set('system.assets.css_pipeline', false);
                $this->config->set('system.assets.js_pipeline', false);
            }

            // Replace themes service with admin.
            $this->grav['themes'] = function ($c) {
                require_once __DIR__ . '/classes/themes.php';
                return new Themes($this->grav);
            };
        }

        // We need popularity no matter what
        require_once __DIR__ . '/classes/popularity.php';
        $this->popularity = new Popularity();
    }

    protected function initializeController($task, $post) {
        require_once __DIR__ . '/classes/controller.php';
        $controller = new AdminController($this->grav, $this->template, $task, $this->route, $post);
        $controller->execute();
        $controller->redirect();
    }

    /**
     * Sets longer path to the home page allowing us to have list of pages when we enter to pages section.
     */
    public function onPagesInitialized()
    {
        $this->session = $this->grav['session'];

        // Set original route for the home page.
        $home = '/' . trim($this->config->get('system.home.alias'), '/');

        // set the default if not set before
        $this->session->expert = $this->session->expert ?: false;

        // set session variable if it's passed via the url
        if ($this->uri->param('mode') == 'expert') {
            $this->session->expert = true;
        } elseif ($this->uri->param('mode') == 'normal') {
            $this->session->expert = false;
        }

        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        $this->grav['admin']->routes = $pages->routes();

        // Remove default route from routes.
        if (isset($this->grav['admin']->routes['/'])) {
            unset($this->grav['admin']->routes['/']);
        }

        $page = $pages->dispatch('/', true);

        // If page is null, the default page does not exist, and we cannot route to it
        if ($page) {
            $page->route($home);
        }

        // Make local copy of POST.
        $post = !empty($_POST) ? $_POST : array();

        // Handle tasks.
        $this->admin->task = $task = !empty($post['task']) ? $post['task'] : $this->uri->param('task');
        if ($task) {
            $this->initializeController($task, $post);
        } elseif ($this->template == 'logs' && $this->route) {
            // Display RAW error message.
            echo $this->admin->logEntry();
            exit();
        }

        $self = $this;

        // make sure page is not frozen!
        unset($this->grav['page']);

        // Replace page service with admin.
        $this->grav['page'] = function () use ($self) {
            $page = new Page;

            if (file_exists(__DIR__ . "/pages/admin/{$self->template}.md")) {
                $page->init(new \SplFileInfo(__DIR__ . "/pages/admin/{$self->template}.md"));
                $page->slug(basename($self->template));
                return $page;
            }

            // If the page cannot be found, try looking in plugins.
            // Allows pages added by plugins in admin
            $plugins = Grav::instance()['config']->get('plugins', []);

            foreach($plugins as $plugin => $data) {
                $path = $this->grav['locator']->findResource(
                    "user://plugins/{$plugin}/admin/pages/{$self->template}.md");

                if (file_exists($path)) {
                    $page->init(new \SplFileInfo($path));
                    $page->slug(basename($self->template));
                    return $page;
                }
            }
        };

        if (empty($this->grav['page'])) {
            $event = $this->grav->fireEvent('onPageNotFound');

            if (isset($event->page)) {
                unset($this->grav['page']);
                $this->grav['page'] = $event->page;
            } else {
                throw new \RuntimeException('Page Not Found', 404);
            }
        }
    }

    public function onAssetsInitialized()
    {
        // Disable Asset pipelining
        $assets = $this->grav['assets'];
        if (method_exists($assets, 'setJsPipeline')) {
            $assets->setJsPipeline(false);
            $assets->setCssPipeline(false);
        }

        // Explicitly set a timestamp on assets
        if (method_exists($assets, 'setTimestamp')) {
            $assets->setTimestamp(substr(md5(GRAV_VERSION),0,10));
        }
    }

    /**
     * Add twig paths to plugin templates.
     */
    public function onTwigTemplatePaths()
    {
        $twig_paths = [];
        $this->grav->fireEvent('onAdminTwigTemplatePaths', new Event(['paths' => &$twig_paths]));

        $twig_paths[] = __DIR__ . '/themes/' . $this->theme . '/templates';

        $this->grav['twig']->twig_paths = $twig_paths;

    }

    /**
     * Set all twig variables for generating output.
     */
    public function onTwigSiteVariables()
    {
        $twig = $this->grav['twig'];

        // Dynamic type support
        $format = $this->uri->extension();
        $ext = '.' . ($format ? $format : 'html') . TWIG_EXT;

        $twig->twig_vars['location'] = $this->template;
        $twig->twig_vars['base_url_relative_frontend'] = $twig->twig_vars['base_url_relative'] ?: '/';
        $twig->twig_vars['admin_route'] = trim($this->config->get('plugins.admin.route'), '/');
        $twig->twig_vars['base_url_relative'] =
            $twig->twig_vars['base_url_simple'] . '/' . $twig->twig_vars['admin_route'];
        $twig->twig_vars['theme_url'] = '/user/plugins/admin/themes/' . $this->theme;
        $twig->twig_vars['base_url'] = $twig->twig_vars['base_url_relative'];
        $twig->twig_vars['base_path'] = GRAV_ROOT;
        $twig->twig_vars['admin'] = $this->admin;

        // Gather Plugin-hooked nav items
        $this->grav->fireEvent('onAdminMenu');

        // DEPRECATED
        $this->grav->fireEvent('onAdminTemplateNavPluginHook');

        switch ($this->template) {
            case 'dashboard':
                $twig->twig_vars['popularity'] = $this->popularity;
                break;
            case 'pages':
                $page = $this->admin->page(true);
                if ($page != null) {
                    $twig->twig_vars['file'] = File::instance($page->filePath());
                    $twig->twig_vars['media_types'] = str_replace('defaults,', '',
                        implode(',.', array_keys($this->config->get('media'))));

                }
                break;
        }
    }

    public function onShutdown()
    {
        // Just so we know that we're in this debug mode
        if ($this->config->get('plugins.admin.popularity.enabled')) {

            // Only track non-admin
            if (!$this->active) {
                $this->popularity->trackHit();
            }
        }
    }

    /**
     * Handles getting GPM updates
     */
    public function onTaskGPM()
    {
        $action = $_POST['action']; // getUpdatable | getUpdatablePlugins | getUpdatableThemes | gravUpdates
        $flush  = isset($_POST['flush']) && $_POST['flush'] == true ? true : false;

        if (isset($this->grav['session'])) {
            $this->grav['session']->close();
        }

        try {
            $gpm = new GPM($flush);

            switch ($action) {
                case 'getUpdates':
                    $resources_updates = $gpm->getUpdatable();
                    if ($gpm->grav != null) {
                        $grav_updates = [
                            "isUpdatable" => $gpm->grav->isUpdatable(),
                            "assets"      => $gpm->grav->getAssets(),
                            "version"     => GRAV_VERSION,
                            "available"   => $gpm->grav->getVersion(),
                            "date"        => $gpm->grav->getDate(),
                            "isSymlink"   => $gpm->grav->isSymlink()
                        ];

                        echo json_encode([
                            "status" => "success",
                            "payload" => ["resources" => $resources_updates, "grav" => $grav_updates, "installed" => $gpm->countInstalled(), 'flushed' => $flush]
                        ]);
                    } else {
                        echo json_encode(["status" => "error", "message" => "Cannot connect to the GPM"]);
                    }
                    break;
            }
        } catch (\Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }

        exit;
    }

    /**
     * Initialize the admin.
     *
     * @throws \RuntimeException
     */
    protected function initializeAdmin()
    {
        $this->enable([
            'onTwigExtensions'    => ['onTwigExtensions', 1000],
            'onPagesInitialized'  => ['onPagesInitialized', 1000],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 1000],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 1000],
            'onAssetsInitialized' => ['onAssetsInitialized', 1000],
            'onTask.GPM'          => ['onTaskGPM', 0]
        ]);

        // Initialize admin class.
        require_once __DIR__ . '/classes/admin.php';

        // Check for required plugins
        if (!$this->grav['config']->get('plugins.login.enabled') ||
            !$this->grav['config']->get('plugins.form.enabled') ||
            !$this->grav['config']->get('plugins.email.enabled')) {
            throw new \RuntimeException('One of the required plugins is missing or not enabled');
        }

        // Double check we have system.yaml and site.yaml
        $config_files[] = $this->grav['locator']->findResource('user://config') . '/system.yaml';
        $config_files[] = $this->grav['locator']->findResource('user://config') . '/site.yaml';
        foreach ($config_files as $config_file) {
            if (!file_exists($config_file)) {
                touch($config_file);
            }
        }

        // Initialize Admin Language if needed
        /** @var Language $language */
        $language = $this->grav['language'];
        if ($language->enabled() && empty($this->grav['session']->admin_lang)) {
            $this->grav['session']->admin_lang = $language->getLanguage();
        }

        // Decide admin template and route.
        $path = trim(substr($this->uri->route(), strlen($this->base)), '/');

        if (empty($this->template)) {
            $this->template = 'dashboard';
        }

        // Can't access path directly...
        if ($path && $path != 'register') {
            $array = explode('/', $path, 2);
            $this->template = array_shift($array);
            $this->route = array_shift($array);
        }

        $this->admin = new Admin($this->grav, $this->base, $this->template, $this->route);

        // And store the class into DI container.
        $this->grav['admin'] = $this->admin;

        // Get theme for admin
        $this->theme = $this->config->get('plugins.admin.theme', 'grav');

        $assets = $this->grav['assets'];
        $translations  = 'if (!window.translations) window.translations = {}; ' . PHP_EOL . 'window.translations.PLUGIN_ADMIN = {};' . PHP_EOL;

        // Enable language translations
        $translations_actual_state = $this->config->get('system.languages.translations');
        $this->config->set('system.languages.translations', true);

        $strings = ['EVERYTHING_UP_TO_DATE',
            'UPDATES_ARE_AVAILABLE',
            'IS_AVAILABLE_FOR_UPDATE',
            'AND',
            'IS_NOW_AVAILABLE',
            'CURRENT',
            'UPDATE_GRAV_NOW',
            'TASK_COMPLETED',
            'UPDATE',
            'UPDATING_PLEASE_WAIT',
            'GRAV_SYMBOLICALLY_LINKED',
            'OF_YOUR',
            'OF_THIS',
            'HAVE_AN_UPDATE_AVAILABLE',
            'UPDATE_AVAILABLE',
            'UPDATES_AVAILABLE',
            'FULLY_UPDATED',
            'DAYS',
            'PAGE_MODES',
            'PAGE_TYPES',
            'ACCESS_LEVELS'
        ];

        foreach($strings as $string) {
            $translations .= 'translations.PLUGIN_ADMIN.' . $string .' = "' . $this->admin->translate('PLUGIN_ADMIN.' . $string) . '"; ' . PHP_EOL;;
        }

        // set the actual translations state back
        $this->config->set('system.languages.translations', $translations_actual_state);

        $assets->addInlineJs($translations);
    }

    /**
     * Add Twig Extensions
     */
    public function onTwigExtensions()
    {
        require_once(__DIR__.'/twig/AdminTwigExtension.php');
        $this->grav['twig']->twig->addExtension(new AdminTwigExtension());
    }

    public function isAdminPath()
    {
        if ($this->uri->route() == $this->base ||
        substr($this->uri->route(), 0, strlen($this->base) + 1) == $this->base . '/') {
            return true;
        }
        return false;
    }

}
