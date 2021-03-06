<?php
/**
 * SLIM Webservice for OC App Recover
 * Lists given directory and returns JSON filelist
 * Recovers given file/folder and returns JSON response
 *
 * By default logging to STDERR (sudo tail -f /var/log/apache2/error.log)
 * further: app-dir/logs/... -> tail -f 2015-07-26.log
 *
 * @author Marcus Mundt <marmu@mailbox.tu-berlin.de>
 * @copyright Marcus Mundt 2015
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 */
require 'vendor/autoload.php';
   
$app = new \Slim\Slim();
// set some config params
$app->config(array(
    'debug' => true,
    'mode' => 'development',
    'templates.path' => '../templates',
    'log.writer' => new \Slim\Logger\DateTimeFileWriter()
));

// Get log writer
$log = $app->getLog();

// log level may be altered during execution
//$app->log->setLevel(\Slim\Log::DEBUG);

// define routes 
// --> http://httpd.apache.org/docs/2.2/mod/core.html#allowencodedslashes
//      NoDecode -> %2F works!
// one listDir for all
$app->get('/files/listDirGeneric/:path/:source', 'listDirGeneric'); 

/*
 * triggers recovery of specified file or folder
 */
$app->get('/files/recover/:file/:source/:dir/:user/:snapshotId', 'recoverFile'); 

/*
 * to do: further method to search files within Backups/Snapshots
 */
$app->get('/files/search/:filename', 'search'); 

/* FUNCTIONS
 * List directory of local filesystem
 * 
 * @param String $path directory to be listed
 * @param String $source directory to be listed
 * @return String $ocJsonFiles contents of directory in 
 *          
 */
// when parameter is optional set default value
//function listDirGeneric($path='/', $source) {
function listDirGeneric($path, $source) {
    $app = Slim\Slim::getInstance();
    $log = $app->getLog();
    $log->info('---------------- LISTDIR ----------------');
    $log->info('path = '.$path);
    $log->info('source = '.$source);
    if (substr($path, 0, 1) != '/') {
        $path = '/'.$path;
    }
    /* base dir on OC server for snapshots depends on server and source 
     * /gpfs/.snapshots | /tubfs/.snapshots 
     * sorting files array in OC pagecontroller!
     */
    $files = listDir($path, $source);
    // json_encode + genJsonForOcFilelist
    $ocJsonFiles = genJsonForOcFileList(json_encode($files));
    $log->info($ocJsonFiles);
    echo $ocJsonFiles;
} // end list

/* obsolete -> listDirGeneric!
 * 
 * @param string $path: directory to be listed
 * @return string $files contents of directory in JSON 
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
 * @param string $dir: directory to process
 * @param string $source: data source of backuped file or snapshot, to be written in file info
 * @return array $dirObjects: two dimensional array with files and folders 
 */
function listDirViaExec($dir, $source) {
    if ($dir[strlen($dir) - 1] != '/') {
        $dir .= '/';
    }
    // OS / config dependent, ubuntu = www-data
    // use only if command should be run as another user
    //$username='www-data';
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
   */
    return $dirObjects;
}

/*
 * generate JSON format for ownCloud filelist in expected format
 * @param $files: files and directories from listDirGeneric function
 * @return JSON-Data to be processed by OC Recover Pagecontroller
 */
function genJsonForOcFileList($files){
    // surround with "files" and braces
    $tmpCleanFilelist = "{\"files\": ".$files."}";
    return $tmpCleanFilelist;
}
/*
 * adapted from: http://php.net/manual/de/function.readdir.php
 * processes dir on local file system
 * @param strin $dir: directory to process
 * @param string $source: data source of backuped file or snapshot, to be written in file info
 * @return array $dirObjects: two dimensional array with files / folders and info on them
 */
function listDir($dir, $source) {
    $snapshot = 'null';
    // get snapshot number from path + use snapshot for check in while below
    if ($source === 'tubfsss') {
        preg_match("/\/snap_([0-9])\//", $dir, $matches);
        $snapshot = $matches[1];
    }
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
        if (!in_array($object, ['.', '..'])) {
            $filename = $dir . $object;
            // only use e-tag for snapshot id, if tubfsss (or other snapshot with IDs has to be listed)
            if ($snapshot !== 'null') {
                $etag = $snapshot;
            } else {
                $etag = filemtime($filename)*1000;
            }
            // create file object according to JSON string expected by recover app filelist
            $fileObject = [
                'id'            => $i,
                // not part of OC-trashbin-files-array
                //'parentId'      => 'null',
                // see ownCloud core/apps/files/lib/helper/formatFileInfo(FileInfo $i)
                // -> \OCP\Util::formatDate($i['mtime']);
                // ---> Deprecated 8.0.0 Use \OC::$server->query('DateTimeFormatter') instead
                // --> use formatFiles($files) in recover/lib/helper.php
                // back to format the date here, how to get german Month?
                'date'          => date('d. F Y \u\m H:i:s \M\E\S\Z', filemtime($filename)),
                // see ownCloud core/apps/files/lib/helper/formatFileInfo(FileInfo $i)
                'mtime'         => filemtime($filename)*1000,
                // just using static image for now, for more see: foramtFileInfo(FileInfo $i)
                // https://github.com/owncloud/core/blob/master/apps/files/lib/helper.php
                // should support all OC filetype, file.svg and folder icon working
                //'icon'          => '/core/core/img/filetypes/file.svg',
                'icon'          => null, // -> icon is set within recover, only dir and file are destinguished
                'name'          => $object,
                // also static for now!
                'permissions'    => '1',
                //'mimetype'      => 'application/octet-stream',
                // trying to use mimetype for source now, since there are functions available in OC to get that value
                'mimetype'      => $source,
                'type'          => filetype($filename),
                // size not supported by trashbin, always "null" in original Trashbin
                //'size'          => filesize($filename),
                //'size'          => 'null',
                //'perm'          => getFilePermissions($filename),
                //'type'        => filetype($filename),
                // using etag for Snapshot number                    
                'etag'          => $etag,
                // this will be displayed when hoovering over a file/dir, could be extended with source
                'extraData'     => './'.$object
                ];
            $dirObjects[] = $fileObject;
            $i++;
        }
    }
    return $dirObjects;
}
// sorting has to be done on the whole files array -> within OC -> pagecontroller!

