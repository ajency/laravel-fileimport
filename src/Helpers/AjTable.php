<?php
namespace Ajency\Ajfileimport\Helpers;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Log;
use Response;

/**
 * Read and set table schema( it also inserts type and size of of field
 * that should be added on temp table) , get primary/unique keys
 * Download table data as csv file
 * Before importing data to master or child table, Based on table structure of
 * master/child table validate each field by length or value in temporary table
 */
class AjTable
{

    private $table_name;
    private $fields;
    private $primary_key;
    private $indexes;
    private $exists = false;
    private $errors = [];
    

    public function getFormatedTableHeaderName($header)
    {

        return str_replace(' ', '_', $header);
    }

    public function __construct($table_name)
    {
        $this->table_name = $table_name;
        
    }

    public function doesTableExist()
    {

        $qry_table_exists = "SHOW TABLES LIKE '" . $this->table_name . "'";

        try {

            $table_exists = DB::select($qry_table_exists);

            if (is_array($table_exists) && count($table_exists) > 0) {
                $this->exists = true;
            } else {
                $this->errors[] = "Table '" . $this->table_name . "' does not exist.";
            }

            return $this->exists;

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();

            return $this->exists;

        }

    }

    public function getTableSchema()
    {
        return $this->fields;
    }

    public function setTableSchema()
    {
        $table_exists = $this->doesTableExist();

        if ($table_exists == false || count($this->errors) > 0) {

            echo "<br>Cannot proceed with import. Please correct the following errors first:<br>";
            $eror_cnt = 1;
            foreach ($this->errors as $key => $value) {
                echo "<br>" . $eror_cnt . ". " . $value;
            }

            return false;
        }

        $qry_table_schema = "EXPLAIN " . $this->table_name;

        try {

            $this->fields = DB::select($qry_table_schema);

            foreach ($this->fields as $key => $value) {

                $field_key              = $this->getFormatedTableHeaderName($value->Field);
                $new_fields[$field_key] = $this->setFieldSizeByTablestructure($value);

            }

            $this->fields = $new_fields;

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();
        }
    }

    /**
     *
     *
     * @param      <type>  $field  The field
     *
     * @return     <type>  The field length.
     */
    public function setFieldSizeByTablestructure($field)
    {
        $field_type_up = strtoupper($field->Type);

        if (strpos($field_type_up, 'TINYINT') !== false) {
            $field->minval           = -128;
            $field->maxval           = 127;
            $field->FieldType        = 'TINYINT';
            $field->tmp_field_type   = 'VARCHAR';
            $field->tmp_field_length = 50;

        } else if (strpos($field_type_up, 'SMALLINT') !== false) {

            $field->minval           = -32768;
            $field->maxval           = 32767;
            $field->FieldType        = 'SMALLINT';
            $field->tmp_field_type   = 'VARCHAR';
            $field->tmp_field_length = 50;

        } else if (strpos($field_type_up, 'MEDIUMINT') !== false) {
            $field->minval           = -8388608;
            $field->maxval           = 8388607;
            $field->FieldType        = 'MEDIUMINT';
            $field->tmp_field_type   = 'VARCHAR';
            $field->tmp_field_length = 50;

        } else if (strpos($field_type_up, 'INT') !== false) {
            $field->minval           = -2147483648;
            $field->maxval           = 2147483647;
            $field->FieldType        = 'INT';
            $field->tmp_field_type   = 'VARCHAR';
            $field->tmp_field_length = 50;

        } else if (strpos($field_type_up, 'BIGINT') !== false) {
            $field->minval           = -9223372036854775808;
            $field->maxval           = 9223372036854775807;
            $field->FieldType        = 'BIGINT';
            $field->tmp_field_type   = 'VARCHAR';
            $field->tmp_field_length = 50;

        }
        /* Float/double/decimal */

        else if (strpos($field_type_up, 'VARCHAR') !== false || strpos($field_type_up, 'CHAR') !== false) {
            $field_explode1        = explode("(", $field_type_up);
            $field_explode2        = explode(")", $field_explode1[1]);
            $field->maxlength      = $field_explode2[0];
            $field->fieldmaxlength = $field_explode2[0];

            if (strpos($field_type_up, 'VARCHAR') !== false) {
                $fieldtype = "VARCHAR";

                $field->tmp_field_type   = 'VARCHAR';
                $field->tmp_field_length = $field->maxlength + 50;

            } else if (strpos($field_type_up, 'CHAR') !== false) {
                $fieldtype = "CHAR";

                $field->tmp_field_type = 'VARCHAR';

                $field->tmp_field_length = (isset($field->maxlength) ? $field->maxlength : 0) + 50;
            }

            $field->FieldType = $fieldtype;
        } else if (strpos($field_type_up, 'TINYTEXT') !== false) {
            $field->maxlength      = 255;
            $field->FieldType      = 'TINYTEXT';
            $field->tmp_field_type = 'TEXT';

        } else if (strpos($field_type_up, 'TEXT') !== false) {
            $field->maxlength      = 65535;
            $field->FieldType      = 'TEXT';
            $field->tmp_field_type = 'LONGTEXT';

        } else if (strpos($field_type_up, 'LONGTEXT ') !== false) {
            $field->maxlength = 4294967295;
            $field->FieldType = 'LONGTEXT';

        } else {
            $field->FieldType        = $field_type_up;
            $field->tmp_field_type   = 'VARCHAR';
            $field->tmp_field_length = 250;
        }

        return $field;

    }

