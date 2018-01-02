<?php

$ajimport_config['filetype']      = "csv";
$ajimport_config['delimiter']     = ",";
$ajimport_config['batchsize']     = "100";
//$ajimport_config['recipient']     = "parag@ajency.in";
$ajimport_config['import_log_mail'] = array(
    'from'        => 'parag+888@ajency.in',
    'subject'     => 'Import log -ajency',
    'to'          => array('parag@ajency.in'),
    'cc'          => array('parag+444@ajency.in', 'parag+555@ajency.in'),
    'bcc'         => array('parag+666@ajency.in'),
    'template'    => '',
    'mail_params' => array('name' => 'importabc', 'day' => date('d-m-Y H:i:s')),
);


$ajimport_config['import_folder'] = ""; //Folder with permission to write

$ajimport_config['temptablename']            = 'aj_import_temp';
$ajimport_config['temptable_default_fields'] = array("tmp_source" => 'y');
//$ajimport_config['filepath']  = resource_path('uploads') . "/filetoimport.csv";

//$ajimport_config['fileheader'] = array('seq', 'first', 'last', 'age', 'street', 'city', 'state', 'zip', 'email');

$ajimport_config['fileheader'] = array('Id', 'Company Name', 'Add', 'City', 'Pin Code', 'Reference', 'State', 'Phone1', 'Phone2', 'Mobile1', 'Mobile2', 'Email1', 'Email2', 'Year', 'Web', 'Business Type', 'Business Details');

/** Fields that need to be mandatary on temp table */
//$ajimport_config['mandatary_tmp_tblfields'] = array( 'Company_Name','Pin_Code');



/** Allows to add indexes on temp table */
//$ajimport_config['indexfields'] = array('Email1','Phone2');


/** Allows to add unique contraint on temp table field */
/*$ajimport_config['uniquefields'] = array(
    'Email1_uniq' => array('Email1', 'Phone2'),
    'region_uniq' => array('State', 'City'),

);*/


/** Mark records invalid on temp table if set of fields matches each other. For ex if Email1 & Email2 value matches each other in row it will be marked as invalid */
$ajimport_config['invalid_matches'] = array(['Email1','Email2'],
                                         ['Mobile1','Mobile2']
                                        );



/** Define Call back after import is done */
/*$ajimport_config['aj_callbacks'] = array(
                                   array('function_name'=>'create',
                                         'class_path'   =>'\App\Http\Controllers\Test1controller',
                                        ),
                                    array('function_name'=>'edit',
                                         'class_path'   =>'\App\Http\Controllers\TestController',
                                        )         
                                );*/


/** Callbacks after completion of each batch*/
/*$ajimport_config['aj_batchcallbacks'] = array(
                                   array('function_name'=>'create',
                                         'class_path'   =>'\App\Http\Controllers\Test3controller',
                                        ),
                                    array('function_name'=>'edit',
                                         'class_path'   =>'\App\Http\Controllers\Test4controller',
                                        )         
                                ); */                              

/**
 * config to update any id column(for ex user_id) based on set of fields from child table(for ex user_communication table)
 */
$ajimport_config['tables_to_update_temp'][] = array(
    'name'                                            => 'user_communications',
    /*  'insertid_childtable'                            => 'id',*/
    'insertid_temptable'                              => array('users_id' => 'object_id'),
    'fields_map_to_update_temptable_child_id'         => array("Email1" => "value"),
    'default_fields_map_to_update_temptable_child_id' => array("type" => "email", "object_type" => "App\User"),
); //'temp table field'=>'child table field')



/**
 * Configs to insert childs data
 */
$ajimport_config['childtables'][] = array(
    'name'                                    => 'users',
    // 'insertid_temptable'  => 'stateid', // 'Field to be added to temp table to store id of insertion record to child table'
    'insertid_childtable'                     => 'id',
    'is_mandatary_insertid'                   => 'yes',
    /*'insertid_mtable'     => 'owner_id' ,*/
    'insertid_temptable'                      => array('users_id' => 'id'),
    'fields_map_to_update_temptable_child_id' => array("Email1" => "email"),
    'fields_map'                              => array("Email1" => "email")); //'temp table field'=>'child table field')

$ajimport_config['childtables'][] = array(
    'name'                                    => 'cities',
    // 'insertid_temptable'  => 'stateid', // 'Field to be added to temp table to store id of insertion record to child table'
    'insertid_childtable'                     => 'id',
    'is_mandatary_insertid'                   => 'yes',
    /*'insertid_mtable'     => 'city_id' ,*/
    'insertid_temptable'                      => array('cities_id' => 'id'),
    'fields_map_to_update_temptable_child_id' => array("State" => "name"),
    'fields_map'                              => array("State" => "name", "state_slug" => "slug"),
    'field_slug'                              => array('State' => 'state_slug'), //   array('temp table field from which slug will be created'=>'additinoal field on tempp table for the slug')
); //'temp table field'=>'child table field'

$ajimport_config['childtables'][] = array(
    'name'                                    => 'areas',
    // 'insertid_temptable'  => 'stateid', // 'Field to be added to temp table to store id of insertion record to child table'
    'insertid_childtable'                     => 'id',
    'is_mandatary_insertid'                   => 'yes',
    /*'insertid_mtable'     => 'locality_id' ,*/
    'insertid_temptable'                      => array('areas_id' => 'id'),
    'fields_map_to_update_temptable_child_id' => array("City" => "name", "cities_id" => "city_id"),
    'fields_map'                              => array("City" => "name", "cities_id" => "city_id"), //'temp table field'=>'child table field'
);

