<?php
/**
 * Ajency Laravel CSV Import Package
 * Note : To be used for tables with field names without spaces
 * Read wiki "https://github.com/ajency/laravel-fileimport/wiki" for details and usage
 * To do :-
 * a) Support to define multiple import congifuration sets,
 * and option to select one of the defined configuration while doing import
 *
 */
namespace Ajency\Ajfileimport\Helpers;

use Ajency\Ajfileimport\Helpers\AjImportlibs;
use Ajency\Ajfileimport\Helpers\AjSchemaValidator;
use Ajency\Ajfileimport\Helpers\AjTable;
//Added to schedule the job queue
use Ajency\Ajfileimport\jobs\AjImportDataJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB; //files storage for import
use Illuminate\Support\Facades\File;
use Log;

/**
 * Class for aj csv file import.
 */
class AjCsvFileImport
{

    private $temp_table_headers;
    private $file_path;
    private $errors = [];
    private $childtables_conf;
    private $temptable_fields;
    private $logs = [];
    private $messages;

    public function __construct($file_path = "")
    {
        if ($file_path != "") {
            $this->file_path = $file_path;
        }

    }

    public function fileuploadform()
    {

        $loader_gif = realpath(__DIR__ . '..\..\assets\images\loader.gif');
        $data       = array('loader_gif' => $loader_gif);
        return view('ajfileimport::index')->with($data);
    }

    public function init($request)
    {

        $import_libs = new AjImportlibs();

        $this->msg               = "<br/> Checking the file permissions ....";
        $result_file_permissions = $import_libs->createTestImportFolder();

        if (count($result_file_permissions['errors']) > 0) {
            $this->set_ajx_return_logs($result_file_permissions);
            return response()->json($this->ajx_return_logs());
        }

        $this->msg = "<br/> Checking for pending FileImport pending jobs ...";

        $prev_pending_jobs = $this->areTherePreviousJobsPending();

        if ($prev_pending_jobs === true) {

            $this->msg .= " - Error";
            $this->logs[] = $this->msg;
            $this->msg    = "";
            return $this->ajx_return_logs();

        } else {
            $this->msg .= " - Passed";
        }

        $this->logs[] = $this->msg;

        $this->msg = "<br/> Checking if tables from configuration file exists in database....";
        $result    = $this->checkIfAllConfigTablesExists();

        if ($result == true) {

            $this->msg .= " Passed ";
            $this->logs[] = $this->msg;

        } else {
            //$import_libs->printLogs($this->getErrorLogs());
            $this->msg .= " Failed ";
            $this->logs[] = $this->msg;
            $this->msg    = "";
            return response()->json($this->ajx_return_logs());

        }

        $this->msg = "";

        $res = $this->clearPreviousImportFiles();

        $file_handle = new FileHandler();
        $result      = $file_handle->storeFile($request);
        if ($result == false) {

            $params['logs']   = $file_handle->getLogs();
            $params['errors'] = $file_handle->getErrors();
            $params['msg']    = $file_handle->getMsg();
            $this->set_ajx_return_logs($params);

            return response()->json($this->ajx_return_logs());
        }

        $file_path = $file_handle->getFilePath();

        $this->setFilePath($file_path);
        $this->importFileData($file_handle);

        return response()->json($this->ajx_return_logs());

    }

    public function getErrorLogs()
    {
        return $this->errors;
    }

    public function ajx_return_logs()
    {

        return array('logs' => $this->logs, 'errors' => $this->errors, 'msg' => $this->msg);
    }

    public function set_ajx_return_logs($params)
    {

        if (isset($params['logs'])) {

            $this->logs = array_merge($this->logs, $params['logs']);
        }

        if (isset($params['errors'])) {
            $this->errors = array_merge($this->errors, $params['errors']);
        }

        if (isset($params['msg'])) {
            $this->msg = $this->msg . $params['msg'];
        }

    }

    public function areTherePreviousJobsPending()
    {

        try {
            $res_pending_job_count = DB::select("SELECT count(*) as pending_job_count FROM aj_import_jobs WHERE queue in ('validateunique','insert_records')");

            Log:info("SELECT count(*) as pending_job_count FROM aj_import_jobs WHERE queue in ('validateunique','insert_records')");
            $pending_job_count = $res_pending_job_count[0]->pending_job_count;

            if ($pending_job_count > 0) {
                $this->errors[] = "There are pending jobs from previous import to be processed!! <br/> Please run job queue <b>'php artisan queue:work --queue=validateunique,insert_records ajfileimportcon'</b>";
                return true;
            } else {

                return false;
            }

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();
        }
    }

    /**
     * clear the files from import folders
     */
    public function clearPreviousImportFiles()
    {

        $folder_to_clean[] = storage_path('app/Ajency/Ajfileimport/validchilddata');
        $folder_to_clean[] = storage_path('app/Ajency/Ajfileimport/mtable');
        $folder_to_clean[] = storage_path('app/Ajency/Ajfileimport/Files');

        foreach ($folder_to_clean as $folder) {
            $result = File::cleanDirectory($folder);
        }
    }

    public function setFilePath($file_path)
    {
        $this->file_path = $file_path;
    }

    public function getFilePath()
    {
        return $this->file_path;
    }

    /**
     * Get the configuration added for tables.
     * Get the field names cleaned. (replace space in field names with underscores)
     * Store it in class child configuration variable
     */
    public function setChildTableConf()
    {
        $childtables_conf = config('ajimportdata.childtables'); //Get child table from config
        foreach ($childtables_conf as $childtable) {

            $new_fields_maps = [];

            if (isset($childtable['fields_map'])) {
                foreach ($childtable['fields_map'] as $key => $value) {
                    $new_fields_maps[str_replace(' ', '_', $key)] = str_replace(' ', '_', $value);
                }

            }

            $childtable['fields_map'] = $new_fields_maps;

            $new_childtables_conf[] = $childtable;

        }

        $this->childtables_conf = $new_childtables_conf;

    }

    /**
     * Gets the child table conf(spaces in fields names from configuration
     *   files were replaced with underscores).
     * @return     array  The child table conf.
     */
    public function getChildTableConf()
    {
        return $this->childtables_conf;
    }

    /**
     * gets file header configuration added in config file and stores it.
     * Any space charater in header name will be replaced by underscore.
     */
    public function setFileHeaderConf()
    {

        $fileheaders_conf = config('ajimportdata.fileheader'); //Get file headers

        foreach ($fileheaders_conf as $header) {
            $new_header_name   = $this->getFormatedTableHeaderName($header);
            $new_header_conf[] = $new_header_name;

        }

        $this->fileheaders_conf = $new_header_conf;
    }

    public function getFileHeaderConf()
    {
        return $this->fileheaders_conf;
    }

    public function getFormatedTableHeaderName($header)
    {

        return str_replace(' ', '_', $header);
    }

    public function getFormatedFieldName($field_name)
    {

        return str_replace(' ', '_', $field_name);
    }

    /**
     * check for validations of file, and does configuration checks.
     * Creates temporary table and imports the csv file data in the table
     * @param      <type>  $file_handle  The file handle
     */
    public function importFileData($file_handle)
    {

        $result_loadfile = $this->validateFile($file_handle);
        if ($result_loadfile === false) {
            return $result_loadfile;
        }

        $temp_tablename     = config('ajimportdata.temptablename');
        $res_table_creation = $this->createTempTable();

        if ($res_table_creation['success'] == false) {
            /*foreach ($res_table_creation['errors'] as $key => $error) {
            echo "<br/>" . $error;
            }*/
            return false;
        }

        $real_file_path = $this->getFilePath();

        $file_headers = $this->getFileHeaderConf(); //$file_handle->getFileHeaders();
        $this->loadFiledatainTempTable($real_file_path, $file_headers, $temp_tablename);
        //$this->insertUpdateChildTable(); //VALIDATING CHILD TABLE FIELDS
    }

    public function validateFile($file)
    {
        $import_libs = new AjImportlibs();

        DB::connection()->disableQueryLog();

        $this->msg = "<br/>Validating file....";
        $result    = $file->isValidFile();

        if ($result !== true) {

            if (count($file->getErrors()) >= 0) {

                // array_merge($this->errors, $file->getErrors());
                $this->set_ajx_return_logs($file->getErrorsLogsMsg());

            } else {
                //echo "Invalid File.";
                $this->msg .= " - Invalid File.";
                $this->errors[] = $this->msg;
            }

            return false;

        } else {

            /*if (count($file->getLogs()) >= 0) {

            $import_libs->printLogs($file->getLogs());
            }*/
            $this->msg .= " - Valid File.";
            $this->logs[] = $this->msg;
            $this->msg    = "";
            return true;
        }

    }