    public function getUniqFields()
    {

        $uniq_fields  = [];
        $table_schema = $this->getTableSchema();
        if (count($table_schema) <= 0) {
            $this->setTableSchema();
        }

        foreach ($table_schema as $child_field_name => $child_field_value) {
            if (isset($child_field_value->Key)) {

                if ($child_field_value->Key == "PRI" || $child_field_value->Key == "UNI") {

                    $uniq_fields[] = $child_field_value->Field;
                }
            }
        }
        /* End validating temp table for uniq field values*/

        return $uniq_fields;
    }

    public function downloadTableDataAsCsv()
    {

        $import_libs = new AjImportlibs();

        $file_prefix = "aj_" . $this->table_name;

        //$folder = storage_path('app/Ajency/Ajfileimport/mtable');

        $folder = $import_libs->getTempImportExportDirectory() . "/Ajency/";

        $import_libs->createDirectoryIfDontExists($folder);

        $child_outfile_name = $import_libs->generateUniqueOutfileName($file_prefix, $folder);

        //$file_path = str_replace("\\", "\\\\", $child_outfile_name);
        $file_path              = $import_libs->formatImportExportFilePath($child_outfile_name);

        $this->setTableSchema();
        $table_schema = $this->getTableSchema();

        foreach ($table_schema as $field_value) {
            $fields_names_ar[] = $field_value->Field;
        }

        try {

            $qry_select_valid_data = "SELECT '" . implode("', '", $fields_names_ar) . "'  ";

            $qry_select_valid_data .= " UNION ALL ";

            $qry_select_valid_data .= " SELECT `" . implode("`, `", $fields_names_ar) . "`   ";

            $qry_select_valid_data .= " INTO OUTFILE '" . $file_path . "'
                                    FIELDS TERMINATED BY ','
                                    OPTIONALLY ENCLOSED BY '\"'
                                    ESCAPED BY ''
                                    LINES TERMINATED BY '\n'
                                    FROM `" . $this->table_name . "`  outtable  ";

            

            $this->debugLog(array('<br/> \n  downloadTableDataAsCsv  :----------------------------------',"filepath" . $file_path,$qry_select_valid_data));

            DB::select($qry_select_valid_data);
            $headers = array('Content-Type' => 'text/csv');

            return response()->download($file_path, $this->table_name . date("_d_m_Y_H_i_s") . '.csv', $headers);

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();

        }

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
