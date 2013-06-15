<?php

/**
 * SyncData
 *
 * @author Team phpManufaktur <team@phpmanufaktur.de>
 * @link https://addons.phpmanufaktur.de/SyncData
 * @copyright 2013 Ralf Hertsch <ralf.hertsch@phpmanufaktur.de>
 * @license MIT License (MIT) http://www.opensource.org/licenses/MIT
 */

namespace phpManufaktur\SyncData\Control;

use phpManufaktur\SyncData\Control\Zip\unZip;
use phpManufaktur\SyncData\Data\General;
use phpManufaktur\SyncData\Data\SynchronizeClient;

/**
 * Class to restore an existing backup archive to the CMS
 *
 * @author ralf.hertsch@phpmanufaktur.de
 *
 */
class Restore
{

    protected $app = null;

    /**
     * Constructor
     *
     * @param Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * Restore Tables from the given source path.
     * If $create_backup is true, will create a fresh backup before restoring,
     * this enable a rollback if the restore fails.
     *
     * @param string $source_path
     * @param boolean $create_backup
     * @throws \Exception
     */
    protected function restoreTables($source_path, $create_backup=true)
    {
        if ($create_backup) {
            // first thing: make a backup of the current database!
            try {
                $backup = new Backup($this->app);
                $backup->backupDatabase(null);
            } catch (\Exception $e) {
                // backup failed
                throw new \Exception($e);
            }
        }

        $tables = array();
        $directory_handle = dir($source_path);
        while (false !== ($file = $directory_handle->read())) {
            // get all files into an array
            if (($file == '.') || ($file == '..')) continue;
            $path = $this->app['utils']->sanitizePath("$source_path/$file");
            if (is_dir($path)) continue;
            $name = substr($file, 0, strrpos($file, '.'));
            if (!in_array($name, $tables)) {
                $tables[] = $name;
            }
        }
        // sort the array ascending
        sort($tables);

        $general = new General($this->app);

        // got the tables to ignore
        $ignore_tables = $this->app['config']['restore']['tables']['ignore'];

        try {
            // restore the tables
            foreach ($tables as $table) {
                //if ($table != 'kit2_propangas24_zip_list') continue;

                if (in_array($table, $ignore_tables)) {
                    $this->app['monolog']->addInfo("Skipped table $table because it is member of the ignore list");
                    continue;
                }

                if (file_exists("$source_path/$table.sql") &&
                    file_exists("$source_path/$table.json")) {

                    // drop the existing table
                    $general->dropTable(CMS_TABLE_PREFIX.$table);

                    // get the SQL to create the table
                    if (false === ($SQL = @file_get_contents("$source_path/$table.sql"))) {
                        throw new \Exception("Can't read the SQL for table $table");
                    }
                    if ($this->app['config']['restore']['settings']['replace_table_prefix']) {
                        // replace the placeholder with the real table prefix
                        $SQL = str_replace('{{ SyncData:TABLE_PREFIX }}', CMS_TABLE_PREFIX, $SQL);
                    }

                    // create the table
                    $general->query($SQL);

                    try {
                        // disable the table keys
                        $this->app['db']->query("ALTER TABLE ".CMS_TABLE_PREFIX."$table DISABLE KEYS");
                        $this->app['monolog']->addInfo("DISABLE KEYS for $table");
                    } catch (\Doctrine\DBAL\DBALException $e) {
                        throw $e->getMessage();
                    }
                    // get the table rows from JSON
                    if (false === ($rows = json_decode(@file_get_contents("$source_path/$table.json"), true))) {
                        throw new \Exception("Can't read the data rows for table $table");
                    }
                    // insert the table rows
                    $replace_cms_url = $this->app['config']['restore']['settings']['replace_cms_url'];
                    $general->insertRows(CMS_TABLE_PREFIX.$table, $rows, $replace_cms_url);
                    $this->app['monolog']->addInfo(sprintf("Inserted %d rows into table %s", count($rows), $table));

                    try{
                        // enable the table keys
                        $this->app['db']->query("ALTER TABLE ".CMS_TABLE_PREFIX."$table ENABLE KEYS");
                        $this->app['monolog']->addInfo("ENABLE KEYS for $table");
                    } catch (\Doctrine\DBAL\DBALException $e) {
                        throw $e->getMessage();
                    }


                    if (file_exists("$source_path/$table.md5")) {
                        if (false === ($md5 = @file_get_contents("$source_path/$table.md5"))) {
                            throw new \Exception("Can't read the MD5 checksum for table $table");
                        }
                        $new_md5 = $general->getTableContentChecksum(CMS_TABLE_PREFIX.$table);
                        if ($md5 != $new_md5) {
                            throw new \Exception("MD5 checksum comparison ($md5 <=> $new_md5) for table $table failed!");
                        }
                        $this->app['monolog']->addInfo("MD5 checksum comparison for table $table was successfull");
                    }
                }
            }
        } catch (\Exception $e) {
            if ($create_backup) {
                // we have created a backup before and can restore!
                $this->app['monolog']->addError($e->getMessage());
                $this->app['monolog']->addCritical("Abort RESTORE, try to restore the previous created BACKUP!");
                //$this->restoreTables(TEMP_PATH.'/backup/tables', false);
                $this->app['monolog']->addInfo("The RESTORE from previous created BACKUP was SUCCESFULL");
                throw new \Exception("The RESTORE process failed with errors. The tables where successfull recovered");
            } else {
                throw new \Exception($e);
            }
        }
    }

