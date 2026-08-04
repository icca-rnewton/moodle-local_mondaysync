<?php
// This file is part of Moodle - http://moodle.org/
//
// local_mondaysync - syncs Monday.com board columns into Moodle user profile fields.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_mondaysync';
$plugin->version   = 2026072509;
$plugin->requires  = 2024100700; // Moodle 4.5.0 (MOODLE_405_STABLE).
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '1.0.0';