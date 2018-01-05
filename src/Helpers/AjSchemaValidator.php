<?php
/**
 * Class to validate the csv data imported to temporary table, based on schema of tables in configuration file
 */

namespace Ajency\Ajfileimport\Helpers;

use Illuminate\Support\Facades\DB;
use Log;

class AjSchemaValidator
{

    private $table_name;
    private $fields;
    private $primary_key;
    private $indexes;
    private $exists = false;
    private $errors = [];
    /**
     *
     * For a table,
     * Update the records on temp table with error on following :--
     * select records from temporary table whose field value are empty and which should be non null in the main table.
     * select records from temporary table whose field value not integer and which should be integer in the main table.
     * select records from temporary table whose field value not double/float and which should be double/float number in the main table.
     * select records from temporary tables  whose field value are having no of characters greater than the number of characters for corresponding field on main table
     * select records from the temporary tables whose fields should be unique, and there are multiple records with same value.
     */

    /*
     *Before importing data to master or child table, Based on table structure of master/child table validate each field by length or value in temporary table
     */

    public function __construct($tablename)
    {
        $this->table_name = $tablename;
        register_shutdown_function(array($this, '__destruct'));

    }
    public function __destruct()
    {

    }

    public function validateField($field_name, $field_data, $loop_count)
    {

        $qry_index = "ALTER TABLE " . $this->table_name . ". ADD INDEX (" . $field_name . ")";

        try {
            DB::statement($qry_index);

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.

            $this->errors[] = $ex->getMessage();
        }

        $batchsize = config('ajimportdata.batchsize');

        $limit = $loop_count * $batchsize;

        $this->validateFieldLength($field_name, $field_data, $limit, $batchsize);
        $this->validateFieldType($field_name, $field_data, $limit, $batchsize);
    }

    public function validateFieldLength($field_name, $field_data, $limit, $batchsize)
    {

        $this->debugLog(array('update query start'));
        $temp_table_name = $this->table_name;

        if (!isset($field_data->maxlength) && !isset($field_data->maxvalue)) {
            return;
        }

        $qry = "UPDATE " . $this->table_name . " SET  aj_error_log = ( CASE ";

        $error_length = ", Length exceeds the field length";
        $error_value  = ", value of field exceeds max value";

        $maxlength_cond       = " length(" . $field_name . ") > " . $field_data->maxlength;
        $maxvalue_cond        = isset($field_data->maxvalue) ? " " . $field_name . "  > " . $field_data->maxvalue : "";
        $errorlog_isnull_cond = " (aj_error_log IS NULL  or aj_error_log ='' or aj_error_log='NULL') ";

        if (isset($field_data->maxlength) && isset($field_data->maxvalue)) {

            $qry .= " WHEN  " . $maxlength_cond . " AND " . $maxvalue_cond . " AND  " . $errorlog_isnull_cond . "  THEN  '" . $error_length . $error_value . "'
                      WHEN  " . $maxlength_cond . " AND " . $errorlog_isnull_cond . "  THEN  '" . $error_length . "'
                      WHEN  " . $maxvalue_cond . " AND " . $errorlog_isnull_cond . " THEN '" . $error_value . "
                      END ) ";

            $qry2 = ", aj_isvalid = CASE WHEN length(" . $field_name . ") > " . $field_data->maxlength . " THEN  'N' ELSE ''   END ";

        } else if (isset($field_data->maxlength)) {

            $qry .= " WHEN  " . $maxlength_cond . " AND " . $errorlog_isnull_cond . "  THEN  '" . $error_length . "'
                                    ELSE  CONCAT(aj_error_log,'" . $error_length . "')

                      END ) ";