    /**
     * Restore files from the archive
     *
     * @param string $source_path
     * @param string $create_backup
     * @throws \Exception
     */
    protected function restoreFiles($source_path, $create_backup=true)
    {
        if ($create_backup) {
            try {
                $backup = new Backup($this->app);
                $backup->backupFiles(null);
            } catch (\Exception $e) {
                throw new \Exception($e);
            }
        }

        try {
            $ignore_directories = array();
            foreach ($this->app['config']['restore']['directories']['ignore']['directory'] as $directory) {
                // take the real path for the directory
                $ignore_directories[] = CMS_PATH.DIRECTORY_SEPARATOR.$directory;
            }
            $ignore_subdirectories = $this->app['config']['restore']['directories']['ignore']['subdirectory'];
            $ignore_files = $this->app['config']['restore']['files']['ignore'];

            // in general the CMS config.php should not restored!
            if ($this->app['config']['restore']['settings']['ignore_cms_config'] &&
                file_exists($source_path.'/config.php') && !@unlink($source_path.'/config.php')) {
                throw new \Exception("Can't delete the config.php from the restore path!");
            }

            $this->app['utils']->copyRecursive(
                $source_path,
                CMS_PATH,
                $ignore_directories,
                $ignore_subdirectories,
                $ignore_files,
                true
                );
        } catch (\Exception $e) {
            if ($create_backup) {
                // Restore fails but we have backup the files
                $this->app['monolog']->addError($e->getMessage());
                $this->app['monolog']->addCritical("Abort RESTORE of files, try to restore the previous saved tables and files");
                // restore the tables
                $this->restoreTables(TEMP_PATH.'/backup/tables', false);
                $this->app['monolog']->addInfo("The RESTORE of the previous saved tables was SUCCESSFULL");
                $this->restoreFiles(TEMP_PATH.'/backup/cms', false);
                $this->app['monolog']->addInfo("The RESTORE of the previous saved files was SUCCESSFULL");
                throw new \Exception("The RESTORE process failed with errors. The files and tables where successfull recovered");
            }
            else {
                throw new \Exception($e);
            }
        }

    }

    /**
     * Start unzipping and processing the backup archive
     *
     * @param string $archive
     * @throws \Exception
     * @return boolean
     */
    protected function processArchive($archive)
    {
        try {
            if (file_exists(TEMP_PATH.'/restore') && !$this->app['utils']->rrmdir(TEMP_PATH.'/restore')) {
                throw new \Exception(sprintf("Can't delete the directory %s", TEMP_PATH.'/restore'));
            }
            if (!file_exists(TEMP_PATH.'/restore') && (false === @mkdir(TEMP_PATH.'/restore'))) {
                throw new \Exception("Can't create the directory ".TEMP_PATH."/restore");
            }

            if (file_exists(TEMP_PATH.'/backup') && !$this->app['utils']->rrmdir(TEMP_PATH.'/backup')) {
                throw new \Exception(sprintf("Can't delete the directory %s", TEMP_PATH.'/restore'));
            }

            $this->app['monolog']->addInfo("Start unzipping $archive");
            $unZip = new unZip($this->app);
            $unZip->setUnZipPath(TEMP_PATH.'/restore');
            $unZip->extract($archive);
            $this->app['monolog']->addInfo("Unzipped $archive");

            // check if the syncdata.json exists
            if (!file_exists(TEMP_PATH.'/restore/backup/syncdata.json')) {
                throw new \Exception("Missing the syncdata.json file within the archive!");
            }

            // restore the tables
            $this->restoreTables(TEMP_PATH.'/restore/backup/tables');

            // restore the files
            $this->restoreFiles(TEMP_PATH.'/restore/backup/cms');

            return true;
        } catch (\Exception $e) {
            throw new \Exception($e);
        }
    }

