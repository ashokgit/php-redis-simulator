<?php

$noOfItems = 50000;

require_once 'vendor/fzaninotto/faker/src/autoload.php';

for ($i = 0; $i < $noOfItems; $i++) {
    $filename = 'data/pages/' . $i . '.json';
    if (!file_exists($filename)) {
        $fp = fopen($filename, 'w');

        $fakeData = getFakeData();

        fwrite($fp, json_encode($fakeData));
        fclose($fp);
    }
}

echo "Generated " . $i . " items \n";

function getFakeData()
{
    $data = [];
    $faker = Faker\Factory::create();
    $data['firstName'] = $faker->firstName;
    $data['lastName'] = $faker->lastName;
    $data['email'] = $faker->email;
    $data['phone'] = $faker->phoneNumber;
    $data['bio'] = $faker->text(200);
    $data['price'] = $faker->randomNumber;
    $data['is_verified'] = $faker->boolean;
    $data['company'] = $faker->company;
    $data['time'] = time();
    $date['date'] = date('Y-m-d');
    $data['about'] = $faker->sentence(500);

    $data['address']['streetAddress'] = $faker->streetAddress;
    $data['address']['city'] = $faker->address;
    $data['address']['postcode'] = $faker->postcode;
    $data['address']['state'] = $faker->state;

    for ($i = 0; $i < 10; $i++) {
        $data['article']['id'] = $i;
        $data['article']['title'] = $faker->sentence(5);
        $data['article']['text'] = $faker->sentence(1000);
    }

    return $data;
}
