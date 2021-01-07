<?php
require_once 'exports/export_wc_patients.php';

use Sirum\Logging\SirumLog;

//TODO Implement Full Matching Algorithm that's in Salesforce and CP's SP
function is_patient_match($mysql, $patient) {
  $patient_cp = find_patient($mysql, $patient);
  $patient_wc = find_patient($mysql, $patient, 'gp_patients_wc');

  if (count($patient_cp) == 1 AND count($patient_wc) == 1) {
    return [
      'patient_id_cp' => $patient_cp[0]['patient_id_cp'],
      'patient_id_wc' => $patient_wc[0]['patient_id_wc']
    ];
  }

  $alert = [
    'todo'              => "TODO Auto Delete Duplicate Patient AND Send Patient Comm of their login and password",
    'patient'           => $patient,
    'count(patient_cp)' => count($patient_cp),
    'count(patient_wc)' => count($patient_wc),
    'patient_cp'       => $patient_cp,
    'patient_wc'       => $patient_wc
  ];

  //TODO Auto Delete Duplicate Patient AND Send Comm of their login and password

  SirumLog::alert("helper_matching: is_patient_match FALSE ".@$patient[0]['first_name']." ".@$patient[0]['last_name']." ".@$patient[0]['birth_date'], $alert);

  print_r($alert);
}

/**
 * Create the association between the wp and the cp patient
 * this will overwrite a current association if it exists
 *
 * @param  Mysql_Wc $mysql         The GP Mysql Connection
 * @param  array    $patient       The patient data
 * @param  int      $patient_id_cp The CP id for the patient
 * @return void
 */


function match_patient($mysql, $patient_id_cp, $patient_id_wc) {

  // Update the patientes table
  $sql = "
    UPDATE
      gp_patients
    SET
      patient_id_cp = '{$patient_id_cp}',
      patient_id_wc = '{$patient_id_wc}'
    WHERE
      patient_id_cp = '{$patient_id_cp}' OR
      patient_id_wc = '{$patient_id_wc}'
  ";

  $mysql->run($sql);

  log_notice("helper_matching: match_patient() matched patient_id_cp:$patient_id_cp with patient_id_wc:$patient_id_wc", [
    'sql' => $sql,
  ]);

  // Insert the patient_id_cp if it deosnt' already exist
  wc_upsert_patient_meta(
    $mysql,
    $patient_id_wc,
    'patient_id_cp',
    $patient_id_cp
  );
}

//TODO Implement Full Matching Algorithm that's in Salesforce and CP's SP
function name_tokens($first_name, $last_name) {
  $first_array = preg_split('/ |-/', $first_name);
  $last_array  = preg_split('/ |-/', $last_name); //Ignore first part of hypenated last names just like they are double last names

  $first_name_token = substr(array_shift($first_array), 0, 3);
  $last_name_token  = array_pop($last_array);

  return ['first_name_token' => $first_name_token, 'last_name_token' => $last_name_token];
}

//TODO Implement Full Matching Algorithm that's in Salesforce and CP's SP
//Table can be gp_patients / gp_patients_wc / gp_patients_cp
function find_patient($mysql, $patient, $table = 'gp_patients') {

  $tokens = name_tokens($patient['first_name'], $patient['last_name']);

  $first_name_token = escape_db_values($tokens['first_name_token']);
  $last_name_token  = escape_db_values($tokens['last_name_token']);

  $sql = "
    SELECT *
    FROM $table
    WHERE
      first_name LIKE '$first_name_token%' AND
      REPLACE(last_name, '*', '') LIKE '%$last_name_token' AND
      birth_date = '$patient[birth_date]'
  ";

  if ( ! $first_name_token OR ! $last_name_token OR ! $patient['birth_date']) {
    log_error('export_wc_patients: find_patient. patient has no name!', [$sql, $patient]);
    return [];
  }

  log_info('export_wc_patients: find_patient', [$sql, $patient]);

  $res = $mysql->run($sql)[0];

  return $res;
}