    /**
     * creates the query part of temp table creation, where fields type/sizes, and indexes on fields are
     * added in query for temp table,
     * Match with child/master table field and get the temp table field in query
     * temp table field type and sizes are set on mastertable/child table  object of class, when
     * setTableschema is called on the ajtable object of class
     * @param      <type>   $mastertable_conf  The mastertable conf
     * @param      <type>   $mastertable       The mastertable
     * @param      boolean  $is_child          Indicates if child
     *
     * @return     string   ( description_of_the_return_value )
     */
    public function tempTableQueryByTable($mastertable_conf, $mastertable, $is_child)
    {

        /* OVERRIDE NULLABLE SET ON CHILD TABLE FIELD RULE, WHILE CREATING TEMP TABLE FIELD FOR SAME FIELD*/
        /*$mandatary_tmp_tblfields_ar = array();

        if (!is_null(config('ajimportdata.mandatary_tmp_tblfields'))) {
        $mandatary_tmp_tblfields_ar = config('ajimportdata.mandatary_tmp_tblfields'); //Get manadatary fields on temp table
        }*/

        $mtable_fieldmaps = $mastertable_conf['fields_map'];

        $qry__create_table = "";

        $mtable_fields = $mastertable->getTableSchema();

        $mtable_flipped_fieldmaps = array_flip($mastertable_conf['fields_map']);

        /* echo "<br/><br/><br/><br/><pre>============================== CONGIGURATION: ";

        print_r($mastertable_conf);
        echo "<br/>-------------- FIELDMAP FLIPPED: ";
        print_r($mtable_flipped_fieldmaps);

        echo "<br/> schema field of table:";
        print_r($mtable_fields);*/

        foreach ($mtable_flipped_fieldmaps as $mfield_key => $mfield_value) {

            $temptable_fields = [];

            $mfield_key = $mastertable->getFormatedTableHeaderName($mfield_key);

            $tfield_name = $this->getFormatedTableHeaderName($mfield_value);

            $cur_mtable_field = $mtable_fields[$mfield_key];

            if (!isset($this->temptable_fields[$tfield_name])) {

                $temptable_fields['Field'] = $tfield_name;

                $qry__create_table .= ", ";
                $qry__create_table .= "`" . $tfield_name . "` " . $cur_mtable_field->tmp_field_type;

                $temptable_fields['Type'] = $cur_mtable_field->tmp_field_type;

                if (isset($cur_mtable_field->tmp_field_length)) {

                    $qry__create_table .= "(" . $cur_mtable_field->tmp_field_length . ")";
                    $temptable_fields['maxlength'] = $cur_mtable_field->tmp_field_length;
                }

                if ($cur_mtable_field->Null == true) {

                    /* OVERRIDE NULLABLE SET ON CHILD TABLE FIELD RULE, WHILE CREATING TEMP TABLE FIELD FOR SAME FIELD*/
                    /*if (in_array($tfield_name, $mandatary_tmp_tblfields_ar)) {
                    $qry__create_table .= " NOT NULL ";
                    } else {
                    $qry__create_table .= " DEFAULT NULL ";
                    }*/

                    $qry__create_table .= " DEFAULT NULL ";

                    $temptable_fields['Null'] = 'YES';
                }

                if (isset($cur_mtable_field->Default)) {

                    $qry__create_table .= " DEFAULT " . $cur_mtable_field->Default;
                    $temptable_fields['Null'] = 'YES';
                }

                //if child table create index on the field
                //Do not create index for data types LONGTEXT,BLOB

                if (strpos($cur_mtable_field->tmp_field_type, 'TEXT') == false && strpos($cur_mtable_field->tmp_field_type, 'BLOB')
                    && $is_child == true) {
                    $qry__create_table .= ", INDEX `" . $tfield_name . "` (`" . $tfield_name . "` )";
                }

                //$mfield_data = $mastertable_fields[$mfield_key];
                //print_r($mtable_fields[$mfield_key]);
                $this->temptable_fields[$tfield_name] = $temptable_fields;

            }

        }

        return $qry__create_table;
    }

    public function getTempTableDefaultFields()
    {

        $qry_temp_default_fields = '';
        $temtable_default_fields = config('ajimportdata.temptable_default_fields'); //Get file headers

        $default_temp_field_cnt = count($temtable_default_fields);

        if (count($default_temp_field_cnt) > 0) {
            foreach ($temtable_default_fields as $key => $value) {
                $qry_temp_default_fields .= ", `" . $key . "`  VARCHAR(250) NOT NULL DEFAULT '" . $value . "' ";
            }
        }

        return $qry_temp_default_fields;
    }

    public function createTempTable()
    {

        $this->setChildTableConf();
        $this->setFileHeaderConf();

        $temp_table_indexes = array();

        $fileheaders_conf = $this->getFileHeaderConf(); //config('ajimportdata.fileheader'); //Get file headers
        //
        $mastertable_conf = config('ajimportdata.mastertable'); //Get file headers

        $childtables_conf = $this->getChildTableConf(); //config('ajimportdata.childtables'); //Get child table from config

        $temp_table_name = config('ajimportdata.temptablename'); //Get temp table name from config

        $this->deleteTable($temp_table_name);

        $qry__create_table = "CREATE TABLE IF NOT EXISTS " . $temp_table_name . " (
                                    `id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY";

        $qry_childtable_insert_ids = "";

        $qry_indexes = "";
        $qry_slug    = "";

        $child_count = 0;
        foreach ($childtables_conf as $child_data) {
            $is_child_table           = true;
            $childtable[$child_count] = new AjTable($child_data['name']);
            $childtable[$child_count]->setTableSchema();
            $qry__create_table .= $this->tempTableQueryByTable($child_data, $childtable[$child_count], $is_child_table);

            if (isset($child_data['insertid_temptable'])) {
                //if (isset($child_data['insertid_mtable'])) {

                $temp_child_insert_id_arr = array_keys($child_data['insertid_temptable']);
                $temp_child_insert_id     = $temp_child_insert_id_arr[0];

                $temptablefield_for_child_insertid = $this->getFormatedFieldName($temp_child_insert_id);
                if (!isset($this->temptable_fields[$temptablefield_for_child_insertid])) {

                    $this->temptable_fields[$temptablefield_for_child_insertid] = array(
                        'Field' => $temptablefield_for_child_insertid,
                        'Type'  => 'INT',
                    );

                    $qry_childtable_insert_ids .= " ," . $temptablefield_for_child_insertid . " INT ";
                    $qry_indexes .= ", INDEX USING BTREE(" . $temptablefield_for_child_insertid . ")";

                    $temp_table_indexes[] = $temptablefield_for_child_insertid;
                }

            }

            if (isset($child_data['field_slug'])) {

                foreach ($child_data['field_slug'] as $key_slug => $value_slug) {
                    $qry_slug .= "`" . $value_slug . "` VARCHAR (250)  DEFAULT NULL ";
                }
            }

        }

        $config_indexes_result = $this->addConfigFieldIndexesOnTempTable($temp_table_indexes);
        $temp_table_indexes    = array_merge($temp_table_indexes, $config_indexes_result['index_fields']);
        $qry_indexes .= $config_indexes_result['index_query'];

        $qry_unique_fields = $this->addConfigUniqueFieldOnTempTable();

        /*echo "*************************";
        var_dump($this->temptable_fields);
        echo "*************************";*/

        //Check if corresponding header fields are considered on temp table
        foreach ($fileheaders_conf as $header) {

            if (!isset($this->temptable_fields[$header]) && strtolower($header) != 'id') {

                $tfield_name = $header;

                $temptable_fields['Field'] = $tfield_name;

                $qry__create_table .= ", ";
                $qry__create_table .= "`" . $tfield_name . "` VARCHAR (250)  DEFAULT NULL ";

                $temptable_fields['Type'] = "VARCHAR";

                $temptable_fields['maxlength'] = 250;
                $temptable_fields['Null']      = 'YES';

            }

        }

        $qry__create_table .= $qry_childtable_insert_ids;

        $qry__create_table .= $this->getTempTableDefaultFields();

        $qry__create_table .= ", `aj_error_log`  LONGTEXT   ";
        $qry__create_table .= ", `aj_isvalid`  CHAR(1) NOT NULL DEFAULT '' ";
        $qry__create_table .= ", `aj_processed`  CHAR(1) NOT NULL DEFAULT 'n' ";
        $qry__create_table .= ", `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ";
        $qry__create_table .= $qry_indexes;
        $qry__create_table .= $qry_unique_fields;
        $qry__create_table .= " )  ENGINE=InnoDB;";
        //dd($qry__create_table);

        Log::info("<pre>" . $qry__create_table);
        $success = false;
        $message = "";

        try {
            Log::info("<br/><br/>Creating 'Temp table' .....");
            //echo $qry__create_table;
            //echo qry__create_table;
            $create_table_result = DB::statement($qry__create_table);
            Log::info(($create_table_result));
            if ($create_table_result === true) {
                $message = "Temp table' created successfully!!";
                $success = true;
            }

        } catch (\PDOException $ex) {

            // Note any method of class PDOException can be called on $ex.

            $this->errors[] = $ex->getMessage();

            Log::info($this->errors);
            $success = false;
        }

        return array("success" => $success, 'errors' => $this->errors, 'message' => $message);

    }

    /*//Read the config and build array by key(field insert id to update) and child tables conf
    public function getSortedTempTableChildIdUpdateConfig()
    {

    $tables_to_update_temp                      = config('ajimportdata.tables_to_update_temp');
    $temp_field_ids_to_update_by_existing_child = [];

    foreach ($tables_to_update_temp as $key => $value) {

    $tmp_field_toupdate_arr = array_keys($value['insertid_temptable']);

    $temp_field_ids_to_update_by_existing_child[$tmp_field_toupdate_arr[0]][] = array(
    'tablename'                                       => $value['name'],
    'fields_map_to_update_temptable_child_id'         => $value['fields_map_to_update_temptable_child_id'],
    'default_fields_map_to_update_temptable_child_id' => $value['default_fields_map_to_update_temptable_child_id'],

    )
    }

    $this->temptbl_fields_to_update_by_childid_conf = $temp_field_ids_to_update_by_existing_child;

    }

    public function updateTempTableIdFieldsWithExistingRecords($tmpfield_to_update, $params)
    {

    $import_libs      = new AjImportlibs();
    $child_table_conf = $params['childtable'];

    $total_childs        = $params['total_childs'];
    $total_batches       = $params['total_loops'];
    $current_child_count = $params['current_child_count'];

    $loop_count = $params['current_loop_count'];

    $temp_tablename = config('ajimportdata.temptablename');

    $child_field_maps = $child_table_conf['fields_map'];

    $child_table_name = $child_table_conf['name'];

    $batchsize = config('ajimportdata.batchsize');

    $limit = $loop_count * $batchsize;

    $temp_fields_ar = array_keys($child_field_maps);

    Log::info('temp_fields_ar');
    Log::info($temp_fields_ar);
    $temp_fields = implode("`,`", $temp_fields_ar);

    $child_fields_ar = array_values($child_field_maps);

    if (isset($child_table_conf['insertid_temptable'])) {

    $tmp_tbl_child_insert_id = array_keys($child_table_conf['insertid_temptable']);
    if (isset($this->temptbl_fields_to_update_by_childid_conf[$tmpfield_to_update])) {

    $temp_tbl_child_field_update_conf_ar = $this->temptbl_fields_to_update_by_childid_conf[$tmpfield_to_update];

    foreach ($temp_tbl_child_field_update_conf_ar as  $value) {
    if($value['tablename']==$child_table_name){

    }
    }

    }

    }

    }*/

