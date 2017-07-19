<?php

// This is a PLUGIN TEMPLATE for Textpattern CMS.

// Copy this file to a new name like abc_myplugin.php.  Edit the code, then
// run this file at the command line to produce a plugin for distribution:
// $ php abc_myplugin.php > abc_myplugin-0.1.txt

// Plugin name is optional.  If unset, it will be extracted from the current
// file name. Plugin names should start with a three letter prefix which is
// unique and reserved for each plugin author ("abc" is just an example).
// Uncomment and edit this line to override:
$plugin['name'] = 'ext_mailchimp';

// Allow raw HTML help, as opposed to Textile.
// 0 = Plugin help is in Textile format, no raw HTML allowed (default).
// 1 = Plugin help is in raw HTML.  Not recommended.
# $plugin['allow_html_help'] = 1;

$plugin['version'] = '1.0.0';
$plugin['author'] = 'Stef Dawson';
$plugin['author_uri'] = 'https://stefdawson.com';
$plugin['description'] = 'Textpattern CMS Mailchimp module for the com_connect email plugin';

// Plugin load order:
// The default value of 5 would fit most plugins, while for instance comment
// spam evaluators or URL redirectors would probably want to run earlier
// (1...4) to prepare the environment for everything else that follows.
// Values 6...9 should be considered for plugins which would work late.
// This order is user-overrideable.
$plugin['order'] = '5';

// Plugin 'type' defines where the plugin is loaded
// 0 = public              : only on the public side of the website (default)
// 1 = public+admin        : on both the public and admin side
// 2 = library             : only when include_plugin() or require_plugin() is called
// 3 = admin               : only on the admin side (no AJAX)
// 4 = admin+ajax          : only on the admin side (AJAX supported)
// 5 = public+admin+ajax   : on both the public and admin side (AJAX supported)
$plugin['type'] = '0';

// Plugin "flags" signal the presence of optional capabilities to the core plugin loader.
// Use an appropriately OR-ed combination of these flags.
// The four high-order bits 0xf000 are available for this plugin's private use
if (!defined('PLUGIN_HAS_PREFS')) define('PLUGIN_HAS_PREFS', 0x0001); // This plugin wants to receive "plugin_prefs.{$plugin['name']}" events
if (!defined('PLUGIN_LIFECYCLE_NOTIFY')) define('PLUGIN_LIFECYCLE_NOTIFY', 0x0002); // This plugin wants to receive "plugin_lifecycle.{$plugin['name']}" events

$plugin['flags'] = '0';

// Plugin 'textpack' is optional. It provides i18n strings to be used in conjunction with gTxt().
// Syntax:
// ## arbitrary comment
// #@event
// #@language ISO-LANGUAGE-CODE
// abc_string_name => Localized String

/** Uncomment me, if you need a textpack
$plugin['textpack'] = <<< EOT
#@admin
#@language en-gb
abc_sample_string => Sample String
abc_one_more => One more
#@language de-de
abc_sample_string => Beispieltext
abc_one_more => Noch einer
EOT;
**/
// End of textpack

if (!defined('txpinterface'))
        @include_once('zem_tpl.php');

# --- BEGIN PLUGIN CODE ---
if (txpinterface === 'public') {
    register_callback('ext_mailchimp', 'comconnect.deliver');
}

/**
 * Callback hook for com_connect to handle delivery.
 * 
 * @param  string $evt     Textpattern event
 * @param  string $stp     Textpattern step (action)
 * @param  array  $payload Delivery content, passed in from com_connect
 */
