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
// print server vars for debugging
//foreach($_SERVER as $key_name => $key_value) {
//    print $key_name . " = " . $key_value . "<br>";
//}    
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
    // http://localhost/webservice4recover/index.php/files/listExt4/testdir
    // how to pass "/" via URL?  "%2F" doesn't work!
    // --> http://httpd.apache.org/docs/2.2/mod/core.html#allowencodedslashes
    //      NoDecode -> %2F works!
    // how to pass further dirs like "testdir2" ? /testdir%2Ftestdir2
    //  http://localhost/webservice4recover/index.php/files/listExt4/gpfs-folder1%2Fgpfs-folder2
    // now path as optional parameter -> if empty, list baseDir 
    //  
    //$app->get('/files/listExt4/(:path)', 'listExt4'); 
    //$app->get('/files/listGpfsSs/(:path)', 'listGpfsSs'); 
    // one listDir for all
    $app->get('/files/listDirGeneric/:path/:source', 'listDirGeneric'); 
    
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
    //function listDirGeneric($path='/', $source) {
    function listDirGeneric($path, $source) {
        if (substr($path, 0, 1) != '/') {
            $path = '/'.$path;
        }
        /* You have to pass "app" it in like this:
         *      $app->put('/get-connections',function() use ($app) {
         * OR
         *      $app = Slim::getInstance();
         * http://docs.slimframework.com/configuration/names-and-scopes/
         */
        //var_dump($path);
        $app = Slim\Slim::getInstance();
        $log = $app->getLog();
        $log->info($path);
        $log->info($source);
                
        // base dir on OC server for snapshots = /gpfs/.snapshots
        // depends on Server and Source, could become Parameter
        $files = listDir($path, $source);
        //$log->info('usorted Files------------------------------:');
        //$log->info($files);
        // TO DO: sort files array -> in OC pagecontroller!
        
        // json_encode + genJsonForOcFilelist
        $ocJsonFiles = genJsonForOcFileList(json_encode($files));
        $log->info($ocJsonFiles);
        echo $ocJsonFiles;
    } // end list
    
    /* obsolete -> listDirGeneric!
     * 
     * @param $path: directory to be listed
     * @return dirJson: contents of directory in JSON 
     *          
     */
    function listGpfsSs($path='/') {
        if (substr($path, 0, 1) != '/') {
            $path = '/'.$path;
        }
        $app = Slim\Slim::getInstance();
        $log = $app->getLog();
        $log->info($path);
        // base dir on OC server for snapshots = /gpfs/.snapshots
        // depends on Server and Source, could become Parameter
        $baseDir = '/gpfs/.snapshots';
        // pass dir and source
        $files = listDirViaExec($baseDir.$path, 'ext4');
        echo json_encode($files);
    } // end listGpfsSs
    
     /*
     * processes dir on local file system via exec() - obsolete
     * @param $dir: directory to process
     * @param $source: data source of backuped file or snapshot, to be written in file info
     * @return $dirObjects: two dimensional array with files and folders 
     */
    function listDirViaExec($dir, $source) {
        if ($dir[strlen($dir) - 1] != '/') {
            $dir .= '/';
        }
        // OS / config dependent, ubuntu = www-data
        // use only if command should be run as another user
        $username='www-data';
        /* test if e.g. "touch" is denied
         * marcus@ocdev:/gpfs/.snapshots$ sudo -u www-data touch test
         * touch: »test“ kann nicht berührt werden: Keine Berechtigung 
         * ABER: sudo -u www-data find ./ -name gpfs-file1, somit alle commands erlaubt
         * --> sudo visudo, let www-data only use ls, and later cp. 
         */
        //$command = 'sudo -u '.$username.' ls -l ';
        $command = 'sudo ls -l --time-style=+\(%s\) ';
        $dirObjects = array();
        
        exec($command.$dir, $dirObjects);
        var_dump($dirObjects);
        // counter for file/folder ID
        $i = 0;
        /* go through retrieved objects and create basic OC files filelist format
         * array:
         * array (size=4)
            0 => string 'total 4' (length=7)
            1 => string '-rw-r--r-- 1 root root    0 (1439310738) gpfs-file1' (length=51)
            2 => string '-rw-r--r-- 1 root root    0 (1439310742) gpfs-file2' (length=51)
            3 => string 'drwxr-xr-x 3 root root 4096 (1439310728) gpfs-folder1' (length=53)
         * could separate filename using "\d\d\d\d\d\d\d\d\d\d) " but what if a filename consists of such a string
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
                    // 'mimetype'      => null, trying to use mimetype for source now
                    'mimetype'      => $source,
                    'type'          => filetype($filename),
                    // size not supported by trashbin, always "null" in original Trashbin
                    //'size'          => filesize($filename),
                    'size'          => null,
                    //'perm'          => permission($filename),
                    //'type'        => filetype($filename),
                    'etag'          => 'null',
                    //'extraData'     => './'.$object.'.'.filemtime($filename)
                    // this will be displayed when hoovering over a file/dir, could be extended with source
                    //'extraData'     => './'.$object.'('.$source.')',
                    'extraData'     => './'.$object,
                    'displayName'   => $object,
                    'dir'           => $dir,
                    'source'        => $source
                );
                $dirObjects[] = $fileObject;
                $i++;
            }
            
        }
         */
        return $dirObjects;
    }
    
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
     * @param $source: data source of backuped file or snapshot, to be written in file info
     * @return $dirObjects: two dimensional array with files / folders and info on them
     */
    function listDir($dir, $source) {
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
                    // 'mimetype'      => null,
                    // trying to use mimetype for source now, since there are functions available in OC to get that value
                    'mimetype'      => $source,
                    'type'          => filetype($filename),
                    // size not supported by trashbin, always "null" in original Trashbin
                    //'size'          => filesize($filename),
                    'size'          => null,
                    //'perm'          => permission($filename),
                    //'type'        => filetype($filename),
                    'etag'          => 'null',
                    //'extraData'     => './'.$object.'.'.filemtime($filename)
                    // this will be displayed when hoovering over a file/dir, could be extended with source
                    //'extraData'     => './'.$object.'('.$source.')',
                    'extraData'     => './'.$object,
                    'displayName'   => $object,
                    'dir'           => $dir,
                    'source'        => $source
                );
                $dirObjects[] = $fileObject;
                $i++;
            }
            
        }
        return $dirObjects;
    }
    /* sorting has to be done on the whole files array within OC!
    function sortFilesArray($files, $sortAttribute, $sortDirection) {
        $hash = array();
    
        foreach($files as $key => $file) {
            //echo "file";
            //var_dump($file);
            $hash[$file[$sortAttribute].$key] = $file;
            //echo "hash";
            //var_dump($hash);
        }

        ($sortDirection === 'desc')? krsort($hash) : ksort($hash);

        $files = array();

        foreach($hash as $file) {
            $files []= $file;
        }
        //echo "files";
        //var_dump($files);
        return $files;
    }
    */
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