    /**
     * Create unique fields on temp table for the configured fields
     *
     * @param      <type>  $temp_table_indexes  The temporary table indexes
     *
     * @return     string  ( description_of_the_return_value )
     */
    public function addConfigUniqueFieldOnTempTable($temp_table_unique = array())
    {
        $temp_tablename_unique_fields = config('ajimportdata.uniquefields');

        $qry_temptable_config_unique = "";
        $new_temp_table_unique       = [];

        $additional_indexes = array_diff($temp_tablename_unique_fields, $temp_table_unique);
        if (!is_null($temp_tablename_unique_fields)) {

            if (is_array($temp_tablename_unique_fields) && count($temp_tablename_unique_fields) > 0) {
                foreach ($additional_indexes as $uniqindex_key => $uniqindex_value) {

                    if (is_array($uniqindex_value)) {
                        $qry_temptable_config_unique .= ", CONSTRAINT " . $this->getFormatedFieldName($uniqindex_key) . " UNIQUE (";
                        $cnt_uniq_index = 0;
                        foreach ($uniqindex_value as $key => $value) {
                            if ($cnt_uniq_index > 0) {
                                $qry_temptable_config_unique .= ", ";
                            }
                            $qry_temptable_config_unique .= "`" . $this->getFormatedFieldName($value) . "`";
                            $cnt_uniq_index++;
                        }
                        $qry_temptable_config_unique .= " ) ";
                    }

                }
            }

        }

        //return array('unique_query' => $qry_temptable_config_unique, 'unique_fields' => $new_temp_table_unique);
        return $qry_temptable_config_unique;

    }

    /**
     * Create indexes on temp table for the configured fields
     *
     * @param      <type>  $temp_table_indexes  The temporary table indexes
     *
     * @return     string  ( description_of_the_return_value )
     */
    public function addConfigFieldIndexesOnTempTable($temp_table_indexes)
    {
        $qry_temptable_config_indexes = "";
        $new_temp_table_indexes       = [];
        $temp_tablename_index_fields  = config('ajimportdata.indexfields');
        if (!is_null($temp_tablename_index_fields)) {

            if (is_array($temp_tablename_index_fields)) {
                $additional_indexes = array_diff($temp_tablename_index_fields, $temp_table_indexes);
                if (count($additional_indexes) > 0) {
                    foreach ($additional_indexes as $value) {

                        $index_field_name = $this->getFormatedFieldName($value);
                        $qry_temptable_config_indexes .= ", INDEX USING BTREE(" . $index_field_name . ")";
                        $new_temp_table_indexes[] = $index_field_name;
                    }
                }
            }

        }

        return array('index_query' => $qry_temptable_config_indexes, 'index_fields' => $new_temp_table_indexes);

    }

    /**
     * Mark records invalid on temp table if set of fields matches each other.
     * For ex if Email1 & Email2 value matches each other in row it will be marked as invalid
     * COnf:  $ajimport_config['invalid_matches'] = array(['Email1','Email2'])
     */
    public function markRcordInvalidIfConfigFieldsMatches($params)
    {

        try {

            $log_msg_fields  = "";
            $qry_field_match = "";
            $loop_count      = $params['current_loop_count'];

            $temp_tablename = config('ajimportdata.temptablename');
            $batchsize      = config('ajimportdata.batchsize');

            $limit = $loop_count * $batchsize;

            $conf_invalid_matches = config('ajimportdata.invalid_matches');

            $temp_table_ids_by_batch = $this->getTempTableIdsByBatch($limit, $batchsize);

            Log::info('markRcordInvalidIfConfigFieldsMatches - configuration here ');
            Log::info($conf_invalid_matches);

            if (!is_null($conf_invalid_matches)) {

                if (is_array($conf_invalid_matches)) {
                    if (count($conf_invalid_matches) > 0) {

                        $cnt_fieldmatch = 0;
                        foreach ($conf_invalid_matches as $value) {

                            if ($cnt_fieldmatch > 0) {
                                $qry_field_match .= " OR ";
                            }
                            $qry_field_match .= "(" . $value[0] . " like " . $value[1] . " AND " . $value[0] . "!='' )  ";
                            $log_msg_fields .= " (" . $value[0] . " , " . $value[1] . ") , ";
                            $cnt_fieldmatch++;
                        }
                        $qry_field_match .= " AND tmptble.id in (" . $temp_table_ids_by_batch . ")";
                        Log::info('markRcordInvalidIfConfigFieldsMatches:--- QUERY------------------------- ');
                        Log::info($qry_field_match);

                        $log_msg_fields = "Duplicate field matches " . $log_msg_fields;

                        $qry_field_match_main = " UPDATE " . $temp_tablename . " tmptble  SET aj_isvalid ='N', aj_error_log='" . $log_msg_fields . "', aj_processed='y'  WHERE " . $qry_field_match;
                        Log::info($qry_field_match_main);
                        DB::update($qry_field_match_main);
                    }
                }

            }

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.
            $this->errors[] = $ex->getMessage();

            $msg_log = json_encode(array('limit' => $limit, 'batchsize' => $batchsize, 'errormsg' => $ex->getMessage()));

        }

    }

    /**
     * Mark records invalid If mandatary fields defined in configuration are null or empty in temp table .
     * For ex if Company_Name or  Pin_Code are null or empty, then the corresponding records will be marked as invalid
     * COnf:  $ajimport_config['mandatary_tmp_tblfields'] = array( 'Company_Name','Pin_Code');
     */
    public function markInvalidIfMandataryTmpTblFieldsAreEmpty($params)
    {

        try {

            $log_mandatary_tmp_tglfields_msg = "";
            $qry_mandatary_tmp_tblfields     = "";
            $loop_count                      = $params['current_loop_count'];

            $temp_tablename = config('ajimportdata.temptablename');
            $batchsize      = config('ajimportdata.batchsize');

            $limit = $loop_count * $batchsize;

            $conf_mandatary_tmp_tblfields = config('ajimportdata.mandatary_tmp_tblfields');

            $temp_table_ids_by_batch = $this->getTempTableIdsByBatch($limit, $batchsize);

            if (!is_null($conf_mandatary_tmp_tblfields)) {

                if (is_array($conf_mandatary_tmp_tblfields)) {
                    if (count($conf_mandatary_tmp_tblfields) > 0) {

                        $cnt_fieldmatch = 0;
                        foreach ($conf_mandatary_tmp_tblfields as $value) {

                            if ($cnt_fieldmatch > 0) {
                                $qry_mandatary_tmp_tblfields .= " OR ";
                                $log_mandatary_tmp_tglfields_msg .= ", ";
                            }
                            $qry_mandatary_tmp_tblfields .= $value . " IS NULL OR  " . $value . " = '' ";
                            $log_mandatary_tmp_tglfields_msg .= $value;
                            $cnt_fieldmatch++;
                        }
                        $qry_mandatary_tmp_tblfields .= " AND tmptble.id in (" . $temp_table_ids_by_batch . ")";
                        Log::info('markRcordInvalidIfConfigFieldsMatches:--- QUERY------------------------- ');
                        Log::info($qry_mandatary_tmp_tblfields);

                        $log_mandatary_tmp_tglfields_msg = "Mandatary fields configured are empty or null " . $log_mandatary_tmp_tglfields_msg;

                        $qry_mandatary_tmp_tblfields_main = " UPDATE " . $temp_tablename . " tmptble  SET aj_isvalid ='N', aj_error_log='" . $log_mandatary_tmp_tglfields_msg . "', aj_processed='y'  WHERE " . $qry_mandatary_tmp_tblfields;

                        DB::update($qry_mandatary_tmp_tblfields_main);
                    }
                }

            }

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.
            $this->errors[] = $ex->getMessage();

            $msg_log = json_encode(array('limit' => $limit, 'batchsize' => $batchsize, 'errormsg' => $ex->getMessage()));

        }

    }

