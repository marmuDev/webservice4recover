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
        /* You have to pass "app" it in like this:
         *      $app->put('/get-connections',function() use ($app) {
         * OR
         *      $app = Slim::getInstance();
         * http://docs.slimframework.com/configuration/names-and-scopes/
         */
        $app = Slim\Slim::getInstance();
        $log = $app->getLog();
        $log->info($path);
        
        $files = listDir($path);
        //print_r($files);
        //print_r("<br>");
               
        // // to OC filelist format (result.data.files in recover filelist.js)
        // adapt file object in listDir, to meet basic requirements
        // function just adds, removes and formats stuff for JSON-Filelist
        $filelistJson = genJsonForOcFileList(json_encode($files));
        $log->info("fileListFinal/JSON");
        $log->info($filelistJson);
        // further sorting may need to be done on the client side (use client ressources insted of server ressources) 
        echo $filelistJson;
    } // end listExt4
    
    /* easier to gen id with array than with json
    // NOW within file object of listDir!!!
    function genIdsForDirContent($dirContent) {
        foreach ($dirContent as $key => $file) {
            // append id to the front of each element
            array_unshift($file, $key);
            array_unshift($file, $key);
            $dirContent[$key]=$file;
        }
        //var_dump($dirContent);
        return $dirContent;   
    }
     */
     
     /*
     * generate JSON format for ownCloud filelist in expected format
     * @param $files: files and directories from processDir function
     * @return JSON-Data to be processed by OC Recover App Lib/Helper
      * 
      * first edit array, then gen Json, or just work on Json text file?
      * 
     */
    function genJsonForOcFileList($files){
        // replace 0 with id - obsolete, see listDir()
        //$files=str_replace('{"0":', '{"id":', $files);
        // surround with "files" and braces
        $tmpCleanFilelist = "{\"files\": ".$files."}";
        return $tmpCleanFilelist;
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
        // set date format to german, to be set in php.ini, not required for OC, there german words are used!
        // Attention: Server needs to support given locale! => perhaps shouldn't do that
        // $newLocale = setlocale(LC_TIME, 'de_DE', 'de_DE.UTF-8');
        // counter for file ID
        $i = 0;
        while ($object = readdir($dirHandle)) {
            if (!in_array($object, array('.', '..'))) {
                $filename = $dir . $object;
                // create file object according to JSON file expected by recover app filelist
                $fileObject = array(
                    'id'            => $i,
                    'parentId'      => 'null',
                    // see ownCloud core/apps/files/lib/helper/formatFileInfo(FileInfo $i)
                    // -> \OCP\Util::formatDate($i['mtime']);
                    // ---> Deprecated 8.0.0 Use \OC::$server->query('DateTimeFormatter') instead
                    // --> use formatFiles($files) in recover/lib/helper.php
                    // back to format the date here, how to get german Month?
                    'date'          => date('d. F Y \u\m H:i:s \M\E\S\Z', filemtime($filename)),
                    //'date'          => filemtime($filename),
                    // see ownCloud core/apps/files/lib/helper/formatFileInfo(FileInfo $i)
                    'mtime'         => filemtime($filename)*1000,
                    // just using static image for now, for more see: foramtFileInfo(FileInfo $i)
                    // https://github.com/owncloud/core/blob/master/apps/files/lib/helper.php
                    // should support all OC filetype, at least file.svg and folder icon
                    //'icon'          => '/core/core/img/filetypes/file.svg',
                    'icon'          => null, // -> icon is set within recover
                    'name'          => $object,
                    // also static for now!
                    'permission'    => 1,
                    //'mimetype'      => 'application/octet-stream',
                    'mimetype'      => null,
                    'type'          => filetype($filename),
                    // size not supported by trashbin, always "null" in original Trashbin
                    //'size'          => filesize($filename),
                    'size'          => null,
                    //'perm'          => permission($filename),
                    //'type'        => filetype($filename),
                    'etag'          => 'null',
                    //'extraData'     => './'.$object.'.'.filemtime($filename)
                    'extraData'     => './'.$object,
                    'displayName'   => $object
                );
                $dirObjects[] = $fileObject;
                $i++;
            }
            
        }
        return $dirObjects;
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
    
    $app->run();
    
    
    
//}