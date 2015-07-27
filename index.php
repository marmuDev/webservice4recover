<?php
require 'vendor/autoload.php';

/**
 * Description of app
 *
 * @author Marcus Mundt <marmu@mailbox.tu-berlin.de>
 * 
 * By default logging to STDERR (sudo tail -f /var/log/apache2/error.log)
 * further: app-dir/logs/... -> tail -f 2015-07-26.log
 * 
 * two main functions + routes for now:
 * GET: Used to retrieve and search data. POST: Used to insert data.
 *  
 */
//class app {
    //put your code here
    $app = new \Slim\Slim();
    // set some config params
    $app->config(array(
        'debug' => true,
        'mode' => 'development',
        'templates.path' => '../templates',
        'log.writer' => new \Slim\Logger\DateTimeFileWriter()
        //'log.enable' => true, 
        //'log.level' => Slim_Log::DEBUG
        /* slim extras deprecated -> https://github.com/codeguy/Slim-Logger
        'log.writer' => new \Slim\Extras\Log\DateTimeFileWriter(array(
            'path' => './logs',
            'name_format' => 'Y-m-d',
            'message_format' => '%label% - %date% - %message%'))
         * 
         */
    ));
    
    // Get log writer
    $log = $app->getLog();
    
    // log level may be altered during execution
    //$app->log->setLevel(\Slim\Log::DEBUG);
    
    // define HTTP routes 
    $app->get('/hello/:name', function ($name) {
        echo "Hello, " . $name;
    });
    
    // Using Get HTTP Method and process listExt4 
    // wie "/" in URL übergeben?  "%2F" funzt nicht!
    $app->get('/files/listExt4/:path', 'listExt4'); 
    
    /*
     * to do: further method to search files within Backups/Snapshots
     * how to use params (filename, mtime, size)
     * $app->get('/users/:id' -> see above! same with PUT
     * 
     */
    $app->get('/files/search/:filename', 'search'); 
    
    /*
     * Using Post HTTP Method and process addRecover function
     * 
     * should trigger recovery of specified file or folder
     * how to use parameters (what to recover)
     * 
     */
    $app->post('/files/recover/:recoverRequest', 'addRecoverRequest'); 
    
    /* FUNCTIONS
     * $app->get('/files/listExt4', 'listExt4'); 
     * path relative to app root!
     * 
     * @param $path: directory to be listed
     * @return dirJson: contents of directory in JSON 
     *          
     */
    function listExt4($path) {
        echo "path = ".$path."\n\n";
        /* You have to pass "app" it in like this:
         *      $app->put('/get-connections',function() use ($app) {
         * OR
         *      $app = Slim::getInstance();
         * http://docs.slimframework.com/configuration/names-and-scopes/
         */
        $app = Slim\Slim::getInstance();
        $log = $app->getLog();
        $log->info($path);
        /* 
         * now using function process dir
        if ($handle = opendir($path)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry != "." && $entry != "..") {
                    echo "$entry\n";
                }
            }
            closedir($handle);
        }
         
         */
        $files = listDir($path);
        // works so far
        foreach ($files as $file) {
            print_r(array_values($file));
            printf("<br>");
        }
        $dirJson = genJsonForOcFileList($files);
        print_r($dirJson);
        //return $dirJson;
    } // end listExt4
    
    // get permissions of given file
    // see: http://php.net/manual/de/function.readdir.php
    function permission($filename) {
        $perms = fileperms($filename);

        if     (($perms & 0xC000) == 0xC000) { $info = 's'; }
        elseif (($perms & 0xA000) == 0xA000) { $info = 'l'; }
        elseif (($perms & 0x8000) == 0x8000) { $info = '-'; }
        elseif (($perms & 0x6000) == 0x6000) { $info = 'b'; }
        elseif (($perms & 0x4000) == 0x4000) { $info = 'd'; }
        elseif (($perms & 0x2000) == 0x2000) { $info = 'c'; }
        elseif (($perms & 0x1000) == 0x1000) { $info = 'p'; }
        else                                 { $info = 'u'; }

        // owner
        $info .= (($perms & 0x0100) ? 'r' : '-');
        $info .= (($perms & 0x0080) ? 'w' : '-');
        $info .= (($perms & 0x0040) ? (($perms & 0x0800) ? 's' : 'x' ) : (($perms & 0x0800) ? 'S' : '-'));

        // group
        $info .= (($perms & 0x0020) ? 'r' : '-');
        $info .= (($perms & 0x0010) ? 'w' : '-');
        $info .= (($perms & 0x0008) ? (($perms & 0x0400) ? 's' : 'x' ) : (($perms & 0x0400) ? 'S' : '-'));

        // others
        $info .= (($perms & 0x0004) ? 'r' : '-');
        $info .= (($perms & 0x0002) ? 'w' : '-');
        $info .= (($perms & 0x0001) ? (($perms & 0x0200) ? 't' : 'x' ) : (($perms & 0x0200) ? 'T' : '-'));

        return $info;
    }
    /*
     * adapted from: http://php.net/manual/de/function.readdir.php
     * processes dir on local file system
     * @param $dir: directory to process
     * @return $dirObjects: two dimensional array with files and folders 
     */
    function listDir($dir) {
        if ($dir[strlen($dir) - 1] != '/') {
            $dir .= '/';
        }
        if (!is_dir($dir)) {
            return array();
        }
        $dirHandle  = opendir($dir);
        $dirObjects = array();
        while ($object = readdir($dirHandle)) {
            if (!in_array($object, array('.', '..'))) {
                $filename = $dir . $object;
                $fileObject = array(
                    'name' => $object,
                    'size' => filesize($filename),
                    'perm' => permission($filename),
                    'type' => filetype($filename),
                    'time' => date("d F Y H:i:s", filemtime($filename))
                );
                $dirObjects[] = $fileObject;
            }
        }
        return $dirObjects;
    }
    
    /*
     * generate JSON format for ownCloud filelist in expected format
     * @param $files: files and directories from processDir function
     * @return JSON-Data to be processed by OC Recover App Lib/Helper
     */
    function genJsonForOcFileList($files){
        
    }
    
    // $app->get('/files/search:filename', 'search'); 
    function search($filename) {
        echo "filename = ".$filename;
    }
    
    // $app->post('/files/recover/:recoverRequest', 'addRecoverRequest'); 
    // solve via get too, since data can be sent and when recoverRequest is ok + stored
    // -> give success info (and go back to last page)
    function addRecoverRequest ($recoverRequest) {
        //$log = $app->getLog();
        $app = Slim::getInstance();
        $log = $app->getLog();
        $log->info($recoverRequest);
    }
    
    $app->run();
    
    
    
//}