    /**
     * Action handler for the class Restore
     *
     * @return string
     */
    public function exec()
    {
        // start restore
        $this->app['monolog']->addInfo('Start RESTORE');

        // check the /inbox
        $files = array();
        $directory_handle = dir(SYNC_DATA_PATH.'/inbox');
        while (false !== ($file = $directory_handle->read())) {
            // get all files into an array
            if (($file == '.') || ($file == '..')) continue;
            $path = $this->app['utils']->sanitizePath(SYNC_DATA_PATH."/inbox/$file");
            if (is_dir($path)) {
                // RESTORE does not scan subdirectories!
                $this->app['monolog']->addInfo("Sipped subdirectory $path, RESTORE search only for files in the /inbox!");
                continue;
            }
            $files[] = $path;
        }
        // sort the array ascending
        sort($files);

        foreach ($files as $file) {
            $fileinfo = pathinfo($file);
            if (strtolower($fileinfo['extension']) !== 'zip') {
                $this->app['monolog']->addInfo(sprintf('RESTORE does only accept ZIP files, %s rejected', basename($file)));
                continue;
            }
            // process the restore file
            if (!file_exists(SYNC_DATA_PATH.'/inbox/'.$fileinfo['filename'].'.md5')) {
                $result = "Missing the MD5 checksum file for the backup archive!";
                $this->app['monolog']->addError($result);
                return $result;
            }
            if (false === ($md5_origin = @file_get_contents(SYNC_DATA_PATH.'/inbox/'.$fileinfo['filename'].'.md5'))) {
                $result = "Can't read the MD5 checksum file for the backup archive!";
                $this->app['monolog']->addError($result);
                return $result;
            }
            $md5 = md5_file($file);
            if ($md5 !== $md5_origin) {
                $result = "The checksum of the backup archive is not equal to the MD5 checksum file value!";
                $this->app['monolog']->addError($result);
                return $result;
            }
            $this->app['monolog']->addInfo("The MD5 checksum of the backup archive is valid ($md5).");

            // processing the archive file
            $this->processArchive($file);

            // very important: set the archive ID!
            if (false === ($syncdata = json_decode(@file_get_contents(TEMP_PATH."/restore/backup/syncdata.json"), true))) {
                throw new \Exception("Can't read the syncdata.json for the backup archive!");
            }
            $archive_id = (isset($syncdata['archive']['last_id'])) ? $syncdata['archive']['last_id'] : 0;
            $data = array(
                'backup_id' => (isset($syncdata['backup']['id'])) ? $syncdata['backup']['id'] : '',
                'backup_date' => (isset($syncdata['backup']['date'])) ? $syncdata['backup']['date'] : '0000-00-00 00:00:00',
                'archive_id' => $archive_id,
                'archive_date' => date('Y-m-d H:i:s'),
                'archive_name' => '',
                'sync_files' => '',
                'sync_master' => '',
                'sync_tables' => '',
                'action' => 'INIT'
            );
            $SynchronizeClient = new SynchronizeClient($this->app);
            $SynchronizeClient->insert($data);
            $this->app['monolog']->addInfo("Added informations for the Synchronize Client");

            // move the backup archive to /data/backup
            if (!file_exists(SYNC_DATA_PATH.'/data/backup/.htaccess') || !file_exists(SYNC_DATA_PATH.'/data/backup/.htpasswd')) {
                $this->app['utils']->createDirectoryProtection(SYNC_DATA_PATH.'/data/backup');
            }
            if (!@rename(SYNC_DATA_PATH.'/inbox/'.$fileinfo['filename'].'.md5', SYNC_DATA_PATH.'/data/backup/'.$fileinfo['filename'].'.md5')) {
                $this->app['monolog']->addError("Can't save the MD5 checksum file in /data/backup!");
            }
            if (!@rename(SYNC_DATA_PATH.'/inbox/'.$fileinfo['filename'].'.zip', SYNC_DATA_PATH.'/data/backup/'.$fileinfo['filename'].'.zip')) {
                $this->app['monolog']->addError("Can't save the backup archive in /data/backup!");
            }

            // and leave the loop
            break;
        }


        $this->app['monolog']->addInfo('Finished RESTORE');
        return 'Finished RESTORE';
    }

}