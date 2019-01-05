<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Uri;
use Grav\Common\Config\Config;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use RocketTheme\Toolbox\Event\Event;

/**
 * Class MicropubPlugin
 * @package Grav\Plugin
 */
class MicropubPlugin extends Plugin
{
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            return;
        }

        $config = $this->grav['config'];
        $enabled = array();

        $enabled = $this->addEnable($enabled, 'onTwigTemplatePaths', ['onTwigTemplatePaths', 0]);

        // ROUTE
        $uri = $this->grav['uri'];
        $route = $config->get('plugins.micropub.route');
        if ($route && $this->startsWith($uri->path(), $route)) {
                $enabled = $this->addEnable($enabled, 'onPagesInitialized', ['handleRequest', 0]);
        }

        // ADVERTISE
        $advertise = $config->get('plugins.micropub.advertise_method');
        if ($advertise === 'header') {
            $enabled = $this->addEnable($enabled, 'onPagesInitialized', ['advertiseHeader', 100]);
        } elseif ($advertise === 'link') {
            $enabled = $this->addEnable($enabled, 'onOutputGenerated', ['advertiseLink', 100]);
        }

        $this->enable($enabled);
    }
    /** 
     * Handle a Micropub request
     */
    public function handleRequest(Event $e) {

        $base = $this->grav['uri']->base();
        $route = $this->grav['uri']->route();

        $token_endpoint = 'https://tokens.indieauth.com/token';

        $config = $this->grav['config'];

        $_HEADERS = array();
        foreach(getallheaders() as $name => $value) {
            $_HEADERS[$name] = $value;
        }
        if (!isset($_HEADERS['Authorization'])) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 401 Unauthorized');
            echo 'Missing "Authorization" header.';
            exit;
        }
        if (!isset($_POST['h'])) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
            echo 'Missing "h" value.';
            exit;
        }
        $options = array(
            CURLOPT_URL => $token_endpoint,
            CURLOPT_HTTPGET => TRUE,
            CURLOPT_USERAGENT => $base,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_HEADER => FALSE,
            CURLOPT_HTTPHEADER => array(
                'Content-type: application/x-www-form-urlencoded',
                'Authorization: '.$_HEADERS['Authorization']
            )
        );
        $curl = curl_init();
        curl_setopt_array($curl, $options);
        $source = curl_exec($curl);
        curl_close($curl);
        parse_str($source, $values);
        if (!isset($values['me'])) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
            echo 'Missing "me" value in authentication token.';
            exit;
        }
        if (!isset($values['scope'])) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
            echo 'Missing "scope" value in authentication token.';
            exit;
        }
        if (substr($values['me'], -1) != '/') {
            $values['me'].= '/';
        }
        if (substr($base, -1) != '/') {
            $base.= '/';
        }
        if (strtolower($values['me']) != strtolower($base)) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
            echo 'Mismatching "me" value in authentication token.';
            exit;
        }
        if ( !stristr($values['scope'], 'post') && !stristr($values['scope'], 'create') ){
            header($_SERVER['SERVER_PROTOCOL'] . ' 403 Forbidden');
            echo 'Missing "post" or "create" value in "scope".';
            exit;
        }
        if (!isset($_POST['content'])) {
            header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
            echo 'Missing "content" value.';
            exit;
        }

        /* Everything's cool. Do something with the $_POST variables
           (such as $_POST['content'], $_POST['category'], $_POST['location'], etc.)
           e.g. create a new entry, store it in a database, whatever. */

        $content = $_POST["content"];
        $created = $this->createPage($content);
        if (!$created) {
            // Could not create file. Error has been thrown.
            return;
        }

        // Now respond

        // Temporarily return to homepage.
        // TODO: set location to newly created page.
        $return_url = $base;

        header($_SERVER['SERVER_PROTOCOL'] . ' 201 Created');
        header('Location: '.$return_url);

        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . '/pages/201-created.md'));
        $page->slug(basename($route));

        $pages = $this->grav['pages'];
        $pages->addPage($page, $route);   
         
    }

    private function createPage($content)
    {
        $config = $this->grav['config'];

        $parent_page = $config['parent_page'];
        $pages = $this->grav['pages'];
        $page = $pages->find($parent_page);
        if (!($page == null)) {
            $this->throw_500('Parent page not found.');
            return false;
        }
        $parent_path = $page->path();

        $slug = time();
        $folder = $parent_path.'/'.$slug;
        $file = $folder . '/item.md';
    
        if (!file_exists($folder)) {
            mkdir($folder);
        }
        file_put_contents($file, $content);
        return true;
    }    
    public function advertiseHeader(Event $e) {
        $uri = $this->grav['uri'];
        $config = $this->grav['config'];
        // Check if the current requested URL needs to advertise the endpoint.
        if (!$this->shouldAdvertise($uri, $config)) {
            return;
        }
        // Build and send the Link header.
        $root = $uri->rootUrl(true);
        $route = $config->get('plugins.micropub.route');
        $url = $root.$route;
        header('Link: <'.$url.'>; rel="micropub"', false);
    }
    public function advertiseLink(Event $e) {
        $uri = $this->grav['uri'];
        $config = $this->grav['config'];

        // Check if the current requested URL needs to advertise the endpoint.
        if (!$this->shouldAdvertise($uri, $config)) {
            return;
        }
        // Then only proceed if we are working on HTML.
        if ($this->grav['page']->templateFormat() !== 'html') {
            return;
        }
        // After that determine if a HEAD element exists to add the LINK to.
        $output = $this->grav->output;
        $headElement = strpos($output, '</head>');
        if ($headElement === false) {
            return;
        }
        // Build the LINK element.
        $root = $uri->rootUrl(true);
        $route = $config->get('plugins.micropub.route');
        $url = $root.$route;
        $tag = '<link href="'.$url.'" rel="micropub" />'."\n\n";
        // Inject LINK element before the HEAD element's closing tag.
        $output = substr_replace($output, $tag, $headElement, 0);
        // replace output
        $this->grav->output = $output;
    }
    /**
     * Determine whether to advertise the Micropub endpoint on the current page.
     *
     * @param  Uri    $uri    Grav Uri object for the current page.
     * @param  Config $config Grav Config object containing plugin settings.
     *
     * @return boolean
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }
    private function shouldAdvertise(Uri $uri, Config $config) {
        // Do not advertise on the receiver itself.
        if ($this->startsWith($uri->route(), $config->get('plugins.micropub.route'))) {
            return false;
        }
        return true;
    }
    private function startsWith($haystack, $needle) {
        // search backwards starting from haystack length characters from the end
        return $needle === "" || strrpos($haystack, $needle, -strlen($haystack)) !== false;
    }    
    private function endsWith($haystack, $needle) {
        // search forward starting from end minus needle length characters
        return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle, $temp) !== false);
    }
    private function addEnable ($array, $key, $value) {
        if (array_key_exists($key, $array)) {
            array_push($array[$key], $value);
        } else {
            $array[$key] = [$value];
        }
        return $array;
    }
    private function throw_500($msg = null) {
        if ($msg === null) {
            $msg = $this->grav['language']->translate('PLUGIN_MICROPUB.MESSAGES.INTERNAL_SERVER_ERROR');
        }
        $this->grav['config']->set('plugins.micropub._msg', $msg);
        $route = $this->grav['uri']->route();
        $pages = $this->grav['pages'];
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . '/pages/500-internal-server-error.md'));
        $page->slug(basename($route));
        $pages->addPage($page, $route);        
    }
    private function throw_501($msg = null) {
        if ($msg === null) {
            $msg = $this->grav['language']->translate('PLUGIN_MICROPUB.MESSAGES.NOT_IMPLEMENTED');
        }
        $this->grav['config']->set('plugins.micropub._msg', $msg);
        $route = $this->grav['uri']->route();
        $pages = $this->grav['pages'];
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . '/pages/501-not-implemented.md'));
        $page->slug(basename($route));
        $pages->addPage($page, $route);        
    }
}