    //Read the config and build array by key(field insert id to update) and child tables conf
    public function update_field_temp_tbl_with_existing_child_records_by_conf($params)
    {

        $import_libs      = new AjImportlibs();
        $child_table_conf = $params['childtable'];

        $total_childs        = $params['total_childs'];
        $total_batches       = $params['total_loops'];
        $current_child_count = $params['current_child_count'];

        $loop_count = $params['current_loop_count'];

        $temp_tablename = config('ajimportdata.temptablename');

        $child_field_maps = $child_table_conf['fields_map'];

        $child_table_name = $child_table_conf['name'];

        $batchsize = config('ajimportdata.batchsize');

        $limit = $loop_count * $batchsize;

        $tables_to_update_temp                      = config('ajimportdata.tables_to_update_temp');
        $temp_field_ids_to_update_by_existing_child = [];

        if (!is_null($tables_to_update_temp)) {

            $temp_table_ids_by_batch = $this->getTempTableIdsByBatch($limit, $batchsize);

            foreach ($tables_to_update_temp as $tables_to_update_temp) {

                $tmp_field_toupdate_arr = array_keys($tables_to_update_temp['insertid_temptable']);
                $child_update_id_arr    = array_values($tables_to_update_temp['insertid_temptable']);

                $child_insert_id_on_temp_table = $tmp_field_toupdate_arr[0];
                $child_update_id               = $child_update_id_arr[0];

                $fields_map_to_update_temptable_child_id = $tables_to_update_temp['fields_map_to_update_temptable_child_id'];

                $cnt_where       = 0;
                $where_condition = '';

                foreach ($fields_map_to_update_temptable_child_id as $tempfield => $childfield) {

                    $where_condition .= " AND ";

                    /*$where_condition .= " tmpdata." . $tempfield . " COLLATE utf8_general_ci = " . "childtable." . $childfield . " COLLATE
                    utf8_general_ci ";*/
                    $where_condition .= " tmpdata." . $tempfield . "  = " . "childtable." . str_replace('\\', '\\\\', $childfield) . " ";
                    $cnt_where++;
                }

                $default_fields_map_to_update_temptable_child_id = $tables_to_update_temp['default_fields_map_to_update_temptable_child_id'];

                foreach ($default_fields_map_to_update_temptable_child_id as $tempfield => $childfield_defaultvalue) {

                    $where_condition .= " AND ";

                    /*$where_condition .= " tmpdata." . $tempfield . " COLLATE utf8_general_ci = " . "childtable." . $childfield . " COLLATE
                    utf8_general_ci ";*/
                    $where_condition .= " childtable." . $tempfield . "  = " . " '" . str_replace('\\', '\\\\', $childfield_defaultvalue) . "' ";
                    $cnt_where++;
                }

                $qry_update_child_ids = "UPDATE " . $temp_tablename . " tmpdata, " . $tables_to_update_temp['name'] . " childtable
                    SET
                        tmpdata." . $child_insert_id_on_temp_table . " =  CAST(childtable." . $child_update_id . " as CHAR(50))
                    WHERE  tmpdata.id in (" . $temp_table_ids_by_batch . ")  AND  tmpdata.aj_isvalid!='N'" . $where_condition;

                try {

                    Log::info('<br/> \n  CUSTOM-UPDATER child ids(' . $child_table_conf['name'] . ') on temp table   :----------------------------------');

                    Log::info($qry_update_child_ids);

                    $res_update = DB::update($qry_update_child_ids);
                    Log::info('res_update===============================');
                    Log::info($res_update);

                    $this->exportValidTemptableDataToFile($params);

                    //update valid rows in temp table with the valid inserts on child table.

                } catch (\Illuminate\Database\QueryException $ex) {

                    // Note any method of class PDOException can be called on $ex.
                    $this->errors[] = $ex->getMessage();

                    $msg_log = json_encode(array('table' => $child_table_conf['name'], 'limit' => $limit, 'batchsize' => $batchsize, 'errormsg' => $ex->getMessage()));

                    $this->setBatchInvalidData($temp_tablename, $limit, $batchsize, $msg_log);

                }

            }
        }

        $this->temptbl_fields_to_update_by_childid_conf = $temp_field_ids_to_update_by_existing_child;

    }

    public function loadFiledatainTempTable($real_file_path, $file_headers, $temp_tablename)
    {

        //$file_path = str_replace("\\", "\\\\", $real_file_path);
        $import_libs = new AjImportlibs();
        $file_path   = $import_libs->formatImportExportFilePath($real_file_path);

        $qry_load_data = "LOAD DATA LOCAL INFILE '" . $file_path . "' INTO TABLE `" . $temp_tablename . "`
                 FIELDS TERMINATED BY ','
                OPTIONALLY ENCLOSED BY '\"'
                ESCAPED BY '\b'
                LINES  TERMINATED BY '\n' IGNORE 1 LINES  ( `";
        $qry_load_data .= implode("`,`", $file_headers) . "` ) ;    ";

        //echo $qry_load_data;
        /*dd($qry_load_data);*/
        try {

            $pdo_obj = DB::connection()->getpdo();
            $result  = $pdo_obj->exec($qry_load_data);
            /*  $pdo_warnings = $pdo_obj->exec('SHOW WARNINGS');*/

            //  var_dump($pdo_obj->events);

            // $pdo_warnings = $pdo_obj->exec('SHOW WARNINGS');
            Log::info($qry_load_data);
            Log::info($result);

            $this->validateTempTableFields();

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.
            echo "========================================== EXCEPTION <br/><br/>Row :" . $row_cnt . "<br/>";

            var_dump($ex->getMessage());
        }

        //var_dump($result);

        /*if(($handle = fopen($file_path, 'r')) !== false)
    {
    // get the first row, which contains the column-titles (if necessary)
    $header = fgetcsv($handle);

    // loop through the file line-by-line
    $row_cnt = 0;
    while(($data = fgetcsv($handle)) !== false)
    {

    echo "<br/><br/>Row :".$row_cnt."<br/>";
    print_r($data);
    $row_cnt++;
    unset($data);
    }
    fclose($handle);
    } */

    }

    public function insertUpdateChildTable()
    {

        $temp_tablename = config('ajimportdata.temptablename');
        $mtable         = new AjTable($temp_tablename);
        $mtable->setTableSchema();
        $mtablevalidator = new AjSchemaValidator($temp_tablename);
        $params          = array('maxlength' => 50);
        $mtablevalidator->validateFieldLength('email', $params);
    }

    /**
     * Adds a job queue by batches in jobs table
     *
     * @return   array of logs and errors(  array('logs' => array('msg'), 'errors' => array('error msg')))
     */
    public function addJobQueue()
    {

        Log::info('addJobQueue-----------');
        $temp_tablename = config('ajimportdata.temptablename');

        $batchsize = config('ajimportdata.batchsize');

        //Get total valid record count from temp table and calculate batches
        try {

            $valid_record_count = DB::SELECT("SELECT COUNT(*) as records_count FROM " . $temp_tablename . " WHERE aj_isvalid!='N' ");

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.
            $this->errors[] = $ex->getMessage();

        }

        $temp_records_count = $valid_record_count[0]->records_count;

        /* $mastertable_conf = config('ajimportdata.mastertable');
        $mtable_name      = $mastertable_conf['name'];
        $mtable_fieldmaps = $mastertable_conf['fields_map'];*/

        $childtables_conf_ar = $this->getChildTableConf(); //config('ajimportdata.childtables');

        $total_loops = round($temp_records_count / $batchsize);
        if ($total_loops <= 0) {
            $total_loops = 1;
        }

        // echo $temp_records_count . "TOTAL LOOPS" . $total_loops;

        $this->addValidateUnique($temp_records_count);

        for ($loop = 0; $loop < $total_loops; $loop++) {

            //echo "<br/>LOOP TEST :" . $loop;

            $job_params = array('current_loop_count' => $loop, 'total_loops' => $total_loops, 'type' => 'insert_records');
            AjImportDataJob::dispatch($job_params)->onQueue('insert_records')->onConnection('ajfileimportcon');

        }

        //echo "<br/><br/> <a href='" . route('downloadtemptablecsv') . "' target='_blank' >Click here</a> View the csv import data from ready table. <br/><b>Note: Please run this command to complete the import of data: <br/> 'php artisan queue:work --queue=validateunique,insert_records'  </b>";

        $this->logs[] = "<br/><br/> <a href='" . route('downloadtemptablecsv') . "' target='_blank' >Click here</a> View the csv import data from ready table. <br/><b>Note: Please run this command to complete the import of data: <br/> 'php artisan queue:work --queue=validateunique,insert_records ajfileimportcon'  </b>";
        return array('logs' => $this->logs, 'errors' => $this->errors);
        Log::info("Executing schedule command");
        /* $app          = App::getFacadeRoot();
    $schedule     = $app->make(Schedule::class);
    $schedule_res = $schedule->command('php artisan queue:work --queue=validateunique,insert_records');
    echo "<pre>";
    print_r($schedule_res);*/

    }

    /**
     * Adds a validate unique job queue
     *
     * @param      integer  $temp_records_count  The temporary records count
     */
    public function addValidateUnique($temp_records_count)
    {

        Log::info('-------------addValidateUnique--------------');

        $temp_tablename        = config('ajimportdata.temptablename');
        $child_table_conf_list = $this->getChildTableConf(); //config('ajimportdata.childtables');
        $total_no_child_tables = count($child_table_conf_list);

        /* echo "<pre>total_no_child_tables:" . $total_no_child_tables;

        print_r($child_table_conf_list);*/

        $batchsize = config('ajimportdata.batchsize'); //Get temp table name from config
        $loops     = round($temp_records_count / $batchsize);

        for ($child_count = 0; $child_count < $total_no_child_tables; $child_count++) {

            $child_table = new AjTable($child_table_conf_list[$child_count]['name']);

            $child_table_schema = $child_table->setTableSchema();

            Log::info('<br/> \n  UNIQ keys for the table ');
            //add batch jobs to add uniq field validation on temp table
            $child_table_unique_keys = $child_table->getUniqFields();

            Log::info($child_table_unique_keys);

            $child_table_field_map      = $child_table_conf_list[$child_count]['fields_map'];
            $child_table_field_map_flip = array_flip($child_table_field_map);

            foreach ($child_table_unique_keys as $child_field_name) {

                if (isset($child_table_field_map_flip[$child_field_name])) {
                    $job_params = array('childtable' => $child_table_conf_list[$child_count], 'type' => 'validateunique', 'child_field_name' => $child_table_field_map_flip[$child_field_name]);
                    AjImportDataJob::dispatch($job_params)->onQueue('validateunique')->onConnection('ajfileimportcon');
                }

            }

        }

    }

