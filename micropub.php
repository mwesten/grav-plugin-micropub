<?php

/** 
 *  Micropub - A Micropub server implementation plugin for Grav CMS
 *  Copyright (C) 2018 Robert van Bregt
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 * 
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details. 
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

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
        $site = $base; // extra, will be modified further on
        $route = $this->grav['uri']->route();

        $config = $this->grav['config'];
        $pages = $this->grav['pages'];

        $token_endpoint = $config->get('plugins.micropub.token_endpoint');
        if ($token_endpoint == '') {
            $this->throw_500('Token endpoint not configured in micropub plugin.');
            return;
        }
        // TODO: Check for valid endpoint URL

        $post_template = $config->get('plugins.micropub.post_template');
        if ($post_template == '') {
            $this->throw_500('Post page template not configured in micropub plugin.');
            return;
        }

        $parent_route = $config->get('plugins.micropub.parent_route');
        $parent_page = $pages->find($parent_route, true);
        if ($parent_page === null) {
            $this->throw_500('Parent page not found: '.$parent_route);
            return;
        }

        $_HEADERS = array();
        foreach(getallheaders() as $name => $value) {
            $_HEADERS[$name] = $value;
        }
        if (!isset($_HEADERS['Authorization'])) {
            $this->throw_401('Missing "Authorization" header.');
            return;
        }
        $options = array(
            CURLOPT_URL => $token_endpoint,
            CURLOPT_HTTPGET => TRUE,
            CURLOPT_USERAGENT => $site,
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
            $this->throw_400('Missing "me" value in authentication token.');
            return;
        }
        if (!isset($values['scope'])) {
            $this->throw_400('Missing "scope" value in authentication token.');
            return;
        }
        if (substr($values['me'], -1) != '/') {
            $values['me'].= '/';
        }
        if (substr($site, -1) != '/') {
            $site.= '/';
        }
        if (strtolower($values['me']) != strtolower($site)) {
            $this->throw_403('Mismatching "me" value in authentication token.');
            return;
        }
        if ( !stristr($values['scope'], 'post') && !stristr($values['scope'], 'create') ){
            $this->throw_403('Missing "post" or "create" value in "scope".');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {

            if (!isset($_POST['h'])) {
                $this->throw_400('Missing "h" value.');
                return;
            }
            if (!isset($_POST['content'])) {
                $this->throw_400('Missing "content" value.');
                return;
            }

            /* Everything's cool. Do something with the $_POST variables
            (such as $_POST['content'], $_POST['category'], $_POST['location'], etc.)
            e.g. create a new entry, store it in a database, whatever. */

            // Adhere to Grav standards
            $_POST = $this->change_key($_POST, 'name', 'title');
            $_POST = $this->change_key($_POST, 'mp-slug', 'slug');
            $_POST = $this->change_key($_POST, 'category', 'tag');
            if (isset($_POST['tag'])) {
                $_POST['taxonomy'] = array('tag' => $_POST['tag']);
            }

            // Get content
            $content = $_POST["content"];

            // Get or set slug
            $slug_date_format = $config->get('plugins.micropub.slug_date_format') ?: 'Y-m-d-H-i';
            $default_slug = date($slug_date_format);
            // TODO: Make default slug configurable

            $slug = $_POST["slug"] ?? $default_slug;

            // Remove superfluous keys
            unset($_POST['h']);
            unset($_POST['access_token']);
            unset($_POST['content']);
            unset($_POST['slug']);
            unset($_POST['tag']);

            // Add timestamp to frontmatter
            $date_in_frontmatter = $config->get('plugins.micropub.date_in_frontmatter') ?: false;
            if ($date_in_frontmatter) {
                $_POST['date'] = date('r');
            }

            // TODO: determine 'default route'
            $route = $parent_route . DS . $slug;

            $page = $pages->find($route);
            if ($page) {
                $this->throw_500('Cannot create page. Page already exists. Please contact adminstrator. Try again or specify a new slug.');
                return;
            }

            $page = new Page;
            $page->slug($slug);
            $page->path($parent_page->path() . DS . $slug);
            $page->parent($parent_page);
            $page->name($post_template . '.md');
            $page->route($route);
            $page->content($content);
            $page->header($_POST);
            $page->save();

            // Now respond

            $return_url = $base . $route;

            header($_SERVER['SERVER_PROTOCOL'] . ' 201 Created');
            header('Location: '.$return_url);

            $page = new Page;
            $page->init(new \SplFileInfo(__DIR__ . '/pages/201-created.md'));
            $page->slug(basename($route));

            $pages = $this->grav['pages'];
            $pages->addPage($page, $route);   
        } else if ($_SERVER['REQUEST_METHOD'] === 'GET') {

            // Offer micropub clients full configuration
            if (isset($_GET['q']) && $_GET['q'] === 'config') {

                $destination = $config->get('plugins.micropub.destination');
                $mp_destination = [];
                foreach ($destination as $value) {
                    $mp_destination[] = [
                        'uid' => $value['uid'],
                        'name' => $value['name']
                    ];
                }
                $payload = [];
                $payload["destination"] = $mp_destination;
                $this->grav['config']->set('plugins.micropub._payload', json_encode($payload,JSON_PRETTY_PRINT));

                // Create and add page
                $route = $this->grav['uri']->route();
                $page = new Page;
                $page->init(new \SplFileInfo(__DIR__ . '/pages/config.md'));
                $page->slug(basename($route));
                $pages = $this->grav['pages'];
                $pages->addPage($page, $route);   
                        
            }
        }  
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
    function change_key( $array, $old_key, $new_key ) {

        if( ! array_key_exists( $old_key, $array ) )
            return $array;
    
        $keys = array_keys( $array );
        $keys[ array_search( $old_key, $keys ) ] = $new_key;
    
        return array_combine( $keys, $array );
    }
    private function throw_400($msg = null) {
        if ($msg === null) {
            $msg = $this->grav['language']->translate('PLUGIN_MICROPUB.MESSAGES.BAD_REQUEST');
        }
        $md_page = '/pages/400-bad-request.md';
        $this->throwHandler($md_page, $msg);
    }
    private function throw_401($msg = null) {
        if ($msg === null) {
            $msg = $this->grav['language']->translate('PLUGIN_MICROPUB.MESSAGES.UNAUTHORIZED');
        }
        $md_page = '/pages/401-unauthorized.md';
        $this->throwHandler($md_page, $msg);       
    }
    private function throw_403($msg = null) {
        if ($msg === null) {
            $msg = $this->grav['language']->translate('PLUGIN_MICROPUB.MESSAGES.FORBIDDEN');
        }
        $md_page = '/pages/403-forbidden.md';
        $this->throwHandler($md_page, $msg);
    }
    private function throw_500($msg = null) {
        if ($msg === null) {
            $msg = $this->grav['language']->translate('PLUGIN_MICROPUB.MESSAGES.INTERNAL_SERVER_ERROR');
        }
        $md_page = '/pages/500-internal-server-error.md';
        $this->throwHandler($md_page, $msg);       
    }
    private function throw_501() {

        $md_page = '/pages/501-not-implemented.md';
        // $this->throwHandler($md_page, $msg);       

        $route = $this->grav['uri']->route();
        $pages = $this->grav['pages'];
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . $md_page));
        $page->slug(basename($route));
        $pages->addPage($page, $route);        

    }
    private function throwHandler($md_page, $msg) {
        $this->grav['config']->set('plugins.micropub._msg', $msg);
        $route = $this->grav['uri']->route();
        $pages = $this->grav['pages'];
        $page = new Page;
        $page->init(new \SplFileInfo(__DIR__ . $md_page));
        $page->slug(basename($route));
        $pages->addPage($page, $route);        
    }
}
