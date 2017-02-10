<?php

require_once __DIR__ . '/../bootstrap.php';

use Wikimedia\PopularPages\PopularPages;

$app = new PopularPages( dirname( __DIR__ ) );
$app->run();