    public function addInsertRecordsQueue($params)
    {
        $this->setChildTableConf();
        Log::info('-------------addInsertRecordsQueue--------------');

        $temp_tablename        = config('ajimportdata.temptablename');
        $child_table_conf_list = $this->getChildTableConf(); // config('ajimportdata.childtables');
        $total_no_child_tables = count($child_table_conf_list);

        Log::info($child_table_conf_list);
        Log::info('total_no_child_tables : ' . $total_no_child_tables);
        $total_no_child_tables;

        $batchsize = config('ajimportdata.batchsize'); //Get temp table name from config
        // $loops     = round($temp_records_count / $batchsize);

        $this->markRcordInvalidIfConfigFieldsMatches($params);
        $this->markInvalidIfMandataryTmpTblFieldsAreEmpty($params);

        for ($child_count = 0; $child_count < $total_no_child_tables; $child_count++) {

            $child_table = new AjTable($child_table_conf_list[$child_count]['name']);

            $child_table_schema = $child_table->setTableSchema();

            Log::info('<br/> \n  UNIQ keys for the table ');
            //add batch jobs to add uniq field validation on temp table
            $child_table_unique_keys = $child_table->getUniqFields();

            Log::info($child_table_unique_keys);

            //Add batch jobs on field validation for set batch of jobs

            $job_params = array('childtable' => $child_table_conf_list[$child_count], 'total_childs' => $total_no_child_tables, 'current_child_count' => $child_count);
            $job_params = array_merge($job_params, $params);
            //AjImportDataJob::dispatch($job_params)->onQueue('validatechildinsert');
            $this->processTempTableFieldValidation($job_params);

        }

    }

    /* #######################################################################################################################################     */

    public function validateTempTableFields()
    {

        Log::info('----------validateTempTableFields---- beffore try block------');

        $temp_tablename = config('ajimportdata.temptablename');

        try {

            $temp_records_count_res = DB::SELECT("SELECT COUNT(*) as records_count FROM " . $temp_tablename);
            Log::info('----------validateTempTableFields----------');
            $temp_records_count = $temp_records_count_res[0]->records_count;
            Log::info($temp_records_count);
            // $this->generateJobQueue($temp_records_count);
            $this->addJobQueue();

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.
            $this->errors[] = $ex->getMessage();

        }

    }

    public function processUniqueFieldValidationQueue($params)
    {

        Log::info("processUniqueFieldValidationQueue:---------------------------");

        Log::info($params);
        $temp_tablename   = config('ajimportdata.temptablename');
        $child_field_name = $params['child_field_name'];

        Log::info($temp_tablename);

        Log::info($child_field_name);

        $temp_table_validator = new AjSchemaValidator($temp_tablename);
        $temp_table_validator->validatePrimaryUnique($child_field_name, $params);

    }

    public function processTempTableFieldValidation($params)
    {

        $temp_tablename   = config('ajimportdata.temptablename');
        $child_table_conf = $params['childtable'];
        $loop_count       = $params['current_loop_count'];

        $child_table = new AjTable($child_table_conf['name']);

        $child_table->setTableSchema();

        $child_table_schema = $child_table->getTableSchema();

        // echo "<pre>";
        // print_r($child_table->getTableSchema());

        $child_field_maps = $child_table_conf['fields_map'];

        //print_r($child_field_maps);

        $temp_table_validator = new AjSchemaValidator($temp_tablename);

        foreach ($child_field_maps as $temp_field_name => $child_field_name) {

            $temp_table_validator->validateField($temp_field_name, $child_table_schema[$child_field_name], $loop_count);

        }

        $tables_to_update_temp = config('ajimportdata.tables_to_update_temp');

        if (is_null($tables_to_update_temp)) {
            $this->exportValidTemptableDataToFile($params);
        } else {
            if (count(config('ajimportdata.tables_to_update_temp') > 0)) {
                $this->update_field_temp_tbl_with_existing_child_records_by_conf($params);
            }
        }

        /* $job_params = array('childtable' => $child_table_conf, 'loop_count' => $loop_count, 'type' => 'insertvalidchilddata');
        AjImportDataJob::dispatch($job_params)->onQueue('insertvalidchilddata');*/

        // $this->exportValidTemptableDataToFile($child_table_conf, $temp_tablename, $loop_count);

    }

    /**
     * public function exportValidTemptableDataToFile($child_table_conf, $temp_tablename, $loop_count)
    Load valid data from temp table into child table by batch
     *
     * @param      <array>  $child_table_conf  The child table conf
     *
     */

    public function exportValidTemptableDataToFile($params)
    {

        $import_libs      = new AjImportlibs();
        $child_table_conf = $params['childtable'];

        $total_childs        = $params['total_childs'];
        $total_batches       = $params['total_loops'];
        $current_child_count = $params['current_child_count'];

        $loop_count = $params['current_loop_count'];

        $temp_tablename = config('ajimportdata.temptablename');

        $child_field_maps = $child_table_conf['fields_map'];

        $child_table_name = $child_table_conf['name'];

        $batchsize = config('ajimportdata.batchsize');

        $limit = $loop_count * $batchsize;

        $temp_fields_ar = array_keys($child_field_maps);

        Log::info('temp_fields_ar');
        Log::info($temp_fields_ar);
        $temp_fields = implode("`,`", $temp_fields_ar);

        $child_fields_ar = array_values($child_field_maps);

        /* If any clumns on temptable needs to be updated by configured set of values from config file*/

        if (isset($child_table_conf['columnupdatevalues'])) {
            $columnupdatevalues = $child_table_conf['columnupdatevalues'];

            $this->updateTableFieldBySetOfDtaticValues($temp_tablename, $columnupdatevalues, $limit, $batchsize);
        }

        /* If slugs has to be added for table fields*/
        if (isset($child_table_conf['field_slug'])) {
            $field_slug = $child_table_conf['field_slug'];

            $this->updateTableFieldBySlug($temp_tablename, $field_slug, $limit, $batchsize);
        }

        /** If default values has to be set for table insertion take the default values*/
        $child_default_values_string = "";
        if (isset($child_table_conf['default_values'])) {
            $child_default_keys = array_keys($child_table_conf['default_values']);
            $child_fields_ar    = array_merge($child_fields_ar, $child_default_keys);
            //$child_default_keys_string = implode("`,`", $child_default_keys);

            $child_default_values        = array_values($child_table_conf['default_values']);
            $child_default_values_string = implode("','", $import_libs->custom_mysql_real_escape($child_default_values));

        }

        /** If fields comma seperated values has to be put in seperate recods on the table */

        $comma_field_select = "";
        $comma_field_from   = "";
        if (isset($child_table_conf['commafield_to_multirecords'])) {
            $child_target_commafield = array_values($child_table_conf['commafield_to_multirecords']);
            $child_fields_ar         = array_merge($child_fields_ar, $child_target_commafield);

            $comma_field_conf = $child_table_conf['commafield_to_multirecords'];

            foreach ($comma_field_conf as $comma_key => $comma_value) {
                $comma_field_select = "SUBSTRING_INDEX(SUBSTRING_INDEX(" . $comma_key . ", ',', numbers.n), ',', -1) " . $comma_key;
                //$comma_field_select = "SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(" . $comma_key . ", ',', numbers.n), ',', -1),'|',1) " . $comma_key;//updated for array('34|slug1,4|slug2,12|slug3')

                $comma_field_from = "  (SELECT 1 n UNION ALL SELECT 2
   UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20) numbers INNER JOIN " . $temp_tablename . "
  ON CHAR_LENGTH(" . $comma_key . ")
     -CHAR_LENGTH(REPLACE(" . $comma_key . ", ',', ''))>=numbers.n-1 ";

            }

        }

        /** If Serialized values has to be added on table build the serialized array value for the fields */
        $serialize_string = "";
        if (isset($child_table_conf['serializevalues'])) {

            $serialized_fields_conf = $child_table_conf['serializevalues'];

            foreach ($serialized_fields_conf as $target_serialized_field => $serialize_tmpfield) {

                $current_serialize_field_cnt = count($serialize_tmpfield);

                $serialize_string = 'CONCAT("a:' . $current_serialize_field_cnt . ':{"';

                foreach ($serialize_tmpfield as $serialize_key => $serialize_value) {

                    $serialize_string .= ',"s:' . strlen($serialize_key) . ':\"' . $serialize_key . '\";"';
                    $serialize_string .= ',"s:",CHAR_LENGTH(' . $serialize_value . '),":\"",' . $serialize_value . ',"\";"';

                }
                $serialize_string .= ',"}")';

                $child_fields_ar[] = $target_serialized_field;

            }

        }

        /** If Json values has to be added on table build the serialized array value for the fields */
        $json_string = "";
        if (isset($child_table_conf['jsonvalues']) && !is_null($child_table_conf['jsonvalues'])) {

            $json_fields_conf = $child_table_conf['jsonvalues'];

            foreach ($json_fields_conf as $target_json_field => $json_tmpfield) {

                $current_json_field_cnt = count($json_tmpfield);

                $json_string.    = 'CONCAT("{"';
                $json_field_cnt = 0;

                foreach ($json_tmpfield as $json_key => $json_value) {

                    /* $json_string .= ',"\"' . $json_key . '\":"';
                    $json_string .= ',"",",' . $json_value . ',"';*/
                    /* $json_string .= ',"\"' . $json_key . '\":"';
                    $json_string .= '"",' . $json_value . '';*/
                    if ($json_field_cnt > 0) {
                        $json_string .= ',","';
                    }

                    $json_string .= ',"\"' . $json_key . '\":"';
                    $json_string .= '"",' . $json_value . '';

                    $json_field_cnt++;

                }
                //$json_string .= ',"}")';
                $json_string .= ',"' . '\"}")';

                $child_fields_ar[] = $target_json_field;

            }

        }

        /** If colstoarrayfield  has been defined (Multiple column values goes as the array to single field on child table) */
        $colstoarrayfield_string = "";
        if (isset($child_table_conf['colstoarrayfield'])) {

            $colstoarrayfield_conf = $child_table_conf['colstoarrayfield'];

            $colstoarrayfield_string.= 'CONCAT("["';
            foreach ($colstoarrayfield_conf as $target_array_field => $array_tmpfield) {

                $current_serialize_field_cnt = count($array_tmpfield);

                $cnt_cols_to_array = 0;
                foreach ($array_tmpfield as $serialize_key => $array_value) {

                    $array_field_collection[] = $array_value;

                    $colstoarrayfield_string .= '," ';
                    if ($cnt_cols_to_array > 0) {
                        $colstoarrayfield_string .= ',","';
                    }
                    $colstoarrayfield_string .= '\'",' . $array_value . ',"\'"';

                    $cnt_cols_to_array++;

                }

                // $colstoarrayfield_string.= implode('.\'","\'.',$array_field_collection);

                $child_fields_ar[] = $target_array_field;

            }
            $colstoarrayfield_string .= ',"]")';

        }

