<?php

function cp_to_wc_key($key) {

  $cp_to_wc = [
    'patient_zip' => 'billing_postcode',
    'patient_state' => 'billing_state',
    'patient_city' => 'billing_city',
    'patient_address2' => 'billing_address_2',
    'patient_address1' => 'billing_address_1',
    'payment_coupon' => 'coupon',
    'tracking_coupon' => 'coupon',
    'phone2' => 'billing_phone',
    'phone1' => 'phone',
    'patient_note' => 'medications_other',
  ];

  return isset($cp_to_wc[$key]) ? $cp_to_wc[$key] : $key;
}

function wc_delete_patient($mysql, $patient_id_wc) {

  $user = "
    DELETE FROM
      wp_users
    WHERE
      ID = $patient_id_wc
  ";

  $mysql->run($user);

  $meta = "
    DELETE FROM
      wp_usermeta
    WHERE
      user_id = $patient_id_wc
  ";

  $mysql->run($meta);
}

function wc_create_patient($mysql, $patient) {

  $insert = "
    INSERT wp_users (
      user_login,
      user_nicename,
      user_email,
      user_registered,
      display_name
    ) VALUES (
      '$patient[first_name] $patient[last_name] $patient[birth_date]',
      '$patient[first_name]-$patient[last_name]-$patient[birth_date]',
      '$patient[email]',
      '$patient[patient_date_added]',
      '$patient[first_name] $patient[last_name] $patient[birth_date]'
    )
  ";

  $mysql->run($insert);

  $user_id = $mysql->run("
    SELECT * FROM wp_users WHERE user_login = '$patient[first_name] $patient[last_name] $patient[birth_date]'
  ")[0];

  echo "\n$insert\n".print_r($user_id, true);

  foreach($patient as $key => $val) {
    wc_upsert_patient_meta($mysql, $user_id[0]['ID'], $key, $val);
  }

  update_wc_patient_active_status($mysql, $user_id[0]['ID'], null);
  update_wc_backup_pharmacy($mysql, $patient_id_wc, $patient);
}

function wc_upsert_patient_meta($mysql, $user_id, $meta_key, $meta_value) {

  $wc_key = cp_to_wc_key($meta_key);
  $wc_val = is_null($meta_value) ? 'NULL' : "'".escape_db_values($meta_value)."'";

  $select = "SELECT * FROM wp_usermeta WHERE user_id = $user_id AND meta_key = '$wc_key'";

  $exists = $mysql->run($select);

  if (isset($exists[0][0])) {
    $upsert = "UPDATE wp_usermeta SET meta_value = $wc_val WHERE user_id = $user_id AND meta_key = '$wc_key'";
  } else {
    $upsert = "INSERT wp_usermeta (umeta_id, user_id, meta_key, meta_value) VALUES (NULL, $user_id, '$wc_key', $wc_val)";
  }

  $mysql->run($upsert);
}

/**
 * Create the association between the wp and the cp patient
 * @param  Mysql_Wc $mysql         The GP Mysql Connection
 * @param  array    $patient       The patient data
 * @param  int      $patient_id_cp The CP id for the patient
 * @return void
 */
function match_patient_wc($mysql, $patient, $patient_id_cp) {
  // Update the patientes table
  $mysql->run(
      "UPDATE
          gp_patients
        SET
          patient_id_wc = {$patient['patient_id_wc']}
        WHERE
          patient_id_wc IS NULL AND
          patient_id_cp = '{$patient_id_cp}'
  ");

  // Insert the patient_id_cp if it deosnt' already exist
  wc_upsert_patient_meta(
      $mysql,
      $patient['patient_id_wc'],
      'patient_id_cp',
      $patient_id_cp
  );

  log_notice("update_patients_wc: matched $patient[first_name] $patient[last_name]");
}

function find_patient_wc($mysql, $patient, $table = 'gp_patients') {
  $first_name_prefix = explode(' ', $patient['first_name']);
  $last_name_prefix  = explode(' ', $patient['last_name']);
  $first_name_prefix = escape_db_values(substr(array_shift($first_name_prefix), 0, 3));
  $last_name_prefix  = escape_db_values(array_pop($last_name_prefix));

  $sql = "
    SELECT *
    FROM $table
    WHERE
      first_name LIKE '$first_name_prefix%' AND
      REPLACE(last_name, '*', '') LIKE '%$last_name_prefix' AND
      birth_date = '$patient[birth_date]'
  ";

  if ( ! $first_name_prefix OR ! $last_name_prefix OR ! $patient['birth_date']) {
    log_error('export_wc_patients: find_patient_wc. patient has no name!', [$sql, $patient]);
    return [];
  }

  log_info('export_wc_patients: find_patient_wc', [$sql, $patient]);

  $res = $mysql->run($sql)[0];

  if ($res)
    echo "\npatient_id_cp:$patient[patient_id_cp] patient_id_wc:$patient[patient_id_wc] $patient[first_name] $patient[last_name] $patient[birth_date]\npatient_id_cp:{$res[0]['patient_id_cp']} patient_id_wc:{$res[0]['patient_id_wc']} {$res[0]['first_name']} {$res[0]['last_name']} {$res[0]['birth_date']}\nresult count:".count($res)."\n";

  return $res;
}

function update_wc_backup_pharmacy($mysql, $patient_id_wc, $patient) {

  if ( ! $patient_id_wc) return;

  $wc_val = json_encode([
    'name'   => $patient['pharmacy_name'],
    'npi'    => $patient['pharmacy_npi'],
    'street' => $patient['pharmacy_address'],
    'fax'    => $patient['pharmacy_fax'],
    'phone'  => $patient['pharmacy_phone']
  ]);

  echo "\nupdate_wc_patient_active_status $patient_id_wc, 'backup_pharmacy',  $wc_val";

  //return wc_upsert_patient_meta($mysql, $patient_id_wc, 'backup_pharmacy',  $wc_val);
}

function update_wc_patient_active_status($mysql, $patient_id_wc, $inactive) {

  if ( ! $patient_id_wc) return;

  if ($inactive == 'Inactive') {
    $wc_val = 'a:1:{s:8:"inactive";b:1;}';
  }

  else if ($inactive == 'Deceased') {
    $wc_val = 'a:1:{s:8:"deceased";b:1;}';
  }

  else {
    $wc_val = 'a:1:{s:8:"customer";b:1;}';
  }

  echo "\nupdate_wc_patient_active_status $inactive -> $patient_id_wc, 'wp_capabilities',  $wc_val";

  //return wc_upsert_patient_meta($mysql, $patient_id_wc, 'wp_capabilities',  $wc_val);
}

function update_wc_phone1($mysql, $patient_id_wc, $phone1) {
  if ( ! $patient_id_wc) return;
  return wc_upsert_patient_meta($mysql, $patient_id_wc, 'phone',  $phone1);
}

function update_wc_phone2($mysql, $patient_id_wc, $phone2) {
  if ( ! $patient_id_wc) return;
  return wc_upsert_patient_meta($mysql, $patient_id_wc, 'billing_phone', $phone2);
}
