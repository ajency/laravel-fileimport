<?php

/**
 * Ajency Laravel CSV Import
 * File read and validations
 */

namespace Ajency\Ajfileimport\Helpers;

use Illuminate\Support\Facades\File;
use Log;

class FileHandler
{

    private $file_path;
    private $delimiter;
    private $file_type;
    private $header_count_match = true;
    private $header_matched     = true;
    private $file_data_exists   = false;
    private $errors             = [];
    private $logs               = [];
    private $msg                = '';
    private $headers            = [];

    public function __construct($param = array())
    {
        if (isset($param['filepath'])) {
            $this->file_path = $param['filepath'];
        }
        register_shutdown_function(array($this, '__destruct'));

    }
     public function __destruct()
    {

    }
    public function getErrors()
    {
        return $this->errors;
    }

    public function getLogs()
    {
        return $this->logs;
    }

    public function getMsg()
    {
        return $this->msg;
    }

    public function getErrorsLogsMsg()
    {

        $data = array("errors" => $this->errors, "logs" => $this->logs, "msg" => $this->msg);
        return $data;
    }

    /**
     * Stores uploaded file in storage folder, which will then be used for import process
     *
     * @param      <type>   $request  The request
     *
     * @return     string  ( path of uploade file )
     */
    public function storeFile($request)
    {

        if (is_null($request->file('ajfile'))) {
            //echo "Please select file.";
            $this->errors[] = "Please select file.";
            return false;
        }

        $temp_path = $request->file('ajfile')->getRealPath();

        if (!file_exists($temp_path)) {
            //echo "Please select file.";
            $this->errors[] = "Please select file.";
            return false;
        }

        $file_extension = $request->file('ajfile')->getClientOriginalExtension(); //File::extension($temp_path);

        $config_file_type = config('ajimportdata.filetype');

        if ($config_file_type != $file_extension) {
            $this->errors[] = "File extension not supported for import. Please try uploading file of type '" . $config_file_type . "'";
            return false;
        }

        $new_file_name = 'ajimportfile' . date('d_m_Y_H_i_s') . '.csv';
        $folder        = storage_path('app/Ajency/Ajfileimport/Files/');

        $import_libs = new AjImportlibs();

        $import_libs->createDirectoryIfDontExists($folder);

        $this->file_path = $folder . $new_file_name;

        $request->file('ajfile')->storeAs('Ajency/Ajfileimport/Files', $new_file_name);

        return $this->file_path;

    }

    public function getFilePath()
    {
        return $this->file_path;
    }

    public function getFileHeaders()
    {
        return $this->headers;
    }

    /**
     * Check if the file contains Headers,
     * Returns true if file header matches with the headers provided in configuratio file
     */
    public function isValidFile()
    {

        $file_type = config('ajimportdata.filetype');

        //echo "File Type : "$file_type;
        switch ($file_type) {

            case 'csv':

                $this->normalizeLineEndings();

                $config_fileheaders       = config('ajimportdata.fileheader');
                $file_headers             = $this->read_csv_file_headers();
                $config_fileheaders_count = count($config_fileheaders);
                $file_headers_count       = count($file_headers);
                $is_data_exists           = $this->checkIfRecordsExists();

                /*print_r($config_fileheaders);
                echo " config header count :". $config_fileheaders_count . "-- File Header count:" . $file_headers_count;*/
                if ($config_fileheaders_count != $file_headers_count) {
                    $this->errors[]           = "Error: Header count mismatched <br/> File Headers count: " . $file_headers_count . " <br/> Config file header count:" . $config_fileheaders_count;
                    $this->header_count_match = false;
                    break;
                } else {

                    $this->logs[] = "Header count matched ";
                }

                if ($is_data_exists == false) {
                    $this->errors[]         = "<br/>Error:  File contains no records to insert";
                    $this->file_data_exists = false;
                    break;
                }

                $header_count          = count($config_fileheaders);
                $header_mismatch_count = 0;

                $headers_mismatch_fields = [];
                for ($i = 0; $i < $header_count; $i++) {
                    if ($config_fileheaders[$i] != $file_headers[$i]) {
                        $header_mismatch_count++;
                        $headers_mismatch_fields[] = $header_mismatch_count . ") " . $config_fileheaders[$i] . "     :    " . $file_headers[$i];
                        $this->header_matched      = false;
                        //break;
                    }

                }

                if (count($headers_mismatch_fields) > 0) {
                    $this->errors[] = "<br/> <b>Error: File Headers mismatched with the configuration for the following <br/> Config Headers     :    File Headers <br/></b>" . implode("<br/> ", $headers_mismatch_fields);
                }

                $this->headers = $config_fileheaders;

                break;

            default:
                $this->errors[] = 'Invalid file type configured';
                break;

        }

        if ($this->header_matched == true) {
            $this->logs[] = "Headers matched with the configuration!!";
        }

        $success = true;

        if (count($this->errors) > 0) {
            $success = false;
        }

        return $success;

    }

    public function read_csv_file_headers($args = array())
    {

        $file_path = $this->file_path;

        $row = 1;
        if (($file_handle = fopen($file_path, "r")) !== false) {
            while (($data = fgetcsv($file_handle, 20000, ",")) !== false) {
                if ($row == 1) {
                    $headers = $data;
                }

                $row++;
                if ($row == 2) {
                    break;
                }
            }
        }
        fclose($file_handle);

        return $headers;

    }

    /**
     * Replaces all line endings with '\n' in the provided file
     * @return     string  path of file
     */
    public function normalizeLineEndings()
    {
        $file_path = $this->file_path;

        $file_content = @file_get_contents($file_path);

        if (!$file_content) {
            return $file_path;
        }

        //normalize the all types of line ending to \n
        $file_content = preg_replace('~\r\n?~', "\n", $file_content);

        file_put_contents($file_path, $file_content);

        return $file_path;
    }

    public function checkIfRecordsExists($args = array())
    {

        $file_path      = $this->file_path;
        $data_row_found = false;

        $row = 1;
        if (($file_handle = fopen($file_path, "r")) !== false) {
            while (($data = fgetcsv($file_handle, 20000, ",")) !== false) {
                if ($row == 1) {
                    $headers = $data;
                }

                if ($row > 1) {
                    foreach ($data as $value) {
                        Log::info('==================================' . $row . ' - check_if_records_exists=======================================');
                        if (trim($value) != '') {
                            $data_row_found = true;
                            break;
                        }
                    }
                }
                $row++;
            }
        }
        fclose($file_handle);

        return $data_row_found;

    }

    /*public function is_directory_exists($filepath)
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

public function createDirectoryIfDontExists($filepath)
{

if (!$this->is_directory_exists($filepath)) {
File::makeDirectory($filepath, 0777, true, true);
}

}*/
}
