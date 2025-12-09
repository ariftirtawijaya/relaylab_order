<?php

$curl = curl_init();

curl_setopt_array($curl, array(
    CURLOPT_URL => 'https://api.fonnte.com/send',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => '',
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => 'POST',
    CURLOPT_POSTFIELDS => array(
        'target' => '6289529303412',   // GANTI DENGAN NOMOR KAMU
        'message' => 'TEST FONNTE dari RelayLab OMS'
    ),
    CURLOPT_HTTPHEADER => array(
        'Authorization: yNuNwRkmU8L4YDyF1NQi'
    ),
));

$response = curl_exec($curl);

if (curl_errno($curl)) {
    echo "CURL ERROR: " . curl_error($curl);
}

curl_close($curl);

echo "<pre>";
var_dump($response);
echo "</pre>";
