<?php /**
 * Ajency Laravel CSV Import Package Additional Library
 */
namespace Ajency\Ajfileimport\Helpers;

use Ajency\Ajfileimport\Mail\AjSendMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Log;
use \Mail;

/**
 * Class for aj importlibs.
 */
class AjImportlibs
{

    public function __construct()
    {
        register_shutdown_function(array($this, '__destruct'));
    }

    public function __destruct()
    {

    }

    public function custom_mysql_real_escape($inp)
    {

        if (is_array($inp)) {
            return array_map(__METHOD__, $inp);
        }

        if (!empty($inp) && is_string($inp)) {
            return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);
        }

        return $inp;

    }

    /**
     * Creates directory if don't exists with provided params ex: array('permissions'=>0777,'recursive'=>true,'force'=>true)
     *
     * @param      <type>  $filepath  The filepath
     * @param      array   $params    The parameters
     */
    public function createDirectoryIfDontExists($filepath, $params = array())
    {
        $default_params = array('permissions' => 0777, 'recursive' => true, 'force' => true);
        $params         = array_merge($default_params, $params);

        $this->debugLog(array('createDirectoryIfDontExists:-----------------------------', $params));
        extract($params);

        if (!$this->is_directory_exists($filepath)) {
            $filepath = File::makeDirectory($filepath, $permissions, $recursive, $force);
        }

        if ($this->is_directory_exists($filepath)) {
            return array('result' => false, 'errors' => array('Please check folder permissions. Directory "' . $filepath . '" cannot be created. Cannot proceed with import'), 'logs' => array());
        } else {
            return array('result' => true, 'errors' => array(), 'logs' => array());
        }

    }

    public function is_directory_exists($filepath)
    {
        if (File::exists($filepath)) {
            if (File::isDirectory($filepath)) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }

    }

    /**
     * { function_description }
     *
     * @param      string  $prefix  The prefix
     * @param      string  $folder  The folder
     *
     * @return     string  ( description_of_the_return_value )
     */
    public function generateUniqueOutfileName($prefix, $folder)
    {

        $rand_string = $this->getRandomString(4);
        $file_path   = $folder . $prefix . "_" . $rand_string . "_" . date('d_m_Y_H_i_s') . ".csv";
        if (file_exists($file_path)) {
            $this->generateUniqueOutfileName($prefix, $folder);
        } else {
            return $file_path;
        }

    }

    /**
     * function to generate random strings
     * @param       int     $length     number of characters in the generated string
     * @return      string  a new string is created with random characters of the desired length
     */
    public function getRandomString($length = 4)
    {
        $randstr = "";
        // srand((double) microtime(TRUE) * 1000000);
        //our array add all letters and numbers if you wish
        $chars = array(
            'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'p',
            'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', '1', '2', '3', '4', '5',
            '6', '7', '8', '9');

        for ($rand = 0; $rand <= $length; $rand++) {
            $random = rand(0, count($chars) - 1);
            $randstr .= $chars[$random];
        }
        return $randstr;
    }

    /**
     * { function_description }
     *
     * @param      <type>  $logs    The logs
     * @param      array   $params  The parameters
     * ex : array('is_success'=>true,'pre_message'=>'here is list of ...','post_message'=>'logs ends here ')
     */
    public function printLogs($logs, $params = array())
    {

        $total_logs = count($logs);

        if (isset($params['pre_message'])) {
            echo $params['pre_message'];
        }

        for ($cnt = 0; $cnt < $total_logs; $cnt++) {
            echo "<br/>" . ($cnt + 1) . ".  " . $logs[$cnt];
        }

        if (isset($params['post_message'])) {
            echo $params['post_message'];
        }

    }

    public function sendMail($params)
    {

        Mail::send(new AjSendMail($params));

        //
        /*Mail::raw('sendDailyProjectMailsToProfile-'.env('APP_ENV'), function ($message) {

    $message->from(env('MAIL_FROM'), env('MAIL_FROM_NAME'));
    $message->to('paragredkar@ajency.in','paragredkar')->subject('daily project mail');
    });*/

    }

    public function getMysqlTempDirectory()
    {

        $qry_get_mysql_temp_directory = "SHOW VARIABLES LIKE 'tmpdir'";
        $res_get_mysql_temp_directory = DB::select($qry_get_mysql_temp_directory);
        foreach ($res_get_mysql_temp_directory as $res_v) {
            return str_replace("\\", "\\\\", $res_v->Value);
        }

    }

    public function getMysqlSecureFilePrivDirectory()
    {

        $qry_get_mysql_securefilepriv_directory = "SHOW VARIABLES LIKE 'secure_file_priv'";
        $res_get_mysql_securefilepriv_directory = DB::select($qry_get_mysql_securefilepriv_directory);
        foreach ($res_get_mysql_securefilepriv_directory as $res_v) {
            return str_replace("\\", "\\\\", $res_v->Value);
        }

    }

    /**
     * Get the temporary export directory where export/import files can be created.
     *
     * @return     string  The temporary export directory.
     */
    public function getTempImportExportDirectory()
    {
        if (config('ajimportdata.import_folder') != "") {

            return config('ajimportdata.import_folder');

        } else {

            $securefilepriv_directory = $this->getMysqlSecureFilePrivDirectory();

            if ($securefilepriv_directory != "" && $securefilepriv_directory != false && !is_null($securefilepriv_directory)) {

                return $securefilepriv_directory;

            } else {
                $mysqltmp_directory = $this->getMysqlTempDirectory();

                if ($mysqltmp_directory != "" && $mysqltmp_directory != false && !is_null($mysqltmp_directory)) {
                    return $mysqltmp_directory;
                } else {
                    return '';
                }

            }

        }

    }

    public function createTestImportFolder()
    {

        $import_export_temp_dir = $this->getTempImportExportDirectory();

        if ($import_export_temp_dir == "" || is_null($import_export_temp_dir || $import_export_temp_dir == false)) {
            return array('result' => false, 'errors' => 'Import directory is not set. Cannot proceed with import. Set "import_folder" in config file or set Mysql secure_file_priv/tmp folder.');
        }

        $ajency_folder = $import_export_temp_dir . "/Ajency/";

        if (!$this->is_directory_exists($ajency_folder)) {

            $result_folder_create = $this->createDirectoryIfDontExists($ajency_folder);
        } else {
            $result_folder_create['result'] = true;
        }

        if ($result_folder_create['result'] == true) {

            $file_prefix = "aj_test_file_create_";

            $test_export_file_path = $this->generateUniqueOutfileName($file_prefix, $ajency_folder);

            //$file_path = str_replace("\\", "\\\\", $test_export_file_path);
            $file_path = $this->formatImportExportFilePath($test_export_file_path);

            $qry_test = "SELECT  'test_id', 'test_name' INTO OUTFILE '" . $file_path . "'
                                    FIELDS TERMINATED BY ','
                                    OPTIONALLY ENCLOSED BY '\"'
                                    LINES TERMINATED BY '\n'
                                    FROM users LIMIT 0,1
                                    ";
            try {

                Log:info($qry_test);
                DB::select($qry_test);

                /* if (!File::exists($test_export_file_path)) {
                return array('result' => false, 'errors' => array("'" . $ajency_folder . "' Folder does not have write permission. Cannot proceed with import"));
                } else {*/
                return array('result' => true, 'errors' => array(), 'logs' => array());
                //}

            } catch (\Illuminate\Database\QueryException $ex) {

                $error_msg = $ex->getMessage();

                if (stristr($error_msg, 'create/write') != false) {
                    $error_msg = "Please set write permission for folder '" . $ajency_folder . "' and Upload the file again.<br/> " . $error_msg;
                }
                return array('result' => false, 'errors' => array($error_msg), 'logs' => array());

            }

        } else {
            return $result_folder_create;
        }

    }

    public function formatImportExportFilePath($file_path)
    {

        $file_path = str_replace("\\", "\\\\", $file_path);
        $file_path = str_replace("/", "//", $file_path);
        return $file_path;
    }

    /**
     * Display queries debug messages in log file
     *
     * @param      <type>  $)      { parameter_description }
     */
    public function debugLog($custom_logs = array())
    {

        $import_debug = config('ajimportdata.debug');
        if ($import_debug == true) {
            foreach ($custom_logs as $value) {
                Log::info($value);
            }
        }
    }

}
