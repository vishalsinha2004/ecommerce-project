<?php

$email = "ammarchhipa9@gmail.com";
$password = 'SX&$VatxUsDg@V7bzgEY%BwrP0LQn1!q';

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => 'https://apiv2.shiprocket.in/v1/external/auth/login',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode([
      "email" => $email,
      "password" => $password
  ]),
  CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
));

$response = curl_exec($curl);
curl_close($curl);

$data = json_decode($response, true);

$token = $data["token"];

echo "Your Auth Token: " . $token;
