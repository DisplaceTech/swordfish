#!/usr/bin/env php
<?php

require __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Application;

define('SWORDFISH_PEPPER', 'd783eff0523c8fa7336bc768c5950f63');

$application = new Application();

$application->add(new \Swordfish\CLI\CreateSecretCommand());
$application->add(new \Swordfish\CLI\RetrieveSecretCommand());

$application->run();