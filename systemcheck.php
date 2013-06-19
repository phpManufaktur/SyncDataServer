<?php

/**
 * SyncData
 *
 * @author Team phpManufaktur <team@phpmanufaktur.de>
 * @link https://addons.phpmanufaktur.de/SyncData
 * @copyright 2013 Ralf Hertsch <ralf.hertsch@phpmanufaktur.de>
 * @license MIT License (MIT) http://www.opensource.org/licenses/MIT
 */

class SystemCheck
{
    protected static $detected_PHP_VERSION = null;
    protected static $required_PHP_VERSION = null;

    protected static $CMS_TYPE = null;
    protected static $CMS_VERSION = null;

    protected static $detected_MYSQL_VERSION = null;
    protected static $required_MYSQL_VERSION = null;

    protected static $detected_CURL = null;
    protected static $required_CURL = null;

    protected static $detected_ZIPArchive = null;
    protected static $required_ZIPArchive = null;

    protected static $cms_config_path = null;

    /**
     * Constructor
     */
    public function __construct()
    {
        self::setCMSpath();
    }

    protected static function setCMSpath()
    {
        //  we assume that the SystemCheck is placed at / or at /syncdata
        if (file_exists(realpath(dirname(__FILE__).'/../config.php'))) {
            self::$cms_config_path = realpath(dirname(__FILE__).'/../config.php');
        }
        elseif (file_exists(realpath(dirname(__FILE__).'/config.php'))) {
            self::$cms_config_path = realpath(dirname(__FILE__).'/config.php');
        }
        else {
            self::$cms_config_path = null;
        }
    }

    /**
     * Set the required PHP version
     *
     * @param string $php_version i.e. '5.3.2'
     */
    public function setRequriredPHPVersion($php_version)
    {
        self::$required_PHP_VERSION = $php_version;
    }

    /**
     * Set the required MySQL version
     *
     * @param string $mysql_version i.e. '5.0.0'
     */
    public function setRequiredMySQLVersion($mysql_version)
    {
        self::$required_MYSQL_VERSION = $mysql_version;
    }

    /**
     * Determine wether cURL is needed or not
     *
     * @param boolean $required
     */
    public function setRequiredCURL($required)
    {
        self::$required_CURL = (bool) $required;
    }

    /**
     * Determine wether the ZIPArchive is needed or not
     *
     * @param boolean $required
     */
    public function setRequriredZIPArchive($required)
    {
        self::$required_ZIPArchive = (bool) $required;
    }

