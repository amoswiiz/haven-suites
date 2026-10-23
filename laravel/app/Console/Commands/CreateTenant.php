<?php
namespace App\Console\Commands;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PDO;

class CreateTenant extends Command
{
    protected $signature = 'haven:tenant-create {slug} {name} {email} {--database=}';
    protected $description = 'Provision an empty isolated company database and its administrator.';
    public function handle(): int {
        $slug = $this->argument('slug'); $email = $this->argument('email');
        $database = $this->option('database') ?: 'haven_tenant_'.str_replace('-','_',$slug);
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$slug) || !preg_match('/^[a-zA-Z0-9_]+$/D',$database)) throw new \InvalidArgumentException('Invalid slug or database name.');
        Validator::make(['email'=>$email,'name'=>$this->argument('name')],['email'=>'required|email','name'=>'required|string|max:150'])->validate();
        if (Tenant::where('slug',$slug)->exists() || Tenant::where('database_name',$database)->exists()) throw new \RuntimeException('Company or database already registered.');
        $schema = database_path('schema/tenant.mysql.sql');
        if (!is_file($schema)) throw new \RuntimeException('Operational schema missing; import an existing CI installation first.');
        $user = User::where('email',$email)->first();
        $password = null;
        if (!$user) {
            $password = $this->secret('Administrator password (minimum 12 characters)');
            Validator::make(['password'=>$password],['password'=>'required|string|min:12'])->validate();
        }
        $config = config('database.connections.tenant');
        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4';
        $pdo = new PDO($dsn,$config['username'],$config['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $exists=$pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?'); $exists->execute([$database]);
        if ($exists->fetch()) throw new \RuntimeException('Destination database exists; it was not changed.');
        $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `'.$database.'`'); $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->exec(file_get_contents($schema)); $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            DB::transaction(function () use ($pdo,$database,$slug,$email,$password,$user) {
                $tenant=Tenant::create(['name'=>$this->argument('name'),'slug'=>$slug,'database_name'=>$database,'active'=>true]);
                if (!$user) $user=User::create(['name'=>$this->argument('name').' Admin','username'=>'admin-'.$slug,'email'=>$email,'password'=>$password,'active'=>true]);
                $statement=$pdo->prepare('INSERT INTO users (username,active,created_at,updated_at) VALUES (?,1,NOW(),NOW())');
                $statement->execute([$user->username]); $legacyId=(int)$pdo->lastInsertId();
                $tenant->users()->attach($user,['roles'=>json_encode(['superadmin']),'legacy_user_id'=>$legacyId]);
                foreach (['Cleaning','Maintenance','Utilities','Rent','Other'] as $category) {
                    $statement=$pdo->prepare('INSERT INTO expense_categories (name,created_at,updated_at) VALUES (?,NOW(),NOW())');
                    $statement->execute([$category]);
                }
            });
            $this->info('Company created: /t/'.$slug.'/dashboard. Configure cleaning rates and host agreements before use.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Provisioning failed. Incomplete database retained for inspection: '.$database);
            throw $e;
        }
    }
}
