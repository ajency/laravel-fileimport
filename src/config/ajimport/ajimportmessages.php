<?php

$ajimport_msg_config['file_permission_check'] = array(
    'message' => '"<br/> Checking the file permissions ...."');
$ajimport_msg_config['pending_job_check'] = array(
    'message'     => 'Checking for pending FileImport pending jobs',
    'success_msg' => ' - Passed ',
    'failure_msg' => ' - Error',
);
$ajimport_msg_config['table_exists_check'] = array(
    'message'     => 'Checking if tables from configuration file exists in database',
    'success_msg' => ' - Passed ',
    'failure_msg' => ' - Failed',
);
$ajimport_msg_config['pending_job_exists'] = array(
    'message' => 'There are pending jobs from previous import to be processed!! <br/> Please run job queue <b>\'php artisan queue:work --queue=validateunique,insert_records ajfileimportcon\'</b>',

);

$ajimport_msg_config['validate_file'] = array(
    'message'     => '<br/>Validating file....',
    'success_msg' => ' - Valid File ',
    'failure_msg' => ' - Invalid File',
);
$ajimport_msg_config['mandatory_fields_empty'] = array(
    'message' => 'Mandatary fields configured are empty or null');
$ajimport_msg_config['download_temp_file'] = array(
    'message' => "View the csv import data from ready table. <br/>",
    'display' => false,
);
$ajimport_msg_config['run_import_job_queue'] = array(
    'message' => 'Listing Import is under process in background, you will receive an email with the upload status once done.', //*<b>Note: Please run this command to complete the import of data: <br/> \'php artisan queue:work --queue=validateunique,insert_records ajfileimportcon\'  </b>',*/

    'display' => true);
$ajimport_msg_config['config_tables_not_found'] = array(
    'message' => 'Following Tables mentioned in config file do not exists in database. <br/>',
);

return $ajimport_msg_config;
