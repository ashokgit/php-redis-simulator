<?php

require '../vendor/autoload.php';
require '../src/RedisPhpSimulator.php';

$loops = [
	0 => [1000 , 1000],
	1 => [1000 , 5000],
	2 => [3000 , 6000],
	3 => [5000 , 10000],
	4 => [10000 , 10000],
	5 => [10000 , 50000],
];
foreach ($loops as $key => $value) {
	$sim = new RedisPhpSimulator();
	$sim->connect();

	$sim->simulationName = 'ThesisTest';
	$sim->distributions = ["zipf"];
	$sim->policies = ["volatile-lru"];
	$sim->noOfPages = $value[0];
	$sim->maxPageHitPerPage = $value[1];

	echo "Simulating $value[0] pages to $value[1] max hits per page \n";
	$sim->simulate();

	print_r($sim->hitratePerAlgo);
	print_r($sim->keyMetrics);
}
