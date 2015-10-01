<?php
/**
 * Login/Logout logging plugin; action component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once(DOKU_PLUGIN . 'action.php');

class action_plugin_loglog extends DokuWiki_Action_Plugin {

    var $islogin = false;
    protected $logFile = 'loglog.log'; // stored in cache directory

    /**
     * register the eventhandlers
     */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS',
            'BEFORE',
            $this,
            'handle_before',
            array()
        );

        // allow other plugins to emit logging events
        $controller->register_hook(
            'PLUGIN_LOGLOG_LOG',
            'BEFORE',
            $this,
            'handle_custom',
            array()
        );

        // autologout plugin
        $controller->register_hook(
            'ACTION_AUTH_AUTOLOGOUT',
            'BEFORE',
            $this,
            'handle_autologout',
            array()
        );
    }

    /**
     * Log an action
     *
     * @param $msg
     * @param null|string $user
     */
    protected function _log($msg, $user = null) {
        global $conf;
        global $INPUT;

        if(is_null($user)) $user = $INPUT->server->str('REMOTE_USER');
        if(!$user) $user = $INPUT->str('u'); // $_REQUEST['u']
        if(!$user) return;

        $t = time();
        $log = implode("\t", array(
            $t,
            strftime($conf['dformat'], $t),
            $_SERVER['REMOTE_ADDR'],
            $user,
            $msg
        ))."\n";
        io_saveFile($conf['cachedir'].'/'.$this->logFile, $log, true);
    }

    /**
     * Handle custom logging events
     *
     * @param Doku_Event $event
     * @param mixed $param data passed to the event handler
     */
    public function handle_custom(Doku_Event $event, $param) {
        if(isset($event->data['message'])) {
            $log = $event->data['message'];
        } else {
            return;
        }
        if(isset($event->data['user'])) {
            $user = $event->data['user'];
        } else {
            $user = null;
        }

        $this->_log($log, $user);
    }

    /**
     * Handle autologoffs by the autologout plugin
     *
     * @param Doku_Event $event
     * @param mixed $param data passed to the event handler
     */
    public function handle_autologout(Doku_Event $event, $param) {
        $this->_log('has been automatically logged off');
    }

    /**
     * catch standard logins/logouts
     *
     * @param Doku_Event $event
     * @param mixed $param data passed to the event handler
     */
    public function handle_before(Doku_Event $event, $param) {
        $act = act_clean($event->data);
        switch ($act) {
            case 'logout':
                $this->_log('logged off');
                break;
            case 'login':
                if (!empty($_SERVER['REMOTE_USER'])) {
                    if (isset($_REQUEST['r'])) {
                        $this->_log('logged in permanently');
                    } else {
                        $this->_log('logged in temporarily');
                    }
                } else {
                    // exclude silent HTTP credential fails from logging
                    if ($_REQUEST['u'] && $_REQUEST['http_credentials']) {
                        $this->_log('failed login attempt');
                    }
                }
                break;
        }
    }
}