    /**
     * Return the operating system: WINDOWS or LINUX
     *
     * @return string
     */
    protected function getOperatingSystem()
    {
        return (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'WINDOWS' : 'LINUX';
    }

    /**
     * Try to get informations about the parent CMS
     *
     * @return Ambigous <string, multitype:NULL >
     */
    protected function getCMSinformation()
    {
        $result = array(
            'Type' => '- no information available -',
            'Version' => '- no information available -'
            );
        if (file_exists(self::$cms_config_path)) {
            include_once self::$cms_config_path;
            if (defined('DB_HOST')) {
                // establish MySQL connection
                if ((false !== ($db_handle = @mysql_connect(DB_HOST, DB_USERNAME, DB_PASSWORD))) &&
                    @mysql_select_db(DB_NAME, $db_handle)) {
                    // select the CMS settings
                    $SQL = "SELECT * FROM `".TABLE_PREFIX."settings`";
                    if (false !== ($query = @mysql_query($SQL))) {
                        // reset CMS type and version
                        self::$CMS_TYPE = null;
                        self::$CMS_VERSION = null;
                        while (false !== ($row = mysql_fetch_assoc($query))) {
                            if ($row['name'] == 'wb_version') {
                                self::$CMS_TYPE = 'WebsiteBaker';
                                self::$CMS_VERSION = $row['value'];
                                break;
                            }
                            if ($row['name'] == 'lepton_version') {
                                self::$CMS_TYPE = 'LEPTON';
                                self::$CMS_VERSION = $row['value'];
                                break;
                            }
                        }
                        @mysql_free_result($query);
                        if (!is_null(self::$CMS_TYPE)) {
                            $result = array(
                                'Type' => self::$CMS_TYPE,
                                'Version' => self::$CMS_VERSION
                            );
                        }
                    }
                    // close the db handle
                    @mysql_close($db_handle);
                }
            }
        }
        return $result;
    }

    /**
     * Return an array with information about MySQL
     *
     * @return multitype:mixed NULL
     */
    protected function getMySQLinformation()
    {
        self::$detected_MYSQL_VERSION = mysql_get_client_info();
        if (is_null(self::$required_MYSQL_VERSION)) {
            self::$required_MYSQL_VERSION = self::$detected_MYSQL_VERSION;
        }
        return array(
            'installed' => self::$detected_MYSQL_VERSION,
            'required' => self::$required_MYSQL_VERSION,
            'checked' => (int) version_compare(self::$detected_MYSQL_VERSION, self::$required_MYSQL_VERSION, '>='),
            'css' => version_compare(self::$detected_MYSQL_VERSION, self::$required_MYSQL_VERSION, '>=') ? 'checked' : 'fail'
            );
    }

    /**
     * Return an array with information about PHP
     *
     * @return multitype:number NULL
     */
    protected function getPHPinformation()
    {
        if (is_null(self::$required_PHP_VERSION)) {
            self::$required_PHP_VERSION = PHP_VERSION;
        }
        self::$detected_PHP_VERSION = PHP_VERSION;
        return array(
            'installed' => self::$detected_PHP_VERSION,
            'required' => self::$required_PHP_VERSION,
            'checked' => (int) version_compare(self::$detected_PHP_VERSION, self::$required_PHP_VERSION, '>='),
            'css' => version_compare(self::$detected_PHP_VERSION, self::$required_PHP_VERSION, '>=') ? 'checked' : 'fail'
        );
    }

    /**
     * Return an array with information about cURL
     *
     * @return multitype:number
     */
    protected function isCURLinstalled()
    {
        self::$detected_CURL = function_exists('curl_init');
        if (is_null(self::$required_CURL)) {
            self::$required_CURL = self::$detected_CURL;
        }
        return array(
            'installed' => (int) self::$detected_CURL,
            'required' => (int) self::$required_CURL,
            'checked' => (self::$required_CURL && !self::$detected_CURL) ? 0 : 1,
            'css' => (self::$required_CURL && !self::$detected_CURL) ? 'fail' : 'checked'
            );
    }

    /**
     * Return an array with information about the ZipArchive
     *
     * @return multitype:number
     */
    protected function isZIParchiveInstalled()
    {
        self::$detected_ZIPArchive = class_exists('ZipArchive');
        if (is_null(self::$required_ZIPArchive)) {
            self::$required_ZIPArchive = self::$detected_ZIPArchive;
        }
        return array(
            'installed' => (int) self::$detected_ZIPArchive,
            'required' => (int) self::$required_ZIPArchive,
            'checked' => (self::$required_ZIPArchive && !self::$detected_ZIPArchive) ? 0 : 1,
            'css' => (self::$required_ZIPArchive && !self::$detected_ZIPArchive) ? 'fail' : 'checked'
        );
    }

    protected function getSyncDataInformation()
    {
        // get SyncData path
        if (file_exists(dirname(__FILE__).'/VERSION')) {
            $syncdata_path = realpath(dirname(__FILE__));
        }
        elseif (file_exists(dirname(__FILE__).'/syncdata/VERSION')) {
            $syncdata_path = realpath(dirname(__FILE__).'/syncdata');
        }
        else {
            $syncdata_path = null;
        }
        // get SyncData Version
        if (file_exists($syncdata_path.'/VERSION')) {
            $syncdata_version = file_get_contents($syncdata_path.'/VERSION');
        }
        else {
            $syncdata_version = '- no information available -';
        }
        // get Doctrine version
        if (file_exists($syncdata_path.'/vendor/Doctrine/DBAL/VERSION')) {
            $doctrine_version = file_get_contents($syncdata_path.'/vendor/Doctrine/DBAL/VERSION');
        }
        else {
            $doctrine_version = '- no information available -';
        }
        // get Swiftmailer version
        if (file_exists($syncdata_path.'/vendor/SwiftMailer/VERSION')) {
            $swift_version = file_get_contents($syncdata_path.'/vendor/SwiftMailer/VERSION');
        }
        else {
            $swift_version = '- no information available -';
        }
        return array(
            'SyncData' => $syncdata_version,
            'Doctrine' => $doctrine_version,
            'SwiftMailer' => $swift_version
        );
    }

    protected static function promptResult($result)
    {
        $curl_required = $result['cURL']['required'] ? 'Yes' : 'No';
        $curl_installed = $result['cURL']['installed'] ? 'Yes' : 'No';
        $zip_required = $result['ZIPArchive']['required'] ? 'Yes' : 'No';
        $zip_installed = $result['ZIPArchive']['installed'] ? 'Yes' : 'No';
        echo <<<EOD
<!DOCTYPE html>
<html lang=en>
    <head>
        <meta charset=utf-8>
        <title>SystemCheck for SyncData</title>
        <style type="text/css" media="screen">
            body {
                margin: 0;
                padding: 0 0 0 50px;
                font-family: "Lucida Sans Unicode", "Lucida Grande", sans-serif;
                font-size: 13px;
                color: #363636;
                background-color: #fff;
            }

            a {
                text-decoration: none;
            }
            a:link,
            a:visited {
                color: #da251d;
                background-color: transparent;
                text-decoration: none;
            }
            a:hover,
            a:active {
                color: #da251d;
                background-color: transparent;
                text-decoration: underline;
            }
            fieldset {
                clear: both;
                margin: 10px 0;
                padding: 10px;
                width: 600px;
            }
            legend {
                font-size: 11px;
            }
            .label {
                clear: both;
                float: left;
                width: 150px;
                margin: 0;
                padding: 0;
            }
            .value {
                float: left;
                width: 300px;
                margin: 0;
                padding: 0;
            }
            .checked {
                color: #006400;
                background-color: transparent;
                font-weight: normal;
            }
            .fail {
                color: #da251d;
                background-color: transparent;
                font-weight: bold;
            }
        </style>
    </head>
    <body>
        <h1>SyncData SystemCheck</h2>
        <p>&copy 2013 <a href="https://phpmanufaktur.de">phpManufaktur</a> by <a href="mailto:ralf.hertsch@phpmanufaktur.de">Ralf Hertsch</a></p>
        <fieldset>
            <legend>CMS</legend>
            <div class="label">CMS Type</div>
            <div class="value">{$result['CMS']['Type']}</div>
            <div class="label">CMS Version</div>
            <div class="value">{$result['CMS']['Version']}</div>
        </fieldset>
        <fieldset>
            <legend>SyncData</legend>
            <div class="label">SyncData</div>
            <div class="value">{$result['SyncData']['SyncData']}</div>
            <div class="label">Doctrine</div>
            <div class="value">{$result['SyncData']['Doctrine']}</div>
            <div class="label">SwiftMailer</div>
            <div class="value">{$result['SyncData']['SwiftMailer']}</div>
        </fieldset>
        <fieldset>
            <legend>Operating System</legend>
            <div class="label">Operating System</div>
            <div class="value">{$result['OPERATING_SYSTEM']}</div>
        </fieldset>
        <fieldset>
            <legend>PHP Version</legend>
            <div class="label">Required</div>
            <div class="value">{$result['PHP_VERSION']['required']}</div>
            <div class="label">Installed</div>
            <div class="value {$result['PHP_VERSION']['css']}">{$result['PHP_VERSION']['installed']}</div>
        </fieldset>
        <fieldset>
            <legend>MySQL Version</legend>
            <div class="label">Required</div>
            <div class="value">{$result['MySQL']['required']}</div>
            <div class="label">Installed</div>
            <div class="value {$result['MySQL']['css']}">{$result['MySQL']['installed']}</div>
        </fieldset>
        <fieldset>
            <legend>cURL</legend>
            <div class="label">Required</div>
            <div class="value">{$curl_required}</div>
            <div class="label">Installed</div>
            <div class="value {$result['cURL']['css']}">{$curl_installed}</div>
        </fieldset>
        <fieldset>
            <legend>ZipArchive</legend>
            <div class="label">Required</div>
            <div class="value">{$zip_required}</div>
            <div class="label">Installed</div>
            <div class="value {$result['ZIPArchive']['css']}">{$zip_installed}</div>
        </fieldset>
        <div class="info">
            <p>To get a <a href="?action=phpinfo">detailed PHP information</a> use the parameter <code>systemcheck.php?action=phpinfo</code>.</p>
            <p>Visit the <a href="https://addons.phpmanufaktur.de/syncdata">SyncData Projekt Home</a> and the <a href="https://github.com/phpManufaktur/SyncData2/wiki">SyncData WIKI</a> to get more information.</p>
            <p>Please feel free to contact the <a href="https://support.phpmanufaktur.de">phpManufaktur Support Group</a> to receive assistance.</p>

        </div>
    </body>
</html>
EOD;
    }

    public function exec($prompt_result=true)
    {
        $result = array(
            'CMS' => $this->getCMSinformation(),
            'OPERATING_SYSTEM' => $this->getOperatingSystem(),
            'PHP_VERSION' => $this->getPHPinformation(),
            'MySQL' => $this->getMySQLinformation(),
            'cURL' => $this->isCURLinstalled(),
            'ZIPArchive' => $this->isZIParchiveInstalled(),
            'SyncData' => $this->getSyncDataInformation()
        );
        return ($prompt_result) ? self::promptResult($result) : $result;
    }

}


if (isset($_GET['action']) && ($_GET['action'] === strtolower('phpinfo'))) {
    // show phpinfo()
    phpinfo();
    exit();
}

// check the system for SyncData
$info = new SystemCheck();
$info->setRequriredPHPVersion('5.3.2');
$info->setRequiredMySQLVersion('5.0.0');
$info->setRequiredCURL(false);
$info->setRequriredZIPArchive(true);
$info->exec();