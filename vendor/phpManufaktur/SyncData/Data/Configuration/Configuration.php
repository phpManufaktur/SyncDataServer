<?php

/**
 * SyncData
 *
 * @author Team phpManufaktur <team@phpmanufaktur.de>
 * @link https://addons.phpmanufaktur.de/SyncData
 * @copyright 2013 Ralf Hertsch <ralf.hertsch@phpmanufaktur.de>
 * @license MIT License (MIT) http://www.opensource.org/licenses/MIT
 */

namespace phpManufaktur\SyncData\Data\Configuration;

use phpManufaktur\SyncData\Control\Application;
use phpManufaktur\SyncData\Data\Configuration\ConfigurationException;
use phpManufaktur\SyncData\Data\CMS\Settings;
use phpManufaktur\SyncData\Control\JSON\JSONFormat;

/**
 * Create and read the configuration files for SyncData
 *
 * @author ralf.hertsch@phpmanufaktur.de
 *
 */
class Configuration
{

    protected $app = null;
    protected static $config_array = null;
    protected static $config_file = null;

    /**
     * Constructor
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        self::$config_file = SYNC_DATA_PATH.'/config/syncdata.json';
    }

    /**
     * Return the configuration array for Doctrine
     *
     * @return array configuration array
     */
    public function getConfiguration()
    {
        return self::$config_array;
    }

    /**
     * Get the configuration information from the parent CMS
     * and create syncdata.json
     *
     * @throws \Exception
     * @throws ConfigurationException
     */
    protected function getConfigurationFromCMS()
    {
        $cmsSettings = new Settings($this->app);
        $cms_settings = $cmsSettings->getSettings();
        if (file_exists(realpath(SYNC_DATA_PATH.'/../config.php'))) {
            include_once realpath(SYNC_DATA_PATH.'/../config.php');
        }
        else {
            throw new \Exception("Can't read the CMS configuration, SyncData stopped.");
        }

        self::$config_array = array(
            'CMS' => array(
                'CMS_SERVER_EMAIL' => $cms_settings['server_email'],
                'CMS_SERVER_NAME' => $cms_settings['wbmailer_default_sendername'],
                'CMS_TYPE' => (isset($cms_settings['lepton_version'])) ? 'LEPTON' : 'WebsiteBaker',
                'CMS_VERSION' => (isset($cms_settings['lepton_version'])) ? $cms_settings['lepton_version'] : $cms_settings['wb_version'],
                'CMS_MEDIA_DIRECTORY' => $cms_settings['media_directory'],
                'CMS_PAGES_DIRECTORY' => $cms_settings['pages_directory'],
                'CMS_URL' => WB_URL,
                'CMS_PATH' => WB_PATH
            ),
            'monolog' => array(
                'email' => array(
                    'active' => true,
                    'level' => 400,
                    'to' => $cms_settings['server_email'],
                    'subject' => 'SyncData Alert'
                )
            ),
            'general' => array(
                'memory_limit' => '512M',
                'max_execution_time' => '300'
            ),
            'backup' => array(
                'settings' => array(
                    'replace_table_prefix' => true,
                    'add_if_not_exists' => true,
                    'replace_cms_url' => true
                ),
                'files' => array(
                    'ignore' => array(
                        '.buildpath',
                        '.project'
                    )
                ),
                'directories' => array(
                    'ignore' => array(
                        'directory' => array(
                            'temp',
                            'syncdata',
                            'kit2'
                        ),
                        'subdirectory' => array(
                            '.git'
                        )
                    )
                ),
                'tables' => array(
                    'ignore' => array(
                        'syncdata_backup_master',
                        'syncdata_backup_tables',
                        'syncdata_backup_files',
                        'syncdata_synchronize_tables',
                        'syncdata_synchronize_master',
                        'syncdata_synchronize_files',
                        'syncdata_synchronize_archives',
                        'syncdata_synchronize_client'
                    )
                )
            ),
            'restore' => array(
                'settings' => array(
                    'replace_table_prefix' => true,
                    'replace_cms_url' => true,
                    'ignore_cms_config' => true
                ),
                'files' => array(
                    'ignore' => array(
                        '.buildpath',
                        '.project'
                    )
                ),
                'directories' => array(
                    'ignore' => array(
                        'directory' => array(
                            'temp',
                            'syncdata',
                            'kit2'
                        ),
                        'subdirectory' => array(
                            '.git'
                        )
                    )
                ),
                'tables' => array(
                    'ignore' => array(
                        'syncdata_backup_master',
                        'syncdata_backup_tables',
                        'syncdata_backup_files',
                        'syncdata_synchronize_tables',
                        'syncdata_synchronize_master',
                        'syncdata_synchronize_files',
                        'syncdata_synchronize_archives',
                        'syncdata_synchronize_client'
                    )
                )
            )
        );
        // encode a formatted JSON file
        $jsonFormat = new JSONFormat();
        $json = $jsonFormat->format(self::$config_array);
        if (!@file_put_contents(self::$config_file, $json)) {
            throw new ConfigurationException("Can't write the configuration file for SyncData!");
        }
        $this->app['monolog']->addInfo("Create configuration file syncdata.json for SyncData");
    }

    /**
     * Initialize the Doctrine configuration settings
     *
     * @throws ConfigurationException
     */
    public function initConfiguration()
    {
        if (!file_exists(self::$config_file)) {
            // get the configuration directly from CMS
            $this->getConfigurationFromCMS();
        }
        elseif ((false === (self::$config_array = json_decode(@file_get_contents(self::$config_file), true))) || !is_array(self::$config_array)) {
            throw new ConfigurationException("Can't read the SyncData configuration file!");
        }

        // set constants for important config values
        define('CMS_URL', self::$config_array['CMS']['CMS_URL']);
        define('CMS_PATH', self::$config_array['CMS']['CMS_PATH']);
        define('CMS_TYPE', self::$config_array['CMS']['CMS_TYPE']);
        define('CMS_VERSION', self::$config_array['CMS']['CMS_VERSION']);
        define('CMS_MEDIA_DIRECTORY', self::$config_array['CMS']['CMS_MEDIA_DIRECTORY']);
        define('CMS_PAGES_DIRECTORY', self::$config_array['CMS']['CMS_PAGES_DIRECTORY']);
        define('CMS_SERVER_EMAIL', self::$config_array['CMS']['CMS_SERVER_EMAIL']);
        define('CMS_SERVER_NAME', self::$config_array['CMS']['CMS_SERVER_NAME']);
        define('TEMP_PATH', SYNC_DATA_PATH.'/temp');

        if (false === ini_set('memory_limit', self::$config_array['general']['memory_limit'])) {
            throw new ConfigurationException(sprintf("Can't set the memory limit to %s", self::$config_array['general']['memory_limit']));
        }
        else {
            $this->app['monolog']->addInfo(sprintf("Set the memory limit to %s", self::$config_array['general']['memory_limit']));
        }
        if (false === ini_set('max_execution_time', self::$config_array['general']['max_execution_time'])) {
            throw new ConfigurationException(sprintf("Can't set the max_execution_time to %s seconds", self::$config_array['general']['max_execution_time']));
        }
        else {
            $this->app['monolog']->addInfo(sprintf("Set the max_execution_time to %s seconds", self::$config_array['general']['max_execution_time']));
        }

        $cfg = $this->getConfiguration();
        $this->app['config'] = $this->app->share(function() use ($cfg) {
            return $cfg;
        });
    }


}