// user communication one for phone after user entry
$ajimport_config['childtables'][] = array(
    'name'                  => 'user_communications',
    'is_mandatary_insertid' => 'no',
    'fields_map'            => array("Phone1" => "value", "users_id" => "object_id"), //'temp table field'=>'child table field'
    'default_values'        => array("object_type" => "App\User", "type" => "mobile"), //array("user communication column name"=>"default value for the column")
);

// user communication one for email after user entry

$ajimport_config['childtables'][] = array(
    'name'                  => 'user_communications',
    'is_mandatary_insertid' => 'no',
    'fields_map'            => array("Email1" => "value", "users_id" => "object_id"), //'temp table field'=>'child table field'
    'default_values'        => array("object_type" => "App\User", "type" => "email"), //array("user communication column name"=>"default value for the column")
);

$ajimport_config['childtables'][] = array(
    'name'                                    => 'listings',
    // 'insertid_temptable'  => 'stateid', // 'Field to be added to temp table to store id of insertion record to child table'
    'insertid_childtable'                     => 'id',
    'is_mandatary_insertid'                   => 'yes',
    //'insertid_mtable'     => 'locality_id' ,
    'insertid_temptable'                      => array('listings_id' => 'id'),
    'fields_map_to_update_temptable_child_id' => array("Company_Name" => "title", "areas_id" => "locality_id", "users_id" => "owner_id"),
    'fields_map'                              => array("Company_Name" => "title", "Add"     => "display_address",
        "Business_Type"                                                   => "type", "areas_id" => "locality_id", "users_id" => "owner_id",
        "Reference"                                                       => "reference",
    ), //'temp table field'=>'child table field'
    'columnupdatevalues'                      => array('Business_Type' => array("Wholeseller" => 11, "Retailer" => 12, "Manufacturer" => 13)),

    /*serialize array form at array('column on tagle'=>array of values to be serialized where key will be a static provided by user and value will be field from temp table)    */
    'serializevalues'                         => array('other_details' => array('website' => 'Web', 'establish_year' => 'Year')),


    /*json array form at array('column on table'=>array of values to be json where key will be a static provided by user and value will be field from temp table)    */
    /*'jsonvalues'                              => array('payment_modes' => array('city' => 'City', 'company_name' => 'Company_Name')),*/

    /* multiple columns as array value to field on child table*/
    'colstoarrayfield'                        => array('highlights' => array('Email1', 'State')),

);

// user communication one for phone after listings table  entry
$ajimport_config['childtables'][] = array(
    'name'                  => 'user_communications',
    'is_mandatary_insertid' => 'no',
    'fields_map'            => array("Phone2" => "value", "listings_id" => "object_id"), //'temp table field'=>'child table field'
    'default_values'        => array("object_type" => "App\Listing", "type" => "phone2"), //array("user communication column name"=>"default value for the column")
);

// user communication one for phone after listings table  entry
$ajimport_config['childtables'][] = array(
    'name'                  => 'user_communications',
    'is_mandatary_insertid' => 'no',
    'fields_map'            => array("Mobile1" => "value", "listings_id" => "object_id"), //'temp table field'=>'child table field'
    'default_values'        => array("object_type" => "App\Listing", "type" => "mobile1"), //array("user communication column name"=>"default value for the column")
);

// user communication one for phone after listings table  entry
$ajimport_config['childtables'][] = array(
    'name'                  => 'user_communications',
    'is_mandatary_insertid' => 'no',
    'fields_map'            => array("Mobile2" => "value", "listings_id" => "object_id"), //'temp table field'=>'child table field'
    'default_values'        => array("object_type" => "App\Listing", "type" => "mobile2"), //array("user communication column name"=>"default value for the column")
);
// user communication one for phone after listings table  entry

$ajimport_config['childtables'][] = array(
    'name'                  => 'user_communications',
    'is_mandatary_insertid' => 'no',
    'fields_map'            => array("Email2" => "value", "listings_id" => "object_id"), //'temp table field'=>'child table field'
    'default_values'        => array("object_type" => "App\Listing", "type" => "email"), //array("user communication column name"=>"default value for the column")
);

/* Example to insert comma seperated fiel to multiple records in childtable*/
/*$ajimport_config['childtables'][] = array('name' => 'listing_category',
'is_mandatary_insertid'                          => 'no',
'fields_map'                                     => array("listings_id" => "listing_id"), //'temp table field'=>'child table field'
'default_values'                                 => array("object_type" => "App\Listing", "type" => "email"), //array("user communication column name"=>"default value for the column")
'commafield_to_multirecords'                     => array('Business_Details' => 'category_id'), //Does not support for multiple comma seperated fields into new records as array here. If more than one field is of type comma seperated and needs to be seperate records, add it as seperate childtable record
'default_values'                                 => array("core" => "1"), //array("user communication column name"=>"default value for the column")
);*/

/*Example configuration to make fields on table to multiple records on child table */
$ajimport_config['childtables'][] = array(
    'name'                   => 'listing_category',
    'is_mandatary_insertid'  => 'no',
    'fields_map'             => array("listings_id" => "listing_id"), //'temp table field'=>'child table field'
    'default_values'         => array("object_type" => "App\Listing", "type" => "email"), //array("user communication column name"=>"default value for the column")
    'fields_to_multirecords' => array('category_id' => array('Email1', 'State')), //Does not support for multiple comma seperated fields into new records as array here. If more than one field is of type comma seperated and needs to be seperate records, add it as seperate childtable record
    'default_values'         => array("core" => "1"), //array("user communication column name"=>"default value for the column")
);

/* End Add Child tables here */

return $ajimport_config;
