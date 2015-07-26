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
    
    $test = "test info log";
    
    $log->info($test);
    
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
    
    // FUNCTIONS
    // $app->get('/files/listExt4', 'listExt4'); 
    function listExt4($path) {
        echo "path = ".$path;
        /* You have to pass "app" it in like this:
         *      $app->put('/get-connections',function() use ($app) {
         * OR
         *      $app = Slim::getInstance();
         * http://docs.slimframework.com/configuration/names-and-scopes/
         */
        $app = Slim\Slim::getInstance();
        $log = $app->getLog();
        $log->info($path);
        
        
        
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
