<?php 

/** 
* ExportDatabaseCLI
* Export and Import Mysql
*
* Made by phatnt93
* 03/08/2020
* 
* @license MIT License
* @author phatnt <thanhphat.uit@gmail.com>
* @github https://github.com/phatnt93/export-import-mysql
* @version 1.0.0
* 
*/

if (!defined('EXIM_BASE_DIR')) {
    define('EXIM_BASE_DIR', __DIR__);
}

/**
 * ExportDatabaseCLI
 */
class ExportDatabaseCLI
{
    private $exportDirPath = EXIM_BASE_DIR . DIRECTORY_SEPARATOR . 'export_db';
    private $logDirPath = EXIM_BASE_DIR . DIRECTORY_SEPARATOR . 'logs';
    private $options = [
        'export' => [
            'excludes_db' => 'phpmyadmin, test, mysql, information_schema, performance_schema',
            'export_databases' => '',
            'db_host' => 'localhost',
            'db_user' => 'root',
            'db_pass' => '',
            'mysqldump_path' => 'mysqldump',
        ]
    ];
    private $dbExport = null;
    private $excludesDb = [];
    private $exportDatabases = [];
    private $mysqldumpPath = '';
    private $mysqlPath = '';

    function __construct($options = []){
        if ($this->is_cli() == false) {
            die('Script must be run on cli');
        }
        $config = $this->get_config();

        if (isset($config['export'])) {
            $this->options['export'] = array_merge($this->options['export'], $config['export']);
        }

        // Create log dir
        $resLogDir = $this->create_dir($this->logDirPath);
        if ($resLogDir !== true) {
            die($resLogDir);
        }
    }

    private function is_cli(){
        if( defined('STDIN') ){
            return true;
        }
        if( empty($_SERVER['REMOTE_ADDR']) and !isset($_SERVER['HTTP_USER_AGENT']) and count($_SERVER['argv']) > 0){
            return true;
        } 
        return false;
    }

    private function get_config(){
        $configFile = "config.ini";
        $configPath = EXIM_BASE_DIR . DIRECTORY_SEPARATOR . $configFile;
        if (!file_exists($configPath)) {
            die('Config file was not found');
        }
        $config = parse_ini_file($configFile, true);
        return $config;
    }

    private function setup_export(){
        $this->excludesDb = $this->explode_string($this->options['export']['excludes_db'], ',');
        $this->exportDatabases = $this->explode_string($this->options['export']['export_databases'], ',');

        if ($this->isWindow()) {
            if (empty($this->options['export']['mysqldump_path'])) {
                die("If you run on window. You have to config mysqldump path");
            }
            if (!file_exists($this->options['export']['mysqldump_path'])) {
                die("Mysqldump file was not found");
            }
        }
        $this->mysqldumpPath = $this->options['export']['mysqldump_path'];
    }

    private function explode_string($str, $flag){
        $res = [];
        $arr = array_filter(explode($flag, $str));
        foreach ($arr as $key => $value) {
            $res[] = trim($value);
        }
        return $res;
    }

    private function create_dir($dirPath = ''){
        if (!file_exists($dirPath)) {
            if (!mkdir($dirPath)) {
                return 'Create directory failed "' . $dirPath;
            }
        }
        return true;
    }

    private function write_log($msg = ''){
        $fileName = 'log_' . date('Ymd') . '.txt';
        $filePath = $this->logDirPath . DIRECTORY_SEPARATOR . $fileName;
        if ($msg != '') {
            $msgPut = date('Y-m-d H:i:s') . ' : ' . $msg . "\n";
            file_put_contents($filePath, $msgPut, FILE_APPEND);
        }
    }

    private function open_connection($optDB){
        try {
            $conn = new PDO("mysql:host={$optDB['db_host']}", $optDB['db_user'], $optDB['db_pass']);
            // set the PDO error mode to exception
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
        return $conn;
    }

    private function query($db, $sql, $bind = []){
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_ASSOC);
        return $stmt->fetchAll();
    }

    private function exec_query($db, $sql, $bind = []){
        $stmt = $db->prepare($sql);
        $stmt->execute();
    }

    private function get_list_db($db){
        $sql = "SHOW DATABASES";
        $data = $this->query($db, $sql);
        return array_column($data, 'Database');
    }

    private function set_list_db_to_export(){
        $dbCheckArr = $this->get_list_db($this->dbExport);
        if (count($this->exportDatabases) > 0) {
            $resGet = [];
            foreach ($this->exportDatabases as $kd => $vd) {
                if (in_array($vd, $dbCheckArr)) {
                    $resGet[] = $vd;
                }
            }
            $this->exportDatabases = $resGet;
        }else{
            foreach ($dbCheckArr as $key => $dbname) {
                if (!in_array($dbname, $this->excludesDb)) {
                    $this->exportDatabases[] = $dbname;
                }
            }
        }
    }

    private function isWindow(){
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return true;
        }
        return false;
    }

    private function runCommand($cmd){
        exec($cmd);
    }

    /**
     * Export db
     * @return [type] [description]
     */
    public function export(){
        try {
            $this->setup_export();
            // Open connection
            $this->dbExport = $this->open_connection($this->options['export']);
            // Create export dir
            $resCreateExportDir = $this->create_dir($this->exportDirPath);
            if ($resCreateExportDir !== true) {
                throw new \Exception($resCreateExportDir);
            }
            $this->set_list_db_to_export();
            $targetDirExportName = 'export_' . date('YmdHis');
            $targetDirExportPath = $this->exportDirPath . DIRECTORY_SEPARATOR . $targetDirExportName;
            $resTargetDirExport = $this->create_dir($targetDirExportPath);
            if ($resTargetDirExport !== true) {
                throw new \Exception($resTargetDirExport);
            }
            if (count($this->exportDatabases) == 0) {
                throw new \Exception('No database name to export');
            }
            foreach ($this->exportDatabases as $ked => $vedName) {
                $targetFileExportPath = $targetDirExportPath . DIRECTORY_SEPARATOR . $vedName . '.sql';
                $cmdStr = implode(' ', [
                    $this->mysqldumpPath,
                    '--host="' . $this->options['export']['db_host'] . '"',
                    '--user="' . $this->options['export']['db_user'] . '"',
                    '--password="' . $this->options['export']['db_pass'] . '"',
                    $vedName,
                    '>',
                    $targetFileExportPath
                ]);
                $this->runCommand($cmdStr);
                // Append query create DB begin file
                $sqlCreateDB =  "CREATE DATABASE {$vedName} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; USE {$vedName};";
                $this->appendStr($targetFileExportPath, $sqlCreateDB);
            }

            echo "OK. File exported in {$targetDirExportName} directory";
        } catch (\Exception $e) {
            $this->write_log($e->getMessage());
            echo "Error!!! Please see log file";
        }
    }

    public function appendStr($file, $str = ''){
        if (file_exists($file)) {
            $content = file_get_contents($file);
            $content = $str . "\n" . $content;
            file_put_contents($file, $content);
        }
    }
}


// Main script
$eidb = new ExportDatabaseCLI();
$eidb->export();