function ext_mailchimp($evt, $stp, &$payload)
{
    // If using copysender, it's the 2nd time the plugin has been called so no need
    // to do anything. com_connect will continue as normal and mail out the email.
    if ($stp === 'copysender') {
        return '';
    }

    $ccConfig = array('return_action' => 'skip');
    $chimpConfig = array('email' => array('email' => null), 'id' => null);
    $chimpVars = array();
    $apiKey = null;
    $action = 'lists/subscribe';

    foreach ($payload['fields'] as $key => $value) {
        if ($key === 'mailchimp_api_key') {
           $apiKey = $value;
        } elseif ($key === 'mailchimp_action') {
           $action = $value;
        } elseif (strpos($key, 'mailchimp_') === 0) {
            $rawKey = substr($key, 10); // Strip off mailchimp_ prefix

            // Optional parameter type can be specified at end of key.
            // Default to string.
            // Todo: arrays?
            $parts = explode(':', $rawKey);
            $param = $parts[0];
            $type = (isset($parts[1])) ? $parts[1] : 's';

            switch ($type) {
                case 'b':
                    $chimpConfig[$param] = (bool)$value;
                    break;
                case 'i':
                    $chimpConfig[$param] = (int)$value;
                    break;
                case 's':
                default:
                    $chimpConfig[$param] = $value;
                    break;
            }
        } elseif (strpos($key, 'ext_') === 0) {
            $param = substr($key, 4); // Strip off ext_ prefix
            $ccConfig[$param] = $value;
        } elseif ($key === 'list_id') {
            $chimpConfig['id'] = $value;
        } elseif ($key === 'email') {
            // Don't ask. It's their spec!
            $chimpConfig['email']['email'] = $value;
        } else {
            $chimpVars[$key] = $value;
        }
    }

    if ($apiKey) {
        $chimpConfig['merge_vars'] = $chimpVars;

        $mc = new ext_Mailchimp($apiKey);
        $result = $mc->call($action, $chimpConfig);

        // Todo: what to do with the result?

        if ($ccConfig['return_action']) {
            return 'comconnect.' . $ccConfig['return_action'];
        }
    } else {
        // Not for us.
        return '';
    }
}

/**
 * Super-simple, minimum abstraction MailChimp API v2 wrapper
 * 
 * Uses curl if available, falls back to file_get_contents and HTTP stream.
 * This probably has more comments than code.
 *
 * Contributors:
 * Michael Minor <me@pixelbacon.com>
 * Lorna Jane Mitchell, github.com/lornajane
 * 
 * @author Drew McLellan <drew.mclellan@gmail.com> 
 * @version 1.1.1
 */
class ext_MailChimp
{
    private $api_key;
    private $api_endpoint = 'https://<dc>.api.mailchimp.com/2.0';
    private $verify_ssl   = false;

    /**
     * Create a new instance
     * @param string $api_key Your MailChimp API key
     */
    function __construct($api_key)
    {
        $this->api_key = $api_key;
        list(, $datacentre) = explode('-', $this->api_key);
        $this->api_endpoint = str_replace('<dc>', $datacentre, $this->api_endpoint);
    }

    /**
     * Call an API method. Every request needs the API key, so that is added automatically -- you don't need to pass it in.
     * @param  string $method The API method to call, e.g. 'lists/list'
     * @param  array  $args   An array of arguments to pass to the method. Will be json-encoded for you.
     * @return array          Associative array of json decoded API response.
     */
    public function call($method, $args=array(), $timeout = 10)
    {
        return $this->makeRequest($method, $args, $timeout);
    }

    /**
     * Performs the underlying HTTP request. Not very exciting
     * @param  string $method The API method to be called
     * @param  array  $args   Assoc array of parameters to be passed
     * @return array          Assoc array of decoded result
     */
    private function makeRequest($method, $args=array(), $timeout = 10)
    {      
        $args['apikey'] = $this->api_key;

        $url = $this->api_endpoint.'/'.$method.'.json';

        if (function_exists('curl_init') && function_exists('curl_setopt')){
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_USERAGENT, 'PHP-MCAPI/2.0');       
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verify_ssl);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($args));
            $result = curl_exec($ch);
            curl_close($ch);
        } else {
            $json_data = json_encode($args);
            $result    = file_get_contents($url, null, stream_context_create(array(
                'http' => array(
                    'protocol_version' => 1.1,
                    'user_agent'       => 'PHP-MCAPI/2.0',
                    'method'           => 'POST',
                    'header'           => "Content-type: application/json\r\n".
                                          "Connection: close\r\n" .
                                          "Content-length: " . strlen($json_data) . "\r\n",
                    'content'          => $json_data,
                ),
            )));
        }

        return $result ? json_decode($result, true) : false;
    }
}

