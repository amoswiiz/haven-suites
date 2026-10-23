<?php
namespace App\Support\Migration;
class Database { public static function connect(): Connection { return db_connect(); } }