// not implemented yet!
// $app->get('/files/search:filename', 'search'); 
function search($filename) {
    echo "filename = ".$filename;
}
/* recovers given file or directory using rename()
 *
 * TO DO:
 *  make generic version, this is tubfs only!
 *  rename won't work when destination dir is not empty
 *
 * @param String $dir directory in which the recover target is located
 * @param String $file file/folder below $dir to be recovered
 * @param String $source stically set to "tubfsss" in recover()
 * @param Int $snapshotId snapshotId of file/folder to be recovered
 * @param String $user snapshotId of file/folder to be recovered
 * @return String JSON returns status code and message
 */
function recoverFile ($file, $source, $dir, $user, $snapshotId) {
    $message = 'null';
    // source path and destination path depend on source of file/folder
    $app = Slim\Slim::getInstance();
    $app->contentType('application/json');
    $response = $app->response;
    //$app->response->headers->set('Content-Type', 'application/json');
    $response->headers->set('Content-Type', 'application/json');
    $response->setStatus(200);
    $log = $app->getLog();
    $log->info('---------------- RECOVER ----------------');
    $log->info('file = '.$file);
    $log->info('source = '.$source);
    $log->info('dir = '.$dir);
    $log->info('user = '.$user);
    $log->info('snapshotId = '.$snapshotId);
    // /tubfs/.snapshots/snap_<snapshotId>/owncloud/data/<user>/files/<dir>/
    switch ($source) {
        case 'tubfsss':
            if ($dir == '/') {
                $recover_source = '/tubfs/.snapshots/snap_'.$snapshotId.'/owncloud/data/'.$user.'/files/'.$file;
                $recover_source_dir = '/tubfs/.snapshots/snap_'.$snapshotId.'/owncloud/data/'.$user.'/files/';
            }
            else {
                $recover_source = '/tubfs/.snapshots/snap_'.$snapshotId.'/owncloud/data/'.$user.'/files/'.$dir.'/'.$file;
                $recover_source_dir = '/tubfs/.snapshots/snap_'.$snapshotId.'/owncloud/data/'.$user.'/files/'.$dir;
            }
            break;
        default:
            $recover_source= '';
            break;
    }
    $log->info('source path = '.$recover_source);
    // destination also source dependent, e.g. /gpfs instead of tubfs
    // /tubfs/owncloud/data/<user>/recovered/<snapshot>/<dir>/
    if ($dir !== '/') {
        $recover_destination = '/home/'.$user.'/recovered/snapshot'.$snapshotId.'/'.$dir.'/';
    }
    else {
        $recover_destination = '/home/'.$user.'/recovered/snapshot'.$snapshotId.'/';
    }
    $log->info('destination path = '.$recover_destination);
    // mkdir if not existent 
    // chmod / chown implicitly called by rename
    // how to set permissions
    // maybe even mkdir is obsolete?
    if (!file_exists($recover_destination)) {  
        if (!mkdir($recover_destination, 0700, true)) {
            $message = 'error while trying to mkdir destination path, aborting recovery!';
            $log->info($message);
            //return json_encode($result);
            echo '{"statusCode":500,"message":"'.$message.'"}';
            // the slim app way
            //$app->halt(500, $message);
            //$response->setStatus(500);
            //$response->write($message);
        }
    } 
    else {
        // if dir exists, ignore and write to it anyways (for now)            
        // could try to recover files beneath if they do not already
        //  exist in destination, or rename with "_X"
        $log->info('destination directory ('.$recover_destination.') already exists in destination path!');
        // if source dir empty, we may abort way earlier!
        // glob () can't work with remote files, 
        // since access to file must be possible through filesystem of server
        //if (count(glob($recover_source_dir.'/*')) === 0 ) {
        if (!glob($recover_source_dir)) {
            $message = 'source directory ('.$recover_source_dir.') is empty, aborting recovery!';
            $log->info($message);
            echo '{"statusCode":500,"message":"'.$message.'"}';
            //$app->halt(500, $message);
            //$response->setStatus(500);
            //$response->write($message);
        }
    }
    // move via rename
    if (!rename($recover_source, $recover_destination.$file)) {
        $message = 'Error while trying to rename (move) file or folder';
        $log->info($message);
    }
    else {
        $message = 'all good';
    }
    $log->info(json_encode($message));
    echo '{"statusCode":200,"message":"'.$message.'"}';
}

// get permissions of given file -> not used, see listDirGeneric
// see: http://php.net/manual/de/function.readdir.php
function getFilePermissions($filename) {
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
