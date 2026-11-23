<?php
/**
 * ThreadSafe Connector, the module for PrestaShop 9 (docs/PHASE15.md): lets a central ThreadSafe back up this shop by itself, with encrypted backups that
 * it starts and fetches on its own schedule. A thin wrapper like the WordPress plugin: everything that backs up lies in the single file
 * threadsafe-backup.php next to this one. It is written for old PHP versions on purpose: a host below PHP 8.4 gets a message, not a fatal error.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Threadsafeconnector extends Module
{
    const TOOL = 'threadsafe-backup.php';

    public function __construct()
    {
        $this->name = 'threadsafeconnector';
        $this->tab = 'administration';
        $this->version = '0.0.0-dev';
        $this->author = 'ThreadSafe';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '9.0.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = 'ThreadSafe Connector';
        $this->description = 'Lets a central ThreadSafe back up this shop by itself: encrypted backups, started and fetched by the schedule of your own server.';
    }

    /** The module can serve only on PHP 8.4 and when it lies where the door expects it: modules/threadsafeconnector/ of this shop. */
    private function problem()
    {
        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            return 'ThreadSafe Connector needs PHP 8.4 or newer; this shop runs PHP ' . PHP_VERSION . '.';
        }
        $mine = realpath(dirname(__FILE__));
        $modules = realpath(_PS_MODULE_DIR_);
        if ($mine === false || $modules === false || dirname($mine) !== $modules) {
            return 'The module is not in the modules directory of this shop; the agent finds its settings by the place of the module directory and cannot work here.';
        }

        return null;
    }

    /**
     * Loads the tool as a library, once. The tool unpacks itself next to its file, so the module directory must be writable for the web server.
     *
     * @throws RuntimeException the tool is not there or cannot unpack itself
     */
    private function loadTool()
    {
        if (class_exists('ThreadSafe\\Web\\AgentPlugin', false)) {
            return;
        }
        $directory = dirname(__FILE__);
        $file = $directory . '/' . self::TOOL;
        if (!is_file($file)) {
            throw new RuntimeException('The file ' . self::TOOL . ' is missing from the module directory. Install the module again from its ZIP.');
        }
        if (!is_dir($directory . '/.threadsafe-backup') && !is_writable($directory)) {
            throw new RuntimeException('The module directory ' . $directory . ' is not writable for the web server: the tool unpacks itself into a hidden directory there, once.');
        }
        define('THREADSAFE_LIBRARY', true);
        require_once $file;   // the stub returns to here after it has loaded the classes
        if (!class_exists('ThreadSafe\\Web\\AgentPlugin', false)) {
            throw new RuntimeException('The tool ' . self::TOOL . ' did not load its program.');
        }
    }

    /** @return \ThreadSafe\Web\AgentPlugin */
    private function agent()
    {
        $this->loadTool();
        $class = 'ThreadSafe\\Web\\AgentPlugin';

        return new $class(rtrim(_PS_ROOT_DIR_, '/\\'), rtrim(_PS_MODULE_DIR_, '/\\'), dirname(__FILE__), null, self::TOOL, 'prestashop9');
    }

    /** Runs a step of the module; what goes wrong is told to the person instead of ending the request. */
    private function attempt($step)
    {
        try {
            if (($problem = $this->problem()) !== null) {
                throw new RuntimeException($problem);
            }
            $step($this->agent());

            return true;
        } catch (Throwable $e) {
            $this->_errors[] = $e->getMessage();

            return false;
        }
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }
        // the workspace (the state and the archives) lies in var/, outside the module directory, so that an update of the module keeps it
        $var = rtrim(_PS_ROOT_DIR_, '/\\') . '/var';

        return $this->attempt(function ($agent) use ($var) {
            $agent->activate($var);
        });
    }

    /** Switched on again: the pointer says on; the workspace and the pairing are still there. */
    public function enable($force_all = false)
    {
        if (!parent::enable($force_all)) {
            return false;
        }
        $var = rtrim(_PS_ROOT_DIR_, '/\\') . '/var';

        return $this->attempt(function ($agent) use ($var) {
            $agent->activate($var);
        });
    }

    /** Disabled: the door answers like a file that is not there; the pairing and the archives stay. */
    public function disable($force_all = false)
    {
        $this->attempt(function ($agent) {
            $agent->deactivate();
        });

        return parent::disable($force_all);
    }

    public function uninstall()
    {
        // the pointer and the state go; the archives stay on the host (a box on the page will ask for more; docs/PHASE15.md 3.4)
        $this->attempt(function ($agent) {
            $agent->uninstall(false);
        });
        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            @unlink(rtrim(_PS_MODULE_DIR_, '/\\') . '/threadsafe-agent.php');
        }

        return parent::uninstall();
    }

    /** The page of the module in the back office (work package 3 makes it a form; this is the state). */
    public function getContent()
    {
        if (($problem = $this->problem()) !== null) {
            return '<div class="alert alert-danger">' . htmlspecialchars($problem, ENT_QUOTES, 'UTF-8') . '</div>';
        }
        try {
            $agent = $this->agent();
            $workspace = $agent->workspace();

            return '<div class="panel"><h3>ThreadSafe Connector</h3><p>' . ($agent->isOn() ? 'The agent is switched on.' : 'The agent is off.')
                . '</p><p>Workspace: <code>' . htmlspecialchars($workspace === null ? '-' : $workspace->directory(), ENT_QUOTES, 'UTF-8') . '</code></p></div>';
        } catch (Throwable $e) {
            return '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
        }
    }
}