# --- END PLUGIN CODE ---
if (0) {
?>
<!--
# --- BEGIN PLUGIN HELP ---
h1. ext_mailchimp

A Textpattern CMS plugin module for the com_connect mailer, permitting people to subscribe to mailing lists you have set up in MailChimp.

h2. Installation

IMPORTANT: requires com_connect v4.5.0.0+ to be installed and active.

Download the plugin, visit your _Admin->Plugins_ panel, paste the code in the box, upload, install, activate. Done.

h2. Usage

The plugin silently sits waiting for com_connect submissions. If any submission has the correct combination of mailchimp fields, the plugin wakes up, performs the designated action (which by default is subscribe to list) and then tells com_connect it's done. In its default configuration, the send-an-email portion of the process is bypassed. This can be overridden using @ext_return_action@: see below.

The way you set up the plugin is by writing a standard @<txp:com_connect>@ form, but with specific elements to configure and control the process. Use @<txp:com_connect_secret />@ to tell the plugin how to interact with MailChimp. Any other, regular fields will simply be passed to MailChimp as "merge tags":http://kb.mailchimp.com/merge-tags/all-the-merge-tags-cheatsheet.

Here's a simple example, which collects an email address, first name, and last name for subscription purposes:

bc. <p>Sign up for our newsletter!</p>
<txp:variable name="chimpApiKey">abc123abc123abc123abc123abc123-us1</txp:variable>
<txp:com_connect to="not_important@example.org">
   <txp:com_connect_secret label="mailchimp_api_key" value='<txp:variable name="chimpApiKey" />' />
   <txp:com_connect_secret label="mailchimp_double_optin:b" value="false" />
   <txp:com_connect_secret label="list_id" value="mylist1234" />
   <txp:com_connect_email name="email" />
   <txp:com_connect_text name="FNAME" label="First name" />
   <txp:com_connect_text name="LNAME" label="Last name" />
   <txp:com_connect_submit label="Subscribe" />
</txp:com_connect>

Pretty simple. Any secret values that begin with @mailchimp_@ are treated as configuration parameters. These can be:

* *mailchimp_api_key* The trigger for the plugin to wake up. Obtain one of these when you sign up for MailChimp. The example above stores it in a variable so you can use it on other pages, potentially for other lists or actions.
* *mailchimp_action* The API action to perform on MailChimp. Defaults to @lists/subscribe@. Another useful one might be @lists/unsubscribe@.
* *mailchimp_????* Any other parameters required for the action. Examples include @double_optin@, @send_welcome@, @update_existing@, @email_type@, @send_goodbye@. See the "API docs":https://apidocs.mailchimp.com/api/2.0/ for a complete list. The above example sets @double_optin@ to @false@.

When sending extra parameters, you tell the plugin each parameter's data type by adding a colon and a single letter to denote its type. Options are:

* @:b@ Boolean.
* @:i@ Integer.
* @:s@ String (default).

Other variables (without any prefix) are treated as regular parameters (strings) to send to MailChimp for the purposes of storage. They are all passed as Merge Tags, with the exception of two special ones:

* *list_id* The subscription list ID of the list to which you wish people to be able to subscribe.
* *email* The email address of the person who is subscribing.

There are also com_connect configuration parameters, which begin with @ext_@. At the moment there's only one:

* *return_action* Determines what happens when the MailChimp process completes:
** *skip* The default, which causes the regular com_connect mail process to be bypassed, while still returning the regular 'success' message.
** *fail* Tell com_connect to return a failure code.
** *success* To allow com_connect to continue sending an email to the recipient. Note that the recipient in this case is the @to@ attribute of the @<txp:com_connect>@ tag (probably you!), not the person signing up.

If you elect to use @copysender@ in com_connect, the signup request will still only be performed once, and com_connect will send out an email to the subscriber, irrespective of the setting of @return_action@.

h2. Author / Credits

Written by Stef 'Bloke' Dawson, but the plugin could not have existed without the awesomeness of Drew McLellan who wrote the microscopic MailChimp API broomstick upon which this plugin rides. All the kudos go to him.

h2. Changelog

* 10 Dec 2014 | v0.10 | Initial release
# --- END PLUGIN HELP ---
-->
<?php
}
?>