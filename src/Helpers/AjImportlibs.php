<?php /**
 * Ajency Laravel CSV Import Package Additional Library
 */
namespace Ajency\Ajfileimport\Helpers;

use Ajency\Ajfileimport\Mail\AjSendMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use \Mail;

/**
 * Class for aj importlibs.
 */
class AjImportlibs
{

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

    public function createDirectoryIfDontExists($filepath)
    {

        if (!$this->is_directory_exists($filepath)) {
            File::makeDirectory($filepath, 0775, true, true);
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

        Mail::to($params['recipient'])->send(new AjSendMail($params));

        //
        /*Mail::raw('sendDailyProjectMailsToProfile-'.env('APP_ENV'), function ($message) {

    $message->from(env('MAIL_FROM'), env('MAIL_FROM_NAME'));
    $message->to('paragredkar@ajency.in','paragredkar')->subject('daily project mail');
    });*/

    }

    public function getMysqlTempDirectory()
    {

        $qry_get_mysql_temp_directory = "SHOW VARIABLES LIKE 'secure_file_priv'";
        $res_get_mysql_temp_directory = DB::select($qry_get_mysql_temp_directory);
        foreach ($res_get_mysql_temp_directory as $res_v) {
            return str_replace("\\","\\\\", $res_v->Value);
        }

    }

    public function createTestImportFolder()
    {

        $mysql_temp_dir = $this->getMysqlTempDirectory();

        if ($mysql_temp_dir == "" || is_null($mysql_temp_dir)) {
            return array('result' => false, 'errors' => 'Mysql Temp directory is not set. Cannot proceed with import');
        }

        $ajency_folder = $this->getMysqlTempDirectory() . "/Ajency";

        if (!$this->is_directory_exists($ajency_folder)) {

            $this->createDirectoryIfDontExists($ajency_folder);
        }

        if ($this->is_directory_exists($ajency_folder)) {

            $file_prefix           = "aj_test_file_create_";

            $test_export_file_path = $this->generateUniqueOutfileName($file_prefix, $ajency_folder);

            $file_path = str_replace("\\", "\\\\", $test_export_file_path);

            $qry_test = "SELECT  test_id, test_name INTO OUTFILE '" . $file_path . "'
                                    FIELDS TERMINATED BY ','
                                    OPTIONALLY ENCLOSED BY '\"'
                                    LINES TERMINATED BY '\n'";
            try {

                Log:info($qry_test);
                DB::select($qry_test);

                if (!File::exists($test_export_file_path)) {
                    return array('result' => false, 'errors' => array("'" . $ajency_folder . "' Folder does not have write permission. Cannot proceed with import"));
                    } else {
                        return array('result' => true,'errors' =>false);
                    }

                } catch (\Illuminate\Database\QueryException $ex) {

                    return array('result' => false, 'errors' => array($ex->getMessage()));

                }

            }
            else {
                return array('result'=>false ,'errors'=>array('Directory is not writable') ) ;
            }



             
        }

    }