        /*Selected columns in a record make new indivisual records*/

        $fields_to_multirecords_from = "";
        if (isset($child_table_conf['fields_to_multirecords'])) {

            $fields_to_multirecords_conf = $child_table_conf['fields_to_multirecords'];

            foreach ($fields_to_multirecords_conf as $fields_to_multirecord_ky => $fields_to_multirecord_vl) {
                $fields_to_multirecord_field     = $fields_to_multirecord_vl;
                $fields_to_multirecord_field_key = $fields_to_multirecord_ky;
            }
            $child_fields_ar[] = $fields_to_multirecord_field_key;

            if (count($fields_to_multirecords_conf) > 0) {

                $fields_to_multirecords_from   = '';
                $fields_to_multirecords_select = ' field_to_multi_recordstble.val ' . $fields_to_multirecord_field_key . ' ';

                $cnt_field_to_multirecords = 0;
                foreach ($fields_to_multirecord_field as $fields_to_multirecord) {

                    if ($cnt_field_to_multirecords == 0) {
                        $fields_to_multirecords_from .= ' ( ';
                    } else if ($cnt_field_to_multirecords > 0) {
                        $fields_to_multirecords_from .= ' UNION ';
                    }

                    $field_to_multi_alias_tbl_name = "a" . $cnt_field_to_multirecords;
                    $field_to_multi_records_where  = " WHERE " . $field_to_multi_alias_tbl_name . ".`" . $fields_to_multirecord . "`!='' AND " . $field_to_multi_alias_tbl_name . ".`" . $fields_to_multirecord . "` IS NOT NULL ";

                    $fields_to_multirecords_from .= ' SELECT ' . $field_to_multi_alias_tbl_name . '.`id` as `id`, ' . $field_to_multi_alias_tbl_name . '.`' . $fields_to_multirecord . '` as val  FROM `' . $temp_tablename . '` ' . $field_to_multi_alias_tbl_name . ' ' . $field_to_multi_records_where;

                    $cnt_field_to_multirecords++;
                }

                $fields_to_multirecords_from .= " ) field_to_multi_recordstble ";
            }

        }

        $child_fields = implode("`,`", $child_fields_ar);

        $file_prefix = "aj_" . $child_table_name;
        //$folder      = storage_path('app/Ajency/Ajfileimport/validchilddata/');
        //$folder      = storage_path('app/Ajency/');
        $folder = $import_libs->getTempImportExportDirectory() . "/Ajency/";

        Log::info('TEMPORARY EXPORT DIRECTORY ');
        Log::info($folder);

        $folder_check = $import_libs->createDirectoryIfDontExists($folder);

        $child_outfile_name = $import_libs->generateUniqueOutfileName($file_prefix, $folder);

        //$child_outfile_name = "aj_" . $child_table_name . "" . date('d_m_Y_H_i_s') . ".csv";

        //$child_outfile_name = storage_path('app/Ajency/Ajfileimport/validchilddata/') . $child_outfile_name;

        // $request->file('ajfile')->storeAs('Ajency/Ajfileimport/Files', $new_file_name);

        //$file_path = str_replace("\\", "\\\\", $child_outfile_name);

        $file_path              = $import_libs->formatImportExportFilePath($child_outfile_name);
        $additional_where_condn = "";

        if (isset($child_table_conf['insertid_temptable'])) {

            $tmp_tbl_child_insert_id_arr = array_keys($child_table_conf['insertid_temptable']);

            if (isset($tmp_tbl_child_insert_id_arr[0])) {

                if ($tmp_tbl_child_insert_id_arr[0]) {
                    $additional_where_condn = " AND ( outtable." . $tmp_tbl_child_insert_id_arr[0] . "=''  ||  outtable." . $tmp_tbl_child_insert_id_arr[0] . " IS NULL) ";
                }
            }

        }

        try {

            $qry_select_valid_data = "SELECT `" . $temp_fields . "` ";

            /*Form the select query based on configuration */
            if ($child_default_values_string != '') {
                $qry_select_valid_data .= ",'" . $child_default_values_string . "'";
            }

            if ($serialize_string != '') {
                $qry_select_valid_data .= "," . $serialize_string;
            }

            if ($json_string != '') {
                $qry_select_valid_data .= "," . $json_string;
            }

            if ($colstoarrayfield_string != '') {
                $qry_select_valid_data .= "," . $colstoarrayfield_string;
            }
            if (isset($child_table_conf['commafield_to_multirecords']) && $comma_field_select != "") {
                $qry_select_valid_data .= "," . $comma_field_select;
            }

            if (isset($child_table_conf['fields_to_multirecords']) && $fields_to_multirecords_select != "") {
                $qry_select_valid_data .= "," . $fields_to_multirecords_select;
            }

            /* Form the from query based on configuration */
            $from_field_str = "";
            if ($comma_field_from != "") {
                $from_field_str = $comma_field_from . " WHERE id in ";
            } else if ($fields_to_multirecords_from != "") {
                $from_field_str = $fields_to_multirecords_from . " INNER JOIN " . $temp_tablename . " outtable  on outtable.id = field_to_multi_recordstble.id   WHERE outtable.id in ";
            } else {
                $from_field_str = $temp_tablename . " outtable WHERE outtable.id in ";
            }

            $qry_select_valid_data .= " INTO OUTFILE '" . $file_path . "'
                                    FIELDS TERMINATED BY ','
                                    OPTIONALLY ENCLOSED BY '\"'
                                    ESCAPED BY ''
                                    LINES TERMINATED BY '\n'
                                    FROM " . $from_field_str . " (SELECT id FROM (SELECT id FROM " . $temp_tablename . " tt   ORDER BY tt.id ASC LIMIT " . $limit . "," . $batchsize . ") tt2 )  AND  aj_isvalid!='N' " . $additional_where_condn . " order by outtable.id ASC";

            Log::info('<br/> \n  validchilddata OUTFILE query  :----------------------------------');
            Log::info("filepath" . $file_path);
            Log::info($qry_select_valid_data);

            DB::select($qry_select_valid_data);

            //update valid rows in temp table with the valid inserts on child table.

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.
            $this->errors[] = $ex->getMessage();

            $msg_log = json_encode(array('table' => $child_table_conf['name'], 'limit' => $limit, 'batchsize' => $batchsize, 'errormsg' => "Insert failed: " . $ex->getMessage()));

            $this->setBatchInvalidData($temp_tablename, $limit, $batchsize, $msg_log);

        }

        //Load valid data from temp table into child table

        $qry_load_data = "LOAD DATA LOCAL INFILE '" . $file_path . "' INTO TABLE " . $child_table_name . "
         FIELDS TERMINATED BY ','
        OPTIONALLY ENCLOSED BY '\"'
        ESCAPED BY '\b'
        LINES  TERMINATED BY '\n'    ( `";
        $qry_load_data .= $child_fields . "` ) ";

        try {
            $pdo_obj = DB::connection()->getpdo();
            $result  = $pdo_obj->exec($qry_load_data);

            Log::info($qry_load_data);

            $job_params_update_child_id = array('childtable' => $child_table_conf, 'current_loop_count' => $loop_count, 'type' => 'tempupdatechildid', 'total_childs' => $total_childs, 'total_loops' => $total_batches, 'current_child_count' => $current_child_count);

            //if($loop_count==0 && $current_child_count==0){
            $this->UpdateTempTableWithChildInsertIds($job_params_update_child_id);
            //}

            //AjImportDataJob::dispatch($job_params_update_child_id)->onQueue('tempupdatechildid');

        } catch (\Illuminate\Database\QueryException $ex) {

            // Note any method of class PDOException can be called on $ex.
            Log::info($ex->getMessage());
            Log::info($ex);

            Log::info("**********************************ERROR************************************");

            $msg_log = json_encode(array('table' => $child_table_conf['name'], 'limit' => $limit, 'batchsize' => $batchsize, 'errormsg' => "Insert failed: " . $ex->getMessage()));

            $this->setBatchInvalidData($temp_tablename, $limit, $batchsize, $msg_log);

        }