            $qry2 = ", aj_isvalid = CASE WHEN length(" . $field_name . ") > " . $field_data->maxlength . " THEN  'N'  ELSE ''   END ";

        }

        if (isset($field_data->maxvalue)) {

            $qry .= " WHEN  " . $maxvalue_cond . " AND " . $errorlog_isnull_cond . " THEN '" . $error_length . "'
                                ELSE  CONCAT(aj_error_log,'" . $error_value . "')
                    END ) ";
            $qry2 = ", aj_isvalid = CASE WHEN length(" . $field_name . ") > " . $field_data->maxlength . " THEN  'N' ELSE ''  END ";
        }

        $qry .= $qry2;

        /*$qry .= " WHERE aj_isvalid!='N'  AND id in (SELECT id FROM(SELECT id FROM " . $temp_table_name . " ttb2  ORDER BY ttb2.id ASC LIMIT " . $limit . ", " . $batchsize . ")  ttb2 )";*/
        $temp_table_ids_by_batch = $this->getTempTableIdsByBatch($limit, $batchsize);

        $qry .= " WHERE aj_isvalid!='N'  AND id in (" . $temp_table_ids_by_batch . ")";

        //echo "<br/>" . $qry;

        try {

            $this->debugLog(array('<br/> \n update query  :----------------------------------', $qry));

            $update_res = DB::update($qry);

            unset($update_res);

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.

            $this->errors[] = $ex->getMessage();

        }
        unset($qry);
        unset($$qry2);

    }

    public function validateFieldType($field_name, $field_data)
    {
        $temp_table_name = $this->table_name;

        $qry = "";

        if ($field_data->FieldType == "INT") {
            $error['int'] = "Warning: #1366 Incorrect integer value: for column '" . $field_name . "' ";
            $qry          = "UPDATE " . $temp_table_name . " SET aj_error_log = '" . $error['int'] . "'  WHERE " . $field_name . " NOT REGEXP '^[0-9]+$'";
        } else if ($field_data->FieldType == "DATETIME") {
            $error['datetime'] = "Warning: #1265 Data truncated for column '" . $field_name . "'";
            $qry               = "UPDATE " . $temp_table_name . " SET aj_error_log = '" . $error['datetime'] . "'  WHERE TO_DAYS(STR_TO_DATE(" . $field_name . ", '%d-%b-%Y')) IS NULL  ";
        }

        if ($qry != "") {
            try {
                DB::update($qry);

            } catch (\Illuminate\Database\QueryException $ex) {

                // Note any method of class PDOException can be called on $ex.

                $this->errors[] = $ex->getMessage();
            }

        }

    }

    /**
     * Validate temp table for the fields having unique  values
     * If multiple records exists with the same field value in temp table, will be marked as invalid row
     *
     * @param      string  $field_name  The field name
     */
    public function validatePrimaryUnique($field_name, $params)
    {

        $childtable_name  = $params['childtable']['name'];
        $temp_table_name  = $this->table_name;
        $uniq_field_error = " Multiple Values for the unique field '" . $field_name . "' in the data provided for child table '" . $childtable_name . "'";

        $qry_validate_uniq = "UPDATE " . $temp_table_name . " SET aj_error_log='" . $uniq_field_error . "', aj_isvalid = 'N' WHERE " . $field_name . " IN (SELECT " . $field_name . " FROM (SELECT tt1." . $field_name . " as " . $field_name . "  FROM " . $temp_table_name . " tt1  group by " . $field_name . " having count(" . $field_name . ")>1) tt2)";

        $this->debugLog(array("-------------ValidatePrimaryUnique----------------", $qry_validate_uniq));

        try {

            DB::update($qry_validate_uniq);

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.

            $this->errors[] = $ex->getMessage();

            /*Log::info($ex->getMessage());
        Log::info($ex);
        Log::info("*********************************************************************");*/
        }
        unset($qry_validate_uniq);
    }

    public function getTempTableIdsByBatch($limit, $batchsize)
    {

        $temp_tablename = config('ajimportdata.temptablename');

        try {

            /* $qry_comma_seperated_temp_ids = "SELECT GROUP_CONCAT(id) as concat_ids FROM (SELECT id FROM " . $temp_tablename . " tt ORDER BY tt.id ASC LIMIT " . $limit . "," . $batchsize . ")  tt2 ";

            Log:info($qry_comma_seperated_temp_ids);
            $res_comma_seperated_temp_ids = DB::select($qry_comma_seperated_temp_ids);

            return $res_comma_seperated_temp_ids[0]->concat_ids;*/

            //No GROUP_CONCAT because of string limit
            $qry_comma_seperated_temp_ids = "SELECT id as concat_id FROM (SELECT id FROM " . $temp_tablename . " tt ORDER BY tt.id ASC LIMIT " . $limit . "," . $batchsize . ")  tt2 ";

            Log:info($qry_comma_seperated_temp_ids);
            $res_comma_seperated_temp_ids   = DB::select($qry_comma_seperated_temp_ids);
            $count_comma_seperated_temp_ids = count($res_comma_seperated_temp_ids);
            if ($count_comma_seperated_temp_ids > 0) {
                for ($cnt = 0; $cnt < $count_comma_seperated_temp_ids; $cnt++) {
                    $temp_table_ids[] = $res_comma_seperated_temp_ids[$cnt]->concat_id;
                }

            }

            $temp_table_concat_ids = implode(",", $temp_table_ids);

            return $temp_table_concat_ids;

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();

        }

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
