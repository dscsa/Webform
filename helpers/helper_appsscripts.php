<?php

function gdoc_post($url, $content) {
  $opts = [
    'http' => [
      'method'  => 'POST',
      'content' => json_encode($content),
      'header'  => "Content-Type: application/json\r\n" .
                   "Accept: application/json\r\n"
    ]
  ];

  $context = stream_context_create($opts);
  return file_get_contents($url.'?GD_KEY='.GD_KEY, false, $context);
}

function watch_invoices() {

  $args = [
    'method'       => 'watchFiles',
    'folder'       => INVOICE_FOLDER_NAME
  ];

  $invoices = json_decode(gdoc_post(GD_HELPER_URL, $args), true);

  if ( ! is_array($invoices)) {
    log_error('ERROR watch_invoices', compact($invoices, $args), null);
    $invoices = [];
  }

  foreach ($invoices as $invoice) {

    preg_match_all('/(Total:? +|Amount Due:? +)\$(\d+)/', $invoice['part0'], $totals);

    //Table columns seem to be divided by table breaks
    preg_match_all('/\\n\$(\d+)/', $invoice['part0'], $items);

    //Differentiate from the four digit year
    preg_match_all('/\d{5,}/', $invoice['name'], $invoice_number);

    log_error('watch_invoices', get_defined_vars());
  }

  //Parse Invoice Text and Look For Changes
  /*  if ( ! match) continue

    var text   = match.getElement()
    var value  = text.replace(RegExp('('+content.needle+') *'), '')
    var digits = value.replace(/\D/g, '')
    var match  = text.replace(RegExp(' *'+value.replace('$', '\\$')), '')

    res.values.push({text:text, match:match, value:value, digits:digits})

    findText('('+content.needle+') *[$\w]+')
  */

 //$order  = set_payment($order, get_payment($order), $mysql);

  return $invoices;


}
