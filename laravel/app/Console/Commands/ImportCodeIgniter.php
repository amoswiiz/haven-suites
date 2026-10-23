<?php
namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class ImportCodeIgniter extends Command
{
    protected $signature = 'haven:import-ci {source : Existing CI MySQL database} {--destination=haven_suites_laravel13} {--slug=haven} {--name=Haven Suites}';
    protected $description = 'Copy a CI database into a NEW tenant database, preserving the source.';

    public function handle(): int {
        $sourceName = $this->argument('source'); $destination = $this->option('destination');
        foreach ([$sourceName,$destination] as $name) if (!preg_match('/^[a-zA-Z0-9_]+$/D',$name)) throw new \InvalidArgumentException('Unsafe database name.');
        if ($sourceName === $destination) throw new \RuntimeException('Source and destination must differ.');
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $this->option('slug'))) throw new \InvalidArgumentException('Invalid tenant slug.');
        if (Tenant::where('slug',$this->option('slug'))->exists()) throw new \RuntimeException('Tenant slug already exists.');
        $config = config('database.connections.tenant');
        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4';
        $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC];
        $server = new PDO($dsn,$config['username'],$config['password'],$options);
        $exists = $server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $exists->execute([$destination]);
        if ($exists->fetch()) throw new \RuntimeException('Destination database already exists. No data was overwritten.');
        $source = new PDO($dsn.';dbname='.$sourceName,$config['username'],$config['password'],$options);
        $source->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        $tables = $source->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('reservations',$tables,true) || !in_array('users',$tables,true)) throw new \RuntimeException('Source is not a Haven Suites schema.');
        $server->exec('CREATE DATABASE `'.$destination.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $copy = new PDO($dsn.';dbname='.$destination,$config['username'],$config['password'],$options);
        $copy->exec('SET FOREIGN_KEY_CHECKS=0');
        $schema = "-- Haven Suites operational schema. No guest or financial data.\n";
        $totals = [];
        try {
            foreach ($tables as $table) {
                $escaped = str_replace('`','``',$table);
                $ddl = $source->query('SHOW CREATE TABLE `'.$escaped.'`')->fetch();
                $create = array_values($ddl)[1];
                $copy->exec($create);
                $schema .= preg_replace('/ AUTO_INCREMENT=\d+/', '', $create).";\n\n";
                $select = $source->query('SELECT * FROM `'.$escaped.'`'); $count = 0; $insert = null;
                while ($row = $select->fetch()) {
                    if (!$insert) {
                        $columns = implode(',',array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',array_keys($row)));
                        $insert = $copy->prepare('INSERT INTO `'.$escaped.'` ('.$columns.') VALUES ('.implode(',',array_fill(0,count($row),'?')).')');
                    }
                    $insert->execute(array_values($row)); $count++;
                }
                $actual = (int)$copy->query('SELECT COUNT(*) FROM `'.$escaped.'`')->fetchColumn();
                if ($actual !== $count) throw new \RuntimeException('Row count mismatch: '.$table);
                $totals[$table] = $count;
            }
            $copy->exec('SET FOREIGN_KEY_CHECKS=1');
            DB::transaction(function () use ($source,$destination,$tables) {
                $tenant = Tenant::create(['name'=>$this->option('name'),'slug'=>$this->option('slug'),'database_name'=>$destination,'active'=>true]);
                $identities = in_array('auth_identities',$tables,true) ? $source->query("SELECT * FROM auth_identities WHERE type='email_password'")->fetchAll() : [];
                $legacyUsers = [];
                foreach ($source->query('SELECT * FROM users')->fetchAll() as $row) $legacyUsers[$row['id']]=$row;
                foreach ($identities as $identity) {
                    $legacy = $legacyUsers[$identity['user_id']] ?? null;
                    if (!$legacy || !empty($legacy['deleted_at'])) continue;
                    $user = User::where('email',$identity['secret'])->first();
                    if (!$user) {
                        $user = new User(['name'=>$legacy['username'] ?: 'Staff','username'=>($legacy['username'] ?: 'staff').'-'.$tenant->id.'-'.$legacy['id'],'email'=>$identity['secret'],'active'=>(bool)$legacy['active']]);
                        // Copy a hash directly, never rehash it as a plaintext password.
                        $user->setRawAttributes([...$user->getAttributes(),'password'=>$identity['secret2']]);
                        $user->save();
                    }
                    $statement = $source->prepare('SELECT `group` FROM auth_groups_users WHERE user_id=?');
                    $statement->execute([$legacy['id']]); $roles = $statement->fetchAll(PDO::FETCH_COLUMN);
                    $tenant->users()->attach($user->id,['roles'=>json_encode($roles ?: ['user']),'legacy_user_id'=>$legacy['id']]);
                }
            });
            $source->commit();
            if (!is_dir(database_path('schema'))) mkdir(database_path('schema'),0775,true);
            file_put_contents(database_path('schema/tenant.mysql.sql'),$schema);
            $privateSource = dirname(base_path()).'/writable/private/kyc';
            $privateTarget = storage_path('app/private/tenants/'.$this->option('slug').'/private/kyc');
            if (is_dir($privateSource)) \Illuminate\Support\Facades\File::copyDirectory($privateSource,$privateTarget);
            $this->info('Copied '.count($totals).' tables ('.array_sum($totals).' rows). Source unchanged.');
            $this->info('Login at /login; tenant at /t/'.$this->option('slug').'/dashboard');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ($source->inTransaction()) $source->rollBack();
            $this->error('Import failed. Incomplete destination was retained for inspection: '.$destination);
            throw $e;
        }
    }
}