        //Update temp table with insert ids of child table

    }

    /**
     *  Update temp table with the insert ids from the child table for given child table batch
     *
     * @param      <type>  $params  array('childtable','current_loop_count','total_childs','total_loops','current_child_count')
     */
    public function UpdateTempTableWithChildInsertIds($params)
    {

        $temp_tablename      = config('ajimportdata.temptablename');
        $child_table_conf    = $params['childtable'];
        $loop_count          = $params['current_loop_count'];
        $total_childs        = $params['total_childs'];
        $total_batches       = $params['total_loops'];
        $current_child_count = $params['current_child_count'];

        $batchsize = config('ajimportdata.batchsize');
        $limit     = $loop_count * $batchsize;

        $child_insert_id_on_temp_table = $this->getFormatedFieldName($child_table_conf['name']) . "_id"; // $child_table_conf['insertid_temptable'];

        $temp_table_ids_by_batch = $this->getTempTableIdsByBatch($limit, $batchsize);

        if (isset($child_table_conf['insertid_childtable'])) {

            $child_insert_id_field = $child_table_conf['insertid_childtable'];

            $field_maps      = $child_table_conf['fields_map'];
            $cnt_where       = 0;
            $where_condition = " ";

            Log::info('UpdateTempTableWithChildInsertIds:-- child_table_conf');
            Log::info($child_table_conf);

            /* update child insert id based on field_maps in config
             * foreach ($field_maps as $tempfield => $childfield) {

            $where_condition .= " AND ";

            $where_condition .= " tmpdata." . $tempfield . "=" . "childtable." . $childfield . "";
            $cnt_where++;
            }*/
            if (isset($child_table_conf['fields_map_to_update_temptable_child_id'])) {

                Log::info("isset(child_table_conf['fields_map_to_update_temptable_child_id']");
                $fields_map_to_update_temptable_child_id = $child_table_conf['fields_map_to_update_temptable_child_id'];
                //
                foreach ($fields_map_to_update_temptable_child_id as $tempfield => $childfield) {

                    $where_condition .= " AND ";

                    /*$where_condition .= " tmpdata." . $tempfield . " COLLATE utf8_general_ci = " . "childtable." . $childfield . " COLLATE
                    utf8_general_ci ";*/
                    $where_condition .= " tmpdata." . $tempfield . "  = " . "childtable." . $childfield . " ";
                    $cnt_where++;
                }

                /*$qry_update_child_ids = "UPDATE " . $temp_tablename . " tmpdata, " . $child_table_conf['name'] . " childtable
                SET
                tmpdata." . $child_insert_id_on_temp_table . " = childtable." . $child_insert_id_field . "
                WHERE  tmpdata.id in (SELECT id FROM (SELECT id FROM " . $temp_tablename . " tt ORDER BY tt.id ASC LIMIT " . $limit . "," . $batchsize . ") tt2 )  AND  tmpdata.aj_isvalid!='N'" . $where_condition;*/

                $qry_update_child_ids = "UPDATE " . $temp_tablename . " tmpdata, " . $child_table_conf['name'] . " childtable
                SET
                    tmpdata." . $child_insert_id_on_temp_table . " =  CAST(childtable." . $child_insert_id_field . " as CHAR(50))
                WHERE  tmpdata.id in (" . $temp_table_ids_by_batch . ")  AND  tmpdata.aj_isvalid!='N'" . $where_condition;

                try {

                    Log::info('<br/> \n  UPDATER child ids(' . $child_table_conf['name'] . ') on temp table   :----------------------------------');

                    Log::info($qry_update_child_ids);

                    $res_update = DB::update($qry_update_child_ids);
                    Log::info('res_update===============================');
                    Log::info($res_update);

                    //update valid rows in temp table with the valid inserts on child table.

                } catch (\Illuminate\Database\QueryException $ex) {

                    // Note any method of class PDOException can be called on $ex.
                    $this->errors[] = $ex->getMessage();

                    $msg_log = json_encode(array('table' => $child_table_conf['name'], 'limit' => $limit, 'batchsize' => $batchsize, 'errormsg' => $ex->getMessage()));

                    $this->setBatchInvalidData($temp_tablename, $limit, $batchsize, $msg_log);

                }

            }

            /* check if child insert id is mandatary on temporary table, and update the records in the current batch to invalid if the child insert id on temp table is empty */
            if (isset($child_table_conf['is_mandatary_insertid'])) {
                if ($child_table_conf['is_mandatary_insertid'] == "yes") {
                    /*$qry_update_failed_child_ids = "UPDATE " . $temp_tablename . " tmpdata, " . $child_table_conf['name'] . " childtable
                    SET tmpdata.aj_isvalid ='N', aj_error_log ='insert on child table " . $child_table_conf['name'] . " Failed '  WHERE (tmpdata." . $child_insert_id_on_temp_table . "='' || tmpdata." . $child_insert_id_on_temp_table . "=0 || tmpdata." . $child_insert_id_on_temp_table . " is NULL )  AND   tmpdata.id in (SELECT id FROM (SELECT id FROM " . $temp_tablename . " tt ORDER BY tt.id ASC LIMIT " . $limit . "," . $batchsize . ") tt2 )   AND  tmpdata.aj_isvalid!='N'";*/
                    $qry_update_failed_child_ids = "UPDATE " . $temp_tablename . " tmpdata, " . $child_table_conf['name'] . " childtable
                        SET tmpdata.aj_isvalid ='N', aj_processed ='y', aj_error_log ='insert on child table " . $child_table_conf['name'] . " Failed '  WHERE (tmpdata." . $child_insert_id_on_temp_table . "='' || tmpdata." . $child_insert_id_on_temp_table . "=0 || tmpdata." . $child_insert_id_on_temp_table . " is NULL )  AND   tmpdata.id in (" . $temp_table_ids_by_batch . ")   AND  tmpdata.aj_isvalid!='N'";

                    try {

                        Log::info('<br/> \n  UPDATER failed child ids(' . $child_table_conf['name'] . ')  on temp table   :----------------------------------');

                        Log::info($qry_update_failed_child_ids);

                        DB::update($qry_update_failed_child_ids);

                        //update valid rows in temp table with the valid inserts on child table.

                    } catch (\Illuminate\Database\QueryException $ex) {

                        // Note any method of class PDOException can be called on $ex.
                        $this->errors[] = $ex->getMessage();

                    }

                }
            }

        }

        $string = "Total child count : " . ($total_childs - 1) . ", current_child_count = " . $current_child_count . " ||  total batches :" . ($total_batches - 1) . ", Limit :" . $limit;
        Log::info($string);

        //If all the child table configurations are processed for the selected batch, update the temp table records as processed for the selected batch
        if ($current_child_count >= ($total_childs - 1)) {
            $this->setProcessed($temp_tablename, $limit, $batchsize);
            $aj_batchcallbacks = config('ajimportdata.aj_batchcallbacks');
            $callback_args     = array('limit' => $limit, 'batchsize' => $batchsize);
            $this->sendControltoCallback($aj_batchcallbacks, $callback_args);

        }

        if ($current_child_count == ($total_childs - 1) && $loop_count == ($total_batches - 1)) {

            $aj_callbacks = config('ajimportdata.aj_callbacks');
            $this->sendControltoCallback($aj_callbacks);
            Log::info('Import done. mailing error log file');
            $this->sendErrorLogFile();

        }

    }

    public function sendControltoCallback($aj_callbacks, $args = array())
    {

        Log::info('Import done. sendControltoCallback');
        Log::info($aj_callbacks);

        if (!is_null($aj_callbacks) && is_array($aj_callbacks)) {
            foreach ($aj_callbacks as $aj_callback) {

                $class_path    = isset($aj_callback['class_path']) ? $aj_callback['class_path'] : '';
                $function_name = isset($aj_callback['function_name']) ? $aj_callback['function_name'] : '';

                if ($class_path != '' && $function_name != '') {
                    $callback_obj = new $class_path;

                    if (count($args) > 0) {
                        $result = call_user_func_array(array($callback_obj, $function_name), array($args));
                    } else {
                        $result = call_user_func(array($callback_obj, $function_name));
                    }

                }

            }
        }

    }

    public function getTempTableIdsByBatch($limit, $batchsize)
    {

        $temp_tablename = config('ajimportdata.temptablename');
        $temp_table_ids = array();

        try {

            /*$qry_comma_seperated_temp_ids = "SELECT GROUP_CONCAT(id) as concat_ids FROM (SELECT id FROM " . $temp_tablename . " tt ORDER BY tt.id ASC LIMIT " . $limit . "," . $batchsize . ")  tt2 ";
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
     * Sends an error log file to the email id added in config file
     */
    public function sendErrorLogFile()
    {

        $import_libs     = new AjImportlibs();
        $recipient       = config('ajimportdata.recipient');
        $import_log_mail = config('ajimportdata.import_log_mail');

        $temp_tablename = config('ajimportdata.temptablename');
        $file_prefix    = "aj_errorlog";
        // $folder         = storage_path('app/Ajency/Ajfileimport/errorlogs/');
        //$folder         = storage_path('app/Ajency/');
        $folder = $import_libs->getTempImportExportDirectory() . "/Ajency/";

        $import_libs->createDirectoryIfDontExists($folder);

        $errorlog_outfile_path = $import_libs->generateUniqueOutfileName($file_prefix, $folder);

        Log::info("sendErrorLogFile:-----------------------------------------------");
        Log::info($errorlog_outfile_path);
        //echo $errorlog_outfile_path;

        //$file_path = str_replace("\\", "\\\\", $errorlog_outfile_path);
        $file_path = $import_libs->formatImportExportFilePath($errorlog_outfile_path);

        $temptable_db = new AjTable($temp_tablename);

        $temptable_db->setTableSchema();
        $temptable_schema = $temptable_db->getTableSchema();

        foreach ($temptable_schema as $field_value) {
            $fields_names_ar[] = $field_value->Field;
        }

        try {

            $qry_select_invalid_data = "SELECT '" . implode("', '", $fields_names_ar) . "'  ";

            $qry_select_invalid_data .= " UNION ALL ";

            $qry_select_invalid_data .= "SELECT  * INTO OUTFILE '" . $file_path . "'
                                    FIELDS TERMINATED BY ','
                                    OPTIONALLY ENCLOSED BY '\"'
                                    LINES TERMINATED BY '\n'
                                    FROM " . $temp_tablename . " outtable WHERE aj_isvalid='N'";

            Log:info($qry_select_invalid_data);
            DB::select($qry_select_invalid_data);

            /* Mail success log  file */

            $success_log_file_prefix = "aj_successlog";
            $successlog_outfile_path = $import_libs->generateUniqueOutfileName($success_log_file_prefix, $folder);
            $successlog_file_path    = $import_libs->formatImportExportFilePath($successlog_outfile_path);

            try {

                $qry_select_valid_data = "SELECT '" . implode("', '", $fields_names_ar) . "'  ";

                $qry_select_valid_data .= " UNION ALL ";

                $qry_select_valid_data .= "SELECT  * INTO OUTFILE '" . $successlog_file_path . "'
                                        FIELDS TERMINATED BY ','
                                        OPTIONALLY ENCLOSED BY '\"'
                                        LINES TERMINATED BY '\n'
                                        FROM " . $temp_tablename . " outtable WHERE aj_isvalid!='N' ";

                Log::info($qry_select_valid_data);
                DB::select($qry_select_valid_data);

            } catch (\Illuminate\Database\QueryException $ex) {

                $this->errors[] = $ex->getMessage();

            }
            /* End Mail success log  file */

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();

        }

        if (is_array($import_log_mail)) {

            $attachment_files = array($errorlog_outfile_path, $successlog_file_path);
            $mail_params      = array_merge($import_log_mail, array('attachment' => $attachment_files));
        }

        //$mail_params = array('recipient' => $recipient, 'attachment' => $errorlog_outfile_path);
        if (is_array($mail_params) && isset($mail_params['to'])) {
            $import_libs->sendMail($mail_params);
        }

    }

    public function deleteTable($table_name)
    {

        $qry_drop_table = "DROP TABLE IF EXISTS " . $table_name;

        try {
            $pdo_obj = DB::connection()->getpdo();
            $result  = $pdo_obj->exec($qry_drop_table);
            Log::info($qry_drop_table);

        } catch (\Illuminate\Database\QueryException $ex) {

            Log::info($ex->getMessage());
            $this->errors[] = $ex->getMessage();

        }

    }

    /**
     * Check if all the tables added in config file exists
     *
     * @return     boolean  true if table exists else false
     */
    public function checkIfAllConfigTablesExists()
    {

        $result_array_diff = array();
        $arr_config_tables = array();

        if (!isset($this->childtables_conf)) {
            $this->setChildTableConf();
        }

        $total_config_table_count = count($this->childtables_conf);

        for ($cnt = 0; $cnt < $total_config_table_count; $cnt++) {

            if (!in_array($this->childtables_conf[$cnt]['name'], $arr_config_tables)) {
                $arr_config_tables[] = $this->childtables_conf[$cnt]['name'];
            }

        }

        $qry_table_list = "SHOW TABLES";

        try {
            $res_table_list = DB::select($qry_table_list);

            if (count($res_table_list) > 0) {

                $total_db_table_count = count($res_table_list);

                $res_column_names = array_keys((array) $res_table_list[0]);
                $res_column_name  = $res_column_names[0];

                for ($count = 0; $count < $total_db_table_count; $count++) {

                    $tmp_array       = (array) $res_table_list[$count];
                    $arr_db_tables[] = $tmp_array[$res_column_name];
                }

                $result_array_diff = array_diff($arr_config_tables, $arr_db_tables);

                if (count($result_array_diff) <= 0) {
                    return true;
                }

            }

            $error_string = "Following Tables mentioned in config file do not exists in database. <br/>";
            $error_string .= implode(", ", $result_array_diff);

            $this->errors[] = $error_string;
            return false;

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();
            return false;

        }

    }

    /**
     * Allows to update values on provided csv data with the set of key value pair, and use these values fr insertion to other tables
     *
     * @param      string  $tablename           The tablename
     * @param      <type>  $columnupdatevalues  The columnupdatevalues
     * @param      string  $limit               The limit
     * @param      string  $batchsize           The batchsize
     */
    public function updateTableFieldBySetOfDtaticValues($tablename, $columnupdatevalues, $limit, $batchsize)
    {

        $qry_update1             = "";
        $temp_table_ids_by_batch = $this->getTempTableIdsByBatch($limit, $batchsize);

        foreach ($columnupdatevalues as $column => $colvalues) {

            $qry_update1 .= " SET " . $column . " =  (case   ";

            foreach ($colvalues as $key => $value) {
                $qry_update1 .= " WHEN " . $column . "='" . $key . "' THEN " . $value . " ";
            }

            $qry_update1 .= " ELSE " . $column . " END ) ";

        }

        /*$qry_update = " UPDATE `" . $tablename . "` tt1 " . $qry_update1 . " WHERE  tt1.id in (SELECT id FROM (SELECT id FROM " . $tablename . " tt ORDER BY tt.id ASC LIMIT " . $limit . "," . $batchsize . ") tt2  )  AND  tt1.aj_isvalid!='N'";*/
        $qry_update = " UPDATE `" . $tablename . "` tt1 " . $qry_update1 . " WHERE  tt1.id in (" . $temp_table_ids_by_batch . ")  AND  tt1.aj_isvalid!='N'";

        Log::info("updateTableFieldBySetOfDtaticValues:-----------------------");
        Log::info($qry_update);
        try {
            DB::update($qry_update);

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();

        }

    }

    /**
     * update the selected set of batch with value yes, once all the the child table configurations are processed for the selected set
     * @param      string  $temp_tablename  The temporary tablename
     * @param      string  $limit           The limit
     * @param      string  $batchsize       The batchsize
     */
    public function setProcessed($temp_tablename, $limit, $batchsize)
    {

        $temp_table_ids_by_batch = $this->getTempTableIdsByBatch($limit, $batchsize);

        Log::info('<br/> \n  setProcessed ');
        /*$qry_set_processed = "UPDATE " . $temp_tablename . " tmpdata
        SET
        tmpdata.aj_processed = 'y'
        WHERE  tmpdata.id in (SELECT id FROM (SELECT id FROM " . $temp_tablename . " tt ORDER BY tt.id ASC LIMIT " . $limit . "," . $batchsize . ") tt2 )  AND  tmpdata.aj_isvalid!='N'";*/
        $qry_set_processed = "UPDATE " . $temp_tablename . " tmpdata
        SET
            tmpdata.aj_processed = 'y'
        WHERE  tmpdata.id in (" . $temp_table_ids_by_batch . ")  AND  tmpdata.aj_isvalid!='N'";

        try {

            Log::info($qry_set_processed);
            Log::info('<br/> \n  setProcessed on temp table   :----------------------------------');

            DB::update($qry_set_processed);

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();

        }
    }

    public function setBatchInvalidData($temp_tablename, $limit, $batchsize, $error_msg)
    {
        $temp_tablename = config('ajimportdata.temptablename');

        $temp_table_ids_by_batch = $this->getTempTableIdsByBatch($limit, $batchsize);

        $qry_set_invalid = "UPDATE " . $temp_tablename . " tmpdata
                                SET
                                    tmpdata.aj_isvalid = 'N',
                                    tmpdata.aj_processed ='y',
                                    tmpdata.aj_error_log = '" . $error_msg . "'
                                WHERE  tmpdata.id in (" . $temp_table_ids_by_batch . ")  AND  tmpdata.aj_isvalid!='N'";

        try {

            Log::info($qry_set_invalid);
            Log::info('<br/> \n  setBatchInvalidData   :----------------------------------');

            DB::update($qry_set_invalid);

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();

        }
    }

    public function updateTableFieldBySlug($tablename, $field_slug, $limit, $batchsize)
    {

        $temp_table_ids_by_batch = $this->getTempTableIdsByBatch($limit, $batchsize);

        $qry_update1 = " SET ";

        foreach ($field_slug as $key_slug => $key_val) {

            $qry_update1 .= "tt1." . $key_val . "= REPLACE(LOWER(tt1." . $key_slug . "),' ','-')";

        }

        $qry_update = " UPDATE `" . $tablename . "` tt1 " . $qry_update1 . " WHERE  tt1.id in (" . $temp_table_ids_by_batch . ")  AND  tt1.aj_isvalid!='N'";

        Log::info("updateTableFieldBySlug:-----------------------");
        Log::info($qry_update);
        try {
            DB::update($qry_update);

        } catch (\Illuminate\Database\QueryException $ex) {

            $this->errors[] = $ex->getMessage();

        }

    }

    /* ################################ Test Functions #######################################################*/

    public function testSchedule()
    {
        /* Log::info("Executing schedule command");
        $app          = App::getFacadeRoot();
        $schedule     = $app->make(Schedule::class);
        $schedule_res = $schedule->command('queue:work --queue=validateunique,insert_records ajfileimportcon');
        echo "<pre>";
        print_r($schedule_res);*/
        DB::connection()->enableQueryLog();

        echo "m i really here?";

//SELECT GROUP_CONCAT(id) as concat_ids FROM (SELECT id FROM aj_import_temp tt ORDER BY tt.id ASC LIMIT 0,100)  tt2
        //UPDATE aj_import_temp AS tmpdata, users AS childtable SET tmpdata.users_id = childtable.id  WHERE  tmpdata.id in (SELECT id FROM (SELECT id FROM aj_import_temp tt ORDER BY tt.id ASC LIMIT 0,100) tt2 )  AND  tmpdata.aj_isvalid!='N'  AND  tmpdata.Email1=childtable.email

        $qry_update_child_ids = "UPDATE aj_import_temp  tmpdata, users  childtable, (SELECT GROUP_CONCAT(id) as concat_ids FROM (SELECT id FROM aj_import_temp tt ORDER BY tt.id ASC LIMIT 0,100)  tt2 )  tt3 SET
            tmpdata.users_id = childtable.id
        WHERE  tmpdata.id in  (tt3.concat_ids) AND  tmpdata.aj_isvalid!='N'  AND  tmpdata.Email1=childtable.email  ";

        /*try {*/

        Log::info('<br/> \n  UPDATER child ids() on temp table   :----------------------------------');

        Log::info($qry_update_child_ids);
        Log::info('STATEMENT NOW : res_update===============================');
        $res_update = DB::statement($qry_update_child_ids);

        dd(DB::getQueryLog());

        $queries = DB::getQueryLog();

        Log::info($queries);

        //update valid rows in temp table with the valid inserts on child table.

        /* } catch (\Illuminate\Database\QueryException $ex) {

    // Note any method of class PDOException can be called on $ex.
    $this->errors[] = $ex->getMessage();

    dd($this->errors);

    }*/
    }
    /* ################################ Test Functions #######################################################*/

}
